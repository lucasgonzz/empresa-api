<?php

namespace Tests\Feature\ImportacionDeSoporte;

use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\SemillaRegistroHelper;
use App\Http\Controllers\Helpers\import\client\AiClientAnalyzer;
use App\Http\Controllers\Helpers\import\provider\AiProviderAnalyzer;
use App\Jobs\RegistrarHitoDePruebaJob;
use App\Models\AiAvisoDelAsistente;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\ImportacionDeSoporte;
use App\Models\Provider;
use App\Services\Importacion\ImportacionDeSoporteService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

/**
 * Clientes y proveedores de la prueba gratuita por el motor de soporte, sin código (misión
 * prueba-e2e-campana-imperio, ronda 8, D1): `modo_prueba=1` → analizar → importar → terminada, con el resumen de
 * los saldos por el ticket, las filas que no son contactos afuera y nombradas, y deshacer. Los negocios activos
 * siguen exactamente como antes (código de confirmación, textos, avisos de soporte).
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class ContactosEnLaPruebaTest extends ImportacionDeSoporteTestCase
{
    private const ARCHIVO = 'clientes cuenta corriente nov.xlsx';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prender_la_prueba();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function prender_la_prueba(): void
    {
        config(['app.FOR_USER' => 'prueba']);
        PruebaHelper::olvidar_cache();
        PruebaHelper::activar_desde_payload($this->dueno, ['dias' => 7]);
        PruebaHelper::olvidar_cache();
    }

    /** El negocio deja de ser una instancia de prueba: queda como un dueño activo. */
    private function apagar_la_prueba(): void
    {
        config(['app.FOR_USER' => null]);
        PruebaHelper::olvidar_cache();
    }

    private function iniciar_en_la_prueba(string $modelo, string $archivo, int $ticket, string $nombre = self::ARCHIVO, array $extra = ['modo_prueba' => 1])
    {
        return $this->post(self::BASE . $modelo . '/iniciar', [
            'user_id' => $this->dueno->id,
            'support_ticket_id' => $ticket,
            'excel_file' => new UploadedFile($archivo, $nombre, null, null, true),
        ] + $extra, $this->cabeceras());
    }

    private function avanzar(string $uuid): ImportacionDeSoporte
    {
        app(ImportacionDeSoporteService::class)->avanzar(ImportacionDeSoporte::where('uuid', $uuid)->firstOrFail());

        return ImportacionDeSoporte::where('uuid', $uuid)->firstOrFail();
    }

    /** La lista del ticket 127: 9 clientes, un título de sección y el total al pie. */
    private function lista_de_clientes(): string
    {
        return $this->planilla([
            ['Nombre', 'Telefono', 'Saldo'],
            ['Ana Prueba CC', '3411000001', 1000],
            ['Bruno Prueba CC', '3411000002', 32000],
            ['Carla Prueba CC', '3411000003', -1200],
            ['Dario Prueba CC', '3411000004', 0],
            ['Elena Prueba CC', '3411000005', null],
            ['--- MAYORISTAS ---', null, null],
            ['Fabian Prueba CC', '3411000006', '$ 5.500,50'],
            ['Gabriela Prueba CC', '3411000007', 2500],
            ['Hector Prueba CC', '3411000008', 750],
            ['Irene Prueba CC', '3411000009', 4000],
            ['TOTAL', null, 47350.5],
        ]);
    }

    private const NOMBRES = ['Ana Prueba CC', 'Bruno Prueba CC', 'Carla Prueba CC', 'Dario Prueba CC', 'Elena Prueba CC',
        'Fabian Prueba CC', 'Gabriela Prueba CC', 'Hector Prueba CC', 'Irene Prueba CC'];

    private function avisos(int $ticket)
    {
        return AiAvisoDelAsistente::where('owner_id', $this->dueno->id)->where('support_ticket_id', $ticket)->orderBy('id')->get();
    }

    // -----------------------------------------------------------------------------------------------------
    // 1. La carga de punta a punta
    // -----------------------------------------------------------------------------------------------------

    public function test_la_lista_de_clientes_de_la_prueba_se_carga_sola_con_sus_saldos_y_el_resumen(): void
    {
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono', 'saldo_actual']);
        $respuesta = $this->iniciar_en_la_prueba('client', $this->lista_de_clientes(), 127)->assertStatus(202)
            ->assertJsonPath('corrida.modo_prueba', true)->assertJsonPath('corrida.estado', 'analizar')
            ->assertJsonPath('corrida.codigo_requerido', false)->assertJsonPath('corrida.codigo_confirmacion', null)
            ->assertJsonPath('corrida.archivo', self::ARCHIVO)->assertJsonPath('corrida_existente', false);
        $uuid = $respuesta->json('corrida.corrida_id');

        $corrida = $this->avanzar($uuid);
        $this->assertSame('terminada', $corrida->estado, (string) $corrida->error);
        $this->assertNull($corrida->codigo, 'En la prueba no hay código.');
        $this->assertSame(1, (int) $corrida->revision);
        $this->assertNotNull($corrida->confirmada_at);
        $this->assertTrue((bool) $corrida->modo_prueba);
        $this->assertSame(9, $corrida->resultado['creados']);

        // Se crean los 9; ni el total ni el título.
        $creados = Client::where('user_id', $this->dueno->id)->whereIn('name', self::NOMBRES)->get()->keyBy('name');
        $this->assertCount(9, $creados);
        $this->assertFalse(Client::where('user_id', $this->dueno->id)->whereIn('name', ['TOTAL', '--- MAYORISTAS ---'])->exists());

        // Saldos: positivo = debe; negativo = a favor (haber, pago del cliente); 0 y vacío no cargan nada.
        $iniciales = CurrentAcount::whereIn('client_id', $creados->pluck('id'))->get();
        $this->assertCount(7, $iniciales);
        $this->assertSame(7, $iniciales->where('detalle', 'Saldo inicial')->count());
        $ana = $iniciales->firstWhere('client_id', $creados['Ana Prueba CC']->id);
        $this->assertEqualsWithDelta(1000, (float) $ana->debe, 0.001);
        $fabian = $iniciales->firstWhere('client_id', $creados['Fabian Prueba CC']->id);
        $this->assertEqualsWithDelta(5500.5, (float) $fabian->debe, 0.001);
        $carla = $iniciales->firstWhere('client_id', $creados['Carla Prueba CC']->id);
        $this->assertEqualsWithDelta(1200, (float) $carla->haber, 0.001);
        $this->assertSame('pago_from_client', $carla->status);
        $this->assertEqualsWithDelta(-1200, $this->saldo('client', $creados['Carla Prueba CC']->id), 0.001);
        $this->assertFalse(CurrentAcount::where('client_id', $creados['Dario Prueba CC']->id)->exists(), 'Saldo 0: sin movimiento.');
        $this->assertFalse(CurrentAcount::where('client_id', $creados['Elena Prueba CC']->id)->exists(), 'Saldo vacío: sin movimiento.');

        // El resumen de la prueba: totales, el mayor y las filas descartadas, nombradas.
        $this->assertEqualsWithDelta(45750.5, $corrida->resumen['saldo_total_deudor'], 0.001);
        $this->assertSame(6, $corrida->resumen['saldos_deudores']);
        $this->assertSame('Bruno Prueba CC', $corrida->resumen['saldo_mayor']['nombre']);
        $this->assertSame(1, $corrida->resumen['saldos_a_favor']);
        $this->assertEqualsWithDelta(1200, $corrida->resumen['saldo_total_a_favor'], 0.001);
        $this->assertSame(2, $corrida->resumen['filas_descartadas_total']);
        $this->assertSame(['--- MAYORISTAS ---', 'TOTAL'], array_column($corrida->resumen['filas_descartadas'], 'texto'));
        $this->assertSame([7, 12], array_column($corrida->resumen['filas_descartadas'], 'fila'));

        // Un solo aviso (el resultado), sin pregunta ni código, por el barrido de la prueba (modo_soporte = false).
        $avisos = $this->avisos(127);
        $this->assertCount(1, $avisos);
        $this->assertFalse((bool) $avisos[0]->modo_soporte);
        $this->assertSame(127, (int) $avisos[0]->support_ticket_id);
        $this->assertSame('Cargué 9 clientes de «' . self::ARCHIVO . '». En total te deben $45.750,50; el saldo más alto es el de'
            . ' Bruno Prueba CC ($32.000). 1 quedó con saldo a favor ($1.200). Dejé afuera 2 filas que no son clientes:'
            . ' «--- MAYORISTAS ---», «TOTAL». Si algo no coincide con tu lista, decímelo y lo deshago.', $avisos[0]->texto);
        $this->assertStringNotContainsString('CONFIRMAR', $avisos[0]->texto);

        // El hito de la importación.
        Queue::assertPushed(RegistrarHitoDePruebaJob::class, function ($job) {
            return $job->tipo === 'prueba.importo' && ($job->datos['modelo'] ?? null) === 'client'
                && (int) $job->owner_id === (int) $this->dueno->id;
        });

        // El estado por el admin: terminada, revertible, sin código.
        $this->getJson(self::BASE . $uuid . '?user_id=' . $this->dueno->id . '&support_ticket_id=127', $this->cabeceras())
            ->assertOk()->assertJsonPath('corrida.estado', 'terminada')->assertJsonPath('corrida.modo_prueba', true)
            ->assertJsonPath('corrida.revertible', true)->assertJsonPath('corrida.codigo_requerido', false);
    }

    // -----------------------------------------------------------------------------------------------------
    // 2 y 3. Lo que pide corrección no carga nada
    // -----------------------------------------------------------------------------------------------------

    public function test_un_nombre_repetido_pide_correccion_sin_cargar_nada_ni_avisar(): void
    {
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono', 'saldo_actual']);
        $uuid = $this->iniciar_en_la_prueba('client', $this->planilla([
            ['Nombre', 'Telefono', 'Saldo'],
            ['Repetido Prueba CC', '1', 100],
            ['Otro Prueba CC', '2', 200],
            ['Repetido Prueba CC', '3', 300],
        ]), 128)->json('corrida.corrida_id');

        $corrida = $this->avanzar($uuid);
        $this->assertSame('requiere_correccion', $corrida->estado);
        $this->assertSame('filas_ambiguas', $corrida->motivo);
        $this->assertNull($corrida->codigo);
        $this->assertSame(0, (int) $corrida->tandas_total);
        $this->assertFalse(Client::where('user_id', $this->dueno->id)->whereIn('name', ['Repetido Prueba CC', 'Otro Prueba CC'])->exists());
        $this->assertCount(0, $this->avisos(128));
        $this->assertStringContainsString('el nombre está repetido en el archivo', $corrida->error);
    }

    public function test_saldos_ilegibles_frenan_la_prueba_y_un_activo_sigue_con_su_codigo_como_hoy(): void
    {
        $filas = [
            ['Nombre', 'Telefono', 'Saldo'],
            ['Juan Ilegible CC', '1', 500],
            ['Ana Ilegible CC', '2', 's/d'],
            ['Beto Ilegible CC', '3', 'USD 1.500'],
        ];
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono', 'saldo_actual']);
        $uuid = $this->iniciar_en_la_prueba('client', $this->planilla($filas), 129)->json('corrida.corrida_id');

        $corrida = $this->avanzar($uuid);
        $this->assertSame('requiere_correccion', $corrida->estado);
        $this->assertSame('saldos_ilegibles', $corrida->motivo);
        $this->assertNull($corrida->codigo);
        $this->assertSame('No importé «' . self::ARCHIVO . '» porque 2 saldos no se pueden leer: fila 3 (Ana Ilegible CC): "s/d";'
            . ' fila 4 (Beto Ilegible CC): "USD 1.500". Poné un número en pesos (o dejalo vacío si no debe nada) y mandámelo de nuevo.',
            $corrida->error);
        $this->assertSame(2, $corrida->resumen['saldos_ilegibles_total']);
        $this->assertFalse(Client::where('user_id', $this->dueno->id)->where('name', 'like', '% Ilegible CC')->exists());
        $this->assertCount(0, $this->avisos(129));
        $this->getJson(self::BASE . $uuid . '?user_id=' . $this->dueno->id . '&support_ticket_id=129', $this->cabeceras())
            ->assertOk()->assertJsonPath('corrida.estado', 'requiere_correccion')->assertJsonPath('corrida.motivo', 'saldos_ilegibles');

        // Regresión: el mismo archivo en un negocio activo pide el código como hoy, sin las claves de la prueba.
        $this->apagar_la_prueba();
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono', 'saldo_actual']);
        $activo = $this->iniciar('client', $this->planilla($filas), 130)->assertStatus(202)
            ->assertJsonPath('corrida.modo_prueba', false)->json('corrida.corrida_id');
        $corrida = $this->avanzar($activo);
        $this->assertSame('confirmacion', $corrida->estado);
        $this->assertFalse((bool) $corrida->modo_prueba);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', (string) $corrida->codigo);
        foreach (['saldo_total_deudor', 'saldos_deudores', 'saldo_mayor', 'saldos_a_favor', 'saldo_total_a_favor',
            'saldos_ilegibles_total', 'filas_descartadas', 'filas_descartadas_total'] as $clave) {
            $this->assertArrayNotHasKey($clave, $corrida->resumen, 'Un activo no cambia las claves del resumen: ' . $clave);
        }
        $pregunta = $this->avisos(130)->first();
        $this->assertTrue((bool) $pregunta->modo_soporte);
        $this->assertStringContainsString('2 saldos no se pueden leer y no se van a cargar', $pregunta->texto);
        $this->assertStringContainsString('CONFIRMAR ' . $corrida->codigo, $pregunta->texto);
    }

    public function test_un_activo_no_descarta_filas_que_no_son_contactos(): void
    {
        // El filtro de filas de la prueba no corre en un negocio activo: el «TOTAL» entra como siempre.
        $this->apagar_la_prueba();
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono', 'saldo_actual']);
        $uuid = $this->iniciar('client', $this->planilla([
            ['Nombre', 'Telefono', 'Saldo'],
            ['Uno Activo CC', '1', 100], ['Dos Activo CC', '2', 200], ['Tres Activo CC', '3', 300], ['TOTAL', null, 600],
        ]), 131)->json('corrida.corrida_id');
        $corrida = $this->avanzar($uuid);
        $this->assertSame('confirmacion', $corrida->estado);
        $this->assertSame(4, $corrida->resumen['crear']);
        $this->assertContains('TOTAL', $corrida->resumen['ejemplos_crear']);
    }

    // -----------------------------------------------------------------------------------------------------
    // 4 y 5. Guardas
    // -----------------------------------------------------------------------------------------------------

    public function test_modo_prueba_con_un_activo_y_sin_modo_prueba_en_la_prueba_se_rechazan(): void
    {
        $archivo = $this->planilla([['Nombre', 'Telefono'], ['Guarda Prueba CC', '1']]);
        $this->iniciar('client', $archivo, 132)->assertStatus(409)->assertJsonPath('motivo', 'dueno_no_activo');

        $this->apagar_la_prueba();
        $this->iniciar_en_la_prueba('client', $archivo, 132)->assertStatus(409)->assertJsonPath('motivo', 'dueno_no_en_prueba');
        $this->iniciar_en_la_prueba('client', $archivo, 132, 'x.xlsx', ['modo_prueba' => 'quizas'])->assertStatus(422);

        // Prueba vencida: tampoco.
        $this->prender_la_prueba();
        PruebaHelper::fijar_vencimiento($this->dueno->id, Carbon::now()->subDay());
        PruebaHelper::olvidar_cache();
        $this->iniciar_en_la_prueba('client', $archivo, 132)->assertStatus(409)->assertJsonPath('motivo', 'dueno_no_en_prueba');
        $this->assertSame(0, ImportacionDeSoporte::where('user_id', $this->dueno->id)->where('support_ticket_id', 132)->count());
    }

    public function test_confirmar_una_corrida_de_la_prueba_da_codigo_vencido(): void
    {
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono']);
        $uuid = $this->iniciar_en_la_prueba('client', $this->planilla([['Nombre', 'Telefono'], ['Confirma Prueba CC', '1']]), 133)
            ->json('corrida.corrida_id');
        $datos = ['user_id' => $this->dueno->id, 'support_ticket_id' => 133, 'codigo' => 'ABCDEF12'];
        $this->postJson(self::BASE . $uuid . '/confirmar', $datos, $this->cabeceras())
            ->assertStatus(409)->assertJsonPath('motivo', 'codigo_vencido');
        $this->assertSame('terminada', $this->avanzar($uuid)->estado);
        $this->postJson(self::BASE . $uuid . '/confirmar', $datos, $this->cabeceras())
            ->assertStatus(409)->assertJsonPath('motivo', 'codigo_vencido');
        $this->postJson(self::BASE . $uuid . '/confirmar', $datos + ['decision' => 'cancelar'], $this->cabeceras())
            ->assertStatus(409)->assertJsonPath('motivo', 'codigo_vencido');
    }

    public function test_si_la_prueba_vence_antes_de_cargar_la_corrida_falla_sin_cargar(): void
    {
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono']);
        $uuid = $this->iniciar_en_la_prueba('client', $this->planilla([['Nombre', 'Telefono'], ['Vence Prueba CC', '1']]), 134)
            ->json('corrida.corrida_id');
        PruebaHelper::fijar_vencimiento($this->dueno->id, Carbon::now()->subMinute());
        PruebaHelper::olvidar_cache();

        $corrida = $this->avanzar($uuid);
        $this->assertSame('error', $corrida->estado);
        $this->assertSame('La prueba terminó antes de cargar «' . self::ARCHIVO . '».', $corrida->error);
        $this->assertFalse(Client::where('user_id', $this->dueno->id)->where('name', 'Vence Prueba CC')->exists());
        // Vencida y sin contratar, la corrida tampoco se consulta.
        $this->getJson(self::BASE . $uuid . '?user_id=' . $this->dueno->id . '&support_ticket_id=134', $this->cabeceras())
            ->assertStatus(409)->assertJsonPath('motivo', 'dueno_no_en_prueba');
    }

    // -----------------------------------------------------------------------------------------------------
    // 6. Deshacer
    // -----------------------------------------------------------------------------------------------------

    public function test_deshacer_una_carga_de_la_prueba_y_tambien_despues_de_contratar(): void
    {
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono', 'saldo_actual']);
        $uuid = $this->iniciar_en_la_prueba('client', $this->lista_de_clientes(), 135)->json('corrida.corrida_id');
        $this->assertSame('terminada', $this->avanzar($uuid)->estado);
        $ids = Client::where('user_id', $this->dueno->id)->whereIn('name', self::NOMBRES)->pluck('id');
        $this->assertCount(9, $ids);

        $this->revertir($uuid, 135)->assertJsonPath('corrida.estado', 'revertida')
            ->assertJsonPath('corrida.resultado.reversion.creados_borrados', 9)
            ->assertJsonPath('corrida.resultado.reversion.movimientos_borrados', 7);
        $this->assertSame(0, Client::where('user_id', $this->dueno->id)->whereIn('name', self::NOMBRES)->count());
        $this->assertFalse(CurrentAcount::whereIn('client_id', $ids)->exists());
        $this->assertStringStartsWith('Deshice la importación de «' . self::ARCHIVO . '»: borré 9 clientes nuevos',
            $this->avisos(135)->last()->texto);
        $this->assertFalse((bool) $this->avisos(135)->last()->modo_soporte);

        // Ya contratado (dueño activo), lo que cargó en la prueba se puede deshacer igual.
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono']);
        $otra = $this->iniciar_en_la_prueba('client', $this->planilla([['Nombre', 'Telefono'], ['Contrato Prueba CC', '1']]), 136)
            ->json('corrida.corrida_id');
        $this->assertSame('terminada', $this->avanzar($otra)->estado);
        $this->apagar_la_prueba();
        $this->revertir($otra, 136)->assertJsonPath('corrida.estado', 'revertida');
        $this->assertFalse(Client::where('user_id', $this->dueno->id)->where('name', 'Contrato Prueba CC')->exists());
    }

    // -----------------------------------------------------------------------------------------------------
    // 7. La semilla
    // -----------------------------------------------------------------------------------------------------

    public function test_la_semilla_se_borra_antes_de_importar_una_sola_vez_y_no_en_el_analisis(): void
    {
        SemillaRegistroHelper::abrir_lote($this->dueno->id, 'test');
        $ejemplo = Client::create(['user_id' => $this->dueno->id, 'name' => 'Ana Prueba CC', 'num' => 1, 'phone' => '999']);
        $otro_ejemplo = Client::create(['user_id' => $this->dueno->id, 'name' => 'Cliente de ejemplo CC', 'num' => 2, 'phone' => '998']);
        SemillaRegistroHelper::cerrar_lote();
        $this->assertTrue(SemillaRegistroHelper::semilla_presente($this->dueno->id));

        // Un archivo que pide corrección no borra nada.
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono', 'saldo_actual']);
        $con_error = $this->iniciar_en_la_prueba('client', $this->planilla([['Nombre', 'Telefono', 'Saldo'], ['Uno CC', '1', 's/d']]), 137)
            ->json('corrida.corrida_id');
        $this->assertSame('requiere_correccion', $this->avanzar($con_error)->estado);
        $this->assertTrue(SemillaRegistroHelper::semilla_presente($this->dueno->id));
        $this->assertNotSoftDeleted('clients', ['id' => $ejemplo->id]);

        // «Ana Prueba CC» de la semilla hace que el análisis la vea como existente; al borrar la semilla el plan cambia
        // (la crea) y en la prueba eso no frena: nadie confirmó la huella anterior.
        $ahora = Carbon::now()->startOfSecond();
        Carbon::setTestNow($ahora);
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono', 'saldo_actual']);
        $uuid = $this->iniciar_en_la_prueba('client', $this->lista_de_clientes(), 138)->json('corrida.corrida_id');
        $corrida = $this->avanzar($uuid);
        $this->assertSame('terminada', $corrida->estado, (string) $corrida->error);
        $this->assertFalse(SemillaRegistroHelper::semilla_presente($this->dueno->id));
        $this->assertSame(0, Client::where('user_id', $this->dueno->id)->whereIn('id', [$ejemplo->id, $otro_ejemplo->id])->count(),
            'No quedan clientes de ejemplo.');
        $this->assertSame(9, $corrida->resultado['creados']);
        $this->assertSame(9, (int) $corrida->resumen['crear']);
        $this->assertEqualsWithDelta(45750.5, $corrida->resumen['saldo_total_deudor'], 0.001, 'El resumen guardado es el nuevo.');
        PruebaHelper::olvidar_cache();
        $borrada = PruebaHelper::config($this->dueno->id)->semilla_borrada_at;
        $this->assertTrue($borrada->eq($ahora));
        $this->assertSame('martin', PruebaHelper::origen_de_importacion($this->dueno->id));

        // Ya borrada, la segunda carga no la vuelve a borrar.
        Carbon::setTestNow($ahora->copy()->addMinutes(5));
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono']);
        $segunda = $this->iniciar_en_la_prueba('client', $this->planilla([['Nombre', 'Telefono'], ['Segunda Semilla CC', '1']]), 139)
            ->json('corrida.corrida_id');
        $this->assertSame('terminada', $this->avanzar($segunda)->estado);
        PruebaHelper::olvidar_cache();
        $this->assertTrue(PruebaHelper::config($this->dueno->id)->semilla_borrada_at->eq($ahora));
    }

    // -----------------------------------------------------------------------------------------------------
    // 8. Proveedores
    // -----------------------------------------------------------------------------------------------------

    public function test_proveedores_de_la_prueba_dicen_les_debes(): void
    {
        $this->analizador(AiProviderAnalyzer::class, ['nombre', 'telefono', 'saldo_actual']);
        $uuid = $this->iniciar_en_la_prueba('provider', $this->planilla([
            ['Proveedor', 'Telefono', 'Saldo'],
            ['Distribuidora Prueba CC', '1', 10000],
        ]), 140, 'proveedores.xlsx')->json('corrida.corrida_id');
        $corrida = $this->avanzar($uuid);
        $this->assertSame('terminada', $corrida->estado, (string) $corrida->error);
        $proveedor = Provider::where('user_id', $this->dueno->id)->where('name', 'Distribuidora Prueba CC')->firstOrFail();
        $this->assertEqualsWithDelta(10000, $this->saldo('provider', $proveedor->id), 0.001);
        $this->assertSame('Cargué 1 proveedor de «proveedores.xlsx». En total les debés $10.000.'
            . ' Si algo no coincide con tu lista, decímelo y lo deshago.', $this->avisos(140)->last()->texto);
        Queue::assertPushed(RegistrarHitoDePruebaJob::class, function ($job) {
            return $job->tipo === 'prueba.importo' && ($job->datos['modelo'] ?? null) === 'provider';
        });

        // Con varios: el más alto y el que quedó a favor.
        $this->analizador(AiProviderAnalyzer::class, ['nombre', 'telefono', 'saldo_actual']);
        $otra = $this->iniciar_en_la_prueba('provider', $this->planilla([
            ['Proveedor', 'Telefono', 'Saldo'],
            ['Mayorista Prueba CC', '2', 2500.5],
            ['Fabrica Prueba CC', '3', 12000],
            ['Taller Prueba CC', '4', -300],
        ]), 141, 'proveedores.xlsx')->json('corrida.corrida_id');
        $corrida = $this->avanzar($otra);
        $this->assertSame('terminada', $corrida->estado, (string) $corrida->error);
        $this->assertSame('Cargué 3 proveedores de «proveedores.xlsx». En total les debés $14.500,50; el saldo más alto es el de'
            . ' Fabrica Prueba CC ($12.000). 1 quedó con saldo a tu favor ($300). Si algo no coincide con tu lista, decímelo y lo deshago.',
            $this->avisos(141)->last()->texto);
    }

    public function test_sin_nada_nuevo_lo_dice_y_un_solo_cliente_va_en_singular(): void
    {
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono']);
        $archivo = $this->planilla([['Nombre', 'Telefono'], ['Solo Prueba CC', '1']]);
        $uuid = $this->iniciar_en_la_prueba('client', $archivo, 142, 'uno.xlsx')->json('corrida.corrida_id');
        $this->assertSame('terminada', $this->avanzar($uuid)->estado);
        $this->assertSame('Cargué 1 cliente de «uno.xlsx». Si algo no coincide con tu lista, decímelo y lo deshago.',
            $this->avisos(142)->last()->texto);

        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono']);
        $otra = $this->iniciar_en_la_prueba('client', $archivo, 143, 'uno.xlsx')->json('corrida.corrida_id');
        $this->assertSame('terminada', $this->avanzar($otra)->estado);
        $this->assertSame('No cargué ningún cliente nuevo de «uno.xlsx»: ya estaban todos igual. Si algo no coincide con tu lista,'
            . ' decímelo y lo deshago.', $this->avisos(143)->last()->texto);
    }

    // -----------------------------------------------------------------------------------------------------
    // 9. prueba-pendientes
    // -----------------------------------------------------------------------------------------------------

    public function test_prueba_pendientes_anuncia_las_capacidades_y_las_corridas_de_la_prueba(): void
    {
        $pendientes = '/api/admin-sync/ai-excel-import/prueba-pendientes?user_id=' . $this->dueno->id . '&support_ticket_id=';
        $this->getJson($pendientes . '144', $this->cabeceras())->assertOk()
            ->assertJsonPath('en_proceso', false)
            ->assertJsonPath('capacidades_prueba', ['margen_previo', 'contactos'])
            ->assertJsonPath('importaciones_de_contactos', []);

        $this->analizador(AiProviderAnalyzer::class, ['nombre']);
        $uuid = $this->iniciar_en_la_prueba('provider', $this->planilla([['Nombre'], ['Pendiente Prueba CC']]), 144)
            ->json('corrida.corrida_id');
        $this->getJson($pendientes . '144', $this->cabeceras())->assertOk()
            ->assertJsonPath('en_proceso', true)
            ->assertJsonPath('pendientes', [])
            ->assertJsonPath('importaciones_de_contactos.0.corrida_id', $uuid)
            ->assertJsonPath('importaciones_de_contactos.0.modelo', 'provider')
            ->assertJsonPath('importaciones_de_contactos.0.estado', 'analizar')
            ->assertJsonPath('importaciones_de_contactos.0.revision', 0)
            ->assertJsonPath('importaciones_de_contactos.0.codigo_requerido', false)
            ->assertJsonPath('importaciones_de_contactos.0.modo_prueba', true);
        $this->getJson($pendientes . '145', $this->cabeceras())->assertOk()->assertJsonPath('importaciones_de_contactos', []);

        // Terminada: ya no está abierta, pero el aviso que todavía no salió deja el ticket en proceso.
        $this->assertSame('terminada', $this->avanzar($uuid)->estado);
        $this->getJson($pendientes . '144', $this->cabeceras())->assertOk()
            ->assertJsonPath('importaciones_de_contactos', [])->assertJsonPath('en_proceso', true);
        AiAvisoDelAsistente::where('owner_id', $this->dueno->id)->where('support_ticket_id', 144)->update(['avisado_por_whatsapp_at' => now()]);
        $this->getJson($pendientes . '144', $this->cabeceras())->assertOk()->assertJsonPath('en_proceso', false);

        // En modo soporte (negocio activo) la corrida de la prueba no aparece entre las de activos.
        $this->analizador(AiProviderAnalyzer::class, ['nombre']);
        $abierta = $this->iniciar_en_la_prueba('provider', $this->planilla([['Nombre'], ['Abierta Prueba CC']]), 146)->json('corrida.corrida_id');
        $this->assertNotNull($abierta);
        $this->apagar_la_prueba();
        $soporte = $this->getJson('/api/admin-sync/ai-excel-import/prueba-pendientes?automatizar_soporte=1&user_id=' . $this->dueno->id
            . '&support_ticket_id=146', $this->cabeceras())->assertOk()
            ->assertJsonPath('importaciones_de_contactos', [])->assertJsonPath('en_proceso', false)->json();
        $this->assertArrayNotHasKey('capacidades_prueba', $soporte);
    }
}
