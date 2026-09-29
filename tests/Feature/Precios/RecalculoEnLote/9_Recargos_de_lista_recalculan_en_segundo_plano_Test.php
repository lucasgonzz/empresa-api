<?php

namespace Tests\Feature\Precios\RecalculoEnLote;

use App\Jobs\ProcessChunkSetFinalPrices;
use App\Jobs\ProcessSetFinalPrices;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Guardar una lista de precios con recargos recalcula en segundo plano, no adentro del request
 * (misión recalculo-precios-motor-rapido, 28/9/2026).
 *
 * PriceTypeController::update() llama a PriceTypeHelper::check_recargos(), que cuando la lista
 * tiene recargos y su fila no se tocó en el último minuto (la condición de siempre, que no cambió)
 * corría ArticleHelper::setArticlesFinalPrice(): el catálogo ENTERO del dueño, artículo por
 * artículo, sincrónico en el request. Ahora encola un ProcessSetFinalPrices con el dueño, sin
 * alcance (todo el catálogo) y origen `tipo_de_precio`.
 *
 * Se prueba por el request real (PUT api/price-type/{id}) con la cola falsa: si algo recalculara
 * adentro del request, los precios pisados a 1 cambiarían y aparecerían price_changes.
 *
 * Cómo se cumple la condición por el request: el PUT manda la lista EXACTAMENTE como está en la
 * base (el porcentaje como el texto que devuelve MySQL), así save() no encuentra nada sucio y no
 * le toca updated_at, que se dejó dos horas atrás.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group recalculo-en-lote
 */
class Recargos_de_lista_recalculan_en_segundo_plano_Test extends RecalculoEnLoteTestCase
{
    /** @var \App\Models\User */
    protected $dueno;

    /** @var int[] */
    protected $ids = [];

    /** @var int */
    protected $marca;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = $this->crear_dueno(['listas_de_precio' => 1]);

        for ($i = 0; $i < 3; $i++) {
            $this->ids[] = $this->crear_articulo($this->dueno, ['cost' => 100 + $i * 7])->id;
        }

        $this->pisar_precio_final($this->ids, 1);

        $this->marca = (int) DB::table('price_changes')->max('id');
    }

    /**
     * Con recargos y la fila sin tocar: el request no recalcula nada y deja encolado el recálculo
     * de todo el catálogo del dueño, con origen `tipo_de_precio` y el nombre de la lista.
     *
     * @return void
     */
    public function test_con_la_condicion_cumplida_se_encola_el_recalculo_y_el_request_no_recalcula_nada()
    {
        $lista = $this->crear_lista($this->dueno, 'Mayorista con recargos', 30, 1, [['percentage' => 5]]);

        $this->envejecer($lista);

        Queue::fake();

        $this->putJson('api/price-type/' . $lista->id, $this->payload_sin_cambios($lista))->assertStatus(200);

        $this->assertNadaRecalculadoEnElRequest();

        $dueno_id = (int) $this->dueno->id;

        Queue::assertPushed(ProcessSetFinalPrices::class, function ($job) use ($dueno_id, $lista) {
            return (int) $job->user_id === $dueno_id
                && is_null($job->from_model_id)
                && is_null($job->model_id)
                && $job->from_dolar === false
                && $job->origen === 'tipo_de_precio'
                && $job->origen_detalle === $lista->name;
        });

        Queue::assertPushed(ProcessSetFinalPrices::class, 1);
    }

    /**
     * La fila de la lista cambió en este mismo guardado (updated_at = ahora): la condición de
     * siempre no se cumple y no se encola nada.
     *
     * @return void
     */
    public function test_si_la_lista_se_acaba_de_guardar_no_se_encola_nada()
    {
        $lista = $this->crear_lista($this->dueno, 'Mayorista con recargos', 30, 1, [['percentage' => 5]]);

        $this->envejecer($lista);

        Queue::fake();

        $payload = $this->payload_sin_cambios($lista);
        $payload['name'] = 'zz Mayorista renombrada';

        $this->putJson('api/price-type/' . $lista->id, $payload)->assertStatus(200);

        $this->assertNadaRecalculadoEnElRequest();

        Queue::assertNotPushed(ProcessSetFinalPrices::class);
        Queue::assertNotPushed(ProcessChunkSetFinalPrices::class);
    }

    /**
     * Una lista sin recargos no cumple la condición aunque su fila sea vieja: nada.
     *
     * @return void
     */
    public function test_una_lista_sin_recargos_no_encola_nada()
    {
        $lista = $this->crear_lista($this->dueno, 'Sin recargos', 30, 1);

        $this->envejecer($lista);

        Queue::fake();

        $this->putJson('api/price-type/' . $lista->id, $this->payload_sin_cambios($lista))->assertStatus(200);

        $this->assertNadaRecalculadoEnElRequest();

        Queue::assertNotPushed(ProcessSetFinalPrices::class);
    }

    /* ------------------------------------------------------------------------------------------
     * Ayudantes
     * ---------------------------------------------------------------------------------------- */

    /**
     * Deja la fila de la lista con updated_at de hace dos horas (la condición de check_recargos).
     *
     * @param  \App\Models\PriceType $lista
     * @return void
     */
    protected function envejecer($lista)
    {
        DB::table('price_types')->where('id', $lista->id)->update(['updated_at' => now()->subHours(2)]);
    }

    /**
     * La lista tal como está en la base, con los valores crudos (el porcentaje como texto con
     * sus dos decimales): así PriceTypeController::update() no ensucia nada y save() no toca
     * updated_at.
     *
     * @param  \App\Models\PriceType $lista
     * @return array
     */
    protected function payload_sin_cambios($lista)
    {
        $fila = DB::table('price_types')->where('id', $lista->id)->first();

        return [
            'name'                                     => $fila->name,
            'percentage'                               => $fila->percentage,
            'update_existing_articles_percentage_mode' => $fila->update_existing_articles_percentage_mode,
            'position'                                 => $fila->position,
            'ocultar_al_publico'                       => $fila->ocultar_al_publico,
            'incluir_en_lista_de_precios_de_excel'     => $fila->incluir_en_lista_de_precios_de_excel,
            'setear_precio_final'                      => $fila->setear_precio_final,
            'se_usa_en_tienda_nube'                    => $fila->se_usa_en_tienda_nube,
            'se_usa_en_ml'                             => $fila->se_usa_en_ml,
            'categories'                               => [],
            'sub_categories'                           => [],
        ];
    }

    /**
     * Ningún artículo del dueño se recalculó adentro del request: los precios siguen pisados en 1
     * y no hay price_changes nuevos.
     *
     * @return void
     */
    protected function assertNadaRecalculadoEnElRequest()
    {
        foreach ($this->ids as $id) {
            $this->assertEquals(1, (float) DB::table('articles')->where('id', $id)->value('final_price'), 'El request recalculó el precio del artículo ' . $id . ' sincrónico.');
        }

        $this->assertSame(0, DB::table('price_changes')->where('id', '>', $this->marca)->whereIn('article_id', $this->ids)->count(), 'El request registró cambios de precio: recalculó sincrónico.');
    }
}
