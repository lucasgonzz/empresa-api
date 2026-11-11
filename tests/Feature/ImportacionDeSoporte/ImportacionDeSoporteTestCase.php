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
 * Lo común de los tests de la importación de clientes y proveedores desde soporte (misión importaciones-de-martin,
 * 11/11/2026): el dueño activo, la clave, el análisis de IA simulado, las planillas y las cuentas corrientes.
 */
abstract class ImportacionDeSoporteTestCase extends EmpresaTestCase
{
    protected const CLAVE = 'admin-importacion-soporte-contactos-test';
    protected const BASE = '/api/admin-sync/importacion-soporte/';

    protected $dueno;
    protected $archivos = [];

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


    protected function importar_confirmado(string $modelo, string $archivo, string $clase, array $campos, int $ticket): ImportacionDeSoporte
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

    /** Pide deshacer (202, queda `revirtiendo`), corre el job y devuelve el estado. */
    protected function revertir(string $uuid, int $ticket)
    {
        $datos = ['user_id' => $this->dueno->id, 'support_ticket_id' => $ticket];
        $this->postJson(self::BASE . $uuid . '/revertir', $datos, $this->cabeceras())
            ->assertStatus(202)->assertJsonPath('corrida.estado', 'revirtiendo');
        Queue::assertPushed(AvanzarImportacionDeSoporte::class);
        app(ImportacionDeSoporteService::class)->avanzar(ImportacionDeSoporte::where('uuid', $uuid)->firstOrFail());

        return $this->getJson(self::BASE . $uuid . '?' . http_build_query($datos), $this->cabeceras())->assertOk();
    }

    protected function iniciar(string $modelo, string $archivo, int $ticket, $user_id = null)
    {
        return $this->post(self::BASE . $modelo . '/iniciar', [
            'user_id' => $user_id ?: $this->dueno->id,
            'support_ticket_id' => $ticket,
            'excel_file' => new UploadedFile($archivo, 'contactos.xlsx', null, null, true),
        ], $this->cabeceras());
    }

    protected function cabeceras(): array
    {
        return ['X-Admin-Api-Key' => self::CLAVE, 'Accept' => 'application/json'];
    }

    protected function cliente(string $nombre, int $num, string $telefono, $saldo = null): Client
    {
        $cliente = Client::create(['user_id' => $this->dueno->id, 'name' => $nombre, 'num' => $num, 'phone' => $telefono]);
        CreditAccountHelper::crear_credit_accounts('client', $cliente->id, $this->dueno->id);
        if (!is_null($saldo)) {
            LocalImportHelper::crearSaldoInicialPorImportacion($saldo, LocalImportHelper::get_credit_account_pesos('client', $cliente->id), 'client', $cliente);
        }

        return $cliente->fresh();
    }

    /** Un débito cualquiera en la cuenta (una venta a cuenta corriente, por ejemplo). */
    protected function debito(string $modelo, int $id, float $monto): void
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

    protected function saldo(string $modelo, int $id): float
    {
        return (float) LocalImportHelper::get_credit_account_pesos($modelo, $id)->fresh()->saldo;
    }

    protected function planilla(array $filas): string
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
    protected function analizador(string $clase, array $campos): void
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
