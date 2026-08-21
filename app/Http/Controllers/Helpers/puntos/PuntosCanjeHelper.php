<?php

namespace App\Http\Controllers\Helpers\puntos;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\MovimientoPunto;
use Illuminate\Support\Facades\Log;

/**
 * El canje de puntos: la validación en el servidor y la escritura.
 *
 * Molde: `app/Http/Controllers/Helpers/LimiteCreditoHelper.php`. Los dos validadores devuelven
 * `null` si está todo bien, o el array que es el cuerpo del 422.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 ACÁ ESTÁ LA AUTORIDAD, NO EN EL FRONT.
 *
 *  El canje viaja adentro del POST/PUT de /api/sale en dos campos (`puntos_canjeados`,
 *  `descuento_puntos`) y `sales.total` lo manda el front ya neteado. Un POST directo a
 *  /api/sale llega igual hasta acá sin pasar por VENDER, así que el `descuento_puntos` del
 *  request NO SE GUARDA NUNCA: se recalcula como `puntos_canjeados * valor_punto` con el
 *  `valor_punto` del programa, y si el del request no coincide se rechaza con 422 en vez de
 *  guardar una venta cuyo total no se puede explicar. Mismo criterio que LimiteCreditoHelper
 *  y que SaleController::makeAfipTicket() con el importe personalizado.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 EL CANJE NO TOCA LA CONTABILIDAD, Y ES CORRECTO QUE NO LA TOQUE.
 *
 *  El canje es un DESCUENTO COMERCIAL sobre la venta, no un gasto: baja `sales.total`, y por
 *  ahí entra solo a `ContabilidadRepository::ventas_brutas()`, que es la fuente que ese reporte
 *  ya declara suya (ContabilidadRepository.php:190). Es el mismo tratamiento que ya tienen
 *  `sales.descuento` y `discount_sale.percentage`.
 *
 *  🔴 NO reusar `current_acount_payment_method_sale.discount_amount`: esa columna es el
 *  descuento/recargo por MEDIO DE PAGO, Capa 3 del motor de precios, y
 *  ContabilidadRepository.php:640 la declara fuente prohibida para cualquier otra lectura.
 *  Por eso el canje vive en columnas propias de `sales` que no lee nadie más.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class PuntosCanjeHelper {

    /**
     * Tolerancia de un centavo, el mismo criterio que CurrentAcountPagoHelper:141. El front
     * arma el descuento con floats de JavaScript y el servidor lo recalcula con decimales de
     * MySQL: sin tolerancia se rechazaría un canje correcto por una diferencia invisible.
     */
    const DELTA = 0.01;

    const TIPO_CANJEADOS = 'canjeados';

    /**
     * Validación del canje de una venta NUEVA (`SaleController@store`).
     *
     * Se llama ANTES de DB::beginTransaction(): un rechazo no toca la base y no consume número
     * de venta, igual que LimiteCreditoHelper::validar_venta_nueva().
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|null  null = se puede guardar. Array = cuerpo del 422.
     */
    static function validar_venta_nueva($request) {

        return self::validar(
            $request->puntos_canjeados,
            $request->descuento_puntos,
            $request->client_id,
            $request->total,
            UserHelper::userId()
        );
    }

    /**
     * Validación del canje de una venta que se está EDITANDO (`SaleController@update`).
     *
     * Corre adentro de la transacción que ya viene abierta, así que el llamador tiene que
     * hacer DB::rollBack() explícito antes de responder. Y tiene que correr DESPUÉS de
     * `deshacer()`: el saldo del cliente incluye el movimiento negativo del canje ANTERIOR de
     * esta misma venta, y si no se deshiciera primero, editar una venta sin cambiarle el canje
     * se rechazaría a sí misma por saldo insuficiente. Es el mismo razonamiento por el que
     * LimiteCreditoHelper::validar_venta_actualizada() resta el movimiento actual de la cuenta
     * corriente antes de comparar contra el límite.
     *
     * @param  \App\Models\Sale          $sale     La venta ya persistida y refrescada.
     * @param  \Illuminate\Http\Request  $request
     * @return array|null
     */
    static function validar_venta_actualizada($sale, $request) {

        return self::validar(
            $request->puntos_canjeados,
            $request->descuento_puntos,
            $sale->client_id,
            $sale->total,
            $sale->user_id
        );
    }

    /**
     * El cuerpo de las dos validaciones, en el orden en que se chequea.
     *
     * @param  mixed  $puntos_pedidos
     * @param  mixed  $descuento_del_request
     * @param  mixed  $client_id
     * @param  mixed  $total          El total que va a quedar guardado, o sea YA neteado por el
     *                                canje: el front lo manda con el descuento aplicado.
     * @param  int    $user_id
     * @return array|null
     */
    private static function validar($puntos_pedidos, $descuento_del_request, $client_id, $total, $user_id) {

        /*
         * Salida barata: la abrumadora mayoría de las ventas no canjea nada, y no pueden pagar
         * ni una query por existir este módulo.
         */
        $puntos = self::a_float($puntos_pedidos);

        if ($puntos <= self::DELTA) {
            return null;
        }

        $sistema = PuntosConfigHelper::programa_activo($user_id);

        if (is_null($sistema)) {
            return self::error('El sistema de puntos no está activo.');
        }

        if (is_null($client_id) || !$client_id) {
            return self::error('Para canjear puntos hay que elegir un cliente.');
        }

        $minimo = (float) $sistema->minimo_canje;

        if ($puntos + self::DELTA < $minimo) {
            return self::error('Hacen falta al menos '.self::formato($minimo).' puntos para canjear.');
        }

        $saldo = PuntosSaldoHelper::saldo($user_id, $client_id);

        if ($puntos > $saldo + self::DELTA) {
            return self::error('El cliente tiene '.self::formato($saldo).' puntos disponibles.');
        }

        /*
         * 🔴 El descuento SIEMPRE sale de acá, nunca del request. Si el front mandó otro
         * número, `sales.total` (que también lo manda el front) ya está neteado con ESE número
         * y guardarlo dejaría una venta cuyo total no se puede explicar con sus propias
         * columnas. Por eso no se corrige en silencio: se rechaza.
         */
        $descuento_servidor = self::calcular_descuento($puntos, $sistema);
        $descuento_request  = self::a_float($descuento_del_request);

        if (abs($descuento_servidor - $descuento_request) > self::DELTA) {

            return self::error(
                'El descuento por puntos no coincide con el valor del punto configurado. '.
                'Corresponden $'.self::formato($descuento_servidor).' por '.self::formato($puntos).' puntos.'
            );
        }

        /*
         * El tope se mide contra el total ANTES del canje, que es `total + descuento` porque el
         * front ya mandó el total neteado. Medirlo contra el total neteado haría que el mismo
         * tope del 20% deje pasar un canje más grande cuanto más grande sea el canje.
         */
        $tope_porcentaje = (float) $sistema->tope_porcentaje;
        $total_bruto     = self::a_float($total) + $descuento_servidor;
        $tope_en_pesos   = $total_bruto * $tope_porcentaje / 100;

        if ($descuento_servidor > $tope_en_pesos + self::DELTA) {

            return self::error(
                'Con puntos se puede pagar hasta el '.self::formato($tope_porcentaje).'% de la compra '.
                '($'.self::formato($tope_en_pesos).').'
            );
        }

        return null;
    }

    /**
     * Escribe el canje: las dos columnas de la venta, el movimiento negativo y el consumo FIFO
     * de los lotes.
     *
     * Corre adentro de la transacción de la venta, nunca abriendo una nueva.
     *
     * @param  \App\Models\Sale          $sale     Venta ya persistida.
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    static function aplicar($sale, $request) {

        $puntos = self::a_float($request->puntos_canjeados);

        if ($puntos <= self::DELTA) {
            return;
        }

        $sistema = PuntosConfigHelper::programa_activo($sale->user_id);

        if (is_null($sistema)) {
            return;
        }

        if (is_null($sale->client_id) || !$sale->client_id) {
            return;
        }

        $descuento = self::calcular_descuento($puntos, $sistema);

        $sale->puntos_canjeados = $puntos;
        $sale->descuento_puntos = $descuento;
        $sale->save();

        /*
         * `price_type_id` va en el centinela SIN_LISTA y no en la lista de la venta: el canje
         * no se otorga por lista, se gasta del saldo entero del cliente. Y con el centinela la
         * fila entra al unique (sale_id, tipo, price_type_id), que es lo que garantiza UN solo
         * canje por venta aunque el update corra dos veces.
         */
        $movimiento = MovimientoPunto::create([
            'user_id'              => $sale->user_id,
            'client_id'            => $sale->client_id,
            'sistema_de_puntos_id' => $sistema->id,
            'tipo'                 => self::TIPO_CANJEADOS,
            'puntos'               => -$puntos,
            'sale_id'              => $sale->id,
            'price_type_id'        => PuntosBaseHelper::SIN_LISTA,
            'monto_base'           => $descuento,
            'detalle'              => 'Canje en venta N° '.$sale->num,
            'employee_id'          => $sale->employee_id,
        ]);

        $consumido = PuntosSaldoHelper::consumir_fifo($movimiento, $puntos);

        if (abs($consumido - $puntos) > self::DELTA) {

            /*
             * No se aborta: el saldo del cliente (SUM(puntos)) ya bajó por el movimiento, que
             * es la cuenta que le importa al negocio. Que los lotes no alcancen significa que
             * el saldo venía de un ajuste manual sin lote o de un desalineo viejo, y eso se
             * arregla mirando el log, no tirando el guardado de la venta en la cara del que
             * está vendiendo.
             */
            Log::info(
                'Canje de puntos: los lotes no alcanzaron. Venta id '.$sale->id.', cliente '.$sale->client_id.
                ', pedidos '.$puntos.', consumidos '.$consumido
            );
        }
    }

    /**
     * Deshace el canje de una venta: devuelve los puntos a los lotes de los que salieron, borra
     * el movimiento y limpia las dos columnas de la venta.
     *
     * La usan `SaleController@update` (antes de aplicar el canje nuevo) y
     * `SaleController@destroy` (antes del delete). Es idempotente: sin canje no hace nada.
     *
     * @param  \App\Models\Sale  $sale
     * @return void
     */
    static function deshacer($sale) {

        if (is_null($sale) || is_null($sale->id)) {
            return;
        }

        /*
         * Gate memoizado por extensión y no por programa activo: si alguien apaga el programa,
         * las ventas que ya tenían canje tienen que poder editarse y borrarse igual. Sin la
         * extensión no puede haber un solo movimiento, así que la salida no cuesta una query.
         */
        if (!PuntosConfigHelper::tiene_extencion($sale->user_id)) {
            return;
        }

        $movimiento = MovimientoPunto::where('sale_id', $sale->id)
                                        ->where('tipo', self::TIPO_CANJEADOS)
                                        ->first();

        if (!is_null($movimiento)) {

            PuntosSaldoHelper::deshacer_consumos($movimiento);

            $movimiento->delete();
        }

        if (is_null($sale->puntos_canjeados) && is_null($sale->descuento_puntos)) {
            return;
        }

        $sale->puntos_canjeados = null;
        $sale->descuento_puntos = null;
        $sale->save();
    }

    /**
     * Los pesos que descuenta un canje.
     *
     * @param  float                        $puntos
     * @param  \App\Models\SistemaDePuntos  $sistema
     * @return float
     */
    static function calcular_descuento($puntos, $sistema) {
        return round((float) $puntos * (float) $sistema->valor_punto, 2);
    }

    /**
     * El cuerpo del 422, con la misma forma que el de LimiteCreditoHelper: una bandera propia
     * para que el front pueda distinguir este rechazo de cualquier otro, y un `message` que se
     * puede mostrar tal cual.
     *
     * @param  string  $message
     * @return array
     */
    private static function error($message) {

        return [
            'error_canje_puntos' => true,
            'message'            => $message,
        ];
    }

    /**
     * @param  mixed  $valor
     * @return float
     */
    private static function a_float($valor) {

        if (is_null($valor) || $valor === '') {
            return 0;
        }

        return (float) $valor;
    }

    /**
     * @param  float|int|string  $numero
     * @return string
     */
    private static function formato($numero) {
        return number_format((float) $numero, 2, ',', '.');
    }
}
