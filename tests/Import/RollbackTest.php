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
     * CORRECCIÓN DEL SETUP (tanda correctivos 2408, ítem 12). Este test estaba rojo de
     * baseline fallando en el conteo PREVIO al rollback (esperaba 2 creados y hay 3), o
     * sea que la aserción del rollback nunca llegaba a evaluarse — y de ahí la
     * discrepancia con la prueba manual de Lucas (24/8/2026), que confirmó que el
     * rollback SÍ borra los artículos creados.
     *
     * Los 3 creados son correctos: 05_rollback.xlsx incluye la fila PC-1200, cuyo
     * artículo en base (A12) tiene provider_code pero provider_id NULL, y el
     * comportamiento FIJADO del sistema para ese caso es "no matchea -> crea duplicado"
     * — está documentado y asertado por StockTest de esta misma suite (cabecera:
     * "F7 PC-1200  A12 no matchea (provider_id null en base) -> crea duplicado", sobre
     * 04_stock.xlsx, que trae la misma fila). El fixture 05 asumía que PC-1200
     * actualizaba al existente; en realidad crea el tercer artículo.
     *
     * Lo que NO se tocó es la aserción del comportamiento bajo prueba: después de
     * revertir tiene que quedar CERO creado vivo — y ahora se exige sobre los tres,
     * incluido el duplicado de PC-1200 (queda vivo únicamente el A12 original).
     *
     * @return void
     */
    public function test_el_rollback_borra_los_articulos_creados()
    {
        $import = $this->importar(self::ARCHIVO, ['provider_id' => null]);

        $this->assertCount(3, $this->articulos_creados(), 'PC-RB-1, PC-RB-2 y el duplicado de PC-1200');

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
         * Del duplicado de PC-1200 borrado tiene que sobrevivir exactamente UNO vivo con
         * ese provider_code: el A12 original del escenario sembrado.
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
