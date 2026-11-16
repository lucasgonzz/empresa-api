<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ResumenDeVentasIaHelper;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\Sale;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión asistente-diario-20261116, rama A, M6-caja — «¿cuánto entró a cada caja?».
 *
 * 🔴 EL PEDIDO REAL (Ferre Total, 14/11/2026): ventas por caja. consultar_resumen_de_ventas agrupaba por
 * método de pago pero no por caja, y el asistente contestaba que no tenía cómo.
 *
 * Lo que fija este archivo: `agrupar_por = caja` reparte la plata con el MISMO importe que el reparto
 * por método (`amount - discount_amount` del pivot; sin pivot, la venta entera a `sales.caja_id`), lo
 * sin caja va a «Sin caja», lo fiado a su grupo aparte, y la suma de los grupos da el total.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class T151_Ventas_por_caja_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::create([
            'name'     => 'Comercio T151',
            'email'    => 't151-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * Cuatro clases de venta, cuatro grupos, y la suma cuadra con el total y con el reparto por método.
     *
     * @group chat-ia
     * @test
     */
    public function agrupa_lo_que_entro_a_cada_caja_y_cuadra_con_el_total()
    {
        $dia = now()->subDays(3)->startOfDay();
        $fecha = $dia->format('Y-m-d');

        $mostrador = DB::table('cajas')->insertGetId(['num' => 1, 'name' => 'Caja mostrador T151', 'user_id' => $this->comercio->id, 'created_at' => now(), 'updated_at' => now()]);
        $banco = DB::table('cajas')->insertGetId(['num' => 2, 'name' => 'Banco T151', 'user_id' => $this->comercio->id, 'created_at' => now(), 'updated_at' => now()]);

        // 1) Cobrada con dos métodos a dos cajas: 600 efectivo (con 60 de descuento del método) + 400 transferencia.
        $repartida = $this->venta($dia->copy()->setTime(9, 0), 940);
        $this->pivot($repartida, 1, 600, 60, $mostrador);
        $this->pivot($repartida, 2, 400, null, $banco);

        // 2) Sin pivot, con la caja en la cabecera.
        $this->venta($dia->copy()->setTime(10, 0), 250, ['caja_id' => $banco]);

        // 3) Con pivot y sin caja.
        $sin_caja = $this->venta($dia->copy()->setTime(11, 0), 100);
        $this->pivot($sin_caja, 1, 100, null, null);

        // 4) A cuenta corriente: no entra a ninguna caja.
        $cliente = Client::create(['name' => 'Cliente T151', 'user_id' => $this->comercio->id]);
        $fiada = $this->venta($dia->copy()->setTime(12, 0), 700, ['client_id' => $cliente->id]);
        CurrentAcount::create(['user_id' => $this->comercio->id, 'client_id' => $cliente->id, 'sale_id' => $fiada->id, 'detalle' => 'Venta T151', 'debe' => 700, 'saldo' => 700, 'status' => 'sin_pagar', 'created_at' => $dia->copy()->setTime(12, 0)]);

        $resumen = ResumenDeVentasIaHelper::resumen($this->comercio->id, $fecha, $fecha, 'caja');

        $this->assertArrayNotHasKey('error', $resumen, json_encode($resumen));
        $this->assertSame('caja', $resumen['agrupado_por']);

        $grupos = [];
        foreach ($resumen['grupos'] as $grupo) {
            $grupos[$grupo['etiqueta']] = $grupo;
        }

        $this->assertEquals(540.0, $grupos['Caja mostrador T151']['total'], '600 menos los 60 de descuento del método.');
        $this->assertEquals(650.0, $grupos['Banco T151']['total'], '400 del pivot + 250 de la cabecera.');
        $this->assertSame(2, $grupos['Banco T151']['cantidad']);
        $this->assertEquals(100.0, $grupos[ResumenDeVentasIaHelper::ETIQUETA_SIN_CAJA]['total']);
        $this->assertEquals(700.0, $grupos[ResumenDeVentasIaHelper::ETIQUETA_CAJA_CUENTA_CORRIENTE]['total']);

        $suma = 0.0;
        foreach ($resumen['grupos'] as $grupo) {
            $suma += $grupo['total'];
        }

        // 🔴 La plata: la suma de las cajas es el total vendido, el mismo que reparte el método de pago.
        $this->assertEquals($resumen['total'], round($suma, 2));
        $this->assertEquals(1990.0, $resumen['total']);

        $por_metodo = ResumenDeVentasIaHelper::resumen($this->comercio->id, $fecha, $fecha, 'metodo_de_pago');
        $suma_metodos = 0.0;
        foreach ($por_metodo['grupos'] as $grupo) {
            $suma_metodos += $grupo['total'];
        }

        $this->assertEquals(round($suma_metodos, 2), round($suma, 2));
        $this->assertStringContainsString('flujo_caja', $resumen['nota']);
    }

    /**
     * Una caja de otro comercio no presta su nombre; y `caja` se ofrece al final del enum de la tool.
     *
     * @group chat-ia
     * @test
     */
    public function la_caja_ajena_no_presta_su_nombre_y_el_enum_la_ofrece_al_final()
    {
        $otro = User::create(['name' => 'Otro T151', 'email' => 't151-otro-' . uniqid() . '@test.local', 'password' => Hash::make('secret')]);
        $ajena = DB::table('cajas')->insertGetId(['num' => 1, 'name' => 'Caja ajena T151', 'user_id' => $otro->id, 'created_at' => now(), 'updated_at' => now()]);

        $dia = now()->subDays(3)->startOfDay();
        $this->venta($dia->copy()->setTime(9, 0), 300, ['caja_id' => $ajena]);

        $resumen = ResumenDeVentasIaHelper::resumen($this->comercio->id, $dia->format('Y-m-d'), $dia->format('Y-m-d'), 'caja');

        $this->assertSame('Caja #' . $ajena, $resumen['grupos'][0]['etiqueta']);

        $enum = null;
        foreach ((new AsistenteIaService())->herramientas_de_lectura() as $herramienta) {
            if ($herramienta['name'] === 'consultar_resumen_de_ventas') {
                $enum = $herramienta['input_schema']['properties']['agrupar_por']['enum'];
            }
        }

        $this->assertSame('caja', end($enum), 'Lo nuevo del enum va atrás (prefijo del caché).');
        $this->assertSame('facturada', $enum[count($enum) - 2]);
    }

    /**
     * @param  Carbon  $momento
     * @param  float   $total
     * @param  array   $extra
     * @return Sale
     */
    protected function venta(Carbon $momento, $total, array $extra = [])
    {
        return Sale::create(array_merge([
            'user_id'    => $this->comercio->id,
            'total'      => $total,
            'terminada'  => 1,
            'moneda_id'  => 1,
            'created_at' => $momento,
        ], $extra));
    }

    /**
     * Una fila de cobro de la venta (pivot current_acount_payment_method_sale).
     *
     * @param  Sale  $venta
     * @param  int  $metodo_id
     * @param  float  $monto
     * @param  float|null  $descuento
     * @param  int|null  $caja_id
     * @return void
     */
    protected function pivot(Sale $venta, $metodo_id, $monto, $descuento, $caja_id)
    {
        DB::table('current_acount_payment_method_sale')->insert([
            'current_acount_payment_method_id' => $metodo_id,
            'sale_id'                          => $venta->id,
            'amount'                           => $monto,
            'discount_amount'                  => $descuento,
            'caja_id'                          => $caja_id,
            'created_at'                       => now(),
            'updated_at'                       => now(),
        ]);
    }
}
