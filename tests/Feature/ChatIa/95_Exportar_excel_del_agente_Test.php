<?php

namespace Tests\Feature\ChatIa;

use App\Exports\TablaDelAgenteExport;
use App\Http\Controllers\Helpers\asistente_ia\ArchivosDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\LinkDePdfIaHelper;
use App\Models\AiArchivo;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\EmpresaTestCase;

/**
 * Misión agente-mejoras-uso-real (29/10/2026, §4 E3.1 del plan) — exportar_excel.
 *
 * EL CASO REAL (CONV 9 de Ferre Total, 28/10/2026): el dueño pidió el ranking de ventas de hoy y
 * después *"¿me lo podés volcar en un archivo Excel?"*. El agente contestó *"No puedo generar archivos
 * Excel desde acá"*. Lo que protege este archivo:
 *
 *  - la herramienta existe en los TRES canales (sistema, WhatsApp y la app), al final del registro de
 *    lectura (el prefijo del caché);
 *  - el ranking se vuelca en un .xlsx de verdad, con los números como NÚMEROS y el link que lo baja;
 *  - 🔴 la seguridad del link: el archivo vive en el disco privado, el token se guarda como sha256 y se
 *    compara con hash_equals, sin token / con otro token / vencido / ajeno / borrado es 404, y la ruta
 *    del archivo sale de la fila y de la carpeta de su dueño;
 *  - 🔴 una celda que empieza con "=" queda como TEXTO (nunca una fórmula que se ejecute al abrirlo);
 *  - una tabla inválida vuelve con el motivo y no escribe nada;
 *  - la limpieza diaria (`asistente:limpiar-archivos`, en el scheduler) borra los vencidos.
 *
 * 🔴 Nada sale a la red: el disco `local` es falso (Storage::fake) y no hay llamada al modelo.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Exportar_excel_del_agente_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio();

        Storage::fake(ArchivosDelAgenteIaHelper::DISCO);
    }

    /**
     * La tabla del ranking de la CONV 9 (los diez primeros de hoy, en unidades y en pesos), como la
     * mandaría el modelo.
     *
     * @return array
     */
    protected function ranking_de_hoy(): array
    {
        $productos = [
            ['TORNILLO DRYWALL GRUESO 6 X 2', 500, 13600],
            ['TARUGOS 6 ESPIGA EN BOLSA DE 1000 U.', 263, 8344.99],
            ['PRECINTOS 02 150 MM X 3,6 MM', 200, 4718],
            ['TORNILLO DRYWALL GRUESO 6X1 1/2', 154, 3311],
            ['REMACHES 4 X 10 EN CAJA X 1000 U.', 150, 4126.5],
            ['TARUGOS 8 ESPIGA EN BOLSA DE 500 U.', 149, 7928.29],
            ['TORNILLO DRYWALL FINO 6 X 1', 130, 1965.6],
            ['PRECINTOS 06 350 MM X 4,8 MM', 100, 9990],
            ['TORNILLO FIX 3.5 X 35', 75, 1308],
            ['TORNILLO C/TANQUE P.MECHA 8 X 1/2" (400) R', 60, 924.6],
        ];

        $filas = [];

        foreach ($productos as $i => $producto) {
            $filas[] = [
                'puesto'   => $i + 1,
                'producto' => $producto[0],
                'unidades' => $producto[1],
                'total'    => $producto[2],
                'margen'   => 15,
            ];
        }

        return [
            'titulo'   => 'Ranking de ventas de hoy',
            'columnas' => [
                ['clave' => 'puesto', 'etiqueta' => 'Puesto', 'formato' => 'numero'],
                ['clave' => 'producto', 'etiqueta' => 'Producto', 'formato' => 'texto'],
                ['clave' => 'unidades', 'etiqueta' => 'Unidades', 'formato' => 'numero'],
                ['clave' => 'total', 'etiqueta' => 'Total vendido', 'formato' => 'moneda'],
                ['clave' => 'margen', 'etiqueta' => 'Margen', 'formato' => 'porcentaje'],
            ],
            'filas'    => $filas,
        ];
    }

    /**
     * Corre exportar_excel por el despacho real del loop, con el mensaje del agente en curso (por
     * WhatsApp, como la CONV 9).
     *
     * @param  array  $input
     * @return array  Lo que ve el modelo.
     */
    protected function exportar(array $input)
    {
        list($conversation, $assistant) = $this->vuelta('¿me lo podés volcar en un archivo Excel?');

        $this->assertInstanceOf(AiMessage::class, $assistant);

        $resultados = (new AsistenteIaService())->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_excel_' . uniqid(),
            'name'  => 'exportar_excel',
            'input' => $input,
        ]], $conversation, $assistant);

        $this->assertCount(1, $resultados);
        $this->assertArrayNotHasKey('is_error', $resultados[0], $resultados[0]['content']);

        return json_decode($resultados[0]['content'], true);
    }

    /**
     * El uuid y el token de un link del agente.
     *
     * @param  string  $link
     * @return array{0: string, 1: string}
     */
    protected function partes_del_link($link): array
    {
        $path = (string) parse_url($link, PHP_URL_PATH);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        return [basename($path), isset($query['t']) ? (string) $query['t'] : ''];
    }

    /**
     * 🔴 La herramienta está en los tres canales y es la ÚLTIMA del registro de lectura (prefijo del caché).
     *
     * @test
     */
    public function exportar_excel_esta_en_los_tres_canales_al_final_del_registro_de_lectura()
    {
        $servicio = new AsistenteIaService();

        $lectura = $servicio->nombres_de_lectura();

        /*
         * 🔴 Lo que cuida el caché es que exportar_excel entró DESPUÉS de todo lo que había (justo detrás de
         * consultar_escaneo_de_factura, la última de antes) y que nada de lo anterior se movió. Ya NO se
         * afirma que sea la última: la regla del prefijo manda sumar cada herramienta nueva al final, y la
         * misma misión (E2, consultar_articulos_sin_rotacion) sumó otra detrás. Mismo criterio que
         * 36_Guardas_de_las_cargas_genericas desde el 21/9/2026.
         */
        $donde = array_search('exportar_excel', $lectura, true);

        $this->assertNotFalse($donde, 'exportar_excel no está en el registro de lectura.');
        $this->assertSame('consultar_escaneo_de_factura', $lectura[$donde - 1], 'Al final de lo que había: el prefijo de las de antes no cambia.');

        $this->assertContains('exportar_excel', array_column($servicio->build_tools(false, false), 'name'), 'Sistema, sin acciones.');
        $this->assertContains('exportar_excel', array_column($servicio->build_tools(true, false, $this->dueno), 'name'), 'Sistema, con acciones.');
        $this->assertContains('exportar_excel', array_column($servicio->build_tools(true, true, $this->dueno), 'name'), 'WhatsApp.');
        $this->assertContains('exportar_excel', array_column($servicio->build_tools(true, false, $this->dueno, true), 'name'), 'La app.');

        $descripcion = '';

        foreach ($servicio->herramientas_de_lectura() as $herramienta) {
            if ($herramienta['name'] === 'exportar_excel') {
                $descripcion = (string) $herramienta['description'];
                $this->assertSame(['titulo', 'columnas', 'filas'], $herramienta['input_schema']['required']);
            }
        }

        $this->assertStringContainsString('Nunca digas que no podés generar un Excel', $descripcion);
        $this->assertStringContainsString('TAL CUAL', $descripcion);
    }

    /**
     * 🔴 CONV 9: el ranking se vuelca en un .xlsx real, con los números como números, y el link lo baja.
     *
     * @test
     */
    public function el_ranking_se_vuelca_en_un_excel_que_baja_por_el_link()
    {
        $binder_antes = Cell::getValueBinder();

        $respuesta = $this->exportar($this->ranking_de_hoy());

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        // 🔴 El binder estático de PhpSpreadsheet vuelve a ser el de antes: si no, el próximo Excel del mismo
        // proceso (una importación en el worker) pasaría por el de esta export.
        $this->assertSame($binder_antes, Cell::getValueBinder());
        $this->assertNotInstanceOf(TablaDelAgenteExport::class, Cell::getValueBinder());

        $this->assertSame(10, $respuesta['filas']);
        $this->assertSame(5, $respuesta['columnas']);
        $this->assertSame('ranking-de-ventas-de-hoy.xlsx', $respuesta['nombre_archivo']);
        $this->assertSame(now()->addDays(7)->format('d/m/Y'), $respuesta['vence']);
        $this->assertStringContainsString('TAL CUAL', $respuesta['nota']);

        // El link: la base pública del dueño, la ruta y el token.
        list($uuid, $token) = $this->partes_del_link($respuesta['link']);

        $this->assertStringStartsWith(LinkDePdfIaHelper::base_publica($this->dueno->id) . '/asistente/archivo/' . $uuid . '?t=', $respuesta['link']);
        $this->assertSame(ArchivosDelAgenteIaHelper::LARGO_DEL_TOKEN, strlen($token));

        // La fila: del dueño, en su carpeta del disco privado, y SIN el token en claro.
        $archivo = AiArchivo::where('uuid', $uuid)->first();

        $this->assertNotNull($archivo);
        $this->assertSame($this->dueno->id, (int) $archivo->user_id);
        $this->assertSame('asistente_archivos/' . $this->dueno->id . '/' . $uuid . '.xlsx', $archivo->path);
        $this->assertSame(hash('sha256', $token), $archivo->token_hash);
        $this->assertNotSame($token, $archivo->token_hash);
        $this->assertStringNotContainsString($token, json_encode(AiArchivo::find($archivo->id)->toArray()), 'El hash del token no sale en el JSON del modelo.');
        $this->assertNotNull($archivo->ai_message_id, 'Queda registrado de qué respuesta salió.');
        Storage::disk(ArchivosDelAgenteIaHelper::DISCO)->assertExists($archivo->path);

        // La descarga, sin sesión: el dueño lo abre desde WhatsApp.
        $this->app['auth']->forgetGuards();

        $descarga = $this->get('/asistente/archivo/' . $uuid . '?t=' . $token);

        $descarga->assertStatus(200);
        $this->assertStringContainsString('ranking-de-ventas-de-hoy.xlsx', (string) $descarga->headers->get('content-disposition'));
        $this->assertStringContainsString('attachment', (string) $descarga->headers->get('content-disposition'));
        $this->assertSame(ArchivosDelAgenteIaHelper::MIME_XLSX, $descarga->headers->get('content-type'));
        $this->assertStringContainsString('no-store', (string) $descarga->headers->get('cache-control'));

        // El contenido: encabezado, el ranking en orden y los números como NÚMEROS.
        $hoja = IOFactory::load(Storage::disk(ArchivosDelAgenteIaHelper::DISCO)->path($archivo->path))->getActiveSheet();

        $this->assertSame('Ranking de ventas de hoy', $hoja->getTitle());
        $this->assertSame(['Puesto', 'Producto', 'Unidades', 'Total vendido', 'Margen'], [
            $hoja->getCell('A1')->getValue(), $hoja->getCell('B1')->getValue(), $hoja->getCell('C1')->getValue(),
            $hoja->getCell('D1')->getValue(), $hoja->getCell('E1')->getValue(),
        ]);
        $this->assertSame('TORNILLO DRYWALL GRUESO 6 X 2', $hoja->getCell('B2')->getValue());
        $this->assertSame(DataType::TYPE_NUMERIC, $hoja->getCell('C2')->getDataType());
        $this->assertEquals(500, $hoja->getCell('C2')->getValue());
        $this->assertEquals(8344.99, $hoja->getCell('D3')->getValue());
        $this->assertSame('TORNILLO C/TANQUE P.MECHA 8 X 1/2" (400) R', $hoja->getCell('B11')->getValue());
        // El porcentaje en puntos (15) queda como 15 % de Excel (0,15 con formato porcentaje).
        $this->assertEquals(0.15, $hoja->getCell('E2')->getValue());
        $this->assertStringContainsString('%', $hoja->getStyle('E2')->getNumberFormat()->getFormatCode());
        $this->assertStringContainsString('$', $hoja->getStyle('D2')->getNumberFormat()->getFormatCode());
        $this->assertNull($hoja->getCell('A12')->getValue(), 'Diez filas y nada más.');
    }

    /**
     * 🔴 Un texto que empieza con "=" (un nombre cargado por cualquiera) queda como TEXTO, nunca como fórmula.
     *
     * @test
     */
    public function una_celda_que_empieza_con_igual_queda_como_texto_y_no_como_formula()
    {
        $respuesta = $this->exportar([
            'titulo'   => 'Clientes: deuda / saldo [octubre]',
            'columnas' => [
                ['clave' => 'nombre', 'etiqueta' => '=Nombre', 'formato' => 'texto'],
                ['clave' => 'deuda', 'etiqueta' => 'Deuda', 'formato' => 'moneda'],
            ],
            'filas'    => [
                ['nombre' => '=HYPERLINK("http://malo.test","tocame")', 'deuda' => 1000],
                ['nombre' => '+54 9 11 1234', 'deuda' => null],
            ],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        list($uuid) = $this->partes_del_link($respuesta['link']);

        $hoja = IOFactory::load(Storage::disk(ArchivosDelAgenteIaHelper::DISCO)->path(AiArchivo::where('uuid', $uuid)->value('path')))->getActiveSheet();

        $this->assertSame(DataType::TYPE_STRING, $hoja->getCell('A1')->getDataType());
        $this->assertSame(DataType::TYPE_STRING, $hoja->getCell('A2')->getDataType());
        $this->assertSame('=HYPERLINK("http://malo.test","tocame")', $hoja->getCell('A2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $hoja->getCell('A3')->getDataType());
        $this->assertNull($hoja->getCell('B3')->getValue(), 'Un null queda vacío.');

        // El nombre de la hoja no lleva los caracteres que Excel no acepta y entra en 31.
        $this->assertSame('Clientes deuda saldo octubre', $hoja->getTitle());
    }

    /**
     * 🔴 El link no abre sin el token, con otro token, vencido, con la fila tocada o con el archivo borrado:
     * siempre el mismo 404.
     *
     * @test
     */
    public function el_link_sin_token_con_otro_token_vencido_o_borrado_da_404()
    {
        $respuesta = $this->exportar($this->ranking_de_hoy());

        list($uuid, $token) = $this->partes_del_link($respuesta['link']);

        $this->app['auth']->forgetGuards();

        $url = '/asistente/archivo/' . $uuid;

        // Sin token, con un token equivocado del mismo largo, con uno corto y con un array.
        $this->get($url)->assertStatus(404);
        $this->get($url . '?t=' . str_repeat('a', ArchivosDelAgenteIaHelper::LARGO_DEL_TOKEN))->assertStatus(404);
        $this->get($url . '?t=' . substr($token, 0, 10))->assertStatus(404);
        $this->get($url . '?t[]=' . $token)->assertStatus(404);

        // Un uuid que no existe, con el token bueno.
        $this->get('/asistente/archivo/00000000-0000-4000-8000-000000000000?t=' . $token)->assertStatus(404);

        // Con el bueno, abre.
        $this->get($url . '?t=' . $token)->assertStatus(200);

        // 🔴 Si alguien tocara la fila para que apunte afuera de la carpeta de su dueño, no se sirve.
        $archivo = AiArchivo::where('uuid', $uuid)->first();
        $path_original = $archivo->path;

        Storage::disk(ArchivosDelAgenteIaHelper::DISCO)->put('.env.copia', 'APP_KEY=secreto');
        AiArchivo::where('id', $archivo->id)->update(['path' => 'asistente_archivos/' . $this->dueno->id . '/../../.env.copia']);
        $this->get($url . '?t=' . $token)->assertStatus(404);

        AiArchivo::where('id', $archivo->id)->update(['path' => 'asistente_archivos/999999/' . $uuid . '.xlsx']);
        $this->get($url . '?t=' . $token)->assertStatus(404);

        AiArchivo::where('id', $archivo->id)->update(['path' => $path_original]);
        $this->get($url . '?t=' . $token)->assertStatus(200);

        // Vencido: el token es el correcto y aun así 404, con el texto para el dueño.
        AiArchivo::where('id', $archivo->id)->update(['expires_at' => Carbon::now()->subMinute()]);

        $vencido = $this->get($url . '?t=' . $token);

        $vencido->assertStatus(404);
        $this->assertStringContainsString('venció', $vencido->getContent());

        // Vigente otra vez, pero con el archivo borrado del disco.
        AiArchivo::where('id', $archivo->id)->update(['expires_at' => Carbon::now()->addDay()]);
        Storage::disk(ArchivosDelAgenteIaHelper::DISCO)->delete($path_original);

        $this->get($url . '?t=' . $token)->assertStatus(404);
    }

    /**
     * 🔴 Nunca el archivo de otro dueño: el token de uno no abre el uuid del otro, y cada uno queda en
     * su carpeta.
     *
     * @test
     */
    public function el_token_de_un_dueno_no_abre_el_archivo_de_otro()
    {
        $mio = $this->exportar($this->ranking_de_hoy());

        list($uuid_mio, $token_mio) = $this->partes_del_link($mio['link']);

        // Otro negocio de la misma base, con su propio Excel.
        $otro = User::create([
            'name'         => 'ZZE Otro negocio',
            'company_name' => 'ZZE Otro negocio',
            'email'        => 'zze-otro-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
            'api_url'      => 'https://api-otro.comerciocity.com',
        ]);

        $suyo = ArchivosDelAgenteIaHelper::exportar_excel($otro->id, null, null, $this->ranking_de_hoy());

        $this->assertTrue($suyo['ok'], json_encode($suyo));

        list($uuid_suyo, $token_suyo) = $this->partes_del_link($suyo['link']);

        $this->assertStringStartsWith('asistente_archivos/' . $otro->id . '/', AiArchivo::where('uuid', $uuid_suyo)->value('path'));
        $this->assertNull(AiArchivo::where('uuid', $uuid_suyo)->value('ai_message_id'), 'Sin mensaje (como el MCP) anda igual.');

        $this->app['auth']->forgetGuards();

        $this->get('/asistente/archivo/' . $uuid_suyo . '?t=' . $token_mio)->assertStatus(404);
        $this->get('/asistente/archivo/' . $uuid_mio . '?t=' . $token_suyo)->assertStatus(404);

        $this->get('/asistente/archivo/' . $uuid_suyo . '?t=' . $token_suyo)->assertStatus(200);
        $this->get('/asistente/archivo/' . $uuid_mio . '?t=' . $token_mio)->assertStatus(200);
    }

    /**
     * Una tabla inválida vuelve con el motivo para que el modelo la corrija, y no escribe nada.
     *
     * @test
     */
    public function una_tabla_invalida_vuelve_con_el_motivo_y_no_escribe_nada()
    {
        $antes = AiArchivo::count();

        $columnas = [['clave' => 'total', 'etiqueta' => 'Total', 'formato' => 'moneda']];

        // Un número con formato no es un dato.
        $con_formato = $this->exportar(['titulo' => 'Ventas', 'columnas' => $columnas, 'filas' => [['total' => '$ 1.234,50']]]);

        $this->assertFalse($con_formato['ok']);
        $this->assertNull($con_formato['link']);
        $this->assertStringContainsString('número sin formato', $con_formato['error']);

        // Sin filas.
        $sin_filas = $this->exportar(['titulo' => 'Ventas', 'columnas' => $columnas, 'filas' => []]);

        $this->assertFalse($sin_filas['ok']);
        $this->assertStringContainsString('no tiene filas', $sin_filas['error']);

        // Más de 1000 filas (el techo del Excel; la tabla de la app tiene 300).
        $filas = [];
        for ($i = 0; $i < ArchivosDelAgenteIaHelper::MAX_FILAS_DEL_EXCEL + 1; $i++) {
            $filas[] = ['total' => $i];
        }

        $de_mas = $this->exportar(['titulo' => 'Ventas', 'columnas' => $columnas, 'filas' => $filas]);

        $this->assertFalse($de_mas['ok']);
        $this->assertStringContainsString('más de 1000 filas', $de_mas['error']);

        // Y 1000 justas sí entran (más que las 300 de mostrar_tabla).
        $mil = $this->exportar(['titulo' => 'Ventas', 'columnas' => $columnas, 'filas' => array_slice($filas, 0, 1000)]);

        $this->assertTrue($mil['ok'], json_encode($mil));
        $this->assertSame(1000, $mil['filas']);

        $this->assertSame($antes + 1, AiArchivo::count(), 'Solo la tabla válida dejó un archivo.');
    }

    /**
     * La limpieza diaria borra los vencidos (archivo y fila) y deja los vigentes; y está en el scheduler.
     *
     * @test
     */
    public function la_limpieza_diaria_borra_los_vencidos_y_deja_los_vigentes()
    {
        $vigente = $this->exportar($this->ranking_de_hoy());
        $vencido = $this->exportar($this->ranking_de_hoy());

        list($uuid_vigente) = $this->partes_del_link($vigente['link']);
        list($uuid_vencido) = $this->partes_del_link($vencido['link']);

        $path_vencido = AiArchivo::where('uuid', $uuid_vencido)->value('path');
        $path_vigente = AiArchivo::where('uuid', $uuid_vigente)->value('path');

        AiArchivo::where('uuid', $uuid_vencido)->update(['expires_at' => Carbon::now()->subDays(1)]);

        $this->assertSame(0, Artisan::call('asistente:limpiar-archivos'));

        $this->assertNull(AiArchivo::where('uuid', $uuid_vencido)->first());
        Storage::disk(ArchivosDelAgenteIaHelper::DISCO)->assertMissing($path_vencido);

        $this->assertNotNull(AiArchivo::where('uuid', $uuid_vigente)->first());
        Storage::disk(ArchivosDelAgenteIaHelper::DISCO)->assertExists($path_vigente);

        // En el scheduler, una vez por día.
        $eventos = array_filter(app(Schedule::class)->events(), function ($evento) {
            return strpos((string) $evento->command, 'asistente:limpiar-archivos') !== false;
        });

        $this->assertCount(1, $eventos, 'asistente:limpiar-archivos tiene que estar en el scheduler.');
        $this->assertSame('45 3 * * *', array_values($eventos)[0]->expression);
    }
}
