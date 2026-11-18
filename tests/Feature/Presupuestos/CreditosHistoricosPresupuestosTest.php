<?php

namespace Tests\Feature\Presupuestos;

use App\Models\Article;
use App\Models\Budget;
use App\Models\BudgetStatus;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\PriceType;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/** Ticket 145: la validación del historial también debe atravesar el circuito de presupuestos. */
class CreditosHistoricosPresupuestosTest extends EmpresaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach ([1 => 'Sin confirmar', 2 => 'Confirmado'] as $id => $nombre) {
            if (!BudgetStatus::find($id)) {
                BudgetStatus::forceCreate(['id' => $id, 'name' => $nombre]);
            }
        }
    }

    public function caminos()
    {
        return [['alta_confirmada'], ['update'], ['confirmar'], ['anular']];
    }

    /** @dataProvider caminos */
    public function test_cuenta_ambigua_explica_el_movimiento_y_revierte_todo_el_presupuesto($camino)
    {
        $usuario = $this->app['auth']->user()->id;
        $lista = PriceType::create(['name' => 'Lista presupuesto crédito histórico', 'user_id' => $usuario, 'position' => 1]);
        $cliente = Client::create(['name' => 'Presupuesto crédito histórico', 'user_id' => $usuario,
            'price_type_id' => $lista->id, 'saldo_pesos' => -85]);
        $cuenta = CreditAccount::create(['model_name' => 'client', 'model_id' => $cliente->id,
            'user_id' => $usuario, 'moneda_id' => 1, 'saldo' => -85]);
        $articulo = Article::create(['name' => 'Artículo presupuesto crédito histórico', 'user_id' => $usuario,
            'status' => 'active', 'stock' => 10, 'final_price' => 100, 'costo_real' => 50]);
        $debito = CurrentAcount::create(['client_id' => $cliente->id, 'credit_account_id' => $cuenta->id,
            'user_id' => $usuario, 'moneda_id' => 1, 'debe' => 100, 'status' => 'sin_pagar', 'pagandose' => 0,
            'saldo' => 100, 'detalle' => 'Venta histórica', 'created_at' => '2026-01-01 10:00:00']);
        // El saldo a favor obliga a aplicar el historial al agregar la venta nueva. La forma
        // Debe negativo + Haber positivo es ambigua y no se debe interpretar a ciegas.
        $credito = CurrentAcount::create(['client_id' => $cliente->id, 'credit_account_id' => $cuenta->id,
            'user_id' => $usuario, 'moneda_id' => 1, 'debe' => -175, 'haber' => 10,
            'status' => 'sin_pagar', 'pagandose' => 0, 'saldo' => -85,
            'detalle' => 'Crédito ambiguo a revisar', 'created_at' => '2026-02-01 10:00:00']);
        $payload = ['client_id' => $cliente->id, 'price_type_id' => $lista->id, 'start_at' => null,
            'finish_at' => null, 'observations' => 'Cambio que debe revertirse', 'total' => 100,
            'budget_status_id' => 2, 'address_id' => null, 'sale_status_id' => null,
            'discount_stock' => 0, 'iva_aplicado' => 0, 'moneda_id' => 1, 'valor_dolar' => null,
            'surchages_in_services' => 1, 'discounts_in_services' => 1,
            'omitir_en_cuenta_corriente' => 0, 'discounts' => [], 'surchages' => [],
            'services' => [], 'promocion_vinotecas' => [], 'combos' => [],
            'articles' => [['id' => $articulo->id, 'status' => 'active', 'cost_in_dollars' => null,
                'name' => $articulo->name, 'amount' => 1, 'price' => 100, 'costo_real' => 50,
                'name_vender_personalizado' => null, 'unidades_individuales' => null,
                'presentacion' => null, 'price_type_personalizado_id' => null, 'bonus' => null, 'location' => null]]];
        $presupuesto = null;
        if ($camino !== 'alta_confirmada') {
            $presupuesto = Budget::create(['user_id' => $usuario, 'client_id' => $cliente->id,
                'price_type_id' => $lista->id, 'budget_status_id' => $camino === 'anular' ? 2 : 1,
                'total' => 100, 'moneda_id' => 1, 'discount_stock' => 0, 'iva_aplicado' => 0,
                'surchages_in_services' => 1, 'discounts_in_services' => 1,
                'observations' => 'Estado original a conservar']);
            $presupuesto->articles()->attach($articulo->id, ['amount' => 1, 'price' => 100, 'cost' => 50]);
        }
        if ($camino === 'anular') {
            $venta = Sale::create(['user_id' => $usuario, 'client_id' => $cliente->id,
                'budget_id' => $presupuesto->id, 'price_type_id' => $lista->id,
                'moneda_id' => 1, 'total' => 100, 'save_current_acount' => 1, 'terminada' => 1,
                'discount_stock' => 0, 'created_at' => '2026-01-01 10:00:00']);
            $debito->update(['sale_id' => $venta->id]);
        }
        $antes = $this->foto($cliente, $cuenta, $articulo);
        if ($camino === 'alta_confirmada') {
            $respuesta = $this->postJson('api/budget', $payload);
        } elseif ($camino === 'update') {
            $respuesta = $this->putJson('api/budget/'.$presupuesto->id, $payload);
        } else {
            $respuesta = $this->postJson('api/budget/'.$presupuesto->id.'/'.$camino);
        }
        $respuesta->assertStatus(422);
        $this->assertStringContainsString('#'.$credito->id, (string) $respuesta->json('message'), $camino);
        $this->assertStringContainsString('Revisá', (string) $respuesta->json('message'), $camino);
        $this->assertArrayHasKey('cuenta_corriente', $respuesta->json('errors'), $camino);
        $this->assertEquals($antes, $this->foto($cliente, $cuenta, $articulo),
            $camino.' debe revertir presupuesto, venta, historial, saldo y efectos físicos/financieros.');
    }

    private function foto($cliente, $cuenta, $articulo): array
    {
        $presupuestos = DB::table('budgets')->where('client_id', $cliente->id)->orderBy('id')->get();
        $movimientos = DB::table('current_acounts')->where('credit_account_id', $cuenta->id)->orderBy('id')->get();
        $ventas = DB::table('sales')->where('client_id', $cliente->id)->orderBy('id')->get();
        return ['presupuestos' => $presupuestos->toArray(),
            'renglones_presupuestos' => DB::table('article_budget')->whereIn('budget_id', $presupuestos->pluck('id'))->orderBy('id')->get()->toArray(),
            'ventas' => $ventas->toArray(), 'movimientos' => $movimientos->toArray(),
            'imputaciones' => DB::table('pagado_por')->whereIn('debe_id', $movimientos->pluck('id'))->orderBy('id')->get()->toArray(),
            'cuenta' => (array) DB::table('credit_accounts')->where('id', $cuenta->id)->first(),
            'cliente' => (array) DB::table('clients')->where('id', $cliente->id)->first(),
            'articulo' => (array) DB::table('articles')->where('id', $articulo->id)->first(),
            'stock' => DB::table('stock_movements')->whereIn('sale_id', $ventas->pluck('id'))->orderBy('id')->get()->toArray(),
            'caja' => DB::table('movimiento_cajas')->whereIn('caja_id', DB::table('cajas')->where('user_id', $cliente->user_id)->select('id'))
                ->orderBy('id')->get()->toArray()];
    }
}
