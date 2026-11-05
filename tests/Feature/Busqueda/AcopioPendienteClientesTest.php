<?php

namespace Tests\Feature\Busqueda;

use App\Models\Client;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;

class AcopioPendienteClientesTest extends BusquedaTestCase
{
    private $dueno;
    private $extension;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::factory()->create();
        $this->extension = ExtencionEmpresa::where('slug', 'acopios')->firstOrFail();
        $this->dueno->extencions()->attach($this->extension->id);
        $this->actingAs($this->dueno, 'web');
    }

    public function test_el_listado_indica_solo_acopios_con_unidades_pendientes(): void
    {
        $pendiente = $this->cliente('Retiro parcial', 1);
        $entregado = $this->cliente('Retiro completo', 2);
        $venta_comun = $this->cliente('Venta sin acopio', 3);
        $sin_ventas = $this->cliente('Sin ventas', 4);
        $sin_entregas = $this->cliente('Retiro por empezar', 5);

        $this->venta($pendiente, 2.25);
        $this->venta($pendiente, 3);
        $this->venta($entregado, 3);
        $this->venta($venta_comun, null, ['en_acopio' => false]);
        $this->venta($sin_entregas, null);

        DB::enableQueryLog();
        $respuesta = $this->buscar()->assertOk();
        $consultas_de_acopio = array_filter(DB::getQueryLog(), function ($consulta) {
            return strpos($consulta['query'], 'article_sale') !== false;
        });
        DB::disableQueryLog();

        $this->assertCount(1, $consultas_de_acopio);
        $esperado = [
            $pendiente->id => true,
            $entregado->id => false,
            $venta_comun->id => false,
            $sin_ventas->id => false,
            $sin_entregas->id => true,
        ];
        $this->assertSame($esperado, array_column($respuesta->json('models.data'), 'tiene_acopio_pendiente', 'id'));
        $listado = $this->getJson('api/client')->assertOk();
        $this->assertSame($esperado, array_column($listado->json('models.data'), 'tiene_acopio_pendiente', 'id'));

        $filtro_anterior = $this->postJson('api/search/client/null/1', [
            'filters' => [],
            'clientes_acopio_pendiente' => true,
        ])->assertOk();
        $this->assertSame($esperado, array_column($filtro_anterior->json('data'), 'tiene_acopio_pendiente', 'id'));
    }

    public function test_el_indicador_respeta_busqueda_y_paginacion(): void
    {
        $primero = $this->cliente('Retiros del corralón A', 1);
        $segundo = $this->cliente('Retiros del corralón B', 2);
        $this->cliente('Otro cliente', 3);
        $this->venta($segundo, 0);

        $filtros = ['query_value' => 'Retiros', 'props' => ['name'], 'per_page' => 1];
        $this->buscar($filtros)->assertOk()
            ->assertJsonPath('models.total', 2)
            ->assertJsonPath('models.current_page', 1)
            ->assertJsonPath('models.data.0.id', $primero->id)
            ->assertJsonPath('models.data.0.tiene_acopio_pendiente', false);
        $this->buscar($filtros, 2)->assertOk()
            ->assertJsonPath('models.total', 2)
            ->assertJsonPath('models.current_page', 2)
            ->assertJsonPath('models.data.0.id', $segundo->id)
            ->assertJsonPath('models.data.0.tiene_acopio_pendiente', true);
    }

    public function test_no_se_cuentan_ventas_borradas_ni_de_otro_comercio(): void
    {
        $cliente = $this->cliente('Cliente sin retiro vigente', 1);
        $otro_comercio = User::factory()->create();
        $this->venta($cliente, 0, ['deleted_at' => now()]);
        $this->venta($cliente, 0, ['user_id' => $otro_comercio->id]);
        Client::create(['user_id' => $otro_comercio->id, 'name' => 'Cliente de otro comercio']);

        $this->buscar()->assertOk()
            ->assertJsonPath('models.total', 1)
            ->assertJsonPath('models.data.0.id', $cliente->id)
            ->assertJsonPath('models.data.0.tiene_acopio_pendiente', false);
    }

    public function test_el_dato_requiere_extension_permiso_y_opt_in_en_el_buscador(): void
    {
        $cliente = $this->cliente('Cliente con retiro', 1);
        $this->venta($cliente, 0);
        $empleado = User::factory()->create(['owner_id' => $this->dueno->id, 'admin_access' => false]);
        $permiso_id = DB::table('permission_empresas')->where('slug', 'client.index')->value('id');
        if (!$permiso_id) {
            $permiso_id = DB::table('permission_empresas')->insertGetId([
                'name' => 'Ver el listado de clientes',
                'slug' => 'client.index',
            ]);
        }

        $this->actingAs($empleado, 'web');
        $sin_permiso = $this->buscar()->assertOk()->json('models.data.0');
        $this->assertArrayNotHasKey('tiene_acopio_pendiente', $sin_permiso);

        $empleado->permissions()->attach($permiso_id);
        $this->buscar()->assertOk()->assertJsonPath('models.data.0.tiene_acopio_pendiente', true);

        $sin_marca = $this->postJson('api/global-search/client', $this->payload_global_search())
            ->assertOk()->json('models.data.0');
        $this->assertArrayNotHasKey('tiene_acopio_pendiente', $sin_marca);

        $this->dueno->extencions()->detach($this->extension->id);
        $sin_extension = $this->buscar()->assertOk()->json('models.data.0');
        $this->assertArrayNotHasKey('tiene_acopio_pendiente', $sin_extension);
    }

    private function cliente(string $nombre, int $dias): Client
    {
        $cliente = Client::create(['name' => $nombre, 'user_id' => $this->dueno->id]);
        $cliente->created_at = now()->subDays($dias);
        $cliente->save();

        return $cliente;
    }

    private function venta(Client $cliente, ?float $entregadas, array $atributos = []): void
    {
        $venta_id = DB::table('sales')->insertGetId(array_replace([
            'user_id' => $this->dueno->id,
            'client_id' => $cliente->id,
            'en_acopio' => true,
        ], $atributos));

        DB::table('article_sale')->insert([
            'article_id' => $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA)->id,
            'sale_id' => $venta_id,
            'amount' => 3,
            'delivered_amount' => $entregadas,
        ]);
    }

    private function buscar(array $filtros = [], int $pagina = 1)
    {
        return $this->postJson('api/global-search/client?page='.$pagina, $this->payload_global_search(
            array_merge(['clientes_acopio_pendiente' => true], $filtros)
        ));
    }
}
