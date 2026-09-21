<?php

namespace Tests\Feature\Mostrador;

use App\Models\Caja;
use App\Models\CompanyPerformance;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\DebtSnapshot;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseConcept;
use App\Models\ProviderOrder;
use App\Services\Mostrador\RecolectorDeHechos;
use App\Services\Mostrador\RecolectorDia;
use Illuminate\Support\Facades\DB;

/**
 * Misión modulo-ia-mostrador — P2: el recolector del "Rendimiento de ayer".
 *
 * Sobre un comercio sembrado a mano (tres ventas ayer en dos sucursales, una a
 * cuenta corriente, una venta la semana pasada y otra hace tres días, un pago de un
 * cliente, una nota de crédito, un artículo que volvió a venderse tras 80 días, uno
 * que quedó en cero, una compra, dos gastos, movimientos y un cierre de caja), cada
 * número del JSON es el que se sembró.
 */
class Recolector_dia_Test extends MostradorTestCase
{
    /** @var array Lo sembrado, para que los asserts nombren ids reales */
    protected $s = [];

    /**
     * Siembra el escenario completo del día.
     *
     * @return void
     */
    protected function sembrar_el_dia()
    {
        $central = $this->sucursal('Casa central');
        $norte   = $this->sucursal('Norte');

        $martillo = $this->articulo('Martillo', ['stock' => 0, 'stock_min' => 5]);
        $pinza    = $this->articulo('Pinza', ['stock' => 10]);
        $cuchara  = $this->articulo('Cuchara', ['stock' => 4, 'stock_min' => 5]);
        $destornillador = $this->articulo('Destornillador', ['stock' => 20]);

        $perez = $this->cliente('Pérez');
        $lopez = $this->cliente('López');
        $gomez = $this->cliente('Gómez');

        $juan = $this->empleado();
        $juan->name = 'Juan';
        $juan->save();

        // Tres ventas ayer.
        $this->venta($this->ayer_a_las(10), [[$martillo, 2, 1000]], [
            'address_id' => $central->id,
            'total_cost' => 1000,
        ]);

        $venta_cc = $this->venta($this->ayer_a_las(11), [[$pinza, 1, 500], [$martillo, 1, 1000]], [
            'address_id'  => $norte->id,
            'employee_id' => $juan->id,
            'client_id'   => $lopez->id,
            'total_cost'  => 700,
        ]);

        // El movimiento de cuenta corriente de esa venta: es lo que la hace "a cuenta".
        CurrentAcount::create([
            'user_id'    => $this->comercio->id,
            'client_id'  => $lopez->id,
            'sale_id'    => $venta_cc->id,
            'debe'       => 1500,
            'status'     => 'sin_pagar',
            'created_at' => $this->ayer_a_las(11),
        ]);

        $venta_transferencia = $this->venta($this->ayer_a_las(15), [[$destornillador, 4, 250]], [
            'address_id' => $central->id,
            'total_cost' => 600,
        ]);

        $transferencia = CurrentAcountPaymentMethod::where('name', 'Transferencia')->first();

        if (!$transferencia) {
            $transferencia = CurrentAcountPaymentMethod::forceCreate(['name' => 'Transferencia']);
        }

        DB::table('current_acount_payment_method_sale')->insert([
            'sale_id'                          => $venta_transferencia->id,
            'current_acount_payment_method_id' => $transferencia->id,
            'amount'                           => 1000,
            'created_at'                       => $this->ayer_a_las(15),
            'updated_at'                       => $this->ayer_a_las(15),
        ]);

        // La semana pasada, mismo día: una venta. Hace tres días: otra.
        $this->venta($this->ayer->copy()->subDays(7)->setTime(12, 0), [[$pinza, 2, 500]]);
        $this->venta($this->ayer->copy()->subDays(3)->setTime(12, 0), [[$pinza, 1, 500]]);

        // El destornillador se había vendido por última vez hace 80 días; el martillo hace 10.
        $this->venta($this->ayer->copy()->subDays(80)->setTime(12, 0), [[$destornillador, 1, 250]]);
        $this->venta($this->ayer->copy()->subDays(10)->setTime(12, 0), [[$martillo, 1, 1000]]);

        // Nota de crédito ayer, del módulo de devoluciones: devuelve una pinza que había
        // costado $150 (article_current_acount.cost), que Rendimiento vuelve a sumar.
        $nota = CurrentAcount::create([
            'user_id'    => $this->comercio->id,
            'client_id'  => $perez->id,
            'haber'      => 200,
            'status'     => 'nota_credito',
            'created_at' => $this->ayer_a_las(16),
        ]);

        DB::table('article_current_acount')->insert([
            'article_id'        => $pinza->id,
            'current_acount_id' => $nota->id,
            'amount'            => 1,
            'price'             => 200,
            'cost'              => 150,
            'created_at'        => $this->ayer_a_las(16),
            'updated_at'        => $this->ayer_a_las(16),
        ]);

        // Pagos: Pérez ayer, López hace 20 días, Gómez nunca.
        CurrentAcount::create([
            'user_id'    => $this->comercio->id,
            'client_id'  => $perez->id,
            'haber'      => 300,
            'status'     => 'pago_from_client',
            'created_at' => $this->ayer_a_las(12),
        ]);

        CurrentAcount::create([
            'user_id'    => $this->comercio->id,
            'client_id'  => $lopez->id,
            'haber'      => 100,
            'status'     => 'pago_from_client',
            'created_at' => $this->ayer->copy()->subDays(20)->setTime(12, 0),
        ]);

        // Deudas (credit_accounts es la fuente de verdad).
        foreach ([[$perez, 900], [$lopez, 300], [$gomez, 50]] as $par) {
            CreditAccount::create([
                'model_name' => 'client',
                'model_id'   => $par[0]->id,
                'saldo'      => $par[1],
                'moneda_id'  => 1,
                'user_id'    => $this->comercio->id,
            ]);
        }

        DebtSnapshot::create(['user_id' => $this->comercio->id, 'date' => $this->ayer->format('Y-m-d'), 'deuda_clientes' => 1250]);
        DebtSnapshot::create(['user_id' => $this->comercio->id, 'date' => $this->ayer->copy()->subDay()->format('Y-m-d'), 'deuda_clientes' => 1500]);

        // Una compra a un proveedor.
        $acme = $this->proveedor_nuevo('Acme');

        ProviderOrder::create([
            'user_id'     => $this->comercio->id,
            'provider_id' => $acme->id,
            'total'       => 5000,
            'created_at'  => $this->ayer_a_las(9),
        ]);

        // Dos gastos: uno con categoría, otro sin.
        $servicios = ExpenseCategory::create(['name' => 'Servicios', 'user_id' => $this->comercio->id]);
        $luz  = ExpenseConcept::create(['num' => 1, 'name' => 'Luz', 'user_id' => $this->comercio->id, 'expense_category_id' => $servicios->id]);
        $cafe = ExpenseConcept::create(['num' => 2, 'name' => 'Café', 'user_id' => $this->comercio->id]);

        Expense::create(['user_id' => $this->comercio->id, 'expense_concept_id' => $luz->id, 'amount' => 700, 'created_at' => $this->ayer_a_las(13)]);
        Expense::create(['user_id' => $this->comercio->id, 'expense_concept_id' => $cafe->id, 'amount' => 300, 'created_at' => $this->ayer_a_las(14)]);

        // Caja: ingresos, egresos y un cierre.
        $caja = Caja::create(['num' => 1, 'name' => 'Principal', 'user_id' => $this->comercio->id]);

        $apertura_id = DB::table('apertura_cajas')->insertGetId([
            'caja_id'        => $caja->id,
            'saldo_apertura' => 0,
            'cerrada_at'     => $this->ayer_a_las(20),
            'created_at'     => $this->ayer_a_las(8),
            'updated_at'     => $this->ayer_a_las(20),
        ]);

        DB::table('movimiento_cajas')->insert([
            ['caja_id' => $caja->id, 'apertura_caja_id' => $apertura_id, 'ingreso' => 4500, 'egreso' => null, 'created_at' => $this->ayer_a_las(10), 'updated_at' => $this->ayer_a_las(10)],
            ['caja_id' => $caja->id, 'apertura_caja_id' => $apertura_id, 'ingreso' => null, 'egreso' => 1000, 'created_at' => $this->ayer_a_las(14), 'updated_at' => $this->ayer_a_las(14)],
        ]);

        $this->s = compact('central', 'norte', 'martillo', 'pinza', 'cuchara', 'destornillador', 'perez', 'lopez', 'gomez', 'juan', 'acme');
    }

    /**
     * @group mostrador
     * @test
     */
    public function la_fecha_por_defecto_es_ayer_para_dia_y_tienda_y_hoy_para_compras_y_stock()
    {
        $hoy = \Carbon\Carbon::parse('2026-09-14 10:30:00');

        $this->assertSame('2026-09-13', RecolectorDeHechos::fecha_por_defecto('dia', $hoy)->format('Y-m-d'));
        $this->assertSame('2026-09-13', RecolectorDeHechos::fecha_por_defecto('tienda', $hoy)->format('Y-m-d'));
        $this->assertSame('2026-09-14', RecolectorDeHechos::fecha_por_defecto('compras', $hoy)->format('Y-m-d'));
        $this->assertSame('2026-09-14', RecolectorDeHechos::fecha_por_defecto('stock', $hoy)->format('Y-m-d'));
    }

    /**
     * @group mostrador
     * @test
     */
    public function un_tipo_desconocido_no_se_recolecta()
    {
        $this->expectException(\InvalidArgumentException::class);

        (new RecolectorDeHechos())->recolectar($this->comercio, 'ventas', $this->ayer);
    }

    /**
     * @group mostrador
     * @test
     */
    public function las_ventas_de_ayer_con_sus_tres_aperturas()
    {
        $this->sembrar_el_dia();

        $h = (new RecolectorDia())->recolectar($this->comercio, $this->ayer);

        $this->assertTrue($h['aplica']);
        $this->assertSame($this->ayer->format('Y-m-d'), $h['fecha']);
        $this->assertSame(RecolectorDia::DIAS_SEMANA[$this->ayer->dayOfWeek], $h['dia_semana']);

        $v = $h['ventas'];
        $this->assertSame(3, $v['cantidad']);
        $this->assertEquals(4500.00, $v['total']);
        $this->assertEquals(1500.00, $v['ticket_promedio']);
        $this->assertEquals(1500.00, $v['a_cuenta_corriente']);
        $this->assertEquals(200.00, $v['devoluciones']);

        $this->assertSame([
            ['address_id' => $this->s['central']->id, 'nombre' => 'Casa central', 'cantidad' => 2, 'total' => 3000.0],
            ['address_id' => $this->s['norte']->id, 'nombre' => 'Norte', 'cantidad' => 1, 'total' => 1500.0],
        ], $v['por_sucursal']);

        $efectivo = CurrentAcountPaymentMethod::find(RecolectorDia::METODO_PAGO_DEFAULT_ID);

        $this->assertSame([
            ['metodo' => $efectivo ? $efectivo->name : 'Método #3', 'total' => 2000.0],
            ['metodo' => 'Cuenta corriente', 'total' => 1500.0],
            ['metodo' => 'Transferencia', 'total' => 1000.0],
        ], $v['por_metodo_pago']);

        $this->assertSame([
            ['empleado' => 'Dueño mostrador', 'cantidad' => 2, 'total' => 3000.0],
            ['empleado' => 'Juan', 'cantidad' => 1, 'total' => 1500.0],
        ], $v['por_vendedor']);
    }

    /**
     * @group mostrador
     * @test
     */
    public function la_comparacion_mira_el_mismo_dia_de_la_semana_pasada_y_el_promedio_de_30_dias()
    {
        $this->sembrar_el_dia();

        $h = (new RecolectorDia())->recolectar($this->comercio, $this->ayer);

        $this->assertSame([
            'fecha'    => $this->ayer->copy()->subDays(7)->format('Y-m-d'),
            'cantidad' => 1,
            'total'    => 1000.0,
        ], $h['comparacion']['mismo_dia_semana_anterior']);

        // 3 ventas de ayer ($4.500) + 1 de la semana pasada ($1.000) + 1 de hace tres días ($500)
        // + 1 de hace diez días ($1.000) = 6 ventas, $7.000 en la ventana de 30 días. La
        // primera venta de la ventana es la de hace diez días: se divide por los 11 días
        // transcurridos desde entonces (ayer incluido), no por 30.
        $this->assertSame(11, $h['comparacion']['promedio_diario_30_dias']['dias_considerados']);
        $this->assertEquals(round(6 / 11, 1), $h['comparacion']['promedio_diario_30_dias']['cantidad']);
        $this->assertEquals(round(7000 / 11, 2), $h['comparacion']['promedio_diario_30_dias']['total']);
    }

    /**
     * El promedio de 30 días se divide por los días transcurridos desde la primera
     * venta de la ventana, entre 1 y 30: sin ventas son los 30; con la primera hace
     * 40 días (fuera de la ventana) y otra ayer, cuenta desde la primera DE LA VENTANA.
     *
     * @group mostrador
     * @test
     */
    public function el_promedio_de_30_dias_se_divide_por_los_dias_desde_la_primera_venta()
    {
        $h = (new RecolectorDia())->recolectar($this->comercio, $this->ayer);
        $this->assertSame(30, $h['comparacion']['promedio_diario_30_dias']['dias_considerados']);
        $this->assertEquals(0.0, $h['comparacion']['promedio_diario_30_dias']['total']);

        $pinza = $this->articulo('Pinza');
        $this->venta($this->ayer->copy()->subDays(40)->setTime(12, 0), [[$pinza, 1, 500]]);
        $this->venta($this->ayer_a_las(12), [[$pinza, 2, 500]]);

        $h = (new RecolectorDia())->recolectar($this->comercio, $this->ayer);
        $this->assertSame(1, $h['comparacion']['promedio_diario_30_dias']['dias_considerados']);
        $this->assertEquals(1.0, $h['comparacion']['promedio_diario_30_dias']['cantidad']);
        $this->assertEquals(1000.0, $h['comparacion']['promedio_diario_30_dias']['total']);

        $this->venta($this->ayer->copy()->subDays(4)->setTime(12, 0), [[$pinza, 1, 500]]);

        $h = (new RecolectorDia())->recolectar($this->comercio, $this->ayer);
        $this->assertSame(5, $h['comparacion']['promedio_diario_30_dias']['dias_considerados']);
        $this->assertEquals(round(2 / 5, 1), $h['comparacion']['promedio_diario_30_dias']['cantidad']);
        $this->assertEquals(300.0, $h['comparacion']['promedio_diario_30_dias']['total']);
    }

    /**
     * `imagen_url` sale de ArticleHelper::getFirstImage(), a través del morph map
     * 'article' que fuerza AppServiceProvider::boot() — no alcanza con que la clave
     * exista en null: tiene que resolver una foto real de punta a punta cuando el
     * artículo la tiene, en mas_vendidos (el caso puntual que reportó Lucas: la foto no
     * aparecía en "Lo más vendido") y en volvieron_a_venderse (misión
     * mostrador-fotos-y-modales, 21/9/2026, que le sumó imagen_url a esta lista).
     *
     * @group mostrador
     * @test
     */
    public function mas_vendidos_y_volvio_a_venderse_traen_la_foto_real_si_el_articulo_tiene_una()
    {
        $this->sembrar_el_dia();
        $this->imagen_de($this->s['destornillador'], 'https://cdn.test.local/storage/destornillador.webp');

        $h = (new RecolectorDia())->recolectar($this->comercio, $this->ayer);
        $a = $h['articulos'];

        // Destornillador es el más vendido (4 unidades) y también el único que volvió
        // a venderse (tras 80 días): las dos listas tienen que traer la misma foto.
        $this->assertSame($this->s['destornillador']->id, $a['mas_vendidos'][0]['article_id']);
        $this->assertSame('https://cdn.test.local/storage/destornillador.webp', $a['mas_vendidos'][0]['imagen_url']);

        $this->assertCount(1, $a['volvieron_a_venderse']);
        $this->assertSame($this->s['destornillador']->id, $a['volvieron_a_venderse'][0]['article_id']);
        $this->assertSame('https://cdn.test.local/storage/destornillador.webp', $a['volvieron_a_venderse'][0]['imagen_url']);

        // Pinza no tiene foto: sigue viajando null, no un string vacío ni un error.
        $this->assertNull($a['mas_vendidos'][2]['imagen_url']);
    }

    /**
     * @group mostrador
     * @test
     */
    public function los_articulos_mas_vendidos_los_que_volvieron_y_los_que_quedaron_en_cero()
    {
        $this->sembrar_el_dia();

        $h = (new RecolectorDia())->recolectar($this->comercio, $this->ayer);
        $a = $h['articulos'];

        // Destornillador 4, Martillo 3, Pinza 1 (por cantidad, desc).
        $this->assertSame(
            [$this->s['destornillador']->id, $this->s['martillo']->id, $this->s['pinza']->id],
            array_column($a['mas_vendidos'], 'article_id')
        );
        $this->assertEquals(3.0, $a['mas_vendidos'][1]['cantidad']);
        $this->assertEquals(3000.00, $a['mas_vendidos'][1]['total']);
        $this->assertSame('Martillo', $a['mas_vendidos'][1]['nombre']);
        $this->assertNull($a['mas_vendidos'][1]['imagen_url']);

        // Solo el destornillador volvió tras 80 días; el martillo se vendió hace 10.
        $this->assertCount(1, $a['volvieron_a_venderse']);
        $this->assertSame($this->s['destornillador']->id, $a['volvieron_a_venderse'][0]['article_id']);
        $this->assertSame(80, $a['volvieron_a_venderse'][0]['dias_sin_venderse']);
        $this->assertEquals(4.0, $a['volvieron_a_venderse'][0]['cantidad']);

        $this->assertSame([
            ['article_id' => $this->s['martillo']->id, 'nombre' => 'Martillo', 'stock' => 0.0, 'stock_minimo' => 5, 'sucursal' => null, 'imagen_url' => null],
        ], $a['quedaron_sin_stock']);

        // Martillo (0 < 5) y Cuchara (4 < 5).
        $this->assertSame(2, $a['bajo_minimo_total']);
    }

    /**
     * Con la preferencia de fecha apagada, Rendimiento (PerformanceHelper::set_sales) toma
     * la venta si created_at O terminada_at cae en el día: una venta cargada hace tres
     * días con la extensión check_sales y terminada ayer ES de ayer, y una cargada ayer
     * que todavía no se terminó no lo es. Los artículos siguen al mismo conjunto.
     *
     * @group mostrador
     * @test
     */
    public function una_venta_cargada_antes_y_terminada_ayer_entra_como_en_rendimiento()
    {
        $martillo = $this->articulo('Martillo', ['stock' => 0]);
        $pinza    = $this->articulo('Pinza', ['stock' => 3]);

        // Cargada ayer y terminada en el acto.
        $this->venta($this->ayer_a_las(10), [[$martillo, 2, 1000]], ['terminada_at' => $this->ayer_a_las(10)]);

        // Cargada hace tres días para chequear (check_sales) y terminada ayer a las 16.
        $this->venta($this->ayer->copy()->subDays(3)->setTime(9, 0), [[$pinza, 1, 500]], [
            'terminada_at' => $this->ayer_a_las(16),
        ]);

        // Cargada ayer y todavía sin terminar: no es una venta de ayer (ni de ningún día).
        $this->venta($this->ayer_a_las(12), [[$martillo, 5, 1000]], ['terminada' => 0, 'terminada_at' => null]);

        $h = (new RecolectorDia())->recolectar($this->comercio, $this->ayer);

        $this->assertSame(2, $h['ventas']['cantidad']);
        $this->assertEquals(2500.00, $h['ventas']['total']);

        $this->assertSame(
            [$martillo->id, $pinza->id],
            array_column($h['articulos']['mas_vendidos'], 'article_id')
        );
        $this->assertEquals(2.0, $h['articulos']['mas_vendidos'][0]['cantidad']);
        $this->assertEquals(1.0, $h['articulos']['mas_vendidos'][1]['cantidad']);
    }

    /**
     * Con users.fechar_ventas_por_fecha_de_entrega prendido, el criterio es el de
     * Sale::scopeEnRangoDeFechas: la venta es del día de su fecha de pedido
     * (COALESCE(fecha_entrega, created_at)), no del día en que se cargó.
     *
     * @group mostrador
     * @test
     */
    public function con_fecha_de_pedido_prendida_la_venta_es_del_dia_de_su_fecha_de_entrega()
    {
        $this->comercio->fechar_ventas_por_fecha_de_entrega = 1;
        $this->comercio->save();

        $martillo = $this->articulo('Martillo');
        $pinza    = $this->articulo('Pinza');
        $cuchara  = $this->articulo('Cuchara');

        // Cargada hace tres días, con entrega ayer: es de ayer.
        $this->venta($this->ayer->copy()->subDays(3)->setTime(9, 0), [[$pinza, 1, 500]], [
            'fecha_entrega' => $this->ayer_a_las(9),
        ]);

        // Cargada ayer, con entrega hoy: NO es de ayer.
        $this->venta($this->ayer_a_las(11), [[$cuchara, 4, 100]], [
            'fecha_entrega' => $this->ayer->copy()->addDay()->setTime(9, 0),
        ]);

        // Cargada ayer sin fecha de entrega: es de ayer.
        $this->venta($this->ayer_a_las(10), [[$martillo, 2, 1000]]);

        $h = (new RecolectorDia())->recolectar($this->comercio, $this->ayer);

        $this->assertSame(2, $h['ventas']['cantidad']);
        $this->assertEquals(2500.00, $h['ventas']['total']);
        $this->assertSame(
            [$martillo->id, $pinza->id],
            array_column($h['articulos']['mas_vendidos'], 'article_id')
        );
    }

    /**
     * En una cuenta con depósitos, lo que quedó en cero EN UNA SUCURSAL también se lista,
     * con el nombre de la sucursal; el artículo en cero global va una sola vez (sin
     * sucursal), y el que tiene stock en todas no aparece.
     *
     * @group mostrador
     * @test
     */
    public function en_una_cuenta_con_depositos_se_lista_lo_que_quedo_en_cero_por_sucursal()
    {
        $central = $this->sucursal('Casa central');
        $norte   = $this->sucursal('Norte');

        // Pinza: 5 en Central, 0 en Norte → global 5: sin stock solo en Norte.
        $pinza = $this->articulo('Pinza', ['stock' => 5]);
        $pinza->addresses()->attach($central->id, ['amount' => 5, 'stock_min' => 2]);
        $pinza->addresses()->attach($norte->id, ['amount' => 0, 'stock_min' => 3]);

        // Martillo: 0 y 0 → global 0: una sola entrada, sin sucursal.
        $martillo = $this->articulo('Martillo', ['stock' => 0, 'stock_min' => 4]);
        $martillo->addresses()->attach($central->id, ['amount' => 0, 'stock_min' => 2]);
        $martillo->addresses()->attach($norte->id, ['amount' => 0, 'stock_min' => 2]);

        // Cuchara: stock en las dos: no aparece.
        $cuchara = $this->articulo('Cuchara', ['stock' => 6]);
        $cuchara->addresses()->attach($central->id, ['amount' => 3]);
        $cuchara->addresses()->attach($norte->id, ['amount' => 3]);

        $this->venta($this->ayer_a_las(10), [[$pinza, 1, 500], [$martillo, 1, 1000], [$cuchara, 1, 100]], ['address_id' => $norte->id]);

        $h = (new RecolectorDia())->recolectar($this->comercio, $this->ayer);

        $this->assertSame([
            ['article_id' => $martillo->id, 'nombre' => 'Martillo', 'stock' => 0.0, 'stock_minimo' => 4, 'sucursal' => null, 'imagen_url' => null],
            ['article_id' => $pinza->id, 'nombre' => 'Pinza', 'stock' => 0.0, 'stock_minimo' => 3, 'sucursal' => 'Norte', 'imagen_url' => null],
        ], $h['articulos']['quedaron_sin_stock']);
    }

    /**
     * stock = null es "no controla stock" (InventoryPerformanceHelper lo cuenta como sin
     * stockear): un artículo así vendido ayer no "quedó sin stock".
     *
     * @group mostrador
     * @test
     */
    public function un_articulo_sin_control_de_stock_no_queda_sin_stock()
    {
        $balanza  = $this->articulo('Balanza', ['stock' => null]);
        $martillo = $this->articulo('Martillo', ['stock' => 0, 'stock_min' => 2]);

        $this->venta($this->ayer_a_las(10), [[$balanza, 1, 5000], [$martillo, 1, 1000]]);

        $h = (new RecolectorDia())->recolectar($this->comercio, $this->ayer);

        $this->assertSame([$martillo->id], array_column($h['articulos']['quedaron_sin_stock'], 'article_id'));
    }

    /**
     * @group mostrador
     * @test
     */
    public function las_cobranzas_con_la_deuda_y_su_variacion()
    {
        $this->sembrar_el_dia();

        $h = (new RecolectorDia())->recolectar($this->comercio, $this->ayer);
        $c = $h['cobranzas'];

        $this->assertSame([
            ['client_id' => $this->s['perez']->id, 'nombre' => 'Pérez', 'monto' => 300.0],
        ], $c['pagos_recibidos']);
        $this->assertEquals(300.00, $c['total_cobrado']);
        $this->assertEquals(1250.00, $c['deuda_clientes_total']);
        $this->assertEquals(-250.00, $c['deuda_clientes_variacion']);

        $this->assertSame([
            ['client_id' => $this->s['perez']->id, 'nombre' => 'Pérez', 'saldo' => 900.0, 'dias_sin_pagar' => 0],
            ['client_id' => $this->s['lopez']->id, 'nombre' => 'López', 'saldo' => 300.0, 'dias_sin_pagar' => 20],
            ['client_id' => $this->s['gomez']->id, 'nombre' => 'Gómez', 'saldo' => 50.0, 'dias_sin_pagar' => null],
        ], $c['clientes_con_mas_deuda']);
    }

    /**
     * @group mostrador
     * @test
     */
    public function compras_gastos_caja_tienda_y_resultado()
    {
        $this->sembrar_el_dia();

        $h = (new RecolectorDia())->recolectar($this->comercio, $this->ayer);

        $this->assertSame(['cantidad' => 1, 'total' => 5000.0, 'proveedores' => ['Acme']], $h['compras_y_gastos']['compras']);

        $this->assertSame([
            'cantidad'      => 2,
            'total'         => 1000.0,
            'por_categoria' => [
                ['categoria' => 'Servicios', 'total' => 700.0],
                ['categoria' => 'Café', 'total' => 300.0],
            ],
        ], $h['compras_y_gastos']['gastos']);

        $this->assertSame([
            'ingresos' => 4500.0,
            'egresos'  => 1000.0,
            'cierres'  => [['caja' => 'Principal', 'diferencia' => null]],
        ], $h['caja']);

        $this->assertSame(['tiene_tienda' => false, 'pedidos' => 0, 'total' => 0.0], $h['tienda']);

        // La fórmula completa de Rendimiento: vendido 4500 - devolución 200 - costo de lo
        // vendido 2300 + costo de lo devuelto 150 = 2150; menos los gastos (1000) = 1150.
        // Montos en pesos, no porcentajes.
        $this->assertSame(['ingresos_netos' => 2150.0, 'rentabilidad' => 1150.0], $h['resultado']);
    }

    /**
     * La fila de company_performances de ese día es una foto de cuando alguien abrió
     * Rendimiento (puede ser de media mañana): no se lee, se calcula siempre.
     *
     * @group mostrador
     * @test
     */
    public function la_fila_de_company_performances_no_se_lee()
    {
        $this->sembrar_el_dia();

        CompanyPerformance::create([
            'user_id'        => $this->comercio->id,
            'year'           => $this->ayer->year,
            'month'          => $this->ayer->month,
            'day'            => $this->ayer->day,
            'from_today'     => 1,
            'ingresos_netos' => 999.5,
            'rentabilidad'   => 555.25,
        ]);

        $h = (new RecolectorDia())->recolectar($this->comercio, $this->ayer);

        $this->assertSame(['ingresos_netos' => 2150.0, 'rentabilidad' => 1150.0], $h['resultado']);
    }

    /**
     * Misma regla de moneda que PerformanceHelper::procesar_gastos: moneda_id 0 y null son
     * pesos (solo 2 es dólares), y un gasto en cero no cuenta.
     *
     * @group mostrador
     * @test
     */
    public function los_gastos_con_moneda_cero_son_pesos_y_los_de_importe_cero_no_cuentan()
    {
        $cafe = ExpenseConcept::create(['num' => 1, 'name' => 'Café', 'user_id' => $this->comercio->id]);

        Expense::create(['user_id' => $this->comercio->id, 'expense_concept_id' => $cafe->id, 'amount' => 300, 'moneda_id' => 0, 'created_at' => $this->ayer_a_las(13)]);
        Expense::create(['user_id' => $this->comercio->id, 'expense_concept_id' => $cafe->id, 'amount' => 100, 'moneda_id' => 2, 'created_at' => $this->ayer_a_las(13)]);
        Expense::create(['user_id' => $this->comercio->id, 'expense_concept_id' => $cafe->id, 'amount' => 0, 'moneda_id' => 1, 'created_at' => $this->ayer_a_las(13)]);

        $h = (new RecolectorDia())->recolectar($this->comercio, $this->ayer);

        $this->assertSame(['cantidad' => 1, 'total' => 300.0, 'por_categoria' => [['categoria' => 'Café', 'total' => 300.0]]], $h['compras_y_gastos']['gastos']);
        $this->assertSame(['ingresos_netos' => 0.0, 'rentabilidad' => -300.0], $h['resultado']);
    }

    /**
     * Un pedido cancelado de la tienda no suma en el bloque `tienda` del día.
     *
     * @group mostrador
     * @test
     */
    public function un_pedido_cancelado_de_la_tienda_no_suma()
    {
        $this->comercio->online = 'https://ferreteria-mostrador.com.ar';
        $this->comercio->save();

        $confirmado = \App\Models\OrderStatus::where('name', 'Confirmado')->first() ?: \App\Models\OrderStatus::forceCreate(['name' => 'Confirmado']);
        $cancelado  = \App\Models\OrderStatus::where('name', 'Cancelado')->first() ?: \App\Models\OrderStatus::forceCreate(['name' => 'Cancelado']);

        $ana = \App\Models\Buyer::create(['name' => 'Ana', 'user_id' => $this->comercio->id]);

        foreach ([[3000, 'confirmed', $confirmado->id], [9999, 'canceled', $cancelado->id], [7777, 'canceled', $confirmado->id]] as $datos) {
            \App\Models\Order::create([
                'user_id' => $this->comercio->id, 'buyer_id' => $ana->id, 'total' => $datos[0], 'status' => $datos[1], 'deliver' => 0,
                'order_status_id' => $datos[2], 'created_at' => $this->ayer_a_las(10),
            ]);
        }

        $h = (new RecolectorDia())->recolectar($this->comercio, $this->ayer);

        $this->assertSame(['tiene_tienda' => true, 'pedidos' => 1, 'total' => 3000.0], $h['tienda']);
    }

    /**
     * @group mostrador
     * @test
     */
    public function un_comercio_sin_movimiento_devuelve_ceros_y_listas_vacias_nunca_omite_claves()
    {
        $h = (new RecolectorDia())->recolectar($this->comercio, $this->ayer);

        $this->assertSame(0, $h['ventas']['cantidad']);
        $this->assertEquals(0.0, $h['ventas']['total']);
        $this->assertNull($h['ventas']['ticket_promedio']);
        $this->assertSame([], $h['ventas']['por_sucursal']);
        $this->assertSame([], $h['articulos']['mas_vendidos']);
        $this->assertSame([], $h['cobranzas']['pagos_recibidos']);
        $this->assertNull($h['cobranzas']['deuda_clientes_variacion']);
        $this->assertSame([], $h['compras_y_gastos']['compras']['proveedores']);
        $this->assertSame([], $h['caja']['cierres']);

        foreach (['aplica', 'fecha', 'dia_semana', 'ventas', 'comparacion', 'articulos', 'cobranzas', 'compras_y_gastos', 'caja', 'tienda', 'resultado'] as $clave) {
            $this->assertArrayHasKey($clave, $h);
        }
    }
}
