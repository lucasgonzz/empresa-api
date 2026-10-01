<?php

namespace Tests\Feature\AuditoriaDeCambios;

use App\Models\AuditLog;
use App\Models\GeocoderCounter;
use App\Models\ImportStatus;
use App\Models\LastSearch;
use App\Models\Provider;
use App\Models\ImageAssignmentRun;
use App\Models\Provincia;

/**
 * Test 5 del plan: los modelos excluidos no generan fila, y la propia `AuditLog` no se audita a
 * sí misma (misión auditoria-de-cambios, 30/9/2026).
 *
 * Se prueba con modelos reales de cada grupo de la exclusión (infraestructura, telemetría) y con
 * un modelo de negocio como control: si el control no dejara fila, el test estaría "verde" solo
 * porque el listener no anda.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
class ModelosExcluidosTest extends AuditoriaTestCase
{
    /**
     * @return void
     */
    public function test_los_modelos_excluidos_no_dejan_filas_y_uno_de_negocio_si()
    {
        // Infraestructura: estado de avance de una importación.
        $estado = ImportStatus::create(['user_id' => $this->dueno->id]);
        $estado->update(['status' => 'completado']);
        $estado->delete();

        // Telemetría: una búsqueda y un contador del geocodificador.
        $busqueda = LastSearch::create(['body' => 'ZZ busqueda de prueba']);
        $busqueda->update(['body' => 'ZZ busqueda editada']);
        GeocoderCounter::create(['user_id' => $this->dueno->id, 'counter' => 1]);

        $this->assertSame(0, $this->filas()->count(), 'Los modelos excluidos no pueden dejar filas.');

        // Control: un modelo de negocio con las mismas operaciones SÍ deja.
        $proveedor = Provider::create(['name' => 'ZZ Proveedor de control', 'user_id' => $this->dueno->id]);

        $this->assertCount(1, $this->filas(Provider::class, 'created'), 'El control tiene que auditar: si no, el test no prueba nada.');
    }

    /**
     * La auditoría no se audita a sí misma: modificar o borrar una fila de `audit_logs` por
     * Eloquent no deja otra fila.
     *
     * @return void
     */
    public function test_audit_log_no_se_audita_a_si_misma()
    {
        $this->crear_articulo(['name' => 'ZZ Auditoria recursion']);

        $fila = $this->filas()->first();
        $this->assertNotNull($fila);

        $cantidad = $this->filas()->count();

        // Un AuditLog creado, editado y borrado por Eloquent (por ejemplo, un script de soporte).
        $propia = AuditLog::create([
            'auditable_type' => 'App\Models\Article',
            'auditable_id'   => 1,
            'event'          => 'created',
            'source'         => 'console',
            'batch_uuid'     => '00000000-0000-4000-8000-000000000000',
        ]);

        $propia->update(['origin' => 'editada']);
        $propia->delete();

        // Al borrarla, la tabla queda como estaba: si la auditoría se auditara a sí misma habría
        // filas con auditable_type = AuditLog (una por el create, una por el update, una por el
        // delete) y el conteo no volvería al de antes.
        $this->assertSame($cantidad, $this->filas()->count(), 'La auditoría no puede auditarse a sí misma.');
        $this->assertSame(0, $this->filas(AuditLog::class)->count());
    }

    /**
     * Aunque se vacíe `modelos_excluidos`, AuditLog sigue sin auditarse: la exclusión también está
     * en el código, para que la recursión no dependa de que nadie toque el config.
     *
     * @return void
     */
    public function test_audit_log_no_se_audita_aunque_se_saque_del_config()
    {
        config(['audit_log.modelos_excluidos' => []]);

        $propia = AuditLog::create([
            'auditable_type' => 'App\Models\Article',
            'auditable_id'   => 1,
            'event'          => 'created',
            'source'         => 'console',
            'batch_uuid'     => '00000000-0000-4000-8000-000000000001',
        ]);

        $propia->update(['origin' => 'editada']);
        $propia->delete();

        $this->assertSame(0, $this->filas(AuditLog::class)->count(), 'AuditLog no se puede auditar a sí misma.');
        $this->assertSame(0, $this->filas()->count());
    }

    /**
     * Provincia la editan usuarios (`Route::resource('provincia')`): se audita. La corrida de
     * asignación de imágenes es estado de proceso: no.
     *
     * @return void
     */
    public function test_provincia_se_audita_y_la_corrida_de_imagenes_no()
    {
        $provincia = Provincia::create(['name' => 'ZZ Provincia de prueba', 'user_id' => $this->dueno->id]);

        $this->assertCount(1, $this->filas(Provincia::class, 'created'));

        $this->assertArrayNotHasKey(Provincia::class, config('audit_log.modelos_excluidos'));
        $this->assertArrayHasKey(ImageAssignmentRun::class, config('audit_log.modelos_excluidos'));
        $this->assertArrayHasKey(\App\Models\ImageAssignmentItem::class, config('audit_log.modelos_excluidos'));
    }
}
