<?php

namespace Tests\Feature\ProcesosEnSegundoPlano;

use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\EmpresaTestCase;

/**
 * Misión gate-4-3-68-rojos (19/11/2026) — el aviso de un proceso sale DESPUÉS del commit.
 *
 * Contrato de fbc900da (`BackgroundProcessHelper::emitir`): si el proceso se registra o avanza adentro
 * de una transacción del código, el broadcast espera al commit; si esa transacción se revierte, no sale
 * (no se anuncia un proceso que no quedó). Sin transacción propia, sale en el acto.
 *
 * Con el trait `AfterCommitComoEnProduccion` la transacción base de la suite no cuenta, igual que en
 * producción (y que en Laravel ≥9). La versión con commit/rollback reales y cola database está en
 * `Tests\Feature\Listado\EfectosDeTandasTest`.
 *
 * @group procesos-en-segundo-plano
 */
class Aviso_despues_del_commit_Test extends EmpresaTestCase
{
    use \Tests\Support\AfterCommitComoEnProduccion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ejecutar_after_commit_como_en_produccion();
    }

    /**
     * Sin transacción propia el aviso sale en el acto.
     *
     * @test
     */
    public function sin_transaccion_propia_el_aviso_sale_en_el_acto()
    {
        Event::fake([BackgroundProcessUpdated::class]);

        BackgroundProcessHelper::iniciar((int) auth()->id(), 'actualizacion_masiva', 'zz aviso inmediato');

        Event::assertDispatchedTimes(BackgroundProcessUpdated::class, 1);
    }

    /**
     * Adentro de una transacción del código el aviso espera al commit y sale una sola vez.
     *
     * @test
     */
    public function adentro_de_una_transaccion_el_aviso_espera_al_commit()
    {
        Event::fake([BackgroundProcessUpdated::class]);

        DB::transaction(function () {
            BackgroundProcessHelper::iniciar((int) auth()->id(), 'actualizacion_masiva', 'zz aviso al commit');

            // Todavía no se confirmó: el proceso podría revertirse.
            Event::assertNotDispatched(BackgroundProcessUpdated::class);
        });

        Event::assertDispatchedTimes(BackgroundProcessUpdated::class, 1);
    }

    /**
     * Si la transacción del código se revierte, el aviso no sale nunca.
     *
     * @test
     */
    public function con_rollback_el_aviso_no_sale()
    {
        Event::fake([BackgroundProcessUpdated::class]);

        try {
            DB::transaction(function () {
                BackgroundProcessHelper::iniciar((int) auth()->id(), 'actualizacion_masiva', 'zz aviso revertido');
                throw new \RuntimeException('zz revertir');
            });
            $this->fail('La transacción tenía que revertirse.');
        } catch (\RuntimeException $e) {
            $this->assertSame('zz revertir', $e->getMessage());
        }

        Event::assertNotDispatched(BackgroundProcessUpdated::class);

        // Un commit posterior no "resucita" el aviso descartado.
        DB::transaction(function () {
        });
        Event::assertNotDispatched(BackgroundProcessUpdated::class);
    }
}
