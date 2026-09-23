<?php

namespace App\Http\Controllers\Helpers\providerOrder;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\currentAcount\CuentaCorrienteLock;
use App\Models\ProviderOrder;
use App\Services\DemoEventoEmitter;
use Illuminate\Support\Facades\DB;

/**
 * El alta de una compra a proveedor, en un solo lugar (misión asistente-por-whatsapp, §3.6 del
 * plan, 16/9/2026).
 *
 * Hasta hoy el alta vivía inline adentro de ProviderOrderController::store(), leyendo el `$request`
 * campo por campo. Eso es la clase de error "el store() que lee un subconjunto del request y el
 * llamador que le manda el resto" (APRENDER_NO_PARCHEAR.md, 5/9/2026): el segundo llamador —acá, el
 * asistente creando la compra donde va a colgar la factura— no tiene request, y armarle uno falso
 * o copiar el cuerpo dejaría dos altas que se van separando sola.
 *
 * 🔴 EL CUERPO ES EL MISMO, LITERAL, CON LAS CLAVES EXPLÍCITAS. `store()` pasó a delegar y no
 * cambió nada observable: misma transacción, mismo orden (create → updateRelationsCreated →
 * precargar_bonificaciones_proveedor → attach_articles → check_modo_facturacion → procesar_pedido),
 * mismo evento de demo afuera de la transacción y misma respuesta. Un `$request->campo` que no vino
 * era null, y una clave ausente de `$datos` también: por eso se lee con `valor()` y no con
 * `$datos['campo']`.
 *
 * 🔴 Y NO SE TOCA NewProviderOrderHelper. Es código de plata y stock: lo usan la pantalla de
 * compras, el update y la confirmación del escaneo de facturas. Que en un worker su constructor
 * resuelva `UserHelper::user()` a null se resuelve autenticando a la persona en el llamador
 * (ConfirmacionPorTextoIaHelper), no cambiándolo a él.
 */
class ProviderOrderAltaHelper
{
    /**
     * Crea la compra con sus artículos y la procesa (stock, precios, descuentos y cuenta corriente
     * del proveedor), todo en una transacción.
     *
     * La transacción no es prolijidad (tanda correctivos 2408, ítem 14): entre el create y
     * procesar_pedido() se escriben la orden, el pivot de artículos, stock, precios, descuentos
     * materializados y la cuenta corriente. Si algo revienta a mitad, sin transacción quedaba una
     * compra a medias — orden sin artículos, o stock sumado sin deuda registrada. La excepción
     * sigue subiendo al llamador: DB::transaction re-lanza después del rollback.
     *
     * @param  array  $datos  Claves: user_id (obligatoria), provider_id, address_id,
     *                        provider_order_status_id, modo_facturacion, total_with_iva,
     *                        total_from_provider_order_afip_tickets, days_to_advise, update_stock,
     *                        update_prices, precios_incluyen_iva, moneda_id,
     *                        generate_current_acount, numero_comprobante, created_at, childrens,
     *                        articles.
     * @return \App\Models\ProviderOrder
     */
    public static function crear(array $datos)
    {
        $controller = new Controller();

        $user_id = (int) self::valor($datos, 'user_id');

        $model = DB::transaction(function () use ($datos, $controller, $user_id) {

            /*
             * 🔴 Candado de la cuenta corriente del proveedor como primera sentencia de la
             * transacción (misión cuenta-corriente-carrera-y-velocidad, 23/9/2026): la compra entra
             * a su cadena de saldos. Ver CuentaCorrienteLock.
             */
            CuentaCorrienteLock::bloquear('provider', self::valor($datos, 'provider_id'));

            /*
             * La fecha de creación elegida por el usuario (misión fecha-creacion-editable,
             * 22/9/2026), y va aparte del array a propósito, NO como una clave más.
             *
             * 🔴 UN `'created_at' => null` NO ES LO MISMO QUE NO MANDAR LA CLAVE. `ProviderOrder`
             * tiene `$guarded = []`, así que la clave entra al modelo; y Eloquent solo completa
             * `created_at` con la hora de ahora si el atributo NO está sucio
             * (`updateTimestamps()`). Asignarlo en null lo ensucia igual, así que la compra
             * quedaría insertada con la columna en NULL en vez de la fecha de hoy. Por eso la
             * clave se agrega únicamente cuando hay valor: un llamador que no habla de la fecha
             * —el asistente de WhatsApp, por ejemplo— tiene que seguir cayendo en el default de
             * Eloquent, exactamente como hasta hoy.
             */
            $created_at = self::valor($datos, 'created_at');

            $datos_created_at = is_null($created_at) ? [] : ['created_at' => $created_at];

            $model = ProviderOrder::create(array_merge([
                'num'                                       => $controller->num('provider_orders', $user_id),
                'modo_facturacion'                          => self::valor($datos, 'modo_facturacion'),
                'total_with_iva'                            => self::valor($datos, 'total_with_iva'),
                'total_from_provider_order_afip_tickets'    => self::valor($datos, 'total_from_provider_order_afip_tickets'),
                'provider_id'                               => self::valor($datos, 'provider_id'),
                'provider_order_status_id'                  => self::valor($datos, 'provider_order_status_id'),
                'days_to_advise'                            => self::valor($datos, 'days_to_advise'),
                'update_stock'                              => self::valor($datos, 'update_stock'),
                'update_prices'                             => self::valor($datos, 'update_prices'),
                'precios_incluyen_iva'                      => self::valor($datos, 'precios_incluyen_iva'),
                'moneda_id'                                 => self::valor($datos, 'moneda_id'),
                'generate_current_acount'                   => self::valor($datos, 'generate_current_acount'),
                'address_id'                                => self::valor($datos, 'address_id'),
                'numero_comprobante'                        => self::valor($datos, 'numero_comprobante'),
                'user_id'                                   => $user_id,
            ], $datos_created_at));

            $controller->updateRelationsCreated('provider_order', $model->id, self::valor($datos, 'childrens'));

            $helper = new NewProviderOrderHelper($model, self::valor($datos, 'articles'));

            // Prompt 262: al crear la orden, pre-carga las bonificaciones del proveedor como
            // descuentos editables de esta orden puntual (si no vinieron descuentos propios).
            $helper->precargar_bonificaciones_proveedor();

            $helper->attach_articles();

            ModoFacturacionHelper::check_modo_facturacion($model, $helper);

            $helper->procesar_pedido();

            return $model;
        });

        /*
         * Evento de la demo (misión 50). Va al final, con la orden ya creada y procesada (stock y
         * precios incluidos): antes de eso todavía puede tirar y el evento estaría reportando una
         * compra que no quedó. Queda FUERA de la transacción a propósito: si el emisor tirara, la
         * compra ya commiteada no se revierte por un evento decorativo.
         *
         * En una instancia de cliente real no cuesta ni una query: la primera guarda del emisor
         * mira el marcador de sesión de demo y sale.
         */
        DemoEventoEmitter::emitir('compra.creada', null, ['id' => $model->id]);

        return $model;
    }

    /**
     * Valor de una clave, null si no vino.
     *
     * Existe para que una clave ausente valga exactamente lo mismo que un `$request->campo` que no
     * viajaba: null. Con `$datos['campo']` a secas, el llamador nuevo tendría que mandar las
     * catorce claves aunque no le importen trece.
     *
     * @param  array  $datos
     * @param  string  $clave
     * @return mixed
     */
    protected static function valor(array $datos, $clave)
    {
        return array_key_exists($clave, $datos) ? $datos[$clave] : null;
    }
}
