<?php

namespace Tests\Feature\PruebaGratuita;

use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Jobs\FinalizeArticleImport;
use App\Jobs\ProcessArticleChunk;
use App\Models\AiAvisoDelAsistente;
use App\Models\Article;
use App\Models\ImportacionDePrueba;
use App\Models\ImportHistory;
use App\Services\Importacion\ImportacionDePruebaService;
use App\Services\Importacion\PlanillaDePrueba;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\EmpresaTestCase;

/**
 * La importación automática de la prueba frente a la lista real de un proveedor (misión
 * prueba-e2e-campana-imperio, 11/11/2026).
 *
 *  M. LA PREGUNTA DEL MARGEN (pedido de Lucas: «si importás el costo también tenés que poner el margen de
 *     ganancia»). Cuando la planilla queda con una columna asignada al costo y ninguna dice a cuánto se
 *     vende, el circuito hace UNA pregunta más, con la forma de todas (`{clave, texto, opciones}`): qué
 *     ganancia sumarle. Se contesta con una opción (20, 30, 40, 50, «despues») o con un porcentaje libre.
 *     La respuesta viaja como una columna `margen_de_ganancia` de la planilla preparada. Con solo precio de
 *     venta no se pregunta nada.
 *  N. FILAS QUE NO SON ARTÍCULOS. Un título de rubro («--- TORNILLERIA ---»), una nota suelta al pie y la
 *     fila del total no llegan al motor, y el resumen dice cuántas y CUÁLES se dejaron afuera. La regla es
 *     conservadora (un «Total» en el medio, un nombre suelto o «**Martillo» son artículos) y corre solo en
 *     la prueba: en modo soporte no se descarta nada nuevo.
 *
 * El fixture es la lista con la que se hizo el ensayo real: membrete de 6 filas, cabecera en la fila 7 y
 * repetida en la 44, 5 títulos «--- RUBRO ---», 50 artículos, «PRECIO» que es el costo, «TOTAL LISTA» y
 * una nota al pie.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class ImportacionDePruebaMargenYFilasQueNoSonArticulosTest extends EmpresaTestCase
{
    const CLAVE = 'admin-prueba-margen-y-filas-test';
    const BASE = '/api/admin-sync/ai-excel-import/';
    const FIXTURE = __DIR__ . '/../../Import/fixtures/lista_distribuidora_del_litoral_nov.xlsx';
    const TITULOS = ['--- TORNILLERIA ---', '--- HERRAMIENTAS ---', '--- PINTURERIA ---', '--- ELECTRICIDAD ---', '--- SANITARIOS ---'];

    private $dueno;
    private $archivos = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->dueno = auth()->user();

        config([
            'app.FOR_USER' => 'prueba',
            'app.USER_ID'  => $this->dueno->id,
            'services.admin_api.api_key' => self::CLAVE,
            'services.openai.embeddings_generacion_pausada' => true,
        ]);

        PruebaHelper::olvidar_cache();
        PruebaHelper::activar_desde_payload($this->dueno, ['dias' => 7]);
        $this->withHeader('Referer', rtrim((string) config('app.url'), '/') . '/');
    }

    protected function tearDown(): void
    {
        foreach ($this->archivos as $archivo) {
            foreach (glob($archivo . '*') ?: [] as $ruta) {
                @unlink($ruta);
            }
        }
        PruebaHelper::olvidar_cache();

        parent::tearDown();
    }

    /**
     * El analizador de producción (detección de cabecera, encabezados repetidos, volcado de la hoja) con una
     * respuesta de IA fija: `titulo de columna => [campo, confianza]`.
     *
     * @param  array $mapeo
     * @return void
     */
    private function analizador(array $mapeo): void
    {
        $dueno = $this->dueno;
        $this->app->bind(AiExcelAnalyzer::class, function () use ($dueno, $mapeo) {
            $analizador = new AnalizadorDeListaDeProveedorFalso((int) $dueno->id);
            $analizador->mapeo = $mapeo;

            return $analizador;
        });
    }

    /**
     * Una corrida de la prueba sobre un xlsx: el fixture real, o una planilla armada con las filas dadas.
     *
     * @param  array|null $filas
     * @param  array      $campos  Lo que cambia de la corrida (modo_soporte…)
     * @return \App\Models\ImportacionDePrueba
     */
    private function corrida(?array $filas = null, array $campos = []): ImportacionDePrueba
    {
        $uuid = (string) Str::uuid();
        $relativa = 'imported_files/prueba_' . $uuid . '.xlsx';
        $destino = storage_path('app/' . $relativa);

        if ($filas === null) {
            copy(self::FIXTURE, $destino);
        } else {
            $libro = new Spreadsheet();
            $libro->getActiveSheet()->setTitle('Hoja 0')->fromArray($filas, null, 'A1', true);
            (new Xlsx($libro))->save($destino);
            $libro->disconnectWorksheets();
        }

        $this->archivos[] = $destino;
        $this->archivos[] = storage_path('app/imported_files/prueba_' . $uuid . '_');
        $this->archivos[] = storage_path('app/imported_files/prueba_' . $uuid . '.preparado');

        return ImportacionDePrueba::create($campos + [
            'uuid' => $uuid, 'user_id' => $this->dueno->id, 'support_ticket_id' => 31,
            'clave' => hash('sha256', $uuid), 'archivo' => $relativa, 'nombre' => 'lista distribuidora del litoral NOV.xlsx',
            'origen' => 'martin', 'estado' => 'analizar',
        ]);
    }

    private function avanzar(ImportacionDePrueba $run): ImportacionDePrueba
    {
        app(ImportacionDePruebaService::class)->avanzar($run->fresh());

        return $run->fresh();
    }

    /**
     * Contesta por el endpoint real, como lo hace el admin con lo que respondió el dueño.
     *
     * @param  \App\Models\ImportacionDePrueba $run
     * @param  array $respuestas
     * @return \Illuminate\Testing\TestResponse
     */
    private function responder(ImportacionDePrueba $run, array $respuestas)
    {
        return $this->postJson(self::BASE . 'prueba-resolver/' . $run->uuid, [
            'user_id' => $this->dueno->id, 'support_ticket_id' => $run->support_ticket_id,
            'revision' => $run->revision, 'respuestas' => $respuestas,
        ], ['X-Admin-Api-Key' => self::CLAVE]);
    }

    /**
     * Corre los lotes de la importación que quedó despachada y devuelve las filas que recibió el motor.
     *
     * @return array
     */
    private function correr_la_importacion(): array
    {
        $inicios = Bus::dispatched(ProcessArticleChunk::class);
        $this->assertCount(1, $inicios, 'La importación tiene que haber despachado su primer lote.');
        $primero = $inicios->first();
        $trabajos = [$primero];
        // Bus::fake conserva la cadena: solo el primer lote figura como despachado.
        foreach ($primero->chained as $serializado) {
            $siguiente = unserialize($serializado);
            if ($siguiente instanceof ProcessArticleChunk) {
                $trabajos[] = $siguiente;
            }
        }
        $filas = [];
        foreach ($trabajos as $job) {
            $filas = array_merge($filas, $job->get_row_from_csv());
            $job->handle();
        }

        return $filas;
    }

    private function mapeo_de_la_lista(): array
    {
        return [
            'COD' => ['codigo_de_proveedor', 0.97], 'DESCRIPCION' => ['nombre', 0.97],
            // «PRECIO» a secas: aunque la IA lo dé por precio de venta con toda la confianza (así pasó en
            // producción), el circuito le pregunta al dueño si es de venta o el costo.
            'PRECIO' => ['precio', 0.99], 'IVA' => ['iva', 0.97],
            'U.M.' => [null, 0.97], 'BULTO' => [null, 0.97],
        ];
    }

    public function test_la_lista_real_del_proveedor_carga_50_articulos_y_pregunta_el_margen(): void
    {
        $this->assertSame('b0f7f871c4908ff17179f06c2fef70f1baece3d8eb62ef21ecf79b58b4dc844b', hash_file('sha256', self::FIXTURE));
        $this->analizador($this->mapeo_de_la_lista());
        $run = $this->corrida();

        // 1. La columna dudosa: ¿el «PRECIO» es de venta o es el costo?
        $run = $this->avanzar($run);
        $this->assertSame('columnas', $run->estado);
        $this->assertSame(['columna_0_2'], array_keys($run->preguntas));
        $this->assertSame(self::PREGUNTA_DEL_PRECIO, $run->preguntas['columna_0_2']['texto']);
        $this->assertSame(7 + 1, (int) $run->hojas[0]['inicio'], 'La cabecera está en la fila 7, después del membrete.');

        // 2. «Es el costo» → la misma corrida pregunta el margen, con la forma de cualquier pregunta.
        $this->responder($run, ['columna_0_2' => 'costo'])->assertStatus(202);
        $run = $this->avanzar($run);
        $this->assertSame('columnas', $run->estado, 'La pregunta del margen usa un estado que cualquier admin ya conoce.');
        $this->assertSame([PlanillaDePrueba::CLAVE_MARGEN], array_keys($run->preguntas));

        $pregunta = $run->preguntas[PlanillaDePrueba::CLAVE_MARGEN];
        $this->assertSame(['clave', 'texto', 'opciones', 'acepta_porcentaje'], array_keys($pregunta));
        $this->assertSame('margen_de_ganancia', $pregunta['clave']);
        $this->assertStringStartsWith('Tu lista trae el costo pero no dice a cuánto vendés. ¿Qué ganancia le sumamos a todos para calcular el precio de venta?', $pregunta['texto']);
        $this->assertSame([
            ['valor' => '20', 'etiqueta' => '20 %'], ['valor' => '30', 'etiqueta' => '30 %'],
            ['valor' => '40', 'etiqueta' => '40 %'], ['valor' => '50', 'etiqueta' => '50 %'],
            ['valor' => 'despues', 'etiqueta' => 'Lo cargo yo después'],
        ], $pregunta['opciones']);
        $this->assertTrue($pregunta['acepta_porcentaje']);

        // El dueño la recibe por el aviso de siempre, con el texto completo.
        $aviso = AiAvisoDelAsistente::where('owner_id', $this->dueno->id)->where('support_ticket_id', 31)->orderByDesc('id')->firstOrFail();
        $this->assertSame($pregunta['texto'], $aviso->texto);

        // Y el admin la ve en las pendientes como cualquier otra: misma forma, mismo tipo.
        $pendientes = $this->getJson(self::BASE . 'prueba-pendientes?user_id=' . $this->dueno->id . '&support_ticket_id=31', ['X-Admin-Api-Key' => self::CLAVE])
            ->assertOk()->json('pendientes');
        $this->assertCount(1, $pendientes);
        $this->assertSame('columnas', $pendientes[0]['tipo']);
        $this->assertSame('margen_de_ganancia', $pendientes[0]['preguntas'][0]['clave']);
        $this->assertSame(['20', '30', '40', '50', 'despues'], array_column($pendientes[0]['preguntas'][0]['opciones'], 'valor'));

        // 3. Una respuesta que no es ni opción ni porcentaje se rechaza; una opción se acepta.
        $this->responder($run, ['margen_de_ganancia' => 'mucho'])->assertStatus(422);
        $this->responder($run, ['margen_de_ganancia' => '40'])->assertStatus(202);

        Bus::fake();
        $run = $this->avanzar($run);
        $this->assertSame('importando', $run->estado);

        // 4. Al motor llegan los 50 artículos y nada más.
        $filas = $this->correr_la_importacion();
        $this->assertCount(50, $filas);
        $crudo = json_encode($filas, JSON_UNESCAPED_UNICODE);
        foreach (array_merge(self::TITULOS, ['TOTAL LISTA', 'Consulte por descuentos', 'DESCRIPCION']) as $intruso) {
            $this->assertStringNotContainsString($intruso, $crudo, $intruso);
        }

        $historial = ImportHistory::findOrFail($run->import_history_id);
        (new FinalizeArticleImport($this->dueno->id, $historial->id, $historial->import_status_id))->handle();
        $historial->refresh();
        $this->assertSame('terminado', $historial->status);
        $this->assertSame(50, (int) $historial->created_models);

        $articulos = Article::where('user_id', $this->dueno->id)->where('provider_code', 'like', 'DL-%')->get();
        $this->assertCount(50, $articulos);
        foreach ($articulos as $articulo) {
            $this->assertEqualsWithDelta(40, (float) $articulo->percentage_gain, 0.001, $articulo->provider_code);
            $this->assertGreaterThan(0, (float) $articulo->cost, $articulo->provider_code);
            $this->assertGreaterThan((float) $articulo->cost, (float) $articulo->final_price, 'Con margen, el precio de venta supera al costo: ' . $articulo->provider_code);
        }
        $tornillo = $articulos->firstWhere('provider_code', 'DL-1002');
        $this->assertSame('TORNILLO AUTOPERFORANTE 8X1', mb_strtoupper($tornillo->name));
        $this->assertEqualsWithDelta(2054.9, (float) $tornillo->cost, 0.01);

        // Ningún artículo es un título, la cabecera repetida, el total o la nota del pie.
        $this->assertSame(0, Article::where('user_id', $this->dueno->id)->where(function ($consulta) {
            $consulta->where('provider_code', 'like', '---%')->orWhere('provider_code', 'COD')
                ->orWhere('provider_code', 'like', 'Consulte%')->orWhere('name', 'like', '---%')
                ->orWhere('name', 'like', 'TOTAL LISTA%')->orWhere('name', 'DESCRIPCION');
        })->count());

        // 5. El resumen dice cuántas filas dejó afuera y con qué ganancia calculó el precio.
        $run = $run->fresh();
        // 5 títulos de rubro y la nota del pie; y el total. (La columna JSON no conserva el orden de las claves.)
        $salteadas = $run->hojas[0]['filas_salteadas'];
        $this->assertSame(6, $salteadas['titulo']);
        $this->assertSame(1, $salteadas['total']);
        $this->assertSame(self::TITULOS, $salteadas['textos_titulo'], 'Se guardan los primeros cinco textos, en su orden.');
        $this->assertSame(['TOTAL LISTA'], $salteadas['textos_total']);
        // Sin cupo de imágenes: el cierre de la importación no sale a buscar fotos (no es lo que se mide acá).
        \App\Services\ImageAssignment\CupoImagenesDePrueba::editar((int) $this->dueno->id, 0);
        $frase = ' Dejé afuera 7 filas que no son artículos: “--- TORNILLERIA ---”, “--- HERRAMIENTAS ---”, “--- PINTURERIA ---”,'
            . ' “--- ELECTRICIDAD ---”, …, “TOTAL LISTA”. Si alguna era un producto, decímelo y la cargo.';
        $this->assertSame($frase, PlanillaDePrueba::texto_de_filas_salteadas($run->hojas));
        $run = $this->avanzar($run);
        $resultado = AiAvisoDelAsistente::where('owner_id', $this->dueno->id)->where('support_ticket_id', 31)
            ->where('texto', 'like', 'Cargué%')->firstOrFail();
        $this->assertStringStartsWith('Cargué 50 artículos con su costo y un 40 % de ganancia.', $resultado->texto);
        $this->assertStringContainsString(' También tomé de tu lista: código de proveedor e IVA.', $resultado->texto);
        foreach (['codigo de proveedor', 'unidad medida', 'encontré estos datos', 'creados'] as $jerga) {
            $this->assertStringNotContainsString($jerga, $resultado->texto, $jerga);
        }
        $this->assertStringContainsString(trim($frase), $resultado->texto);
    }

    // -----------------------------------------------------------------------------------------------------
    // Ítem O: las preguntas, en palabras del dueño
    // -----------------------------------------------------------------------------------------------------

    public function test_las_preguntas_de_la_lista_real_usan_articulos_de_ejemplo_y_no_un_titulo_de_rubro(): void
    {
        // Lo que dudó el analizador en el ensayo real: el código, el «PRECIO» y el «BULTO».
        $this->analizador([
            'COD' => ['codigo_de_proveedor', 0.50], 'DESCRIPCION' => ['nombre', 0.97], 'PRECIO' => ['precio', 0.60],
            'IVA' => ['iva', 0.97], 'U.M.' => [null, 0.97], 'BULTO' => [null, 0.30],
        ]);
        $run = $this->avanzar($this->corrida());

        $this->assertSame('columnas', $run->estado);
        $this->assertSame(['columna_0_0', 'columna_0_2', 'columna_0_5'], array_keys($run->preguntas));
        $this->assertSame(['DL-1002', 'DL-1003'], $run->hojas[0]['columnas'][0]['ejemplos'], 'La primera fila de datos es «--- TORNILLERIA ---»: no sirve de ejemplo.');

        $cierre = ' Si es otra cosa, decime cuál; y si no la querés cargar, decime que no la cargue.';
        $codigo = 'En tu lista hay una columna que se llama «COD» (por ejemplo: DL-1002, DL-1003). ¿Qué es?'
            . ' Puede ser el código que usa tu proveedor, un código tuyo o el código de barras.' . $cierre;
        $bulto = 'En tu lista hay una columna que se llama «BULTO» (por ejemplo: 100, 12). ¿Qué es?'
            . ' Puede ser el stock que tenés o cuántas unidades trae el bulto o la caja.' . $cierre;
        $this->assertSame($codigo, $run->preguntas['columna_0_0']['texto']);
        $this->assertSame($bulto, $run->preguntas['columna_0_5']['texto']);
        $this->assertSame(['codigo_de_proveedor', 'sku', 'codigo_de_barras', 'ignorar'], $run->preguntas['columna_0_0']['sugeridas']);
        $this->assertSame(['stock_actual', 'u_individuales', 'ignorar'], $run->preguntas['columna_0_5']['sugeridas']);

        // La forma de siempre: clave, texto y opciones con sus `valor`; `sugeridas` es un agregado.
        $this->assertSame(['clave', 'texto', 'opciones', 'sugeridas'], array_keys($run->preguntas['columna_0_0']));
        $valores = array_column($run->preguntas['columna_0_0']['opciones'], 'valor');
        foreach (['ignorar', 'codigo_de_proveedor', 'sku', 'codigo_de_barras', 'costo', 'precio', 'stock_actual', 'u_individuales'] as $valor) {
            $this->assertContains($valor, $valores, $valor);
        }

        // El aviso que lee el dueño: cabecera nueva, preguntas numeradas y separadas por una línea en blanco.
        $aviso = AiAvisoDelAsistente::where('owner_id', $this->dueno->id)->where('support_ticket_id', 31)->firstOrFail();
        $this->assertStringStartsWith("Ya leí tu lista. Antes de cargarla necesito que me aclares esto:\n\n1. " . $codigo . "\n\n2. En tu lista hay una columna que se llama «PRECIO»", $aviso->texto);
        $this->assertStringEndsWith("\n\n3. " . $bulto, $aviso->texto);

        // Ni una pregunta, ni el aviso, ni los ejemplos guardados nombran un título, el total o la nota del pie.
        $todo = json_encode([$run->preguntas, $aviso->texto, array_column($run->hojas[0]['columnas'], 'ejemplos'), array_column($run->hojas[0]['columnas'], 'muestra')], JSON_UNESCAPED_UNICODE);
        foreach (array_merge(self::TITULOS, ['TOTAL LISTA', 'Consulte por descuentos', 'columna A', 'LISTA NOV', 'Qué dato querés cargar']) as $intruso) {
            $this->assertStringNotContainsString($intruso, $todo, $intruso);
        }
    }

    public function test_el_texto_de_una_columna_dudosa_segun_su_familia(): void
    {
        $columna = function (?string $campo, string $titulo, array $ejemplos, bool $segura = false) {
            return ['campo' => $campo, 'segura' => $segura, 'titulo' => $titulo, 'muestra' => $ejemplos[0] ?? '', 'ejemplos' => $ejemplos];
        };
        $hoja = ['indice' => 0, 'nombre' => 'Precios', 'columnas' => [
            0 => $columna(null, 'Código', ['A-1', 'A-2']),
            1 => $columna('nombre', 'Descripción', ['Martillo'], true),
            2 => $columna(null, 'Importe', ['1500', '2300.5']),
            3 => $columna(null, 'Cant.', ['4', '12']),
            4 => $columna('marca', 'Fabricante', ['Bahco']),
            5 => $columna(null, '', ['x']),
            6 => $columna('sku', 'Dato', []),
        ]];
        $planilla = new PlanillaDePrueba(new ImportacionDePrueba(['user_id' => $this->dueno->id]));
        $textos = array_column($planilla->preguntas_columnas([$hoja]), 'texto', 'clave');
        $cierre = ' Si es otra cosa, decime cuál; y si no la querés cargar, decime que no la cargue.';

        // Un código.
        $this->assertSame('En tu lista hay una columna que se llama «Código» (por ejemplo: A-1, A-2). ¿Qué es?'
            . ' Puede ser el código que usa tu proveedor, un código tuyo o el código de barras.' . $cierre, $textos['columna_0_0']);
        // Un importe.
        $this->assertSame('En tu lista hay una columna que se llama «Importe» (por ejemplo: 1.500,00 y 2.300,50).'
            . ' ¿Es lo que te cobra el proveedor o es a lo que vendés vos?' . $cierre, $textos['columna_0_2']);
        // Una cantidad.
        $this->assertSame('En tu lista hay una columna que se llama «Cant.» (por ejemplo: 4, 12). ¿Qué es?'
            . ' Puede ser el stock que tenés o cuántas unidades trae el bulto o la caja.' . $cierre, $textos['columna_0_3']);
        // Fuera de las tres familias: lo que sugirió el analizador, con su etiqueta.
        $this->assertSame('En tu lista hay una columna que se llama «Fabricante» (por ejemplo: Bahco). ¿Qué es? Puede ser «marca».' . $cierre, $textos['columna_0_4']);
        // Sin título se la ubica por su letra; sin pista, no se sugiere nada.
        $this->assertSame('En tu lista hay una columna sin título (la F) (por ejemplo: x). ¿Qué es?' . $cierre, $textos['columna_0_5']);
        // La familia sale primero de lo que dudó el analizador, aunque el título no diga nada; y sin ejemplos no se inventa uno.
        $this->assertSame('En tu lista hay una columna que se llama «Dato». ¿Qué es?'
            . ' Puede ser el código que usa tu proveedor, un código tuyo o el código de barras.' . $cierre, $textos['columna_0_6']);

        // Con más de una hoja con datos sí se dice en cuál está.
        $otra = ['indice' => 1, 'nombre' => 'Ofertas', 'columnas' => [0 => $columna(null, 'Cod', ['B-9'])]];
        $varias = array_column($planilla->preguntas_columnas([$hoja, $otra]), 'texto', 'clave');
        $this->assertStringStartsWith('En la hoja «Ofertas» de tu lista hay una columna que se llama «Cod» (por ejemplo: B-9). ¿Qué es?', $varias['columna_1_0']);
        $this->assertStringStartsWith('En la hoja «Precios» de tu lista hay una columna que se llama «Código»', $varias['columna_0_0']);

        // Una corrida analizada antes de este cambio trae solo `muestra`: se usa como ejemplo.
        $vieja = ['indice' => 0, 'nombre' => 'Hoja', 'columnas' => [0 => ['campo' => null, 'segura' => false, 'titulo' => 'Stock', 'muestra' => '7']]];
        $this->assertStringStartsWith('En tu lista hay una columna que se llama «Stock» (por ejemplo: 7). ¿Qué es?', array_values($planilla->preguntas_columnas([$vieja]))[0]['texto']);
    }

    public function test_las_opciones_tienen_etiquetas_legibles_y_los_mismos_valores(): void
    {
        $lista = \App\Models\PriceType::create(['user_id' => $this->dueno->id, 'name' => 'ZZ Mayorista']);
        $planilla = new PlanillaDePrueba(new ImportacionDePrueba(['user_id' => $this->dueno->id]));
        $hoja = ['indice' => 0, 'nombre' => 'Hoja', 'columnas' => [0 => ['campo' => null, 'segura' => false, 'titulo' => 'Dato', 'muestra' => '', 'ejemplos' => []]]];
        $etiquetas = array_column(array_values($planilla->preguntas_columnas([$hoja]))[0]['opciones'], 'etiqueta', 'valor');

        foreach ([
            'u_individuales' => 'unidades por bulto', 'in_offer' => 'en oferta', 'sub_categoria' => 'subcategoría',
            'codigo_de_proveedor' => 'código de proveedor', 'precio' => 'precio de venta', 'stock_actual' => 'stock',
            'codigo_de_barras' => 'código de barras', 'moneda' => 'moneda del costo (pesos o dólares)', 'ignorar' => 'Ignorar',
            'price_type_' . $lista->id . '_final_price' => 'Lista ZZ Mayorista: precio de venta final',
            'price_type_' . $lista->id . '_percentage' => 'Lista ZZ Mayorista: margen de ganancia (%)',
            'price_type_' . $lista->id . '_setear' => 'Lista ZZ Mayorista: fijar el precio final (sí o no)',
        ] as $valor => $etiqueta) {
            $this->assertSame($etiqueta, $etiquetas[$valor] ?? null, $valor);
        }
        foreach ($etiquetas as $valor => $etiqueta) {
            $this->assertStringNotContainsString('_', $etiqueta, 'La etiqueta de «' . $valor . '» sigue cruda.');
        }
        // Los `valor` son las claves del importador, las mismas que resuelve el mapeo previo.
        foreach (array_keys($etiquetas) as $valor) {
            $this->assertSame($valor, $planilla->resolver_campo_previo((string) $valor), (string) $valor);
        }
    }

    public function test_un_aviso_lleva_tres_preguntas_como_mucho_y_avisa_que_faltan(): void
    {
        $this->analizador([
            'Dato uno' => [null, 0.1], 'Dato dos' => [null, 0.1], 'Dato tres' => [null, 0.1],
            'Dato cuatro' => [null, 0.1], 'Descripción' => ['nombre', 0.97],
        ]);
        $run = $this->avanzar($this->corrida([
            ['Dato uno', 'Dato dos', 'Dato tres', 'Dato cuatro', 'Descripción'],
            ['a', 'b', 'c', 'd', 'Martillo'],
            ['e', 'f', 'g', 'h', 'Pinza'],
        ]));

        $this->assertCount(4, $run->preguntas);
        $aviso = AiAvisoDelAsistente::where('owner_id', $this->dueno->id)->where('support_ticket_id', 31)->firstOrFail();
        $this->assertStringContainsString("\n\n3. En tu lista hay una columna que se llama «Dato tres» (por ejemplo: c, g).", $aviso->texto);
        $this->assertStringNotContainsString('4. ', $aviso->texto);
        $this->assertStringNotContainsString('Dato cuatro', $aviso->texto);
        $this->assertStringEndsWith(' Después te consulto lo que falte.', $aviso->texto);

        $pendientes = $this->getJson(self::BASE . 'prueba-pendientes?user_id=' . $this->dueno->id . '&support_ticket_id=31', ['X-Admin-Api-Key' => self::CLAVE])
            ->assertOk()->json('pendientes.0.preguntas');
        $this->assertSame([true, true, true, false], array_column($pendientes, 'ya_consultada'));
    }

    // -----------------------------------------------------------------------------------------------------
    // Ítem P: un importe no se adivina
    // -----------------------------------------------------------------------------------------------------

    const PREGUNTA_DEL_PRECIO = 'En tu lista hay una columna que se llama «PRECIO» (por ejemplo: 2.054,90 y 849,57).'
        . ' ¿Es lo que te cobra el proveedor o es a lo que vendés vos?'
        . ' Si es otra cosa, decime cuál; y si no la querés cargar, decime que no la cargue.';

    /**
     * El caso de producción: el analizador da «PRECIO» por precio de venta con TODA la confianza.
     */
    private function mapeo_del_analizador_seguro(): array
    {
        return [
            'COD' => ['codigo_de_proveedor', 0.97], 'DESCRIPCION' => ['nombre', 0.97], 'PRECIO' => ['precio', 0.99],
            'IVA' => ['iva', 0.97], 'U.M.' => [null, 0.97], 'BULTO' => [null, 0.97],
        ];
    }

    public function test_un_precio_a_secas_se_pregunta_aunque_el_analizador_este_seguro_y_la_carga_espera(): void
    {
        $this->analizador($this->mapeo_del_analizador_seguro());
        Bus::fake();
        $run = $this->avanzar($this->corrida());

        $this->assertSame('columnas', $run->estado, 'No se carga nada sin saber si «PRECIO» es el costo o el precio de venta.');
        $this->assertSame(['columna_0_2'], array_keys($run->preguntas));
        $this->assertSame(self::PREGUNTA_DEL_PRECIO, $run->preguntas['columna_0_2']['texto']);
        $this->assertSame(['costo', 'precio', 'ignorar'], $run->preguntas['columna_0_2']['sugeridas']);
        $valores = array_column($run->preguntas['columna_0_2']['opciones'], 'valor');
        $this->assertContains('costo', $valores);
        $this->assertContains('precio', $valores);

        // Mientras no conteste, volver a avanzar no escribe nada.
        $run = $this->avanzar($run);
        $this->assertSame('columnas', $run->estado);
        Bus::assertNotDispatched(ProcessArticleChunk::class);
        $this->assertSame(0, Article::where('user_id', $this->dueno->id)->where('provider_code', 'like', 'DL-%')->count());
    }

    public function test_si_contesta_que_es_su_precio_de_venta_se_carga_tal_cual_y_no_se_pregunta_el_margen(): void
    {
        $this->analizador($this->mapeo_del_analizador_seguro());
        $run = $this->avanzar($this->corrida());
        $this->responder($run, ['columna_0_2' => 'precio'])->assertStatus(202);

        Bus::fake();
        $run = $this->avanzar($run);
        $this->assertSame('importando', $run->estado, 'Con precio de venta no hay margen que preguntar.');
        $this->assertCount(50, $this->correr_la_importacion());

        $historial = ImportHistory::findOrFail($run->import_history_id);
        (new FinalizeArticleImport($this->dueno->id, $historial->id, $historial->import_status_id))->handle();

        $articulos = Article::where('user_id', $this->dueno->id)->where('provider_code', 'like', 'DL-%')->get();
        $this->assertCount(50, $articulos);
        $tornillo = $articulos->firstWhere('provider_code', 'DL-1002');
        $this->assertEqualsWithDelta(2054.9, (float) $tornillo->price, 0.01, 'El importe de la lista es el precio de venta.');
        foreach ($articulos as $articulo) {
            $this->assertEmpty((float) $articulo->cost, $articulo->provider_code);
            $this->assertEmpty((float) $articulo->percentage_gain, $articulo->provider_code);
            $this->assertGreaterThan(0, (float) $articulo->price, $articulo->provider_code);
        }

        \App\Services\ImageAssignment\CupoImagenesDePrueba::editar((int) $this->dueno->id, 0);
        $this->avanzar($run);
        $resultado = AiAvisoDelAsistente::where('owner_id', $this->dueno->id)->where('support_ticket_id', 31)
            ->where('texto', 'like', 'Cargué%')->firstOrFail();
        $this->assertStringStartsWith('Cargué 50 artículos con su precio de venta (sin costo).', $resultado->texto);
    }

    public function test_con_el_mapeo_previo_que_fija_el_costo_no_se_pregunta_costo_o_venta_pero_si_el_margen(): void
    {
        $this->analizador($this->mapeo_del_analizador_seguro());
        $run = $this->corrida(null, ['columnas_previas' => [['hoja' => 'LISTA NOV', 'columna' => 'C', 'campo' => 'costo']]]);

        $run = $this->avanzar($run);

        $this->assertSame('columnas', $run->estado);
        $this->assertSame([PlanillaDePrueba::CLAVE_MARGEN], array_keys($run->preguntas), 'Soporte ya confirmó que es el costo: solo falta el margen.');
        $this->assertTrue($run->hojas[0]['columnas'][2]['segura']);
        $this->assertSame('costo', $run->hojas[0]['columnas'][2]['campo']);
    }

    public function test_dos_importes_sin_jerarquia_clara_se_preguntan_los_dos(): void
    {
        $this->analizador([
            'Código' => ['codigo_de_proveedor', 0.97], 'Descripción' => ['nombre', 0.97],
            'Precio Lista' => ['precio', 0.97], 'Precio Contado' => [null, 0.97],
        ]);
        $run = $this->avanzar($this->corrida([
            ['Código', 'Descripción', 'Precio Lista', 'Precio Contado'],
            ['A-1', 'Martillo', 1000, 900],
            ['A-2', 'Pinza', 2500.5, 2250],
        ]));

        $this->assertSame('columnas', $run->estado);
        $this->assertSame(['columna_0_2', 'columna_0_3'], array_keys($run->preguntas));
        $cierre = ' Si es otra cosa, decime cuál; y si no la querés cargar, decime que no la cargue.';
        $this->assertSame('En tu lista hay una columna que se llama «Precio Lista» (por ejemplo: 1.000,00 y 2.500,50).'
            . ' ¿Es lo que te cobra el proveedor o es a lo que vendés vos?'
            . ' Tu lista trae más de un importe (también «Precio Contado»): decime cuál es tu precio de venta de siempre.' . $cierre,
            $run->preguntas['columna_0_2']['texto']);
        $this->assertStringContainsString('(también «Precio Lista»)', $run->preguntas['columna_0_3']['texto']);
    }

    public function test_un_costo_y_un_final_confirman_el_final_y_un_costo_con_precio_de_venta_no_preguntan(): void
    {
        $filas = [['A-1', 'Martillo', 1000, 1400], ['A-2', 'Pinza', 2500, 3500]];

        // «Costo» dice lo que es; «Final» al lado de un costo se confirma.
        $this->analizador(['Código' => ['codigo_de_proveedor', 0.97], 'Descripción' => ['nombre', 0.97], 'Costo' => ['costo', 0.97], 'Final' => ['precio', 0.97]]);
        $run = $this->avanzar($this->corrida(array_merge([['Código', 'Descripción', 'Costo', 'Final']], $filas)));
        $this->assertSame('columnas', $run->estado);
        $this->assertSame(['columna_0_3'], array_keys($run->preguntas));
        $this->assertStringStartsWith('En tu lista hay una columna que se llama «Final» (por ejemplo: 1400, 3500). ¿Qué es? Puede ser a lo que vendés vos (precio de venta).', $run->preguntas['columna_0_3']['texto']);

        // «Costo» y «Precio de venta»: los dos títulos lo dicen, no hay nada que preguntar (ni margen).
        ImportHistory::where('user_id', $this->dueno->id)->whereIn('status', ['en_preparacion', 'en_proceso'])->update(['status' => 'terminado']);
        $this->analizador(['Código' => ['codigo_de_proveedor', 0.97], 'Descripción' => ['nombre', 0.97], 'Costo' => ['costo', 0.97], 'Precio de venta' => ['precio', 0.97]]);
        Bus::fake();
        $run = $this->avanzar($this->corrida(array_merge([['Código', 'Descripción', 'Costo', 'Precio de venta']], $filas)));
        $this->assertSame('importando', $run->estado);
    }

    public function test_que_dice_el_titulo_de_un_importe(): void
    {
        foreach (['Costo', 'COSTO S/IVA', 'costos', 'Precio de costo', 'Precio compra', 'P. Costo'] as $titulo) {
            $this->assertSame('costo', PlanillaDePrueba::importe_segun_titulo($titulo), $titulo);
        }
        foreach (['Precio de venta', 'Precio venta', 'PVP', 'Precio público', 'Precio al público', 'Venta', 'Final', 'Precio final'] as $titulo) {
            $this->assertSame('precio', PlanillaDePrueba::importe_segun_titulo($titulo), $titulo);
        }
        foreach (['PRECIO', 'Importe', 'Valor', '$', 'P. Lista', 'Lista', 'Precio lista', 'Precio unitario', 'Unitario', '', 'Precio Contado', 'Mayorista'] as $titulo) {
            $this->assertNull(PlanillaDePrueba::importe_segun_titulo($titulo), $titulo);
        }
        // Con más de un importe en la hoja, «final» se confirma.
        $this->assertNull(PlanillaDePrueba::importe_segun_titulo('Final', true));
        $this->assertNull(PlanillaDePrueba::importe_segun_titulo('Precio final', true));
        $this->assertSame('precio', PlanillaDePrueba::importe_segun_titulo('Precio de venta', true));

        $columna = function (?string $campo, string $titulo) {
            return ['campo' => $campo, 'segura' => true, 'titulo' => $titulo, 'muestra' => '1', 'ejemplos' => ['1']];
        };
        // El título dice costo y el analizador lo dio por precio de venta: se pregunta.
        $contradicha = PlanillaDePrueba::dudar_de_los_importes([0 => $columna('nombre', 'Descripción'), 1 => $columna('precio', 'Costo s/IVA')]);
        $this->assertTrue($contradicha[0]['segura']);
        $this->assertArrayNotHasKey('importe', $contradicha[0]);
        $this->assertFalse($contradicha[1]['segura']);
        $this->assertTrue($contradicha[1]['importe']);
        // Sin ningún importe no cambia nada.
        $sin_importes = [0 => $columna('nombre', 'Descripción'), 1 => $columna('stock_actual', 'Stock')];
        $this->assertSame($sin_importes, PlanillaDePrueba::dudar_de_los_importes($sin_importes));
    }

    public function test_en_modo_soporte_un_precio_a_secas_no_suma_preguntas(): void
    {
        PruebaHelper::fijar_vencimiento($this->dueno->id, null);
        PruebaHelper::olvidar_cache();
        $this->analizador(['Código' => ['codigo_de_proveedor', 0.97], 'Descripción' => ['nombre', 0.97], 'Precio' => ['precio', 0.97]]);
        $run = $this->avanzar($this->corrida([
            ['Código', 'Descripción', 'Precio'],
            ['SP-1', 'Pinza universal', 1000],
        ], ['modo_soporte' => true]));

        $this->assertSame('confirmacion', $run->estado, 'En un negocio que ya opera queda el circuito de siempre.');
    }

    public function test_el_resumen_dice_que_se_cargo_en_palabras_y_con_singular_y_plural(): void
    {
        $resumen = new \ReflectionMethod(ImportacionDePruebaService::class, 'resumen_en_palabras');
        $resumen->setAccessible(true);
        $servicio = app(ImportacionDePruebaService::class);
        $corrida = function (array $mapeo, array $extra = [], array $decisiones = []) {
            return new ImportacionDePrueba(['user_id' => $this->dueno->id, 'decisiones' => $decisiones, 'hojas' => [['indice' => 0, 'mapeo' => $mapeo] + $extra]]);
        };

        $this->assertSame(
            'Cargué 50 artículos con su costo y un 40 % de ganancia.',
            $resumen->invoke($servicio, $corrida(['nombre' => 1, 'costo' => 2], ['margen_respondido' => '40'], ['margen_de_ganancia' => '40']), 50, 0, 0, 0)
        );
        $this->assertSame(
            'Cargué 50 artículos con su precio de venta (sin costo).',
            $resumen->invoke($servicio, $corrida(['nombre' => 1, 'precio' => 2]), 50, 0, 0, 0)
        );
        $this->assertSame(
            'Cargué 1 artículo con su costo y su precio de venta. 1 fila quedó para revisar.',
            $resumen->invoke($servicio, $corrida(['nombre' => 1, 'costo' => 2, 'precio' => 3]), 1, 0, 0, 1)
        );
        $this->assertSame(
            'Cargué 12 artículos con su costo (el precio de venta lo cargás vos después). 10 son nuevos y 2 ya los tenías: los actualicé.'
            . ' 3 filas quedaron sin cargar. 2 filas quedaron para revisar.',
            $resumen->invoke($servicio, $corrida(['nombre' => 1, 'costo' => 2], [], ['margen_de_ganancia' => 'despues']), 10, 2, 3, 2)
        );
        $this->assertSame(
            'Cargué 1 artículo. Ya lo tenías: lo actualicé. 1 fila quedó sin cargar.',
            $resumen->invoke($servicio, $corrida(['nombre' => 1, 'stock_actual' => 2]), 0, 1, 1, 0)
        );

        $this->assertSame('código de proveedor, IVA y unidad de medida', PlanillaDePrueba::campos_en_palabras(['unidad_medida', 'iva', 'codigo_de_proveedor']));
        $this->assertSame('código de proveedor e IVA', PlanillaDePrueba::campos_en_palabras(['iva', 'codigo_de_proveedor']));
        $this->assertSame('subcategoría y unidades por bulto', PlanillaDePrueba::campos_en_palabras(['sub_categoria', 'u_individuales']));
        $this->assertSame('stock', PlanillaDePrueba::campos_en_palabras(['stock_actual']));
        $this->assertSame('', PlanillaDePrueba::campos_en_palabras([]));
    }

    public function test_el_margen_acepta_un_porcentaje_libre_y_despues_deja_todo_como_hoy(): void
    {
        $filas = [
            ['Código', 'Descripción', 'Costo'],
            ['MG-1', 'Pinza universal', 1000],
            ['MG-2', 'Destornillador philips', 500],
        ];
        $mapeo = ['Código' => ['codigo_de_proveedor', 0.97], 'Descripción' => ['nombre', 0.97], 'Costo' => ['costo', 0.97]];

        // Con costo seguro no hay pregunta de columnas: la primera y única es la del margen.
        $this->analizador($mapeo);
        $run = $this->avanzar($this->corrida($filas));
        $this->assertSame('columnas', $run->estado);
        $this->assertSame([PlanillaDePrueba::CLAVE_MARGEN], array_keys($run->preguntas));

        // Un porcentaje que no está entre las opciones: vale por `acepta_porcentaje`.
        $this->responder($run, ['margen_de_ganancia' => '35,5 %'])->assertStatus(202);
        Bus::fake();
        $run = $this->avanzar($run);
        $this->assertSame('importando', $run->estado);
        $this->correr_la_importacion();
        $this->assertEqualsWithDelta(35.5, (float) Article::where('user_id', $this->dueno->id)->where('provider_code', 'MG-1')->value('percentage_gain'), 0.001);

        // «Lo cargo yo después»: se carga el costo y no se toca ningún margen, como hasta hoy.
        ImportHistory::where('user_id', $this->dueno->id)->whereIn('status', ['en_preparacion', 'en_proceso'])->update(['status' => 'terminado']);
        $filas[1][0] = 'MG-3';
        $filas[2][0] = 'MG-4';
        $this->analizador($mapeo);
        $run = $this->avanzar($this->corrida($filas));
        $this->assertSame([PlanillaDePrueba::CLAVE_MARGEN], array_keys($run->preguntas));
        $this->responder($run, ['margen_de_ganancia' => 'despues'])->assertStatus(202);
        Bus::fake();
        $run = $this->avanzar($run);
        $this->assertSame('importando', $run->estado);
        $motor = $this->correr_la_importacion();
        $this->assertStringNotContainsString('margen', json_encode(array_keys($run->hojas[0]['mapeo'])));
        $this->assertArrayNotHasKey('margen_respondido', $run->hojas[0]);
        $this->assertCount(2, $motor);
        $sin_margen = Article::where('user_id', $this->dueno->id)->where('provider_code', 'MG-3')->firstOrFail();
        $this->assertEqualsWithDelta(1000, (float) $sin_margen->cost, 0.01);
        $this->assertEmpty((float) $sin_margen->percentage_gain);
    }

    public function test_con_solo_precio_de_venta_no_se_pregunta_nada_y_el_precio_se_carga_tal_cual(): void
    {
        $this->analizador(['Producto' => ['nombre', 0.97], 'Precio de venta' => ['precio', 0.97]]);
        $run = $this->corrida([
            ['Producto', 'Precio de venta'],
            ['ZZ Lima plana bastarda', 1250.5],
            ['ZZ Serrucho carpintero', 9800],
        ]);

        Bus::fake();
        $run = $this->avanzar($run);

        $this->assertSame('importando', $run->estado, 'Sin costo no hay nada que preguntar.');
        $this->assertSame([], $run->preguntas ?? []);
        $this->assertSame(0, AiAvisoDelAsistente::where('owner_id', $this->dueno->id)->where('support_ticket_id', 31)->count());

        $this->correr_la_importacion();
        $lima = Article::where('user_id', $this->dueno->id)->where('name', 'like', 'ZZ Lima plana bastarda')->firstOrFail();
        $this->assertEqualsWithDelta(1250.5, (float) $lima->price, 0.01);
        $this->assertEmpty((float) $lima->percentage_gain);
    }

    public function test_solo_se_pregunta_cuando_hay_costo_y_nada_dice_a_cuanto_se_vende(): void
    {
        $necesita = function (array $campos) {
            return PlanillaDePrueba::hoja_necesita_margen(['mapeo' => array_flip($campos)]);
        };

        $this->assertTrue($necesita(['codigo_de_proveedor', 'nombre', 'costo']));
        $this->assertTrue($necesita(['nombre', 'costo', 'iva', 'stock_actual']));
        $this->assertFalse($necesita(['nombre', 'precio']), 'Solo precio de venta: se carga tal cual.');
        $this->assertFalse($necesita(['nombre', 'costo', 'precio']));
        $this->assertFalse($necesita(['nombre', 'costo', 'margen_de_ganancia']));
        $this->assertFalse($necesita(['nombre', 'costo', '%_mayorista']), 'El margen de una lista de precios también cuenta.');
        $this->assertFalse($necesita(['nombre', 'costo', '$_final_mayorista']));
        $this->assertFalse($necesita(['nombre', 'stock_actual']));

        $this->assertSame([], PlanillaDePrueba::pregunta_del_margen([['mapeo' => ['nombre' => 0, 'precio' => 1]]]));
        // Con dos hojas alcanza con que una lo necesite: es UNA pregunta para toda la corrida.
        $this->assertSame(['margen_de_ganancia'], array_keys(PlanillaDePrueba::pregunta_del_margen([
            ['mapeo' => ['nombre' => 0, 'precio' => 1]], ['mapeo' => ['nombre' => 0, 'costo' => 1]],
        ])));

        // El margen se aplica solo a la hoja que lo necesita.
        $hojas = PlanillaDePrueba::aplicar_margen([
            ['mapeo' => ['nombre' => 0, 'precio' => 1]], ['mapeo' => ['nombre' => 0, 'costo' => 1]],
        ], ['margen_de_ganancia' => '30']);
        $this->assertArrayNotHasKey('margen_respondido', $hojas[0]);
        $this->assertSame('30', $hojas[1]['margen_respondido']);
        $this->assertArrayNotHasKey('margen_respondido', PlanillaDePrueba::aplicar_margen([['mapeo' => ['nombre' => 0, 'costo' => 1]]], ['margen_de_ganancia' => 'despues'])[0]);
    }

    public function test_que_respuestas_son_un_porcentaje(): void
    {
        $this->assertSame('35', PlanillaDePrueba::margen_de_respuesta('35'));
        $this->assertSame('35', PlanillaDePrueba::margen_de_respuesta(' 35 % '));
        $this->assertSame('35.5', PlanillaDePrueba::margen_de_respuesta('35,5'));
        $this->assertSame('12.25', PlanillaDePrueba::margen_de_respuesta('12.25%'));
        $this->assertSame('100', PlanillaDePrueba::margen_de_respuesta('100.00'));
        $this->assertSame('1000', PlanillaDePrueba::margen_de_respuesta('1000'));

        foreach (['despues', '', '0', '0,0', '-20', '1001', 'treinta', '30 por ciento', '3e2', '20;30', null, ['30']] as $invalida) {
            $this->assertNull(PlanillaDePrueba::margen_de_respuesta($invalida), json_encode($invalida));
        }
    }

    public function test_la_importacion_desde_soporte_de_un_negocio_que_ya_opera_no_pregunta_el_margen(): void
    {
        // Negocio que contrató: sin vencimiento. En modo soporte un margen único pisaría el de cada artículo.
        PruebaHelper::fijar_vencimiento($this->dueno->id, null);
        PruebaHelper::olvidar_cache();
        $this->analizador(['Código' => ['codigo_de_proveedor', 0.97], 'Descripción' => ['nombre', 0.97], 'Costo' => ['costo', 0.97]]);
        $run = $this->corrida([
            ['Código', 'Descripción', 'Costo'],
            ['SP-1', 'Pinza universal', 1000],
        ], ['modo_soporte' => true]);

        $run = $this->avanzar($run);

        $this->assertSame('confirmacion', $run->estado, 'Pasa directo a la confirmación de soporte, como hasta hoy.');
        $this->assertSame(['confirmacion'], array_keys($run->preguntas));
    }

    /**
     * @dataProvider filas_de_la_planilla
     */
    public function test_que_filas_no_son_articulos(?string $esperado, array $fila, bool $es_el_cierre, string $caso): void
    {
        // Columnas de la lista: A código, B nombre, C costo, D IVA, E unidad (sin mapear), F bulto (sin mapear).
        $mapeo = ['codigo_de_proveedor' => 0, 'nombre' => 1, 'costo' => 2, 'iva' => 3];

        $this->assertSame($esperado, PlanillaDePrueba::motivo_para_saltear($fila, $mapeo, $es_el_cierre), $caso);
    }

    public function filas_de_la_planilla(): array
    {
        $titulo = PlanillaDePrueba::FILA_TITULO;
        $total = PlanillaDePrueba::FILA_TOTAL;

        return [
            // TÍTULOS: un único valor en toda la fila, decorado de los dos lados o suelto en la columna del código.
            [$titulo, ['--- TORNILLERIA ---', '', '', '', '', ''], false, 'rubro en la columna del código'],
            [$titulo, ['HERRAMIENTAS ELÉCTRICAS', '', '', '', '', ''], false, 'rubro sin decorar en la columna del código'],
            [$titulo, ['LINEA 2000', '', '', '', '', ''], false, 'un rubro puede traer números si tiene espacios'],
            [$titulo, ['Consulte por descuentos por cantidad.', '', '', '', '', ''], false, 'nota al pie en la columna del código'],
            [$titulo, ['', '--- PINTURERIA ---', '', '', '', ''], false, 'decorado de los dos lados en la columna del nombre'],
            [$titulo, ['', '*** OFERTAS ***', '', '', '', ''], false, 'con asteriscos'],
            [$titulo, ['', '=== SANITARIOS ===', '', '', '', ''], false, 'con iguales'],
            [$titulo, ['', '## ELECTRICIDAD ##', '', '', '', ''], false, 'con numerales'],
            [$titulo, ["\u{00A0}", '== SANITARIOS ==', ' ', '', '', ''], false, 'los espacios duros no cuentan como dato'],
            [$titulo, ['--- TORNILLERIA ---', '', '', '', '', ''], true, 'un título también puede ser la última fila'],
            // Lo que parece un título y es un artículo: ante la duda, se importa.
            [null, ['', '**Martillo carpintero', '', '', '', ''], false, 'decorado solo de apertura: es un nombre'],
            [null, ['', '-- Martillo 500g --', '', '', '', ''], false, 'decorado, pero con números adentro: es un artículo'],
            [null, ['', 'Martillo', '', '', '', ''], false, 'un nombre suelto es un artículo sin precio'],
            [null, ['', 'Tornillo sin precio', '', '', '', ''], false, 'otro artículo sin código ni precio'],
            [null, ['', '--- OFERTA', '', '', '', ''], false, 'sin cierre no es un título'],
            [null, ['', '--- OFERTA ***', '', '', '', ''], false, 'abre y cierra con signos distintos'],
            [null, ['DL-1002', '', '', '', '', ''], false, 'un código solo sigue yendo al motor'],
            [null, ['ABC123', '', '', '', '', ''], false, 'un código de texto solo, también'],
            [null, ['7790001112223', '', '', '', '', ''], false, 'un código de barras solo'],
            [null, ['', '', '1500', '', '', ''], false, 'un importe solo no es un título'],
            [null, ['', '', '', '', '', 'Precios sujetos a cambio'], false, 'una nota sin decorar fuera de la columna del código'],
            [null, ['DL-1002', 'TORNILLO AUTOPERFORANTE 8X1', '2054.9', '10.5', 'UN', '100'], false, 'un artículo completo'],
            [null, ['--- OFERTA ---', 'Taladro', '', '', '', ''], false, 'con dos datos ya no es un título'],
            // TOTAL: solo la fila de cierre, sin código, con nombre que empieza con total/subtotal/suma y un importe como mucho.
            [$total, ['', 'TOTAL LISTA', '1154150.97', '', '', ''], true, 'el total del fixture'],
            [$total, ['', 'Total general', '$ 1.154.150,97', '', '', ''], true, 'con formato de plata'],
            [$total, ['', 'Subtotal', '', '', '', '350000'], true, 'el importe en una columna sin mapear'],
            [$total, ['', 'SUMA DE IMPORTES', '9000', '', '', ''], true, 'suma'],
            [$total, ['', 'Sub total sin IVA', '9000', '', '', ''], true, 'sub total en dos palabras'],
            [$total, ['', 'Totales', '5', '', '', ''], true, 'en plural'],
            [$total, ['', 'TOTAL', '', '', '', ''], true, 'el total sin importe'],
            // Las mismas filas en el MEDIO de la planilla son artículos.
            [null, ['', 'Suma', '4500', '', '', ''], false, 'Suma en el medio'],
            [null, ['', 'Total', '1500', '', '', ''], false, 'Total en el medio'],
            [null, ['', 'Subtotal', '', '120000', '', ''], false, 'Subtotal en el medio'],
            [null, ['', 'TOTAL LISTA', '1154150.97', '', '', ''], false, 'hasta el total del fixture, si no es el cierre'],
            // Y en el cierre, lo que no es un total.
            [null, ['', 'Stock total', '15', '', '', ''], true, 'no empieza con total'],
            [null, ['', 'Totalmente nuevo', '100', '', '', ''], true, 'totalmente no es total'],
            [null, ['T-1', 'TOTAL LISTA', '1154150.97', '', '', ''], true, 'con código es un artículo'],
            [null, ['', 'TOTAL LISTA', '1154150.97', '21', '', ''], true, 'con más de un dato al lado no es el total'],
            [null, ['', 'TOTAL LISTA', 'ver detalle', '', '', ''], true, 'sin importe al lado no es el total'],
            [null, ['', '', '', '', '', ''], true, 'una fila vacía la saltea quien la lee, no esta regla'],
        ];
    }

    public function test_los_casos_que_encontro_la_revision_se_importan(): void
    {
        // Una planilla del dueño sin códigos: A nombre, B precio.
        $sin_codigos = ['nombre' => 0, 'precio' => 1];
        foreach ([['Suma', '4500'], ['Total', '1500'], ['Stock total', '15'], ['**Martillo carpintero', ''], ['-- Martillo 500g --', ''], ['Martillo', '']] as $fila) {
            $this->assertNull(PlanillaDePrueba::motivo_para_saltear($fila, $sin_codigos), json_encode($fila, JSON_UNESCAPED_UNICODE));
        }
        $this->assertNull(PlanillaDePrueba::motivo_para_saltear(['', 'Subtotal', '', '120000'], ['codigo_de_proveedor' => 0, 'nombre' => 1, 'costo' => 3]));

        // Una fila con solo un código de texto: sin columna de nombre no hay con qué decir que es un rubro.
        $solo_codigos = ['codigo_de_proveedor' => 0, 'costo' => 1];
        $this->assertNull(PlanillaDePrueba::motivo_para_saltear(['TANZA', ''], $solo_codigos));
        $this->assertNull(PlanillaDePrueba::motivo_para_saltear(['Caña telescópica', ''], $solo_codigos));
        // Con columna de nombre, un texto suelto en la columna del código es un rubro; un código, no.
        $con_nombre = ['codigo_de_proveedor' => 0, 'nombre' => 1, 'costo' => 2];
        $this->assertSame(PlanillaDePrueba::FILA_TITULO, PlanillaDePrueba::motivo_para_saltear(['TANZA', '', ''], $con_nombre));
        $this->assertNull(PlanillaDePrueba::motivo_para_saltear(['TZ-40', '', ''], $con_nombre));
    }

    /**
     * La misma planilla leída en la prueba y en modo soporte: lo que llega al motor.
     *
     * @return array [filas de la planilla, mapeo de la IA]
     */
    private function planilla_con_totales_en_el_medio(): array
    {
        return [[
            ['Código', 'Descripción', 'Precio de venta'],
            ['A-1', 'Martillo', 100],
            [null, 'Total', 1500],
            ['A-2', 'Pinza', 200],
            ['--- HERRAMIENTAS DE MANO ---', null, null],
            [null, 'Suma', 4500],
            [null, '**Martillo carpintero', null],
            ['A-3', 'Sierra', 300],
            [null, 'TOTAL', 6600],
            ['Precios sujetos a cambio', null, null],
        ], ['Código' => ['codigo_de_proveedor', 0.97], 'Descripción' => ['nombre', 0.97], 'Precio de venta' => ['precio', 0.97]]];
    }

    public function test_en_la_prueba_solo_se_descartan_el_titulo_la_nota_y_el_total_del_pie(): void
    {
        [$filas, $mapeo] = $this->planilla_con_totales_en_el_medio();
        $this->analizador($mapeo);
        $run = $this->corrida($filas);

        Bus::fake();
        $run = $this->avanzar($run);
        $this->assertSame('importando', $run->estado);

        $inicios = Bus::dispatched(ProcessArticleChunk::class);
        $this->assertCount(1, $inicios);
        $columnas = array_flip(['proveedor', PlanillaDePrueba::SEPARAR_CODIGO, 'codigo_de_proveedor', 'nombre', 'precio']);
        $nombres = array_column($inicios->first()->get_row_from_csv(), $columnas['nombre']);

        // «Total» y «Suma» del medio y el nombre suelto son artículos; el título, el total del pie y la nota, no.
        $this->assertSame(['Martillo', 'Total', 'Pinza', 'Suma', '**Martillo carpintero', 'Sierra'], $nombres);
        $salteadas = $run->hojas[0]['filas_salteadas'];
        $this->assertSame(2, $salteadas['titulo']);
        $this->assertSame(1, $salteadas['total']);
        $this->assertSame(
            ' Dejé afuera 3 filas que no son artículos: “--- HERRAMIENTAS DE MANO ---”, “Precios sujetos a cambio”, “TOTAL”.'
            . ' Si alguna era un producto, decímelo y la cargo.',
            PlanillaDePrueba::texto_de_filas_salteadas($run->hojas)
        );
    }

    public function test_en_modo_soporte_no_se_descarta_nada_nuevo(): void
    {
        // Negocio que ya opera (sin vencimiento): ahí la planilla se lee como hasta hoy.
        PruebaHelper::fijar_vencimiento($this->dueno->id, null);
        PruebaHelper::olvidar_cache();
        [$filas, $mapeo] = $this->planilla_con_totales_en_el_medio();
        $this->analizador($mapeo);
        $run = $this->avanzar($this->corrida($filas, ['modo_soporte' => true]));

        $this->assertSame('confirmacion', $run->estado);
        $this->assertStringContainsString('con 9 filas para importar', $run->preguntas['confirmacion']['texto'], 'Las nueve filas con datos, títulos y totales incluidos.');
        $this->assertStringNotContainsString('Dejé afuera', $run->preguntas['confirmacion']['texto']);

        $planilla = new PlanillaDePrueba($run);
        $leidas = iterator_to_array($planilla->filas($planilla->resolver_columnas($run->hojas, [])), false);
        $this->assertCount(9, $leidas);
        $this->assertSame('--- HERRAMIENTAS DE MANO ---', $leidas[3]['codigo_de_proveedor']);
        $this->assertSame('TOTAL', $leidas[7]['nombre']);
    }

    public function test_el_resumen_dice_cuales_filas_quedaron_afuera(): void
    {
        $this->assertSame('', PlanillaDePrueba::texto_de_filas_salteadas([['indice' => 0]]));
        $this->assertSame(
            ' Dejé afuera 1 fila que no es un artículo: “TOTAL LISTA”. Si era un producto, decímelo y la cargo.',
            PlanillaDePrueba::texto_de_filas_salteadas([['filas_salteadas' => ['total' => 1, 'textos_total' => ['TOTAL LISTA']]]])
        );
        // Dos hojas, más títulos que cupo: los primeros, puntos suspensivos y el total a la vista al final.
        $this->assertSame(
            ' Dejé afuera 8 filas que no son artículos: “--- A ---”, “--- B ---”, “--- C ---”, “--- D ---”, …, “Total general”.'
            . ' Si alguna era un producto, decímelo y la cargo.',
            PlanillaDePrueba::texto_de_filas_salteadas([
                ['filas_salteadas' => ['titulo' => 2, 'textos_titulo' => ['--- A ---', '--- B ---']]],
                ['filas_salteadas' => ['titulo' => 5, 'total' => 1, 'textos_titulo' => ['--- C ---', '--- D ---', '--- E ---', '--- F ---', '--- G ---'], 'textos_total' => ['Total general']]],
            ])
        );
        // Sin total, entran cinco títulos.
        $this->assertSame(
            ' Dejé afuera 2 filas que no son artículos: “--- A ---”, “--- B ---”. Si alguna era un producto, decímelo y la cargo.',
            PlanillaDePrueba::texto_de_filas_salteadas([['filas_salteadas' => ['titulo' => 2, 'textos_titulo' => ['--- A ---', '--- B ---']]]])
        );
        // Una corrida guardada antes de que existieran los textos: dice cuántas, sin inventar cuáles.
        $this->assertSame(
            ' Dejé afuera 3 filas que no son artículos. Si alguna era un producto, decímelo y la cargo.',
            PlanillaDePrueba::texto_de_filas_salteadas([['filas_salteadas' => ['titulo' => 2, 'total' => 1]]])
        );
    }
}

/**
 * El analizador real con una respuesta de IA fija: el mapeo que le dio el test, por título de columna.
 */
class AnalizadorDeListaDeProveedorFalso extends AiExcelAnalyzer
{
    /** @var array título de columna => [campo, confianza] */
    public $mapeo = [];

    /**
     * @param  string $prompt
     * @return string
     */
    protected function call_claude(string $prompt): string
    {
        $columnas = [];
        foreach ($this->mapeo as $titulo => $destino) {
            $columnas[] = [
                'excel_column' => $titulo, 'system_property' => $destino[0],
                'confidence' => $destino[1], 'interpretation_note' => null,
            ];
        }

        return json_encode([
            'column_mapping' => $columnas, 'provider_id' => null, 'provider_confidence' => 'bajo',
            'assistant_notes' => [], 'encabezados_repetidos' => [],
        ]);
    }
}
