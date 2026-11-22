<?php

namespace Tests\Feature\DemandaSinStock;

use App\Http\Controllers\Helpers\CatalogoDeDatosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\EsquemaDeDatosIaHelper;
use App\Models\Address;
use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión demanda-sin-stock-vender — archivo 4: el asistente de IA puede leer
 * los frenos de Vender (criterio CA10).
 *
 * El catálogo del asistente se deriva del esquema: toda tabla con user_id
 * entra sola, pero acá se pide que `unmet_demand` sea una entidad CURADA
 * (descripción y campos pensados), ubicada en "proveedores y compras", y que
 * lo cancelado por el vendedor jamás le llegue. Mismo camino de lectura que
 * tests/Feature/ChatIa/31_Consulta_generica_omnisciente_Test.php.
 */
class Asistente_ia_Test extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // El catálogo se cachea 24 h: se arma de nuevo contra el esquema de esta base.
        EsquemaDeDatosIaHelper::olvidar();
    }

    protected function tearDown(): void
    {
        // No dejar un catálogo armado dentro de la transacción para otros tests.
        EsquemaDeDatosIaHelper::olvidar();

        parent::tearDown();
    }

    /**
     * CA10: la entidad existe, es curada, vive en compras, expone los campos
     * útiles (y no el user_id) y trae la condición fija que oculta lo
     * cancelado.
     *
     * @group demanda-sin-stock
     * @test
     */
    public function la_tabla_es_curada_y_va_en_compras()
    {
        $declaracion = EsquemaDeDatosIaHelper::declaracion('unmet_demand');

        $this->assertNotNull($declaracion, 'unmet_demand tiene que existir como entidad del asistente.');
        $this->assertSame('unmet_demands', $declaracion['tabla']);
        $this->assertTrue($declaracion['curada'], 'Tiene que ser una entrada curada, no derivada.');
        $this->assertSame('proveedores y compras', $declaracion['modulo']);

        foreach (['motivo', 'cantidad_pedida', 'stock_disponible', 'article_id'] as $campo) {
            $this->assertArrayHasKey($campo, $declaracion['campos'], 'Falta el campo ' . $campo);
        }

        $this->assertArrayNotHasKey('user_id', $declaracion['campos'], 'El user_id es el scope de dueño, no un campo.');

        $this->assertContains(
            'unmet_demands.canceled_at IS NULL',
            array_column($declaracion['condiciones_fijas'], 'sql'),
            'Lo que el vendedor canceló no se le muestra al asistente.'
        );
    }

    /**
     * CA10: con una fila cancelada y una vigente, la consulta del asistente
     * para el dueño devuelve sólo la vigente; y un dueño ajeno no ve nada.
     *
     * @group demanda-sin-stock
     * @test
     */
    public function lo_cancelado_no_se_lee()
    {
        $duenio = User::create([
            'name'     => 'Comercio asistente D4',
            'email'    => 'demanda-d4-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $otro = User::create([
            'name'     => 'Otro comercio asistente D4',
            'email'    => 'demanda-d4-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $sucursal = Address::create(['street' => 'Sucursal D4', 'user_id' => $duenio->id]);
        $mecha = Article::create(['name' => 'Mecha 8', 'user_id' => $duenio->id]);

        $base = [
            'user_id'          => $duenio->id,
            'employee_id'      => $duenio->id,
            'article_id'       => $mecha->id,
            'address_id'       => $sucursal->id,
            'motivo'           => 'sin_stock',
            'cantidad_pedida'  => null,
            'stock_disponible' => 0,
            'canceled_at'      => null,
            'created_at'       => now(),
            'updated_at'       => now(),
        ];

        // Una vigente y una cancelada por el vendedor.
        DB::table('unmet_demands')->insert($base);
        DB::table('unmet_demands')->insert(array_merge($base, ['canceled_at' => now()]));

        $resultado = CatalogoDeDatosIaHelper::consultar_datos($duenio->id, 'unmet_demand', []);

        $this->assertArrayNotHasKey('error', $resultado, 'La entidad tiene que poder consultarse.');
        $this->assertEquals(1, $resultado['registros_encontrados'], 'La fila cancelada no le llega al asistente.');

        // Otro comercio no ve las filas de este.
        $ajeno = CatalogoDeDatosIaHelper::consultar_datos($otro->id, 'unmet_demand', []);

        $this->assertArrayNotHasKey('error', $ajeno);
        $this->assertEquals(0, $ajeno['registros_encontrados']);
    }
}
