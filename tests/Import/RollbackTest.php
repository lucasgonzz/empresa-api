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
     * 🔴 HISTORIA DE ESTE CONTEO, porque dos misiones llegaron a números distintos y hay
     * que saber cuál manda.
     *
     * El test estuvo rojo de baseline fallando en el conteo PREVIO al rollback, así que la
     * aserción del rollback nunca llegaba a evaluarse — de ahí la discrepancia con la
     * prueba manual de Lucas (24/8/2026), que confirmó que el rollback SÍ borra los
     * creados. Las dos misiones que lo investigaron coincidieron en el mecanismo: el
     * tercer artículo era el duplicado de PC-1200, porque A12 tiene `provider_code` pero
     * `provider_id` NULL y el índice sólo indexaba cuando estaban los dos campos.
     *
     * Donde se separaron fue en qué hacer con eso:
     *
     *  - La tanda de correctivos 2408 (ítem 12) lo tomó como comportamiento FIJADO
     *    ("no matchea -> crea duplicado") y corrigió el setup a 3.
     *  - Esta misión lo tomó como un defecto y arregló la causa: un artículo con código de
     *    proveedor pero sin proveedor asignado AHORA SÍ matchea, cuando la importación no
     *    eligió proveedor. **Decisión explícita de Lucas del 24/8/2026.**
     *
     * Manda la segunda, y por eso el conteo vuelve a 2. El duplicado ya no se crea: la
     * fila PC-1200 actualiza al A12 existente, que es lo que el fixture asumía desde el
     * principio. Ver `ArticleIndexCache::build()`/`update()` y
     * `CodigosDeProveedorTest::test_articulo_con_provider_code_sin_proveedor_matchea`.
     *
     * ⚠️ Si alguna vez este conteo vuelve a dar 3, NO es que el fixture esté mal: es que
     * se revirtió el indexado sin proveedor. Buscá ahí antes de tocar el número.
     *
     * La aserción del comportamiento bajo prueba no cambió: después de revertir tiene que
     * quedar CERO creado vivo, y sobrevivir únicamente el A12 original con PC-1200.
     *
     * @return void
     */
    public function test_el_rollback_borra_los_articulos_creados()
    {
        $import = $this->importar(self::ARCHIVO, ['provider_id' => null]);

        $this->assertCount(2, $this->articulos_creados(), 'PC-RB-1 y PC-RB-2 (PC-1200 actualiza al A12 existente, no crea duplicado)');

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

        /*
         * Tiene que sobrevivir exactamente UNO vivo con ese provider_code: el A12 original
         * del escenario sembrado, que la importación actualizó y el rollback restauró.
         *
         * Esta aserción la agregó la tanda de correctivos 2408 para cubrir el duplicado que
         * en ese momento se creaba. Se conserva aunque el duplicado ya no exista: ahora
         * vigila lo contrario —que el rollback no borre de más al artículo que actualizó—,
         * que es un camino que antes no se ejercitaba nunca.
         */
        $this->assertSame(
            1,
            Article::where('user_id', $this->tenant->id)
                    ->where('provider_code', 'PC-1200')
                    ->withTrashed()
                    ->whereNull('deleted_at')
                    ->count(),
            'Tras el rollback tiene que quedar vivo solo el A12 original con PC-1200.'
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
     * Hasta acá la garantía era "el segundo rollback no rompe nada" (idempotencia).
     * Con el bloqueo del grupo 305 la garantía es más fuerte: directamente no hay
     * segundo rollback, el endpoint lo rechaza con 409. Se renombra a propósito
     * (grupo 305, prompt 02): bloquear es más fuerte que hacer idempotente.
     *
     * @return void
     */
    public function test_revertir_dos_veces_no_duplica_el_efecto()
    {
        $antes = ArticleSnapshot::tomar($this->tenant->id);

        $import = $this->importar(self::ARCHIVO, ['provider_id' => null]);

        $this->revertir($import);
        $this->revertir($import, 409);

        $this->assertEquals(
            $antes,
            ArticleSnapshot::tomar($this->tenant->id),
            'El segundo rollback (rechazado) alteró el estado ya restaurado.'
        );
    }

    /**
     * Después de un rollback exitoso, la fila queda marcada como revertida y
     * puede_revertirse() ya no lo permite (grupo 305, prompt 02).
     *
     * @return void
     */
    public function test_el_rollback_deja_la_importacion_marcada_como_revertida()
    {
        $import = $this->importar(self::ARCHIVO, ['provider_id' => null]);

        $this->revertir($import);

        $import->refresh();

        $this->assertSame('revertida', $import->rollback_status);
        $this->assertNotNull($import->rolled_back_at);
        $this->assertFalse($import->puede_revertirse());
    }

    /**
     * El index() del historial informa can_revert por fila: true antes del
     * rollback, false para una importación ya revertida (grupo 305, prompt 02).
     *
     * @return void
     */
    public function test_el_historial_informa_que_una_importacion_revertida_no_se_puede_revertir()
    {
        $import = $this->importar(self::ARCHIVO, ['provider_id' => null]);

        $antes = $this->getJson('/api/import-history/article')->assertStatus(200)->json('models');
        $fila_antes = collect($antes)->firstWhere('id', $import->id);
        $this->assertTrue($fila_antes['can_revert']);

        $this->revertir($import);

        $despues = $this->getJson('/api/import-history/article')->assertStatus(200)->json('models');
        $fila_despues = collect($despues)->firstWhere('id', $import->id);
        $this->assertFalse($fila_despues['can_revert']);
    }

    /**
     * Con QUEUE_CONNECTION=sync el job de rollback corre inline dentro del request.
     *
     * @param  \App\Models\ImportHistory $import
     * @param  int                       $status  Código HTTP esperado (grupo 305, prompt 02:
     *                                             los tests que esperan el bloqueo piden 409).
     * @return \Illuminate\Testing\TestResponse
     */
    protected function revertir($import, $status = 202)
    {
        return $this->postJson('/api/import-history/rollback/' . $import->id)->assertStatus($status);
    }
}
