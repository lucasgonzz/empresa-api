<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ArchivosDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ValorizacionDeStockIaHelper;
use App\Models\AiArchivo;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Article;
use App\Models\Provider;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-mejoras-4-dias (16/11/2026), P5 — el Excel de artículos lo arma el SERVIDOR.
 *
 * EL CASO REAL (V9, Pesca, conv 22): «Si por favor» al Excel de todo el stock. El agente dijo «Genero el
 * Excel con las 948 filas» y no llegó nada: exportar_excel exige que el modelo escriba cada fila. Lo que
 * protege este archivo:
 *
 *  - exportar_articulos_excel en los tres canales, al final del registro de lectura;
 *  - 🔴 el modelo manda filtros y columnas, NO filas: con 1.200 artículos (más que el techo de 1.000 filas de
 *    exportar_excel) sale el .xlsx entero, por lotes, con los números como números;
 *  - el link es el de siempre (disco privado, token, vencimiento) y baja el archivo;
 *  - los recortes de la auditoría de precios (V16): sin precio, precio menor o igual al costo;
 *  - 🔴 sin el permiso de costos, el Excel va sin las columnas de costo y lo dice;
 *  - más que el techo, error honesto (nunca un Excel cortado en silencio).
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados, union types.
 *
 * @group chat-ia
 */
class T131_Excel_de_articulos_desde_el_servidor_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio();

        Storage::fake(ArchivosDelAgenteIaHelper::DISCO);
    }

    /**
     * N artículos de un proveedor, insertados de a lotes (los valores exactos, sin cálculos de precio).
     */
    protected function catalogo($cuantos, Provider $proveedor)
    {
        $filas = [];

        for ($i = 1; $i <= $cuantos; $i++) {
            $filas[] = [
                'name'        => sprintf('Señuelo ZZX %04d', $i),
                'bar_code'    => '779' . sprintf('%07d', $i),
                'user_id'     => $this->dueno->id,
                'status'      => 'active',
                'stock'       => 2,
                'cost'        => 100,
                'final_price' => 150,
                'provider_id' => $proveedor->id,
                'created_at'  => now(),
                'updated_at'  => now(),
            ];

            if (count($filas) === 500) {
                DB::table('articles')->insert($filas);
                $filas = [];
            }
        }

        if (count($filas)) {
            DB::table('articles')->insert($filas);
        }
    }

    protected function exportar(array $input, $conversation = null)
    {
        list($conversation, $assistant) = $this->vuelta('pasame todo el stock en un Excel', $conversation);

        $resultados = (new AsistenteIaService())->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_excel_art_' . uniqid(),
            'name'  => 'exportar_articulos_excel',
            'input' => $input,
        ]], $conversation, $assistant);

        $this->assertArrayNotHasKey('is_error', $resultados[0], $resultados[0]['content']);

        return json_decode($resultados[0]['content'], true);
    }

    protected function hoja_del_link($link)
    {
        $uuid = basename((string) parse_url($link, PHP_URL_PATH));

        $archivo = AiArchivo::where('uuid', $uuid)->firstOrFail();

        return IOFactory::load(Storage::disk(ArchivosDelAgenteIaHelper::DISCO)->path($archivo->path))->getActiveSheet();
    }

    /**
     * @test
     */
    public function esta_en_los_tres_canales_y_no_pide_filas()
    {
        $servicio = new AsistenteIaService();

        $this->assertContains('exportar_articulos_excel', $servicio->nombres_de_lectura());
        $this->assertContains('exportar_articulos_excel', array_column($servicio->build_tools(true, true, $this->dueno), 'name'), 'WhatsApp.');
        $this->assertContains('exportar_articulos_excel', array_column($servicio->build_tools(true, false, $this->dueno, true), 'name'), 'La app.');

        foreach ($servicio->herramientas_de_lectura() as $herramienta) {
            if ($herramienta['name'] === 'exportar_articulos_excel') {
                $this->assertArrayNotHasKey('filas', $herramienta['input_schema']['properties'], 'Las filas las lee el servidor.');
            }
        }
    }

    /**
     * 🔴 V9: 1.200 artículos (más que las 1.000 filas de exportar_excel) salen enteros en el .xlsx.
     *
     * @test
     */
    public function el_excel_trae_todos_los_articulos_del_filtro()
    {
        $proveedor = Provider::create(['name' => 'Albatros ZZX', 'user_id' => $this->dueno->id]);

        $this->catalogo(1200, $proveedor);

        $r = $this->exportar(['filtros' => [['campo' => 'proveedor', 'operador' => 'igual', 'valor' => 'Albatros ZZX']], 'titulo' => 'Stock de Albatros']);

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(1200, $r['filas']);
        $this->assertSame('stock-de-albatros.xlsx', $r['nombre_archivo']);
        $this->assertStringContainsString('/asistente/archivo/', $r['link']);

        $hoja = $this->hoja_del_link($r['link']);

        $this->assertSame('Artículo', $hoja->getCell('A1')->getValue());
        $this->assertSame('Señuelo ZZX 0001', $hoja->getCell('A2')->getValue());
        $this->assertSame('Señuelo ZZX 1200', $hoja->getCell('A1201')->getValue(), 'Todas las filas, en orden de nombre.');
        $this->assertNull($hoja->getCell('A1202')->getValue());

        // Columnas por defecto: Artículo, Código, Rubro, Proveedor, Stock, Costo, Precio, Valor a costo, Valor a precio.
        $this->assertSame('7790000001', $hoja->getCell('B2')->getValue(), 'Un código de barras es texto (no pierde ceros).');
        $this->assertSame('Albatros ZZX', $hoja->getCell('D2')->getValue());
        $this->assertSame(DataType::TYPE_NUMERIC, $hoja->getCell('E2')->getDataType());
        $this->assertEquals(2, $hoja->getCell('E2')->getValue());
        $this->assertEquals(300, $hoja->getCell('I2')->getValue(), 'Valor a precio = 2 × 150.');
        $this->assertEquals(200, $hoja->getCell('H2')->getValue(), 'Valor a costo = 2 × 100.');
    }

    /**
     * V16: la lista de los que tienen el precio mal, entera, con las columnas pedidas.
     *
     * @test
     */
    public function la_auditoria_de_precios_sale_entera_con_las_columnas_pedidas()
    {
        $proveedor = Provider::create(['name' => 'Albatros ZZX', 'user_id' => $this->dueno->id]);
        $this->catalogo(3, $proveedor);

        DB::table('articles')->where('name', 'Señuelo ZZX 0002')->update(['final_price' => 90]);
        DB::table('articles')->where('name', 'Señuelo ZZX 0003')->update(['final_price' => null]);

        $r = $this->exportar(['solo' => ['precio_menor_o_igual_al_costo'], 'columnas' => ['nombre', 'costo', 'precio']]);

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(2, $r['filas'], 'El de precio 90 y el sin precio (0 ≤ 100).');
        $this->assertSame(3, $r['columnas']);

        $hoja = $this->hoja_del_link($r['link']);

        $this->assertSame(['Artículo', 'Costo (en pesos)', 'Precio'], [$hoja->getCell('A1')->getValue(), $hoja->getCell('B1')->getValue(), $hoja->getCell('C1')->getValue()]);
        $this->assertSame('Señuelo ZZX 0002', $hoja->getCell('A2')->getValue());
        $this->assertEquals(90, $hoja->getCell('C2')->getValue());

        $r = $this->exportar(['columnas' => ['nombre', 'cualquiera']]);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('No conozco la columna', $r['error']);
    }

    /**
     * 🔴 Sin el permiso de costos, el Excel no trae nada del costo.
     *
     * @test
     */
    public function sin_permiso_de_costos_el_excel_va_sin_costos()
    {
        $proveedor = Provider::create(['name' => 'Albatros ZZX', 'user_id' => $this->dueno->id]);
        $this->catalogo(2, $proveedor);

        $empleado = User::create([
            'name'     => 'Empleado ZZX',
            'email'    => 'zzx-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->dueno->id,
        ]);

        $conversation = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $empleado->id]);

        $r = $this->exportar([], $conversation);

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertArrayHasKey('sin_costos', $r);

        $hoja = $this->hoja_del_link($r['link']);
        $encabezado = [];

        foreach (range('A', 'J') as $letra) {
            $encabezado[] = $hoja->getCell($letra . '1')->getValue();
        }

        $this->assertNotContains('Costo (en pesos)', $encabezado);
        $this->assertNotContains('Valor a costo', $encabezado);
        $this->assertContains('Precio', $encabezado);

        $r = $this->exportar(['solo' => ['sin_costo']], $conversation);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('permiso para ver costos', $r['error']);
    }

    /**
     * Más que el techo: error honesto, sin archivo. Y un filtro sin artículos tampoco arma nada.
     *
     * @test
     */
    public function mas_que_el_techo_o_nada_no_arma_un_excel()
    {
        $r = $this->exportar(['filtros' => [['campo' => 'nombre', 'operador' => 'contiene', 'valor' => 'no existe ZZX']]]);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Ningún artículo', $r['error']);
        $this->assertSame(0, AiArchivo::where('user_id', $this->dueno->id)->count());

        $this->assertSame(15000, ValorizacionDeStockIaHelper::MAX_FILAS_DEL_EXCEL, 'El techo documentado en la herramienta.');
    }
}
