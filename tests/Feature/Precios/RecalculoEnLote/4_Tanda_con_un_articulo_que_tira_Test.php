<?php

namespace Tests\Feature\Precios\RecalculoEnLote;

use App\Http\Controllers\Helpers\PriceUpdateRunHelper;
use App\Http\Controllers\Helpers\article\precios\RecalculoDePreciosEnLote;
use App\Http\Controllers\Helpers\import\article\motor\PreciosEnLote;
use App\Jobs\ProcessChunkSetFinalPrices;
use App\Models\User;
use App\Notifications\GlobalNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Una tanda del motor es atómica (misión recalculo-precios-motor-rapido, 28/9/2026): si un
 * artículo tira en el medio del cálculo, no se escribe NADA de esa tanda —ni los artículos que ya
 * se habían calculado antes que él—, el modo lote queda apagado (su estado es estático: si
 * quedara prendido, se lo llevaría el próximo job del mismo worker) y la excepción sube. Por la
 * cola, el lote falla y la corrida cierra en error con aviso al usuario.
 *
 * La falla se provoca con un impuesto sobre ventas del 100 % atado a UN artículo: se aplica por
 * división (precio / (1 - 100 %)) y la división por cero tira (ErrorException en PHP 7.4 con el
 * manejador de Laravel, DivisionByZeroError en 8). Es una configuración absurda a propósito: lo
 * que se prueba es qué pasa cuando un artículo cualquiera revienta, no por qué.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group recalculo-en-lote
 */
class Tanda_con_un_articulo_que_tira_Test extends RecalculoEnLoteTestCase
{
    /** @var \App\Models\User */
    protected $dueno;

    /** @var int[] */
    protected $ids = [];

    /** @var int */
    protected $el_que_tira;

    /** @var int */
    protected $marca;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = $this->crear_dueno(['listas_de_precio' => 1]);

        $this->crear_lista($this->dueno, 'Lista', 30, 1);

        $impuesto_roto = $this->impuesto_sobre_ventas($this->dueno, 100, false);

        for ($i = 0; $i < 5; $i++) {
            $this->ids[] = $this->crear_articulo($this->dueno, ['cost' => 100 + $i * 10])->id;
        }

        /*
         * El segundo, con margen propio y un precio manual viejo: el cálculo le borra el precio
         * con un save() inmediato, aun en modo lote (ArticleHelper::setFinalPrice(), la rama del
         * `price = null`). Va ANTES del que tira, así que ese save() ya ocurrió cuando la tanda
         * revienta: es lo que prueba que el cálculo corre adentro de la transacción de la tanda.
         */
        DB::table('articles')->where('id', $this->ids[1])->update(['percentage_gain' => 20, 'price' => 999]);

        /* El del medio: los dos anteriores ya quedaron calculados y registrados cuando revienta. */
        $this->el_que_tira = $this->ids[2];

        DB::table('article_sale_tax')->insert(['article_id' => $this->el_que_tira, 'sale_tax_id' => $impuesto_roto->id]);

        $this->pisar_precio_final($this->ids, 1);

        $this->marca = (int) DB::table('price_changes')->max('id');
    }

    /**
     * El motor solo: tira, no escribe nada de la tanda y deja el modo lote apagado.
     *
     * @return void
     */
    public function test_el_motor_no_escribe_nada_de_la_tanda_y_deja_el_modo_lote_apagado()
    {
        $antes = $this->estado();

        $tiro = null;

        try {
            RecalculoDePreciosEnLote::recalcular($this->ids, User::find($this->dueno->id));
        } catch (\Throwable $e) {
            $tiro = $e;
        }

        $this->assertNotNull($tiro, 'El artículo con el impuesto del 100 % tenía que tirar.');

        $this->assertFalse(PreciosEnLote::esta_activo(), 'El modo lote quedó prendido después de la excepción.');

        $pendientes = PreciosEnLote::pendientes();
        $this->assertSame(0, $pendientes['pares'] + $pendientes['cambios'] + $pendientes['monedas'], 'Quedó algo registrado en PreciosEnLote.');

        $this->assertEquals($antes, $this->estado(), 'La tanda que tiró escribió algo: tenía que no escribir nada.');
    }

    /**
     * Por la cola: el lote falla, su failed() cierra la corrida en error y avisa, y el lote no
     * cuenta como procesado. Los artículos siguen como estaban.
     *
     * @return void
     */
    public function test_por_la_cola_el_lote_falla_y_la_corrida_cierra_en_error_con_aviso()
    {
        Notification::fake();

        $run = PriceUpdateRunHelper::abrir($this->dueno->id, 'proveedor');

        DB::table('price_update_runs')->where('id', $run->id)->update(['total_chunks' => 1, 'chunks_encolados' => 1]);

        $antes = $this->estado();

        $tiro = null;

        try {
            /* Cola sync: corre inline y, al fallar, Laravel llama a failed() antes de relanzar. */
            dispatch(new ProcessChunkSetFinalPrices($this->ids, $this->dueno->id, $run->id));
        } catch (\Throwable $e) {
            $tiro = $e;
        }

        $this->assertNotNull($tiro, 'El lote tenía que fallar.');

        $this->assertFalse(PreciosEnLote::esta_activo(), 'El modo lote quedó prendido después del lote que falló.');

        $this->assertEquals($antes, $this->estado(), 'El lote que falló escribió algo.');

        $run->refresh();

        $this->assertSame('error', $run->status, 'La corrida tenía que cerrar en error.');
        $this->assertNotEmpty($run->error_detalle);
        $this->assertSame(0, (int) $run->processed_chunks, 'Un lote que falló no cuenta como procesado.');
        $this->assertSame(0, DB::table('price_update_run_articles')->where('price_update_run_id', $run->id)->count());

        Notification::assertSentTo(
            User::find($this->dueno->id),
            GlobalNotification::class,
            function ($notification) {
                return $notification->message_text === 'Error al actualizar Precios';
            }
        );
    }

    /**
     * Sin el artículo roto, la misma tanda sí escribe: la guarda de que el escenario no es mudo.
     *
     * @return void
     */
    public function test_sin_el_articulo_roto_la_misma_tanda_escribe()
    {
        $sanos = array_values(array_diff($this->ids, [$this->el_que_tira]));

        $resultado = RecalculoDePreciosEnLote::recalcular($sanos, User::find($this->dueno->id));

        $this->assertCount(4, $resultado['cambiaron']);
        $this->assertSame(4, DB::table('price_changes')->where('id', '>', $this->marca)->count());
        $this->assertNull(DB::table('articles')->where('id', $this->ids[1])->value('price'), 'El precio manual viejo del artículo con margen se tenía que borrar.');
    }

    /**
     * Una tanda que falla al ESCRIBIR (no al calcular) tampoco deja nada a medias: el UPDATE de
     * los artículos y los pivots ya se ejecutaron cuando el volcado de las monedas revienta, y la
     * transacción de la tanda los revierte.
     *
     * La falla: una entrada por moneda con el precio fijado a mano sobre un costo mínimo da un
     * porcentaje que no entra en article_price_type_monedas.percentage (DECIMAL(8,2)); en modo
     * estricto MySQL lo rechaza. Hoy, por artículo, reventaba igual en el save() de esa entrada.
     *
     * @return void
     */
    public function test_una_tanda_que_falla_al_escribir_no_deja_nada_a_medias()
    {
        $dueno = $this->crear_dueno(['listas_de_precio' => 1], ['ventas_en_dolares']);

        $lista = $this->crear_lista($dueno, 'Pesos', 30, 1);

        $sano = $this->crear_articulo($dueno, ['cost' => 100]);
        $roto = $this->crear_articulo($dueno, ['cost' => 0.01]);

        foreach ([$sano, $roto] as $article) {
            $article->price_types()->attach($lista->id, ['percentage' => 30, 'final_price' => 1]);
        }

        \App\Models\ArticlePriceTypeMoneda::create([
            'article_id' => $sano->id, 'price_type_id' => $lista->id, 'moneda_id' => self::ARS,
            'percentage' => 30, 'final_price' => 1, 'setear_precio_final' => 0, 'cotizar_desde_otra_moneda' => 0,
        ]);

        \App\Models\ArticlePriceTypeMoneda::create([
            'article_id' => $roto->id, 'price_type_id' => $lista->id, 'moneda_id' => self::ARS,
            'percentage' => 0, 'final_price' => 99999999, 'setear_precio_final' => 1, 'cotizar_desde_otra_moneda' => 0,
        ]);

        $ids = [$sano->id, $roto->id];

        $this->pisar_precio_final($ids, 1);

        $foto_antes = $this->foto($ids, $this->marca);

        $tiro = null;

        try {
            RecalculoDePreciosEnLote::recalcular($ids, User::find($dueno->id));
        } catch (\Throwable $e) {
            $tiro = $e;
        }

        $this->assertNotNull($tiro, 'El porcentaje fuera de rango tenía que hacer fallar la escritura.');
        $this->assertStringContainsStringIgnoringCase('percentage', $tiro->getMessage(), 'La falla tenía que ser la de la columna de monedas: ' . $tiro->getMessage());

        $this->assertFalse(PreciosEnLote::esta_activo());

        $this->assertEquals($foto_antes, $this->foto($ids, $this->marca), 'La tanda que falló al escribir dejó artículos, pivots o cambios de precio a medio escribir.');
    }

    /**
     * Lo que la tanda podría haber escrito: los artículos (todas las columnas que el cálculo toca),
     * sus pivots de listas y los price_changes nuevos.
     *
     * @return array
     */
    protected function estado()
    {
        return [
            'articles' => DB::table('articles')
                            ->whereIn('id', $this->ids)
                            ->orderBy('id')
                            ->get(['id', 'cost', 'costo_real', 'price', 'final_price', 'previus_final_price', 'final_price_updated_at', 'base_margen', 'updated_at'])
                            ->map(function ($fila) { return (array) $fila; })
                            ->all(),
            'pivots'   => DB::table('article_price_type')
                            ->whereIn('article_id', $this->ids)
                            ->orderBy('article_id')
                            ->orderBy('price_type_id')
                            ->get(['article_id', 'price_type_id', 'percentage', 'final_price', 'precio_luego_de_recargos', 'monto_ganancia'])
                            ->map(function ($fila) { return (array) $fila; })
                            ->all(),
            'cambios'  => DB::table('price_changes')->where('id', '>', $this->marca)->count(),
        ];
    }
}
