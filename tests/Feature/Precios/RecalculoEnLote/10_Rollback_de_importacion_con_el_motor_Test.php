<?php

namespace Tests\Feature\Precios\RecalculoEnLote;

use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Http\Controllers\Helpers\import\article\motor\PreciosEnLote;
use App\Jobs\RollbackArticleImportHistory;
use App\Models\PriceType;
use App\Models\PriceTypeSurchage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Import\ImportTestCase;

/**
 * La reversión de una importación con el motor en lote deja la base EXACTAMENTE igual que con el
 * recálculo por artículo de antes (misión recalculo-precios-motor-rapido, 28/9/2026).
 *
 * RollbackArticleImportHistory::recalcular_precios_derivados() pasó de setFinalPrice(..., true,
 * ...) por artículo a RecalculoDePreciosEnLote::recalcular(). Acá se importa el fixture de
 * reversión de verdad (tenant 900, como tests/Import/RollbackTest) y se revierte la misma
 * importación dos veces sobre la misma base, cada una en un savepoint que se revierte:
 *
 *  - con RollbackConElRecalculoDeHoy (el job con el recálculo de develop, tal cual);
 *  - con el job real (el motor).
 *
 * y se comparan, campo por campo y con el reloj congelado, todos los artículos del tenant
 * (incluidos los creados por la importación, que el rollback borra), sus pivots de listas, sus
 * entradas por moneda y los price_changes de la reversión (FotoDePrecios).
 *
 * Extiende la base de los tests de importación y no la de esta carpeta: necesita su tenant, su
 * sembrado y su importar().
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group recalculo-en-lote
 */
class Rollback_de_importacion_con_el_motor_Test extends ImportTestCase
{
    use FotoDePrecios;

    /** El fixture de tests/Import/RollbackTest. */
    const ARCHIVO = '05_rollback.xlsx';

    /** Instante congelado de las dos reversiones. */
    const AHORA = '2030-01-15 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->limpiar_estado_del_proceso();

        Notification::fake();
    }

    protected function tearDown(): void
    {
        $this->limpiar_estado_del_proceso();

        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * El tenant tal cual lo deja la base de tests de importación (sin listas de precio).
     *
     * @return void
     */
    public function test_el_rollback_con_el_motor_deja_la_misma_base_que_por_articulo()
    {
        $r = $this->comparar_rollback();

        $this->assertGreaterThan(0, count($r['foto_hoy']['cambios']), 'La reversión tenía que devolver precios (price_changes): si no, la comparación no prueba nada.');

        /* Referencia: el autor de esos price_changes es el dueño que pidió la reversión, no el empleado de la sesión. */
        foreach ($r['foto_hoy']['cambios'] as $cambios) {
            foreach ($cambios as $cambio) {
                $this->assertSame((int) $this->tenant->id, (int) $cambio['employee_id']);
            }
        }
    }

    /**
     * El mismo rollback con el tenant trabajando con dos listas de precio (una con recargo): la
     * reversión también recalcula el precio de cada lista.
     *
     * @return void
     */
    public function test_el_rollback_con_listas_de_precio_deja_la_misma_base_que_por_articulo()
    {
        $this->tenant->listas_de_precio = 1;
        $this->tenant->save();
        $this->actingAs($this->tenant, 'web');

        $mayorista = PriceType::create(['name' => 'zz Mayorista rollback', 'user_id' => $this->tenant->id, 'percentage' => 25, 'position' => 1]);
        $minorista = PriceType::create(['name' => 'zz Minorista rollback', 'user_id' => $this->tenant->id, 'percentage' => 45, 'position' => 2]);

        PriceTypeSurchage::create(['name' => 'zz Recargo rollback', 'price_type_id' => $minorista->id, 'percentage' => 3, 'position' => 1]);

        $r = $this->comparar_rollback();

        $this->assertGreaterThan(0, count($r['foto_hoy']['pivots']), 'Los artículos restaurados tenían que quedar con sus listas.');

        $con_listas = 0;
        foreach ($r['foto_hoy']['cambios'] as $cambios) {
            foreach ($cambios as $cambio) {
                if (count($cambio['listas']) > 0) {
                    $con_listas++;
                }
            }
        }

        $this->assertGreaterThan(0, $con_listas, 'Los price_changes de la reversión tenían que llevar el precio de cada lista.');
    }

    /* ------------------------------------------------------------------------------------------
     * Ayudantes
     * ---------------------------------------------------------------------------------------- */

    /**
     * Importa el fixture y revierte la importación dos veces sobre la misma base (camino de hoy y
     * motor, cada una en un savepoint), y afirma que dejan exactamente lo mismo.
     *
     * @return array ['foto_hoy' => array, 'foto_motor' => array]
     */
    protected function comparar_rollback()
    {
        $import = $this->importar(self::ARCHIVO, ['provider_id' => null]);

        $ids = DB::table('articles')
                    ->where('user_id', $this->tenant->id)
                    ->orderBy('id')
                    ->pluck('id')
                    ->map(function ($id) { return (int) $id; })
                    ->all();

        $marca = (int) DB::table('price_changes')->max('id');

        $foto_despues_de_importar = $this->foto($ids, $marca);

        /*
         * La reversión la pide un EMPLEADO del tenant, como pasa en producción: el controlador le
         * pasa al job el dueño (owner_user_id) y la sesión es la del empleado. Así el autor de los
         * price_changes (owner_user_id, en los dos caminos) no coincide con el que resolvería la
         * sesión (UserHelper::userId(false)), y un motor que no recibiera owner_user_id daría otra
         * foto.
         */
        $empleado = User::create([
            'name'     => 'zz Empleado rollback',
            'email'    => 'rollback-empleado-' . uniqid('', true) . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->tenant->id,
        ]);

        $this->actingAs(User::find($empleado->id), 'web');

        /* Camino de hoy. */
        DB::beginTransaction();
        Carbon::setTestNow(self::AHORA);

        (new RollbackConElRecalculoDeHoy($import->id, $this->tenant->id, $this->tenant->id))->handle();

        $foto_hoy = $this->foto($ids, $marca);

        Carbon::setTestNow();
        DB::rollBack();

        $this->limpiar_estado_del_proceso();

        /* Motor. */
        DB::beginTransaction();
        Carbon::setTestNow(self::AHORA);

        (new RollbackArticleImportHistory($import->id, $this->tenant->id, $this->tenant->id))->handle();

        $foto_motor = $this->foto($ids, $marca);

        Carbon::setTestNow();
        DB::rollBack();

        $this->assertFalse(PreciosEnLote::esta_activo(), 'El rollback dejó el modo lote encendido.');

        /* Guarda: la reversión tiene que haber cambiado algo, o comparar no prueba nada. */
        $this->assertNotEquals($foto_despues_de_importar['articles'], $foto_hoy['articles'], 'La reversión no cambió ningún artículo: el test no prueba nada.');

        $this->assertEquals(
            $foto_hoy,
            $foto_motor,
            'El rollback con el motor dejó en la base algo distinto del rollback con el recálculo por artículo.'
        );

        return [
            'foto_hoy'   => $foto_hoy,
            'foto_motor' => $foto_motor,
        ];
    }

    /**
     * Lo que vive en estáticas del proceso y no en la base (ver RecalculoEnLoteTestCase).
     *
     * @return void
     */
    protected function limpiar_estado_del_proceso()
    {
        PreciosEnLote::descartar();
        PreciosEnLote::deshabilitar(false);

        ArticlePricesHelper::$sale_taxes_cache = [];
        ArticlePricesHelper::$payment_method_layer3_cache = [];
    }
}
