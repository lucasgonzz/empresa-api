<?php

namespace Tests\Feature\ImportacionDeSoporte;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\LocalImportHelper;
use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\import\client\AiClientAnalyzer;
use App\Http\Controllers\Helpers\import\provider\AiProviderAnalyzer;
use App\Jobs\AvanzarImportacionDeSoporte;
use App\Models\AiAvisoDelAsistente;
use App\Models\Article;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\ImportacionDeSoporte;
use App\Models\ImportacionDeSoporteCambio;
use App\Models\Provider;
use App\Models\User;
use App\Services\Importacion\ImportacionDeSoporteService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\EmpresaTestCase;

/**
 * Importación de clientes y proveedores desde el ticket de soporte, con resumen, código y deshacer (misión
 * importaciones-de-martin, 11/11/2026).
 */
class ClientesYProveedoresDesdeSoporteTest extends EmpresaTestCase
{
    private const CLAVE = 'admin-importacion-soporte-contactos-test';
    private const BASE = '/api/admin-sync/importacion-soporte/';

    private $dueno;
    private $archivos = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->dueno = auth()->user();
        config([
            'app.USER_ID' => $this->dueno->id,
            'services.admin_api.api_key' => self::CLAVE,
        ]);
        PruebaHelper::olvidar_cache();
    }

    protected function tearDown(): void
    {
        foreach ($this->archivos as $archivo) {
            @unlink($archivo);
        }
        foreach (ImportacionDeSoporte::where('user_id', $this->dueno->id)->pluck('archivo') as $ruta) {
            @unlink(storage_path('app/' . $ruta));
        }
        PruebaHelper::olvidar_cache();
        parent::tearDown();
    }

    public function test_clientes_resumen_codigo_importacion_y_reversion_completa(): void
    {
        $ana = $this->cliente('Ana Soporte', 9101, '100', 1000);
        $bruno = $this->cliente('Bruno Soporte', 9102, '200');
        $archivo = $this->planilla([
            ['Numero', 'Nombre', 'Telefono', 'Saldo'],
            [9101, 'Ana Soporte', '111', 600],
            [null, 'Bruno Soporte', '222', null],
            [null, 'Carla Soporte', '333', 1500],
            [null, 'Dario Soporte', '444', 's/d'],
        ]);
        $this->analizador(AiClientAnalyzer::class, ['numero', 'nombre', 'telefono', 'saldo_actual']);

        $respuesta = $this->iniciar('client', $archivo, 30)->assertStatus(202)
            ->assertJsonPath('corrida.estado', 'analizar')->assertJsonPath('corrida_existente', false);
        $uuid = $respuesta->json('corrida.corrida_id');
        $this->iniciar('client', $archivo, 30)->assertStatus(202)
            ->assertJsonPath('corrida.corrida_id', $uuid)->assertJsonPath('corrida_existente', true);
        Queue::assertPushed(AvanzarImportacionDeSoporte::class, 1);

        $corrida = ImportacionDeSoporte::where('uuid', $uuid)->firstOrFail();
        app(ImportacionDeSoporteService::class)->avanzar($corrida);

        $estado = $this->getJson(self::BASE . $uuid . '?user_id=' . $this->dueno->id . '&support_ticket_id=30', $this->cabeceras())
            ->assertOk()->assertJsonPath('corrida.estado', 'confirmacion')->assertJsonPath('corrida.codigo_requerido', true)
            ->assertJsonPath('corrida.resumen.crear', 2)->assertJsonPath('corrida.resumen.actualizar', 2)
            ->assertJsonPath('corrida.resumen.saldos_total', 2)->assertJsonPath('corrida.revertible', false)->json('corrida');
        $saldos = collect($estado['resumen']['saldos'])->keyBy('nombre');
        $this->assertSame('nota_de_credito', $saldos['Ana Soporte']['accion']);
        $this->assertEquals(1000, $saldos['Ana Soporte']['saldo_actual']);
        $this->assertEquals(600, $saldos['Ana Soporte']['saldo_resultante']);
        $this->assertEquals(400, $saldos['Ana Soporte']['monto']);
        $this->assertSame('saldo_inicial', $saldos['Carla Soporte']['accion']);
        $this->assertEquals(1500, $saldos['Carla Soporte']['saldo_resultante']);
        $this->assertSame('s/d', $estado['resumen']['saldos_ilegibles'][0]['texto']);
        $codigo = $estado['codigo_confirmacion'];
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $codigo);
        $this->assertStringContainsString('CONFIRMAR ' . $codigo, $estado['texto_confirmacion']);
        $pregunta = AiAvisoDelAsistente::where('owner_id', $this->dueno->id)->where('support_ticket_id', 30)->firstOrFail();
        $this->assertTrue((bool) $pregunta->modo_soporte);
        $this->assertStringContainsString('CONFIRMAR ' . $codigo, $pregunta->texto);
        $this->assertStringContainsString('nota de crédito por $400', $pregunta->texto);
        $this->assertDatabaseMissing('clients', ['user_id' => $this->dueno->id, 'name' => 'Carla Soporte']);

        $confirmar = self::BASE . $uuid . '/confirmar';
        $datos = ['user_id' => $this->dueno->id, 'support_ticket_id' => 30];
        $this->postJson($confirmar, $datos, $this->cabeceras())->assertStatus(422);
        $this->postJson($confirmar, $datos + ['codigo' => 'ABCDEF12'], $this->cabeceras())
            ->assertStatus(409)->assertJsonPath('motivo', 'codigo_incorrecto');
        $this->postJson($confirmar, ['support_ticket_id' => 31] + $datos + ['codigo' => $codigo], $this->cabeceras())
            ->assertStatus(404)->assertJsonPath('motivo', 'corrida_no_encontrada');
        $this->postJson(self::BASE . $uuid . '/revertir', $datos, $this->cabeceras())
            ->assertStatus(409)->assertJsonPath('motivo', 'no_revertible');
        $this->postJson($confirmar, $datos + ['codigo' => strtolower($codigo)], $this->cabeceras())
            ->assertStatus(202)->assertJsonPath('corrida.estado', 'importar');
        $this->postJson($confirmar, $datos + ['codigo' => $codigo], $this->cabeceras())->assertOk();
        $this->assertFalse(AiAvisoDelAsistente::whereKey($pregunta->id)->exists(), 'La pregunta sin enviar se retira al confirmar.');

        app(ImportacionDeSoporteService::class)->avanzar($corrida->fresh());
        $corrida->refresh();
        $this->assertSame('terminada', $corrida->estado);
        $this->assertSame(2, $corrida->resultado['creados']);
        $this->assertSame(2, $corrida->resultado['actualizados']);
        $this->assertSame(['saldo_inicial' => 1, 'nota_de_credito' => 1, 'nota_de_debito' => 0], $corrida->resultado['movimientos']);
        $carla = Client::where('user_id', $this->dueno->id)->where('name', 'Carla Soporte')->firstOrFail();
        $this->assertEquals(1500, $this->saldo('client', $carla->id));
        $this->assertEquals(600, $this->saldo('client', $ana->id));
        $this->assertSame('222', $bruno->fresh()->phone);
        $this->assertSame('111', $ana->fresh()->phone);
        $this->assertDatabaseHas('clients', ['user_id' => $this->dueno->id, 'name' => 'Dario Soporte']);
        $this->assertSame(4, ImportacionDeSoporteCambio::where('importacion_de_soporte_id', $corrida->id)
            ->whereIn('tipo', ['creado', 'actualizado'])->count());
        $resultado = AiAvisoDelAsistente::where('owner_id', $this->dueno->id)->where('support_ticket_id', 30)->firstOrFail();
        $this->assertStringContainsString('2 clientes creados y 2 actualizados', $resultado->texto);

        $this->getJson(self::BASE . $uuid . '?user_id=' . $this->dueno->id . '&support_ticket_id=30', $this->cabeceras())
            ->assertOk()->assertJsonPath('corrida.revertible', true)->assertJsonPath('corrida.codigo_requerido', false);
        $this->postJson(self::BASE . $uuid . '/revertir', $datos, $this->cabeceras())
            ->assertOk()->assertJsonPath('corrida.estado', 'revertida')
            ->assertJsonPath('corrida.resultado.reversion.creados_borrados', 2)
            ->assertJsonPath('corrida.resultado.reversion.actualizados_restaurados', 2)
            ->assertJsonPath('corrida.resultado.reversion.movimientos_borrados', 2);
        $this->assertSoftDeleted('clients', ['id' => $carla->id]);
        $this->assertSame('200', $bruno->fresh()->phone);
        $this->assertSame('100', $ana->fresh()->phone);
        $this->assertEquals(1000, $this->saldo('client', $ana->id));
        $this->assertFalse(CurrentAcount::where('client_id', $ana->id)->where('status', 'nota_credito')->exists());
        $this->postJson(self::BASE . $uuid . '/revertir', $datos, $this->cabeceras())->assertOk()->assertJsonPath('corrida.estado', 'revertida');
        $this->postJson($confirmar, $datos + ['codigo' => $codigo], $this->cabeceras())
            ->assertStatus(409)->assertJsonPath('motivo', 'codigo_vencido');
        $this->assertStringContainsString('Deshice la importación', AiAvisoDelAsistente::where('owner_id', $this->dueno->id)
            ->where('support_ticket_id', 30)->latest('id')->value('texto'));
    }

    public function test_revertir_con_movimientos_posteriores_anula_con_contraasiento_y_respeta_ediciones(): void
    {
        $ana = $this->cliente('Ana Posterior', 9201, '100', 1000);
        $bruno = $this->cliente('Bruno Posterior', 9202, '200');
        $archivo = $this->planilla([
            ['Numero', 'Nombre', 'Telefono', 'Saldo'],
            [9201, 'Ana Posterior', '100', 600],
            [9202, 'Bruno Posterior', '222', null],
            [null, 'Elena Posterior', '555', 300],
        ]);
        $corrida = $this->importar_confirmado('client', $archivo, AiClientAnalyzer::class, ['numero', 'nombre', 'telefono', 'saldo_actual'], 40);
        $this->assertSame('terminada', $corrida->estado);
        $elena = Client::where('user_id', $this->dueno->id)->where('name', 'Elena Posterior')->firstOrFail();

        // Después de la importación: una venta a la cuenta de Ana, un cambio a mano en Bruno y un movimiento de Elena.
        $this->debito('client', $ana->id, 50);
        $this->debito('client', $elena->id, 70);
        $bruno->fresh()->update(['phone' => '999']);

        $this->postJson(self::BASE . $corrida->uuid . '/revertir', ['user_id' => $this->dueno->id, 'support_ticket_id' => 40], $this->cabeceras())
            ->assertOk()->assertJsonPath('corrida.resultado.reversion.contraasientos', 1)
            ->assertJsonPath('corrida.resultado.reversion.creados_conservados', 1)
            ->assertJsonPath('corrida.resultado.reversion.actualizados_conservados', 1);

        $this->assertEquals(1050, $this->saldo('client', $ana->id));
        $this->assertTrue(CurrentAcount::where('client_id', $ana->id)->where('description', ImportacionDeSoporteService::OBSERVACION_DE_LA_REVERSION)->exists());
        $this->assertSame('999', $bruno->fresh()->phone);
        $this->assertNotSoftDeleted('clients', ['id' => $elena->id]);
        $this->assertEquals(370, $this->saldo('client', $elena->id));
        $conservado = ImportacionDeSoporteCambio::where('importacion_de_soporte_id', $corrida->id)->where('modelo_id', $bruno->id)->firstOrFail();
        $this->assertSame('conservado', $conservado->reversion);
        $this->assertStringContainsString('phone', $conservado->motivo_reversion);
    }

    public function test_proveedores_saldo_inicial_no_se_carga_y_reversion_que_conserva_los_que_tienen_articulos(): void
    {
        $viejo = Provider::create(['user_id' => $this->dueno->id, 'name' => 'Proveedor Viejo Soporte', 'num' => 9301, 'phone' => '1']);
        CreditAccountHelper::crear_credit_accounts('provider', $viejo->id, $this->dueno->id);
        LocalImportHelper::crearSaldoInicialPorImportacion(500, LocalImportHelper::get_credit_account_pesos('provider', $viejo->id), 'provider', $viejo);
        $archivo = $this->planilla([
            ['Nombre', 'Telefono', 'Saldo'],
            ['Proveedor Viejo Soporte', '1', 800],
            ['Proveedor Nuevo Soporte', '2', 300],
            ['Proveedor Con Articulos', '3', null],
        ]);
        $this->analizador(AiProviderAnalyzer::class, ['nombre', 'telefono', 'saldo_actual']);
        $uuid = $this->iniciar('provider', $archivo, 50)->assertStatus(202)->json('corrida.corrida_id');
        $corrida = ImportacionDeSoporte::where('uuid', $uuid)->firstOrFail();
        app(ImportacionDeSoporteService::class)->avanzar($corrida);
        $corrida->refresh();
        $this->assertSame('confirmacion', $corrida->estado);
        $this->assertSame(2, $corrida->resumen['crear']);
        $this->assertSame(1, $corrida->resumen['actualizar']);
        $saldos = collect($corrida->resumen['saldos'])->keyBy('nombre');
        $this->assertSame('no_se_carga', $saldos['Proveedor Viejo Soporte']['accion']);
        $this->assertEquals(800, $saldos['Proveedor Viejo Soporte']['saldo_excel']);
        $this->assertSame('saldo_inicial', $saldos['Proveedor Nuevo Soporte']['accion']);
        $this->assertStringContainsString('el Excel dice $800 y la cuenta tiene $500', AiAvisoDelAsistente::where('owner_id', $this->dueno->id)
            ->where('support_ticket_id', 50)->value('texto'));

        $this->postJson(self::BASE . $uuid . '/confirmar', ['user_id' => $this->dueno->id, 'support_ticket_id' => 50, 'codigo' => $corrida->codigo], $this->cabeceras())
            ->assertStatus(202);
        app(ImportacionDeSoporteService::class)->avanzar($corrida->fresh());
        $corrida->refresh();
        $this->assertSame('terminada', $corrida->estado);
        $this->assertSame(1, $corrida->resultado['saldos_no_cargados']);
        $nuevo = Provider::where('user_id', $this->dueno->id)->where('name', 'Proveedor Nuevo Soporte')->firstOrFail();
        $con_articulos = Provider::where('user_id', $this->dueno->id)->where('name', 'Proveedor Con Articulos')->firstOrFail();
        $this->assertEquals(300, $this->saldo('provider', $nuevo->id));
        $this->assertEquals(500, $this->saldo('provider', $viejo->id));
        Article::create(['user_id' => $this->dueno->id, 'name' => 'Artículo del proveedor nuevo', 'status' => 'active', 'provider_id' => $con_articulos->id]);

        $this->postJson(self::BASE . $uuid . '/revertir', ['user_id' => $this->dueno->id, 'support_ticket_id' => 50], $this->cabeceras())
            ->assertOk()->assertJsonPath('corrida.resultado.reversion.creados_borrados', 1)
            ->assertJsonPath('corrida.resultado.reversion.creados_conservados', 1);
        $this->assertSoftDeleted('providers', ['id' => $nuevo->id]);
        $this->assertFalse(CurrentAcount::where('provider_id', $nuevo->id)->exists());
        $this->assertNotSoftDeleted('providers', ['id' => $con_articulos->id]);
        $this->assertEquals(500, $this->saldo('provider', $viejo->id));
    }

    public function test_si_cambia_el_resumen_entre_el_codigo_y_la_importacion_vuelve_a_preguntar_y_cancelar_no_importa(): void
    {
        $ana = $this->cliente('Ana Cambia', 9401, '100', 1000);
        $archivo = $this->planilla([['Numero', 'Nombre', 'Saldo'], [9401, 'Ana Cambia', 600], [null, 'Nueva Cambia', 10]]);
        $this->analizador(AiClientAnalyzer::class, ['numero', 'nombre', 'saldo_actual']);
        $uuid = $this->iniciar('client', $archivo, 60)->json('corrida.corrida_id');
        $corrida = ImportacionDeSoporte::where('uuid', $uuid)->firstOrFail();
        app(ImportacionDeSoporteService::class)->avanzar($corrida);
        $primero = $corrida->fresh()->codigo;
        $datos = ['user_id' => $this->dueno->id, 'support_ticket_id' => 60];
        $this->postJson(self::BASE . $uuid . '/confirmar', $datos + ['codigo' => $primero], $this->cabeceras())->assertStatus(202);

        $this->debito('client', $ana->id, 100);
        app(ImportacionDeSoporteService::class)->avanzar($corrida->fresh());
        $corrida->refresh();
        $this->assertSame('confirmacion', $corrida->estado);
        $this->assertSame(2, (int) $corrida->revision);
        $this->assertNotSame($primero, $corrida->codigo);
        $this->assertEquals(1100, collect($corrida->resumen['saldos'])->firstWhere('nombre', 'Ana Cambia')['saldo_actual']);
        $this->assertDatabaseMissing('clients', ['user_id' => $this->dueno->id, 'name' => 'Nueva Cambia']);
        $this->postJson(self::BASE . $uuid . '/confirmar', $datos + ['codigo' => $primero], $this->cabeceras())
            ->assertStatus(409)->assertJsonPath('motivo', 'codigo_incorrecto');

        $this->postJson(self::BASE . $uuid . '/confirmar', $datos + ['codigo' => $corrida->codigo, 'decision' => 'cancelar'], $this->cabeceras())
            ->assertOk()->assertJsonPath('corrida.estado', 'cancelada');
        app(ImportacionDeSoporteService::class)->avanzar($corrida->fresh());
        $this->assertDatabaseMissing('clients', ['user_id' => $this->dueno->id, 'name' => 'Nueva Cambia']);
    }

    public function test_dueno_no_activo_otro_dueno_de_la_base_compartida_y_clave(): void
    {
        $otro = User::create(['name' => 'Otro comercio', 'company_name' => 'Otro comercio', 'password' => Hash::make('x'),
            'email' => 'otro-soporte-' . uniqid() . '@test.local']);
        $del_otro = Client::create(['user_id' => $otro->id, 'name' => 'Compartido Soporte', 'num' => 1, 'phone' => 'otro']);
        $archivo = $this->planilla([['Nombre', 'Telefono'], ['Compartido Soporte', 'nuevo']]);

        $this->post(self::BASE . 'client/iniciar', ['user_id' => $this->dueno->id, 'support_ticket_id' => 70,
            'excel_file' => new UploadedFile($archivo, 'clientes.xlsx', null, null, true)], ['Accept' => 'application/json'])->assertStatus(401);
        $this->iniciar('client', $archivo, 70, $otro->id)->assertStatus(409)->assertJsonPath('motivo', 'dueno_no_activo');
        $this->iniciar('article', $archivo, 70)->assertStatus(404);
        $this->getJson(self::BASE . '00000000-0000-4000-8000-000000000000?user_id=' . $this->dueno->id . '&support_ticket_id=70', $this->cabeceras())
            ->assertStatus(404)->assertJsonPath('motivo', 'corrida_no_encontrada');

        $corrida = $this->importar_confirmado('client', $archivo, AiClientAnalyzer::class, ['nombre', 'telefono'], 70);
        $this->assertSame(1, $corrida->resultado['creados']);
        $this->assertSame('otro', $del_otro->fresh()->phone);
        $this->assertDatabaseHas('clients', ['user_id' => $this->dueno->id, 'name' => 'Compartido Soporte', 'phone' => 'nuevo']);

        config(['app.FOR_USER' => 'prueba']);
        PruebaHelper::olvidar_cache();
        PruebaHelper::activar_desde_payload($this->dueno, ['dias' => 7]);
        PruebaHelper::olvidar_cache();
        $this->iniciar('client', $this->planilla([['Nombre'], ['Otro']]), 71)->assertStatus(409)->assertJsonPath('motivo', 'dueno_no_activo');
        $this->getJson(self::BASE . $corrida->uuid . '?user_id=' . $this->dueno->id . '&support_ticket_id=70', $this->cabeceras())
            ->assertStatus(409)->assertJsonPath('motivo', 'dueno_no_activo');
    }

    /* -------------------------------------------------------------------------------------------------- */

    private function importar_confirmado(string $modelo, string $archivo, string $clase, array $campos, int $ticket): ImportacionDeSoporte
    {
        $this->analizador($clase, $campos);
        $uuid = $this->iniciar($modelo, $archivo, $ticket)->assertStatus(202)->json('corrida.corrida_id');
        $corrida = ImportacionDeSoporte::where('uuid', $uuid)->firstOrFail();
        app(ImportacionDeSoporteService::class)->avanzar($corrida);
        $this->postJson(self::BASE . $uuid . '/confirmar', ['user_id' => $this->dueno->id, 'support_ticket_id' => $ticket,
            'codigo' => $corrida->fresh()->codigo], $this->cabeceras())->assertStatus(202);
        app(ImportacionDeSoporteService::class)->avanzar($corrida->fresh());

        return $corrida->fresh();
    }

    private function iniciar(string $modelo, string $archivo, int $ticket, $user_id = null)
    {
        return $this->post(self::BASE . $modelo . '/iniciar', [
            'user_id' => $user_id ?: $this->dueno->id,
            'support_ticket_id' => $ticket,
            'excel_file' => new UploadedFile($archivo, 'contactos.xlsx', null, null, true),
        ], $this->cabeceras());
    }

    private function cabeceras(): array
    {
        return ['X-Admin-Api-Key' => self::CLAVE, 'Accept' => 'application/json'];
    }

    private function cliente(string $nombre, int $num, string $telefono, $saldo = null): Client
    {
        $cliente = Client::create(['user_id' => $this->dueno->id, 'name' => $nombre, 'num' => $num, 'phone' => $telefono]);
        CreditAccountHelper::crear_credit_accounts('client', $cliente->id, $this->dueno->id);
        if (!is_null($saldo)) {
            LocalImportHelper::crearSaldoInicialPorImportacion($saldo, LocalImportHelper::get_credit_account_pesos('client', $cliente->id), 'client', $cliente);
        }

        return $cliente->fresh();
    }

    /** Un débito cualquiera en la cuenta (una venta a cuenta corriente, por ejemplo). */
    private function debito(string $modelo, int $id, float $monto): void
    {
        $cuenta = LocalImportHelper::get_credit_account_pesos($modelo, $id);
        $movimiento = CurrentAcount::create([
            'detalle' => 'Venta de prueba', 'debe' => $monto, 'status' => 'sin_pagar',
            $modelo . '_id' => $id, 'user_id' => $this->dueno->id, 'credit_account_id' => $cuenta->id, 'moneda_id' => 1,
            'created_at' => now()->addSecond(),
        ]);
        $movimiento->saldo = CurrentAcountHelper::getSaldo($cuenta->id, $movimiento) + $monto;
        $movimiento->save();
        CurrentAcountHelper::update_credit_account_saldo($cuenta->id);
    }

    private function saldo(string $modelo, int $id): float
    {
        return (float) LocalImportHelper::get_credit_account_pesos($modelo, $id)->fresh()->saldo;
    }

    private function planilla(array $filas): string
    {
        $libro = new Spreadsheet();
        $libro->getActiveSheet()->setTitle('Hoja 0')->fromArray($filas, null, 'A1', true);
        $archivo = tempnam(sys_get_temp_dir(), 'soporte_contactos_');
        (new Xlsx($libro))->save($archivo);
        $libro->disconnectWorksheets();
        $this->archivos[] = $archivo;

        return $archivo;
    }

    /** El análisis de la IA simulado: cada campo en su columna, en orden, con el encabezado en la fila 1. */
    private function analizador(string $clase, array $campos): void
    {
        $analizador = Mockery::mock($clase);
        $analizador->shouldReceive('analyze')->andReturnUsing(function () use ($campos) {
            $mapeo = [];
            foreach ($campos as $posicion => $campo) {
                $mapeo[] = ['system_property' => $campo, 'confidence' => 0.95, 'excel_column_index' => $posicion, 'excel_column' => $campo];
            }

            return [
                'column_mapping' => $mapeo, 'provider_id' => null, 'provider_confidence' => null, 'row_count' => 3,
                'hoja_elegida' => ['indice' => 0, 'nombre' => 'Hoja 0'],
                'encabezado_detectado' => ['fila' => 1, 'columnas' => $campos],
            ];
        });
        $this->app->bind($clase, function () use ($analizador) {
            return $analizador;
        });
    }
}
