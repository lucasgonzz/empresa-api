<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Catalogo;
use App\Http\Controllers\Helpers\providerOrder\ProviderOrderAltaHelper;
use App\Http\Controllers\Helpers\providerOrder\ProviderOrderScanAltaHelper;
use App\Http\Controllers\ProviderOrderScanController;
use App\Models\Address;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\Provider;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderScan;
use App\Models\ProviderOrderStatus;
use Carbon\Carbon;

/**
 * La compra con la factura adentro: lo que Lucas pidió en el mismo dictado de la misión
 * asistente-por-whatsapp (§3.6 del plan, 16/9/2026).
 *
 * El dueño manda la foto de la factura de un proveedor diciendo "esto es la compra de tal
 * proveedor". El asistente tiene que dar de alta la compra de ese proveedor si todavía no hay
 * ninguna, y cargarle esa foto como factura para que la IA la procese. La revisión del escaneo la
 * hace después el dueño desde el sistema, por el camino de siempre.
 *
 * 🔴 LA FOTO NO SALE DEL PROMPT: SALE DE LAS IMÁGENES SIN GESTIONAR DE LA CONVERSACIÓN. Nadie manda
 * la foto y el nombre del proveedor en el mismo mensaje: manda la foto, el asistente pregunta de
 * quién es, y el nombre llega al turno siguiente. Si la herramienta leyera la foto del mensaje
 * actual, ese caso —que es el normal— no funcionaría nunca.
 *
 * 🔴 CUÁNDO SE REUSA UNA COMPRA Y CUÁNDO SE CREA UNA NUEVA. Lucas pidió "dar de alta una compra
 * para ese proveedor en caso de que aún no haya ninguna". El criterio es conservador a propósito,
 * porque agregarle una factura a una compra ya cargada duplica stock y deuda: se reusa SOLO una
 * compra de ese proveedor SIN NINGÚN ARTÍCULO cargado, del mismo dueño, de los últimos
 * DIAS_DE_REUSO días y sin un escaneo en curso. En cualquier otro caso se crea una nueva, y la
 * tarjeta dice cuál de las dos cosas va a pasar antes de que el dueño confirme.
 *
 * 🔴 DECISIONES DE LUCAS DEL 24/9/2026 (misión asistente-fotos-barras-y-compras), que dan vuelta tres
 * cosas de la versión original. En demo3 (conv 12) la compra tardó TRES mensajes: el asistente
 * preguntó si el proveedor era el emisor de la factura, preguntó la sucursal entre seis, y recién ahí
 * la armó. Lo que Lucas pidió:
 *
 *   1. SI EL PROVEEDOR NO EXISTE, SE CREA. Ya no es un error ("cargalo desde Proveedores"): la tarjeta
 *      lleva el proveedor nuevo y, al ejecutar, se lo da de alta por el MISMO camino que la pantalla
 *      (EjecutorGenericoIaHelper → ProviderController::store, con su correlativo y sus cuentas
 *      corrientes) y después se crea la compra, en la misma ejecución. El proveedor es el que nombró
 *      la persona; si no nombró ninguno, el emisor que el modelo lee en la factura. Si la factura dice
 *      otra razón social, NO se cuestiona: manda lo que dijo la persona.
 *   2. LA SUCURSAL NO SE PREGUNTA. La nombrada; si no, la de quien escribe (`users.address_id`); si no,
 *      la única; si no, ninguna. La compra nace con `update_stock = 0`, y la sucursal la exige la
 *      pantalla al revisar el escaneo, que es cuando de verdad se mueve stock.
 *   3. EN "RESUELTO" SE EJECUTA DIRECTO (ver HerramientasDeCarga::AUTO_CONFIRMABLES); en "cauteloso",
 *      UNA confirmación que cubre todo, incluido el alta del proveedor.
 *
 * 🔴 MISIÓN asistente-aviso-escaneo-factura (16/10/2026), dos cosas más:
 *
 *   4. «ADJUNTALA A LA COMPRA 12» SE CUMPLE. Con `compra_num` la factura va a la compra que nombró el
 *      dueño aunque ya tenga artículos (la tarjeta lo avisa y en la revisión elige qué renglones
 *      sumar); el proveedor de esa compra manda sobre el que nombró. Sin `compra_num` sigue la regla
 *      conservadora de arriba.
 *   5. EL AVISO DE "TERMINÓ EL ESCANEO" PUEDE LLEGAR POR WHATSAPP, con el link a la revisión, si el
 *      admin del cliente anunció que lo manda (AvisosDelAsistenteHelper). El escaneo nace con su
 *      conversación y con `avisar_por_whatsapp`, y el texto del resultado dice por dónde llega.
 */
class PropuestaCompraConFacturaIaHelper
{
    /**
     * Días hacia atrás en los que una compra vacía del mismo proveedor se considera "la de esta
     * factura". Más allá de eso es una compra que quedó abierta de otra cosa, y colgarle la factura
     * de hoy sería mezclar dos compras distintas.
     */
    const DIAS_DE_REUSO = 7;

    /** Slug de la extensión que gatea el escaneo de facturas de compra. */
    const EXTENSION_ESCANEO = 'escaneo_factura_compra';

    /** Cuántos proveedores se ofrecen como candidatos cuando el nombre no resuelve. */
    const TOPE_CANDIDATOS = 10;

    /**
     * ⚠️ YA NO ES LA VENTANA DE LAS FOTOS (misión asistente-fotos-barras-y-compras, 24/9/2026). Era
     * "cuántos mensajes hacia atrás se miran", y en demo3 una foto que seguía sin usar quedó afuera
     * porque se había charlado de más. Las fotos de la factura ahora son la ÚLTIMA TANDA que mandó
     * el dueño en las últimas 24 horas (FotosDeLaConversacionIaHelper::ultima_tanda), sin importar
     * cuántos mensajes hubo después. Queda como referencia de la ventana vieja: el test del hallazgo
     * B (8_Correcciones_del_chequeo_Test) arma con ella una charla "más larga que la ventana" entre la
     * góndola y la factura, y la góndola tiene que seguir quedando afuera — ahora por la tanda.
     */
    const MENSAJES_PARA_LAS_FOTOS = 6;

    /**
     * Nombre del estado con el que nace una compra desde acá, tal como lo siembra
     * ProviderOrderStatusSeeder. Se resuelve POR NOMBRE y no con el id 1 hardcodeado: el id
     * depende del orden en que corrió un seeder hace años, y en un cliente cuyo listado se
     * reordenó alguna vez, "1" puede ser "Recibido" — que marcaría como recibida una compra que
     * todavía no llegó.
     */
    const ESTADO_EN_PROCESO = 'En proceso';

    /**
     * Cuántos minutos después de cargar una compra con factura se considera que una foto SOLA (sin
     * texto) del mismo proveedor es una página más de esa factura y no una compra nueva (ver el 🔴
     * de las facturas de varias fotos en proponer()). Tres minutos cubren una tanda de fotos
     * mandadas de a una por WhatsApp; eran diez hasta el segundo chequeo adversarial del 24/9/2026,
     * que mostró que con diez frenaba la segunda compra legítima del mismo proveedor.
     */
    const MINUTOS_COMPRA_RECIENTE = 3;

    /**
     * Herramienta proponer_compra_con_factura.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input  proveedor*, sucursal, cuit, reemplaza_a, compra_num (el N° de una compra
     *                        ya cargada a la que se adjunta la factura; ver proponer_sobre_compra_indicada)
     * @return array  Respuesta de la herramienta (propuesta creada o respuesta de negocio).
     */
    public static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input)
    {
        if (!PermisosIaHelper::puede($contexto->persona, PermisosIaHelper::COMPRAS)) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_permiso('compras'));
        }

        if (!UserHelper::hasExtencion(self::EXTENSION_ESCANEO, $contexto->owner)) {

            return RespuestaDeCargaIa::error(
                'Esta cuenta no tiene contratada la lectura de facturas de compra, así que no puedo procesar la foto. '
                . 'La compra la podés cargar a mano desde Compras.'
            );
        }

        /*
         * 🔴 LA COMPRA QUE INDICÓ EL DUEÑO VA POR SU PROPIO CAMINO (misión
         * asistente-aviso-escaneo-factura, decisión 3 de Lucas del 16/10/2026): «adjuntala a la
         * compra 12» se cumple aunque esa compra ya tenga artículos — el dueño eligió la compra, y en
         * la revisión del escaneo elige qué renglones sumar. La regla automática de abajo (reusar
         * solo una compra VACÍA de los últimos DIAS_DE_REUSO días) no cambia: es la que corre cuando
         * nadie nombró ninguna compra.
         */
        $compra_num = EntradaDeCargaIa::valor($input, 'compra_num');

        if (!EntradaDeCargaIa::vacio($compra_num)) {

            $compra = self::compra_por_numero($contexto, $compra_num);

            if (RespuestaDeCargaIa::es_negativa($compra)) {

                return $compra;
            }

            return self::proponer_sobre_compra_indicada($contexto, $mensaje, $input, $compra);
        }

        /*
         * Correcciones del 24/9/2026: el proveedor se reconoce NORMALIZADO y en las dos direcciones
         * contra `name` y `razon_social` (y primero por CUIT si vino). Ver ProveedorDeLaFacturaIaHelper.
         */
        $proveedor = ProveedorDeLaFacturaIaHelper::resolver(
            $contexto,
            EntradaDeCargaIa::texto($input, 'proveedor'),
            EntradaDeCargaIa::texto($input, 'cuit'),
            $mensaje
        );

        if (RespuestaDeCargaIa::es_negativa($proveedor)) {

            return $proveedor;
        }

        /*
         * Un proveedor que no existe ya no corta (decisión de Lucas, 24/9/2026): se arma su alta con
         * el mismo payload que mandaría la pantalla, y la tarjeta lo dice antes de confirmar.
         */
        $proveedor_nuevo = null;

        if (is_array($proveedor)) {

            $proveedor_nuevo = self::alta_del_proveedor($contexto, $proveedor['nuevo']);

            if (RespuestaDeCargaIa::es_negativa($proveedor_nuevo)) {

                return $proveedor_nuevo;
            }
        }

        $nombre_proveedor = is_null($proveedor_nuevo) ? (string) $proveedor->name : $proveedor_nuevo['nombre'];

        $fotos = self::imagenes_sin_gestionar($contexto, $mensaje);

        if (!count($fotos['paginas'])) {

            return RespuestaDeCargaIa::error(
                'No tengo ninguna foto de factura sin usar de las últimas ' . FotosDeLaConversacionIaHelper::HORAS . ' horas. Mandámela y te la cargo.'
            );
        }

        /*
         * 🔴 UNA FACTURA DE VARIAS FOTOS NO SON VARIAS COMPRAS. Por WhatsApp cada foto es un mensaje y
         * cada mensaje es un turno: en "resuelto", la página 2 que llega un minuto después de la 1
         * crearía una SEGUNDA compra del mismo proveedor con otro escaneo. Si el mensaje del dueño es
         * SÓLO la foto (sin texto), llegó a menos de MINUTOS_COMPRA_RECIENTE de una compra de este
         * proveedor cargada desde esta conversación y su escaneo sigue en curso, no se crea otra: se
         * avisa, y las fotos de esta tanda se sellan para que no se cuelen solas en la próxima compra
         * (la página se agrega a mano desde Compras).
         *
         * ⚠️ Acotada así en el segundo chequeo adversarial (24/9/2026): con cualquier mensaje y diez
         * minutos frenaba una segunda compra LEGÍTIMA del mismo proveedor ("y esta otra factura") y
         * encima le sellaba las fotos. Con texto, es una compra nueva.
         */
        if (is_null($proveedor_nuevo)) {

            $reciente = self::compra_reciente_en_curso($contexto, $proveedor, $mensaje);

            if (!is_null($reciente)) {

                AsistenteImagenHelper::marcar_gestionadas(self::ids_de($fotos['consideradas']));

                return RespuestaDeCargaIa::error(
                    'Ya cargué la compra N° ' . $reciente->num . ' de ' . $proveedor->name . ' con la factura hace un momento y todavía se está escaneando. '
                    . 'Si esta foto es otra página de esa factura, agregala desde Compras cuando termine el escaneo; no armé otra compra.'
                );
            }
        }

        /*
         * 🔴 UN PROVEEDOR NUEVO QUE LA PERSONA NO NOMBRÓ NO SE CREA SOLO. Caso real (prueba del
         * 24/9/2026): el dueño dijo "Perez Hnos Mayorista" y el modelo rápido mandó "Global Sources
         * S.A.", el emisor que leyó en la factura; en "resuelto" se ejecutó y quedó dado de alta un
         * proveedor que nadie pidió. Si el nombre no aparece en lo que escribió la persona, la compra
         * queda como tarjeta aunque el modo sea "resuelto" o "directo" (`requiere_confirmacion`, que
         * respeta la puerta de auto-confirmación), y la tarjeta lo dice.
         */
        $proveedor_no_dicho = !is_null($proveedor_nuevo)
            && !ProveedorDeLaFacturaIaHelper::lo_dijo_la_persona($contexto, $mensaje, $nombre_proveedor);

        /* Nunca pregunta (decisión de Lucas, 24/9/2026): ver resolver_sucursal(). */
        $sucursal = self::resolver_sucursal($contexto, EntradaDeCargaIa::texto($input, 'sucursal'));

        $a_reusar = is_null($proveedor_nuevo) ? self::compra_reusable($contexto, $proveedor) : null;

        $imagen_ids = self::ids_de($fotos['paginas']);

        $consideradas_ids = self::ids_de($fotos['consideradas']);

        $renglones = [
            ['etiqueta' => 'Proveedor', 'valor' => $nombre_proveedor . (is_null($proveedor_nuevo) ? '' : ' (nuevo: lo doy de alta)')],
        ];

        if (!is_null($sucursal)) {

            $renglones[] = ['etiqueta' => 'Sucursal', 'valor' => self::nombre_de_sucursal($sucursal)];
        }

        $cuantas = self::descripcion_de_fotos($imagen_ids, $consideradas_ids);

        $renglones[] = ['etiqueta' => 'Fotos de la factura', 'valor' => $cuantas];

        $que_pasa = is_null($a_reusar)
            ? 'Se crea una compra nueva'
            : 'Se usa la compra N° ' . $a_reusar->num . ', que está vacía';

        if (!is_null($proveedor_nuevo)) {

            $que_pasa = 'Se da de alta el proveedor y se crea una compra nueva';
        }

        $renglones[] = ['etiqueta' => 'Qué se hace', 'valor' => $que_pasa];

        $datos = [
            'provider_id'           => is_null($proveedor_nuevo) ? (int) $proveedor->id : null,
            'proveedor'             => $nombre_proveedor,
            /*
             * El alta del proveedor tal como la ejecuta EjecutorGenericoIaHelper (entidad, operación,
             * payload de la pantalla y lo pedido), o null si el proveedor ya existía.
             */
            'proveedor_nuevo'       => is_null($proveedor_nuevo) ? null : $proveedor_nuevo['alta'],
            'address_id'            => is_null($sucursal) ? null : (int) $sucursal->id,
            'sucursal'              => is_null($sucursal) ? null : self::nombre_de_sucursal($sucursal),
            'imagen_ids'            => $imagen_ids,
            /* Las que se sellan al confirmar: superset de imagen_ids (ver imagenes_sin_gestionar). */
            'imagen_ids_miradas'    => $consideradas_ids,
            'reusar_provider_order' => is_null($a_reusar) ? null : (int) $a_reusar->id,
            /*
             * false: la compra la eligió la regla automática (vacía y reciente) o es nueva. Con true
             * (proponer_sobre_compra_indicada) la nombró el dueño y orden_para_la_factura() no se cae
             * a una compra nueva aunque tenga artículos.
             */
            'compra_indicada'       => false,
            /*
             * Espejo de `prefill_prop_on_select` del formulario de la SPA (src/models/provider_order.js):
             * al elegir el proveedor, la compra hereda su default de costos brutos. Si acá se
             * ignorara, la misma compra cargada por la pantalla y por el asistente interpretaría
             * los costos de la factura distinto, y una cuenta de Responsable Inscripto quedaría 21%
             * abajo o arriba.
             */
            'precios_incluyen_iva'  => is_null($proveedor_nuevo) ? (bool) $proveedor->precios_incluyen_iva : false,
        ];

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_COMPRA_CON_FACTURA,
            is_null($proveedor_nuevo) ? self::clave($proveedor->id) : self::clave_de_proveedor_nuevo($nombre_proveedor),
            $datos,
            [
                'titulo'    => 'Compra con factura',
                'renglones' => $renglones,
                'aviso'     => $proveedor_no_dicho
                    ? 'No tenés ningún proveedor "' . $nombre_proveedor . '" y no me lo nombraste: lo leí de la factura. Confirmá que sea ése antes de darlo de alta.'
                    : null,
            ],
            EntradaDeCargaIa::valor($input, 'reemplaza_a')
        );

        $resumen = 'Compra de ' . $nombre_proveedor . ' · ' . $cuantas . ' · ' . $que_pasa;

        $extra = is_null($proveedor_nuevo) ? [] : ['proveedor_nuevo' => $nombre_proveedor];

        if ($proveedor_no_dicho) {

            $extra['requiere_confirmacion'] = true;
            $extra['nota'] = 'El proveedor "' . $nombre_proveedor . '" no existe y la persona no lo nombró. Quedó una tarjeta: '
                . 'decile que no lo encontré entre sus proveedores, preguntale si es ése o cuál es, y no digas que quedó cargado.';
        }

        return AccionesIaHelper::respuesta_de_propuesta($creada, $resumen, $extra);
    }

    /**
     * La compra del dueño con ese número (el N° que ve en Compras), o la respuesta negativa.
     *
     * Se busca por `num` del DUEÑO, sin filtro de días ni de artículos: la nombró él, y puede ser de
     * hace un mes. Un número que no existe es un rechazo recuperable (sin tarjeta): el modelo se lo
     * cuenta y el dueño corrige el número o pide una compra nueva.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed  $compra_num
     * @return \App\Models\ProviderOrder|array
     */
    protected static function compra_por_numero(ContextoDeCargaIa $contexto, $compra_num)
    {
        if (!is_numeric($compra_num) || (int) $compra_num <= 0) {

            return RespuestaDeCargaIa::error('Decime el número de la compra tal como lo ves en Compras (por ejemplo, la compra 12).');
        }

        $compra = ProviderOrder::where('user_id', $contexto->owner_id)
                                ->where('num', (int) $compra_num)
                                ->first();

        if (is_null($compra)) {

            return RespuestaDeCargaIa::error(
                'No encontré ninguna compra N° ' . (int) $compra_num . ' entre las tuyas. '
                . 'Fijate el número en Compras, o decime si armo una compra nueva con la factura.'
            );
        }

        return $compra;
    }

    /**
     * La tarjeta que adjunta la factura a la compra que nombró el dueño («adjuntala a la compra 12»).
     *
     * Las reglas (decisión 3 de Lucas, 16/10/2026):
     *   - EL PROVEEDOR DE LA COMPRA MANDA. Si el dueño nombró a otro, la factura va igual a esa
     *     compra con SU proveedor y la tarjeta lo avisa: mezclar dos proveedores en una compra
     *     desarma la cuenta corriente de los dos.
     *   - CON ARTÍCULOS SE USA IGUAL, y la tarjeta avisa cuántos tiene: en la revisión del escaneo
     *     el dueño elige qué renglones sumar, así que nada se duplica sin que él lo vea.
     *   - CON UN ESCANEO EN CURSO, el mismo corte que en el resto del sistema: dos escaneos sobre una
     *     compra se pisarían.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input
     * @param  \App\Models\ProviderOrder  $compra
     * @return array
     */
    protected static function proponer_sobre_compra_indicada(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input, ProviderOrder $compra)
    {
        $proveedor = is_null($compra->provider_id)
            ? null
            : Provider::where('user_id', $contexto->owner_id)->where('id', (int) $compra->provider_id)->first();

        if (is_null($proveedor)) {

            return RespuestaDeCargaIa::error(
                'La compra N° ' . $compra->num . ' no tiene proveedor cargado. Asignáselo desde Compras y pedímelo de nuevo.'
            );
        }

        if (self::tiene_escaneo_en_curso($compra)) {

            return RespuestaDeCargaIa::error(
                'La compra N° ' . $compra->num . ' ya tiene un escaneo en curso. Esperá a que termine y pedímelo de nuevo.'
            );
        }

        $fotos = self::imagenes_sin_gestionar($contexto, $mensaje);

        if (!count($fotos['paginas'])) {

            return RespuestaDeCargaIa::error(
                'No tengo ninguna foto de factura sin usar de las últimas ' . FotosDeLaConversacionIaHelper::HORAS . ' horas. Mandámela y te la cargo.'
            );
        }

        $imagen_ids = self::ids_de($fotos['paginas']);

        $consideradas_ids = self::ids_de($fotos['consideradas']);

        $cuantas = self::descripcion_de_fotos($imagen_ids, $consideradas_ids);

        $articulos = $compra->articles()->count();

        $avisos = [];

        $nota = [];

        $nombrado = EntradaDeCargaIa::texto($input, 'proveedor');

        if (self::es_otro_proveedor($contexto, $nombrado, $proveedor)) {

            $avisos[] = 'La compra N° ' . $compra->num . ' es de ' . $proveedor->name . ', no de "' . $nombrado . '": la factura va a esa compra, con su proveedor.';

            $nota[] = 'La compra N° ' . $compra->num . ' es de ' . $proveedor->name . ' y no del proveedor que nombró la persona: decíselo.';
        }

        if ($articulos > 0) {

            $cuantos = $articulos === 1 ? '1 artículo' : $articulos . ' artículos';

            $avisos[] = 'La compra N° ' . $compra->num . ' ya tiene ' . $cuantos . ': al revisar el escaneo elegís qué renglones sumar.';

            $nota[] = 'La compra ya tiene ' . $cuantos . ' cargados: contale que al revisar el escaneo elige qué renglones sumar.';
        }

        $que_pasa = 'Se adjunta la factura a la compra N° ' . $compra->num
            . ($articulos > 0 ? ', que ya tiene ' . ($articulos === 1 ? '1 artículo' : $articulos . ' artículos') : '');

        $renglones = [
            ['etiqueta' => 'Proveedor', 'valor' => (string) $proveedor->name],
            ['etiqueta' => 'Compra', 'valor' => 'N° ' . $compra->num],
            ['etiqueta' => 'Fotos de la factura', 'valor' => $cuantas],
            ['etiqueta' => 'Qué se hace', 'valor' => $que_pasa],
        ];

        $datos = [
            'provider_id'           => (int) $proveedor->id,
            'proveedor'             => (string) $proveedor->name,
            'proveedor_nuevo'       => null,
            /* La compra ya tiene su sucursal (o ninguna): no se toca. */
            'address_id'            => is_null($compra->address_id) ? null : (int) $compra->address_id,
            'sucursal'              => null,
            'imagen_ids'            => $imagen_ids,
            'imagen_ids_miradas'    => $consideradas_ids,
            'reusar_provider_order' => (int) $compra->id,
            'compra_indicada'       => true,
            'precios_incluyen_iva'  => (bool) $compra->precios_incluyen_iva,
        ];

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_COMPRA_CON_FACTURA,
            self::clave($proveedor->id),
            $datos,
            [
                'titulo'    => 'Compra con factura',
                'renglones' => $renglones,
                'aviso'     => count($avisos) ? implode(' ', $avisos) : null,
            ],
            EntradaDeCargaIa::valor($input, 'reemplaza_a')
        );

        $extra = count($nota) ? ['nota' => implode(' ', $nota)] : [];

        return AccionesIaHelper::respuesta_de_propuesta(
            $creada,
            'Compra N° ' . $compra->num . ' de ' . $proveedor->name . ' · ' . $cuantas . ' · ' . $que_pasa,
            $extra
        );
    }

    /**
     * true si la persona nombró un proveedor y NO es el de la compra.
     *
     * Primero por el reconocimiento de siempre (ProveedorDeLaFacturaIaHelper::existente, normalizado
     * contra `name` y `razon_social`); si el nombre no es de ningún proveedor, se compara normalizado
     * y en las dos direcciones contra el de la compra («Distribuidora Sur» y «Distribuidora Sur
     * S.R.L.» son el mismo). Sin nombre no hay nada que avisar.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $nombrado
     * @param  \App\Models\Provider  $proveedor  El de la compra.
     * @return bool
     */
    protected static function es_otro_proveedor(ContextoDeCargaIa $contexto, $nombrado, Provider $proveedor)
    {
        $nombrado = trim((string) $nombrado);

        if ($nombrado === '') {

            return false;
        }

        $existente = ProveedorDeLaFacturaIaHelper::existente($contexto->owner_id, $nombrado);

        if (!is_null($existente)) {

            return (int) $existente->id !== (int) $proveedor->id;
        }

        $buscado = ProveedorDeLaFacturaIaHelper::normalizar($nombrado);

        if ($buscado === '') {

            return false;
        }

        foreach ([$proveedor->name, $proveedor->razon_social] as $nombre) {

            $propio = ProveedorDeLaFacturaIaHelper::normalizar((string) $nombre);

            if ($propio !== '' && (mb_strpos($propio, $buscado) !== false || mb_strpos($buscado, $propio) !== false)) {

                return false;
            }
        }

        return true;
    }

    /**
     * «1 foto», «3 fotos» y, si quedaron fotos afuera del escaneo, cuántas había sin usar.
     *
     * Si quedaron fotos afuera, la tarjeta lo dice ANTES de que el dueño confirme: al confirmar se
     * sellan todas (si no, las que sobran se colarían en la próxima compra), así que tiene que poder
     * decir "pará, esa no es de esta factura".
     *
     * @param  array  $imagen_ids
     * @param  array  $consideradas_ids
     * @return string
     */
    protected static function descripcion_de_fotos(array $imagen_ids, array $consideradas_ids)
    {
        $cuantas = count($imagen_ids) === 1 ? '1 foto' : count($imagen_ids) . ' fotos';

        if (count($consideradas_ids) > count($imagen_ids)) {

            $cuantas .= ' (de ' . count($consideradas_ids) . ' sin usar; van las más nuevas)';
        }

        return $cuantas;
    }

    /**
     * La clave de una compra cuyo proveedor todavía no existe: por su nombre, para que una
     * corrección sobre la misma factura reemplace la tarjeta.
     *
     * @param  string  $nombre
     * @return string
     */
    public static function clave_de_proveedor_nuevo($nombre)
    {
        return 'compra_con_factura:nuevo:' . mb_strtolower(trim((string) $nombre));
    }

    /**
     * El alta del proveedor que no existe, armada con las MISMAS piezas que proponer_alta de
     * `provider` (la declaración del catálogo, validar_campos y payload_de_alta): al ejecutar la
     * corre EjecutorGenericoIaHelper por ProviderController::store, igual que la pantalla. Si la
     * persona no puede crear proveedores, corta acá con el motivo, antes de dejar ninguna tarjeta.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $nombre
     * @return array  ['nombre' => string, 'alta' => array] o la respuesta negativa.
     */
    protected static function alta_del_proveedor(ContextoDeCargaIa $contexto, $nombre)
    {
        $declaracion = PropuestaGenericaIaHelper::entidad_para($contexto, 'provider', Catalogo::OP_ALTA);

        if (RespuestaDeCargaIa::es_negativa($declaracion)) {

            return RespuestaDeCargaIa::error(
                'No tenés ningún proveedor que se llame "' . $nombre . '" y no lo puedo dar de alta: ' . $declaracion['error']
            );
        }

        $validado = PropuestaGenericaIaHelper::validar_campos($contexto, $declaracion, Catalogo::OP_ALTA, ['name' => $nombre]);

        if (RespuestaDeCargaIa::es_negativa($validado)) {

            return $validado;
        }

        return [
            'nombre' => trim((string) $nombre),
            'alta'   => [
                'entidad'   => $declaracion['entidad'],
                'operacion' => Catalogo::OP_ALTA,
                'payload'   => PropuestaGenericaIaHelper::payload_de_alta($declaracion, $validado['payload']),
                'pedidos'   => $validado['pedidos'],
            ],
        ];
    }

    /**
     * Identidad de la carga para el reemplazo: una corrección sobre la misma factura reemplaza la
     * tarjeta, y la factura de otro proveedor no la pisa.
     *
     * @param  int  $provider_id
     * @return string
     */
    public static function clave($provider_id)
    {
        return 'compra_con_factura:' . (int) $provider_id;
    }

    /**
     * Crea (o reusa) la compra, le cuelga el escaneo y sella las fotos. Corre adentro de la
     * transacción de EjecutorAccionesIaHelper y con la persona ya autenticada
     * (ConfirmacionPorTextoIaHelper), que es lo que necesita NewProviderOrderHelper para no
     * resolver un usuario nulo.
     *
     * 🔴 TODO SE RE-VERIFICA ACÁ. Entre la propuesta y el sí del dueño pueden pasar horas: el
     * proveedor pudo borrarse, la compra vacía pudo cargarse desde la pantalla, y las fotos pudo
     * usarlas otra tarjeta. Confirmar sin revisar sería colgarle una factura a una compra que ya
     * tiene artículos — que es exactamente el duplicado de stock y deuda que el criterio de reuso
     * vino a evitar.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array  resultado {texto, ruta, provider_id, provider_order_id}
     *
     * @throws AccionIaException  422 si la carga ya no se puede hacer.
     */
    public static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion)
    {
        if (!PermisosIaHelper::puede($contexto->persona, PermisosIaHelper::COMPRAS)) {

            throw new AccionIaException(422, PermisosIaHelper::mensaje_sin_permiso('compras'));
        }

        if (!UserHelper::hasExtencion(self::EXTENSION_ESCANEO, $contexto->owner)) {

            throw new AccionIaException(422, 'Esta cuenta ya no tiene contratada la lectura de facturas de compra.');
        }

        $datos = is_array($accion->datos) ? $accion->datos : [];

        $es_nuevo = empty($datos['provider_id']) && !empty($datos['proveedor_nuevo']) && is_array($datos['proveedor_nuevo']);

        $creado = false;

        if ($es_nuevo) {

            list($proveedor, $creado) = self::proveedor_nuevo($contexto, $datos);

        } else {

            $proveedor = Provider::where('user_id', $contexto->owner_id)
                                    ->where('id', isset($datos['provider_id']) ? (int) $datos['provider_id'] : 0)
                                    ->first();
        }

        if (is_null($proveedor)) {

            throw new AccionIaException(422, 'Ese proveedor ya no existe entre los tuyos. Pedímelo de nuevo.');
        }

        /*
         * 🔴 Se bloquean TODAS las que la propuesta miró, no solo las que van al escaneo: son las
         * que se van a sellar, y sellarlas sin haberlas bloqueado deja la misma carrera que el
         * candado vino a cerrar.
         */
        $miradas = self::imagenes_por_id(
            $contexto,
            isset($datos['imagen_ids_miradas']) ? $datos['imagen_ids_miradas'] : (isset($datos['imagen_ids']) ? $datos['imagen_ids'] : [])
        );

        $paginas_pedidas = self::ids_limpios(isset($datos['imagen_ids']) ? $datos['imagen_ids'] : []);

        $imagenes = [];

        foreach ($miradas as $mirada) {

            if (in_array((int) $mirada->id, $paginas_pedidas, true)) {

                $imagenes[] = $mirada;
            }
        }

        if (!count($imagenes)) {

            throw new AccionIaException(422, 'Las fotos de esa factura ya se usaron en otra compra. Mandámelas de nuevo.');
        }

        $destino = self::orden_para_la_factura($contexto, $proveedor, $datos);

        $orden = $destino['orden'];

        $paginas = [];

        foreach ($imagenes as $imagen) {

            $binario = AsistenteImagenHelper::binario($imagen);

            if (is_null($binario)) {

                continue;
            }

            $paginas[] = [
                'binario'         => $binario,
                'nombre_original' => 'factura-' . $imagen->orden . '.webp',
            ];
        }

        if (!count($paginas)) {

            throw new AccionIaException(422, 'No pude leer las fotos de esa factura. Mandámelas de nuevo.');
        }

        /*
         * 🔴 EL AVISO POR WHATSAPP SE PIDE SOLO SI VA A LLEGAR (misión asistente-aviso-escaneo-factura,
         * 16/10/2026): la carga vino por WhatsApp Y el admin de este cliente anunció que consulta los
         * avisos (AvisosDelAsistenteHelper::anunciar). Con un admin viejo el escaneo nace sin aviso y
         * el texto de abajo sigue diciendo que el aviso es en la pantalla, como siempre.
         *
         * La conversación se guarda igual en los dos casos: es lo que deja que
         * consultar_escaneo_de_factura encuentre "la factura de esta charla".
         */
        $avisa_por_whatsapp = self::avisa_por_whatsapp($contexto, $accion);

        $resultado = ProviderOrderScanAltaHelper::crear($orden, $paginas, $contexto->persona, [
            'ai_conversation_id'  => is_null($contexto->conversation) ? null : (int) $contexto->conversation->id,
            'avisar_por_whatsapp' => $avisa_por_whatsapp,
        ]);

        if ((int) $resultado['status'] !== 202) {

            $mensaje = isset($resultado['body']['message'])
                ? (string) $resultado['body']['message']
                : 'No se pudo empezar a leer la factura.';

            throw new AccionIaException(422, $mensaje);
        }

        /*
         * Se sellan TODAS las miradas, no solo las que subieron. Una foto que la propuesta
         * consideró y dejó afuera por el tope no puede quedar libre: sin esto se engancharía sola
         * a la próxima compra, que puede ser de otro proveedor.
         */
        AsistenteImagenHelper::marcar_gestionadas(self::ids_de($miradas));

        /*
         * El aviso de que el escaneo terminó no lo escribe esto. En la pantalla lo manda
         * RunProviderOrderScanJob::notificar_fin() por el proceso en segundo plano; por WhatsApp lo
         * manda el admin cuando consulta GET admin-sync/asistente/avisos-pendientes (solo si el
         * escaneo nació con `avisar_por_whatsapp`).
         *
         * 🔴 EL TEXTO DICE QUÉ PASÓ Y CÓMO LLEGA EL AVISO, sin prometer lo que no va a venir:
         *   - «Creé la Compra N° X…» vs «Adjunté la factura a la Compra N° X…»: el dueño tiene que
         *     saber si se armó una compra nueva o si la factura fue a una que ya existía.
         *   - Con el aviso pedido → «te aviso por acá con el link»; sin él (admin viejo, o la carga
         *     no vino por WhatsApp) → el aviso es en la pantalla, como antes de esta misión.
         * «Compra N° X» va con mayúscula en los dos casos: es como lo dice el listado de Compras, y
         * lo que buscan los tests de la carga.
         *
         * `provider_id` y `provider_order_id` no son para la persona: los lee
         * compra_reciente_en_curso() para no crear una segunda compra con la página 2 de la misma
         * factura.
         */
        $cierre = $avisa_por_whatsapp
            ? ' Estoy escaneando la factura en segundo plano: cuando termine de leerla te aviso por acá con el link para revisarla en Compras.'
            : ' Estoy escaneando la factura en segundo plano: cuando termine te aparece el aviso en el sistema '
                . '(en la pantalla, no por WhatsApp) y ahí revisás los artículos desde Compras.';

        return [
            'texto'             => self::que_paso($orden, $proveedor, $destino['adjuntada'], $creado) . $cierre,
            /*
             * Compras es la solapa 'compras' de la ruta 'provider' de la SPA (/proveedores/compras).
             * Hasta el 10/10/2026 iba name 'proveedores', que no existe en router/index.js y dejaba
             * la pantalla en blanco (misión ver-en-del-asistente-refresca-destino).
             *
             * `fecha` es el día de la compra: Compras lista por día y la factura puede haber caído en
             * una compra vacía de hasta DIAS_DE_REUSO días atrás (orden_para_la_factura), que no está
             * en el día de hoy. Clave opcional, como la del gasto.
             */
            'ruta'              => [
                'name'   => 'provider',
                'params' => ['view' => 'compras'],
                'texto'  => 'Ver en Compras',
                'fecha'  => $orden->created_at->format('Y-m-d'),
            ],
            'provider_id'       => (int) $proveedor->id,
            'provider_order_id' => (int) $orden->id,
        ];
    }

    /**
     * La compra a la que se le cuelga la factura: la que nombró el dueño, la vacía que la tarjeta
     * prometió reusar si todavía está vacía, o una nueva.
     *
     * 🔴 CON `compra_indicada` NO HAY CAÍDA A UNA COMPRA NUEVA (decisión 3 de Lucas, 16/10/2026): el
     * dueño eligió esa compra sabiendo que tenía artículos —la tarjeta se lo dijo— y en la revisión
     * del escaneo elige qué renglones sumar. Lo que sí se revalida: que siga siendo suya, que exista
     * con el mismo proveedor y que no tenga un escaneo en curso. Si algo de eso cambió entre la
     * propuesta y el sí, se corta con el motivo en vez de mandar la factura a otro lado.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\Provider  $proveedor
     * @param  array  $datos
     * @return array  ['orden' => \App\Models\ProviderOrder, 'adjuntada' => bool] — `adjuntada` es
     *                true si la factura fue a una compra que ya existía (indicada o vacía reusada).
     *
     * @throws AccionIaException
     */
    protected static function orden_para_la_factura(ContextoDeCargaIa $contexto, Provider $proveedor, array $datos)
    {
        $a_reusar = isset($datos['reusar_provider_order']) ? (int) $datos['reusar_provider_order'] : 0;

        if ($a_reusar > 0 && !empty($datos['compra_indicada'])) {

            $indicada = ProviderOrder::where('user_id', $contexto->owner_id)
                                        ->where('id', $a_reusar)
                                        ->where('provider_id', $proveedor->id)
                                        ->first();

            if (is_null($indicada)) {

                throw new AccionIaException(422, 'Esa compra ya no existe o cambió de proveedor. Pedímelo de nuevo.');
            }

            if (self::tiene_escaneo_en_curso($indicada)) {

                throw new AccionIaException(422, 'Esa compra ya tiene un escaneo en curso. Esperá a que termine.');
            }

            /*
             * La bandera del IVA se prende solo en una compra VACÍA, con el mismo criterio que la
             * vacía reusada de abajo. A una compra con artículos que el dueño cargó a mano no se le
             * cambia cómo suma su total: prender la bandera le recalcularía los renglones que ya
             * tenía en la próxima confirmación.
             */
            if (self::esta_vacia($indicada) && !$indicada->total_with_iva) {

                $indicada->total_with_iva = 1;
                $indicada->save();
            }

            return ['orden' => $indicada, 'adjuntada' => true];
        }

        if ($a_reusar > 0) {

            $orden = ProviderOrder::where('user_id', $contexto->owner_id)
                                    ->where('id', $a_reusar)
                                    ->where('provider_id', $proveedor->id)
                                    ->first();

            /*
             * Si entre la propuesta y el sí alguien le cargó artículos, ya no es "la compra vacía
             * de esta factura": se crea una nueva en vez de mezclarlas. La tarjeta decía otra cosa,
             * pero el resultado se lo cuenta al dueño con el número real.
             */
            if (!is_null($orden) && !self::esta_vacia($orden) ) {

                $orden = null;
            }

            if (!is_null($orden) && self::tiene_escaneo_en_curso($orden)) {

                throw new AccionIaException(422, 'Esa compra ya tiene un escaneo en curso. Esperá a que termine.');
            }

            if (!is_null($orden)) {

                /*
                 * 🔴 LA COMPRA VACÍA QUE SE REUSA TAMBIÉN TIENE QUE SUMAR EL IVA (misión
                 * compra-asistente-iva-total, 9/10/2026). Hasta esa misión el alta de abajo no
                 * mandaba `total_with_iva` y la compra quedaba en NULL; una de esas, vacía y de los
                 * últimos DIAS_DE_REUSO días, es justo la que se reusa acá, y la factura que se le
                 * cuelga ahora no sumaría su IVA al total ni a la deuda con el proveedor. Volver a
                 * guardarla desde el formulario NO la arregla: ver el 🔴 del alta de abajo.
                 *
                 * Se prende solo si está apagada (NULL o 0). esta_vacia() de arriba garantiza
                 * únicamente que no tiene ARTÍCULOS (puede tener costos extra o una factura cargada
                 * a mano), y prender la bandera no recalcula nada en el momento: ProviderOrder no
                 * tiene observers. El próximo recálculo —la confirmación del escaneo de esta
                 * factura— le aplica la misma regla que a una compra del formulario. Monotributista
                 * y `precios_incluyen_iva` siguen sin sumar: eso lo resuelve
                 * NewProviderOrderHelper::suma_iva_al_total(), no la bandera.
                 */
                if (!$orden->total_with_iva) {

                    $orden->total_with_iva = 1;
                    $orden->save();
                }

                return ['orden' => $orden, 'adjuntada' => true];
            }
        }

        $model = ProviderOrderAltaHelper::crear([
            'user_id'                  => $contexto->owner_id,
            'provider_id'              => (int) $proveedor->id,
            'provider_order_status_id' => self::estado_en_proceso_id(),
            'address_id'               => isset($datos['address_id']) ? $datos['address_id'] : null,
            /*
             * Los tres interruptores irreversibles nacen como en el formulario: sin actualizar
             * precios ni stock, y generando cuenta corriente. La compra nace VACÍA —los artículos
             * los carga el dueño al confirmar el escaneo, desde la pantalla de revisión— así que
             * acá no hay nada que procesar todavía.
             */
            'update_prices'            => 0,
            'update_stock'             => 0,
            'generate_current_acount'  => 1,
            /*
             * 🔴 Y SUMANDO EL IVA AL TOTAL, como la del formulario (misión compra-asistente-iva-total,
             * 9/10/2026). Antes esta clave no viajaba, ProviderOrderAltaHelper la insertaba en NULL
             * y NewProviderOrderHelper::suma_iva_al_total() la exige: al confirmar el escaneo, el IVA
             * de la factura quedaba en `total_iva` pero no se sumaba al total, y la deuda con el
             * proveedor quedaba NETA.
             *
             * Y no se arreglaba sola: el formulario pone el 1 solo en una compra NUEVA (el `value: 1`
             * de `total_with_iva` en el modelo de la SPA). Al editar, la SPA reenvía lo guardado y
             * ProviderOrderController::update() lo respeta; la casilla está oculta, así que nadie
             * la puede prender DESDE LA PANTALLA. Una compra que nace apagada no suma el IVA nunca
             * más, por más que se la vuelva a guardar desde el formulario.
             *
             * Monotributista y `precios_incluyen_iva` siguen sin sumar: lo decide
             * suma_iva_al_total(), no esta bandera.
             */
            'total_with_iva'           => 1,
            'precios_incluyen_iva'     => isset($datos['precios_incluyen_iva']) && $datos['precios_incluyen_iva'] ? 1 : 0,
            'moneda_id'                => 1,
            'articles'                 => [],
        ]);

        return ['orden' => $model, 'adjuntada' => false];
    }

    /**
     * La primera frase del resultado: qué se hizo con la compra.
     *
     * @param  \App\Models\ProviderOrder  $orden
     * @param  \App\Models\Provider  $proveedor
     * @param  bool  $adjuntada  La factura fue a una compra que ya existía.
     * @param  bool  $proveedor_creado  El proveedor se dio de alta en esta misma carga.
     * @return string
     */
    protected static function que_paso(ProviderOrder $orden, Provider $proveedor, $adjuntada, $proveedor_creado)
    {
        if (!$adjuntada) {

            return 'Creé la Compra N° ' . $orden->num . ' de ' . $proveedor->name
                . ($proveedor_creado ? ' (proveedor nuevo, lo di de alta)' : '') . '.';
        }

        $articulos = $orden->articles()->count();

        $como_estaba = $articulos > 0
            ? 'ya estaba cargada, con ' . ($articulos === 1 ? '1 artículo' : $articulos . ' artículos')
                . ': al revisar el escaneo elegís qué renglones sumar'
            : 'ya estaba creada y sin artículos';

        return 'Adjunté la factura a la Compra N° ' . $orden->num . ' de ' . $proveedor->name . ' (' . $como_estaba . ').';
    }

    /**
     * true si el aviso de "terminó el escaneo" tiene que salir por WhatsApp: la tarjeta la propuso
     * un mensaje de WhatsApp y el admin de este dueño anunció que consulta los avisos.
     *
     * Se mira el canal del mensaje que PROPUSO la tarjeta, no el de quien la confirma: la compra con
     * factura solo se declara en WhatsApp (sus fotos solo existen ahí), y el aviso tiene que volver
     * por donde vino la foto.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return bool
     */
    protected static function avisa_por_whatsapp(ContextoDeCargaIa $contexto, AiMessageAction $accion)
    {
        $propuso = AiMessage::find((int) $accion->ai_message_id);

        if (is_null($propuso) || !$propuso->es_de_whatsapp()) {

            return false;
        }

        return AvisosDelAsistenteHelper::admin_avisa($contexto->owner_id);
    }

    /**
     * Acá vivía resolver_proveedor(): un LIKE en una sola dirección contra `name` y `razon_social`.
     * Desde las correcciones del 24/9/2026 el proveedor lo reconoce ProveedorDeLaFacturaIaHelper,
     * normalizado y en las dos direcciones: "Distribuidora Sur S.R.L." leído en la factura no
     * encontraba a "Distribuidora Sur" y en "resuelto" se daba de alta un duplicado.
     */

    /**
     * La compra de ESTE proveedor que se cargó desde esta conversación hasta MINUTOS_COMPRA_RECIENTE
     * antes del mensaje del dueño y cuyo escaneo sigue en curso, o null — y null siempre que ese
     * mensaje traiga texto. Ver el 🔴 de las facturas de varias fotos en proponer().
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\Provider  $proveedor
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @return \App\Models\ProviderOrder|null
     */
    protected static function compra_reciente_en_curso(ContextoDeCargaIa $contexto, Provider $proveedor, AiMessage $mensaje)
    {
        $pedido = AiMessage::where('ai_conversation_id', $contexto->conversation->id)
                            ->where('rol', 'user')
                            ->where('id', '<', (int) $mensaje->id)
                            ->orderBy('id', 'DESC')
                            ->first();

        /* Con texto ("y esta otra factura") es una compra nueva: la persona dijo algo. */
        if (is_null($pedido) || trim((string) $pedido->contenido) !== '') {

            return null;
        }

        $llego = is_null($pedido->created_at) ? Carbon::now() : Carbon::parse($pedido->created_at);

        $recientes = AiMessageAction::where('ai_conversation_id', $contexto->conversation->id)
                                    ->where('tipo', AiMessageAction::TIPO_COMPRA_CON_FACTURA)
                                    ->where('estado', AiMessageAction::ESTADO_CONFIRMADA)
                                    ->where('resuelta_at', '>=', $llego->copy()->subMinutes(self::MINUTOS_COMPRA_RECIENTE))
                                    ->orderBy('id', 'DESC')
                                    ->get();

        foreach ($recientes as $accion) {

            $resultado = $accion->resultado;

            if (!is_object($resultado) || !isset($resultado->provider_id, $resultado->provider_order_id)) {

                continue;
            }

            if ((int) $resultado->provider_id !== (int) $proveedor->id) {

                continue;
            }

            $orden = ProviderOrder::where('user_id', $contexto->owner_id)
                                    ->where('id', (int) $resultado->provider_order_id)
                                    ->first();

            if (!is_null($orden) && self::tiene_escaneo_en_curso($orden)) {

                return $orden;
            }
        }

        return null;
    }

    /**
     * El proveedor de una tarjeta cuyo proveedor no existía al proponerla: se lo da de alta por el
     * controller de la pantalla (EjecutorGenericoIaHelper), salvo que alguien lo haya creado entre la
     * propuesta y el sí —ahí se usa ése y no se duplica—.
     *
     * Corre adentro de la transacción de la compra: si después el escaneo no puede arrancar, el
     * rollback se lleva también al proveedor, y no queda un proveedor suelto de una compra que no
     * existe. ProviderController::store no tiene efectos hacia afuera (su notificación de alta es un
     * `return` vacío), así que no hay nada que quede del otro lado del rollback.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $datos
     * @return array  [\App\Models\Provider|null, bool $creado_ahora]
     *
     * @throws AccionIaException
     */
    protected static function proveedor_nuevo(ContextoDeCargaIa $contexto, array $datos)
    {
        $alta = $datos['proveedor_nuevo'];

        $nombre = isset($datos['proveedor']) ? trim((string) $datos['proveedor']) : '';

        if ($nombre !== '') {

            /* Normalizado y contra `name` Y `razon_social` (correcciones del 24/9/2026). */
            $ya_existe = ProveedorDeLaFacturaIaHelper::existente($contexto->owner_id, $nombre);

            if (!is_null($ya_existe)) {

                return [Provider::find($ya_existe->id), false];
            }
        }

        /*
         * Una tarjeta de alta "de mentira", sin guardar, con la forma exacta que lee el ejecutor
         * genérico: es el mismo camino que la tarjeta #9 de demo3 (alta de proveedor desde el
         * asistente), con las mismas guardas de permiso y la misma persona autenticada.
         */
        $accion_de_alta = new AiMessageAction();
        $accion_de_alta->tipo = AiMessageAction::TIPO_ALTA;
        $accion_de_alta->datos = $alta;

        $resultado = EjecutorGenericoIaHelper::ejecutar($contexto, $accion_de_alta);

        $proveedor = Provider::where('user_id', $contexto->owner_id)
                                ->where('id', isset($resultado['id']) ? (int) $resultado['id'] : 0)
                                ->first();

        return [$proveedor, !is_null($proveedor)];
    }

    /**
     * La sucursal a la que entra la mercadería, o null. NUNCA pregunta (decisión de Lucas,
     * 24/9/2026): en demo3 (conv 12) la pregunta "¿a cuál de estas seis sucursales entra?" fue uno
     * de los tres mensajes que tardó una compra que el dueño pidió de una.
     *
     * El orden: la que nombró la persona → la de quien escribe (`users.address_id`, la misma que usa
     * el resto de las cargas: OpcionesDeCargaIaHelper::address_id_de_la_persona) → la única que
     * haya → ninguna. Una compra sin sucursal es válida: nace con `update_stock = 0` y la pantalla
     * la pide al revisar el escaneo, que es cuando de verdad se mueve stock.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $nombre
     * @return \App\Models\Address|null
     */
    protected static function resolver_sucursal(ContextoDeCargaIa $contexto, $nombre)
    {
        $sucursales = Address::where('user_id', $contexto->owner_id)->orderBy('id')->get();

        if (count($sucursales) === 0) {

            return null;
        }

        $nombre = trim((string) $nombre);

        if ($nombre !== '') {

            foreach ($sucursales as $sucursal) {

                if (mb_stripos(self::nombre_de_sucursal($sucursal), $nombre) !== false) {

                    return $sucursal;
                }
            }
        }

        $de_la_persona = OpcionesDeCargaIaHelper::address_id_de_la_persona($contexto->persona);

        if (!is_null($de_la_persona)) {

            foreach ($sucursales as $sucursal) {

                if ((int) $sucursal->id === (int) $de_la_persona) {

                    return $sucursal;
                }
            }
        }

        if (count($sucursales) === 1) {

            return $sucursales[0];
        }

        return null;
    }

    /**
     * Las fotos que se van a enganchar a esta factura, y TODAS las que se miraron para elegirlas.
     *
     * 🔴 SE ACOTA A LA ÚLTIMA TANDA DEL DUEÑO, NO A LA CONVERSACIÓN ENTERA. Sin ese corte, una foto
     * vieja que quedó sin gestionar —el dueño mandó la foto de una góndola preguntando un precio a
     * la mañana, nadie la "usó" para nada— entraba como una página más de la factura que manda a la
     * tarde. Nadie se entera hasta que el escaneo devuelve renglones que no existen. Hasta el
     * 24/9/2026 el corte era una ventana de MENSAJES_PARA_LAS_FOTOS mensajes, y dejaba afuera la
     * factura misma cuando se charlaba de más; ahora es la tanda de fotos seguidas del dueño de las
     * últimas 24 horas (FotosDeLaConversacionIaHelper::ultima_tanda), que no depende de cuánto se
     * habló después y sigue dejando afuera la góndola.
     *
     * 🔴 Y SE DEVUELVEN TAMBIÉN LAS QUE NO ENTRAN. Si en la ventana hay más fotos sin gestionar que
     * las que acepta un escaneo, las que sobran NO se pueden dejar libres: quedarían esperando a la
     * próxima compra, que puede ser de otro proveedor, y se colarían ahí. Al confirmar se sellan
     * TODAS las consideradas y solo se suben las elegidas (ver ejecutar()).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que está proponiendo.
     * @return array  ['paginas' => AiMessageImagen[], 'consideradas' => AiMessageImagen[]]
     */
    protected static function imagenes_sin_gestionar(ContextoDeCargaIa $contexto, AiMessage $mensaje)
    {
        $max = self::max_paginas();

        /* Sólo fotos de mensajes del DUEÑO: ver el 🔴 de FotosDeLaConversacionIaHelper. */
        $consideradas = FotosDeLaConversacionIaHelper::ultima_tanda($contexto, $mensaje);

        /*
         * Si sobran, se quedan las MÁS NUEVAS: son las páginas de la factura que el dueño acaba de
         * mandar. Las anteriores se sellan igual al confirmar, pero no viajan al escaneo.
         */
        $paginas = count($consideradas) > $max
            ? array_slice($consideradas, count($consideradas) - $max)
            : $consideradas;

        return ['paginas' => $paginas, 'consideradas' => $consideradas];
    }

    /**
     * Id del estado "En proceso", resuelto por nombre. Null si el cliente le cambió el nombre a
     * sus estados: ahí la compra nace SIN estado, que es lo mismo que deja el formulario de la SPA
     * cuando el select viene vacío, y no un estado adivinado.
     *
     * Ver el comentario de ESTADO_EN_PROCESO: el id fijo dependía del orden de un seeder.
     *
     * @return int|null
     */
    protected static function estado_en_proceso_id()
    {
        $estado = ProviderOrderStatus::where('name', self::ESTADO_EN_PROCESO)->orderBy('id')->first();

        return is_null($estado) ? null : (int) $estado->id;
    }

    /**
     * Cuántas páginas acepta un escaneo. Misma config que el escaneo de facturas.
     *
     * @return int
     */
    protected static function max_paginas()
    {
        $max = (int) config('services.escaneo_factura_compra.max_imagenes', 6);

        return $max > 0 ? $max : 6;
    }

    /**
     * Las fotos de la tarjeta que siguen sin gestionar, en orden y CON LA FILA BLOQUEADA.
     *
     * 🔴 `lockForUpdate()` ES LO QUE EVITA QUE DOS TARJETAS SE LLEVEN LA MISMA FACTURA. El candado
     * del ejecutor bloquea la fila de `ai_message_actions`, así que dos tarjetas DISTINTAS —dos
     * proveedores, dos claves, ninguna reemplaza a la otra— confirmadas casi juntas no se
     * serializan entre sí: las dos llegarían hasta acá y las dos crearían compra + escaneo con las
     * mismas fotos. Eso toca stock y cuenta corriente de dos proveedores.
     *
     * Con el candado, la segunda espera a que la primera commitee y entonces su lectura —que es una
     * lectura bloqueante, o sea que ve lo último commiteado y no el snapshot— ya encuentra las
     * fotos con `gestionada_at` puesto: devuelve vacío y `ejecutar()` corta con el 422 de "esas
     * fotos ya se usaron". Que es exactamente lo que hay que decirle al dueño.
     *
     * El candado va SOLO acá y no en la lectura de `proponer()`: aquélla corre en el job, fuera de
     * toda transacción y sin crear nada (una propuesta es una tarjeta, no una compra), así que un
     * candado ahí no tendría dónde sostenerse ni qué proteger. Lo que hay que serializar es la
     * ESCRITURA, y la escritura es ésta.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed  $ids
     * @return array<int, \App\Models\AiMessageImagen>
     */
    protected static function imagenes_por_id(ContextoDeCargaIa $contexto, $ids)
    {
        $limpios = self::ids_limpios($ids);

        if (!count($limpios)) {

            return [];
        }

        return AiMessageImagen::where('user_id', $contexto->owner_id)
                                ->sinGestionar()
                                ->whereIn('id', $limpios)
                                ->orderBy('id')
                                ->lockForUpdate()
                                ->get()
                                ->all();
    }

    /**
     * Los ids de una lista de fotos.
     *
     * @param  array<int, \App\Models\AiMessageImagen>  $imagenes
     * @return array<int, int>
     */
    protected static function ids_de(array $imagenes)
    {
        $ids = [];

        foreach ($imagenes as $imagen) {

            $ids[] = (int) $imagen->id;
        }

        return $ids;
    }

    /**
     * Lista de ids enteros, sin repetidos ni basura.
     *
     * @param  mixed  $ids
     * @return array<int, int>
     */
    protected static function ids_limpios($ids)
    {
        if (!is_array($ids)) {

            return [];
        }

        $limpios = [];

        foreach ($ids as $id) {

            $id = (int) $id;

            if ($id > 0 && !in_array($id, $limpios, true)) {

                $limpios[] = $id;
            }
        }

        return $limpios;
    }

    /**
     * La compra vacía y reciente de ese proveedor que se puede reusar, o null. Ver el 🔴 del
     * docblock de la clase.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\Provider  $proveedor
     * @return \App\Models\ProviderOrder|null
     */
    protected static function compra_reusable(ContextoDeCargaIa $contexto, Provider $proveedor)
    {
        $desde = Carbon::now()->subDays(self::DIAS_DE_REUSO);

        $candidatas = ProviderOrder::where('user_id', $contexto->owner_id)
                                    ->where('provider_id', $proveedor->id)
                                    ->where('created_at', '>=', $desde)
                                    ->orderBy('id', 'DESC')
                                    ->limit(self::TOPE_CANDIDATOS)
                                    ->get();

        foreach ($candidatas as $candidata) {

            if (!self::esta_vacia($candidata)) {

                continue;
            }

            if (self::tiene_escaneo_en_curso($candidata)) {

                continue;
            }

            return $candidata;
        }

        return null;
    }

    /**
     * true si la compra no tiene ningún artículo cargado.
     *
     * @param  \App\Models\ProviderOrder  $orden
     * @return bool
     */
    protected static function esta_vacia(ProviderOrder $orden)
    {
        return $orden->articles()->count() === 0;
    }

    /**
     * true si la compra tiene un escaneo todavía en curso (misma ventana de vencimiento que el
     * controlador del escaneo: uno más viejo está abandonado y no bloquea).
     *
     * @param  \App\Models\ProviderOrder  $orden
     * @return bool
     */
    protected static function tiene_escaneo_en_curso(ProviderOrder $orden)
    {
        return ProviderOrderScan::where('provider_order_id', $orden->id)
                                ->whereIn('estado', ProviderOrderScanController::ESTADOS_EN_CURSO)
                                ->where('created_at', '>=', Carbon::now()->subMinutes(ProviderOrderScanController::MINUTOS_ESCANEO_EN_CURSO))
                                ->exists();
    }

    /**
     * Cómo se nombra una sucursal: es lo que muestra el select del formulario
     * (`select_prop_name: 'street'`), con el número si lo tiene.
     *
     * @param  \App\Models\Address  $sucursal
     * @return string
     */
    protected static function nombre_de_sucursal(Address $sucursal)
    {
        $nombre = trim((string) $sucursal->street);

        $numero = trim((string) $sucursal->street_number);

        if ($numero !== '') {

            $nombre = trim($nombre . ' ' . $numero);
        }

        return $nombre === '' ? 'Sucursal ' . $sucursal->id : $nombre;
    }
}
