<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ArchivosDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ValorizacionDeStockIaHelper;
use App\Models\AiArchivo;
use App\Models\AiConversation;
use App\Models\Article;
use App\Models\Provider;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-diario-20261117 (parte B, M1) — el Excel de artículos trae el MARGEN.
 *
 * EL CASO REAL (Truvari, conv. 36): «costo, precio de venta y qué margen de ganancia tiene cada artículo» y el
 * Excel salió sin margen. Lo que cuida este archivo:
 *  - las columnas «Margen % sobre costo» y «Ganancia por unidad», con el criterio del sistema para la ganancia
 *    (precio NETO de IVA e impuestos − costo; ArticlePricesHelper::quitar_iva_y_sale_taxes);
 *  - un artículo sin costo deja las celdas VACÍAS, no en 0;
 *  - 🔴 sin el permiso de costos, ni margen ni ganancia.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados, union types.
 *
 * @group chat-ia
 */
class T156_Margen_en_el_excel_de_articulos_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    /** @var Provider */
    protected $proveedor;

    protected function setUp(): void
    {
        parent::setUp();

        // Responsable Inscripto con la condición fiscal migrada: el IVA se suma al vender, no va al costo.
        $this->armar_negocio([
            'usar_condicion_fiscal_en_costeo' => 1,
            'condicion_iva_precios'           => User::CONDICION_RRII,
        ]);

        Storage::fake(ArchivosDelAgenteIaHelper::DISCO);

        $this->proveedor = Provider::create(['name' => 'Bodega ZZM', 'user_id' => $this->dueno->id]);

        $iva = DB::table('ivas')->where('percentage', '21')->first();
        $iva_id = $iva ? (int) $iva->id : DB::table('ivas')->insertGetId(['percentage' => '21', 'created_at' => now(), 'updated_at' => now()]);

        // Costo 80, precio final 121 con IVA 21 % → neto 100 → ganancia 20 por unidad → margen 25 %.
        DB::table('articles')->insert([
            [
                'name' => 'Malbec ZZM', 'user_id' => $this->dueno->id, 'status' => 'active', 'stock' => 6,
                'cost' => 80, 'final_price' => 121, 'iva_id' => $iva_id, 'aplicar_iva' => 1,
                'provider_id' => $this->proveedor->id, 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'name' => 'Torrontés sin costo ZZM', 'user_id' => $this->dueno->id, 'status' => 'active', 'stock' => 3,
                'cost' => null, 'final_price' => 121, 'iva_id' => $iva_id, 'aplicar_iva' => 1,
                'provider_id' => $this->proveedor->id, 'created_at' => now(), 'updated_at' => now(),
            ],
        ]);
    }

    /**
     * El margen y la ganancia del artículo con costo y precio conocidos; vacío el que no tiene costo.
     *
     * @test
     */
    public function el_excel_trae_el_margen_y_la_ganancia_de_cada_articulo()
    {
        $r = $this->exportar(['columnas' => ['nombre', 'costo', 'precio', 'margen', 'ganancia_por_unidad']]);

        $this->assertTrue($r['ok'], json_encode($r));

        $hoja = $this->hoja_del_link($r['link']);

        $this->assertSame(
            ['Artículo', 'Costo (en pesos)', 'Precio', 'Margen % sobre costo', 'Ganancia por unidad'],
            [$hoja->getCell('A1')->getValue(), $hoja->getCell('B1')->getValue(), $hoja->getCell('C1')->getValue(), $hoja->getCell('D1')->getValue(), $hoja->getCell('E1')->getValue()]
        );

        // Ordenado por nombre: Malbec, Torrontés.
        $this->assertSame('Malbec ZZM', $hoja->getCell('A2')->getValue());
        $this->assertEquals(0.25, $hoja->getCell('D2')->getValue(), 'Margen 25 % (el Excel guarda la fracción).');
        $this->assertSame('0.00%', $hoja->getStyle('D2')->getNumberFormat()->getFormatCode());
        $this->assertEquals(20, $hoja->getCell('E2')->getValue(), 'Ganancia = 121 / 1,21 − 80.');

        $this->assertSame('Torrontés sin costo ZZM', $hoja->getCell('A3')->getValue());
        $this->assertNull($hoja->getCell('D3')->getValue(), 'Sin costo, margen vacío, no 0.');
        $this->assertNull($hoja->getCell('E3')->getValue(), 'Sin costo, ganancia vacía, no 0.');
    }

    /**
     * Sin columnas pedidas, el margen y la ganancia van al final de las de siempre (sin correr las demás).
     *
     * @test
     */
    public function por_defecto_van_al_final()
    {
        $r = $this->exportar([]);

        $this->assertTrue($r['ok'], json_encode($r));

        $hoja = $this->hoja_del_link($r['link']);

        $this->assertSame('Valor a precio', $hoja->getCell('I1')->getValue());
        $this->assertSame('Margen % sobre costo', $hoja->getCell('J1')->getValue());
        $this->assertSame('Ganancia por unidad', $hoja->getCell('K1')->getValue());
        $this->assertEquals(0.25, $hoja->getCell('J2')->getValue());
    }

    /**
     * 🔴 Sin el permiso de costos, ni margen ni ganancia (aunque se pidan).
     *
     * @test
     */
    public function sin_permiso_de_costos_no_hay_margen()
    {
        $empleado = User::create([
            'name'     => 'Empleado ZZM',
            'email'    => 'zzm-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->dueno->id,
        ]);

        $conversation = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $empleado->id]);

        $r = $this->exportar(['columnas' => ['nombre', 'precio', 'margen', 'ganancia_por_unidad']], $conversation);

        $this->assertTrue($r['ok'], json_encode($r));

        $hoja = $this->hoja_del_link($r['link']);

        $encabezado = [];

        foreach (range('A', 'E') as $letra) {
            $encabezado[] = $hoja->getCell($letra . '1')->getValue();
        }

        $this->assertNotContains('Margen % sobre costo', $encabezado);
        $this->assertNotContains('Ganancia por unidad', $encabezado);
        $this->assertContains('Precio', $encabezado);
    }

    /**
     * La cuenta, suelta: sin costo, sin precio o sin factor, null.
     *
     * @test
     */
    public function la_cuenta_del_margen()
    {
        $this->assertSame([25.0, 20.0], ValorizacionDeStockIaHelper::columnas_de_margen(80.0, 121.0, 1 / 1.21));
        $this->assertSame([null, null], ValorizacionDeStockIaHelper::columnas_de_margen(null, 121.0, 1.0));
        $this->assertSame([null, null], ValorizacionDeStockIaHelper::columnas_de_margen(0.0, 121.0, 1.0));
        $this->assertSame([null, null], ValorizacionDeStockIaHelper::columnas_de_margen(80.0, null, 1.0));
        $this->assertSame([null, null], ValorizacionDeStockIaHelper::columnas_de_margen(80.0, 121.0, null));
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
        list($conversation, $assistant) = $this->vuelta('pasame costo, precio y margen en un Excel', $conversation);

        $resultados = (new AsistenteIaService())->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_margen_' . uniqid(),
            'name'  => 'exportar_articulos_excel',
            'input' => array_merge(['filtros' => [['campo' => 'proveedor', 'operador' => 'igual', 'valor' => 'Bodega ZZM']]], $input),
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
