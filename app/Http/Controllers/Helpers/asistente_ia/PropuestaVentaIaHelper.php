<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Http\Controllers\Helpers\ConsultasSistemaIaHelper;
use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\SaleController;
use App\Models\Address;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\Discount;
use App\Models\PriceType;
use App\Models\SaleType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * La venta propuesta por el asistente (misión asistente-omnisciente, §4 del contrato, 21/9/2026):
 * validación al proponer, tarjeta, payload de ejecución y ejecución al confirmar.
 *
 * 🔴 LA VENTA SE CREA POR `SaleController::store()` CON EL MISMO PAYLOAD QUE ARMA `vender.js`.
 * Nada de `Sale::create` a mano ni de reimplementar `attachProperies`: una venta tiene stock,
 * cuenta corriente, movimiento de caja, comisión, puntos, costo y ganancia colgando del mismo
 * método, y una segunda forma de crearla es una segunda verdad que envejece por su lado. Lo que
 * este helper hace es lo que hace la PANTALLA antes de apretar Guardar: resolver los renglones,
 * preciarlos con la misma cascada (`ArticlePricesHelper::resolver_precio_de_venta`), aplicar el
 * descuento del método de pago y el de venta como `vender_set_total.js`, chequear lo que
 * `checkear_vender()` chequea, y armar el POST clave por clave con los defaults del store de
 * Vender para todo lo que la persona no dijo. El mapa clave por clave está en `payload()`.
 *
 * 🔴 TODO LO QUE EL CONTROLLER RECHAZA CON 422 SE PIDE ANTES, en la propuesta, para que el clic
 * no falle: la lista de precios obligatoria (`PriceTypeHelper::requiere_lista_de_precios`), el
 * método de pago de una venta de contado (`PaymentMethodHelper::validar_venta_nueva`) y la cuenta
 * corriente del cliente (sin `credit_account` en pesos, `CurrentAcountFromSaleHelper` revienta con
 * la venta a medio escribir). Y lo que la pantalla frena sin que el controller lo sepa —la caja
 * cuando la cuenta tiene cajas (`chequeos/cajas.js`), la sucursal cuando la cuenta tiene
 * sucursales (`chequeos/sucursal.js`), el tipo de venta (`chequeos/sale_type.js`)— también.
 * El límite de crédito NO: ese 422 vuelve como texto de la tarjeta, porque el saldo cambia
 * entre la propuesta y el clic y es el controller el que tiene la última palabra.
 *
 * NO SE AUTO-CONFIRMA CON "CAUTELOSO" NI CON "RESUELTO": `venta` no entra en
 * `HerramientasDeCarga::AUTO_CONFIRMABLES`. Una venta mueve stock y plata.
 *
 * ⚠️ SÍ se ejecuta sola con el dueño en "directo" (misión asistente-capacidades-y-hilos,
 * 22/9/2026): entra en `AUTO_CONFIRMABLES_DIRECTO` y su `case` en ejecutar() pasa por
 * quizas_auto_confirmar(), que es quien mira el modo. Ese modo se prende a mano desde la
 * configuración del asistente y el default sigue siendo "resuelto", así que nadie lo tiene sin
 * haberlo pedido. Lo que NO se auto-ejecuta en ningún modo es borrar (proponer_baja), la
 * actualización masiva y la unificación de bancos: ver NUNCA_AUTO_CONFIRMABLES.
 */
/*
 * ⚠️ SIETE MÉTODOS DE ESTA CLASE SON `public` Y NO `protected`, Y ES A PROPÓSITO (misión
 * asistente-capacidades-y-hilos, 22/9/2026): `resolver_cliente`, `resolver_lista_de_precios`,
 * `resolver_renglones`, `resolver_descuento`, `resolver_sucursal`, `renglon_de_articulo` y
 * `porcentaje` los usa también `PropuestaPresupuestoIaHelper`. Un presupuesto de VENDER es la
 * MISMA pantalla que una venta hasta el momento de cobrar: mismos renglones, misma cascada de
 * precios por lista, mismo catálogo de descuentos y misma sucursal. Copiar esos resolvedores en el
 * otro archivo sería tener dos criterios de precio para la misma pantalla, que es exactamente la
 * clase de error que el repo ya tiene aprendida.
 */
class PropuestaVentaIaHelper
{
    /**
     * Permiso para vender: la ruta `/vender` de la SPA pide `can: 'sale.store'`
     * (src/router/routes.js:69). Mismo origen que el de gastos (`expense.store`).
     */
    const PERMISO = 'sale.store';

    /**
     * Permiso para aplicar un descuento de venta: el panel de descuentos de la etapa 3 de Vender se
     * dibuja con `can('sale.discount_surchage.aplicar')` (src/mixins/vender/discount_surchage_permissions.js).
     */
    const PERMISO_DESCUENTOS = 'sale.discount_surchage.aplicar';

    /** Tope de renglones por venta (contrato §4). */
    const MAX_ITEMS = 50;

    /** Tope de candidatos que se ofrecen cuando un nombre es ambiguo. */
    const TOPE_CANDIDATOS = 10;

    /**
     * Extensiones que gobiernan el stock en Vender (src/mixins/vender/check_stock.js): con `check`
     * la pantalla NO deja agregar un artículo sin stock suficiente; con `warn` avisa y lo agrega
     * igual; sin ninguna no mira el stock. Acá el aviso va siempre que el stock quede negativo (una
     * tarjeta puede decir más que un toast), y el rechazo solo con `check`.
     */
    const EXTENSION_CHECK_STOCK = 'check_article_stock_en_vender';

    /** Extensión que habilita la fecha de entrega en Vender (`SaleHelper::get_terminada`). */
    const EXTENSION_FECHA_ENTREGA = 'ventas_con_fecha_de_entrega';

    /** Los dos cobros que entiende la herramienta (contrato §4). */
    const COBRO_CONTADO = 'contado';

    const COBRO_CUENTA_CORRIENTE = 'cuenta_corriente';

    /**
     * Ruta de la SPA donde ver la venta registrada: `name: 'sale'` es `/ventas/:view?/:sub_view?`
     * (src/router/index.js:113). Va sin params (`new \stdClass()`, como el gasto): la vista
     * resuelve `view || 'todas'` y `sub_view || 'todos'` (`resolve_view_scope()` de
     * src/mixins/sale.js), que es donde cae el nav para un admin.
     */
    const RUTA_VENTAS = 'sale';

    /** Lo que se le contesta cuando `SaleController::store()` devolvió el 200 vacío del guard anti-duplicado. */
    const MENSAJE_DUPLICADA = 'El sistema encontró una venta igual creada hace segundos y no la duplicó. '
        . 'Mirá la pantalla de ventas antes de volver a intentar.';

    /**
     * Herramienta proponer_venta.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input  items*, cliente, cobro, metodo_de_pago, caja, lista_de_precios,
     *                        descuento_porcentaje, observaciones, sucursal, fecha_entrega,
     *                        tipo_de_venta, reemplaza_a
     * @param  mixed  $reemplaza_a  Id de la tarjeta que corrige (si no viene, se lee de $input).
     * @return array  Respuesta de la herramienta (propuesta creada o respuesta de negocio).
     */
    public static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input, $reemplaza_a = null): array
    {
        if (!PermisosIaHelper::puede($contexto->persona, self::PERMISO)) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_permiso('ventas'));
        }

        if (is_null($contexto->owner)) {

            return RespuestaDeCargaIa::error('No pude resolver el dueño de la cuenta. Probá de nuevo en unos segundos.');
        }

        if (is_null($reemplaza_a)) {
            $reemplaza_a = EntradaDeCargaIa::valor($input, 'reemplaza_a');
        }

        /** Lo que falta para armar la venta, para preguntarlo todo en un solo mensaje. */
        $faltan = [];

        /** Las opciones que acompañan a lo que falta. */
        $opciones = [];

        // ------------------------------------------------------------------ cliente
        $cliente = self::resolver_cliente($contexto, EntradaDeCargaIa::valor($input, 'cliente'));

        if (RespuestaDeCargaIa::es_negativa($cliente)) {

            return $cliente;
        }

        // ------------------------------------------------------------------ cobro
        /*
         * Sin decir cómo se cobra, la pregunta se JUNTA con las demás (los artículos ambiguos, la
         * sucursal) en vez de cortar acá: la regla del prompt es preguntar todo lo que falta en un
         * solo mensaje. Un cobro mal escrito o imposible sí corta, es un error.
         */
        $cobro = self::resolver_cobro($contexto, $cliente, EntradaDeCargaIa::texto($input, 'cobro'));

        if (RespuestaDeCargaIa::es_negativa($cobro)) {

            if (!count($cobro['faltan'])) {

                return $cobro;
            }

            self::juntar_faltantes($faltan, $opciones, $cobro);

            $cobro = null;
        }

        $metodo = null;
        $caja = null;

        if ($cobro === self::COBRO_CONTADO) {

            $metodo = self::resolver_metodo_de_pago(EntradaDeCargaIa::texto($input, 'metodo_de_pago'), $faltan, $opciones);

            if (RespuestaDeCargaIa::es_negativa($metodo)) {

                return $metodo;
            }

            $caja = self::resolver_caja($contexto, $metodo, EntradaDeCargaIa::texto($input, 'caja'), $faltan, $opciones);

            if (RespuestaDeCargaIa::es_negativa($caja)) {

                return $caja;
            }
        }

        // ------------------------------------------------------------------ lista de precios
        $lista = self::resolver_lista_de_precios($contexto, $cliente, EntradaDeCargaIa::texto($input, 'lista_de_precios'));

        if (RespuestaDeCargaIa::es_negativa($lista)) {

            return $lista;
        }

        // ------------------------------------------------------------------ renglones
        $porcentaje_del_metodo = is_null($metodo)
            ? null
            : SaleHelper::resolver_descuento_recargo_metodo_pago($contexto->owner_id, (int) $metodo->id, null);

        $renglones = self::resolver_renglones($contexto, EntradaDeCargaIa::valor($input, 'items'), $lista, $porcentaje_del_metodo, $faltan, $opciones);

        if (RespuestaDeCargaIa::es_negativa($renglones)) {

            return $renglones;
        }

        // ------------------------------------------------------------------ descuento de venta
        $descuento = self::resolver_descuento($contexto, $cliente, EntradaDeCargaIa::valor($input, 'descuento_porcentaje'));

        if (RespuestaDeCargaIa::es_negativa($descuento)) {

            return $descuento;
        }

        // ------------------------------------------------------------------ sucursal y tipo de venta
        $sucursal = self::resolver_sucursal($contexto, EntradaDeCargaIa::texto($input, 'sucursal'), $faltan, $opciones);

        if (RespuestaDeCargaIa::es_negativa($sucursal)) {

            return $sucursal;
        }

        $tipo_de_venta = self::resolver_tipo_de_venta($contexto, EntradaDeCargaIa::texto($input, 'tipo_de_venta'), $faltan, $opciones);

        if (RespuestaDeCargaIa::es_negativa($tipo_de_venta)) {

            return $tipo_de_venta;
        }

        // ------------------------------------------------------------------ fecha de entrega y observaciones
        $fecha_entrega = self::resolver_fecha_entrega($contexto, EntradaDeCargaIa::valor($input, 'fecha_entrega'));

        if (RespuestaDeCargaIa::es_negativa($fecha_entrega)) {

            return $fecha_entrega;
        }

        $observaciones = EntradaDeCargaIa::texto($input, 'observaciones');

        if (count($faltan)) {

            return RespuestaDeCargaIa::faltan($faltan, $opciones);
        }

        // ------------------------------------------------------------------ totales, como vender_set_total.js
        $totales = self::totales($renglones, $descuento);

        // ------------------------------------------------------------------ stock
        $stock = self::revisar_stock($contexto, $renglones);

        if (RespuestaDeCargaIa::es_negativa($stock)) {

            return $stock;
        }

        // ------------------------------------------------------------------ payload y tarjeta
        $payload = self::payload($renglones, $cliente, $cobro, $metodo, $caja, $lista, $descuento, $sucursal, $tipo_de_venta, $fecha_entrega, $observaciones, $totales);

        $presentacion = self::presentacion($renglones, $cliente, $cobro, $metodo, $caja, $lista, $descuento, $sucursal, $fecha_entrega, $observaciones, $totales, $stock);

        $resumen = self::resumen($renglones, $cliente, $cobro, $metodo, $caja, $lista, $descuento, $sucursal, $fecha_entrega, $totales);

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            self::tipo(),
            self::clave($renglones, $cliente),
            ['payload' => $payload, 'resumen' => $resumen],
            $presentacion,
            $reemplaza_a
        );

        return AccionesIaHelper::respuesta_de_propuesta($creada, self::resumen_en_una_linea($renglones, $cliente, $cobro, $metodo, $caja, $totales));
    }

    /**
     * Identidad de la venta para el reemplazo: la misma combinación de artículos (sin importar
     * cantidades ni precios) para el mismo cliente. Una corrección ("no, eran 5 martillos") pisa la
     * tarjeta; otra venta con otros artículos u otro cliente no la pisa.
     *
     * @param  array  $renglones
     * @param  \App\Models\Client|null  $cliente
     * @return string
     */
    public static function clave(array $renglones, $cliente): string
    {
        $ids = [];

        foreach ($renglones as $renglon) {
            $ids[] = (int) $renglon['article']->id;
        }

        sort($ids);

        $firma = implode(',', $ids) . '|' . (is_null($cliente) ? 0 : (int) $cliente->id);

        return 'venta:' . substr(md5($firma), 0, 16);
    }

    /**
     * El tipo de tarjeta. `AiMessageAction::TIPO_VENTA` lo declara el constructor B (contrato §4);
     * mientras no esté, el literal es el mismo que va a declarar.
     *
     * @return string
     */
    public static function tipo(): string
    {
        return defined('App\Models\AiMessageAction::TIPO_VENTA') ? constant('App\Models\AiMessageAction::TIPO_VENTA') : 'venta';
    }

    /**
     * Registra la venta de la tarjeta por `SaleController::store()`, con el payload guardado al
     * proponer. Corre adentro de la transacción de EjecutorAccionesIaHelper, autenticado como la
     * persona que confirma (clic: sanctum; WhatsApp: ConfirmacionPorTextoIaHelper).
     *
     * 🔴 `store()` abre su propia transacción adentro de la del ejecutor —queda como savepoint— y
     * toma un GET_LOCK por comercio: un 201 deja la venta escrita y el commit real lo hace el
     * ejecutor al cerrar; un 422 del controller sale ANTES de que abra la suya y no escribe nada;
     * y un 500 de adentro ya hizo su rollback al savepoint. Los tres casos están cubiertos por
     * test (39_Venta_por_asistente_Test).
     *
     * 🔴 EL 200 VACÍO DEL GUARD ANTI-DUPLICADO (`venta_ya_cread`) NO ES UN ÉXITO. Es la misma venta
     * (mismo cliente, mismo empleado, mismo total) creada hace menos de 5 segundos, casi siempre
     * desde la pantalla. Se corta con un 422 y un texto que manda a mirar Ventas: si se tratara
     * como éxito, la tarjeta diría "registrada" sobre una venta que no se creó, y si se dejara
     * pasar en silencio, la persona reintentaría a los 6 segundos y AHÍ sí la duplicaría.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array  resultado {texto, ruta, venta_id, numero, total}
     *
     * @throws AccionIaException  422 si la venta ya no se puede hacer; 500 si no hay persona autenticada.
     */
    public static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion): array
    {
        if (!PermisosIaHelper::puede($contexto->persona, self::PERMISO)) {

            throw new AccionIaException(422, PermisosIaHelper::mensaje_sin_permiso('ventas'));
        }

        /*
         * Contrato §5: la persona tiene que estar autenticada ANTES de llamar al controller, porque
         * `store()` lee `UserHelper::userId()` para el dueño, el empleado y el correlativo. Los dos
         * caminos (clic y WhatsApp) ya lo garantizan; esto es la defensa por si aparece un tercero.
         */
        $persona = $contexto->persona;

        if (is_null($persona) || is_null(Auth::id()) || (int) Auth::id() !== (int) $persona->id) {

            throw new AccionIaException(500, 'La venta no se puede registrar sin la persona autenticada.');
        }

        $datos = is_array($accion->datos) ? $accion->datos : [];

        $payload = isset($datos['payload']) && is_array($datos['payload']) ? $datos['payload'] : [];

        if (!isset($payload['items']) || !is_array($payload['items']) || !count($payload['items'])) {

            throw new AccionIaException(422, 'La tarjeta no tiene artículos. Pedímela de nuevo.');
        }

        self::verificar_para_ejecutar($contexto, $payload);

        $request = Request::create('/api/sale', 'POST', $payload);
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(function () use ($persona) {
            return $persona;
        });

        $respuesta = app(SaleController::class)->store($request);

        $status = (int) $respuesta->getStatusCode();
        $contenido = (string) $respuesta->getContent();
        $body = json_decode($contenido, true);

        if ($status === 201 && is_array($body) && isset($body['model']) && is_array($body['model'])) {

            $venta = $body['model'];

            $numero = isset($venta['num']) ? (int) $venta['num'] : 0;

            return [
                'texto'    => 'Venta N° ' . $numero . ' registrada',
                'ruta'     => [
                    'name'   => self::RUTA_VENTAS,
                    'params' => new \stdClass(),
                    'texto'  => 'Ver en Ventas',
                ],
                'venta_id' => isset($venta['id']) ? (int) $venta['id'] : 0,
                'numero'   => $numero,
                'total'    => isset($venta['total']) ? (float) $venta['total'] : (float) $payload['total'],
            ];
        }

        if ($status === 200 && trim($contenido) === '') {

            throw new AccionIaException(422, self::MENSAJE_DUPLICADA);
        }

        $mensaje = is_array($body) && isset($body['message']) && trim((string) $body['message']) !== ''
            ? trim((string) $body['message'])
            : 'No se pudo registrar la venta.';

        if ($status >= 500) {
            $mensaje = 'No se pudo registrar la venta: ' . $mensaje;
        }

        throw new AccionIaException(422, mb_substr($mensaje, 0, 1000));
    }

    /**
     * Lo que pudo cambiar entre la propuesta y el clic, revisado ANTES de llamar al controller:
     * los artículos siguen siendo del dueño, el cliente sigue existiendo, y la caja de un cobro
     * de contado sigue abierta y ofrecible (mismo criterio que PagosIaHelper::verificar_para_ejecutar:
     * un movimiento colgado de una caja que cerraron en el medio descuadra ese arqueo sin aviso).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $payload
     * @return void
     *
     * @throws AccionIaException
     */
    protected static function verificar_para_ejecutar(ContextoDeCargaIa $contexto, array $payload)
    {
        $ids = [];

        foreach ($payload['items'] as $item) {
            if (is_array($item) && isset($item['id'])) {
                $ids[] = (int) $item['id'];
            }
        }

        $existen = Article::where('user_id', $contexto->owner_id)
            ->whereIn('id', $ids)
            ->count('id');

        if ($existen !== count(array_unique($ids))) {

            throw new AccionIaException(422, 'Alguno de los artículos de la tarjeta ya no existe entre los tuyos. Pedímela de nuevo.');
        }

        $client_id = isset($payload['client_id']) ? (int) $payload['client_id'] : 0;

        if ($client_id > 0 && !Client::where('user_id', $contexto->owner_id)->where('id', $client_id)->exists()) {

            throw new AccionIaException(422, 'El cliente de la tarjeta ya no existe entre los tuyos. Pedímela de nuevo.');
        }

        $caja_id = isset($payload['caja_id']) ? (int) $payload['caja_id'] : 0;

        if ($caja_id > 0) {

            $sigue_ofrecible = false;

            foreach (OpcionesDeCargaIaHelper::cajas_ofrecibles($contexto, 1) as $ofrecible) {

                if ((int) $ofrecible->id === $caja_id) {
                    $sigue_ofrecible = true;
                    break;
                }
            }

            if (!$sigue_ofrecible) {

                throw new AccionIaException(422, 'La caja de la tarjeta ya no está disponible (la cerraron o ya no la podés usar). Pedime la venta de nuevo.');
            }
        }
    }

    // =====================================================================================
    // Resolución de cada dato de la venta
    // =====================================================================================

    /**
     * El cliente que nombró la persona (por nombre o por id), null si no nombró ninguno, o la
     * respuesta de negocio.
     *
     * 🔴 NUNCA CREA UN CLIENTE. Un cliente nuevo arrastra cuenta corriente, lista de precios y
     * condición fiscal: si el nombre no resuelve, se pregunta. Misma mecánica que el proveedor de
     * PropuestaCompraConFacturaIaHelper.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed  $valor
     * @return \App\Models\Client|null|array
     */
    public static function resolver_cliente(ContextoDeCargaIa $contexto, $valor)
    {
        if (EntradaDeCargaIa::vacio($valor)) {

            return null;
        }

        if (is_int($valor) || (is_string($valor) && ctype_digit(trim($valor)))) {

            $cliente = Client::where('user_id', $contexto->owner_id)
                ->where('id', (int) $valor)
                ->first();

            if (is_null($cliente)) {

                return RespuestaDeCargaIa::error('Ese cliente no existe entre los tuyos. Decime su nombre.');
            }

            return $cliente;
        }

        $nombre = trim((string) $valor);

        $candidatos = Client::where('user_id', $contexto->owner_id)
            ->where('name', 'LIKE', '%' . addcslashes($nombre, '%_\\') . '%')
            ->orderBy('name')
            ->limit(self::TOPE_CANDIDATOS)
            ->get(['id', 'name', 'price_type_id', 'seller_id']);

        if (count($candidatos) === 0) {

            return RespuestaDeCargaIa::error(
                'No encontré ningún cliente que se llame así. No puedo crear clientes: cargalo desde Clientes y volvé a pedírmelo, o hacé la venta sin cliente.'
            );
        }

        if (count($candidatos) === 1) {

            return $candidatos[0];
        }

        /* Un nombre escrito completo gana sobre los parciales: "Juan" no es "Juan Pérez" si existe "Juan". */
        foreach ($candidatos as $candidato) {

            if (ConsultasSistemaIaHelper::normalize_text((string) $candidato->name) === ConsultasSistemaIaHelper::normalize_text($nombre)) {

                return $candidato;
            }
        }

        $opciones = [];

        foreach ($candidatos as $candidato) {
            $opciones[] = ['cliente_id' => (int) $candidato->id, 'nombre' => (string) $candidato->name];
        }

        return RespuestaDeCargaIa::faltan(['cuál de estos clientes es'], ['clientes' => $opciones]);
    }

    /**
     * Cómo se cobra: 'contado' o 'cuenta_corriente'.
     *
     * Sin cliente solo hay contado (no hay cuenta corriente a la que mandarla). Con cliente y sin
     * decir cómo, se pregunta —en un solo mensaje, con los métodos y las cajas a mano—: es lo que
     * el vendedor decide con el toggle "Omitir en cuenta corriente" de la pantalla, y adivinarlo
     * es meterle plata a una cuenta corriente o dejar de meterla. Cuenta corriente solo si el
     * cliente tiene `credit_account` en pesos: sin ella, `CurrentAcountFromSaleHelper` revienta
     * DESPUÉS de crear la venta y descontar el stock.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\Client|null  $cliente
     * @param  string  $texto
     * @return string|array
     */
    protected static function resolver_cobro(ContextoDeCargaIa $contexto, $cliente, $texto)
    {
        $normalizado = strtr(ConsultasSistemaIaHelper::normalize_text($texto), [' ' => '_', '-' => '_']);

        if ($normalizado === 'cta_cte' || $normalizado === 'cuenta') {
            $normalizado = self::COBRO_CUENTA_CORRIENTE;
        }

        if ($normalizado === 'efectivo' || $normalizado === 'ahora') {
            $normalizado = self::COBRO_CONTADO;
        }

        if ($normalizado !== '' && !in_array($normalizado, [self::COBRO_CONTADO, self::COBRO_CUENTA_CORRIENTE], true)) {

            return RespuestaDeCargaIa::error('El cobro tiene que ser contado o cuenta_corriente.');
        }

        if (is_null($cliente)) {

            if ($normalizado === self::COBRO_CUENTA_CORRIENTE) {

                return RespuestaDeCargaIa::error('Sin cliente la venta es de contado: si va a cuenta corriente, decime de qué cliente es.');
            }

            return self::COBRO_CONTADO;
        }

        if ($normalizado === '') {

            return RespuestaDeCargaIa::faltan(
                ['si la venta se cobra ahora (contado: con qué método de pago y a qué caja) o va a la cuenta corriente de ' . $cliente->name],
                [
                    'cobro'           => [self::COBRO_CONTADO, self::COBRO_CUENTA_CORRIENTE],
                    'metodos_de_pago' => self::opciones_de_metodos(),
                    'cajas'           => self::opciones_de_cajas($contexto),
                ]
            );
        }

        if ($normalizado === self::COBRO_CUENTA_CORRIENTE) {

            $tiene_cuenta = CreditAccount::where('model_name', 'client')
                ->where('model_id', (int) $cliente->id)
                ->where('moneda_id', 1)
                ->exists();

            if (!$tiene_cuenta) {

                return RespuestaDeCargaIa::error(
                    $cliente->name . ' no tiene cuenta corriente en pesos: cobrá la venta de contado, o abrile la cuenta desde Clientes.'
                );
            }
        }

        return $normalizado;
    }

    /**
     * El método de pago de una venta de contado, por nombre, entre los del catálogo de la pantalla
     * (los mismos que ofrece OpcionesDeCargaIaHelper, con las mismas exclusiones: ni cheque, ni
     * tarjeta de crédito, ni retención). Sin nombre se anota como faltante. Devuelve null solo si
     * el catálogo está vacío (ahí la pantalla tampoco exige método).
     *
     * @param  string  $nombre
     * @param  array  $faltan  Se llena por referencia.
     * @param  array  $opciones  Se llena por referencia.
     * @return \App\Models\CurrentAcountPaymentMethod|null|array
     */
    protected static function resolver_metodo_de_pago($nombre, array &$faltan, array &$opciones)
    {
        $metodos = OpcionesDeCargaIaHelper::metodos_de_pago();

        if (!count($metodos)) {

            return null;
        }

        $nombre = trim((string) $nombre);

        if ($nombre === '') {

            $faltan[] = 'con qué método de pago se cobra';
            $opciones['metodos_de_pago'] = self::opciones_de_metodos($metodos);

            return null;
        }

        $buscado = ConsultasSistemaIaHelper::normalize_text($nombre);

        $exacto = null;
        $parciales = [];

        foreach ($metodos as $metodo) {

            $candidato = ConsultasSistemaIaHelper::normalize_text((string) $metodo->name);

            if ($candidato === $buscado) {
                $exacto = $metodo;
                break;
            }

            if (mb_strpos($candidato, $buscado) !== false) {
                $parciales[] = $metodo;
            }
        }

        $elegido = !is_null($exacto) ? $exacto : (count($parciales) === 1 ? $parciales[0] : null);

        if (is_null($elegido)) {

            if (count($parciales) > 1) {

                return RespuestaDeCargaIa::faltan(['cuál de estos métodos de pago es'], ['metodos_de_pago' => self::opciones_de_metodos(collect($parciales))]);
            }

            return RespuestaDeCargaIa::error('No hay un método de pago que se llame "' . $nombre . '".', ['metodos_de_pago' => self::opciones_de_metodos($metodos)]);
        }

        $motivo = OpcionesDeCargaIaHelper::motivo_no_usable($elegido);

        if (!is_null($motivo)) {

            return RespuestaDeCargaIa::error($motivo, ['metodos_de_pago' => self::opciones_de_metodos($metodos)]);
        }

        return $elegido;
    }

    /**
     * La caja a la que entra el cobro. Espejo de `chequeos/cajas.js` y de PagosIaHelper: si la
     * cuenta tiene cajas, hace falta una —la nombrada (entre las ofrecibles), la de por defecto de
     * ese método, o se pregunta—; si no tiene ninguna, la venta va sin caja y nombrar una es un
     * error. Devuelve null cuando no hay caja que poner.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\CurrentAcountPaymentMethod|null  $metodo
     * @param  string  $nombre
     * @param  array  $faltan  Se llena por referencia.
     * @param  array  $opciones  Se llena por referencia.
     * @return \App\Models\Caja|null|array
     */
    protected static function resolver_caja(ContextoDeCargaIa $contexto, $metodo, $nombre, array &$faltan, array &$opciones)
    {
        $nombre = trim((string) $nombre);

        if (!OpcionesDeCargaIaHelper::hay_cajas($contexto->owner_id)) {

            if ($nombre !== '') {

                return RespuestaDeCargaIa::error('Esta cuenta no tiene ninguna caja creada: no me mandes caja.');
            }

            return null;
        }

        $cajas = OpcionesDeCargaIaHelper::cajas_de_la_cuenta($contexto->owner_id);
        $ofrecibles = OpcionesDeCargaIaHelper::cajas_ofrecibles($contexto, 1, $cajas);

        if (!count($ofrecibles)) {

            return RespuestaDeCargaIa::error('No tenés ninguna caja abierta disponible, abrila en Tesorería.');
        }

        $calles = OpcionesDeCargaIaHelper::calles_de_sucursales($contexto->owner_id);

        $opciones_de_cajas = [];

        foreach ($ofrecibles as $ofrecible) {
            $opciones_de_cajas[] = OpcionesDeCargaIaHelper::opcion_de_caja($ofrecible, $calles);
        }

        if ($nombre !== '') {

            $buscado = ConsultasSistemaIaHelper::normalize_text($nombre);

            $exacta = null;
            $parciales = [];

            foreach ($ofrecibles as $ofrecible) {

                $candidata = ConsultasSistemaIaHelper::normalize_text(OpcionesDeCargaIaHelper::nombre_de_caja($ofrecible, $calles));

                if ($candidata === $buscado || ConsultasSistemaIaHelper::normalize_text((string) $ofrecible->name) === $buscado) {
                    $exacta = $ofrecible;
                    break;
                }

                if (mb_strpos($candidata, $buscado) !== false) {
                    $parciales[] = $ofrecible;
                }
            }

            if (!is_null($exacta)) {

                return $exacta;
            }

            if (count($parciales) === 1) {

                return $parciales[0];
            }

            if (count($parciales) > 1) {

                return RespuestaDeCargaIa::faltan(['cuál de estas cajas es'], ['cajas' => $opciones_de_cajas]);
            }

            return RespuestaDeCargaIa::error('La caja "' . $nombre . '" no está entre las que podés usar.', ['cajas' => $opciones_de_cajas]);
        }

        // Sin método (catálogo vacío) no hay caja por defecto que buscar: la pantalla exige la caja igual.
        $por_defecto = is_null($metodo)
            ? null
            : OpcionesDeCargaIaHelper::caja_por_defecto($contexto, (int) $metodo->id, 1, $ofrecibles, $cajas);

        if (!is_null($por_defecto)) {

            return $por_defecto;
        }

        // Con una sola caja ofrecible no hay nada que preguntar: es la que la pantalla dejaría elegir.
        if (count($ofrecibles) === 1) {

            return $ofrecibles[0];
        }

        $faltan[] = 'a qué caja va el cobro';
        $opciones['cajas'] = $opciones_de_cajas;

        return null;
    }

    /**
     * La lista de precios con la que sale la venta, en el mismo orden que
     * `price_types.js::resolver_lista_por_defecto()` de la SPA: la pedida por nombre, la del
     * cliente, y si no la lista por defecto del comercio (la de position más alta, mismo criterio
     * que ArticlePricesHelper).
     *
     * 🔴 SOLO EN UNA CUENTA QUE VENDE CON LISTAS. `setPriceType()` de la SPA corta en la primera
     * línea con `!requiere_lista_de_precios()` —el flag `users.listas_de_precio` del dueño, menos la
     * extensión de rangos, y que exista alguna lista— y la venta sale con `price_type_id` null y
     * los renglones al `final_price` del artículo. Acá es el mismo corte, con la misma función del
     * back (`PriceTypeHelper::requiere_lista_de_precios`): en esas cuentas la lista del cliente no
     * se mira, y nombrar una es un error (el selector ni se dibuja).
     *
     * Y si la cuenta exige lista y no se pudo resolver ninguna, se pregunta ACÁ: el controller
     * rechazaría la venta con 422 y el clic fallaría. Con la lista por defecto siempre disponible
     * cuando hay listas, ese caso es una red y no el camino normal.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\Client|null  $cliente
     * @param  string  $nombre
     * @return \App\Models\PriceType|null|array
     */
    public static function resolver_lista_de_precios(ContextoDeCargaIa $contexto, $cliente, $nombre)
    {
        $nombre = trim((string) $nombre);

        if (!PriceTypeHelper::requiere_lista_de_precios($contexto->owner)) {

            if ($nombre !== '') {

                return RespuestaDeCargaIa::error('Esta cuenta no vende con listas de precios: no me mandes lista.');
            }

            return null;
        }

        $listas = PriceType::where('user_id', $contexto->owner_id)->orderBy('name')->get();

        if ($nombre !== '') {

            if (!count($listas)) {

                return RespuestaDeCargaIa::error('Esta cuenta no tiene listas de precios: no me mandes lista.');
            }

            $buscado = ConsultasSistemaIaHelper::normalize_text($nombre);

            $exacta = null;
            $parciales = [];

            foreach ($listas as $lista) {

                $candidata = ConsultasSistemaIaHelper::normalize_text((string) $lista->name);

                if ($candidata === $buscado) {
                    $exacta = $lista;
                    break;
                }

                if (mb_strpos($candidata, $buscado) !== false) {
                    $parciales[] = $lista;
                }
            }

            if (!is_null($exacta)) {

                return $exacta;
            }

            if (count($parciales) === 1) {

                return $parciales[0];
            }

            if (count($parciales) > 1) {

                return RespuestaDeCargaIa::faltan(['cuál de estas listas de precios es'], ['listas_de_precios' => self::opciones_de_listas($parciales)]);
            }

            return RespuestaDeCargaIa::error('No hay una lista de precios que se llame "' . $nombre . '".', ['listas_de_precios' => self::opciones_de_listas($listas->all())]);
        }

        if (!count($listas)) {

            return null;
        }

        $del_cliente = is_null($cliente) ? null : PriceTypeHelper::normalizar_price_type_id($cliente->price_type_id);

        if (!is_null($del_cliente)) {

            foreach ($listas as $lista) {

                if ((int) $lista->id === $del_cliente) {

                    return $lista;
                }
            }
        }

        /* La de position más alta, y a igual position la de id más alto (el mismo desempate que el resolvedor de precios). */
        $elegida = null;

        foreach ($listas as $lista) {

            $position = is_null($lista->position) ? 0 : (int) $lista->position;

            if (is_null($elegida)) {
                $elegida = $lista;
                continue;
            }

            $position_elegida = is_null($elegida->position) ? 0 : (int) $elegida->position;

            if ($position > $position_elegida || ($position === $position_elegida && (int) $lista->id > (int) $elegida->id)) {
                $elegida = $lista;
            }
        }

        if (is_null($elegida) && PriceTypeHelper::requiere_lista_de_precios($contexto->owner)) {

            return RespuestaDeCargaIa::faltan(['con qué lista de precios sale la venta'], ['listas_de_precios' => self::opciones_de_listas($listas->all())]);
        }

        return $elegida;
    }

    /**
     * Los renglones de la venta: cada `items[]` resuelto a un artículo del dueño, con su cantidad y
     * su precio unitario.
     *
     * El precio: el que dictó la persona (`precio_unitario`, que es el "precio personalizado" del
     * remito: no se le aplica el descuento del método de pago, igual que en `getPriceVender()`), o
     * el que devuelve la cascada única de `ArticlePricesHelper::resolver_precio_de_venta` con la
     * lista de la venta, menos el descuento (o más el recargo) del método de pago elegido, que la
     * pantalla aplica renglón por renglón (`aplicar_descuento_metodo_de_pago`). Un artículo sin
     * precio cargado corta: cobrarlo a $ 0 no es una venta.
     *
     * Un mismo artículo repetido en dos ítems se junta en un renglón sumando cantidades, que es lo
     * que hace el remito con los repetidos.
     *
     * Un artículo AMBIGUO (dos o más matchean) no corta: se anota en `$faltan` con sus opciones y
     * se sigue con los demás, para que la pregunta salga junto con las otras. Lo que sí corta es un
     * artículo que no existe o una cantidad imposible.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed  $items
     * @param  \App\Models\PriceType|null  $lista
     * @param  float|null  $porcentaje_del_metodo  Positivo descuenta, negativo recarga, null nada.
     * @param  array  $faltan  Se llena por referencia.
     * @param  array  $opciones  Se llena por referencia.
     * @return array<int, array<string, mixed>>|array  Renglones (vacío si alguno quedó pendiente), o la respuesta negativa.
     */
    public static function resolver_renglones(ContextoDeCargaIa $contexto, $items, $lista, $porcentaje_del_metodo, array &$faltan, array &$opciones)
    {
        if (!is_array($items) || !count($items)) {

            return RespuestaDeCargaIa::faltan(['qué artículos lleva la venta y cuántos de cada uno']);
        }

        if (count($items) > self::MAX_ITEMS) {

            return RespuestaDeCargaIa::error('Una venta desde acá lleva hasta ' . self::MAX_ITEMS . ' artículos distintos. Hacela en dos partes o desde Vender.');
        }

        $renglones = [];

        foreach (array_values($items) as $indice => $item) {

            $item = is_array($item) ? $item : [];

            $cantidad = EntradaDeCargaIa::valor($item, 'cantidad');

            if (EntradaDeCargaIa::vacio($cantidad)) {
                $cantidad = 1;
            }

            if (!is_numeric($cantidad) || (float) $cantidad <= 0) {

                return RespuestaDeCargaIa::error('La cantidad del artículo ' . ($indice + 1) . ' tiene que ser un número mayor a 0.');
            }

            $cantidad = round((float) $cantidad, 2);

            $article = self::resolver_articulo($contexto, $item, $lista);

            if (RespuestaDeCargaIa::es_negativa($article)) {

                if (!count($article['faltan'])) {

                    return $article;
                }

                self::juntar_faltantes($faltan, $opciones, $article);

                continue;
            }

            /*
             * 🔴 UN ARTICULO CON VARIANTES NO SE VENDE DESDE ACA, Y NO ES UNA LIMITACION MENOR.
             *
             * Con la extension `article_variants` prendida, Vender NO deja vender el padre: devuelve
             * una fila por variante y la venta va con `article_variant_id`. Este helper no conoce
             * las variantes, asi que su payload lo manda en null: `SaleHelper::attachArticle()` lo
             * guarda sin variante y el movimiento de stock se escribe contra el padre.
             *
             * Lo que pasaria en una tienda de ropa que pide "vende 2 remeras Nike": la venta se
             * registra, se cobra, entra a la caja --y el stock del talle queda igual--. Y encima el
             * descuento que si se le hizo al padre se pisa despues, porque
             * UpdateVariantsStockHelper recalcula el stock del padre sumando sus variantes. O sea:
             * una venta real con el stock intacto, sin error en ningun lado.
             *
             * Por eso se corta ACA, antes de proponer, y se dice adonde ir. Vender el talle correcto
             * desde el chat es una mision aparte: hay que preguntar cual, y el modelo no tiene hoy
             * como saber que variantes existen.
             */
            if (UserHelper::hasExtencion('article_variants', $contexto->owner) && $article->article_variants()->exists()) {

                return RespuestaDeCargaIa::error(
                    $article->name . ' se vende por variante (talle, color), y desde el chat no puedo elegir cuál. '
                        . 'Esa venta se hace desde Vender, que te muestra una fila por variante.'
                );
            }

            $dictado = EntradaDeCargaIa::valor($item, 'precio_unitario');

            $precio_dictado = null;

            if (!EntradaDeCargaIa::vacio($dictado)) {

                if (!is_numeric($dictado) || (float) $dictado <= 0) {

                    return RespuestaDeCargaIa::error('El precio unitario de ' . $article->name . ' tiene que ser un número mayor a 0.');
                }

                $precio_dictado = round((float) $dictado, 2);
            }

            if (!is_null($precio_dictado)) {

                $precio = $precio_dictado;
                $origen = 'dictado';

            } else {

                $resuelto = ArticlePricesHelper::resolver_precio_de_venta($article, $contexto->owner, is_null($lista) ? null : (int) $lista->id);

                if (is_null($resuelto['final_price']) || (float) $resuelto['final_price'] <= 0) {

                    return RespuestaDeCargaIa::error(
                        $article->name . ' no tiene precio de venta cargado. Decime a cuánto lo vendés (precio_unitario) o cargale el precio desde el listado de artículos.'
                    );
                }

                $precio = (float) $resuelto['final_price'];
                $origen = (string) $resuelto['origen'];

                if (!is_null($porcentaje_del_metodo) && (float) $porcentaje_del_metodo != 0) {
                    $precio = $precio - $precio * (float) $porcentaje_del_metodo / 100;
                }

                $precio = round($precio, 2);
            }

            $id = (int) $article->id;

            if (isset($renglones[$id])) {

                $renglones[$id]['cantidad'] = round($renglones[$id]['cantidad'] + $cantidad, 2);

                if (!is_null($precio_dictado)) {
                    $renglones[$id]['precio'] = $precio;
                    $renglones[$id]['precio_dictado'] = $precio_dictado;
                    $renglones[$id]['origen'] = $origen;
                }

                continue;
            }

            $renglones[$id] = [
                'article'        => $article,
                'cantidad'       => $cantidad,
                'precio'         => $precio,
                'precio_dictado' => $precio_dictado,
                'origen'         => $origen,
            ];
        }

        return array_values($renglones);
    }

    /**
     * Un artículo del dueño a partir de un ítem: por `articulo_id` o por `articulo` (texto).
     *
     * El texto se busca como lo busca la pantalla y como lo hace
     * ConsultasSistemaIaHelper::resolver_articulo: exacto por nombre contra la base primero (sin
     * mirar mayúsculas ni acentos), después exacto por código de barras o de proveedor, y recién
     * después por contenido en los tres. La diferencia con aquél es que esto elige lo que se le va
     * a COBRAR a alguien: si el contenido matchea más de un artículo, no se toma "el primero por
     * nombre", se pregunta cuál (contrato §5: una ambigüedad corta, nunca se adivina).
     *
     * Solo entre los ACTIVOS: un artículo pausado no aparece en el buscador de Vender.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $item
     * @param  \App\Models\PriceType|null  $lista  Para el precio de cada opción cuando hay que preguntar.
     * @return \App\Models\Article|array
     */
    protected static function resolver_articulo(ContextoDeCargaIa $contexto, array $item, $lista)
    {
        $columnas = ['id', 'user_id', 'name', 'bar_code', 'provider_code', 'stock', 'price', 'final_price', 'cost', 'costo_real', 'cost_in_dollars', 'presentacion', 'unidades_individuales', 'iva_id', 'status'];

        $base = Article::query()
            ->where('user_id', $contexto->owner_id)
            ->where('status', 'active')
            ->with(['price_types', 'iva']);

        $articulo_id = EntradaDeCargaIa::valor($item, 'articulo_id');

        if (!EntradaDeCargaIa::vacio($articulo_id) && is_numeric($articulo_id) && (int) $articulo_id > 0) {

            $article = (clone $base)->where('id', (int) $articulo_id)->first($columnas);

            if (is_null($article)) {

                return RespuestaDeCargaIa::error('Uno de los artículos no existe entre tus artículos activos. Buscalo por nombre antes de armar la venta.');
            }

            return $article;
        }

        $texto = EntradaDeCargaIa::texto($item, 'articulo');

        if ($texto === '') {

            return RespuestaDeCargaIa::faltan(['qué artículo es el ítem ' . (isset($item['cantidad']) ? 'de ' . $item['cantidad'] . ' unidades' : 'sin nombre')]);
        }

        $normalizado = ConsultasSistemaIaHelper::normalize_text($texto);

        $escapado = addcslashes($texto, '%_\\');

        $candidatos = (clone $base)
            ->where(function ($sub) use ($escapado) {
                $sub->where('name', 'LIKE', '%' . $escapado . '%')
                    ->orWhere('bar_code', 'LIKE', '%' . $escapado . '%')
                    ->orWhere('provider_code', 'LIKE', '%' . $escapado . '%');
            })
            ->orderBy('name')
            ->limit(self::TOPE_CANDIDATOS + 1)
            ->get($columnas);

        if (count($candidatos) === 0) {

            return RespuestaDeCargaIa::error(
                'No encontré ningún artículo activo que se llame "' . $texto . '" ni con ese código. Buscalo con otra palabra o cargalo desde el listado de artículos.'
            );
        }

        if (count($candidatos) === 1) {

            return $candidatos[0];
        }

        /* El nombre o el código escrito completo gana sobre los parciales. */
        foreach ($candidatos as $candidato) {

            if (ConsultasSistemaIaHelper::normalize_text((string) $candidato->name) === $normalizado
                || (trim((string) $candidato->bar_code) !== '' && ConsultasSistemaIaHelper::normalize_text((string) $candidato->bar_code) === $normalizado)
                || (trim((string) $candidato->provider_code) !== '' && ConsultasSistemaIaHelper::normalize_text((string) $candidato->provider_code) === $normalizado)) {

                return $candidato;
            }
        }

        $opciones = [];

        foreach ($candidatos->take(self::TOPE_CANDIDATOS) as $candidato) {

            $resuelto = ArticlePricesHelper::resolver_precio_de_venta($candidato, $contexto->owner, is_null($lista) ? null : (int) $lista->id);

            $opciones[] = [
                'articulo_id' => (int) $candidato->id,
                'nombre'      => (string) $candidato->name,
                'precio'      => is_null($resuelto['final_price']) ? null : round((float) $resuelto['final_price'], 2),
            ];
        }

        return RespuestaDeCargaIa::faltan(['cuál de estos artículos es "' . $texto . '"'], ['articulos' => $opciones]);
    }

    /**
     * El descuento de venta, o null si no se pidió.
     *
     * 🔴 ES UN DESCUENTO DEL CATÁLOGO, NO UN PORCENTAJE SUELTO. En la pantalla el descuento de una
     * venta se elige entre los `discounts` del comercio (los comunes y los del cliente), en la etapa
     * 3 de Vender, y se adjunta a la venta por su id (`SaleHelper::attachDiscounts`). No hay forma
     * de tipear "10 %" en el remito; lo más parecido es el total forzado, que es otra extensión y
     * otra semántica. Así que el porcentaje que dicta la persona se busca entre los descuentos que
     * el comercio tiene cargados: si no hay uno con ese porcentaje, se le dice cuáles hay.
     *
     * Y pide el mismo permiso que el panel: `sale.discount_surchage.aplicar`.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\Client|null  $cliente
     * @param  mixed  $valor
     * @return \App\Models\Discount|null|array
     */
    public static function resolver_descuento(ContextoDeCargaIa $contexto, $cliente, $valor)
    {
        if (EntradaDeCargaIa::vacio($valor)) {

            return null;
        }

        if (!is_numeric($valor) || (float) $valor < 0 || (float) $valor > 100) {

            return RespuestaDeCargaIa::error('El descuento tiene que ser un porcentaje entre 0 y 100.');
        }

        $porcentaje = round((float) $valor, 2);

        if ($porcentaje == 0) {

            return null;
        }

        if (!PermisosIaHelper::puede($contexto->persona, self::PERMISO_DESCUENTOS)) {

            return RespuestaDeCargaIa::error('No tenés permiso para aplicar descuentos de venta desde tu usuario.');
        }

        $descuentos = Discount::where('user_id', $contexto->owner_id)
            ->where(function ($q) use ($cliente) {
                $q->whereNull('client_id');

                if (!is_null($cliente)) {
                    $q->orWhere('client_id', (int) $cliente->id);
                }
            })
            ->orderBy('name')
            ->get();

        $elegido = null;

        foreach ($descuentos as $descuento) {

            if (abs((float) $descuento->percentage - $porcentaje) < 0.005) {

                /* Con dos del mismo porcentaje gana el del cliente (es el que la pantalla lista primero). */
                if (is_null($elegido) || (!is_null($cliente) && (int) $descuento->client_id === (int) $cliente->id)) {
                    $elegido = $descuento;
                }
            }
        }

        if (!is_null($elegido)) {

            return $elegido;
        }

        $opciones = [];

        foreach ($descuentos as $descuento) {
            $opciones[] = ['nombre' => (string) $descuento->name, 'porcentaje' => (float) $descuento->percentage];
        }

        $lista = count($opciones) ? ' Los que tenés cargados son: ' . self::listar_descuentos($descuentos) . '.' : ' No tenés ningún descuento de venta cargado.';

        return RespuestaDeCargaIa::error(
            'No tenés un descuento de venta del ' . self::porcentaje($porcentaje) . ' %.' . $lista
                . ' Pedime la venta con uno de esos, o cargalo desde Vender > Descuentos y volvé a pedírmela.',
            ['descuentos_de_venta' => $opciones]
        );
    }

    /**
     * La sucursal de la venta, o null si la cuenta no tiene sucursales.
     *
     * Espejo de `chequeos/sucursal.js`: con sucursales cargadas la venta no se guarda sin una
     * ("Indique la SUCURSAL de la venta para restar el stock en los depósitos que correspondan").
     * La pantalla la inicializa con la de la persona (`start_methods.js`), así que ese es el
     * default; con una sola sucursal es esa; con varias y sin default, se pregunta.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $nombre
     * @param  array  $faltan  Se llena por referencia.
     * @param  array  $opciones  Se llena por referencia.
     * @return \App\Models\Address|null|array
     */
    public static function resolver_sucursal(ContextoDeCargaIa $contexto, $nombre, array &$faltan, array &$opciones)
    {
        $sucursales = Address::where('user_id', $contexto->owner_id)->orderBy('id')->get();

        $nombre = trim((string) $nombre);

        if (!count($sucursales)) {

            if ($nombre !== '') {

                return RespuestaDeCargaIa::error('Esta cuenta no tiene sucursales cargadas: no me mandes sucursal.');
            }

            return null;
        }

        $opciones_de_sucursales = [];

        foreach ($sucursales as $sucursal) {
            $opciones_de_sucursales[] = ['sucursal' => (string) $sucursal->street];
        }

        if ($nombre !== '') {

            $buscado = ConsultasSistemaIaHelper::normalize_text($nombre);

            $parciales = [];

            foreach ($sucursales as $sucursal) {

                $candidata = ConsultasSistemaIaHelper::normalize_text((string) $sucursal->street);

                if ($candidata === $buscado) {

                    return $sucursal;
                }

                if (mb_strpos($candidata, $buscado) !== false) {
                    $parciales[] = $sucursal;
                }
            }

            if (count($parciales) === 1) {

                return $parciales[0];
            }

            if (count($parciales) > 1) {

                return RespuestaDeCargaIa::faltan(['cuál de estas sucursales es'], ['sucursales' => $opciones_de_sucursales]);
            }

            return RespuestaDeCargaIa::error('No hay una sucursal que se llame "' . $nombre . '".', ['sucursales' => $opciones_de_sucursales]);
        }

        $de_la_persona = OpcionesDeCargaIaHelper::address_id_de_la_persona($contexto->persona);

        if (!is_null($de_la_persona)) {

            foreach ($sucursales as $sucursal) {

                if ((int) $sucursal->id === $de_la_persona) {

                    return $sucursal;
                }
            }
        }

        if (count($sucursales) === 1) {

            return $sucursales[0];
        }

        $faltan[] = 'de qué sucursal es la venta';
        $opciones['sucursales'] = $opciones_de_sucursales;

        return null;
    }

    /**
     * El tipo de venta, o null si la cuenta no usa tipos de venta (lo normal).
     *
     * Espejo de `chequeos/sale_type.js`: con tipos cargados la pantalla no guarda sin elegir uno.
     * Con uno solo es ese; con varios y sin decir cuál, se pregunta. `tipo_de_venta` no está en el
     * input_schema del contrato §4 (lo agrega B si hace falta): mientras tanto, una cuenta con
     * varios tipos recibe el `faltan` con los nombres.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $nombre
     * @param  array  $faltan  Se llena por referencia.
     * @param  array  $opciones  Se llena por referencia.
     * @return \App\Models\SaleType|null|array
     */
    protected static function resolver_tipo_de_venta(ContextoDeCargaIa $contexto, $nombre, array &$faltan, array &$opciones)
    {
        $tipos = SaleType::where('user_id', $contexto->owner_id)->orderBy('id')->get();

        if (!count($tipos)) {

            return null;
        }

        $nombre = trim((string) $nombre);

        $opciones_de_tipos = [];

        foreach ($tipos as $tipo) {
            $opciones_de_tipos[] = ['tipo_de_venta' => (string) $tipo->name];
        }

        if ($nombre !== '') {

            $buscado = ConsultasSistemaIaHelper::normalize_text($nombre);

            foreach ($tipos as $tipo) {

                if (ConsultasSistemaIaHelper::normalize_text((string) $tipo->name) === $buscado) {

                    return $tipo;
                }
            }

            foreach ($tipos as $tipo) {

                if (mb_strpos(ConsultasSistemaIaHelper::normalize_text((string) $tipo->name), $buscado) !== false) {

                    return $tipo;
                }
            }

            return RespuestaDeCargaIa::error('No hay un tipo de venta que se llame "' . $nombre . '".', ['tipos_de_venta' => $opciones_de_tipos]);
        }

        if (count($tipos) === 1) {

            return $tipos[0];
        }

        $faltan[] = 'qué tipo de venta es';
        $opciones['tipos_de_venta'] = $opciones_de_tipos;

        return null;
    }

    /**
     * La fecha de entrega, o null si no vino. Solo tiene sentido con la extensión que la habilita
     * en Vender: sin ella, `SaleHelper::get_terminada` la ignora y la venta nacería terminada con
     * una fecha que ninguna pantalla muestra.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed  $valor
     * @return \Carbon\Carbon|null|array
     */
    protected static function resolver_fecha_entrega(ContextoDeCargaIa $contexto, $valor)
    {
        if (EntradaDeCargaIa::vacio($valor)) {

            return null;
        }

        if (!UserHelper::hasExtencion(self::EXTENSION_FECHA_ENTREGA, $contexto->owner)) {

            return RespuestaDeCargaIa::error('Esta cuenta no trabaja con fecha de entrega en las ventas: hacé la venta sin fecha.');
        }

        return EntradaDeCargaIa::fecha($contexto, $valor);
    }

    /**
     * Sub total y total como los calcula `mixins/vender_set_total.js`: el sub total es la suma de
     * los renglones (precio unitario ya con el descuento del método de pago, × cantidad), y el total
     * es esa suma menos el descuento de venta (`aplicar_discounts()` se lo resta a `total_articles`
     * y acá no hay servicios ni combos). Sin descuento son iguales.
     *
     * @param  array  $renglones
     * @param  \App\Models\Discount|null  $descuento
     * @return array{sub_total: float, total: float, descuento_monto: float}
     */
    public static function totales(array $renglones, $descuento): array
    {
        $sub_total = 0.0;

        foreach ($renglones as $renglon) {
            $sub_total += (float) $renglon['precio'] * (float) $renglon['cantidad'];
        }

        $sub_total = round($sub_total, 2);

        $descuento_monto = 0.0;

        if (!is_null($descuento)) {
            $descuento_monto = round($sub_total * (float) $descuento->percentage / 100, 2);
        }

        return [
            'sub_total'       => $sub_total,
            'total'           => round($sub_total - $descuento_monto, 2),
            'descuento_monto' => $descuento_monto,
        ];
    }

    /**
     * Qué pasa con el stock: el aviso de la tarjeta (los artículos que quedan en negativo), o la
     * respuesta negativa si la cuenta no deja vender sin stock. Devuelve null si no hay nada que
     * decir.
     *
     * Se mira el stock total del artículo (`articles.stock`), no el del depósito: es el mismo
     * número que muestra el buscador de Vender en la columna Stock. Los artículos sin stock
     * (`stock` null) no lo manejan y no entran.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $renglones
     * @return string|null|array
     */
    protected static function revisar_stock(ContextoDeCargaIa $contexto, array $renglones)
    {
        $en_negativo = [];

        foreach ($renglones as $renglon) {

            $article = $renglon['article'];

            if (is_null($article->stock)) {
                continue;
            }

            $queda = round((float) $article->stock - (float) $renglon['cantidad'], 2);

            if ($queda < 0) {
                $en_negativo[] = $article->name . ' (hay ' . self::numero((float) $article->stock) . ', quedaría en ' . self::numero($queda) . ')';
            }
        }

        if (!count($en_negativo)) {

            return null;
        }

        if (UserHelper::hasExtencion(self::EXTENSION_CHECK_STOCK, $contexto->owner)) {

            return RespuestaDeCargaIa::error('Esta cuenta no vende sin stock y no alcanza para: ' . implode('; ', $en_negativo) . '.');
        }

        return 'Descuenta stock y queda en negativo: ' . implode('; ', $en_negativo) . '.';
    }

    // =====================================================================================
    // Payload, tarjeta y resumen
    // =====================================================================================

    /**
     * El POST de `api/sale` clave por clave como lo arma la acción `vender` de
     * `src/store/vender/vender.js`, con los defaults del `state` de ese store para todo lo que la
     * persona no dijo. Cada clave está en el mismo orden que allá, para poder cotejarlas de a
     * pares.
     *
     * Lo que se decide acá y no es un default de la pantalla:
     *   - `items`: un renglón por artículo con las claves que leen `SaleHelper::attachArticles` /
     *     `attachArticle` / `getCost` / `get_iva_percentage_for_pivot` (`id`, `amount`,
     *     `price_vender`, `cost`, `costo_real`, `cost_in_dollars`, `presentacion`,
     *     `unidades_individuales`, `iva_id`, `iva`), sacadas del mismo modelo que la SPA manda
     *     entero. `price_vender_personalizado` va cuando la persona dictó el precio, como cuando el
     *     vendedor lo edita en el remito.
     *   - `omitir_en_cuenta_corriente`: 1 en una venta de contado CON cliente (es el toggle de la
     *     pantalla), 0 en las otras dos. `save_current_acount` queda en 1 como en el store: con
     *     cliente y sin omitir, la venta va a la cuenta corriente; sin cliente no hay cuenta.
     *   - El cobro va por el camino del método ÚNICO del select (`current_acount_payment_method_id`
     *     + `caja_id`, `selected_payment_methods` vacío), no por el reparto del modal: es el que
     *     usa el vendedor todos los días y el que hace que `attachSelectedPaymentMethods` resuelva
     *     el porcentaje del método por su cuenta.
     *   - `price_description` va con una descripción mínima del cálculo, en el mismo formato de
     *     renglones de `total_description`.
     *
     * @param  array  $renglones
     * @param  \App\Models\Client|null  $cliente
     * @param  string  $cobro
     * @param  \App\Models\CurrentAcountPaymentMethod|null  $metodo
     * @param  \App\Models\Caja|null  $caja
     * @param  \App\Models\PriceType|null  $lista
     * @param  \App\Models\Discount|null  $descuento
     * @param  \App\Models\Address|null  $sucursal
     * @param  \App\Models\SaleType|null  $tipo_de_venta
     * @param  \Carbon\Carbon|null  $fecha_entrega
     * @param  string  $observaciones
     * @param  array  $totales
     * @return array<string, mixed>
     */
    public static function payload(array $renglones, $cliente, $cobro, $metodo, $caja, $lista, $descuento, $sucursal, $tipo_de_venta, $fecha_entrega, $observaciones, array $totales): array
    {
        $items = [];
        $descripcion = [];

        foreach ($renglones as $renglon) {

            $article = $renglon['article'];

            $item = [
                'id'                         => (int) $article->id,
                'name'                       => (string) $article->name,
                'bar_code'                   => $article->bar_code,
                'provider_code'              => $article->provider_code,
                'is_article'                 => true,
                'amount'                     => (float) $renglon['cantidad'],
                'price_vender'               => (float) $renglon['precio'],
                'price_vender_personalizado' => is_null($renglon['precio_dictado']) ? null : (float) $renglon['precio_dictado'],
                'cost'                       => $article->cost,
                'costo_real'                 => $article->costo_real,
                'cost_in_dollars'            => $article->cost_in_dollars,
                'presentacion'               => $article->presentacion,
                'unidades_individuales'      => $article->unidades_individuales,
                'iva_id'                     => $article->iva_id,
                'iva'                        => is_null($article->iva) ? null : ['id' => (int) $article->iva->id, 'percentage' => $article->iva->percentage],
                'stock'                      => $article->stock,
            ];

            $items[] = $item;

            $descripcion[] = 'ITEM: ' . $article->name;
            $descripcion[] = ($renglon['origen'] === 'dictado' ? 'Precio personalizado: ' : 'Precio (' . $renglon['origen'] . '): ') . FormatoIaHelper::monto($renglon['precio']);
            $descripcion[] = 'Precio unitario final: ' . FormatoIaHelper::monto($renglon['precio']);
        }

        $descripcion[] = 'SubTotal: ' . FormatoIaHelper::monto($totales['sub_total']) . ' (sumatoria de todos los items con descuentos individuales y descuentos/recargos de metodos de pago aplicados)';

        if (!is_null($descuento)) {
            $descripcion[] = 'APLICANDO DESCUENTOS DE VENTA';
            $descripcion[] = 'Aplicando descuento del ' . self::porcentaje((float) $descuento->percentage) . '%';
            $descripcion[] = 'Descuento para articulos: ' . FormatoIaHelper::monto($totales['descuento_monto']);
        }

        $descripcion[] = 'Total venta: ' . FormatoIaHelper::monto($totales['total']);
        $descripcion[] = 'TOTAL FINAL';
        $descripcion[] = 'Total venta: ' . FormatoIaHelper::monto($totales['total']);
        $descripcion[] = 'Venta armada por el asistente de IA';

        $es_contado = $cobro === self::COBRO_CONTADO;

        return [
            'save_afip_ticket'                           => 0,
            'items'                                      => $items,
            'client_id'                                  => is_null($cliente) ? null : (int) $cliente->id,
            'discounts'                                  => is_null($descuento) ? [] : [['id' => (int) $descuento->id, 'name' => (string) $descuento->name, 'percentage' => (float) $descuento->percentage]],
            'surchages'                                  => [],
            'save_current_acount'                        => 1,
            'make_current_acount_pago'                   => 0,
            'sale_type_id'                               => is_null($tipo_de_venta) ? 0 : (int) $tipo_de_venta->id,
            'discounts_in_services'                      => 0,
            'surchages_in_services'                      => 0,
            'current_acount_payment_method_id'           => ($es_contado && !is_null($metodo)) ? (int) $metodo->id : 0,
            'afip_information_id'                        => 0,
            'employee_id'                                => 0,
            'address_id'                                 => is_null($sucursal) ? 0 : (int) $sucursal->id,
            'to_check'                                   => 0,
            'checked'                                    => 0,
            'confirmed'                                  => 0,
            'observations'                               => $observaciones,
            'omitir_en_cuenta_corriente'                 => ($es_contado && !is_null($cliente)) ? 1 : 0,
            'numero_orden_de_compra'                     => '',
            'selected_payment_methods'                   => [],
            'discount_percentage'                        => null,
            'discount_amount'                            => null,
            'sub_total'                                  => $totales['sub_total'],
            'price_type_id'                              => is_null($lista) ? null : (int) $lista->id,
            'total'                                      => $totales['total'],
            'seller_id'                                  => 0,
            'cuota_id'                                   => 0,
            'cantidad_cuotas'                            => 0,
            'cuota_descuento'                            => null,
            'cuota_recargo'                              => null,
            'monto_credito_real'                         => null,
            'caja_id'                                    => ($es_contado && !is_null($caja)) ? (int) $caja->id : 0,
            'moneda_id'                                  => 1,
            'valor_dolar'                                => null,
            'afip_tipo_comprobante_id'                   => 0,
            'descuento'                                  => null,
            'forzar_total_monto'                         => null,
            'puntos_canjeados'                           => null,
            'descuento_puntos'                           => null,
            'fecha_entrega'                              => is_null($fecha_entrega) ? null : $fecha_entrega->format('Y-m-d'),
            'incoterms'                                  => 0,
            'observations_ocultas'                       => '',
            'aplicar_recargos_directo_a_items'           => 0,
            'sale_status_id'                             => 0,
            'discount_stock'                             => 1,
            'iva_aplicado'                               => 1,
            'price_description'                          => json_encode($descripcion, JSON_UNESCAPED_UNICODE),
            'send_mail'                                  => 0,
            'dias_alerta_venta_no_cobrada_personalizado' => null,
            'log'                                        => [],
        ];
    }

    /**
     * La tarjeta: un renglón por artículo, el cliente, el cobro, el descuento, la lista, la
     * sucursal, la entrega, las observaciones y el total (contrato §1).
     *
     * @return array{titulo: string, renglones: array, aviso: string|null}
     */
    protected static function presentacion(array $renglones, $cliente, $cobro, $metodo, $caja, $lista, $descuento, $sucursal, $fecha_entrega, $observaciones, array $totales, $aviso): array
    {
        $filas = [];

        foreach ($renglones as $renglon) {

            $filas[] = ['etiqueta' => 'Artículo', 'valor' => self::renglon_de_articulo($renglon)];
        }

        $filas[] = ['etiqueta' => 'Cliente', 'valor' => is_null($cliente) ? 'Sin cliente' : (string) $cliente->name];

        $filas[] = ['etiqueta' => 'Cobro', 'valor' => self::texto_de_cobro($cobro, $metodo, $caja)];

        if (!is_null($descuento)) {
            $filas[] = ['etiqueta' => 'Descuento', 'valor' => $descuento->name . ' ' . self::porcentaje((float) $descuento->percentage) . ' % (−' . FormatoIaHelper::monto($totales['descuento_monto']) . ')'];
        }

        if (!is_null($lista)) {
            $filas[] = ['etiqueta' => 'Lista de precios', 'valor' => (string) $lista->name];
        }

        if (!is_null($sucursal)) {
            $filas[] = ['etiqueta' => 'Sucursal', 'valor' => (string) $sucursal->street];
        }

        if (!is_null($fecha_entrega)) {
            $filas[] = ['etiqueta' => 'Entrega', 'valor' => FormatoIaHelper::fecha_con_dia($fecha_entrega)];
        }

        if ($observaciones !== '') {
            $filas[] = ['etiqueta' => 'Observaciones', 'valor' => $observaciones];
        }

        $filas[] = ['etiqueta' => 'Total', 'valor' => FormatoIaHelper::monto($totales['total'])];

        return [
            'titulo'    => 'Venta',
            'renglones' => $filas,
            'aviso'     => is_string($aviso) && $aviso !== '' ? $aviso : null,
        ];
    }

    /**
     * El resumen que queda en `datos` de la tarjeta: lo mismo que la tarjeta muestra, en forma de
     * datos (para el informe, para el historial y para quien lea la fila sin la SPA).
     *
     * @return array<string, mixed>
     */
    protected static function resumen(array $renglones, $cliente, $cobro, $metodo, $caja, $lista, $descuento, $sucursal, $fecha_entrega, array $totales): array
    {
        $articulos = [];

        foreach ($renglones as $renglon) {

            $articulos[] = [
                'articulo_id' => (int) $renglon['article']->id,
                'nombre'      => (string) $renglon['article']->name,
                'cantidad'    => (float) $renglon['cantidad'],
                'precio'      => (float) $renglon['precio'],
                'subtotal'    => round((float) $renglon['precio'] * (float) $renglon['cantidad'], 2),
                'origen'      => (string) $renglon['origen'],
            ];
        }

        return [
            'articulos'       => $articulos,
            'cliente'         => is_null($cliente) ? null : (string) $cliente->name,
            'cobro'           => $cobro,
            'metodo_de_pago'  => is_null($metodo) ? null : (string) $metodo->name,
            'caja'            => is_null($caja) ? null : (string) $caja->name,
            'descuento'       => is_null($descuento) ? null : ['nombre' => (string) $descuento->name, 'porcentaje' => (float) $descuento->percentage, 'monto' => $totales['descuento_monto']],
            'lista_de_precios' => is_null($lista) ? null : (string) $lista->name,
            'sucursal'        => is_null($sucursal) ? null : (string) $sucursal->street,
            'fecha_entrega'   => is_null($fecha_entrega) ? null : $fecha_entrega->format('Y-m-d'),
            'sub_total'       => $totales['sub_total'],
            'total'           => $totales['total'],
        ];
    }

    /**
     * La línea que lee la IA: "Venta · 3 × Martillo acero + 1 × Pinza · Cliente X · Efectivo · Caja Efectivo · Total $ 8.000".
     *
     * @return string
     */
    protected static function resumen_en_una_linea(array $renglones, $cliente, $cobro, $metodo, $caja, array $totales): string
    {
        $partes = [];

        foreach ($renglones as $renglon) {
            $partes[] = self::numero((float) $renglon['cantidad']) . ' × ' . $renglon['article']->name;
        }

        return 'Venta · ' . implode(' + ', $partes)
            . ' · ' . (is_null($cliente) ? 'Sin cliente' : $cliente->name)
            . ' · ' . self::texto_de_cobro($cobro, $metodo, $caja)
            . ' · Total ' . FormatoIaHelper::monto($totales['total']);
    }

    /**
     * "3 × Martillo acero — $ 2.000 = $ 6.000"
     *
     * @param  array  $renglon
     * @return string
     */
    public static function renglon_de_articulo(array $renglon): string
    {
        $subtotal = round((float) $renglon['precio'] * (float) $renglon['cantidad'], 2);

        return self::numero((float) $renglon['cantidad']) . ' × ' . $renglon['article']->name
            . ' — ' . FormatoIaHelper::monto($renglon['precio'])
            . ' = ' . FormatoIaHelper::monto($subtotal);
    }

    /**
     * "Efectivo · Caja Efectivo", "Efectivo (sin caja)" o "A cuenta corriente".
     *
     * @return string
     */
    protected static function texto_de_cobro($cobro, $metodo, $caja): string
    {
        if ($cobro === self::COBRO_CUENTA_CORRIENTE) {

            return 'A cuenta corriente';
        }

        if (is_null($metodo)) {

            return 'Contado';
        }

        if (is_null($caja)) {

            return $metodo->name . ' (sin caja)';
        }

        return $metodo->name . ' · ' . $caja->name;
    }

    // =====================================================================================
    // Opciones y formato
    // =====================================================================================

    /**
     * Suma los faltantes y las opciones de una respuesta `faltan` a los que ya se juntaron, para
     * devolverlos todos en una sola respuesta. Dos preguntas con la misma clave de opciones (dos
     * artículos ambiguos) concatenan sus listas: cada opción trae el nombre, así que la IA sabe
     * cuál va con cuál.
     *
     * @param  array  $faltan  Se llena por referencia.
     * @param  array  $opciones  Se llena por referencia.
     * @param  array  $respuesta  Una respuesta de RespuestaDeCargaIa::faltan().
     * @return void
     */
    protected static function juntar_faltantes(array &$faltan, array &$opciones, array $respuesta)
    {
        foreach ($respuesta['faltan'] as $falta) {
            $faltan[] = $falta;
        }

        $nuevas = is_array($respuesta['opciones']) ? $respuesta['opciones'] : [];

        foreach ($nuevas as $clave => $lista) {

            if (isset($opciones[$clave]) && is_array($opciones[$clave]) && is_array($lista)) {
                $opciones[$clave] = array_merge($opciones[$clave], $lista);
                continue;
            }

            $opciones[$clave] = $lista;
        }
    }

    /**
     * @param  \Illuminate\Support\Collection|null  $metodos
     * @return array
     */
    protected static function opciones_de_metodos($metodos = null): array
    {
        if (is_null($metodos)) {
            $metodos = OpcionesDeCargaIaHelper::metodos_de_pago();
        }

        $usables = [];

        foreach (OpcionesDeCargaIaHelper::opciones_de_metodos($metodos) as $opcion) {

            if (!empty($opcion['se_puede_usar'])) {
                $usables[] = ['nombre' => $opcion['nombre']];
            }
        }

        return $usables;
    }

    /**
     * @param  ContextoDeCargaIa  $contexto
     * @return array
     */
    protected static function opciones_de_cajas(ContextoDeCargaIa $contexto): array
    {
        if (!OpcionesDeCargaIaHelper::hay_cajas($contexto->owner_id)) {

            return [];
        }

        $calles = OpcionesDeCargaIaHelper::calles_de_sucursales($contexto->owner_id);

        $opciones = [];

        foreach (OpcionesDeCargaIaHelper::cajas_ofrecibles($contexto, 1) as $caja) {
            $opciones[] = ['nombre' => OpcionesDeCargaIaHelper::nombre_de_caja($caja, $calles)];
        }

        return $opciones;
    }

    /**
     * @param  array  $listas
     * @return array
     */
    protected static function opciones_de_listas(array $listas): array
    {
        $opciones = [];

        foreach ($listas as $lista) {
            $opciones[] = ['nombre' => (string) $lista->name];
        }

        return $opciones;
    }

    /**
     * "Descuento e2e (15 %), Mayorista (10 %)"
     *
     * @param  iterable  $descuentos
     * @return string
     */
    protected static function listar_descuentos($descuentos): string
    {
        $partes = [];

        foreach ($descuentos as $descuento) {
            $partes[] = $descuento->name . ' (' . self::porcentaje((float) $descuento->percentage) . ' %)';
        }

        return implode(', ', $partes);
    }

    /**
     * "15", "10,5": el porcentaje sin decimales cuando son ,00.
     *
     * @param  float  $porcentaje
     * @return string
     */
    public static function porcentaje($porcentaje): string
    {
        return self::numero((float) $porcentaje);
    }

    /**
     * Un número como lo escribe la tarjeta: sin decimales cuando son ,00, coma decimal si no.
     *
     * @param  float  $numero
     * @return string
     */
    protected static function numero($numero): string
    {
        $numero = round((float) $numero, 2);

        if (abs($numero - round($numero)) < 0.005) {

            return number_format($numero, 0, ',', '.');
        }

        return rtrim(rtrim(number_format($numero, 2, ',', '.'), '0'), ',');
    }
}
