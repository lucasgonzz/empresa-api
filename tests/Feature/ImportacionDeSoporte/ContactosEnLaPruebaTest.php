<?php

namespace Tests\Feature\ImportacionDeSoporte;

use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\SemillaRegistroHelper;
use App\Http\Controllers\Helpers\import\client\AiClientAnalyzer;
use App\Http\Controllers\Helpers\import\provider\AiProviderAnalyzer;
use App\Jobs\AvanzarImportacionDeSoporte;
use App\Jobs\RegistrarHitoDePruebaJob;
use App\Models\AiAvisoDelAsistente;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\ImportacionDeSoporte;
use App\Models\Provider;
use App\Models\PruebaConfig;
use App\Http\Controllers\AdminSync\ImportacionDePruebaController;
use App\Services\Importacion\ImportacionDeSoporteService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
            ['TOTAL', null, 44550.5],
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
        // A2 (revisión): lo que le deben, el saldo a favor y el neto, que es el TOTAL de su planilla (44.550,50).
        $this->assertSame('Cargué 9 clientes de «' . self::ARCHIVO . '». Entre todos te deben $45.750,50 y 1 tiene saldo a favor'
            . ' ($1.200): el neto es $44.550,50. El saldo más alto es el de Bruno Prueba CC ($32.000). Dejé afuera 2 filas que no'
            . ' son clientes: «--- MAYORISTAS ---» y «TOTAL». Si alguna era un cliente, decímelo y la cargo. Si algo no coincide'
            . ' con tu lista, decímelo y lo deshago.', $avisos[0]->texto);
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

    public function test_un_nombre_repetido_pide_correccion_sin_cargar_nada_y_se_lo_avisa_al_dueno(): void
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
        $this->assertStringContainsString('el nombre está repetido en el archivo', $corrida->error);
        // A4 (revisión): en la prueba el dueño recibe por el ticket qué pasó, que no se cargó nada y qué hacer.
        $this->assertSame('No importé «' . self::ARCHIVO . '» porque 2 filas no se pueden importar sin adivinar: fila 2'
            . ' (Repetido Prueba CC): el nombre está repetido en el archivo; fila 4 (Repetido Prueba CC): el nombre está repetido en'
            . ' el archivo. No cargué nada: corregí esas filas en tu planilla y mandámela de nuevo.', $corrida->error);
        $avisos = $this->avisos(128);
        $this->assertCount(1, $avisos);
        $this->assertSame($corrida->error, $avisos[0]->texto);
        $this->assertFalse((bool) $avisos[0]->modo_soporte);
        // Una sola vez: la corrida ya no avanza.
        $this->avanzar($uuid);
        $this->assertCount(1, $this->avisos(128));
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
        $this->assertCount(1, $this->avisos(129));
        $this->assertSame($corrida->error, $this->avisos(129)[0]->texto);
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
        // A5 (revisión): sin nombrar lo que es cero y diciendo que también se sacaron los saldos.
        $this->assertSame('Deshice la carga de «' . self::ARCHIVO . '»: borré 9 clientes nuevos. También saqué los saldos que había cargado.',
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
        $this->assertSame('Cargué 3 proveedores de «proveedores.xlsx». Entre todos les debés $14.500,50 y 1 tiene saldo a tu favor'
            . ' ($300): el neto es $14.200,50. El saldo más alto es el de Fabrica Prueba CC ($12.000). Si algo no coincide con tu'
            . ' lista, decímelo y lo deshago.',
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

    // -----------------------------------------------------------------------------------------------------
    // Revisión de la ronda 8: A1 a A8
    // -----------------------------------------------------------------------------------------------------

    public function test_a1_un_titulo_de_grupo_sin_dos_puntos_en_la_primera_fila_no_se_crea_como_cliente(): void
    {
        // La planilla de la medición, rearmada: cabecera, un título de grupo en la primera fila de datos (sin «:», sin
        // mayúsculas, sin palabra de total), clientes (tres con solo nombre y saldo), otro título con «:» y el TOTAL.
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'cuit', 'telefono', 'saldo_actual']);
        $uuid = $this->iniciar_en_la_prueba('client', $this->planilla([
            ['Cliente', 'CUIT', 'Tel.', 'Saldo'],
            ['Mayoristas', null, null, null],
            ['Ferretería Suelta Uno', '20-11111111-1', '3411000001', 15000],
            ['Corralón Suelta Dos', null, '3411000002', 8200.5],
            ['Rosa Suelta', null, null, 3000],
            ['Pinturería Suelta Tres', '20-22222222-2', '3411000003', 0],
            ['Minoristas:', null, null, null],
            ['Hugo Suelta', null, null, 1200],
            ['Marta Suelta', null, null, -500],
            ['Electricidad Suelta Cuatro', null, '3411000004', 42000],
            ['Taller Suelta Cinco', null, '3411000005', 7300],
            ['Plomería Suelta Seis', '20-33333333-3', null, 900],
            ['TOTAL', null, null, 77100.5],
        ]), 150, 'clientes nov.xlsx')->json('corrida.corrida_id');

        $corrida = $this->avanzar($uuid);
        $this->assertSame('terminada', $corrida->estado, (string) $corrida->error);
        $this->assertSame(9, $corrida->resultado['creados']);
        $this->assertFalse(Client::where('user_id', $this->dueno->id)->whereIn('name', ['Mayoristas', 'Minoristas:', 'TOTAL'])->exists());
        $this->assertSame(['Mayoristas', 'Minoristas:', 'TOTAL'], array_column($corrida->resumen['filas_descartadas'], 'texto'));
        $this->assertSame(['suelta', 'titulo', 'total'], array_column($corrida->resumen['filas_descartadas'], 'motivo'));
        // En el orden de la planilla.
        $this->assertSame([2, 7, 13], array_column($corrida->resumen['filas_descartadas'], 'fila'));
        $this->assertSame('Cargué 9 clientes de «clientes nov.xlsx». Entre todos te deben $77.600,50 y 1 tiene saldo a favor ($500):'
            . ' el neto es $77.100,50. El saldo más alto es el de Electricidad Suelta Cuatro ($42.000). Dejé afuera 3 filas que no'
            . ' son clientes: «Mayoristas», «Minoristas:» y «TOTAL». Si alguna era un cliente, decímelo y la cargo. Si algo no'
            . ' coincide con tu lista, decímelo y lo deshago.', $this->avisos(150)->last()->texto);
    }

    public function test_a2_sin_saldos_a_favor_el_total_es_como_antes_y_solo_a_favor_lo_dice(): void
    {
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono', 'saldo_actual']);
        $uuid = $this->iniciar_en_la_prueba('client', $this->planilla([
            ['Nombre', 'Telefono', 'Saldo'], ['Uno Neto CC', '1', 1000], ['Dos Neto CC', '2', 2500.5],
        ]), 151, 'deudores.xlsx')->json('corrida.corrida_id');
        $this->assertSame('terminada', $this->avanzar($uuid)->estado);
        $this->assertSame('Cargué 2 clientes de «deudores.xlsx». En total te deben $3.500,50; el saldo más alto es el de Dos Neto CC'
            . ' ($2.500,50). Si algo no coincide con tu lista, decímelo y lo deshago.', $this->avisos(151)->last()->texto);

        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono', 'saldo_actual']);
        $otra = $this->iniciar_en_la_prueba('client', $this->planilla([
            ['Nombre', 'Telefono', 'Saldo'], ['Tres Neto CC', '3', -100], ['Cuatro Neto CC', '4', -250],
        ]), 152, 'a favor.xlsx')->json('corrida.corrida_id');
        $this->assertSame('terminada', $this->avanzar($otra)->estado);
        $this->assertSame('Cargué 2 clientes de «a favor.xlsx». 2 tienen saldo a favor ($350). Si algo no coincide con tu lista,'
            . ' decímelo y lo deshago.', $this->avisos(152)->last()->texto);
    }

    public function test_a3_la_segunda_carga_da_el_total_de_la_lista_y_dice_que_cambio(): void
    {
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono', 'saldo_actual']);
        $primera = $this->iniciar_en_la_prueba('client', $this->lista_de_clientes(), 153)->json('corrida.corrida_id');
        $this->assertSame('terminada', $this->avanzar($primera)->estado);

        // El dueño corrige una fila (Bruno debe 30.000 y no 32.000) y la manda de nuevo.
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono', 'saldo_actual']);
        $segunda = $this->iniciar_en_la_prueba('client', $this->planilla([
            ['Nombre', 'Telefono', 'Saldo'],
            ['Ana Prueba CC', '3411000001', 1000],
            ['Bruno Prueba CC', '3411000002', 30000],
            ['Carla Prueba CC', '3411000003', -1200],
            ['Dario Prueba CC', '3411000004', 0],
            ['Elena Prueba CC', '3411000005', null],
            ['--- MAYORISTAS ---', null, null],
            ['Fabian Prueba CC', '3411000006', '$ 5.500,50'],
            ['Gabriela Prueba CC', '3411000007', 2500],
            ['Hector Prueba CC', '3411000008', 750],
            ['Irene Prueba CC', '3411000009', 4000],
            ['TOTAL', null, 42550.5],
        ]), 154)->json('corrida.corrida_id');
        $corrida = $this->avanzar($segunda);
        $this->assertSame('terminada', $corrida->estado, (string) $corrida->error);
        $this->assertSame(0, $corrida->resultado['creados']);
        $this->assertSame(1, $corrida->resumen['existentes_con_cambios']);
        $this->assertSame(8, $corrida->resumen['existentes_iguales']);
        $bruno = Client::where('user_id', $this->dueno->id)->where('name', 'Bruno Prueba CC')->firstOrFail();
        $this->assertEqualsWithDelta(30000, $this->saldo('client', $bruno->id), 0.001);
        // El total es el de la LISTA (no solo la fila que se movió): cierra con el TOTAL de su planilla.
        $this->assertSame('Actualicé 1 cliente de «' . self::ARCHIVO . '» que ya tenías; los demás ya estaban igual. Entre todos te'
            . ' deben $43.750,50 y 1 tiene saldo a favor ($1.200): el neto es $42.550,50. El saldo más alto es el de Bruno Prueba CC'
            . ' ($30.000). Dejé afuera 2 filas que no son clientes: «--- MAYORISTAS ---» y «TOTAL». Si alguna era un cliente,'
            . ' decímelo y la cargo. Si algo no coincide con tu lista, decímelo y lo deshago.', $this->avisos(154)->last()->texto);

        // Una tercera igual: no cambió nada, y el total sigue siendo el de la lista.
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono', 'saldo_actual']);
        $tercera = $this->iniciar_en_la_prueba('client', $this->planilla([
            ['Nombre', 'Telefono', 'Saldo'], ['Ana Prueba CC', '3411000001', 1000], ['Bruno Prueba CC', '3411000002', 30000],
        ]), 155, 'dos.xlsx')->json('corrida.corrida_id');
        $this->assertSame('terminada', $this->avanzar($tercera)->estado);
        $this->assertSame('No cargué ningún cliente nuevo de «dos.xlsx»: ya estaban todos igual. En total te deben $31.000; el saldo'
            . ' más alto es el de Bruno Prueba CC ($30.000). Si algo no coincide con tu lista, decímelo y lo deshago.',
            $this->avisos(155)->last()->texto);

        // Uno nuevo y uno que ya estaba igual.
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono', 'saldo_actual']);
        $cuarta = $this->iniciar_en_la_prueba('client', $this->planilla([
            ['Nombre', 'Telefono', 'Saldo'], ['Ana Prueba CC', '3411000001', 1000], ['Nuevo Prueba CC', '3411000010', 200],
        ]), 156, 'tres.xlsx')->json('corrida.corrida_id');
        $this->assertSame('terminada', $this->avanzar($cuarta)->estado);
        $this->assertStringStartsWith('Cargué 1 cliente nuevo de «tres.xlsx»; los demás ya estaban igual. En total te deben $1.200;',
            $this->avisos(156)->last()->texto);
    }

    public function test_a4_ninguna_corrida_de_la_prueba_que_no_carga_queda_muda(): void
    {
        // Sin la columna del nombre.
        $this->analizador(AiClientAnalyzer::class, ['telefono', 'saldo_actual']);
        $uuid = $this->iniciar_en_la_prueba('client', $this->planilla([['Tel', 'Saldo'], ['1', 100]]), 157, 'sin nombre.xlsx')
            ->json('corrida.corrida_id');
        $corrida = $this->avanzar($uuid);
        $this->assertSame('error', $corrida->estado);
        $esperado = 'No pude preparar «sin nombre.xlsx»: no encontré en el archivo una columna con los nombres. No cargué nada: revisá'
            . ' que tu planilla tenga una columna con el nombre de cada cliente y mandámela de nuevo.';
        $this->assertSame($esperado, $corrida->error);
        $this->assertSame($esperado, $this->avisos(157)->last()->texto);
        $this->assertFalse((bool) $this->avisos(157)->last()->modo_soporte);

        // Sin filas con clientes.
        $this->analizador(AiProviderAnalyzer::class, ['nombre', 'telefono']);
        $uuid = $this->iniciar_en_la_prueba('provider', $this->planilla([['Nombre', 'Telefono']]), 158, 'vacia.xlsx')->json('corrida.corrida_id');
        $this->assertSame('error', $this->avanzar($uuid)->estado);
        $this->assertSame('El archivo «vacia.xlsx» no tiene filas con proveedores para importar. No cargué nada: revisá que sea la planilla'
            . ' de tus proveedores y mandámela de nuevo.', $this->avisos(158)->last()->texto);

        // Más filas que el máximo.
        config(['importacion_soporte.maximo_de_filas' => 2]);
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono']);
        $uuid = $this->iniciar_en_la_prueba('client', $this->planilla([['Nombre', 'Telefono'], ['G1 CC', '1'], ['G2 CC', '2'], ['G3 CC', '3']]),
            159, 'grande.xlsx')->json('corrida.corrida_id');
        $corrida = $this->avanzar($uuid);
        $this->assertSame('archivo_demasiado_grande', $corrida->motivo);
        $this->assertSame('No importé «grande.xlsx» porque tiene 3 clientes y el máximo por archivo es 2. Partilo en archivos de hasta 2'
            . ' filas y mandalos de a uno.', $this->avisos(159)->last()->texto);
        $this->assertFalse(Client::where('user_id', $this->dueno->id)->whereIn('name', ['G1 CC', 'G2 CC', 'G3 CC'])->exists());
        config(['importacion_soporte.maximo_de_filas' => 5000]);

        // Un número que choca con la numeración automática: en palabras del dueño, sin la clave interna.
        $this->cliente('Existente Num CC', 5, '1');
        $this->analizador(AiClientAnalyzer::class, ['numero', 'nombre']);
        $uuid = $this->iniciar_en_la_prueba('client', $this->planilla([['Num', 'Nombre'], [999999990, 'Choca Num CC'], [null, 'Sin Num CC']]),
            160, 'numeros.xlsx')->json('corrida.corrida_id');
        $corrida = $this->avanzar($uuid);
        $this->assertSame('filas_ambiguas', $corrida->motivo);
        $this->assertStringNotContainsString('numero_automatico_choca', $this->avisos(160)->last()->texto);
        $this->assertStringContainsString('el número choca con el que le tocaría a un cliente nuevo', $this->avisos(160)->last()->texto);

        // La prueba vencida antes de cargar también avisa (texto del contrato).
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono']);
        $uuid = $this->iniciar_en_la_prueba('client', $this->planilla([['Nombre', 'Telefono'], ['Vencida Aviso CC', '1']]), 161, 'v.xlsx')
            ->json('corrida.corrida_id');
        PruebaHelper::fijar_vencimiento($this->dueno->id, Carbon::now()->subMinute());
        PruebaHelper::olvidar_cache();
        $this->avanzar($uuid);
        $this->assertSame('La prueba terminó antes de cargar «v.xlsx».', $this->avisos(161)->last()->texto);
    }

    public function test_a4_un_activo_que_pide_correccion_sigue_sin_aviso_y_con_el_texto_de_siempre(): void
    {
        $this->apagar_la_prueba();
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono']);
        $uuid = $this->iniciar('client', $this->planilla([['Nombre', 'Telefono'], ['Rep Activo CC', '1'], ['Rep Activo CC', '2']]), 162)
            ->json('corrida.corrida_id');
        $corrida = $this->avanzar($uuid);
        $this->assertSame('requiere_correccion', $corrida->estado);
        $this->assertSame('No importé «contactos.xlsx» porque 2 filas no se pueden importar sin adivinar: fila 2 (Rep Activo CC): el'
            . ' nombre está repetido en el archivo; fila 3 (Rep Activo CC): el nombre está repetido en el archivo.', $corrida->error);
        $this->assertCount(0, $this->avisos(162));

        $this->analizador(AiClientAnalyzer::class, ['telefono']);
        $uuid = $this->iniciar('client', $this->planilla([['Tel'], ['1']]), 163)->json('corrida.corrida_id');
        $this->assertSame('No pude preparar «contactos.xlsx»: No encontré en el archivo una columna con los nombres.',
            $this->avanzar($uuid)->error);
    }

    public function test_a5_el_texto_de_deshacer_de_un_activo_queda_identico(): void
    {
        $texto = new \ReflectionMethod(ImportacionDeSoporteService::class, 'texto_de_la_reversion');
        $texto->setAccessible(true);
        $reversion = ['creados_borrados' => 10, 'actualizados_restaurados' => 0, 'movimientos_borrados' => 7, 'contraasientos' => 0,
            'creados_conservados' => 0, 'saldos_anulados_de_conservados' => 0, 'actualizados_conservados' => 0];
        $activo = new ImportacionDeSoporte(['modelo' => 'client', 'nombre' => 'lista.xlsx', 'resultado' => ['reversion' => $reversion]]);
        $this->assertSame('Deshice la importación de «lista.xlsx»: borré 10 clientes nuevos y dejé como estaban 0 que se habían actualizado.',
            $texto->invoke(new ImportacionDeSoporteService(), $activo));

        $prueba = new ImportacionDeSoporte(['modelo' => 'client', 'nombre' => 'lista.xlsx', 'modo_prueba' => true,
            'resultado' => ['reversion' => $reversion]]);
        $this->assertSame('Deshice la carga de «lista.xlsx»: borré 10 clientes nuevos. También saqué los saldos que había cargado.',
            $texto->invoke(new ImportacionDeSoporteService(), $prueba));
        $prueba->resultado = ['reversion' => ['creados_borrados' => 1, 'actualizados_restaurados' => 2, 'movimientos_borrados' => 0,
            'contraasientos' => 1, 'creados_conservados' => 0, 'saldos_anulados_de_conservados' => 0, 'actualizados_conservados' => 1]];
        $prueba->modelo = 'provider';
        $this->assertSame('Deshice la carga de «lista.xlsx»: borré 1 proveedor nuevo y dejé como estaban 2 que se habían actualizado.'
            . ' A 1 no le volví los datos que se cambiaron a mano después. Anulé 1 saldo con una nota opuesta porque la cuenta ya'
            . ' tenía movimientos posteriores.', $texto->invoke(new ImportacionDeSoporteService(), $prueba));
    }

    public function test_a6_el_job_vuelve_a_leer_la_configuracion_de_la_prueba(): void
    {
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono']);
        $uuid = $this->iniciar_en_la_prueba('client', $this->planilla([['Nombre', 'Telefono'], ['Cache Job CC', '1']]), 164, 'c.xlsx')
            ->json('corrida.corrida_id');
        // El proceso tiene en memoria la prueba vigente; mientras tanto la prueba deja de existir.
        $this->assertTrue(PruebaHelper::en_periodo_vigente($this->dueno->id));
        PruebaConfig::where('owner_id', $this->dueno->id)->delete();

        (new AvanzarImportacionDeSoporte((int) ImportacionDeSoporte::where('uuid', $uuid)->value('id')))
            ->handle(app(ImportacionDeSoporteService::class));
        $corrida = ImportacionDeSoporte::where('uuid', $uuid)->firstOrFail();
        $this->assertSame('error', $corrida->estado, 'Sin olvidar la memoria, el job cargaba con una prueba que ya no estaba.');
        $this->assertSame('La prueba terminó antes de cargar «c.xlsx».', $corrida->error);
        $this->assertFalse(Client::where('user_id', $this->dueno->id)->where('name', 'Cache Job CC')->exists());
    }

    public function test_a7_el_plazo_de_la_carga_cuenta_desde_que_termino_de_borrar_la_semilla(): void
    {
        SemillaRegistroHelper::abrir_lote($this->dueno->id, 'test');
        Client::create(['user_id' => $this->dueno->id, 'name' => 'Ejemplo Plazo CC', 'num' => 1, 'phone' => '1']);
        SemillaRegistroHelper::cerrar_lote();
        $inicio = Carbon::now()->startOfSecond();
        Carbon::setTestNow($inicio);
        // El borrado de la semilla «tarda» 20 minutos: al marcarla borrada, el reloj avanza.
        $avanzado = false;
        PruebaConfig::saved(function () use (&$avanzado, $inicio) {
            if (!$avanzado) {
                $avanzado = true;
                Carbon::setTestNow($inicio->copy()->addMinutes(20));
            }
        });

        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono']);
        $uuid = $this->iniciar_en_la_prueba('client', $this->planilla([['Nombre', 'Telefono'], ['Plazo CC', '1']]), 165, 'p.xlsx')
            ->json('corrida.corrida_id');
        $corrida = $this->avanzar($uuid);
        $this->assertTrue($avanzado);
        $this->assertSame('terminada', $corrida->estado, (string) $corrida->error);
        $this->assertTrue($corrida->confirmada_at->eq($inicio->copy()->addMinutes(20)),
            'confirmada_at es el fin del borrado (' . $corrida->confirmada_at . '), no el fin del análisis.');

        // Con la marca así, una consulta del admin 5 minutos después no la da por cortada (el job duraba 900 s desde el
        // análisis, y con 20 minutos de borrado ya la habría dado por vencida).
        $otra = ImportacionDeSoporte::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $this->dueno->id,
            'support_ticket_id' => 166, 'clave' => hash('sha256', 'plazo'), 'modelo' => 'client', 'archivo' => 'x', 'nombre' => 'x',
            'estado' => 'importar', 'modo_prueba' => true, 'confirmada_at' => $corrida->confirmada_at, 'avisar_por_whatsapp' => false]);
        DB::table('importaciones_de_soporte')->where('id', $otra->id)->update(['updated_at' => $inicio->copy()->addMinutes(9)]);
        Carbon::setTestNow($inicio->copy()->addMinutes(25));
        app(ImportacionDeSoporteService::class)->reanudar_si_hace_falta($otra->fresh());
        $this->assertSame('importar', $otra->fresh()->estado);
    }

    public function test_a8_prueba_pendientes_no_vuelve_a_mirar_la_columna_en_cada_consulta(): void
    {
        $memo = new \ReflectionProperty(ImportacionDePruebaController::class, 'soporta_contactos_en_la_prueba');
        $memo->setAccessible(true);
        $memo->setValue(null, null);
        $pendientes = '/api/admin-sync/ai-excel-import/prueba-pendientes?user_id=' . $this->dueno->id . '&support_ticket_id=167';
        $consultas_a_la_tabla = function () {
            return count(array_filter(DB::getQueryLog(), function ($consulta) {
                return strpos($consulta['query'], 'information_schema') !== false
                    && in_array('importaciones_de_soporte', $consulta['bindings'], true);
            }));
        };

        DB::enableQueryLog();
        $this->getJson($pendientes, $this->cabeceras())->assertOk()->assertJsonPath('capacidades_prueba', ['margen_previo', 'contactos']);
        $this->assertGreaterThan(0, $consultas_a_la_tabla());
        DB::flushQueryLog();
        $this->getJson($pendientes, $this->cabeceras())->assertOk()->assertJsonPath('capacidades_prueba', ['margen_previo', 'contactos']);
        $this->assertSame(0, $consultas_a_la_tabla(), 'La segunda consulta usa lo que ya sabe el proceso.');
        DB::disableQueryLog();
    }

    public function test_b2_en_el_aviso_de_contactos_se_nombran_hasta_diez_y_el_resto_con_el_numero(): void
    {
        $texto = new \ReflectionMethod(ImportacionDeSoporteService::class, 'texto_de_descartadas');
        $texto->setAccessible(true);
        $filas = [];
        for ($i = 1; $i <= 12; $i++) {
            $filas[] = ['fila' => $i + 1, 'texto' => 'Grupo ' . $i, 'motivo' => 'titulo'];
        }
        $this->assertSame(' Dejé afuera 12 filas que no son clientes: «Grupo 1», «Grupo 2», «Grupo 3», «Grupo 4», «Grupo 5», «Grupo 6»,'
            . ' «Grupo 7», «Grupo 8», «Grupo 9», «Grupo 10» y 2 más. Si alguna era un cliente, decímelo y la cargo.',
            $texto->invoke(new ImportacionDeSoporteService(), ['filas_descartadas' => $filas, 'filas_descartadas_total' => 12], 'cliente', 'clientes'));
        // Solo el total: no se ofrece cargarlo.
        $this->assertSame(' Dejé afuera 1 fila que no es un proveedor: «TOTAL».', $texto->invoke(new ImportacionDeSoporteService(),
            ['filas_descartadas' => [['fila' => 9, 'texto' => 'TOTAL', 'motivo' => 'total']], 'filas_descartadas_total' => 1], 'proveedor', 'proveedores'));
    }

    public function test_segunda_pasada_los_saldos_que_no_se_cargan_se_dicen_con_su_monto(): void
    {
        $viejo = Provider::create(['user_id' => $this->dueno->id, 'name' => 'Proveedor Viejo NSC', 'num' => 9601, 'phone' => '1']);
        \App\Http\Controllers\Helpers\CreditAccountHelper::crear_credit_accounts('provider', $viejo->id, $this->dueno->id);
        \App\Http\Controllers\Helpers\LocalImportHelper::crearSaldoInicialPorImportacion(500,
            \App\Http\Controllers\Helpers\LocalImportHelper::get_credit_account_pesos('provider', $viejo->id), 'provider', $viejo);
        $this->analizador(AiProviderAnalyzer::class, ['nombre', 'telefono', 'saldo_actual']);
        $uuid = $this->iniciar_en_la_prueba('provider', $this->planilla([
            ['Proveedor', 'Telefono', 'Saldo'], ['Proveedor Viejo NSC', '1', 800], ['Proveedor Nuevo NSC', '2', 300],
        ]), 170, 'p.xlsx')->json('corrida.corrida_id');
        $corrida = $this->avanzar($uuid);
        $this->assertSame('terminada', $corrida->estado, (string) $corrida->error);
        $this->assertEqualsWithDelta(500, $this->saldo('provider', $viejo->id), 0.001);
        $this->assertSame('Cargué 1 proveedor nuevo de «p.xlsx»; los demás ya estaban igual. En total les debés $300. A 1 no le cargué'
            . ' el saldo de tu planilla ($800) porque su cuenta ya tenía movimientos: en el sistema sigue con $500. Si algo no coincide'
            . ' con tu lista, decímelo y lo deshago.', $this->avisos(170)->last()->texto);
    }

    // -----------------------------------------------------------------------------------------------------
    // A1, tercera pasada: la señal de formato
    // -----------------------------------------------------------------------------------------------------

    /**
     * Una planilla con formato en la columna del nombre: fila => 'negrita' | 'relleno' | 'combinada'. La cabecera va en
     * negrita, como la escribe cualquiera.
     */
    private function planilla_con_formato(array $filas, array $estilos): string
    {
        $libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $hoja = $libro->getActiveSheet()->setTitle('Hoja 0');
        $hoja->fromArray($filas, null, 'A1', true);
        $hoja->getStyle('A1:D1')->getFont()->setBold(true);
        foreach ($estilos as $fila => $estilo) {
            if ($estilo === 'negrita') {
                $hoja->getStyle('A' . $fila)->getFont()->setBold(true);
            } elseif ($estilo === 'relleno') {
                $hoja->getStyle('A' . $fila)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('FFE699');
            } elseif ($estilo === 'combinada') {
                $hoja->mergeCells('A' . $fila . ':D' . $fila);
            } elseif ($estilo === 'combinada_con_la_de_al_lado') {
                $hoja->mergeCells('A' . $fila . ':B' . $fila);
            } elseif ($estilo === 'gris') {
                $hoja->getStyle('A' . $fila . ':D' . $fila)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('D9D9D9');
            } elseif ($estilo === 'rojo') {
                $hoja->getStyle('A' . $fila . ':D' . $fila)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('FF0000');
            }
        }
        $archivo = tempnam(sys_get_temp_dir(), 'soporte_formato_');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save($archivo);
        $libro->disconnectWorksheets();
        $this->archivos[] = $archivo;

        return $archivo;
    }

    /** Un título arriba (fila 2) y 9 clientes con teléfono y saldo. */
    private function lista_con_titulo(string $titulo, string $prefijo): array
    {
        $filas = [['Cliente', 'CUIT', 'Tel.', 'Saldo'], [$titulo, null, null, null]];
        for ($i = 1; $i <= 9; $i++) {
            $filas[] = [$prefijo . ' cliente ' . $i, null, '3411' . str_pad((string) $i, 6, '0', STR_PAD_LEFT), 100 * $i];
        }

        return $filas;
    }

    private function cargar_con_formato(array $filas, array $estilos, int $ticket): ImportacionDeSoporte
    {
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'cuit', 'telefono', 'saldo_actual']);
        $uuid = $this->iniciar_en_la_prueba('client', $this->planilla_con_formato($filas, $estilos), $ticket, 'f.xlsx')->json('corrida.corrida_id');
        $corrida = $this->avanzar($uuid);
        $this->assertSame('terminada', $corrida->estado, (string) $corrida->error);

        return $corrida;
    }

    public function test_a1_formato_un_titulo_fuera_del_vocabulario_en_negrita_queda_afuera_y_sin_formato_entra(): void
    {
        $ticket = 180;
        // Cuarta pasada: el relleno ya no es señal (ver el test de abajo); «Constructoras» va en negrita (antes, con relleno).
        foreach (['Fiado' => 'negrita', 'Constructoras' => 'negrita', 'Albañiles' => 'combinada', 'Los de siempre' => 'negrita'] as $titulo => $estilo) {
            $prefijo = 'F' . substr(md5($titulo), 0, 5);
            $corrida = $this->cargar_con_formato($this->lista_con_titulo($titulo, $prefijo), [2 => $estilo], $ticket++);
            $this->assertSame(9, $corrida->resultado['creados'], $titulo);
            $this->assertFalse(Client::where('user_id', $this->dueno->id)->where('name', $titulo)->exists(), $titulo);
            $this->assertSame([['fila' => 2, 'texto' => $titulo, 'motivo' => 'suelta']], $corrida->resumen['filas_descartadas'], $titulo);
            $this->assertStringContainsString('Dejé afuera 1 fila que no es un cliente: «' . $titulo . '». Si era un cliente, decímelo y la'
                . ' cargo.', $this->avisos($ticket - 1)->last()->texto);

            // La misma planilla sin formato: entra como un cliente más (no está en el vocabulario).
            $prefijo = 'S' . substr(md5($titulo), 0, 5);
            $corrida = $this->cargar_con_formato($this->lista_con_titulo($titulo . ' S', $prefijo), [], $ticket++);
            $this->assertSame(10, $corrida->resultado['creados'], $titulo . ' sin formato');
            $this->assertTrue(Client::where('user_id', $this->dueno->id)->where('name', $titulo . ' S')->exists());
        }
    }

    public function test_a1_formato_con_toda_la_columna_en_negrita_no_es_senal_y_gomez_sin_formato_entra(): void
    {
        $filas = $this->lista_con_titulo('Fiado TC', 'TC');
        $todas = [];
        for ($fila = 2; $fila <= 11; $fila++) {
            $todas[$fila] = 'negrita';
        }
        $corrida = $this->cargar_con_formato($filas, $todas, 190);
        $this->assertSame(10, $corrida->resultado['creados']);
        $this->assertTrue(Client::where('user_id', $this->dueno->id)->where('name', 'Fiado TC')->exists());

        // «Gómez» en letra normal entre clientes en letra normal: entra. En negrita queda afuera y se nombra.
        $corrida = $this->cargar_con_formato($this->lista_con_titulo('Gómez GN', 'GN'), [], 191);
        $this->assertSame(10, $corrida->resultado['creados']);
        $corrida = $this->cargar_con_formato($this->lista_con_titulo('Gómez GB', 'GB'), [2 => 'negrita'], 192);
        $this->assertSame(9, $corrida->resultado['creados']);
        $this->assertSame('Gómez GB', $corrida->resumen['filas_descartadas'][0]['texto']);
        // Una razón social en negrita entra igual.
        $corrida = $this->cargar_con_formato($this->lista_con_titulo('Constructora Norte SRL', 'RS'), [2 => 'negrita'], 193);
        $this->assertSame(10, $corrida->resultado['creados']);
    }

    public function test_a1_formato_un_activo_no_cambia(): void
    {
        $this->apagar_la_prueba();
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'cuit', 'telefono', 'saldo_actual']);
        $uuid = $this->iniciar('client', $this->planilla_con_formato($this->lista_con_titulo('Fiado AC', 'AC'), [2 => 'negrita']), 194)
            ->json('corrida.corrida_id');
        $corrida = $this->avanzar($uuid);
        $this->assertSame('confirmacion', $corrida->estado);
        $this->assertSame(10, $corrida->resumen['crear']);
        $this->assertContains('Fiado AC', $corrida->resumen['ejemplos_crear']);
        $this->assertArrayNotHasKey('filas_descartadas', $corrida->resumen);
    }

    // -----------------------------------------------------------------------------------------------------
    // Cuarta pasada: A1 a A5 de punta a punta
    // -----------------------------------------------------------------------------------------------------

    public function test_a1_cuarta_pasada_gomez_entra_con_filas_alternadas_morosos_en_rojo_o_combinada_por_error(): void
    {
        // Gómez (fila 2) + 9 clientes con datos (filas 3 a 11).
        $alternadas = [2 => 'gris', 4 => 'gris', 6 => 'gris', 8 => 'gris', 10 => 'gris'];
        $morosos = [2 => 'rojo', 5 => 'rojo', 9 => 'rojo'];
        $ticket = 200;
        foreach (['alternadas' => $alternadas, 'morosos' => $morosos, 'combinada' => [2 => 'combinada_con_la_de_al_lado']] as $caso => $estilos) {
            $prefijo = 'G' . substr(md5($caso), 0, 5);
            $corrida = $this->cargar_con_formato($this->lista_con_titulo('Gómez ' . $prefijo, $prefijo), $estilos, $ticket++);
            $this->assertSame(10, $corrida->resultado['creados'], $caso);
            $this->assertTrue(Client::where('user_id', $this->dueno->id)->where('name', 'Gómez ' . $prefijo)->exists(), $caso);
        }
    }

    public function test_a3_cuatro_grupos_con_senal_y_contactos_de_solo_nombre_mezclados(): void
    {
        $filas = [['Cliente', 'CUIT', 'Tel.', 'Saldo'], ['Clientes de la obra', null, null, null]];
        for ($i = 1; $i <= 4; $i++) {
            $filas[] = ['Obra G4 cliente ' . $i, null, '34110000' . $i, 100 * $i];
        }
        $filas[] = ['Del barrio', null, null, null];
        $filas[] = ['Kiosco G4', null, null, null];
        for ($i = 5; $i <= 8; $i++) {
            $filas[] = ['Barrio G4 cliente ' . $i, null, '34110000' . $i, 100 * $i];
        }
        $filas[] = ['Fiado', null, null, null];
        for ($i = 9; $i <= 12; $i++) {
            $filas[] = ['Fiado G4 cliente ' . $i, null, '3411000' . $i, 100 * $i];
        }
        $filas[] = ['mayoristas', null, null, null];
        $filas[] = ['Gómez G4', null, null, null];
        $corrida = $this->cargar_con_formato($filas, [13 => 'negrita'], 210);
        // 12 con datos y dos contactos de solo nombre; los cuatro grupos afuera, sin tope.
        $this->assertSame(14, $corrida->resultado['creados']);
        $this->assertSame(['Clientes de la obra', 'Del barrio', 'Fiado', 'mayoristas'], array_column($corrida->resumen['filas_descartadas'], 'texto'));
        $this->assertTrue(Client::where('user_id', $this->dueno->id)->whereIn('name', ['Kiosco G4', 'Gómez G4'])->count() === 2);
    }

    public function test_a4_a5_la_nota_debajo_del_total_sin_nombre_y_la_nota_en_el_medio_quedan_afuera_y_el_total_se_nombra(): void
    {
        $filas = [['Cliente', 'CUIT', 'Tel.', 'Saldo']];
        for ($i = 1; $i <= 5; $i++) {
            $filas[] = ['Nota cliente ' . $i, null, '34110000' . $i, 1000 * $i];
        }
        $filas[] = ['ojo: este paga los viernes, no antes', null, null, null];
        for ($i = 6; $i <= 9; $i++) {
            $filas[] = ['Nota cliente ' . $i, null, '34110000' . $i, 1000 * $i];
        }
        $filas[] = [null, null, 'TOTAL', 45000];
        $filas[] = [null, null, null, null];
        $filas[] = ['Fulana dejó plata de más, descontarle en la próxima compra', null, null, null];
        $corrida = $this->cargar_con_formato($filas, [], 211);
        $this->assertSame(9, $corrida->resultado['creados']);
        $this->assertFalse(Client::where('user_id', $this->dueno->id)->where('name', 'like', 'Fulana%')->exists());
        $this->assertSame([['fila' => 7, 'texto' => 'ojo: este paga los viernes, no antes', 'motivo' => 'nota'],
            ['fila' => 12, 'texto' => 'TOTAL', 'motivo' => 'total'],
            ['fila' => 14, 'texto' => 'Fulana dejó plata de más, descontarle en la próxima compra', 'motivo' => 'nota']],
            $corrida->resumen['filas_descartadas']);
        $this->assertStringContainsString('Dejé afuera 3 filas que no son clientes: «ojo: este paga los viernes, no antes», «TOTAL» y «Fulana'
            . ' dejó plata de más, descontarle en la próxima compra». Si alguna era un cliente, decímelo y la cargo.', $this->avisos(211)->last()->texto);
    }

    // -----------------------------------------------------------------------------------------------------
    // Quinta pasada: la red para lo dudoso, los saldos de comerciante y los repetidos
    // -----------------------------------------------------------------------------------------------------

    public function test_a5_los_que_entraron_solo_con_el_nombre_se_dicen(): void
    {
        $filas = [['Nombre', 'Telefono', 'Saldo']];
        for ($i = 1; $i <= 6; $i++) {
            $filas[] = ['Con datos SN ' . $i, '341100' . $i, 100 * $i];
        }
        foreach (['Gómez SN', 'Kiosco SN', 'Pirulo SN', 'Doña Rosa SN', 'Martín SN', 'Carla SN', 'Hugo SN'] as $nombre) {
            $filas[] = [$nombre, null, null];
        }
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono', 'saldo_actual']);
        $uuid = $this->iniciar_en_la_prueba('client', $this->planilla($filas), 220, 'sn.xlsx')->json('corrida.corrida_id');
        $corrida = $this->avanzar($uuid);
        $this->assertSame('terminada', $corrida->estado, (string) $corrida->error);
        $this->assertSame(13, $corrida->resultado['creados'], 'Sin señal, entran todos.');
        $this->assertStringContainsString(' 7 quedaron solo con el nombre («Gómez SN», «Kiosco SN», «Pirulo SN», «Doña Rosa SN», «Martín SN» y'
            . ' 2 más): si alguno no era un cliente, decímelo.', $this->avisos(220)->last()->texto);

        // Uno solo, y los que traen saldo no cuentan.
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono', 'saldo_actual']);
        $otra = $this->iniciar_en_la_prueba('client', $this->planilla([['Nombre', 'Telefono', 'Saldo'], ['Con saldo SN', null, 500],
            ['Sin nada SN', null, null], ['Con tel SN', '1', null]]), 221, 'uno.xlsx')->json('corrida.corrida_id');
        $this->assertSame('terminada', $this->avanzar($otra)->estado);
        $this->assertStringContainsString(' 1 quedó solo con el nombre («Sin nada SN»): si no era un cliente, decímelo.', $this->avisos(221)->last()->texto);
    }

    public function test_b2_los_saldos_escritos_como_los_escribe_un_comerciante_se_leen_en_la_prueba_y_un_activo_queda_igual(): void
    {
        $filas = [['Nombre', 'Telefono', 'Saldo'], ['Saldo A', '1', '$13500.-'], ['Saldo B', '2', '1.250,50 $'], ['Saldo C', '3', '1250 pesos'],
            ['Saldo D', '4', 'USD 1.500']];
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono', 'saldo_actual']);
        $uuid = $this->iniciar_en_la_prueba('client', $this->planilla($filas), 222, 's.xlsx')->json('corrida.corrida_id');
        $corrida = $this->avanzar($uuid);
        // La moneda extranjera sigue siendo ilegible: la carga no se hace y se dice cuál.
        $this->assertSame('saldos_ilegibles', $corrida->motivo);
        $this->assertSame(['Saldo D'], array_column($corrida->resumen['saldos_ilegibles'], 'nombre'));

        array_pop($filas);
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono', 'saldo_actual']);
        $uuid = $this->iniciar_en_la_prueba('client', $this->planilla($filas), 223, 's2.xlsx')->json('corrida.corrida_id');
        $corrida = $this->avanzar($uuid);
        $this->assertSame('terminada', $corrida->estado, (string) $corrida->error);
        foreach (['Saldo A' => 13500, 'Saldo B' => 1250.5, 'Saldo C' => 1250] as $nombre => $saldo) {
            $cliente = Client::where('user_id', $this->dueno->id)->where('name', $nombre)->firstOrFail();
            $this->assertEqualsWithDelta($saldo, $this->saldo('client', $cliente->id), 0.001, $nombre);
        }

        // Un activo, como siempre: el lector de siempre (no se toca la regla de los negocios que pagan).
        $this->apagar_la_prueba();
        $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono', 'saldo_actual']);
        $activo = $this->iniciar('client', $this->planilla([['Nombre', 'Telefono', 'Saldo'], ['Activo Saldo A', '1', '$13500.-']]), 224)
            ->json('corrida.corrida_id');
        $corrida = $this->avanzar($activo);
        $this->assertSame('confirmacion', $corrida->estado);
        $this->assertSame(['$13500.-'], array_column($corrida->resumen['saldos_ilegibles'], 'texto'));
    }

    public function test_b4_los_textos_repetidos_de_las_filas_afuera_se_agrupan(): void
    {
        $texto = new \ReflectionMethod(ImportacionDeSoporteService::class, 'texto_de_descartadas');
        $texto->setAccessible(true);
        $filas = [['fila' => 5, 'texto' => 'Subtotal', 'motivo' => 'total'], ['fila' => 9, 'texto' => 'Subtotal', 'motivo' => 'total'],
            ['fila' => 14, 'texto' => 'Subtotal', 'motivo' => 'total'], ['fila' => 15, 'texto' => 'TOTAL GENERAL', 'motivo' => 'total']];
        $this->assertSame(' Dejé afuera 4 filas que no son clientes: «Subtotal» (3 veces) y «TOTAL GENERAL».',
            $texto->invoke(new ImportacionDeSoporteService(), ['filas_descartadas' => $filas, 'filas_descartadas_total' => 4], 'cliente', 'clientes'));
    }

    // -----------------------------------------------------------------------------------------------------
    // Sexta pasada: plata
    // -----------------------------------------------------------------------------------------------------

    public function test_sexta_pasada_zonas_con_su_total_el_aviso_cierra_con_el_total_general(): void
    {
        $filas = [['Cliente', 'CUIT', 'Tel.', 'Saldo']];
        $zonas = ['Barrio Norte' => [12000, 8000.5, 4000], 'Centro' => [3000, 2500, 1000], 'Villa del Parque' => [10000, 15000, 6000]];
        $general = 0.0;
        $estilos = [];
        foreach ($zonas as $zona => $saldos) {
            $filas[] = [$zona, null, null, null];
            $estilos[count($filas)] = 'rojo';
            foreach ($saldos as $i => $saldo) {
                $filas[] = [$zona . ' cliente ' . ($i + 1), null, '34110' . count($filas), $saldo];
            }
            $filas[] = ['Total ' . $zona, null, null, array_sum($saldos)];
            $general += array_sum($saldos);
        }
        $filas[] = ['TOTAL GENERAL', null, null, $general];
        $corrida = $this->cargar_con_formato($filas, $estilos, 230);
        $this->assertSame(9, $corrida->resultado['creados']);
        foreach (array_keys($zonas) as $zona) {
            $this->assertFalse(Client::where('user_id', $this->dueno->id)->whereIn('name', [$zona, 'Total ' . $zona])->exists(), $zona);
        }
        // Lo que le deben cierra con el TOTAL GENERAL de su planilla (61.500,50) y ningún total es «el saldo más alto».
        $texto = $this->avisos(230)->last()->texto;
        $this->assertStringContainsString('En total te deben $61.500,50; el saldo más alto es el de Villa del Parque cliente 2 ($15.000).', $texto);
        $this->assertStringNotContainsString('solo con el nombre', $texto);
    }

    public function test_sexta_pasada_subtotales_de_grupos_en_mayusculas_no_se_cargan_como_clientes(): void
    {
        foreach (['Subtotal talleres y flotas', 'Subtotal talleres, flotas y remises', 'Sub total de los talleres de la zona'] as $n => $rotulo) {
            $filas = [['Cliente', 'CUIT', 'Tel.', 'Saldo'], ['PARTICULARES', null, null, null], ['Ana P' . $n, null, '1', 1000], ['Bruno P' . $n, null, '2', 2000],
                ['Carla P' . $n, null, '3', 3000], ['Subtotal particulares', null, null, 6000], ['TALLERES Y FLOTAS', null, null, null],
                ['Taller Uno ' . $n, null, '4', 500000], ['Taller Dos ' . $n, null, '5', 488780.5], [$rotulo, null, null, 988780.5],
                ['TOTAL', null, null, 994780.5]];
            $corrida = $this->cargar_con_formato($filas, [], 231 + $n);
            $this->assertSame(5, $corrida->resultado['creados'], $rotulo);
            $this->assertFalse(Client::where('user_id', $this->dueno->id)->where('name', $rotulo)->exists(), $rotulo);
            $this->assertStringContainsString('En total te deben $994.780,50; el saldo más alto es el de Taller Uno ' . $n . ' ($500.000).',
                $this->avisos(231 + $n)->last()->texto, $rotulo);
        }
    }

    public function test_sexta_pasada_el_saldo_con_el_menos_atras_no_se_adivina(): void
    {
        foreach (['1500-', '1.500-', '0-', '1500 -'] as $n => $saldo) {
            $this->analizador(AiClientAnalyzer::class, ['nombre', 'telefono', 'saldo_actual']);
            $uuid = $this->iniciar_en_la_prueba('client', $this->planilla([['Nombre', 'Telefono', 'Saldo'], ['Menos ' . $n, '1', $saldo],
                ['Otro menos ' . $n, '2', '1.500,-']]), 240 + $n, 'm.xlsx')->json('corrida.corrida_id');
            $corrida = $this->avanzar($uuid);
            $this->assertSame('saldos_ilegibles', $corrida->motivo, $saldo);
            $this->assertSame([$saldo], array_column($corrida->resumen['saldos_ilegibles'], 'texto'), $saldo);
            $this->assertFalse(Client::where('user_id', $this->dueno->id)->where('name', 'Menos ' . $n)->exists(), $saldo);
        }
    }
}
