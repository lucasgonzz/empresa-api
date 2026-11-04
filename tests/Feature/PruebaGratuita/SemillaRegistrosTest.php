<?php

namespace Tests\Feature\PruebaGratuita;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\SemillaRegistroHelper;
use App\Models\Article;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Client;
use App\Models\Pending;
use App\Models\Provider;
use App\Models\SemillaRegistro;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * La marca de lo sembrado y su borrado (misión prueba-experiencia-empresa, 3/11/2026).
 *
 *  1. `abrir_lote()` + `cerrar_lote()` registran en `semilla_registros` todo id nuevo de las tablas de negocio;
 *     un lote adentro de otro no abre; el Client del lead se saca con sus cuentas.
 *  2. `borrar_lo_sembrado()` se lleva lo del lote (fuera de la base, no a la papelera) y deja intacto lo que
 *     el negocio creó después; al terminar, `semilla_presente()` es false.
 *  3. C5: `operaciones_mes` cuenta lo registrado en el mes y excluye lo sembrado.
 */
class SemillaRegistrosTest extends EmpresaTestCase
{
    const CLAVE_DE_ADMIN = 'clave-de-admin-del-test-de-semilla-2a9c';

    /** @var \App\Models\User */
    protected $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        // El modo prueba existe solo con FOR_USER=prueba (lo escribe el script de alta del admin).
        config(['app.FOR_USER' => 'prueba']);

        PruebaHelper::olvidar_cache();
        SemillaRegistroHelper::descartar_lote();

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        config(['app.USER_ID' => $this->dueno->id]);
        config(['services.admin_api.api_key' => self::CLAVE_DE_ADMIN]);
    }

    protected function tearDown(): void
    {
        SemillaRegistroHelper::descartar_lote();
        PruebaHelper::olvidar_cache();
        parent::tearDown();
    }

    private function crear_articulo($nombre)
    {
        return Article::create(['name' => $nombre, 'user_id' => $this->dueno->id, 'status' => 'active']);
    }

    private function crear_cliente($nombre)
    {
        $client = Client::create(['name' => $nombre, 'user_id' => $this->dueno->id]);
        CreditAccountHelper::crear_credit_accounts('client', $client->id);

        return $client;
    }

    public function test_el_lote_marca_lo_que_aparece_entre_abrir_y_cerrar()
    {
        $this->assertTrue(SemillaRegistroHelper::abrir_lote($this->dueno->id, 'test'));
        $this->assertFalse(SemillaRegistroHelper::abrir_lote($this->dueno->id, 'otro'), 'Un lote adentro de otro no abre.');

        $articulo = $this->crear_articulo('Sembrado por el test');
        $cliente = $this->crear_cliente('Cliente sembrado por el test');
        $proveedor = Provider::create(['name' => 'Proveedor sembrado', 'user_id' => $this->dueno->id]);

        $marcadas = SemillaRegistroHelper::cerrar_lote();

        $this->assertGreaterThanOrEqual(4, $marcadas, 'Artículo, cliente, sus cuentas y proveedor.');
        $this->assertContains($articulo->id, SemillaRegistroHelper::ids($this->dueno->id, 'articles'));
        $this->assertContains($cliente->id, SemillaRegistroHelper::ids($this->dueno->id, 'clients'));
        $this->assertContains($proveedor->id, SemillaRegistroHelper::ids($this->dueno->id, 'providers'));
        $this->assertNotEmpty(SemillaRegistroHelper::ids($this->dueno->id, 'credit_accounts'));
        $this->assertTrue(SemillaRegistroHelper::semilla_presente($this->dueno->id));
        $this->assertFalse(SemillaRegistroHelper::hay_lote_abierto());

        $fila = SemillaRegistro::where('tabla', 'articles')->where('registro_id', $articulo->id)->first();
        $this->assertStringStartsWith('test-', $fila->lote);
        $this->assertSame($this->dueno->id, (int) $fila->owner_id);
    }

    public function test_excluir_cliente_lo_saca_de_la_marca_con_sus_cuentas()
    {
        SemillaRegistroHelper::abrir_lote($this->dueno->id, 'test');
        $lead = $this->crear_cliente('El lead');
        SemillaRegistroHelper::cerrar_lote();

        SemillaRegistroHelper::excluir_cliente($lead->id);

        $this->assertNotContains($lead->id, SemillaRegistroHelper::ids($this->dueno->id, 'clients'));
        $cuentas = DB::table('credit_accounts')->where('model_name', 'client')->where('model_id', $lead->id)->pluck('id')->all();
        $this->assertNotEmpty($cuentas);
        foreach ($cuentas as $cuenta_id) {
            $this->assertNotContains((int) $cuenta_id, SemillaRegistroHelper::ids($this->dueno->id, 'credit_accounts'));
        }
    }

    public function test_cerrar_sin_lote_abierto_no_marca_nada()
    {
        $this->assertSame(0, SemillaRegistroHelper::cerrar_lote());
    }

    public function test_borrar_lo_sembrado_se_lleva_el_lote_y_deja_lo_que_el_negocio_creo_despues()
    {
        SemillaRegistroHelper::abrir_lote($this->dueno->id, 'test');
        $articulo_sembrado = $this->crear_articulo('Artículo de ejemplo');
        $cliente_sembrado = $this->crear_cliente('Cliente de ejemplo');
        $proveedor_sembrado = Provider::create(['name' => 'Proveedor de ejemplo', 'user_id' => $this->dueno->id]);
        $categoria_sembrada = Category::create(['name' => 'Categoría de ejemplo', 'user_id' => $this->dueno->id]);
        $presupuesto_sembrado = Budget::create(['client_id' => $cliente_sembrado->id, 'user_id' => $this->dueno->id]);
        $pendiente_sembrado = Pending::create(['detalle' => 'Pendiente de ejemplo', 'user_id' => $this->dueno->id]);
        $lead = $this->crear_cliente('El lead');
        SemillaRegistroHelper::cerrar_lote();
        SemillaRegistroHelper::excluir_cliente($lead->id);

        // Lo que el negocio cargó DESPUÉS de la siembra.
        $articulo_real = $this->crear_articulo('Artículo real del negocio');
        $cliente_real = $this->crear_cliente('Cliente real del negocio');

        $cuentas_del_lead = DB::table('credit_accounts')->where('model_name', 'client')->where('model_id', $lead->id)->count();
        $this->assertGreaterThan(0, $cuentas_del_lead);

        $resumen = SemillaRegistroHelper::borrar_lo_sembrado($this->dueno->id);

        $this->assertSame(1, $resumen['articles']);
        $this->assertSame(1, $resumen['clients']);
        $this->assertSame(1, $resumen['providers']);
        $this->assertSame(1, $resumen['categories']);
        $this->assertSame(1, $resumen['budgets']);
        $this->assertSame(1, $resumen['pendings']);

        // Fuera de la base, no en la papelera.
        $this->assertSame(0, DB::table('articles')->where('id', $articulo_sembrado->id)->count());
        $this->assertSame(0, DB::table('clients')->where('id', $cliente_sembrado->id)->count());
        $this->assertSame(0, DB::table('providers')->where('id', $proveedor_sembrado->id)->count());
        $this->assertSame(0, DB::table('categories')->where('id', $categoria_sembrada->id)->count());
        $this->assertSame(0, DB::table('budgets')->where('id', $presupuesto_sembrado->id)->count());
        $this->assertSame(0, DB::table('pendings')->where('id', $pendiente_sembrado->id)->count());
        $this->assertSame(0, DB::table('credit_accounts')->where('model_name', 'client')->where('model_id', $cliente_sembrado->id)->count());

        // Lo real y el lead quedan, con sus cuentas.
        $this->assertNotNull(Article::find($articulo_real->id));
        $this->assertNotNull(Client::find($cliente_real->id));
        $this->assertNotNull(Client::find($lead->id));
        $this->assertSame($cuentas_del_lead, DB::table('credit_accounts')->where('model_name', 'client')->where('model_id', $lead->id)->count());

        // El fixture de la suite no se tocó.
        $this->assertNotNull($this->articulo_del_fixture());

        $this->assertFalse(SemillaRegistroHelper::semilla_presente($this->dueno->id));
    }

    /**
     * Política del chequeo (3/11/2026): lo real hecho CON lo de ejemplo se va; lo real con datos propios queda.
     */
    public function test_las_operaciones_reales_hechas_con_lo_sembrado_se_borran_y_las_propias_quedan()
    {
        $ahora = Carbon::now();

        SemillaRegistroHelper::abrir_lote($this->dueno->id, 'test');
        $articulo_sembrado = $this->crear_articulo('Artículo de ejemplo');
        $cliente_sembrado = $this->crear_cliente('Cliente de ejemplo');
        SemillaRegistroHelper::cerrar_lote();

        $articulo_propio = $this->crear_articulo('Artículo propio');
        $cliente_propio = $this->crear_cliente('Cliente propio');

        // Tres ventas reales (fuera del lote): dos tocan lo de ejemplo, una es toda propia.
        $venta_con_articulo_de_ejemplo = DB::table('sales')->insertGetId(['user_id' => $this->dueno->id, 'client_id' => $cliente_propio->id, 'created_at' => $ahora, 'updated_at' => $ahora]);
        DB::table('article_sale')->insert(['sale_id' => $venta_con_articulo_de_ejemplo, 'article_id' => $articulo_sembrado->id, 'amount' => 1, 'price' => 10]);
        $venta_a_cliente_de_ejemplo = DB::table('sales')->insertGetId(['user_id' => $this->dueno->id, 'client_id' => $cliente_sembrado->id, 'created_at' => $ahora, 'updated_at' => $ahora]);
        DB::table('article_sale')->insert(['sale_id' => $venta_a_cliente_de_ejemplo, 'article_id' => $articulo_propio->id, 'amount' => 1, 'price' => 10]);
        $venta_propia = DB::table('sales')->insertGetId(['user_id' => $this->dueno->id, 'client_id' => $cliente_propio->id, 'created_at' => $ahora, 'updated_at' => $ahora]);
        DB::table('article_sale')->insert(['sale_id' => $venta_propia, 'article_id' => $articulo_propio->id, 'amount' => 1, 'price' => 10]);
        // Un presupuesto real al cliente de ejemplo y un movimiento de stock real del artículo de ejemplo.
        $presupuesto = Budget::create(['client_id' => $cliente_sembrado->id, 'user_id' => $this->dueno->id]);
        $movimiento = DB::table('stock_movements')->insertGetId(['article_id' => $articulo_sembrado->id, 'user_id' => $this->dueno->id, 'amount' => 1, 'created_at' => $ahora, 'updated_at' => $ahora]);

        $colgadas = SemillaRegistroHelper::operaciones_reales_colgadas($this->dueno->id);
        $this->assertEqualsCanonicalizing([$venta_con_articulo_de_ejemplo, $venta_a_cliente_de_ejemplo], $colgadas['sales']);
        $this->assertSame([$presupuesto->id], $colgadas['budgets']);
        $this->assertSame([$movimiento], $colgadas['stock_movements']);

        $resumen = SemillaRegistroHelper::borrar_lo_sembrado($this->dueno->id);

        $this->assertSame(4, $resumen['reales_colgadas']);
        $this->assertSame(0, DB::table('sales')->whereIn('id', [$venta_con_articulo_de_ejemplo, $venta_a_cliente_de_ejemplo])->count(), 'Las ventas hechas con lo de ejemplo se van.');
        $this->assertSame(0, DB::table('article_sale')->whereIn('sale_id', [$venta_con_articulo_de_ejemplo, $venta_a_cliente_de_ejemplo])->count(), 'Con sus renglones.');
        $this->assertSame(0, DB::table('budgets')->where('id', $presupuesto->id)->count());
        $this->assertSame(0, DB::table('stock_movements')->where('id', $movimiento)->count());
        $this->assertSame(1, DB::table('sales')->where('id', $venta_propia)->count(), 'La venta propia queda.');
        $this->assertSame(1, DB::table('article_sale')->where('sale_id', $venta_propia)->count());
        $this->assertNotNull(Article::find($articulo_propio->id));
        $this->assertNotNull(Client::find($cliente_propio->id));

        DB::table('article_sale')->where('sale_id', $venta_propia)->delete();
        DB::table('sales')->where('id', $venta_propia)->delete();
    }

    /**
     * Política del chequeo (3/11/2026): la caja con movimientos reales conserva su saldo; la que solo tenía
     * movimientos de ejemplo vuelve a cero.
     */
    public function test_la_caja_con_movimientos_reales_conserva_su_saldo_y_la_de_ejemplo_vuelve_a_cero()
    {
        $ahora = Carbon::now();
        $caja_mixta = \App\Models\Caja::create(['num' => 9001, 'name' => 'Caja mixta del test', 'user_id' => $this->dueno->id, 'saldo' => 1234, 'abierta' => 0]);
        $caja_de_ejemplo = \App\Models\Caja::create(['num' => 9002, 'name' => 'Caja solo ejemplo del test', 'user_id' => $this->dueno->id, 'saldo' => 999, 'abierta' => 0]);

        // Aperturas reales (fuera del lote): los movimientos cuelgan de una apertura siempre.
        $apertura_mixta = DB::table('apertura_cajas')->insertGetId(['caja_id' => $caja_mixta->id, 'saldo_apertura' => 0, 'apertura_employee_id' => $this->dueno->id, 'total_ingresos' => 0, 'total_egresos' => 0, 'created_at' => $ahora, 'updated_at' => $ahora]);
        $apertura_ejemplo = DB::table('apertura_cajas')->insertGetId(['caja_id' => $caja_de_ejemplo->id, 'saldo_apertura' => 0, 'apertura_employee_id' => $this->dueno->id, 'total_ingresos' => 0, 'total_egresos' => 0, 'created_at' => $ahora, 'updated_at' => $ahora]);

        SemillaRegistroHelper::abrir_lote($this->dueno->id, 'test');
        DB::table('movimiento_cajas')->insert(['caja_id' => $caja_mixta->id, 'apertura_caja_id' => $apertura_mixta, 'ingreso' => 100, 'saldo' => 100, 'created_at' => $ahora, 'updated_at' => $ahora]);
        DB::table('movimiento_cajas')->insert(['caja_id' => $caja_de_ejemplo->id, 'apertura_caja_id' => $apertura_ejemplo, 'ingreso' => 999, 'saldo' => 999, 'created_at' => $ahora, 'updated_at' => $ahora]);
        SemillaRegistroHelper::cerrar_lote();

        $movimiento_real = DB::table('movimiento_cajas')->insertGetId(['caja_id' => $caja_mixta->id, 'apertura_caja_id' => $apertura_mixta, 'ingreso' => 1134, 'saldo' => 1234, 'created_at' => $ahora, 'updated_at' => $ahora]);

        SemillaRegistroHelper::borrar_lo_sembrado($this->dueno->id);

        $this->assertSame(1234.0, (float) DB::table('cajas')->where('id', $caja_mixta->id)->value('saldo'), 'Con un movimiento real, el saldo queda.');
        $this->assertSame(1, DB::table('movimiento_cajas')->where('caja_id', $caja_mixta->id)->count());
        $this->assertSame($movimiento_real, (int) DB::table('movimiento_cajas')->where('caja_id', $caja_mixta->id)->value('id'));
        $this->assertSame(0.0, (float) DB::table('cajas')->where('id', $caja_de_ejemplo->id)->value('saldo'), 'Solo con movimientos de ejemplo, vuelve a cero.');
        $this->assertSame(0, DB::table('movimiento_cajas')->where('caja_id', $caja_de_ejemplo->id)->count());

        DB::table('movimiento_cajas')->where('id', $movimiento_real)->delete();
        DB::table('apertura_cajas')->whereIn('id', [$apertura_mixta, $apertura_ejemplo])->delete();
        DB::table('cajas')->whereIn('id', [$caja_mixta->id, $caja_de_ejemplo->id])->delete();
    }

    /**
     * Lucas: «borra todo». Empleados, vendedores y propiedades de ejemplo se van; el dueño nunca.
     */
    public function test_empleados_vendedores_y_propiedades_de_ejemplo_se_van_y_el_dueno_queda()
    {
        SemillaRegistroHelper::abrir_lote($this->dueno->id, 'test');
        $empleado = User::create(['name' => 'Empleado de ejemplo', 'owner_id' => $this->dueno->id, 'email' => 'empleado-' . uniqid() . '@test.local', 'password' => bcrypt('x')]);
        $vendedor = \App\Models\Seller::create(['num' => 9001, 'name' => 'Vendedor de ejemplo', 'user_id' => $this->dueno->id]);
        $tipo = \App\Models\ArticlePropertyType::create(['num' => 9001, 'name' => 'Talle de ejemplo', 'user_id' => $this->dueno->id]);
        SemillaRegistroHelper::cerrar_lote();

        $this->assertContains($empleado->id, SemillaRegistroHelper::ids($this->dueno->id, 'users'));
        $this->assertNotContains($this->dueno->id, SemillaRegistroHelper::ids($this->dueno->id, 'users'), 'El dueño nunca entra en la marca.');

        SemillaRegistroHelper::borrar_lo_sembrado($this->dueno->id);

        $this->assertSame(0, DB::table('users')->where('id', $empleado->id)->count());
        $this->assertSame(0, DB::table('sellers')->where('id', $vendedor->id)->count());
        $this->assertSame(0, DB::table('article_property_types')->where('id', $tipo->id)->count());
        $this->assertNotNull(User::find($this->dueno->id));
    }

    public function test_borrar_sin_marcas_no_hace_nada()
    {
        $antes = (int) DB::table('articles')->count();

        $this->assertSame([], SemillaRegistroHelper::borrar_lo_sembrado($this->dueno->id));
        $this->assertSame($antes, (int) DB::table('articles')->count());
    }

    public function test_c5_operaciones_mes_cuenta_lo_del_mes_y_excluye_lo_sembrado()
    {
        $ahora = Carbon::now();
        $antes = $this->withHeader('X-Admin-Api-Key', self::CLAVE_DE_ADMIN)->getJson('/api/admin-sync/mensualidad-info')->json('operaciones_mes');

        $this->assertSame($ahora->format('Y-m'), $antes['mes']);
        $this->assertSame($antes['ventas'] + $antes['compras'] + $antes['gastos'], $antes['total']);

        $venta_real = DB::table('sales')->insertGetId(['user_id' => $this->dueno->id, 'created_at' => $ahora, 'updated_at' => $ahora]);
        $venta_sembrada = DB::table('sales')->insertGetId(['user_id' => $this->dueno->id, 'created_at' => $ahora, 'updated_at' => $ahora]);
        $venta_vieja = DB::table('sales')->insertGetId(['user_id' => $this->dueno->id, 'created_at' => $ahora->copy()->subMonths(2), 'updated_at' => $ahora]);
        DB::table('expenses')->insert(['user_id' => $this->dueno->id, 'created_at' => $ahora, 'updated_at' => $ahora]);
        SemillaRegistro::create(['owner_id' => $this->dueno->id, 'tabla' => 'sales', 'registro_id' => $venta_sembrada, 'lote' => 'test', 'created_at' => $ahora]);

        $despues = $this->withHeader('X-Admin-Api-Key', self::CLAVE_DE_ADMIN)->getJson('/api/admin-sync/mensualidad-info')->json('operaciones_mes');

        $this->assertSame($antes['ventas'] + 1, $despues['ventas'], 'Suma la venta real del mes; no la sembrada ni la de hace dos meses.');
        $this->assertSame($antes['gastos'] + 1, $despues['gastos']);
        $this->assertSame($antes['compras'], $despues['compras']);
        $this->assertSame($despues['ventas'] + $despues['compras'] + $despues['gastos'], $despues['total']);

        DB::table('sales')->whereIn('id', [$venta_real, $venta_sembrada, $venta_vieja])->delete();
    }

    private function articulo_del_fixture()
    {
        return $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);
    }
}
