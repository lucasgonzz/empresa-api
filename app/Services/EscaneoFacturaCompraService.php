<?php

namespace App\Services;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Helpers\AiTokenUsageHelper;
use App\Models\Article;
use App\Models\Iva;
use App\Models\ProviderOrderScan;
use App\Services\Compras\CodigosDeProveedor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;

/**
 * Motor del escaneo de facturas de compra con IA (misión escaneo-factura-compra).
 *
 * Recibe un ProviderOrderScan con sus fotos ya guardadas y devuelve el array del contrato
 * de la sección 2 del plan: columnas detectadas, artículos (ya matcheados contra el
 * catálogo), datos del comprobante y avisos.
 *
 * 🔴 TRES COSAS QUE NO SE NEGOCIAN EN ESTE ARCHIVO:
 *
 * 1. NO HAY USUARIO AUTENTICADO. Este servicio lo llama un job de cola. El dueño de los
 *    datos sale SIEMPRE de `$scan->user_id` y la persona de `$scan->auth_user_id`. Acá
 *    nunca aparecen `UserHelper::`, `auth()->` ni `$this->userId()`: dentro del worker
 *    devuelven null (o el usuario equivocado) y el matcheo terminaría corriendo contra el
 *    catálogo de otro comercio.
 *
 * 2. CADA LECTURA ES UNA SOLA LLAMADA CON TODAS LAS PÁGINAS. Las N fotos de un escaneo son
 *    páginas consecutivas del MISMO comprobante y viajan juntas en un único
 *    `messages[0].content`, con un bloque de texto "PÁGINA N de M" antes de cada imagen y las
 *    instrucciones al final. Una tabla que arranca en la página 1 y sigue en la 2 solo se puede
 *    reconstruir viendo las dos a la vez; partir esto en N llamadas rompe la funcionalidad.
 *
 * 3. NUNCA SE CREA UN ARTÍCULO. El matcheo marca `sin_match` y deja `crear_en_catalogo` en
 *    false; la creación la decide el usuario después, en el modal de revisión. Un código
 *    mal leído por OCR que crea un artículo fantasma en el catálogo del cliente es el peor
 *    resultado posible de esta funcionalidad.
 *
 * Quién lee (misión escaneo-factura-haiku, 2/11/2026): la primera lectura la hace un modelo
 * económico (`model`, Haiku 5.5) y uno más caro (`model_revision`, Sonnet 5.5) relee la factura
 * ENTERA solo cuando la primera no pasa el control de necesita_revision(). Gana la segunda
 * completa, sin mezclar campos: dos lecturas distintas pueden numerar o partir los renglones
 * distinto, y una mezcla daría una fila que nadie leyó. El usuario no se entera de cuál leyó.
 *
 * Constraint del repo: PHP 7.4 (nada de `?->`, `match`, `str_contains`, union types, etc).
 */
class EscaneoFacturaCompraService
{
    /**
     * Versión del contrato del `resultado` que persiste el job. Si algún día cambia la
     * forma del JSON, este número sube y el frontend puede decidir qué hacer con los
     * escaneos viejos que quedaron guardados con la forma anterior.
     *
     * @var int
     */
    const VERSION_CONTRATO = 1;

    /**
     * Únicas claves válidas para `columnas_detectadas[].clave`. Cualquier otra cosa que
     * devuelva Claude se descarta: el frontend arma la grilla con estas y solo estas.
     *
     * @var array
     */
    const CLAVES_COLUMNA = [
        'bar_code',
        'codigo_proveedor',
        'nombre',
        'cantidad',
        'costo_unitario',
        'descuento_porcentaje',
        'iva_porcentaje',
        'total_linea',
        'notas',
    ];

    /**
     * Campos numéricos de cada fila de artículo. Todos pasan por
     * ImportHelper::parseNumericValue(), que tolera "$ 37.468,24".
     *
     * @var array
     */
    const CAMPOS_NUMERICOS_ARTICULO = [
        'cantidad',
        'costo_unitario',
        'descuento_porcentaje',
        'iva_porcentaje',
        'total_linea',
    ];

    /**
     * Campos numéricos de la cabecera del comprobante.
     *
     * 🔴 Las retenciones NO están, y no es un olvido (misión `compras-factura-manual-alicuotas`,
     * 17/9/2026). Una factura de compra no trae retenciones: quien retiene es tu cliente cuando te
     * paga, no el proveedor cuando te factura. Pedirle a la IA `retencion_iibb` / `retencion_iva` /
     * `retencion_ganancias` de un papel donde ese número no existe es invitarla a inventarlo —
     * cualquier importe con pinta de impuesto al pie del comprobante cae ahí. Las retenciones se
     * cargan al registrar un cobro en la cuenta corriente de un cliente, con los datos del
     * certificado.
     *
     * @var array
     */
    const CAMPOS_NUMERICOS_FACTURA = [
        'neto_gravado',
        'total_iva',
        'total',
        'percepcion_iibb',
        'percepcion_iva',
    ];

    /**
     * Tipos de imagen que la API de Anthropic acepta. Se usa solo en el camino de
     * respaldo (cuando Intervention no pudo re-encodear la foto y se manda el binario
     * original tal cual quedó en disco).
     *
     * @var array
     */
    const MEDIA_TYPES_ACEPTADOS = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    /**
     * Modelos a los que se les pide `fallbacks: "default"`: si el clasificador de seguridad declina
     * la lectura, la API la reintenta sola con otro modelo dentro de la misma llamada.
     *
     * Es una lista y no "la llamada de revisión" porque depende del modelo, no del orden: a Haiku
     * 5.5 no hay que mandárselo (no tiene fallback de servidor: declinado queda declinado) y un
     * `.env` puede poner cualquier modelo en cualquiera de las dos posiciones.
     *
     * @var array
     */
    const MODELOS_CON_FALLBACK_DE_SERVIDOR = [
        'claude-sonnet-5-5',
    ];

    /**
     * Header beta que habilita `fallbacks: "default"`. Es propio de esa forma: la forma con lista de
     * modelos usa `server-side-fallback-2026-06-01`, y cruzarlas devuelve un 400.
     *
     * @var string
     */
    const BETA_FALLBACK_DE_SERVIDOR = 'server-side-fallback-2026-07-01';

    /**
     * Ancho al que se agranda la foto, como máximo. Junto con la escala y los tramos de abajo es la
     * geometría medida (misión escaneo-factura-haiku): con ella el modelo económico pasó de 17 códigos
     * mal leídos a ninguno. No son números de ajuste fino: si cambian, hay que volver a medir.
     *
     * @var int
     */
    const ANCHO_MAXIMO_AGRANDADA = 1560;

    /**
     * Escala máxima del agrandado: más allá de ×3 una foto muy chica no gana detalle, solo tokens.
     *
     * @var int
     */
    const ESCALA_MAXIMA = 3;

    /**
     * Debajo de esta escala no se agranda: una foto que ya casi llega al ancho no gana nada, y
     * remuestrearla la deja apenas más borrosa y ocupa más memoria.
     *
     * @var float
     */
    const ESCALA_MINIMA_QUE_SUMA = 1.1;

    /**
     * Alto de cada tramo, en píxeles.
     *
     * @var int
     */
    const ALTO_TRAMO = 1100;

    /**
     * Filas que comparten dos tramos seguidos, para que un renglón que cae justo en el corte se lea
     * entero en alguno de los dos: el 15 % del alto del tramo.
     *
     * 🔴 No alcanza con la altura de un renglón, por la deriva de la inclinación. El corte es horizontal
     * y una foto de celular nunca está derecha, así que un mismo renglón cae más arriba en un borde
     * que en el otro. A este ancho, 2° de inclinación son 54 px de deriva y 3° son 82, más los 40 o 50
     * px que ocupa un renglón agrandado: con 80 de solape, un renglón inclinado podía quedar partido en
     * los dos tramos. Con 165 entra hasta unos 4°.
     *
     * @var int
     */
    const SOLAPE_TRAMO = 165;

    /**
     * Margen sobre el tamaño de un bitmap decodificado (4 bytes por pixel) que se exige tener libre,
     * el mismo criterio de ImageCropHelper::has_memory_to_copy(). El 30 % cubre el resto de lo que el
     * proceso tiene en vuelo.
     *
     * @var float
     */
    const FACTOR_MEMORIA = 1.3;

    /**
     * Peso máximo de un tramo en PNG, en bytes. La API acepta 10 MB por imagen medidos en base64, que
     * son 7,5 MB crudos: se deja margen. Con el `max_side` por defecto un tramo no llega ni cerca
     * (medido: 1,1 MB); solo importa si alguien lo sube por .env.
     *
     * @var int
     */
    const MAX_BYTES_POR_TRAMO = 7340032;

    /**
     * Peso máximo del total de imágenes de un escaneo, en bytes crudos. Son unos 85 MB en base64 contra
     * los 128 MB que la API acepta por pedido. Medido con fotos reales: menos de 2 MB por página. Se
     * lee con `static::` para poder probarlo.
     *
     * @var int
     */
    const MAX_BYTES_POR_ESCANEO = 67108864;

    /**
     * Memoria de la resolución de alícuotas dentro de un mismo escaneo: texto del porcentaje
     * ('21', '10.5') → id de `ivas`, o null si esa alícuota no existe en la tabla.
     *
     * Una factura de 80 renglones tiene, con suerte, dos alícuotas distintas: sin esta memoria
     * serían 80 SELECT idénticos contra una tabla global que no cambia durante la corrida.
     *
     * @var array
     */
    protected $ivas_por_porcentaje = [];

    /**
     * Escanea las fotos de un ProviderOrderScan y devuelve el array del contrato §2.
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @return array
     *
     * @throws \RuntimeException  Cuando falta la clave de la API, no hay fotos legibles,
     *                            Anthropic responde con error o la respuesta no se puede
     *                            parsear. El job la traduce a `estado = 'error'` con un
     *                            mensaje que el usuario puede leer.
     */
    public function escanear(ProviderOrderScan $scan): array
    {
        $api_key = (string) config('services.anthropic.api_key');

        /*
         * Se chequea ANTES de tocar las imágenes: si no hay credencial no hay escaneo
         * posible, y redimensionar seis fotos para después no poder llamar a nadie es
         * medio minuto de worker tirado.
         */
        if (trim($api_key) === '') {
            throw new \RuntimeException(
                'No está configurada la clave de la API de IA (ANTHROPIC_API_KEY), así que no se pudo leer la factura.'
            );
        }

        $preparacion = $this->preparar_imagenes($scan);
        $imagenes    = $preparacion['imagenes'];
        $avisos      = $preparacion['avisos'];

        if (count($imagenes) === 0) {
            throw new \RuntimeException(
                'No se pudo leer ninguna de las fotos de la factura. Probá sacarlas de nuevo, con buena luz y sin recortar el comprobante.'
            );
        }

        /*
         * La primera lectura con el modelo económico. Una lectura que falla (error de la API, JSON
         * roto, respuesta cortada) no corta el escaneo acá: es un motivo más para revisar.
         */
        $primera = $this->leer_factura($scan, $imagenes, $this->modelo_de_lectura());
        $motivo  = $this->necesita_revision($primera);

        /* Si la primera pasó el control, es la que vale. Si no, gana la revisión entera. */
        $ganadora         = $primera;
        $tokens           = $primera['tokens'];
        $sigue_sin_cerrar = null;

        if (!is_null($motivo)) {
            $ganadora = $this->leer_factura($scan, $imagenes, $this->modelo_de_revision());

            /* Se pagaron las dos: lo que queda en el resultado es lo que costó el escaneo. */
            $tokens['input']  += $ganadora['tokens']['input'];
            $tokens['output'] += $ganadora['tokens']['output'];

            /* La revisión pasa por el mismo control: que la haya leído otro modelo no la hace cierta. */
            if (is_null($ganadora['error'])) {
                $sigue_sin_cerrar = $this->necesita_revision($ganadora);
            }
        }

        $this->dejar_rastro_de_la_lectura($scan, $primera, $ganadora, $motivo, $sigue_sin_cerrar);

        /*
         * 🔴 Dos salidas distintas, y no hay que confundirlas:
         *
         *   · La revisión FALLÓ (error de la API, sin JSON, cortada): no hay segunda lectura, solo una
         *     primera que ya no pasó el control o que tampoco existe. Va el error de siempre, y el
         *     usuario vuelve a escanear.
         *   · La revisión LEYÓ pero tampoco cierra: el escaneo sigue "listo", porque es lo mejor que se
         *     pudo leer y hay datos para corregir a mano contra el papel. Lo que no puede es llegar en
         *     silencio: va con aviso y con los renglones marcados (ver anotar_lo_que_sigue_sin_cerrar()).
         */
        if (!is_null($ganadora['error'])) {
            throw $ganadora['error'];
        }

        $parseado = $ganadora['parseado'];

        if (!is_null($sigue_sin_cerrar)) {
            $parseado = $this->anotar_lo_que_sigue_sin_cerrar($parseado);
        }

        /* Los avisos de la preparación (fotos salteadas) se suman a los que dio la IA. */
        $parseado['avisos'] = array_merge($avisos, $parseado['avisos']);

        /* `pagina` es de la hoja real: un número de tramo que se haya colado no puede pasar de acá. */
        $parseado['articulos'] = $this->acotar_paginas($parseado['articulos'], $preparacion['paginas']);

        /* Lo que agrega el backend: match contra el catálogo e iva_id de cada alícuota. */
        $parseado['articulos'] = $this->matchear_articulos($parseado['articulos'], $scan);
        $parseado['articulos'] = $this->resolver_iva_id_de_articulos($parseado['articulos']);
        $parseado['factura']   = $this->resolver_iva_ids($parseado['factura']);

        return [
            'version'             => self::VERSION_CONTRATO,
            'paginas_procesadas'  => $preparacion['paginas'],
            'columnas_detectadas' => $parseado['columnas_detectadas'],
            'articulos'           => $parseado['articulos'],
            'factura'             => $parseado['factura'],
            'avisos'              => $parseado['avisos'],
            'tokens'              => $tokens,
            /*
             * Rastro para medir cuántas facturas se revisan y por qué. Solo modelos y un motivo de
             * una lista cerrada: ningún dato de la factura. La pantalla de revisión no lo usa.
             */
            'lectura'             => [
                'modelo'           => $ganadora['modelo'],
                'revisada'         => !is_null($motivo),
                'motivo_revision'  => $motivo,
                'primer_modelo'    => $primera['modelo'],
                'sigue_sin_cerrar' => $sigue_sin_cerrar,
            ],
        ];
    }

    /**
     * Modelo de la primera lectura.
     *
     * El fallback por si config() viene vacío tiene que decir lo MISMO que el default de
     * config/services.php ('escaneo_factura_compra.model') y que el .env.example: son tres lugares
     * con el mismo valor, y desincronizarlos hace que el modelo real dependa de cuál de los tres
     * ganó, que es lo peor de los dos mundos.
     *
     * @return string
     */
    protected function modelo_de_lectura(): string
    {
        $modelo = trim((string) config('services.escaneo_factura_compra.model'));

        return $modelo !== '' ? $modelo : 'claude-haiku-5-5';
    }

    /**
     * Modelo de la revisión. Mismo criterio de tres lugares que modelo_de_lectura().
     *
     * @return string
     */
    protected function modelo_de_revision(): string
    {
        $modelo = trim((string) config('services.escaneo_factura_compra.model_revision'));

        return $modelo !== '' ? $modelo : 'claude-sonnet-5-5';
    }

    /**
     * Hace una lectura completa de la factura con un modelo y devuelve cómo salió, sin lanzar: quien
     * llama decide si una lectura fallida se revisa o llega al usuario.
     *
     * Se registra el consumo apenas hay respuesta, ANTES de mirar si sirve: una lectura cortada o
     * con el JSON roto también se paga, y sin su fila el escaneo revisado parecería más barato de
     * lo que fue.
     *
     * Las respuestas con `stop_reason` max_tokens y refusal se tratan como lecturas fallidas de
     * entrada, sin depender de que el parseo reviente: una respuesta cortada puede traer un JSON
     * que por casualidad decodifica y le falta la mitad de los renglones.
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @param  array                          $imagenes  Salida de preparar_imagenes()
     * @param  string                         $modelo    Modelo a pedir
     * @return array  ['modelo'=>string,'parseado'=>array|null,'tokens'=>['input'=>int,'output'=>int],
     *                 'error'=>\RuntimeException|null,'motivo_falla'=>string|null]
     */
    protected function leer_factura(ProviderOrderScan $scan, array $imagenes, string $modelo): array
    {
        $lectura = [
            'modelo'       => $modelo,
            'parseado'     => null,
            'tokens'       => ['input' => 0, 'output' => 0],
            'error'        => null,
            'motivo_falla' => null,
        ];

        /* Avanza junto con el proceso, para saber en qué punto se cayó si salta la excepción. */
        $motivo_si_falla = 'error_de_la_api';

        try {
            $response = $this->llamar_a_claude($scan, $imagenes, $modelo);

            $motivo_si_falla = 'respuesta_invalida';

            $body = $response->json();

            if (!is_array($body)) {
                throw new \RuntimeException('La IA devolvió una respuesta que no se pudo interpretar.');
            }

            /*
             * `model` de la respuesta es quien la produjo: con un fallback de servidor puede no ser
             * el pedido. Se contabiliza con ese, que es el que se cobró.
             */
            if (isset($body['model']) && trim((string) $body['model']) !== '') {
                $lectura['modelo'] = trim((string) $body['model']);
            }

            /*
             * Registro de consumo (§3.5). registrar() nunca lanza: es contabilidad de fondo y
             * no puede voltear un escaneo que ya está hecho.
             */
            AiTokenUsageHelper::registrar([
                'user_id'       => $scan->user_id,
                'auth_user_id'  => $scan->auth_user_id,
                'proceso'       => 'escaneo_factura_compra',
                'body'          => $body,
                'modelo'        => $lectura['modelo'],
                'referencia_id' => $scan->id,
            ]);

            $usage = isset($body['usage']) && is_array($body['usage']) ? $body['usage'] : [];

            $lectura['tokens'] = [
                'input'  => isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : 0,
                'output' => isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : 0,
            ];

            $stop_reason = isset($body['stop_reason']) ? (string) $body['stop_reason'] : '';

            if ($stop_reason === 'refusal') {
                $motivo_si_falla = 'rechazo_del_modelo';

                throw new \RuntimeException('La IA no devolvió contenido de texto.');
            }

            if ($stop_reason === 'max_tokens') {
                $motivo_si_falla = 'corte_por_max_tokens';

                throw new \RuntimeException('La IA no devolvió un JSON válido con los datos de la factura: la respuesta se cortó antes de terminar.');
            }

            $lectura['parseado'] = $this->parse_respuesta($body);
        } catch (\RuntimeException $e) {
            $lectura['error']        = $e;
            $lectura['motivo_falla'] = $motivo_si_falla;
        }

        return $lectura;
    }

    /**
     * Decide si una lectura necesita que otro modelo relea la factura. Devuelve el motivo (de una
     * lista cerrada, va al rastro) o null si la lectura pasa.
     *
     * 🔴 Es el control del que depende toda la misión. Medido con 10 facturas reales y 30 lecturas
     * del modelo económico: detectó las 4 lecturas malas de 4, sin falsas alarmas. Lo que se le
     * escapa a ese modelo no es leer sino interpretar (una bonificación 50 % + 15 % tomada como
     * 50 %, el costo neto escrito como unitario con el descuento encima), y eso se ve solo porque
     * el total del renglón impreso en el papel deja de cerrar con lo demás que leyó.
     *
     * Revisa si:
     *   · la lectura falló: error de la API, respuesta sin JSON válido, cortada o rechazada;
     *   · no trae ningún artículo;
     *   · algún renglón no da la cuenta (ver renglon_cierra());
     *   · dos renglones son iguales (ver clave_de_renglon());
     *   · más de la mitad de los renglones no tienen con qué hacer la cuenta, porque entonces el
     *     control no está controlando nada;
     *   · la suma de los renglones no llega a lo que dice el comprobante (ver
     *     importe_de_referencia_de_los_renglones());
     *   · el total impreso no cierra con su desglose (ver aviso_de_total_que_no_cuadra()).
     *
     * @param  array  $lectura  Salida de leer_factura()
     * @return string|null
     */
    protected function necesita_revision(array $lectura)
    {
        if (!is_null($lectura['motivo_falla'])) {
            return $lectura['motivo_falla'];
        }

        return $this->controlar_lectura($lectura['parseado'])['motivo'];
    }

    /**
     * Corre el control sobre una lectura ya parseada y devuelve TODO lo que encontró, no solo lo
     * primero: la primera lectura solo necesita saber si se relee, pero la que queda después de la
     * revisión tiene que avisar de cada cosa (ver anotar_lo_que_sigue_sin_cerrar()).
     *
     * `motivo` es el de mayor prioridad: los renglones antes que los totales, porque el costo mal de un
     * renglón es lo que más se propaga (cuenta corriente, y con "actualizar precios", precios de venta).
     *
     * @param  array  $parseado  Salida de parse_respuesta()
     * @return array  ['motivo'=>string|null,'no_cierran'=>int[],'repetidos'=>int[],'sin_datos'=>bool,
     *                 'faltan_renglones'=>bool,'suma'=>float,'referencia'=>float|null]
     */
    protected function controlar_lectura(array $parseado): array
    {
        $articulos = $parseado['articulos'];

        $control = [
            'motivo'           => null,
            'no_cierran'       => [],
            'repetidos'        => [],
            'sin_datos'        => false,
            'faltan_renglones' => false,
            'suma'             => 0.0,
            'referencia'       => null,
        ];

        if (count($articulos) === 0) {
            $control['motivo'] = 'sin_articulos';

            return $control;
        }

        $sin_cuenta    = 0;
        $suma_completa = true;
        $vistos        = [];

        foreach ($articulos as $indice => $articulo) {
            $importe = $this->importe_del_renglon($articulo);

            if (is_null($importe)) {
                $suma_completa = false;
            } else {
                $control['suma'] += $importe;
            }

            if (is_null($articulo['cantidad']) || is_null($articulo['costo_unitario']) || is_null($articulo['total_linea'])) {
                $sin_cuenta++;
            } elseif (!$this->renglon_cierra($articulo)) {
                $control['no_cierran'][] = $indice;
            }

            $clave = $this->clave_de_renglon($articulo);

            if (!is_null($clave)) {
                if (isset($vistos[$clave])) {
                    $control['repetidos'][] = $vistos[$clave];
                    $control['repetidos'][] = $indice;
                } else {
                    $vistos[$clave] = $indice;
                }
            }
        }

        $control['sin_datos'] = $sin_cuenta * 2 > count($articulos);

        /* Un renglón sin total ni cuenta posible deja la suma corta por eso, no porque falten renglones. */
        if ($suma_completa) {
            $control['referencia']       = $this->importe_de_referencia_de_los_renglones($parseado['factura']);
            $control['faltan_renglones'] = $this->faltan_renglones($control['suma'], $control['referencia']);
        }

        if (count($control['no_cierran']) > 0) {
            $control['motivo'] = 'renglon_no_cierra';
        } elseif (count($control['repetidos']) > 0) {
            $control['motivo'] = 'renglon_repetido';
        } elseif ($control['sin_datos']) {
            $control['motivo'] = 'renglones_sin_datos';
        } elseif ($control['faltan_renglones']) {
            $control['motivo'] = 'faltan_renglones';
        } elseif (!is_null($this->aviso_de_total_que_no_cuadra($parseado['factura']))) {
            /*
             * 🔴 La cabecera también termina en la deuda: el total que se guarda sale del desglose más
             * las percepciones (ProviderOrderScanController::guardar_factura()). Medido en 7 facturas A:
             * el total y su desglose dan exacto o se alejan un 3,2 %, sin nada en el medio, y ese 3,2 %
             * es una percepción de pie que esa lectura perdió (otras lecturas de la misma factura la
             * traen justa; se rotula, por ejemplo, "Regímenes Especiales"). No hubo falsas alarmas. Desde
             * la regla 9 del prompt ninguna lectura la perdió en 60 escaneos reales de 10 facturas. Si
             * vuelve a pasar y la revisión tampoco la trae, queda el aviso.
             */
            $control['motivo'] = 'total_no_cuadra';
        }

        return $control;
    }

    /**
     * Importe de un renglón para sumarlo contra el comprobante: el impreso, o si no se leyó, la cuenta
     * con lo que sí se leyó. Null si tampoco hay con qué hacerla.
     *
     * @param  array  $articulo  Renglón normalizado
     * @return float|null
     */
    protected function importe_del_renglon(array $articulo)
    {
        if (!is_null($articulo['total_linea'])) {
            return (float) $articulo['total_linea'];
        }

        if (is_null($articulo['cantidad']) || is_null($articulo['costo_unitario'])) {
            return null;
        }

        $descuento = is_null($articulo['descuento_porcentaje']) ? 0.0 : (float) $articulo['descuento_porcentaje'];

        return (float) $articulo['cantidad'] * (float) $articulo['costo_unitario'] * (1 - $descuento / 100);
    }

    /**
     * Clave para reconocer dos renglones iguales: mismo código, cantidad y costo. Null si falta alguno
     * de los tres (sin código, vale el nombre: hay facturas que no traen códigos).
     *
     * 🔴 Por qué importa: el solape entre tramos puede hacer que un renglón se lea dos veces. Las dos filas
     * cierran su cuenta, y una suma de más contra el neto se confunde con una bonificación de pie, así
     * que ningún otro control lo ve. Y al confirmar hace daño en silencio: la compra guarda una sola
     * fila por artículo, pero el stock se mueve por cada renglón (NewProviderOrderHelper::update_article()),
     * así que entra el doble de stock con la deuda de uno.
     *
     * @param  array  $articulo  Renglón normalizado
     * @return string|null
     */
    protected function clave_de_renglon(array $articulo)
    {
        $codigo = $articulo['codigo_proveedor'];

        if (is_null($codigo)) {
            $codigo = $articulo['bar_code'];
        }

        if (is_null($codigo)) {
            $codigo = $articulo['nombre'];
        }

        if (is_null($codigo) || is_null($articulo['cantidad']) || is_null($articulo['costo_unitario'])) {
            return null;
        }

        return mb_strtolower(preg_replace('/\s+/', '', $codigo)) . '|' . (float) $articulo['cantidad'] . '|' . (float) $articulo['costo_unitario'];
    }

    /**
     * ¿Cierra `cantidad × costo × (1 − descuento) = total del renglón`? Un descuento null es que el
     * renglón no tiene descuento.
     *
     * @param  array  $articulo  Renglón normalizado, con cantidad, costo y total no nulos
     * @return bool
     */
    protected function renglon_cierra(array $articulo): bool
    {
        $descuento = is_null($articulo['descuento_porcentaje']) ? 0.0 : (float) $articulo['descuento_porcentaje'];

        $calculado = (float) $articulo['cantidad'] * (float) $articulo['costo_unitario'] * (1 - $descuento / 100);
        $impreso   = (float) $articulo['total_linea'];

        return abs($calculado - $impreso) <= $this->tolerancia_del_renglon((float) $articulo['cantidad'], $impreso);
    }

    /**
     * Cuánto puede alejarse la cuenta de un renglón sin que valga la pena pagar otra lectura. El mayor de:
     *
     *   · El 1 % del total.
     *   · Un piso de 2, acotado al 5 % del total. Sin ese tope no escala: en una factura en dólares,
     *     1 × 3,50 leído 5,00 pasaba con un 43 % de error, porque 1,50 entra en un piso de 2.
     *   · Los centavos de redondeo: el unitario impreso viene redondeado a dos decimales, y ese medio
     *     centavo se multiplica por la cantidad. 1000 × 0,125 se imprime 0,13 y da 5 de diferencia.
     *
     * @param  float  $cantidad
     * @param  float  $total
     * @return float
     */
    protected function tolerancia_del_renglon(float $cantidad, float $total): float
    {
        return max(
            abs($total) * 0.01,
            min(2, abs($total) * 0.05),
            0.01 + 0.005 * abs($cantidad)
        );
    }

    /** Identifica una bonificación informativa cuando el unitario ya incluye el descuento. */
    protected function unitario_ya_bonificado(array $fila): bool
    {
        if ($fila['cantidad'] === null || $fila['costo_unitario'] === null || $fila['total_linea'] === null
            || $fila['descuento_porcentaje'] <= 0 || $fila['descuento_porcentaje'] > 100) {
            return false;
        }

        $importe = $fila['cantidad'] * $fila['costo_unitario'];
        $impreso = $fila['total_linea'];
        $redondeo = 0.01 + 0.005 * abs($fila['cantidad']);
        $con_descuento = $importe * (1 - $fila['descuento_porcentaje'] / 100);

        return abs($importe - $impreso) <= $redondeo
            && abs($con_descuento - $impreso) > $this->tolerancia_del_renglon($fila['cantidad'], $impreso);
    }

    /**
     * Importe del comprobante contra el que se compara la suma de los renglones, o null si la lectura no
     * trae con qué. Tiene que estar en la misma base que los renglones, y depende del comprobante:
     *
     *   · Factura B o C: no discriminan IVA, así que los renglones lo traen adentro y su base es
     *     `total − percepciones`. Contra el neto no serviría: si lo leyó, la suma le queda un 21 % arriba
     *     y eso tapa cualquier renglón perdido.
     *   · Con neto: el neto. No incluye impuestos, igual que los renglones de una factura A.
     *   · Sin neto: `total − percepciones − IVA`. Un remito sin desglose queda en el total, que es lo único
     *     que tiene.
     *
     * Se decide por el tipo que leyó el modelo y no por lo cerca que quede la suma de cada importe:
     * una bonificación de pie deja la suma de una factura A más cerca del neto con IVA que del neto, y
     * eso daría una falsa alarma en facturas bien leídas (medido).
     *
     * @param  array  $factura  Factura normalizada
     * @return float|null
     */
    protected function importe_de_referencia_de_los_renglones(array $factura)
    {
        $neto  = isset($factura['neto_gravado']) ? (float) $factura['neto_gravado'] : 0.0;
        $total = isset($factura['total']) ? (float) $factura['total'] : 0.0;
        $iva   = isset($factura['total_iva']) ? (float) $factura['total_iva'] : 0.0;

        $percepciones = (isset($factura['percepcion_iibb']) ? (float) $factura['percepcion_iibb'] : 0.0)
                      + (isset($factura['percepcion_iva']) ? (float) $factura['percepcion_iva'] : 0.0);

        $tipo = isset($factura['tipo_comprobante']) ? (string) $factura['tipo_comprobante'] : '';

        if ($total > 0 && preg_match('/\b[BC]\b/i', $tipo) === 1) {
            return $total - $percepciones;
        }

        if ($neto > 0) {
            return $neto;
        }

        if ($total > 0) {
            return $total - $percepciones - $iva;
        }

        return null;
    }

    /**
     * ¿La suma de los renglones queda por debajo de lo que dice el comprobante? Eso es que faltan
     * renglones, lo único que la cuenta por renglón no puede ver.
     *
     * 🔴 Se compara para UN SOLO LADO, y no es un olvido. El comprobante no trae un "subtotal" en el
     * contrato: lo más parecido es `neto_gravado`, que no incluye impuestos pero sí está DESPUÉS de
     * las bonificaciones de pie. En las facturas A medidas, dos de siete tienen la suma de renglones
     * bien leída hasta un 18 % ARRIBA del neto por una bonificación general, así que "se aleja
     * para arriba" no dice nada: comparado para los dos lados mandaría a revisar una de cada cinco
     * facturas sin motivo. Para abajo, en cambio, una bonificación nunca explica la diferencia. En
     * las lecturas guardadas no saltó ninguna vez.
     *
     * @param  float       $suma        Suma de los importes de todos los renglones
     * @param  float|null  $referencia  Salida de importe_de_referencia_de_los_renglones()
     * @return bool
     */
    protected function faltan_renglones(float $suma, $referencia): bool
    {
        if (is_null($referencia) || $referencia <= 0) {
            return false;
        }

        $diferencia = $referencia - $suma;

        return $diferencia > $referencia * 0.01 && $diferencia > abs($suma) * 0.01;
    }

    /**
     * Deja a la vista lo que la revisión no pudo resolver: un aviso por cada problema, en `avisos`, que
     * la pantalla de revisión ya muestra arriba de todo, y los renglones afectados en `campos_dudosos`,
     * que los pinta. No corrige ni saca nada: dos lecturas dijeron lo mismo, y quien puede decidir es
     * quien tiene el papel adelante. Un renglón repetido, por ejemplo, puede ser real.
     *
     * El total que no cuadra no se agrega acá: parse_respuesta() ya puso su aviso y marcó el campo.
     *
     * @param  array  $parseado  Salida de parse_respuesta()
     * @return array  El mismo $parseado, con los avisos y los campos dudosos sumados
     */
    protected function anotar_lo_que_sigue_sin_cerrar(array $parseado): array
    {
        $control = $this->controlar_lectura($parseado);
        $avisos  = [];

        if ($control['motivo'] === 'sin_articulos') {
            $avisos[] = 'No se pudo leer ningún artículo de la factura, ni volviéndola a leer.';
        }

        if (count($control['no_cierran']) > 0) {
            $filas = $this->nombrar_filas($parseado['articulos'], $control['no_cierran']);

            $avisos[] = $filas['sujeto'] . ($filas['plural'] ? ' no cierran' : ' no cierra') . ': la cantidad por el costo con el ' .
                        'descuento no da el total de la línea, y volver a leer la factura no lo resolvió. Revisá esos ' .
                        'importes contra el papel antes de confirmar: el costo que quede es el que entra a la cuenta corriente.';

            $parseado['articulos'] = $this->marcar_como_dudosos(
                $parseado['articulos'],
                $control['no_cierran'],
                ['cantidad', 'costo_unitario', 'descuento_porcentaje', 'total_linea']
            );
        }

        if (count($control['repetidos']) > 0) {
            $filas = $this->nombrar_filas($parseado['articulos'], $control['repetidos']);

            $avisos[] = $filas['sujeto'] . ($filas['plural'] ? ' traen' : ' trae') . ' el mismo código, cantidad y costo. Puede ser ' .
                        'real o un renglón leído dos veces: si está de más, sacalo antes de confirmar, porque el stock ' .
                        'se sumaría dos veces.';

            $parseado['articulos'] = $this->marcar_como_dudosos($parseado['articulos'], $control['repetidos'], ['cantidad']);
        }

        if ($control['sin_datos']) {
            $avisos[] = 'Más de la mitad de los renglones no tiene cantidad, costo o total, así que no se pudieron ' .
                        'controlar. Revisalos contra el papel antes de confirmar.';
        }

        if ($control['faltan_renglones']) {
            $avisos[] = 'Los renglones suman ' . number_format($control['suma'], 2, ',', '.') . ' y el comprobante dice ' .
                        number_format((float) $control['referencia'], 2, ',', '.') . ': puede faltar algún renglón, y volver a ' .
                        'leer la factura no lo resolvió. Comparalo contra el papel antes de confirmar.';
        }

        $parseado['avisos'] = array_merge($parseado['avisos'], $avisos);

        return $parseado;
    }

    /**
     * Cómo nombrar unos renglones en un aviso, con el número de fila que muestra la pantalla de revisión.
     *
     * @param  array  $articulos
     * @param  int[]  $indices
     * @return array  ['sujeto'=>string,'plural'=>bool]
     */
    protected function nombrar_filas(array $articulos, array $indices): array
    {
        $numeros = [];

        foreach ($indices as $indice) {
            $numeros[] = (int) $articulos[$indice]['fila'];
        }

        $numeros = array_values(array_unique($numeros));

        sort($numeros);

        if (count($numeros) === 1) {
            return ['sujeto' => 'La fila ' . $numeros[0], 'plural' => false];
        }

        $ultimo = array_pop($numeros);

        return ['sujeto' => 'Las filas ' . implode(', ', $numeros) . ' y ' . $ultimo, 'plural' => true];
    }

    /**
     * Suma campos a `campos_dudosos` de unos renglones, que es lo que la pantalla de revisión pinta.
     *
     * @param  array     $articulos
     * @param  int[]     $indices
     * @param  string[]  $campos
     * @return array
     */
    protected function marcar_como_dudosos(array $articulos, array $indices, array $campos): array
    {
        foreach (array_unique($indices) as $indice) {
            $articulos[$indice]['campos_dudosos'] = array_values(array_unique(
                array_merge($articulos[$indice]['campos_dudosos'], $campos)
            ));
        }

        return $articulos;
    }

    /**
     * Deja en el log qué modelo quedó y por qué se revisó, también cuando el escaneo termina en
     * error (ahí no hay `resultado` donde quede). Es lo que permite medir después cuántas facturas
     * se revisan. Ningún dato de la factura: solo ids, modelos, un motivo y tokens.
     *
     * 🔴 En `warning` si hubo revisión o error, y no es por la gravedad: el piso de log de casi todas
     * las instalaciones es `warning` (ver config/logging.php), así que en `info` este rastro no se
     * escribe justo donde hace falta medirlo. La lectura que pasó de una queda en `info`: sería una
     * línea por escaneo y no agrega nada.
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @param  array                          $primera   Salida de leer_factura()
     * @param  array                          $ganadora  Salida de leer_factura()
     * @param  string|null                    $motivo    Salida de necesita_revision() para la primera
     * @param  string|null                    $sigue_sin_cerrar  Salida de necesita_revision() para la revisión
     * @return void
     */
    protected function dejar_rastro_de_la_lectura(ProviderOrderScan $scan, array $primera, array $ganadora, $motivo, $sigue_sin_cerrar)
    {
        $contexto = [
            'provider_order_scan_id' => $scan->id,
            'primer_modelo'          => $primera['modelo'],
            'modelo'                 => $ganadora['modelo'],
            'revisada'               => !is_null($motivo),
            'motivo_revision'        => $motivo,
            'sigue_sin_cerrar'       => $sigue_sin_cerrar,
            'termino_en_error'       => !is_null($ganadora['error']),
            'motivo_del_error'       => $ganadora['motivo_falla'],
            'tokens_primera'         => $primera['tokens'],
            'tokens_ganadora'        => $ganadora['tokens'],
        ];

        if (!is_null($motivo) || !is_null($ganadora['error'])) {
            Log::warning('EscaneoFacturaCompraService: lectura terminada', $contexto);

            return;
        }

        Log::info('EscaneoFacturaCompraService: lectura terminada', $contexto);
    }

    /**
     * Lee las fotos del escaneo de disco y las deja listas para mandar, en base64.
     *
     * Primero intenta agrandarla y cortarla en tramos (ver tramos_de_la_foto()), que es lo que
     * le permite al modelo económico leer bien los códigos. Si eso no suma, no entra en memoria
     * o falla por cualquier motivo, la foto va como siempre fue: redimensionada al lado mayor
     * configurado y en webp.
     *
     * Las fotos ya se guardaron redimensionadas y en webp cuando el usuario las subió, así
     * que el camino de siempre normalmente no cambia nada (el `upsize` del constraint impide
     * agrandar). Se hace igual porque el servicio no puede confiar en cómo quedó el archivo en
     * disco: una foto guardada por una versión anterior, o un archivo tocado a mano, tiene que
     * llegar igual a la API con un media_type que Anthropic acepte.
     *
     * Camino de respaldo: si Intervention no puede procesar el archivo (GD sin soporte de
     * webp, imagen corrupta), se manda el binario original con el mime que guardó el
     * controlador, siempre que sea uno de los que la API acepta. Una foto ilegible se saltea
     * con un aviso, no tumba el escaneo entero: cinco páginas buenas y una borrosa siguen
     * valiendo la pena.
     *
     * Cada elemento de `imagenes` es una imagen a mandar, que puede ser una foto entera o un tramo.
     * `pagina` es la posición de la foto entre las que se pudieron leer, que es lo que dice el rótulo;
     * `paginas` cuenta hojas, no imágenes.
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @return array  ['imagenes' => [['orden'=>int,'pagina'=>int,'tramo'=>int,'tramos'=>int,'media_type'=>string,'base64'=>string], ...],
     *                 'avisos' => string[], 'paginas' => int]
     */
    protected function preparar_imagenes(ProviderOrderScan $scan): array
    {
        $max_side = (int) config('services.escaneo_factura_compra.max_side');
        $max_side = $max_side > 0 ? $max_side : 1568;

        $imagenes         = [];
        $avisos           = [];
        $paginas          = 0;
        $bytes_acumulados = 0;

        $filas = $scan->images()->orderBy('orden')->get();

        foreach ($filas as $image) {
            $ruta = storage_path('app/' . $image->path);

            if (!is_file($ruta)) {
                Log::warning('EscaneoFacturaCompraService: falta el archivo de una foto del escaneo', [
                    'provider_order_scan_id' => $scan->id,
                    'orden'                  => $image->orden,
                    'path'                   => $image->path,
                ]);

                $avisos[] = 'No se encontró el archivo de la página ' . $image->orden . '; se analizó el resto.';
                continue;
            }

            $binario = @file_get_contents($ruta);

            if ($binario === false || $binario === '') {
                $avisos[] = 'No se pudo leer la página ' . $image->orden . '; se analizó el resto.';
                continue;
            }

            $tramos = $this->tramos_de_la_foto($binario, $max_side, $scan, (int) $image->orden);

            if (count($tramos) > 0 && !$this->alcanza_para_enviar($tramos, $bytes_acumulados)) {
                Log::warning('EscaneoFacturaCompraService: los tramos de una foto no entran en el pedido; va como siempre', [
                    'provider_order_scan_id' => $scan->id,
                    'orden'                  => $image->orden,
                ]);

                $tramos = [];
            }

            if (count($tramos) > 0) {
                $paginas++;

                foreach ($tramos as $indice => $png) {
                    $bytes_acumulados += strlen($png);

                    $imagenes[] = [
                        'orden'      => (int) $image->orden,
                        'pagina'     => $paginas,
                        'tramo'      => $indice + 1,
                        'tramos'     => count($tramos),
                        'media_type' => 'image/png',
                        'base64'     => base64_encode($png),
                    ];
                }

                continue;
            }

            $media_type = 'image/webp';
            $encodeada  = null;

            try {
                $manager = new ImageManager();
                $img     = $manager->make($binario);

                /*
                 * 1568 px de lado mayor, no 512 como la validación de imágenes de producto:
                 * para leer un código de artículo de ocho caracteres impreso chico hace falta
                 * resolución. `upsize` evita agrandar una foto que ya es más chica (agrandar
                 * solo suma tokens, no detalle).
                 */
                $img->resize($max_side, $max_side, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                });

                $encodeada = (string) $img->encode('webp', 80);

                if ($encodeada === '') {
                    $encodeada = null;
                }
            } catch (\Exception $e) {
                Log::info('EscaneoFacturaCompraService: Intervention no pudo procesar una foto; se manda el original', [
                    'provider_order_scan_id' => $scan->id,
                    'orden'                  => $image->orden,
                    'error'                  => $e->getMessage(),
                ]);

                $encodeada = null;
            }

            if (is_null($encodeada)) {
                /* Respaldo: el binario original, si su mime es uno de los que la API acepta. */
                $mime_guardado = strtolower(trim((string) $image->mime));

                if (!in_array($mime_guardado, self::MEDIA_TYPES_ACEPTADOS, true)) {
                    $avisos[] = 'No se pudo procesar la página ' . $image->orden . '; se analizó el resto.';
                    continue;
                }

                $encodeada  = $binario;
                $media_type = $mime_guardado;
            }

            $paginas++;
            $bytes_acumulados += strlen($encodeada);

            $imagenes[] = [
                'orden'      => (int) $image->orden,
                'pagina'     => $paginas,
                'tramo'      => 1,
                'tramos'     => 1,
                'media_type' => $media_type,
                'base64'     => base64_encode($encodeada),
            ];
        }

        return ['imagenes' => $imagenes, 'avisos' => $avisos, 'paginas' => $paginas];
    }

    /**
     * Agranda una foto y la corta en tramos, y devuelve el PNG de cada uno, de arriba hacia abajo.
     * Vacío significa "mandala como siempre": no hacía falta, no entra en memoria o algo falló.
     *
     * Medido con facturas reales: el modelo económico lee mal los códigos impresos chicos en la foto
     * tal cual (un dígito de menos, un 5 leído como 6), y agrandada deja de hacerlo. Se corta porque
     * la API achica por su cuenta las imágenes demasiado grandes: sin tramos, una hoja agrandada
     * volvería a achicarse y el agrandado se perdería.
     *
     * PNG y no webp porque es con lo que se midió, y no agrega pérdida sobre una foto que ya la tiene.
     *
     * Se agranda con el resize de Intervention (imagecopyresampled) y no con imagescale() bicúbico,
     * aunque la medición se hizo con el bicúbico de otra librería: comparado contra ese, el resize de
     * GD queda más cerca (30 a 41 dB, contra 16 a 35 del bicúbico de GD) y pesa menos de la mitad.
     *
     * 🔴 LA MEMORIA MANDA ACÁ. Un error fatal por memoria no se atrapa con ningún catch: tumba el
     * worker con el escaneo colgado y lo que hubiera en la cola. Por eso se calcula ANTES de agrandar,
     * y por eso se recorta con imagecrop(), que copia solo ese pedazo, y no clonando el bitmap entero
     * por tramo. Cada bitmap se libera apenas deja de hacer falta. Una foto ancha no se agranda.
     *
     * Trabaja con GD directo porque `new ImageManager()` sin configuración usa siempre ese driver, y
     * porque Intervention recorta en el lugar: cortar ahí perdería el resto de la imagen.
     *
     * @param  string                         $binario   Contenido del archivo
     * @param  int                            $max_side  Lado mayor configurado
     * @param  \App\Models\ProviderOrderScan  $scan
     * @param  int                            $orden     Orden de la foto, solo para el log
     * @return string[]
     */
    protected function tramos_de_la_foto(string $binario, int $max_side, ProviderOrderScan $scan, int $orden): array
    {
        $img = null;

        try {
            $manager = new ImageManager();
            $img     = $manager->make($binario);

            /* El mismo tope de siempre: una foto más grande que el configurado baja primero. */
            $img->resize($max_side, $max_side, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });

            $medidas = $this->medidas_agrandadas($img->width(), $img->height());

            /* Ni suma agrandarla ni hay nada que cortar: es una foto para mandar como siempre. */
            if (!$medidas['agrandar'] && $medidas['alto'] <= self::ALTO_TRAMO) {
                return [];
            }

            /*
             * El bitmap final, un tramo y su PNG conviven con la imagen que ya está en memoria,
             * contada dentro de memory_get_usage().
             */
            $bytes_bitmap = $medidas['ancho'] * $medidas['alto'] * 4;
            $bytes_tramo  = $medidas['ancho'] * min($medidas['alto'], self::ALTO_TRAMO) * 4;

            if (!$this->hay_memoria_para(($bytes_bitmap + $bytes_tramo + self::MAX_BYTES_POR_TRAMO) * self::FACTOR_MEMORIA)) {
                Log::warning('EscaneoFacturaCompraService: sin memoria para agrandar una foto; va como siempre', [
                    'provider_order_scan_id' => $scan->id,
                    'orden'                  => $orden,
                ]);

                return [];
            }

            if ($medidas['agrandar']) {
                $img->resize($medidas['ancho'], $medidas['alto']);
            }

            return $this->cortar_en_tramos($img->getCore());
        } catch (\Throwable $e) {
            Log::warning('EscaneoFacturaCompraService: no se pudo agrandar una foto; va como siempre', [
                'provider_order_scan_id' => $scan->id,
                'orden'                  => $orden,
                'error'                  => $e->getMessage(),
            ]);

            return [];
        } finally {
            if (!is_null($img)) {
                $img->destroy();
            }
        }
    }

    /**
     * Medidas a las que hay que llevar una foto: el ancho llega a ANCHO_MAXIMO_AGRANDADA con una escala
     * de hasta ESCALA_MAXIMA. Si la escala no suma, la foto queda como está.
     *
     * @param  int  $ancho
     * @param  int  $alto
     * @return array  ['ancho'=>int,'alto'=>int,'agrandar'=>bool]
     */
    protected function medidas_agrandadas(int $ancho, int $alto): array
    {
        $escala = min(self::ESCALA_MAXIMA, self::ANCHO_MAXIMO_AGRANDADA / $ancho);

        if ($escala < self::ESCALA_MINIMA_QUE_SUMA) {
            return ['ancho' => $ancho, 'alto' => $alto, 'agrandar' => false];
        }

        return [
            'ancho'    => (int) round($ancho * $escala),
            'alto'     => (int) round($alto * $escala),
            'agrandar' => true,
        ];
    }

    /**
     * Corta un bitmap en tramos solapados y devuelve el PNG de cada uno. Vacío si en algún momento no
     * hay memoria o un tramo pesa más de lo que la API acepta: una página con tramos faltantes
     * perdería renglones sin que nadie se entere, así que es todo o nada.
     *
     * El último tramo es lo que quede, aunque sea bajo: el criterio es el medido.
     *
     * @param  resource|\GdImage  $bitmap
     * @return string[]
     */
    protected function cortar_en_tramos($bitmap): array
    {
        $ancho = imagesx($bitmap);
        $alto  = imagesy($bitmap);

        $bytes_tramo = $ancho * min($alto, self::ALTO_TRAMO) * 4;

        $tramos = [];
        $desde  = 0;

        while (true) {
            /* El bitmap del tramo y dos PNG de los grandes: el que se arma y su paso a base64. */
            if (!$this->hay_memoria_para($bytes_tramo * self::FACTOR_MEMORIA + 2 * self::MAX_BYTES_POR_TRAMO)) {
                return [];
            }

            $hasta = min($desde + self::ALTO_TRAMO, $alto);

            $tramo = imagecrop($bitmap, ['x' => 0, 'y' => $desde, 'width' => $ancho, 'height' => $hasta - $desde]);

            if ($tramo === false) {
                return [];
            }

            $png = $this->png_de($tramo);

            /* El unset es lo que lo libera en PHP 8, donde ya no es un recurso. */
            $this->liberar_bitmap($tramo);
            unset($tramo);

            if ($png === '' || strlen($png) > self::MAX_BYTES_POR_TRAMO) {
                return [];
            }

            $tramos[] = $png;

            if ($hasta >= $alto) {
                return $tramos;
            }

            $desde = $hasta - self::SOLAPE_TRAMO;
        }
    }

    /**
     * PNG de un bitmap. imagepng() escribe en la salida estándar, así que se captura con un buffer, que
     * se cierra aunque GD lance: un buffer abierto de más se tragaría todo lo que el worker imprima
     * después.
     *
     * @param  resource|\GdImage  $bitmap
     * @return string
     */
    protected function png_de($bitmap): string
    {
        $nivel = ob_get_level();

        ob_start();

        try {
            imagepng($bitmap);

            return (string) ob_get_contents();
        } finally {
            while (ob_get_level() > $nivel) {
                ob_end_clean();
            }
        }
    }

    /**
     * Libera un bitmap de GD. En PHP 7.4 es un recurso y hay que destruirlo; desde PHP 8 es un objeto
     * que se libera solo al perder la referencia, y a imagedestroy() lo deprecan más adelante.
     *
     * @param  resource|\GdImage  $bitmap
     * @return void
     */
    protected function liberar_bitmap($bitmap)
    {
        if (is_resource($bitmap)) {
            imagedestroy($bitmap);
        }
    }

    /**
     * ¿Entran estos tramos en el pedido a la API y en la memoria que hace falta para mandarlo?
     *
     * Al enviar, el base64 de todas las imágenes convive con dos copias más: el JSON del cuerpo y lo que
     * arma el cliente HTTP. Medido con fotos reales, seis páginas son unos 13 MB de base64 y entran
     * cómodas en un worker de 128 MB; este control es para las fotos que pesen mucho más que esas.
     *
     * @param  string[]  $tramos            PNG de esta foto
     * @param  int       $bytes_acumulados  Bytes crudos de las imágenes anteriores
     * @return bool
     */
    protected function alcanza_para_enviar(array $tramos, int $bytes_acumulados): bool
    {
        $total = $bytes_acumulados;

        foreach ($tramos as $png) {
            $total += strlen($png);
        }

        if ($total > static::MAX_BYTES_POR_ESCANEO) {
            return false;
        }

        return $this->hay_memoria_para(2 * $total * 4 / 3);
    }

    /**
     * ¿Se pueden pedir estos bytes sin pasarse del memory_limit? Mismo criterio que
     * ImageCropHelper::has_memory_to_copy(), que es privado y recibe una imagen ya abierta.
     *
     * @param  int|float  $bytes
     * @return bool
     */
    protected function hay_memoria_para($bytes): bool
    {
        $limite = $this->limite_de_memoria();

        if ($limite < 0) {
            return true;
        }

        return memory_get_usage(true) + $bytes <= $limite;
    }

    /**
     * memory_limit de PHP en bytes.
     *
     * @return int  Bytes; -1 si no hay límite ("-1", "0" o vacío).
     */
    protected function limite_de_memoria(): int
    {
        $crudo = trim((string) ini_get('memory_limit'));

        if ($crudo === '' || $crudo === '-1') {
            return -1;
        }

        $numero = (float) $crudo;
        $unidad = strtolower(substr($crudo, -1));

        if ($unidad === 'g') {
            $numero *= 1024 * 1024 * 1024;
        } elseif ($unidad === 'm') {
            $numero *= 1024 * 1024;
        } elseif ($unidad === 'k') {
            $numero *= 1024;
        }

        return $numero > 0 ? (int) $numero : -1;
    }

    /**
     * Deja el `pagina` de cada renglón dentro de las hojas que se mandaron. El rótulo y las
     * instrucciones ya le piden al modelo la página y no el tramo; esto cubre el caso en que igual
     * escriba el número de un tramo, para que la pantalla de revisión nunca vea la página 3 de 2.
     *
     * @param  array  $articulos
     * @param  int    $total_paginas
     * @return array
     */
    protected function acotar_paginas(array $articulos, int $total_paginas): array
    {
        foreach ($articulos as $indice => $articulo) {
            $articulos[$indice]['pagina'] = max(1, min($total_paginas, (int) $articulo['pagina']));
        }

        return $articulos;
    }

    /**
     * Hace la llamada de UNA lectura a Anthropic: una sola, con todas las páginas juntas.
     *
     * 🔴 Estructura del content, y el orden importa (§3.1):
     *   [ texto "PÁGINA 1 de 3", imagen, texto "PÁGINA 2 de 3", imagen, ..., instrucciones ]
     * Las instrucciones van al final, después de TODAS las imágenes, que es lo que
     * recomienda Anthropic para visión multi-imagen y lo que ya hace
     * ArticleImageValidationService::validate() (imagen primero, texto después).
     *
     * Una página cortada lleva un rótulo por tramo: "PÁGINA 1 de 3, TRAMO 2 de 3". La página va
     * primero y es la misma en todos sus tramos, porque ese número es el que el modelo tiene que
     * devolver en `pagina`.
     *
     * Sin `temperature`, `thinking` ni `tool_choice`, y a propósito: Haiku 5.5 y Sonnet 5.5 rechazan
     * con un 400 los valores no default de los parámetros de muestreo y el `tool_choice` forzado, y
     * los dos razonan por defecto. Los bloques de razonamiento que eso agrega al `content` los
     * saltea parse_respuesta(), que junta solo los de tipo `text`.
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @param  array                          $imagenes  Salida de preparar_imagenes()
     * @param  string                         $model     Modelo que hace esta lectura
     * @return \Illuminate\Http\Client\Response
     *
     * @throws \RuntimeException
     */
    protected function llamar_a_claude(ProviderOrderScan $scan, array $imagenes, string $model)
    {
        $api_key    = (string) config('services.anthropic.api_key');
        $timeout    = (int) config('services.escaneo_factura_compra.timeout');
        $max_tokens = (int) config('services.escaneo_factura_compra.max_tokens');

        $timeout    = $timeout > 0 ? $timeout : 180;
        $max_tokens = $max_tokens > 0 ? $max_tokens : 8000;

        $total      = count(array_unique(array_column($imagenes, 'pagina')));
        $hay_tramos = max(array_column($imagenes, 'tramos')) > 1;
        $content    = [];

        foreach ($imagenes as $imagen) {
            /*
             * El rótulo antes de cada imagen es lo que le permite a la IA decir en qué
             * página estaba cada renglón y unificar una fila cortada entre dos páginas.
             */
            $rotulo = 'PÁGINA ' . $imagen['pagina'] . ' de ' . $total;

            if ($imagen['tramos'] > 1) {
                $rotulo .= ', TRAMO ' . $imagen['tramo'] . ' de ' . $imagen['tramos'];
            }

            $content[] = [
                'type' => 'text',
                'text' => $rotulo,
            ];

            $content[] = [
                'type'   => 'image',
                'source' => [
                    'type'       => 'base64',
                    'media_type' => $imagen['media_type'],
                    'data'       => $imagen['base64'],
                ],
            ];
        }

        /* Las instrucciones, recién ahora, después de todas las páginas. */
        $content[] = [
            'type' => 'text',
            'text' => $this->build_user_prompt($scan, $total, $hay_tramos),
        ];

        $cuerpo = [
            'model'      => $model,
            'max_tokens' => $max_tokens,
            'system'     => $this->build_system_prompt(),
            'messages'   => [
                [
                    'role'    => 'user',
                    'content' => $content,
                ],
            ],
        ];

        $http = $this->build_anthropic_http_client($api_key);

        /*
         * Si el clasificador declina la lectura, que la reintente la propia API con otro modelo. El
         * que produjo la respuesta viene en `model`, y leer_factura() contabiliza con ese.
         */
        if (in_array($model, self::MODELOS_CON_FALLBACK_DE_SERVIDOR, true)) {
            $cuerpo['fallbacks'] = 'default';

            $http = $http->withHeaders(['anthropic-beta' => self::BETA_FALLBACK_DE_SERVIDOR]);
        }

        try {
            $response = $http
                ->timeout($timeout)
                ->post('https://api.anthropic.com/v1/messages', $cuerpo);
        } catch (\Exception $e) {
            Log::warning('EscaneoFacturaCompraService: error de conexión con Anthropic', [
                'provider_order_scan_id' => $scan->id,
                'modelo'                 => $model,
                'error'                  => $e->getMessage(),
            ]);

            throw new \RuntimeException('No se pudo conectar con el servicio de IA para leer la factura. Probá de nuevo en un rato.');
        }

        if (!$response->successful()) {
            $body      = $response->json();
            $api_error = isset($body['error']['message']) ? $body['error']['message'] : 'HTTP ' . $response->status();

            Log::warning('EscaneoFacturaCompraService: Anthropic respondió con error', [
                'provider_order_scan_id' => $scan->id,
                'modelo'                 => $model,
                'status'                 => $response->status(),
                'error'                  => $api_error,
            ]);

            throw new \RuntimeException('El servicio de IA respondió con un error al leer la factura: ' . $api_error);
        }

        return $response;
    }

    /**
     * System prompt: rol, tratamiento de las páginas como un solo documento, formato de
     * salida y las diez reglas (§3.3). El párrafo anti-complacencia del final es el
     * núcleo de la funcionalidad: sin él, el modelo inventa un código o un precio
     * verosímil, el usuario lo confirma sin darse cuenta, y le entra basura al catálogo y a
     * la cuenta corriente del proveedor.
     *
     * @return string
     */
    protected function build_system_prompt(): string
    {
        return implode("\n", [
            'Sos un asistente que lee facturas y remitos de compra de proveedores argentinos a partir de',
            'fotos, y devuelve los datos en JSON.',
            '',
            'Las imágenes que te paso son PÁGINAS CONSECUTIVAS DEL MISMO COMPROBANTE, en orden. Tratalas',
            'como un solo documento:',
            '- La tabla de artículos puede empezar en una página y seguir en la siguiente.',
            '- Los encabezados de columna suelen repetirse en cada página: NO los devuelvas como artículos.',
            '- Si un renglón quedó cortado entre dos páginas, unificalo en UN SOLO artículo y anotalo en',
            '  "avisos".',
            '- Los datos de cabecera (número, fecha, CUIT del emisor) suelen estar solo en la primera página,',
            '  y los totales solo en la última. Tomalos de donde estén.',
            '',
            'Respondés ÚNICAMENTE con un objeto JSON válido: sin texto antes ni después, sin backticks,',
            'sin markdown.',
            '',
            'Estructura exacta:',
            '',
            '{',
            '  "columnas_detectadas": [',
            '    { "clave": "codigo_proveedor", "etiqueta_en_factura": "CÓDIGO", "confianza": 0.94 }',
            '  ],',
            '  "articulos": [',
            '    {',
            '      "fila": 1,',
            '      "pagina": 1,',
            '      "bar_code": null,',
            '      "codigo_proveedor": "AR-1024",',
            '      "nombre": "MARTILLO ACERO 500G",',
            '      "cantidad": 12,',
            '      "costo_unitario": 2450.50,',
            '      "descuento_porcentaje": 10,',
            '      "iva_porcentaje": 21,',
            '      "total_linea": 26465.40,',
            '      "notas": null,',
            '      "confianza_fila": 0.93,',
            '      "campos_dudosos": ["costo_unitario"]',
            '    }',
            '  ],',
            '  "factura": {',
            '    "es_factura_afip": true,',
            '    "confianza": 0.9,',
            '    "tipo_comprobante": "A",',
            '    "punto_venta": "0003",',
            '    "numero": "00012345",',
            '    "issued_at": "2026-08-14",',
            '    "emisor_cuit": "30712345678",',
            '    "emisor_razon_social": "DISTRIBUIDORA DEL SUR S.A.",',
            '    "receptor_cuit": null,',
            '    "neto_gravado": 100000,',
            '    "total_iva": 21000,',
            /*
             * El ejemplo cuadra a propósito: 100000 de neto + 21000 de IVA + 2500 de percepción de
             * IIBB = 123500. Desde que el total que se guarda sale de esa suma (y no del número
             * suelto), un ejemplo donde el total no cierra con su propio desglose le estaría
             * enseñando a la IA justo lo contrario de lo que tiene que leer.
             */
            '    "total": 123500,',
            '    "ivas": [ { "porcentaje": 21, "neto": 100000, "importe": 21000 } ],',
            '    "percepcion_iibb": 2500,',
            '    "percepcion_iva": null,',
            // Las retenciones no se piden a propósito: una factura de compra no las tiene (retiene
            // el cliente al pagar, no el proveedor al facturar). Ver CAMPOS_NUMERICOS_FACTURA.
            '    "campos_dudosos": []',
            '  },',
            '  "avisos": []',
            '}',
            '',
            'REGLAS:',
            '',
            '1. "columnas_detectadas[].clave" solo puede ser: bar_code, codigo_proveedor, nombre, cantidad,',
            '   costo_unitario, descuento_porcentaje, iva_porcentaje, total_linea, notas. Si una columna de',
            '   la factura no corresponde a ninguna, no la incluyas.',
            '',
            '2. Todas las confianzas son números entre 0 y 1. La confianza de una columna es qué tan seguro',
            '   estás de que esa columna de la factura es esa propiedad; la de una fila, qué tan legible',
            '   estaba ese renglón.',
            '',
            '3. "campos_dudosos" lista los nombres de los campos de ESE objeto que leíste con dificultad',
            '   (borroso, tapado, ambiguo). Es la lista de lo que el usuario tiene que mirar a mano.',
            '',
            '4. Los números van como números JSON, con punto decimal y SIN separador de miles ni símbolo de',
            '   moneda. Si no podés leer un número, poné null y agregá el campo a "campos_dudosos". NO',
            '   inventes un valor plausible.',
            '',
            '5. "issued_at" en formato AAAA-MM-DD. Las facturas argentinas escriben la fecha DD/MM/AAAA:',
            '   convertila. Si no hay fecha, null.',
            '',
            '6. "emisor_cuit" y "receptor_cuit" solo dígitos, sin guiones. El emisor es QUIEN EMITE la',
            '   factura (el proveedor). El receptor es el comercio que compra. No los confundas.',
            '',
            '7. Si el documento NO es una factura AFIP (es un remito, un presupuesto, una lista escrita a',
            '   mano), poné "es_factura_afip": false y dejá en null todo lo que no aplique. Los artículos',
            '   se extraen igual: un remito también tiene la tabla.',
            '',
            '8. NO devuelvas como artículos: encabezados de columna, subtotales, totales, líneas de',
            '   "SON PESOS...", leyendas de AFIP, condiciones de pago, ni el pie del comprobante.',
            '',
            /*
             * 🔴 Regla medida (misión escaneo-factura-haiku, 2/11/2026). Una percepción de pie con otro
             * nombre ("Regímenes Especiales") se perdía en 15 de las 16 lecturas cuyo total no cuadraba,
             * incluidas todas las de Sonnet 5.5 sobre esas facturas, y el total que se guarda sale de lo
             * leído: la deuda con el proveedor quedaba un 3,2 % corta.
             *
             * Va a `percepcion_iibb` lo que no sea de IVA porque el contrato no tiene otro campo y es el
             * que el servidor ya suma (FacturaDeCompraHelper::percepciones()). Tiene un costo: ese campo
             * también entra a la posición fiscal como percepción de IIBB sufrida (PosicionFiscalHelper),
             * por eso se le pide que avise cuando la línea no dice IIBB, para que se corrija al revisar.
             *
             * "Ret." no contradice que las retenciones no se pidan (ver CAMPOS_NUMERICOS_FACTURA): acá
             * entra solo la línea que SUMA al total impreso, no un campo de retenciones.
             *
             * La salvedad del neto hace falta: sin ella, "toda línea que suma al total" también es el
             * neto. La anti-complacencia queda última a propósito: pedirle que la cuenta cierre invita a
             * ajustar un importe para que cierre.
             */
            '9. PERCEPCIONES Y OTROS CONCEPTOS DEL PIE: toda línea del pie de la factura que sume al total y',
            '   no sea el neto (gravado, exento o no gravado) ni el IVA va en un campo de percepciones, aunque',
            '   tenga otro nombre: "Regímenes Especiales", "Perc. IIBB", "Percepción IVA", "Imp. Internos",',
            '   "Ret." y parecidas. No puede quedar afuera. Lo que sea percepción de IVA va en "percepcion_iva";',
            '   todo lo demás, en "percepcion_iibb". Si varias líneas van al mismo campo, poné la suma. Si pusiste',
            '   en "percepcion_iibb" una línea que no dice IIBB, anotá en "avisos" cuál fue.',
            '   Control: la suma de "neto" e "importe" de "ivas" más "percepcion_iibb" y "percepcion_iva" tiene que dar el "total" impreso.',
            '   Si no da, NO inventes ni ajustes ningún valor para que cierre: dejá lo que leíste y anotá en',
            '   "avisos" que no cierra. Si da, no lo anotes: "avisos" es solo para lo que la persona tiene',
            '   que mirar antes de confirmar, nunca para contar que algo salió bien.',
            '',
            '10. REGLA ANTI-COMPLACENCIA (la más importante, no la relajes): si un dato no se lee con',
            '   claridad, poné null y marcalo en "campos_dudosos". Decir "no pude leerlo" es una respuesta',
            '   correcta y esperada. Inventar un código de artículo o un precio verosímil es el peor error',
            '   posible: el usuario lo va a confirmar sin darse cuenta y le va a entrar basura al catálogo',
            '   y a la cuenta corriente del proveedor.',
        ]);
    }

    /**
     * Bloque de texto final: contexto de la compra a la que pertenece el escaneo y el
     * recordatorio del formato. Va después de todas las imágenes.
     *
     * El contexto es barato y sirve de ancla: saber qué proveedor se espera ayuda a la IA a
     * no confundir el CUIT del emisor con el del receptor.
     *
     * El aviso de los tramos va solo si alguna página se cortó: sin él, el renglón que aparece en dos
     * imágenes por el solape saldría dos veces y el modelo podría escribir el tramo como página.
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @param  int                            $total_paginas
     * @param  bool                           $hay_tramos
     * @return string
     */
    protected function build_user_prompt(ProviderOrderScan $scan, int $total_paginas, bool $hay_tramos = false): string
    {
        $lineas = [];

        if ($hay_tramos) {
            $lineas[] = 'Algunas páginas te las mandé cortadas en TRAMOS: son pedazos de la misma hoja, de arriba hacia';
            $lineas[] = 'abajo y con solape. Un renglón repetido por el solape va UNA sola vez. En "pagina" poné el';
            $lineas[] = 'número de la PÁGINA, nunca el del tramo.';
            $lineas[] = '';
        }

        $lineas[] = 'CONTEXTO DE LA COMPRA (para orientarte, no lo copies a la respuesta):';

        $provider_order = $scan->provider_order;
        $provider_name  = optional(optional($provider_order)->provider)->name;

        if (!is_null($provider_name) && trim((string) $provider_name) !== '') {
            $lineas[] = '- Proveedor esperado: ' . $provider_name . '. Ese es el EMISOR de la factura.';
        } else {
            $lineas[] = '- Proveedor esperado: no se sabe. El emisor es quien firma el comprobante, no el comercio que compra.';
        }

        $lineas[] = '- Páginas del comprobante que te mandé: ' . $total_paginas . '.';
        $lineas[] = '';
        $lineas[] = 'Leé las ' . $total_paginas . ' páginas como UN SOLO comprobante y devolvé el objeto JSON';
        $lineas[] = 'con la estructura exacta que te indiqué: sin texto antes ni después, sin backticks,';
        $lineas[] = 'sin markdown. Si un dato no se lee con claridad, null y a "campos_dudosos".';

        return implode("\n", $lineas);
    }

    /**
     * Parsea la respuesta de Anthropic y la normaliza campo por campo (§3.4).
     *
     * No se confía en NADA de la forma que llega: cada clave se lee con isset, se castea
     * explícitamente y se valida contra la lista de valores permitidos. Un modelo que un día
     * devuelve `articulos` como objeto en vez de array, o una confianza en 7.5, no puede
     * romper el escaneo ni meter basura en el resultado que después se persiste.
     *
     * @param  array  $body  Respuesta completa de la API de Anthropic
     * @return array  ['columnas_detectadas'=>array,'articulos'=>array,'factura'=>array,'avisos'=>array]
     *
     * @throws \RuntimeException
     */
    protected function parse_respuesta(array $body): array
    {
        if (!isset($body['content']) || !is_array($body['content'])) {
            throw new \RuntimeException('La IA no devolvió contenido de texto.');
        }

        /* Paso 1: concatenar todos los bloques de texto de la respuesta. */
        $texto = '';
        foreach ($body['content'] as $block) {
            if (is_array($block) && isset($block['type']) && $block['type'] === 'text' && isset($block['text'])) {
                $texto .= (string) $block['text'];
            }
        }

        $texto = trim($texto);

        if ($texto === '') {
            throw new \RuntimeException('La IA no devolvió contenido de texto.');
        }

        /* Paso 2: sacar el markdown que el modelo agrega igual, pese a la instrucción. */
        $texto = preg_replace('/^```(?:json)?\s*/i', '', $texto);
        $texto = preg_replace('/\s*```$/i', '', $texto);
        $texto = trim($texto);

        /* Paso 3: quedarse con el primer objeto balanceado (tolera texto alrededor). */
        $inicio = strpos($texto, '{');
        $fin    = strrpos($texto, '}');

        if ($inicio === false || $fin === false || $fin <= $inicio) {
            /* Sin la respuesta: trae datos de la factura de un cliente, y el log no es lugar para eso. */
            Log::error('EscaneoFacturaCompraService: la respuesta de la IA no contiene un objeto JSON', [
                'largo' => mb_strlen($texto),
            ]);

            throw new \RuntimeException('La IA no devolvió un JSON válido con los datos de la factura.');
        }

        $json     = substr($texto, $inicio, $fin - $inicio + 1);
        $decoded  = json_decode($json, true);

        /*
         * Paso 4: si no decodifica, se loguea el error y el largo, y se corta con mensaje legible. La
         * respuesta cruda no se loguea: trae datos de la factura de un cliente.
         *
         * 🔴 El mensaje de error se guarda en una variable ANTES de loguear: Log::error() termina
         * serializando el contexto con json_encode() (vía el formatter de Monolog), y ese
         * json_encode() interno resetea el json_last_error() global. Llamar json_last_error_msg()
         * de nuevo después del log siempre devuelve "No error", aunque el decode haya fallado -
         * medido en demo el 5/9/2026 con una factura de "sources s.a." cuyo JSON venía truncado.
         */
        if (json_last_error() !== JSON_ERROR_NONE) {
            $mensaje_error = json_last_error_msg();

            Log::error('EscaneoFacturaCompraService: JSON inválido en la respuesta de la IA', [
                'largo'      => mb_strlen($texto),
                'json_error' => $mensaje_error,
            ]);

            throw new \RuntimeException('La IA no devolvió un JSON válido con los datos de la factura: ' . $mensaje_error);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('La IA no devolvió un JSON válido con los datos de la factura.');
        }

        /* Paso 5: normalización campo por campo. */
        $factura = $this->normalizar_factura(isset($decoded['factura']) ? $decoded['factura'] : null);

        $avisos = $this->normalizar_avisos(isset($decoded['avisos']) ? $decoded['avisos'] : null);

        /*
         * La discrepancia entre el total impreso y el que sale del desglose va además como aviso,
         * no solo como `campos_dudosos`: el campo dudoso marca QUÉ mirar, el aviso dice CUÁNTO no
         * cierra y por qué importa. Es lo único que separa un renglón mal leído de una deuda mal
         * calculada, y es barato.
         */
        $aviso_total = $this->aviso_de_total_que_no_cuadra($factura);

        if (!is_null($aviso_total)) {
            $avisos[] = $aviso_total;
        }

        return [
            'columnas_detectadas' => $this->normalizar_columnas(isset($decoded['columnas_detectadas']) ? $decoded['columnas_detectadas'] : null),
            'articulos'           => $this->normalizar_articulos(isset($decoded['articulos']) ? $decoded['articulos'] : null),
            'factura'             => $factura,
            'avisos'              => $avisos,
        ];
    }

    /**
     * Normaliza `columnas_detectadas`: descarta las claves que no están en el contrato y
     * clampea la confianza a [0,1].
     *
     * @param  mixed  $columnas
     * @return array
     */
    protected function normalizar_columnas($columnas): array
    {
        if (!is_array($columnas)) {
            return [];
        }

        $normalizadas = [];

        foreach ($columnas as $columna) {
            if (!is_array($columna)) {
                continue;
            }

            $clave = isset($columna['clave']) ? (string) $columna['clave'] : '';

            /* Clave fuera del contrato: se descarta la columna entera (§2.1). */
            if (!in_array($clave, self::CLAVES_COLUMNA, true)) {
                continue;
            }

            $etiqueta = isset($columna['etiqueta_en_factura']) && !is_null($columna['etiqueta_en_factura'])
                ? trim((string) $columna['etiqueta_en_factura'])
                : null;

            if ($etiqueta === '') {
                $etiqueta = null;
            }

            $normalizadas[] = [
                'clave'               => $clave,
                'etiqueta_en_factura' => $etiqueta,
                'confianza'           => $this->clamp_confianza(isset($columna['confianza']) ? $columna['confianza'] : null),
            ];
        }

        return $normalizadas;
    }

    /**
     * Normaliza `articulos`: castea cada campo, pasa los números por parseNumericValue() y
     * descarta las filas basura.
     *
     * Filas basura (§2.1): las que no tienen ni bar_code, ni codigo_proveedor, ni nombre.
     * Eso saca los subtotales, los "SON PESOS…" y las líneas de pie que la IA a veces mete
     * como artículos.
     *
     * @param  mixed  $articulos
     * @return array
     */
    protected function normalizar_articulos($articulos): array
    {
        /* Puede haber factura sin artículos legibles: no es un error, es un resultado. */
        if (!is_array($articulos)) {
            return [];
        }

        $normalizados = [];
        $numero_fila  = 0;

        foreach ($articulos as $articulo) {
            if (!is_array($articulo)) {
                continue;
            }

            $numero_fila++;

            $campos_dudosos = $this->normalizar_avisos(isset($articulo['campos_dudosos']) ? $articulo['campos_dudosos'] : null);

            $fila = [
                'fila'             => isset($articulo['fila']) ? (int) $articulo['fila'] : $numero_fila,
                'pagina'           => isset($articulo['pagina']) ? (int) $articulo['pagina'] : 1,
                'bar_code'         => $this->texto_o_null(isset($articulo['bar_code']) ? $articulo['bar_code'] : null),
                'codigo_proveedor' => $this->texto_o_null(isset($articulo['codigo_proveedor']) ? $articulo['codigo_proveedor'] : null),
                'nombre'           => $this->texto_o_null(isset($articulo['nombre']) ? $articulo['nombre'] : null),
                'notas'            => $this->texto_o_null(isset($articulo['notas']) ? $articulo['notas'] : null),
                'confianza_fila'   => $this->clamp_confianza(isset($articulo['confianza_fila']) ? $articulo['confianza_fila'] : null),
            ];

            /*
             * Fila basura: sin ninguno de los tres identificadores no hay artículo posible.
             * Se descarta ANTES de gastar el parseo de números.
             */
            if (is_null($fila['bar_code']) && is_null($fila['codigo_proveedor']) && is_null($fila['nombre'])) {
                continue;
            }

            foreach (self::CAMPOS_NUMERICOS_ARTICULO as $campo) {
                $crudo = isset($articulo[$campo]) ? $articulo[$campo] : null;

                $numero = $this->numero_o_null($crudo, $campo, $fila['fila'], $campos_dudosos);

                $fila[$campo] = $numero;
            }

            $campos_de_la_cuenta = ['cantidad', 'costo_unitario', 'descuento_porcentaje', 'total_linea'];
            if (!array_intersect($campos_de_la_cuenta, $campos_dudosos) && $this->unitario_ya_bonificado($fila)) {
                $fila['descuento_porcentaje'] = null;
            }

            $fila['campos_dudosos'] = array_values(array_unique($campos_dudosos));

            /* Orden estable de las claves, el mismo del ejemplo del contrato §2. */
            $normalizados[] = [
                'fila'                 => $fila['fila'],
                'pagina'               => $fila['pagina'],
                'bar_code'             => $fila['bar_code'],
                'codigo_proveedor'     => $fila['codigo_proveedor'],
                'nombre'               => $fila['nombre'],
                'cantidad'             => $fila['cantidad'],
                'costo_unitario'       => $fila['costo_unitario'],
                'descuento_porcentaje' => $fila['descuento_porcentaje'],
                'iva_porcentaje'       => $fila['iva_porcentaje'],
                'total_linea'          => $fila['total_linea'],
                'notas'                => $fila['notas'],
                'confianza_fila'       => $fila['confianza_fila'],
                'campos_dudosos'       => $fila['campos_dudosos'],
            ];
        }

        return $normalizados;
    }

    /**
     * Normaliza el bloque `factura` del comprobante.
     *
     * @param  mixed  $factura
     * @return array
     */
    protected function normalizar_factura($factura): array
    {
        if (!is_array($factura)) {
            /* Sin bloque de factura, el documento se trata como "no es factura AFIP". */
            $factura = ['es_factura_afip' => false];
        }

        $campos_dudosos = $this->normalizar_avisos(isset($factura['campos_dudosos']) ? $factura['campos_dudosos'] : null);

        $punto_venta = $this->texto_o_null(isset($factura['punto_venta']) ? $factura['punto_venta'] : null);
        $numero      = $this->texto_o_null(isset($factura['numero']) ? $factura['numero'] : null);

        /* `code` es lo que el resto del sistema usa como identificador del comprobante. */
        $code = null;
        if (!is_null($punto_venta) && !is_null($numero)) {
            $code = $punto_venta . '-' . $numero;
        } elseif (!is_null($numero)) {
            $code = $numero;
        }

        $normalizada = [
            'es_factura_afip'     => isset($factura['es_factura_afip']) ? (bool) $factura['es_factura_afip'] : false,
            'confianza'           => $this->clamp_confianza(isset($factura['confianza']) ? $factura['confianza'] : null),
            'tipo_comprobante'    => $this->texto_o_null(isset($factura['tipo_comprobante']) ? $factura['tipo_comprobante'] : null),
            'punto_venta'         => $punto_venta,
            'numero'              => $numero,
            'code'                => $code,
            'issued_at'           => $this->fecha_o_null(isset($factura['issued_at']) ? $factura['issued_at'] : null, $campos_dudosos),
            'emisor_cuit'         => $this->cuit_o_null(isset($factura['emisor_cuit']) ? $factura['emisor_cuit'] : null),
            'emisor_razon_social' => $this->texto_o_null(isset($factura['emisor_razon_social']) ? $factura['emisor_razon_social'] : null),
            'receptor_cuit'       => $this->cuit_o_null(isset($factura['receptor_cuit']) ? $factura['receptor_cuit'] : null),
        ];

        foreach (self::CAMPOS_NUMERICOS_FACTURA as $campo) {
            $crudo = isset($factura[$campo]) ? $factura[$campo] : null;

            $normalizada[$campo] = $this->numero_o_null($crudo, $campo, null, $campos_dudosos);
        }

        $normalizada['ivas']           = $this->normalizar_ivas(isset($factura['ivas']) ? $factura['ivas'] : null, $campos_dudosos);

        /*
         * 🔴 El total impreso contra el calculado (misión `compras-factura-manual-alicuotas`,
         * 17/9/2026). Desde que el total del comprobante lo calcula el servidor a partir del
         * desglose, un renglón mal leído por la IA deja de ser un número feo en pantalla y pasa a
         * mover la deuda con el proveedor. Antes viajaba el número del papel y el desglose era
         * decorativo; ahora manda el desglose, así que si los dos no coinciden hay que decirlo
         * ANTES de guardar nada — acá, donde el modal de revisión lo muestra y el usuario lo
         * corrige a mano.
         */
        if (!is_null($this->aviso_de_total_que_no_cuadra($normalizada))) {
            $campos_dudosos[] = 'total';
        }

        $normalizada['campos_dudosos'] = array_values(array_unique($campos_dudosos));

        return $normalizada;
    }

    /**
     * Mensaje de aviso si el total impreso del comprobante no coincide con el que sale de su
     * desglose de IVA más las percepciones. `null` si coinciden, o si no hay con qué comparar.
     *
     * No se avisa cuando falta uno de los dos lados:
     *
     *   · Sin desglose (una Factura C, o un comprobante donde la IA no lo leyó) no hay nada que
     *     comparar, y el total impreso es el único dato que existe: se guarda ése, así que no hay
     *     movimiento silencioso del que avisar.
     *   · Sin total impreso tampoco: el desglose es lo único que hay.
     *
     * La tolerancia de medio peso absorbe el redondeo por fila (cada alícuota se guarda con dos
     * decimales) sin dejar pasar un dígito mal leído, que es lo que se quiere cazar.
     *
     * @param  array  $factura  Factura ya normalizada (con `total`, `ivas` y las percepciones).
     * @return string|null
     */
    protected function aviso_de_total_que_no_cuadra(array $factura)
    {
        $total_impreso = isset($factura['total']) ? $factura['total'] : null;

        $ivas = isset($factura['ivas']) && is_array($factura['ivas']) ? $factura['ivas'] : [];

        if (is_null($total_impreso) || count($ivas) === 0) {
            return null;
        }

        $calculado = 0;

        foreach ($ivas as $iva) {
            $calculado += (float) (isset($iva['neto']) ? $iva['neto'] : 0)
                        + (float) (isset($iva['importe']) ? $iva['importe'] : 0);
        }

        $calculado += (float) (isset($factura['percepcion_iibb']) ? $factura['percepcion_iibb'] : 0)
                    + (float) (isset($factura['percepcion_iva']) ? $factura['percepcion_iva'] : 0);

        $diferencia = round((float) $total_impreso - $calculado, 2);

        if (abs($diferencia) <= 0.5) {
            return null;
        }

        return 'El total de la factura ('.number_format((float) $total_impreso, 2, ',', '.').') no coincide '.
                'con la suma de su desglose de IVA y percepciones ('.number_format($calculado, 2, ',', '.').'): '.
                'hay una diferencia de '.number_format(abs($diferencia), 2, ',', '.').'. Revisá los netos, los '.
                'importes de IVA y las percepciones antes de confirmar: el total que se guarda sale de esa suma.';
    }

    /**
     * Normaliza `factura.ivas[]`. El `iva_id` lo resuelve después resolver_iva_ids(): acá
     * queda en null para que la clave exista siempre y el frontend no tenga que preguntarse
     * si vino o no.
     *
     * @param  mixed  $ivas
     * @param  array  $campos_dudosos  Por referencia: un importe ilegible se anota acá
     * @return array
     */
    protected function normalizar_ivas($ivas, array &$campos_dudosos): array
    {
        if (!is_array($ivas)) {
            return [];
        }

        $normalizados = [];

        foreach ($ivas as $iva) {
            if (!is_array($iva)) {
                continue;
            }

            $normalizados[] = [
                'porcentaje' => $this->numero_o_null(isset($iva['porcentaje']) ? $iva['porcentaje'] : null, 'porcentaje de IVA', null, $campos_dudosos),
                'iva_id'     => null,
                'neto'       => $this->numero_o_null(isset($iva['neto']) ? $iva['neto'] : null, 'neto de IVA', null, $campos_dudosos),
                'importe'    => $this->numero_o_null(isset($iva['importe']) ? $iva['importe'] : null, 'importe de IVA', null, $campos_dudosos),
            ];
        }

        return $normalizados;
    }

    /**
     * Normaliza una lista de strings (avisos, campos_dudosos). Cualquier cosa que no sea un
     * array se convierte en lista vacía.
     *
     * @param  mixed  $lista
     * @return array
     */
    protected function normalizar_avisos($lista): array
    {
        if (!is_array($lista)) {
            return [];
        }

        $normalizada = [];

        foreach ($lista as $item) {
            if (is_array($item) || is_object($item)) {
                continue;
            }

            $texto = trim((string) $item);

            if ($texto !== '') {
                $normalizada[] = $texto;
            }
        }

        return $normalizada;
    }

    /**
     * Matchea cada artículo leído contra el catálogo del comercio y le agrega las tres
     * claves que pone el backend: `match`, `incluir` y `crear_en_catalogo`.
     *
     * Diferencias deliberadas con ProviderOrderArticleImport::get_article() (§2.2):
     *
     * 1. CASCADA, no if/elseif. El import prueba UN solo criterio (el primero no nulo); acá
     *    se prueban los tres en orden hasta encontrar. Motivo: el OCR puede leer mal un
     *    dígito del código de barras, y si eso corta la búsqueda, un artículo que existe
     *    queda marcado como nuevo.
     *
     * 2. 🔴 NUNCA CREA. El import crea el artículo inactivo cuando no encuentra. Acá se
     *    marca `sin_match` con `crear_en_catalogo => false` y decide el usuario. Un código
     *    mal leído creando un artículo fantasma en el catálogo del cliente es el peor
     *    resultado posible de esta funcionalidad.
     *
     * Todas las búsquedas filtran por `$scan->user_id`: acá no hay usuario autenticado.
     *
     * @param  array                          $articulos
     * @param  \App\Models\ProviderOrderScan  $scan
     * @return array
     */
    protected function matchear_articulos(array $articulos, ProviderOrderScan $scan): array
    {
        $owner_id = (int) $scan->user_id;
        $provider_id = optional($scan->provider_order)->provider_id;

        foreach ($articulos as $indice => $articulo) {
            $encontrado = null;
            $criterio   = null;

            $ambiguo = false;
            /* Una coincidencia ambigua se revisa: nunca elegir el primer artículo de una colisión. */
            if (!is_null($articulo['bar_code'])) {
                $candidatos = Article::where('user_id', $owner_id)
                    ->where('bar_code', $articulo['bar_code'])
                    ->limit(2)->get();
                $ambiguo = $candidatos->count() > 1;
                $encontrado = $candidatos->count() === 1 ? $candidatos->first() : null;

                if (!is_null($encontrado)) {
                    $criterio = 'bar_code';
                }
            }

            if (!$ambiguo && is_null($encontrado) && $provider_id && !is_null($articulo['codigo_proveedor'])) {
                $candidatos = CodigosDeProveedor::candidatos($owner_id, $provider_id, $articulo['codigo_proveedor']);
                $ambiguo = $candidatos->count() > 1;
                $encontrado = $candidatos->count() === 1 ? $candidatos->first() : null;

                if (!is_null($encontrado)) {
                    $criterio = 'provider_code';
                }
            }

            if (!$ambiguo && is_null($encontrado) && !is_null($articulo['nombre'])) {
                $candidatos = Article::where('user_id', $owner_id)
                    ->where('name', $articulo['nombre'])
                    ->get()->filter(function ($candidato) use ($articulo) {
                        return CodigosDeProveedor::nombre_exacto($candidato->name) === CodigosDeProveedor::nombre_exacto($articulo['nombre']);
                    });
                $encontrado = $candidatos->count() === 1 ? $candidatos->first() : null;

                if (!is_null($encontrado)) {
                    $criterio = 'name';
                }
            }

            if (!is_null($encontrado)) {
                $articulos[$indice]['match'] = [
                    'estado'             => 'encontrado',
                    'article_id'         => (int) $encontrado->id,
                    'criterio'           => $criterio,
                    'nombre_en_catalogo' => $encontrado->name,
                    'candidatos'         => [],
                ];
            } else {
                $articulos[$indice]['match'] = [
                    'estado'             => 'sin_match',
                    'article_id'         => null,
                    'criterio'           => null,
                    'nombre_en_catalogo' => null,
                    'candidatos'         => $this->buscar_candidatos($articulo['nombre'], $owner_id),
                ];
            }

            /* Arrancan todos tildados para incluir… */
            $articulos[$indice]['incluir'] = true;

            /* …y NINGUNO marcado para crear, ni siquiera los sin_match (decisión 3). */
            $articulos[$indice]['crear_en_catalogo'] = false;
        }

        return $articulos;
    }

    /**
     * Hasta tres artículos del catálogo con nombre parecido, para que el usuario pueda
     * elegir a mano cuando el matcheo exacto no encontró nada.
     *
     * Se busca por las primeras dos palabras del nombre leído: es lo que sobrevive a un OCR
     * mediocre en el final del renglón ("MARTILLO ACERO 500G MANGO FIBRA" leído como
     * "MARTILLO ACERO 500G MANG0 F1BRA" sigue empezando igual).
     *
     * @param  string|null  $nombre
     * @param  int          $owner_id
     * @return array
     */
    protected function buscar_candidatos($nombre, int $owner_id): array
    {
        if (is_null($nombre) || trim($nombre) === '') {
            return [];
        }

        $palabras = preg_split('/\s+/', trim($nombre));

        if (!is_array($palabras) || count($palabras) === 0) {
            return [];
        }

        $primeras = implode(' ', array_slice($palabras, 0, 2));

        if (mb_strlen($primeras) < 3) {
            /* Un fragmento de dos letras trae medio catálogo: no ayuda a nadie. */
            return [];
        }

        $encontrados = Article::where('user_id', $owner_id)
            ->where('name', 'LIKE', '%' . $primeras . '%')
            ->limit(3)
            ->get();

        $candidatos = [];

        foreach ($encontrados as $candidato) {
            $candidatos[] = [
                'article_id'    => (int) $candidato->id,
                'nombre'        => $candidato->name,
                'provider_code' => $candidato->provider_code,
                'motivo'        => 'nombre parecido',
            ];
        }

        return $candidatos;
    }

    /**
     * Resuelve el `iva_id` de CADA RENGLÓN a partir del `iva_porcentaje` que leyó la IA, con el
     * mismo criterio que la cabecera del comprobante (resolver_iva_ids(), acá abajo).
     *
     * 🔴 Por qué el renglón necesita su propia alícuota y no alcanza con la del artículo del
     * catálogo: el `iva_id` de la línea es lo que termina en el pivot de la compra y, para los
     * artículos que el usuario manda crear, en el alta del artículo. Un artículo nuevo sin
     * alícuota entra con iva_id null, y a partir de ahí
     * NewProviderOrderHelper::get_total_article() no le calcula IVA (pide iva_id no nulo y
     * distinto de 0) y ModoFacturacionHelper::get_ivas() lo saltea entero: en modo automático
     * ese renglón aporta $0 de IVA aunque el comprobante diga 21%.
     *
     * Va en el contrato para que el modal de revisión lo pueda mostrar y corregir antes de
     * confirmar: si la IA leyó mal la columna de IVA, el usuario lo arregla ahí.
     *
     * Null cuando la línea no trajo porcentaje o cuando ese porcentaje no existe en `ivas`; en
     * ese caso el controlador cae a la alícuota del artículo del catálogo, que es el
     * comportamiento de siempre.
     *
     * @param  array  $articulos
     * @return array
     */
    protected function resolver_iva_id_de_articulos(array $articulos): array
    {
        foreach ($articulos as $indice => $articulo) {

            $porcentaje = isset($articulo['iva_porcentaje']) ? $articulo['iva_porcentaje'] : null;

            $articulos[$indice]['iva_id'] = $this->buscar_iva_id($porcentaje);
        }

        return $articulos;
    }

    /**
     * Id de la fila de `ivas` cuyo porcentaje coincide con el leído, o null.
     *
     * Punto único de la búsqueda: lo usan la cabecera del comprobante y cada renglón, y las dos
     * tienen que resolver igual. Memoriza por porcentaje (ver $ivas_por_porcentaje).
     *
     * @param  mixed  $porcentaje
     * @return int|null
     */
    protected function buscar_iva_id($porcentaje)
    {
        if (is_null($porcentaje) || $porcentaje === '') {
            return null;
        }

        $texto = $this->porcentaje_como_texto($porcentaje);

        if (array_key_exists($texto, $this->ivas_por_porcentaje)) {
            return $this->ivas_por_porcentaje[$texto];
        }

        $encontrado = Iva::where('percentage', $texto)->first();

        $this->ivas_por_porcentaje[$texto] = is_null($encontrado) ? null : (int) $encontrado->id;

        return $this->ivas_por_porcentaje[$texto];
    }

    /**
     * Resuelve el `iva_id` de cada alícuota del comprobante contra la tabla `ivas`.
     *
     * Ojo con dos cosas: la tabla `ivas` es GLOBAL (no tiene user_id, no se filtra por
     * comercio) y guarda el porcentaje como TEXTO ('21', '10.5', 'Exento', 'No Gravado').
     * Por eso el porcentaje leído se compara como string normalizado: 21.0 tiene que buscar
     * '21', no '21.0'.
     *
     * Si no matchea, el iva_id queda en null y el porcentaje se anota en campos_dudosos:
     * el usuario lo elige a mano en el modal de revisión.
     *
     * @param  array  $factura
     * @return array
     */
    protected function resolver_iva_ids(array $factura): array
    {
        if (!isset($factura['ivas']) || !is_array($factura['ivas']) || count($factura['ivas']) === 0) {
            return $factura;
        }

        $campos_dudosos = isset($factura['campos_dudosos']) && is_array($factura['campos_dudosos'])
            ? $factura['campos_dudosos']
            : [];

        foreach ($factura['ivas'] as $indice => $iva) {
            $porcentaje = isset($iva['porcentaje']) ? $iva['porcentaje'] : null;

            if (is_null($porcentaje)) {
                $factura['ivas'][$indice]['iva_id'] = null;
                continue;
            }

            $texto = $this->porcentaje_como_texto($porcentaje);

            /* Misma búsqueda memorizada que usan los renglones: un solo criterio para los dos. */
            $iva_id = $this->buscar_iva_id($porcentaje);

            if (is_null($iva_id)) {
                $factura['ivas'][$indice]['iva_id'] = null;
                $campos_dudosos[]                   = 'iva ' . $texto . '%';
                continue;
            }

            $factura['ivas'][$indice]['iva_id'] = $iva_id;
        }

        $factura['campos_dudosos'] = array_values(array_unique($campos_dudosos));

        return $factura;
    }

    /**
     * Pasa un porcentaje numérico al texto con el que está guardado en la tabla `ivas`:
     * 21.0 -> '21', 10.5 -> '10.5'.
     *
     * @param  mixed  $porcentaje
     * @return string
     */
    protected function porcentaje_como_texto($porcentaje): string
    {
        $numero = (float) $porcentaje;

        if ((float) (int) $numero === $numero) {
            return (string) (int) $numero;
        }

        /* Se saca el relleno de ceros de la derecha: 10.50 -> '10.5'. */
        return rtrim(rtrim(number_format($numero, 4, '.', ''), '0'), '.');
    }

    /**
     * Convierte un valor leído por la IA a número, tolerando "$ 37.468,24".
     *
     * Si parseNumericValue() no puede con él, el campo queda en null y su nombre se agrega a
     * `campos_dudosos`: NO se rompe la fila entera por un número mal leído (§2.1).
     *
     * @param  mixed        $valor
     * @param  string       $etiqueta        Nombre del campo, para el mensaje y para campos_dudosos
     * @param  int|null     $fila            Número de fila, solo para el mensaje del helper
     * @param  array        $campos_dudosos  Por referencia
     * @return float|int|null
     */
    protected function numero_o_null($valor, string $etiqueta, $fila, array &$campos_dudosos)
    {
        if (is_null($valor)) {
            return null;
        }

        if (is_bool($valor) || is_array($valor)) {
            $campos_dudosos[] = $etiqueta;
            return null;
        }

        try {
            return ImportHelper::parseNumericValue($valor, $etiqueta, $fila);
        } catch (\InvalidArgumentException $e) {
            $campos_dudosos[] = $etiqueta;
            return null;
        }
    }

    /**
     * Castea a string y devuelve null si queda vacío. Todo lo que no sea escalar se
     * descarta: un objeto anidado donde tendría que venir un nombre no es un nombre.
     *
     * @param  mixed  $valor
     * @return string|null
     */
    protected function texto_o_null($valor)
    {
        if (is_null($valor) || is_array($valor) || is_object($valor) || is_bool($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    /**
     * CUIT: solo dígitos, sin guiones ni puntos. Si después de limpiar no queda nada,
     * devuelve null.
     *
     * @param  mixed  $valor
     * @return string|null
     */
    protected function cuit_o_null($valor)
    {
        $texto = $this->texto_o_null($valor);

        if (is_null($texto)) {
            return null;
        }

        $solo_digitos = preg_replace('/\D/', '', $texto);

        return $solo_digitos === '' ? null : $solo_digitos;
    }

    /**
     * Fecha del comprobante en formato AAAA-MM-DD. Cualquier otra forma se descarta y se
     * anota en campos_dudosos: guardar una fecha mal interpretada (14/08 leído como 8 de
     * abril) es peor que no guardar ninguna.
     *
     * @param  mixed  $valor
     * @param  array  $campos_dudosos  Por referencia
     * @return string|null
     */
    protected function fecha_o_null($valor, array &$campos_dudosos)
    {
        $texto = $this->texto_o_null($valor);

        if (is_null($texto)) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) !== 1) {
            $campos_dudosos[] = 'issued_at';
            return null;
        }

        $partes = explode('-', $texto);

        if (!checkdate((int) $partes[1], (int) $partes[2], (int) $partes[0])) {
            $campos_dudosos[] = 'issued_at';
            return null;
        }

        return $texto;
    }

    /**
     * Confianza válida: número entre 0 y 1. Lo que venga ausente, no numérico o fuera de
     * rango se clampea con el mismo criterio que AiProviderAnalyzer::enrich_column_mapping().
     *
     * @param  mixed  $valor
     * @return float
     */
    protected function clamp_confianza($valor): float
    {
        if (is_null($valor) || is_array($valor) || is_object($valor) || is_bool($valor) || !is_numeric($valor)) {
            return 0.0;
        }

        return (float) max(0, min(1, (float) $valor));
    }

    /**
     * Cliente HTTP hacia Anthropic con headers y TLS del entorno. Mismo patrón que
     * ArticleImageValidationService::build_anthropic_http_client().
     *
     * Sin el manejo de verify_ssl / ca_bundle, en el WAMP de Lucas la llamada explota con
     * cURL error 60 (certificado no verificable) y el escaneo termina en error sin motivo
     * entendible.
     *
     * @param  string  $api_key
     * @return \Illuminate\Http\Client\PendingRequest
     */
    protected function build_anthropic_http_client(string $api_key)
    {
        $http = Http::withHeaders([
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ]);

        $verify_ssl = (bool) config('services.anthropic.verify_ssl', true);
        $ca_bundle  = config('services.anthropic.ca_bundle');

        if (!$verify_ssl) {
            $http = $http->withoutVerifying();
        } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
            $http = $http->withOptions(['verify' => $ca_bundle]);
        }

        return $http;
    }
}
