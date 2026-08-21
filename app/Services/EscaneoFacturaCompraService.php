<?php

namespace App\Services;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Helpers\AiTokenUsageHelper;
use App\Models\Article;
use App\Models\Iva;
use App\Models\ProviderOrderScan;
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
 * 2. UNA SOLA LLAMADA CON TODAS LAS PÁGINAS. Las N fotos de un escaneo son páginas
 *    consecutivas del MISMO comprobante y viajan juntas en un único `messages[0].content`,
 *    con un bloque de texto "PÁGINA N de M" antes de cada imagen y las instrucciones al
 *    final. Una tabla que arranca en la página 1 y sigue en la 2 solo se puede reconstruir
 *    viendo las dos a la vez; partir esto en N llamadas rompe la funcionalidad.
 *
 * 3. NUNCA SE CREA UN ARTÍCULO. El matcheo marca `sin_match` y deja `crear_en_catalogo` en
 *    false; la creación la decide el usuario después, en el modal de revisión. Un código
 *    mal leído por OCR que crea un artículo fantasma en el catálogo del cliente es el peor
 *    resultado posible de esta funcionalidad.
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
     * @var array
     */
    const CAMPOS_NUMERICOS_FACTURA = [
        'neto_gravado',
        'total_iva',
        'total',
        'percepcion_iibb',
        'percepcion_iva',
        'retencion_iibb',
        'retencion_iva',
        'retencion_ganancias',
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

        $response = $this->llamar_a_claude($scan, $imagenes);

        $body = $response->json();

        if (!is_array($body)) {
            throw new \RuntimeException('La IA devolvió una respuesta que no se pudo interpretar.');
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
            'referencia_id' => $scan->id,
        ]);

        $parseado = $this->parse_respuesta($body);

        /* Los avisos de la preparación (fotos salteadas) se suman a los que dio la IA. */
        $parseado['avisos'] = array_merge($avisos, $parseado['avisos']);

        /* Lo que agrega el backend: match contra el catálogo e iva_id de cada alícuota. */
        $parseado['articulos'] = $this->matchear_articulos($parseado['articulos'], $scan);
        $parseado['factura']   = $this->resolver_iva_ids($parseado['factura']);

        $usage = isset($body['usage']) && is_array($body['usage']) ? $body['usage'] : [];

        return [
            'version'             => self::VERSION_CONTRATO,
            'paginas_procesadas'  => count($imagenes),
            'columnas_detectadas' => $parseado['columnas_detectadas'],
            'articulos'           => $parseado['articulos'],
            'factura'             => $parseado['factura'],
            'avisos'              => $parseado['avisos'],
            'tokens'              => [
                'input'  => isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : 0,
                'output' => isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : 0,
            ],
        ];
    }

    /**
     * Lee las fotos del escaneo de disco y las deja listas para mandar: redimensionadas al
     * lado mayor configurado y en base64.
     *
     * Las fotos ya se guardaron redimensionadas y en webp cuando el usuario las subió, así
     * que este paso normalmente no cambia nada (el `upsize` del constraint impide agrandar).
     * Se hace igual porque el servicio no puede confiar en cómo quedó el archivo en disco:
     * una foto guardada por una versión anterior, o un archivo tocado a mano, tiene que
     * llegar igual a la API con un media_type que Anthropic acepte.
     *
     * Camino de respaldo: si Intervention no puede procesar el archivo (GD sin soporte de
     * webp, imagen corrupta), se manda el binario original con el mime que guardó el
     * controlador, siempre que sea uno de los que la API acepta. Una foto ilegible se saltea
     * con un aviso, no tumba el escaneo entero: cinco páginas buenas y una borrosa siguen
     * valiendo la pena.
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @return array  ['imagenes' => [['orden'=>int,'media_type'=>string,'base64'=>string], ...], 'avisos' => string[]]
     */
    protected function preparar_imagenes(ProviderOrderScan $scan): array
    {
        $max_side = (int) config('services.escaneo_factura_compra.max_side');
        $max_side = $max_side > 0 ? $max_side : 1568;

        $imagenes = [];
        $avisos   = [];

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

            $imagenes[] = [
                'orden'      => (int) $image->orden,
                'media_type' => $media_type,
                'base64'     => base64_encode($encodeada),
            ];
        }

        return ['imagenes' => $imagenes, 'avisos' => $avisos];
    }

    /**
     * Hace LA llamada a Anthropic: una sola, con todas las páginas juntas.
     *
     * 🔴 Estructura del content, y el orden importa (§3.1):
     *   [ texto "PÁGINA 1 de 3", imagen, texto "PÁGINA 2 de 3", imagen, ..., instrucciones ]
     * Las instrucciones van al final, después de TODAS las imágenes, que es lo que
     * recomienda Anthropic para visión multi-imagen y lo que ya hace
     * ArticleImageValidationService::validate() (imagen primero, texto después).
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @param  array                          $imagenes  Salida de preparar_imagenes()
     * @return \Illuminate\Http\Client\Response
     *
     * @throws \RuntimeException
     */
    protected function llamar_a_claude(ProviderOrderScan $scan, array $imagenes)
    {
        $api_key    = (string) config('services.anthropic.api_key');
        $model      = (string) config('services.escaneo_factura_compra.model');
        $timeout    = (int) config('services.escaneo_factura_compra.timeout');
        $max_tokens = (int) config('services.escaneo_factura_compra.max_tokens');

        $model      = $model !== '' ? $model : 'claude-sonnet-4-5';
        $timeout    = $timeout > 0 ? $timeout : 180;
        $max_tokens = $max_tokens > 0 ? $max_tokens : 8000;

        $total   = count($imagenes);
        $content = [];

        foreach ($imagenes as $indice => $imagen) {
            /*
             * El rótulo antes de cada imagen es lo que le permite a la IA decir en qué
             * página estaba cada renglón y unificar una fila cortada entre dos páginas.
             */
            $content[] = [
                'type' => 'text',
                'text' => 'PÁGINA ' . ($indice + 1) . ' de ' . $total,
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
            'text' => $this->build_user_prompt($scan, $total),
        ];

        try {
            $response = $this->build_anthropic_http_client($api_key)
                ->timeout($timeout)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model'      => $model,
                    'max_tokens' => $max_tokens,
                    'system'     => $this->build_system_prompt(),
                    'messages'   => [
                        [
                            'role'    => 'user',
                            'content' => $content,
                        ],
                    ],
                ]);
        } catch (\Exception $e) {
            Log::warning('EscaneoFacturaCompraService: error de conexión con Anthropic', [
                'provider_order_scan_id' => $scan->id,
                'error'                  => $e->getMessage(),
            ]);

            throw new \RuntimeException('No se pudo conectar con el servicio de IA para leer la factura. Probá de nuevo en un rato.');
        }

        if (!$response->successful()) {
            $body      = $response->json();
            $api_error = isset($body['error']['message']) ? $body['error']['message'] : 'HTTP ' . $response->status();

            Log::warning('EscaneoFacturaCompraService: Anthropic respondió con error', [
                'provider_order_scan_id' => $scan->id,
                'status'                 => $response->status(),
                'error'                  => $api_error,
            ]);

            throw new \RuntimeException('El servicio de IA respondió con un error al leer la factura: ' . $api_error);
        }

        return $response;
    }

    /**
     * System prompt: rol, tratamiento de las páginas como un solo documento, formato de
     * salida y las nueve reglas (§3.3). El párrafo anti-complacencia del final es el
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
            '    "total": 124300,',
            '    "ivas": [ { "porcentaje": 21, "neto": 100000, "importe": 21000 } ],',
            '    "percepcion_iibb": 2500,',
            '    "percepcion_iva": null,',
            '    "retencion_iibb": null,',
            '    "retencion_iva": null,',
            '    "retencion_ganancias": null,',
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
            '9. REGLA ANTI-COMPLACENCIA (la más importante, no la relajes): si un dato no se lee con',
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
     * @param  \App\Models\ProviderOrderScan  $scan
     * @param  int                            $total_paginas
     * @return string
     */
    protected function build_user_prompt(ProviderOrderScan $scan, int $total_paginas): string
    {
        $lineas   = [];
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
            Log::error('EscaneoFacturaCompraService: la respuesta de la IA no contiene un objeto JSON', [
                'respuesta_cruda' => mb_substr($texto, 0, 2000),
            ]);

            throw new \RuntimeException('La IA no devolvió un JSON válido con los datos de la factura.');
        }

        $json     = substr($texto, $inicio, $fin - $inicio + 1);
        $decoded  = json_decode($json, true);

        /* Paso 4: si no decodifica, se loguea la respuesta cruda y se corta con mensaje legible. */
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('EscaneoFacturaCompraService: JSON inválido en la respuesta de la IA', [
                'respuesta_cruda' => mb_substr($texto, 0, 2000),
                'json_error'      => json_last_error_msg(),
            ]);

            throw new \RuntimeException('La IA no devolvió un JSON válido con los datos de la factura: ' . json_last_error_msg());
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('La IA no devolvió un JSON válido con los datos de la factura.');
        }

        /* Paso 5: normalización campo por campo. */
        return [
            'columnas_detectadas' => $this->normalizar_columnas(isset($decoded['columnas_detectadas']) ? $decoded['columnas_detectadas'] : null),
            'articulos'           => $this->normalizar_articulos(isset($decoded['articulos']) ? $decoded['articulos'] : null),
            'factura'             => $this->normalizar_factura(isset($decoded['factura']) ? $decoded['factura'] : null),
            'avisos'              => $this->normalizar_avisos(isset($decoded['avisos']) ? $decoded['avisos'] : null),
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
        $normalizada['campos_dudosos'] = array_values(array_unique($campos_dudosos));

        return $normalizada;
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

        foreach ($articulos as $indice => $articulo) {
            $encontrado = null;
            $criterio   = null;

            /* Cascada de tres criterios, en orden de confiabilidad. */
            if (!is_null($articulo['bar_code'])) {
                $encontrado = Article::where('user_id', $owner_id)
                    ->where('bar_code', $articulo['bar_code'])
                    ->first();

                if (!is_null($encontrado)) {
                    $criterio = 'bar_code';
                }
            }

            if (is_null($encontrado) && !is_null($articulo['codigo_proveedor'])) {
                $encontrado = Article::where('user_id', $owner_id)
                    ->where('provider_code', $articulo['codigo_proveedor'])
                    ->first();

                if (!is_null($encontrado)) {
                    $criterio = 'provider_code';
                }
            }

            if (is_null($encontrado) && !is_null($articulo['nombre'])) {
                $encontrado = Article::where('user_id', $owner_id)
                    ->where('name', $articulo['nombre'])
                    ->first();

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

            $encontrado = Iva::where('percentage', $texto)->first();

            if (is_null($encontrado)) {
                $factura['ivas'][$indice]['iva_id'] = null;
                $campos_dudosos[]                   = 'iva ' . $texto . '%';
                continue;
            }

            $factura['ivas'][$indice]['iva_id'] = (int) $encontrado->id;
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
