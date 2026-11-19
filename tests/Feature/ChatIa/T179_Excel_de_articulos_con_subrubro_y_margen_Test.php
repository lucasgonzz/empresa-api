<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ArchivosDelAgenteIaHelper;
use App\Models\AiArchivo;
use App\Models\AiConversation;
use App\Models\Provider;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-diario-20261119 (rama A, M2) — el Excel de artículos con categoría, subcategoría, margen y
 * el precio de VENTA.
 *
 * 🔴 EL CASO REAL (Ferre Total, 18/11/2026, conv. 72, msgs 693-699): 145 esmaltes «MULT» de un proveedor, «y
 * agregá categoría y subcategoría y margen de ganancia». exportar_articulos_excel no tenía subrubro; el
 * asistente armó el archivo a mano con exportar_excel leyendo `price` (el precio MANUAL, vacío en los 145:
 * el de venta es `final_price`, calculado por costo + margen) y el Excel salió con el precio en 0.
 *
 * Lo que se cuida:
 *  - las columnas subrubro (la subcategoría) y margen_cargado (el % de ganancia cargado en el artículo);
 *  - la columna precio es el precio final de venta aunque `price` esté vacío;
 *  - 🔴 sin permiso de costos, el margen cargado no sale.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados, union types.
 *
 * @group chat-ia
 */
class T179_Excel_de_articulos_con_subrubro_y_margen_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio();

        Storage::fake(ArchivosDelAgenteIaHelper::DISCO);

        $proveedor = Provider::create(['name' => 'Pinturas ZZM2', 'user_id' => $this->dueno->id]);

        $rubro = DB::table('categories')->insertGetId(['name' => 'Pinturas y accesorios ZZM2', 'user_id' => $this->dueno->id, 'created_at' => now(), 'updated_at' => now()]);
        $subrubro = DB::table('sub_categories')->insertGetId(['name' => 'Esmalte Multiaccion ZZM2', 'category_id' => $rubro, 'user_id' => $this->dueno->id, 'created_at' => now(), 'updated_at' => now()]);

        // Como en Ferre Total: `price` (manual) vacío, `final_price` calculado por costo + 50 %.
        DB::table('articles')->insert([
            [
                'name' => 'ESMALTE AC. MULT. BLANCO X 1 LT ZZM2', 'user_id' => $this->dueno->id, 'status' => 'active', 'stock' => 4,
                'cost' => 7967.5, 'price' => null, 'final_price' => 14461.01, 'percentage_gain' => 50,
                'category_id' => $rubro, 'sub_category_id' => $subrubro,
                'provider_id' => $proveedor->id, 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'name' => 'ESMALTE AC. MULT. NEGRO X 1 LT ZZM2', 'user_id' => $this->dueno->id, 'status' => 'active', 'stock' => 2,
                'cost' => 7967.5, 'price' => null, 'final_price' => 14461.01, 'percentage_gain' => null,
                'category_id' => $rubro, 'sub_category_id' => null,
                'provider_id' => $proveedor->id, 'created_at' => now(), 'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Rubro, subrubro, precio de venta y margen cargado, por el despacho real.
     *
     * @test
     */
    public function el_excel_trae_categoria_subcategoria_margen_cargado_y_precio_de_venta()
    {
        $r = $this->exportar(['columnas' => ['nombre', 'rubro', 'subrubro', 'precio', 'margen_cargado']]);

        $this->assertTrue($r['ok'], json_encode($r));

        $hoja = $this->hoja_del_link($r['link']);

        $this->assertSame(
            ['Artículo', 'Rubro', 'Subrubro', 'Precio', 'Margen de ganancia cargado %'],
            [$hoja->getCell('A1')->getValue(), $hoja->getCell('B1')->getValue(), $hoja->getCell('C1')->getValue(), $hoja->getCell('D1')->getValue(), $hoja->getCell('E1')->getValue()]
        );

        $this->assertSame('ESMALTE AC. MULT. BLANCO X 1 LT ZZM2', $hoja->getCell('A2')->getValue());
        $this->assertSame('Pinturas y accesorios ZZM2', $hoja->getCell('B2')->getValue());
        $this->assertSame('Esmalte Multiaccion ZZM2', $hoja->getCell('C2')->getValue());
        $this->assertEquals(14461.01, $hoja->getCell('D2')->getValue(), 'El precio de venta (final_price), no el manual vacío.');
        $this->assertEquals(0.5, $hoja->getCell('E2')->getValue(), '50 % cargado (el Excel guarda la fracción).');

        $this->assertSame('ESMALTE AC. MULT. NEGRO X 1 LT ZZM2', $hoja->getCell('A3')->getValue());
        $this->assertNull($hoja->getCell('C3')->getValue(), 'Sin subrubro: celda vacía.');
        $this->assertNull($hoja->getCell('E3')->getValue(), 'Sin margen cargado: celda vacía, no 0.');
    }

    /**
     * Las columnas de siempre no se movieron: el default no suma las nuevas.
     *
     * @test
     */
    public function el_default_no_cambia()
    {
        $r = $this->exportar([]);

        $this->assertTrue($r['ok'], json_encode($r));

        $hoja = $this->hoja_del_link($r['link']);

        $this->assertSame('Ganancia por unidad', $hoja->getCell('K1')->getValue());
        $this->assertNull($hoja->getCell('L1')->getValue(), 'Nada después de la última columna de siempre.');
    }

    /**
     * 🔴 Sin el permiso de costos, el margen cargado no sale (el subrubro sí).
     *
     * @test
     */
    public function sin_permiso_de_costos_no_va_el_margen_cargado()
    {
        $empleado = User::create([
            'name'     => 'Empleado ZZM2',
            'email'    => 'zzm2-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->dueno->id,
        ]);

        $conversation = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $empleado->id]);

        $r = $this->exportar(['columnas' => ['nombre', 'subrubro', 'margen_cargado']], $conversation);

        $this->assertTrue($r['ok'], json_encode($r));

        $hoja = $this->hoja_del_link($r['link']);

        $this->assertSame('Subrubro', $hoja->getCell('B1')->getValue());
        $this->assertNull($hoja->getCell('C1')->getValue(), 'La columna de margen no viaja.');
    }

    // ---------------------------------------------------------------------

    /**
     * Ejecuta exportar_articulos_excel por el despacho real.
     *
     * @param  array  $input
     * @param  AiConversation|null  $conversation
     * @return array
     */
    protected function exportar(array $input, $conversation = null)
    {
        list($conversation, $assistant) = $this->vuelta('pasame los MULT con categoría, subcategoría y margen en un Excel', $conversation);

        $resultados = (new AsistenteIaService())->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_m2_' . uniqid(),
            'name'  => 'exportar_articulos_excel',
            'input' => array_merge(['filtros' => [['campo' => 'proveedor', 'operador' => 'igual', 'valor' => 'Pinturas ZZM2']]], $input),
        ]], $conversation, $assistant);

        $this->assertArrayNotHasKey('is_error', $resultados[0], $resultados[0]['content']);

        return json_decode($resultados[0]['content'], true);
    }

    /**
     * La hoja del .xlsx que dejó el link.
     *
     * @param  string  $link
     * @return \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
     */
    protected function hoja_del_link($link)
    {
        $uuid = basename((string) parse_url($link, PHP_URL_PATH));

        $archivo = AiArchivo::where('uuid', $uuid)->firstOrFail();

        return IOFactory::load(Storage::disk(ArchivosDelAgenteIaHelper::DISCO)->path($archivo->path))->getActiveSheet();
    }
}
