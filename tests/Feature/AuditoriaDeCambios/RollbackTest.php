<?php

namespace Tests\Feature\AuditoriaDeCambios;

use App\Models\Provider;
use Illuminate\Support\Facades\DB;

/**
 * Test 6 del plan: si la transacción del negocio hace rollback, la auditoría también (misión
 * auditoria-de-cambios, 30/9/2026).
 *
 * Es la razón por la que el INSERT de auditoría es inmediato y por la misma conexión, y no diferido
 * a `terminating` ni a una cola (ver el docblock de `AuditLogRecorder::insertar()`): una venta que
 * revienta a mitad de camino y se revierte no puede dejar constancia de algo que nunca pasó.
 *
 * Los tests corren dentro de la transacción de `DatabaseTransactions`, así que `DB::transaction()`
 * anida con savepoints; el rollback de un savepoint descarta igual todo lo escrito adentro.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
class RollbackTest extends AuditoriaTestCase
{
    /**
     * Una transacción que tira una excepción no deja filas de auditoría.
     *
     * @return void
     */
    public function test_una_transaccion_que_falla_no_deja_filas_de_auditoria()
    {
        $tiro = false;

        try {
            DB::transaction(function () {

                Provider::create(['name' => 'ZZ Proveedor que se revierte', 'user_id' => $this->dueno->id]);

                // Adentro de la transacción la fila de auditoría existe (es parte de ella)...
                $this->assertSame(1, $this->filas()->count());

                throw new \RuntimeException('Falla a mitad de la operación de negocio');
            });
        } catch (\RuntimeException $e) {
            $tiro = true;
        }

        $this->assertTrue($tiro, 'La transacción tenía que fallar.');

        // ...y con el rollback desaparece junto con el proveedor.
        $this->assertSame(0, Provider::where('name', 'ZZ Proveedor que se revierte')->count());
        $this->assertSame(0, $this->filas()->count(), 'El rollback tiene que llevarse también la auditoría.');
    }

    /**
     * Un rollback explícito hace lo mismo, y lo que se confirma después sí queda.
     *
     * @return void
     */
    public function test_un_rollback_explicito_no_deja_filas_y_un_commit_si()
    {
        DB::beginTransaction();

        Provider::create(['name' => 'ZZ Proveedor revertido a mano', 'user_id' => $this->dueno->id]);

        DB::rollBack();

        $this->assertSame(0, $this->filas()->count(), 'Rollback explícito: sin auditoría.');

        DB::beginTransaction();

        Provider::create(['name' => 'ZZ Proveedor confirmado', 'user_id' => $this->dueno->id]);

        DB::commit();

        $this->assertSame(1, $this->filas()->count(), 'Lo que se confirma queda auditado.');
    }
}
