<?php

namespace Tests\Feature\EdicionCompletaVenta;

use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Tests\Feature\CuentaCorriente\ArmaCadenas;
use Tests\Feature\VentasEnDolares\CuentaCorriente_Base;

/**
 * Base de los tests de la misión edicion-completa-ventas-presupuestos (8/10/2026): editar una venta
 * ya guardada cambiándole el CLIENTE, la MONEDA, la COTIZACIÓN del dólar y el IVA, por el endpoint
 * real (`PUT api/sale/{id}`).
 *
 * El pedido de Lucas dice, textual, "chequear que el saldo del cliente original quede bien luego de
 * haber sacado esa venta". Por eso cada caso de cuenta corriente cierra con
 * `assert_saldo_independiente()`, que NO usa ninguna función del sistema para calcular lo esperado:
 * suma a mano el debe y el haber CRUDOS de `current_acounts` de la cuenta y compara ese número contra
 * los tres lugares donde el sistema guarda el saldo (el `saldo` del último movimiento de la cadena,
 * `credit_accounts.saldo` y `clients.saldo_pesos` / `saldo_dolares`), y además recorre la cadena fila
 * por fila con `assert_cadena_cierra()` (de `ArmaCadenas`, que tampoco usa el código bajo prueba).
 * Un test que verifica el saldo con la misma función que lo calculó no puede dar rojo.
 *
 * Se apoya en `CuentaCorriente_Base` (escenario 2R: dueño 500, dólar 1200, cajas por moneda, ventas y
 * pagos por los endpoints reales). Lo único que se escribe directo en la base es SETUP: clientes,
 * límites, la marca de consolidación.
 *
 * 🔴 EL PAYLOAD DE EDICIÓN EMULA A LA SPA NUEVA (`previus_sale/index.js::updateSale()`): manda
 * `moneda_id` y `valor_dolar` SIEMPRE, y los renglones con el `pivot` guardado (precio, costo y
 * cantidad), que es lo que la SPA restaura al reabrir una venta. Un test que quiera la SPA vieja saca
 * `moneda_id` del payload con `sin_moneda()`.
 *
 * PHP 7.4: sin `match`, `?->`, argumentos nombrados, union types ni propiedades tipadas.
 */
abstract class EdicionCompletaVentaTestCase extends CuentaCorriente_Base
{
    use ArmaCadenas;

    /**
     * Los renglones de artículo de una venta tal como los manda la SPA al editarla: con el precio
     * guardado en `price_vender` y el `pivot` (precio, costo, cantidad). Con el `pivot` presente,
     * `SaleHelper::getCost()` devuelve el costo guardado (no lo vuelve a cotizar).
     *
     * @param  \App\Models\Sale  $venta
     * @return array
     */
    protected function items_de($venta)
    {
        $items = [];

        $filas = DB::table('article_sale')
                    ->where('sale_id', $venta->id)
                    ->orderBy('id')
                    ->get();

        foreach ($filas as $fila) {

            $items[] = [
                'is_article'   => true,
                'id'           => (int) $fila->article_id,
                'price_vender' => (float) $fila->price,
                'amount'       => (float) $fila->amount,
                // Un costo NULL viaja null (no 0): la SPA restaura el pivot tal cual. Con null,
                // `getCost()` no toma la rama del pivot y el renglón vuelve a quedar sin costo.
                'pivot'        => [
                    'price'  => (float) $fila->price,
                    'cost'   => is_null($fila->cost) ? null : (float) $fila->cost,
                    'amount' => (float) $fila->amount,
                ],
            ];
        }

        return $items;
    }

    /**
     * Los mismos renglones re-expresados en otra moneda, como lo hace `reexpresar_comprobante.js` en
     * la SPA al cambiar la moneda del comprobante: precio y costo guardados, divididos (pesos →
     * dólares) o multiplicados (dólares → pesos) por la cotización.
     *
     * @param  array  $items
     * @param  float  $factor  Lo que multiplica a cada importe (1/1200 para pasar a dólares).
     * @return array
     */
    protected function reexpresar($items, $factor)
    {
        foreach ($items as $i => $item) {
            $items[$i]['price_vender']    = round($item['price_vender'] * $factor, 2);
            $items[$i]['pivot']['price']  = round($item['pivot']['price'] * $factor, 2);
            $items[$i]['pivot']['cost']   = is_null($item['pivot']['cost']) ? null : round($item['pivot']['cost'] * $factor, 2);
        }

        return $items;
    }

    /**
     * Payload de `PUT api/sale/{id}` de la SPA nueva para una venta a cuenta corriente, sin cambios:
     * lo que hay que cambiar se pasa en `$overrides`.
     *
     * @param  \App\Models\Sale  $venta
     * @param  array  $overrides
     * @return array
     */
    protected function payload_de_edicion($venta, $overrides = [])
    {
        $venta = Sale::find($venta->id);

        return array_merge([
            'client_id'                  => $venta->client_id,
            'save_current_acount'        => $venta->save_current_acount,
            'omitir_en_cuenta_corriente' => $venta->omitir_en_cuenta_corriente,
            'to_check'                   => 0,
            'checked'                    => 0,
            'confirmed'                  => 0,
            'discounts_in_services'      => 1,
            'surchages_in_services'      => 1,
            // El alta guarda 0 como "sin vendedor"; la SPA manda null.
            'seller_id'                  => $venta->seller_id ? $venta->seller_id : null,
            'employee_id'                => null,
            'sub_total'                  => (float) $venta->total,
            'total'                      => (float) $venta->total,
            'moneda_id'                  => $venta->moneda_id,
            'valor_dolar'                => $venta->valor_dolar,
            'iva_aplicado'               => $venta->iva_aplicado,
            'items'                      => $this->items_de($venta),
            'discounts'                  => [],
            'surchages'                  => [],
            'returned_items'             => [],
        ], $overrides);
    }

    /**
     * Saca `moneda_id` del payload: es el PUT de la SPA vieja, que nunca la mandó.
     *
     * @param  array  $payload
     * @return array
     */
    protected function sin_moneda($payload)
    {
        unset($payload['moneda_id']);

        return $payload;
    }

    /**
     * Edita la venta por el endpoint real y devuelve la respuesta cruda.
     *
     * @param  \App\Models\Sale  $venta
     * @param  array  $payload
     * @return \Illuminate\Testing\TestResponse
     */
    protected function put_venta($venta, $payload)
    {
        $this->avanzar_reloj_de_ventas();

        return $this->putJson('api/sale/'.$venta->id, $payload);
    }

    /**
     * Edita la venta y exige 200, mostrando el cuerpo si no.
     *
     * @param  \App\Models\Sale  $venta
     * @param  array  $payload
     * @return \App\Models\Sale  La venta releída.
     */
    protected function editar_ok($venta, $payload)
    {
        $response = $this->put_venta($venta, $payload);

        $this->assertEquals(200, $response->getStatusCode(), 'PUT api/sale/'.$venta->id.' no devolvió 200. Cuerpo: '.substr($response->getContent(), 0, 800));

        return Sale::find($venta->id);
    }

    /**
     * El saldo de la cuenta del cliente en esa moneda, verificado de forma INDEPENDIENTE contra un
     * valor calculado a mano en el test:
     *
     *  1. Σ debe − Σ haber de los movimientos no provisorios de la cuenta, leídos crudos;
     *  2. el `saldo` del último movimiento de la cadena (orden `created_at, id`);
     *  3. `credit_accounts.saldo`;
     *  4. `clients.saldo_pesos` o `clients.saldo_dolares`, según la moneda;
     *  5. la cadena cierra fila por fila (`assert_cadena_cierra()`).
     *
     * @param  \App\Models\Client  $cliente
     * @param  int  $moneda_id
     * @param  float  $esperado  El saldo que el test calculó a mano.
     * @param  string  $contexto
     * @return void
     */
    protected function assert_saldo_independiente($cliente, $moneda_id, $esperado, $contexto)
    {
        $cuenta = $this->cuenta($cliente, $moneda_id);

        $filas = DB::table('current_acounts')
                    ->where('credit_account_id', $cuenta->id)
                    ->where('is_provisorio', 0)
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->get();

        $suma = 0.0;

        foreach ($filas as $fila) {
            $suma += (float) $fila->debe - (float) $fila->haber;
        }

        $this->assertMonto($esperado, $suma, $contexto.': Σ debe − Σ haber de la cuenta (moneda '.$moneda_id.') no da el saldo esperado.');

        if (count($filas)) {
            $ultima = $filas[count($filas) - 1];
            $this->assertMonto($esperado, $ultima->saldo, $contexto.': el saldo del último movimiento de la cadena (id '.$ultima->id.') no es el esperado.');
        }

        $this->assertMonto($esperado, CreditAccount::find($cuenta->id)->saldo, $contexto.': credit_accounts.saldo no es el esperado.');

        $columna = $moneda_id == self::DOLARES ? 'saldo_dolares' : 'saldo_pesos';

        $this->assertMonto(
            $esperado,
            DB::table('clients')->where('id', $cliente->id)->value($columna),
            $contexto.': clients.'.$columna.' no es el esperado.'
        );

        $this->assert_cadena_cierra($cuenta->id, $contexto.' (moneda '.$moneda_id.')');
    }

    /**
     * "Foto" cruda de todo lo que una edición de venta puede escribir, para comparar antes y después
     * de un rechazo: la fila de la venta, sus renglones, los movimientos de cuenta corriente de los
     * clientes involucrados (y los que llevan el `sale_id`), las imputaciones de esos movimientos, las
     * cuentas de los clientes con sus saldos y los saldos de los clientes.
     *
     * @param  \App\Models\Sale  $venta
     * @param  array  $clientes  Los clientes involucrados.
     * @return array
     */
    protected function foto_cruda($venta, $clientes)
    {
        $ids_clientes = [];

        foreach ($clientes as $cliente) {
            $ids_clientes[] = $cliente->id;
        }

        $movimientos = DB::table('current_acounts')
                        ->where(function ($q) use ($ids_clientes, $venta) {
                            $q->whereIn('client_id', $ids_clientes)
                              ->orWhere('sale_id', $venta->id);
                        })
                        ->orderBy('id')
                        ->get();

        $ids_movimientos = $movimientos->pluck('id')->all();

        return [
            'sale'          => (array) DB::table('sales')->where('id', $venta->id)->first(),
            'article_sale'  => DB::table('article_sale')->where('sale_id', $venta->id)->orderBy('id')->get()->map(function ($f) { return (array) $f; })->all(),
            'movimientos'   => $movimientos->map(function ($f) { return (array) $f; })->all(),
            'pagado_por'    => DB::table('pagado_por')->whereIn('debe_id', $ids_movimientos)->orderBy('id')->get()->map(function ($f) { return (array) $f; })->all(),
            'cuentas'       => DB::table('credit_accounts')->where('model_name', 'client')->whereIn('model_id', $ids_clientes)->orderBy('id')->get()->map(function ($f) { return (array) $f; })->all(),
            'clientes'      => DB::table('clients')->whereIn('id', $ids_clientes)->orderBy('id')->get(['id', 'saldo', 'saldo_pesos', 'saldo_dolares'])->map(function ($f) { return (array) $f; })->all(),
            'comisiones'    => DB::table('seller_commissions')->where('sale_id', $venta->id)->orderBy('id')->get()->map(function ($f) { return (array) $f; })->all(),
        ];
    }

    /**
     * Un cliente de OTRO comercio (otro dueño), con sus cuentas: para la guarda de tenencia.
     *
     * @return \App\Models\Client
     */
    protected function cliente_de_otro_comercio()
    {
        $otro_duenio = DB::table('users')
                        ->whereNull('owner_id')
                        ->where('id', '!=', $this->usuario->id)
                        ->orderBy('id')
                        ->value('id');

        $this->assertNotNull($otro_duenio, 'La base de testing no tiene un segundo dueño para armar el cliente ajeno.');

        $cliente = Client::create([
            'num'     => (int) Client::where('user_id', $otro_duenio)->max('num') + 1,
            'name'    => 'zz Cliente ajeno '.uniqid(),
            'user_id' => $otro_duenio,
        ]);

        \App\Http\Controllers\Helpers\CreditAccountHelper::crear_credit_accounts('client', $cliente->id, $otro_duenio);

        return $cliente;
    }

    /**
     * El movimiento de débito de la venta en CUALQUIER cuenta (o null): para afirmar que no quedó en
     * ninguna otra.
     *
     * @param  \App\Models\Sale  $venta
     * @return \Illuminate\Support\Collection
     */
    protected function debitos_de_la_venta($venta)
    {
        return CurrentAcount::where('sale_id', $venta->id)
                            ->whereNull('haber')
                            ->get();
    }
}
