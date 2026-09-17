<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\UserHelper;
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
     * Cuántos mensajes hacia atrás se miran para juntar las fotos de la factura.
     *
     * El caso real son cuatro: la foto, el "¿de qué proveedor es?", la respuesta del dueño y la
     * propuesta. Seis deja lugar para un ida y vuelta más (por ejemplo, la sucursal) sin llegar a
     * agarrar una foto de otro momento de la charla.
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
     * Herramienta proponer_compra_con_factura.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input  proveedor*, sucursal
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

        $proveedor = self::resolver_proveedor($contexto, EntradaDeCargaIa::texto($input, 'proveedor'));

        if (RespuestaDeCargaIa::es_negativa($proveedor)) {

            return $proveedor;
        }

        $fotos = self::imagenes_sin_gestionar($contexto, $mensaje);

        if (!count($fotos['paginas'])) {

            return RespuestaDeCargaIa::error(
                'No tengo ninguna foto de factura sin usar en los últimos mensajes. Mandámela y te la cargo.'
            );
        }

        $sucursal = self::resolver_sucursal($contexto, EntradaDeCargaIa::texto($input, 'sucursal'));

        if (RespuestaDeCargaIa::es_negativa($sucursal)) {

            return $sucursal;
        }

        $a_reusar = self::compra_reusable($contexto, $proveedor);

        $imagen_ids = self::ids_de($fotos['paginas']);

        $consideradas_ids = self::ids_de($fotos['consideradas']);

        $renglones = [
            ['etiqueta' => 'Proveedor', 'valor' => (string) $proveedor->name],
        ];

        if (!is_null($sucursal)) {

            $renglones[] = ['etiqueta' => 'Sucursal', 'valor' => self::nombre_de_sucursal($sucursal)];
        }

        $cuantas = count($imagen_ids) === 1 ? '1 foto' : count($imagen_ids) . ' fotos';

        /*
         * Si quedaron fotos afuera del escaneo, la tarjeta lo dice ANTES de que el dueño confirme:
         * al confirmar se sellan todas (si no, las que sobran se colarían en la próxima compra),
         * así que tiene que poder decir "pará, esa no es de esta factura".
         */
        if (count($consideradas_ids) > count($imagen_ids)) {

            $cuantas .= ' (de ' . count($consideradas_ids) . ' sin usar; van las más nuevas)';
        }

        $renglones[] = ['etiqueta' => 'Fotos de la factura', 'valor' => $cuantas];

        $que_pasa = is_null($a_reusar)
            ? 'Se crea una compra nueva'
            : 'Se usa la compra N° ' . $a_reusar->num . ', que está vacía';

        $renglones[] = ['etiqueta' => 'Qué se hace', 'valor' => $que_pasa];

        $datos = [
            'provider_id'           => (int) $proveedor->id,
            'proveedor'             => (string) $proveedor->name,
            'address_id'            => is_null($sucursal) ? null : (int) $sucursal->id,
            'sucursal'              => is_null($sucursal) ? null : self::nombre_de_sucursal($sucursal),
            'imagen_ids'            => $imagen_ids,
            /* Las que se sellan al confirmar: superset de imagen_ids (ver imagenes_sin_gestionar). */
            'imagen_ids_miradas'    => $consideradas_ids,
            'reusar_provider_order' => is_null($a_reusar) ? null : (int) $a_reusar->id,
            /*
             * Espejo de `prefill_prop_on_select` del formulario de la SPA (src/models/provider_order.js):
             * al elegir el proveedor, la compra hereda su default de costos brutos. Si acá se
             * ignorara, la misma compra cargada por la pantalla y por el asistente interpretaría
             * los costos de la factura distinto, y una cuenta de Responsable Inscripto quedaría 21%
             * abajo o arriba.
             */
            'precios_incluyen_iva'  => (bool) $proveedor->precios_incluyen_iva,
        ];

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_COMPRA_CON_FACTURA,
            self::clave($proveedor->id),
            $datos,
            ['titulo' => 'Compra con factura', 'renglones' => $renglones, 'aviso' => null],
            EntradaDeCargaIa::valor($input, 'reemplaza_a')
        );

        $resumen = 'Compra de ' . $proveedor->name . ' · ' . $cuantas . ' · ' . $que_pasa;

        return AccionesIaHelper::respuesta_de_propuesta($creada, $resumen);
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
     * @return array  resultado {texto, ruta}
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

        $proveedor = Provider::where('user_id', $contexto->owner_id)
                                ->where('id', isset($datos['provider_id']) ? (int) $datos['provider_id'] : 0)
                                ->first();

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

        $orden = self::orden_para_la_factura($contexto, $proveedor, $datos);

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

        $resultado = ProviderOrderScanAltaHelper::crear($orden, $paginas, $contexto->persona);

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

        return [
            'texto' => 'Compra N° ' . $orden->num . ' creada, estoy leyendo la factura. Cuando termine la revisás desde Compras',
            'ruta'  => [
                'name'   => 'proveedores',
                'params' => new \stdClass(),
                'texto'  => 'Ver en Compras',
            ],
        ];
    }

    /**
     * La compra a la que se le cuelga la factura: la vacía que la tarjeta prometió reusar, si
     * todavía está vacía, o una nueva.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\Provider  $proveedor
     * @param  array  $datos
     * @return \App\Models\ProviderOrder
     *
     * @throws AccionIaException
     */
    protected static function orden_para_la_factura(ContextoDeCargaIa $contexto, Provider $proveedor, array $datos)
    {
        $a_reusar = isset($datos['reusar_provider_order']) ? (int) $datos['reusar_provider_order'] : 0;

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

                return $orden;
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
            'precios_incluyen_iva'     => isset($datos['precios_incluyen_iva']) && $datos['precios_incluyen_iva'] ? 1 : 0,
            'moneda_id'                => 1,
            'articles'                 => [],
        ]);

        return $model;
    }

    /**
     * El proveedor que nombró el dueño, o la respuesta de negocio que pide desambiguar.
     *
     * 🔴 NUNCA CREA UN PROVEEDOR. Un proveedor nuevo arrastra cuenta corriente, bonificaciones y
     * condición fiscal: si el nombre no resuelve, se pregunta. Misma mecánica que el resto de las
     * herramientas de carga.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $nombre
     * @return \App\Models\Provider|array
     */
    protected static function resolver_proveedor(ContextoDeCargaIa $contexto, $nombre)
    {
        $nombre = trim((string) $nombre);

        if ($nombre === '') {

            return RespuestaDeCargaIa::faltan(
                ['de qué proveedor es la factura'],
                ['proveedores' => self::candidatos($contexto, '')]
            );
        }

        $candidatos = Provider::where('user_id', $contexto->owner_id)
                                ->where('name', 'LIKE', '%' . addcslashes($nombre, '%_\\') . '%')
                                ->orderBy('name')
                                ->limit(self::TOPE_CANDIDATOS)
                                ->get();

        if (count($candidatos) === 1) {

            return $candidatos[0];
        }

        if (count($candidatos) === 0) {

            return RespuestaDeCargaIa::error(
                'No encontré ningún proveedor que se llame así. No puedo crear proveedores: cargalo desde Proveedores y volvé a pedírmelo.',
                ['proveedores' => self::candidatos($contexto, '')]
            );
        }

        /* Un nombre escrito completo gana sobre los parciales: "Sur" no es "Sur SRL" si existe "Sur". */
        foreach ($candidatos as $candidato) {

            if (mb_strtolower(trim((string) $candidato->name)) === mb_strtolower($nombre)) {

                return $candidato;
            }
        }

        return RespuestaDeCargaIa::faltan(
            ['cuál de estos proveedores es'],
            ['proveedores' => self::como_opciones($candidatos)]
        );
    }

    /**
     * La sucursal a la que entra la mercadería, o la respuesta de negocio que la pregunta.
     *
     * `address_id` es obligatorio en el formulario de la SPA solo si la cuenta TIENE sucursales
     * (`required_if_models_length: 'address'`), así que una cuenta sin ninguna devuelve null y la
     * compra se crea sin sucursal, igual que por la pantalla.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $nombre
     * @return \App\Models\Address|null|array
     */
    protected static function resolver_sucursal(ContextoDeCargaIa $contexto, $nombre)
    {
        $sucursales = Address::where('user_id', $contexto->owner_id)->orderBy('id')->get();

        if (count($sucursales) === 0) {

            return null;
        }

        if (count($sucursales) === 1) {

            return $sucursales[0];
        }

        $nombre = trim((string) $nombre);

        if ($nombre !== '') {

            foreach ($sucursales as $sucursal) {

                if (mb_stripos(self::nombre_de_sucursal($sucursal), $nombre) !== false) {

                    return $sucursal;
                }
            }
        }

        $opciones = [];

        foreach ($sucursales as $sucursal) {

            $opciones[] = ['id' => (int) $sucursal->id, 'sucursal' => self::nombre_de_sucursal($sucursal)];
        }

        return RespuestaDeCargaIa::faltan(['a qué sucursal entra la mercadería'], ['sucursales' => $opciones]);
    }

    /**
     * Las fotos que se van a enganchar a esta factura, y TODAS las que se miraron para elegirlas.
     *
     * 🔴 SE ACOTA POR LOS ÚLTIMOS MENSAJES, NO POR LA CONVERSACIÓN ENTERA. Sin ese corte, una foto
     * vieja que quedó sin gestionar —el dueño mandó la foto de una góndola preguntando un precio a
     * la mañana, nadie la "usó" para nada— entraba como una página más de la factura que manda a la
     * tarde. Nadie se entera hasta que el escaneo devuelve renglones que no existen. La ventana de
     * MENSAJES_PARA_LAS_FOTOS alcanza de sobra para el caso real —foto, "¿de qué proveedor es?",
     * "de Distribuidora Sur", la propuesta— y deja afuera cualquier cosa de más atrás.
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

        /* Los ids de los últimos mensajes de la conversación, hasta el que está proponiendo. */
        $mensajes_recientes = AiMessage::where('ai_conversation_id', $contexto->conversation->id)
                                        ->where('id', '<=', $mensaje->id)
                                        ->orderBy('id', 'DESC')
                                        ->limit(self::MENSAJES_PARA_LAS_FOTOS)
                                        ->pluck('id');

        if (!count($mensajes_recientes)) {

            return ['paginas' => [], 'consideradas' => []];
        }

        $consideradas = AiMessageImagen::where('user_id', $contexto->owner_id)
                                        ->sinGestionar()
                                        ->whereIn('ai_message_id', $mensajes_recientes->all())
                                        ->orderBy('id')
                                        ->get()
                                        ->all();

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
     * Los primeros proveedores del dueño, para ofrecerlos cuando el nombre no resuelve.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $busqueda
     * @return array
     */
    protected static function candidatos(ContextoDeCargaIa $contexto, $busqueda)
    {
        $query = Provider::where('user_id', $contexto->owner_id);

        if (trim((string) $busqueda) !== '') {

            $query->where('name', 'LIKE', '%' . addcslashes(trim((string) $busqueda), '%_\\') . '%');
        }

        return self::como_opciones($query->orderBy('name')->limit(self::TOPE_CANDIDATOS)->get());
    }

    /**
     * @param  iterable  $proveedores
     * @return array
     */
    protected static function como_opciones($proveedores)
    {
        $opciones = [];

        foreach ($proveedores as $proveedor) {

            $opciones[] = ['id' => (int) $proveedor->id, 'proveedor' => (string) $proveedor->name];
        }

        return $opciones;
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
