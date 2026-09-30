<?php

namespace Tests\Feature\AuditoriaDeCambios;

use App\Models\Provider;
use App\Services\AuditLog\AuditContext;
use Illuminate\Support\Facades\Log;

/**
 * Test 7 del plan: si el registro de auditoría falla, la operación de negocio termina bien y sale
 * un warning (misión auditoria-de-cambios, 30/9/2026).
 *
 * El caso real es un cliente a mitad de un upgrade: el código nuevo ya está, pero la migración de
 * `audit_logs` todavía no corrió. La tabla no existe, y el sistema tiene que seguir vendiendo.
 * Se reproduce apuntando la tabla de la auditoría a una que no existe (`audit_log.tabla`) y NO
 * borrando la real: un DROP/ALTER hace commit implícito en MySQL y rompería el aislamiento del
 * resto de la suite.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
class FalloDelRegistroNoRompeElNegocioTest extends AuditoriaTestCase
{
    /**
     * Con la tabla ausente, guardar un modelo funciona igual y se avisa UNA sola vez.
     *
     * @return void
     */
    public function test_con_la_tabla_ausente_la_operacion_termina_bien_y_sale_un_solo_warning()
    {
        config(['audit_log.tabla' => 'audit_logs_que_no_existe']);

        Log::spy();

        $primero = Provider::create(['name' => 'ZZ Proveedor sin auditoria 1', 'user_id' => $this->dueno->id]);
        $segundo = Provider::create(['name' => 'ZZ Proveedor sin auditoria 2', 'user_id' => $this->dueno->id]);

        $primero->update(['name' => 'ZZ Proveedor sin auditoria 1 editado']);

        // El negocio no se enteró: los tres guardados quedaron.
        $this->assertSame(1, Provider::where('name', 'ZZ Proveedor sin auditoria 1 editado')->count());
        $this->assertSame(1, Provider::where('name', 'ZZ Proveedor sin auditoria 2')->count());

        // Y hubo un solo aviso, no uno por fila.
        Log::shouldHaveReceived('warning')
            ->withArgs(function ($mensaje) {
                return strpos((string) $mensaje, 'AuditLogRecorder') !== false;
            })
            ->once();

        // Y tras el error de "tabla inexistente" el proceso deja de insistir un rato (no paga un
        // INSERT fallido por cada fila). Queda comprobado de forma observable en el test siguiente.
        $this->assertGreaterThan(time(), AuditContext::esperar_hasta());
    }

    /**
     * Cuando la tabla vuelve a existir (reinicio del contexto = proceso nuevo), se audita de nuevo.
     *
     * @return void
     */
    public function test_al_volver_la_tabla_se_retoma_la_auditoria()
    {
        config(['audit_log.tabla' => 'audit_logs_que_no_existe']);

        Log::spy();

        Provider::create(['name' => 'ZZ Proveedor antes', 'user_id' => $this->dueno->id]);

        config(['audit_log.tabla' => 'audit_logs']);

        // Todavía dentro de la espera: no escribe.
        Provider::create(['name' => 'ZZ Proveedor durante', 'user_id' => $this->dueno->id]);
        $this->assertSame(0, $this->filas()->count());

        // Pasada la espera (un worker de cola vive días), retoma sola.
        AuditContext::fijar_espera(time() - 1);

        Provider::create(['name' => 'ZZ Proveedor despues', 'user_id' => $this->dueno->id]);
        $this->assertSame(1, $this->filas(Provider::class, 'created')->count());
    }
}
