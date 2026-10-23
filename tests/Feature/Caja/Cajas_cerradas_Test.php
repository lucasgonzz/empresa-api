<?php

namespace Tests\Feature\Caja;

use App\Http\Controllers\Helpers\caja\CajaAperturaHelper;
use App\Http\Controllers\Helpers\caja\MovimientoCajaHelper;
use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Models\AperturaCaja;
use App\Models\Article;
use App\Models\Caja;
use App\Models\Cheque;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Expense;
use App\Models\MovimientoCaja;
use App\Models\MovimientoEntreCaja;
use App\Models\Sale;
use App\Models\Seller;
use App\Models\SellerCommission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\EmpresaTestCase;

/** Regresión de Ananda: un turno cerrado nunca recibe una operación nueva. */
class Cajas_cerradas_Test extends EmpresaTestCase
{
    protected $abierta;
    protected $cerrada;

    protected function setUp(): void
    {
        parent::setUp();
        $this->abierta = $this->crear_caja('zz Abierta cajas cerradas');
        $this->cerrada = $this->crear_caja('zz Cerrada cajas cerradas');
        $this->putJson('api/cerrar-caja/'.$this->cerrada->id)->assertStatus(200);
    }

    protected function crear_caja($nombre)
    {
        $caja = Caja::create(['name' => $nombre, 'num' => 990010, 'user_id' => 500, 'saldo' => 1000, 'moneda_id' => 1]);
        (new CajaAperturaHelper($caja->id))->abrir_caja();
        return $caja->fresh();
    }

    protected function fila($caja, $monto = 50)
    {
        return ['current_acount_payment_method_id' => 3, 'amount' => $monto, 'caja_id' => $caja->id,
            'moneda_id' => 1, 'cotizacion' => null, 'amount_cotizado' => null];
    }

    protected function foto()
    {
        return [Sale::count(), CurrentAcount::count(), Expense::count(), MovimientoCaja::count(),
            MovimientoEntreCaja::count(), $this->abierta->fresh()->saldo, $this->cerrada->fresh()->saldo,
            AperturaCaja::whereIn('caja_id', [$this->abierta->id, $this->cerrada->id])->orderBy('id')->get()->toArray(),
            DB::table('article_sale')->count(), DB::table('current_acount_payment_method_sale')->count(),
            DB::table('current_acount_current_acount_payment_method')->count(), DB::table('expense_current_acount_payment_method')->count(),
            DB::table('stock_movements')->count(), SellerCommission::count()];
    }

    protected function venta($filas, $otros = [])
    {
        $articulo = Article::create(['name' => 'zz Regresion caja cerrada', 'user_id' => 500, 'final_price' => 100, 'status' => 'active', 'stock' => 10]);
        return array_merge(['client_id' => null, 'omitir_en_cuenta_corriente' => 0, 'save_current_acount' => 0,
            'to_check' => 0, 'moneda_id' => 1, 'total' => 100, 'sub_total' => 100, 'terminada' => 1,
            'discount_stock' => 0, 'discounts' => [], 'surchages' => [], 'discounts_in_services' => 1,
            'surchages_in_services' => 1, 'selected_payment_methods' => $filas,
            'current_acount_payment_method_id' => null, 'cuota_descuento' => 0, 'cuota_recargo' => 0,
            'items' => [['id' => $articulo->id, 'is_article' => true, 'name' => $articulo->name, 'amount' => 1, 'price_vender' => 100]]], $otros);
    }

    /** @test */
    public function venta_repartida_con_caja_cerrada_rechaza_sin_venta_stock_ni_cobro_parcial()
    {
        Queue::fake();
        $payload = $this->venta([$this->fila($this->abierta), $this->fila($this->cerrada)], ['discount_stock' => 1]);
        $articulo = Article::findOrFail($payload['items'][0]['id']);
        $depositos = DB::table('address_article')->where('article_id', $articulo->id)->get()->toArray();
        $antes = $this->foto();
        $respuesta = $this->postJson('api/sale', $payload);
        $respuesta->assertStatus(422);
        $this->assertStringContainsString($this->cerrada->name, $respuesta->json('message'));
        $this->assertEquals($antes, $this->foto());
        $this->assertEquals(10, $articulo->fresh()->stock);
        $this->assertEquals($depositos, DB::table('address_article')->where('article_id', $articulo->id)->get()->toArray());
        Queue::assertNothingPushed();
    }

    /** @test */
    public function venta_en_caja_abierta_impacta_y_cuenta_corriente_no_exige_caja()
    {
        $payload = $this->venta([$this->fila($this->abierta, 100)], ['discount_stock' => 1]);
        $this->postJson('api/sale', $payload)->assertStatus(201);
        $this->assertEquals(9, Article::findOrFail($payload['items'][0]['id'])->stock);
        $this->assertEquals(1100, $this->abierta->fresh()->saldo);
        $cliente = Client::where('user_id', 500)->firstOrFail();
        $antes = MovimientoCaja::count();
        $this->postJson('api/sale', $this->venta([$this->fila($this->cerrada, 100)],
            ['client_id' => $cliente->id, 'save_current_acount' => 1]))->assertStatus(201);
        $this->assertEquals($antes, MovimientoCaja::count());
    }

    /** @test */
    public function cobro_mixto_rechaza_antes_del_recibo_y_de_ambas_cajas()
    {
        $cliente = Client::where('user_id', 500)->firstOrFail();
        CreditAccountHelper::crear_credit_accounts('client', $cliente->id);
        $cuenta = CreditAccount::where('model_name', 'client')->where('model_id', $cliente->id)->where('moneda_id', 1)->firstOrFail();
        $antes = $this->foto();
        $respuesta = $this->postJson('api/current-acount/pago', ['model_name' => 'client', 'model_id' => $cliente->id,
            'credit_account_id' => $cuenta->id, 'haber' => 100, 'current_date' => 1, 'is_provisorio' => 0,
            'current_acount_payment_methods' => [$this->fila($this->abierta), $this->fila($this->cerrada)]]);
        $respuesta->assertStatus(422);
        $this->assertStringContainsString($this->cerrada->name, $respuesta->json('message'));
        $this->assertEquals($antes, $this->foto());
    }

    /** @test */
    public function gasto_transferencia_y_manual_rechazan_sin_efectos()
    {
        $antes = $this->foto();
        $this->postJson('api/expense', ['amount' => 100, 'moneda_id' => 1,
            'payment_methods' => [$this->fila($this->abierta), $this->fila($this->cerrada)]])->assertStatus(422);
        $this->assertEquals($antes, $this->foto());
        $this->postJson('api/movimiento-entre-caja', ['from_caja_id' => $this->abierta->id,
            'to_caja_id' => $this->cerrada->id, 'amount' => 100])->assertStatus(422);
        $this->assertEquals($antes, $this->foto());
        $this->postJson('api/movimiento-caja', ['caja_id' => $this->cerrada->id,
            'apertura_caja_id' => AperturaCaja::where('caja_id', $this->cerrada->id)->value('id'),
            'concepto_movimiento_caja_id' => 1, 'ingreso' => 100])->assertStatus(422);
        $this->assertEquals($antes, $this->foto());
    }

    /** @test */
    public function cobrar_y_pagar_cheque_en_cerrada_no_cambia_su_estado()
    {
        foreach (['cobrar' => 'recibido', 'pagar' => 'emitido'] as $accion => $tipo) {
            $cheque = Cheque::create(['user_id' => 500, 'tipo' => $tipo, 'numero' => 'zz Cerrada '.$accion,
                'amount' => 100, 'fecha_emision' => date('Y-m-d'), 'fecha_pago' => date('Y-m-d')]);
            $antes = $this->foto();
            $this->putJson('api/cheque/'.$accion, ['cheque_id' => $cheque->id, 'caja_id' => $this->cerrada->id])->assertStatus(422);
            $this->assertNull($cheque->fresh()->estado_manual);
            $this->assertNull($cheque->fresh()->cobrado_en);
            $this->assertNull($cheque->fresh()->cobrado_por_id);
            $this->assertEquals($antes, $this->foto());
        }
    }

    /** @test */
    public function metodo_unico_y_pago_vendedor_tambien_rechazan_cerrada()
    {
        $payload = $this->venta([], ['current_acount_payment_method_id' => 3, 'caja_id' => $this->cerrada->id]);
        $antes = $this->foto();
        $this->postJson('api/sale', $payload)->assertStatus(422);
        $this->assertEquals($antes, $this->foto());
        $vendedor = Seller::create(['name' => 'zz Vendedor cajas cerradas', 'user_id' => 500, 'num' => 99001]);
        $this->postJson('api/seller-commission/pago', ['seller_id' => $vendedor->id, 'moneda_id' => 1,
            'current_acount_payment_methods' => [$this->fila($this->abierta), $this->fila($this->cerrada)]])->assertStatus(422);
        $this->assertEquals($antes, $this->foto());
    }

    /** @test */
    public function editar_gasto_sin_movimiento_no_lo_repara_en_caja_cerrada_ni_guarda_cambios_parciales()
    {
        $gasto = Expense::create(['user_id' => 500, 'num' => 99001, 'amount' => 100, 'moneda_id' => 1]);
        $gasto->current_acount_payment_methods()->attach(3, ['amount' => 100, 'caja_id' => $this->cerrada->id]);
        $antes = $this->foto();
        $this->putJson('api/expense/'.$gasto->id, ['amount' => 200, 'importe_iva' => 0,
            'created_at' => $gasto->created_at->format('Y-m-d H:i:s'), 'observations' => 'No debe guardarse'])->assertStatus(422);
        $this->assertEquals(100, $gasto->fresh()->amount);
        $this->assertNull($gasto->fresh()->observations);
        $this->assertEquals(100, $gasto->current_acount_payment_methods()->first()->pivot->amount);
        $this->assertEquals($antes, $this->foto());
    }

    /** @test */
    public function reapertura_anterior_recibe_el_movimiento_y_manual_no_elude_turno_vigente()
    {
        $anterior = $this->abierta->current_apertura_caja_id;
        $this->putJson('api/cerrar-caja/'.$this->abierta->id)->assertStatus(200);
        $this->putJson('api/abrir-caja/'.$this->abierta->id)->assertStatus(200);
        $posterior = $this->abierta->fresh()->current_apertura_caja_id;
        $this->postJson('api/apertura-caja/reabrir/'.$anterior)->assertStatus(200);
        $datos = ['caja_id' => $this->abierta->id, 'concepto_movimiento_caja_id' => 1, 'ingreso' => 100];
        $this->postJson('api/movimiento-caja', $datos + ['apertura_caja_id' => $posterior])->assertStatus(422);
        $respuesta = $this->postJson('api/movimiento-caja', $datos);
        $respuesta->assertStatus(201);
        $this->assertEquals($anterior, $respuesta->json('model.apertura_caja_id'));
    }

    /** @test */
    public function referencia_historica_explicita_sigue_disponible_para_helpers_internos()
    {
        $apertura = AperturaCaja::where('caja_id', $this->cerrada->id)->firstOrFail();
        $movimiento = (new MovimientoCajaHelper())->crear_movimiento(['caja_id' => $this->cerrada->id,
            'apertura_caja_id' => $apertura->id, 'concepto_movimiento_caja_id' => 1,
            'ingreso' => 10, 'egreso' => null, 'notas' => 'Reconstruccion historica interna']);
        $this->assertEquals($apertura->id, $movimiento->apertura_caja_id);
    }
}
