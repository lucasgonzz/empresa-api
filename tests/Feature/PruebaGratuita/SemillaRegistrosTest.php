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
