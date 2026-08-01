<?php

namespace Tests\Import;

use App\Models\Article;
use Tests\Import\Helpers\ArticleSnapshot;

/**
 * Reversión de una importación.
 *
 * La prueba central no verifica campos sueltos: toma una foto completa del tenant
 * antes de importar y exige que después del rollback la foto sea IDÉNTICA. Es la
 * única forma de cubrir las relaciones (depósitos, listas de precio, descuentos,
 * recargos, pivot de proveedor), que es justo donde el rollback falló histórica-
 * mente.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe, argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class RollbackTest extends ImportTestCase
{
    const ARCHIVO = '05_rollback.xlsx';

    /**
     * @return void
     */
    public function test_el_rollback_deja_todo_exactamente_como_estaba()
    {
        $antes = ArticleSnapshot::tomar($this->tenant->id);

        $import = $this->importar(self::ARCHIVO, ['provider_id' => null]);

        $durante = ArticleSnapshot::tomar($this->tenant->id);

        /*
         * Guarda imprescindible: un test de rollback que pasa porque la importación
         * no cambió nada no prueba absolutamente nada.
         */
        $this->assertNotEquals(
            $antes,
            $durante,
            'La importación no modificó nada, así que el test de rollback no prueba nada.'
        );

        $this->revertir($import);

        $despues = ArticleSnapshot::tomar($this->tenant->id);

        $this->assertEquals(
            $antes,
            $despues,
            "El rollback no restauró el estado original:\n" . ArticleSnapshot::diferencias($antes, $despues)
        );
    }

    /**
     * Los artículos creados por la importación se borran, no quedan huérfanos.
     *
     * @return void
     */
    public function test_el_rollback_borra_los_articulos_creados()
    {
        $import = $this->importar(self::ARCHIVO, ['provider_id' => null]);

        $this->assertCount(2, $this->articulos_creados(), 'PC-RB-1 y PC-RB-2');

        $this->revertir($import);

        $this->assertCount(0, $this->articulos_creados());

        $this->assertSame(
            0,
            Article::where('user_id', $this->tenant->id)
                    ->whereIn('provider_code', ['PC-RB-1', 'PC-RB-2'])
                    ->withTrashed()
                    ->whereNull('deleted_at')
                    ->count()
        );
    }

    /**
     * Los artículos que la importación NO tocó tampoco los puede tocar el rollback.
     *
     * @return void
     */
    public function test_el_rollback_no_toca_articulos_ajenos_a_la_importacion()
    {
        $import = $this->importar(self::ARCHIVO, ['provider_id' => null]);

        $this->revertir($import);

        /* A7 no aparece en 05_rollback.xlsx. */
        $this->assertDecimal(700, $this->recargar('A7')->cost);
        $this->assertDecimal(70,  $this->recargar('A7')->stock);
    }

    /**
     * Revertir dos veces la misma importación no puede romper ni duplicar el efecto.
     *
     * @return void
     */
    public function test_revertir_dos_veces_es_idempotente()
    {
        $antes = ArticleSnapshot::tomar($this->tenant->id);

        $import = $this->importar(self::ARCHIVO, ['provider_id' => null]);

        $this->revertir($import);
        $this->revertir($import);

        $this->assertEquals(
            $antes,
            ArticleSnapshot::tomar($this->tenant->id),
            'El segundo rollback alteró el estado ya restaurado.'
        );
    }

    /**
     * Con QUEUE_CONNECTION=sync el job de rollback corre inline dentro del request.
     *
     * @param  \App\Models\ImportHistory $import
     * @return void
     */
    protected function revertir($import)
    {
        $this->postJson('/api/import-history/rollback/' . $import->id)->assertStatus(202);
    }
}
