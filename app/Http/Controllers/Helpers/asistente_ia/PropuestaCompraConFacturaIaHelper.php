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

    /** Estado con el que nace una compra desde acá: "En proceso" (ProviderOrderStatusSeeder). */
    const ESTADO_EN_PROCESO = 1;

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

        $imagenes = self::imagenes_sin_gestionar($contexto, $mensaje);

        if (!count($imagenes)) {

            return RespuestaDeCargaIa::error(
                'No tengo ninguna foto de factura sin usar en esta conversación. Mandámela y te la cargo.'
            );
        }

        $sucursal = self::resolver_sucursal($contexto, EntradaDeCargaIa::texto($input, 'sucursal'));

        if (RespuestaDeCargaIa::es_negativa($sucursal)) {

            return $sucursal;
        }

        $a_reusar = self::compra_reusable($contexto, $proveedor);

        $imagen_ids = [];

        foreach ($imagenes as $imagen) {

            $imagen_ids[] = (int) $imagen->id;
        }

        $renglones = [
            ['etiqueta' => 'Proveedor', 'valor' => (string) $proveedor->name],
        ];

        if (!is_null($sucursal)) {

            $renglones[] = ['etiqueta' => 'Sucursal', 'valor' => self::nombre_de_sucursal($sucursal)];
        }

        $fotos = count($imagen_ids) === 1 ? '1 foto' : count($imagen_ids) . ' fotos';

        $renglones[] = ['etiqueta' => 'Fotos de la factura', 'valor' => $fotos];

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

        $resumen = 'Compra de ' . $proveedor->name . ' · ' . $fotos . ' · ' . $que_pasa;

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

        $imagenes = self::imagenes_por_id($contexto, isset($datos['imagen_ids']) ? $datos['imagen_ids'] : []);

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

        $ids = [];

        foreach ($imagenes as $imagen) {

            $ids[] = (int) $imagen->id;
        }

        AsistenteImagenHelper::marcar_gestionadas($ids);

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
            'provider_order_status_id' => self::ESTADO_EN_PROCESO,
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
     * Las fotos de esta conversación que todavía no usó ninguna carga, de la más vieja a la más
     * nueva y topeadas en lo que acepta un escaneo.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje
     * @return array<int, \App\Models\AiMessageImagen>
     */
    protected static function imagenes_sin_gestionar(ContextoDeCargaIa $contexto, AiMessage $mensaje)
    {
        $max = (int) config('services.escaneo_factura_compra.max_imagenes', 6);
        $max = $max > 0 ? $max : 6;

        $recientes = AiMessageImagen::where('ai_message_imagenes.user_id', $contexto->owner_id)
                                    ->sinGestionar()
                                    ->whereIn('ai_message_imagenes.ai_message_id', function ($query) use ($contexto) {
                                        $query->select('id')
                                                ->from('ai_messages')
                                                ->where('ai_conversation_id', $contexto->conversation->id);
                                    })
                                    ->orderBy('ai_message_imagenes.id', 'DESC')
                                    ->limit($max)
                                    ->get();

        /* Se leen de la más nueva para topear, y se devuelven en el orden en que llegaron. */
        return array_reverse($recientes->all());
    }

    /**
     * Las fotos de la tarjeta que siguen sin gestionar, en orden.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed  $ids
     * @return array<int, \App\Models\AiMessageImagen>
     */
    protected static function imagenes_por_id(ContextoDeCargaIa $contexto, $ids)
    {
        if (!is_array($ids) || !count($ids)) {

            return [];
        }

        $limpios = [];

        foreach ($ids as $id) {

            $limpios[] = (int) $id;
        }

        return AiMessageImagen::where('user_id', $contexto->owner_id)
                                ->sinGestionar()
                                ->whereIn('id', $limpios)
                                ->orderBy('id')
                                ->get()
                                ->all();
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
