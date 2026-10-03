<?php

namespace Tests\Feature\Precios\SincronizarMargenDeLista;

use App\Jobs\ProcessChunkSetFinalPrices;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Precios\RecalculoEnLote\RecalculoEnLoteTestCase;

/**
 * Base de los tests del botón "Sincronizar artículos" del margen de una lista de precios (misión
 * sincronizar-margen-lista-precios, 1/10/2026).
 *
 * Reusa el armado de RecalculoEnLoteTestCase (un comercio propio por test, listas, artículos
 * releídos de la base, DatabaseTransactions) y agrega el ESCENARIO MEZCLADO que piden los tests:
 * una lista "Mayorista" con margen 30 atada a artículos en cada situación posible, para que cada
 * alcance elija distinto y se vea qué queda intacto.
 *
 *   artículo            pivot de Mayorista                     entra en
 *   ------------------  -------------------------------------  ----------------------------------
 *   en_30               30.00, setear_precio_final 0           coinciden, todos
 *   en_null             NULL (usa el margen de la lista)       coinciden, todos
 *   en_30_setear_null   30.00, setear_precio_final NULL        coinciden, todos
 *   en_25               25.00 (margen propio)                  todos
 *   fijado_en_30        30.00, setear_precio_final 1, $500     solo todos CON el tilde
 *   fijado_en_40        40.00, setear_precio_final 1, $600     solo todos CON el tilde
 *   borrado             30.00 (artículo con soft delete)       nunca
 *   solo_en_la_otra     sin Mayorista; "Otra" en 30.00         nunca
 *   de_otro_dueno       lista de OTRO comercio, 30.00          nunca
 *
 * `en_30` además está atado a "Otra" con 30.00: esa fila no se puede tocar.
 *
 * Números esperados del preview de Mayorista: porcentaje_actual "30.00", total 6, coinciden 3,
 * con precio fijado a mano 2.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
abstract class SincronizarMargenTestCase extends RecalculoEnLoteTestCase
{
    /** @var \App\Models\User */
    protected $dueno;

    /** @var \App\Models\User */
    protected $otro_dueno;

    /** @var \App\Models\PriceType La lista que se sincroniza. */
    protected $lista;

    /** @var \App\Models\PriceType Otra lista del mismo dueño. */
    protected $otra;

    /** @var \App\Models\PriceType Una lista de otro comercio. */
    protected $lista_ajena;

    /** @var array<string, int> Ids de artículo del escenario, por nombre. */
    protected $art = [];

    /**
     * Arma el escenario mezclado (ver el docblock de la clase). Deja logueado al dueño de la
     * lista y la cola falsa prendida.
     *
     * @param  float|null $porcentaje_de_la_lista  Margen guardado de Mayorista.
     * @return void
     */
    protected function armar_escenario($porcentaje_de_la_lista = 30)
    {
        // El otro comercio primero: crear_dueno() deja logueado al último que crea.
        $this->otro_dueno  = $this->crear_dueno(['listas_de_precio' => 1]);
        $this->lista_ajena = $this->crear_lista($this->otro_dueno, 'Ajena', 30, 1);
        $de_otro_dueno     = $this->crear_articulo($this->otro_dueno, ['cost' => 10]);
        $this->atar($de_otro_dueno->id, $this->lista_ajena->id, '30.00');

        $this->dueno = $this->crear_dueno(['listas_de_precio' => 1]);
        $this->lista = $this->crear_lista($this->dueno, 'Mayorista', $porcentaje_de_la_lista, 1);
        $this->otra  = $this->crear_lista($this->dueno, 'Otra', 30, 2);

        $nombres = ['en_30', 'en_null', 'en_30_setear_null', 'en_25', 'fijado_en_30', 'fijado_en_40', 'borrado', 'solo_en_la_otra'];

        foreach ($nombres as $nombre) {
            $this->art[$nombre] = $this->crear_articulo($this->dueno, ['cost' => 10])->id;
        }

        $this->art['de_otro_dueno'] = $de_otro_dueno->id;

        $this->atar($this->art['en_30'], $this->lista->id, '30.00', 0);
        $this->atar($this->art['en_30'], $this->otra->id, '30.00', 0);
        $this->atar($this->art['en_null'], $this->lista->id, null, 0);
        $this->atar($this->art['en_30_setear_null'], $this->lista->id, '30.00', null);
        $this->atar($this->art['en_25'], $this->lista->id, '25.00', 0);
        $this->atar($this->art['fijado_en_30'], $this->lista->id, '30.00', 1, 500);
        $this->atar($this->art['fijado_en_40'], $this->lista->id, '40.00', 1, 600);
        $this->atar($this->art['borrado'], $this->lista->id, '30.00', 0);
        $this->atar($this->art['solo_en_la_otra'], $this->otra->id, '30.00', 0);

        \App\Models\Article::find($this->art['borrado'])->delete();

        Queue::fake();
    }

    /**
     * Una fila del pivot article_price_type.
     *
     * @param  int         $article_id
     * @param  int         $price_type_id
     * @param  string|null $porcentaje
     * @param  int|null    $setear_precio_final
     * @param  float|null  $final_price
     * @return void
     */
    protected function atar($article_id, $price_type_id, $porcentaje, $setear_precio_final = 0, $final_price = null)
    {
        DB::table('article_price_type')->insert([
            'article_id'          => $article_id,
            'price_type_id'       => $price_type_id,
            'percentage'          => $porcentaje,
            'setear_precio_final' => $setear_precio_final,
            'final_price'         => $final_price,
        ]);
    }

    /**
     * La fila del pivot de un artículo del escenario en una lista: [percentage, setear_precio_final].
     *
     * @param  string $nombre         Nombre del artículo en el escenario.
     * @param  int    $price_type_id
     * @return array
     */
    protected function pivot($nombre, $price_type_id)
    {
        $fila = DB::table('article_price_type')
                    ->where('article_id', $this->art[$nombre])
                    ->where('price_type_id', $price_type_id)
                    ->first();

        $this->assertNotNull($fila, 'El artículo ' . $nombre . ' no tiene la lista ' . $price_type_id . ' atada.');

        return [
            'percentage'          => $fila->percentage,
            'setear_precio_final' => is_null($fila->setear_precio_final) ? null : (int) $fila->setear_precio_final,
        ];
    }

    /**
     * Todas las filas del pivot de los artículos del escenario, con todas sus columnas, ordenadas.
     *
     * @return array
     */
    protected function foto_de_pivots()
    {
        return DB::table('article_price_type')
                    ->whereIn('article_id', array_values($this->art))
                    ->orderBy('id')
                    ->get()
                    ->map(function ($fila) { return (array) $fila; })
                    ->all();
    }

    /**
     * El payload del PUT tal como lo arma el formulario: la fila de la lista como está en la base
     * (igual que RecalculoEnLote/9) con los cambios encima.
     *
     * @param  \App\Models\PriceType $lista
     * @param  array                 $cambios
     * @return array
     */
    protected function payload($lista, array $cambios = [])
    {
        $fila = DB::table('price_types')->where('id', $lista->id)->first();

        return array_merge([
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
        ], $cambios);
    }

    /**
     * El preview por el request real.
     *
     * @param  int $price_type_id
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedir_preview($price_type_id)
    {
        return $this->getJson('api/price-type/' . $price_type_id . '/sincronizar-margen/preview');
    }

    /**
     * Los ids que quedaron encolados para recalcular (en los lotes de la cola falsa), ordenados.
     *
     * @return int[]
     */
    protected function ids_encolados()
    {
        $ids = [];

        foreach (Queue::pushed(ProcessChunkSetFinalPrices::class) as $chunk) {
            $propiedad = new \ReflectionProperty($chunk, 'article_ids');
            $propiedad->setAccessible(true);

            foreach ($propiedad->getValue($chunk) as $id) {
                $ids[] = (int) $id;
            }
        }

        sort($ids);

        return $ids;
    }

    /**
     * Ids de artículo del escenario por nombre, ordenados.
     *
     * @param  string[] $nombres
     * @return int[]
     */
    protected function ids_de(array $nombres)
    {
        $ids = [];

        foreach ($nombres as $nombre) {
            $ids[] = (int) $this->art[$nombre];
        }

        sort($ids);

        return $ids;
    }
}
