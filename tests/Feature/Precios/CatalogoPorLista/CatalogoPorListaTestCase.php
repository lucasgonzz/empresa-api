<?php

namespace Tests\Feature\Precios\CatalogoPorLista;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Precios\RecalculoEnLote\RecalculoEnLoteTestCase;

/**
 * Base de los tests del catálogo de la tienda por lista de precios en empresa-api (misión
 * catalogo-por-lista-tienda, 5/10/2026): el interruptor de la lista
 * (`price_types.catalogo_restringido_en_tienda`), el check por artículo y por lista
 * (`article_price_type.visible_en_tienda`), la actualización masiva, el contador y lo que el
 * asistente de IA no puede tocar.
 *
 * Reusa el armado de RecalculoEnLoteTestCase (un comercio propio por test, listas, artículos
 * releídos de la base, DatabaseTransactions), igual que SincronizarMargenTestCase, y agrega el
 * escenario mínimo: un dueño con dos listas (Minorista sin restricción y Mayorista restringida) y
 * OTRO comercio con su propia lista, para probar que nada cruza de dueño.
 *
 * 🔴 Siempre `= 1`: NULL y 0 son los dos "no habilitado" / "sin restricción". Los tests que miran
 * la base distinguen NULL de 0 a propósito, porque la escritura condicional consiste justamente en
 * NO convertir un NULL en 0 (ni al revés) cuando nadie lo pidió.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
abstract class CatalogoPorListaTestCase extends RecalculoEnLoteTestCase
{
    /** @var \App\Models\User */
    protected $dueno;

    /** @var \App\Models\User */
    protected $otro_dueno;

    /** @var \App\Models\PriceType Lista sin restricción. */
    protected $minorista;

    /** @var \App\Models\PriceType Lista con el catálogo restringido en la tienda. */
    protected $mayorista;

    /** @var \App\Models\PriceType Lista de otro comercio. */
    protected $lista_ajena;

    /**
     * Arma los dos comercios y deja logueado al dueño de Minorista y Mayorista.
     *
     * @return void
     */
    protected function armar_comercios()
    {
        // El otro comercio primero: crear_dueno() deja logueado al último que crea.
        $this->otro_dueno  = $this->crear_dueno(['listas_de_precio' => 1]);
        $this->lista_ajena = $this->crear_lista($this->otro_dueno, 'Ajena', 30, 1);

        $this->dueno     = $this->crear_dueno(['listas_de_precio' => 1]);
        $this->minorista = $this->crear_lista($this->dueno, 'Minorista', 30, 1);
        $this->mayorista = $this->crear_lista($this->dueno, 'Mayorista', 20, 2);

        DB::table('price_types')->where('id', $this->mayorista->id)->update(['catalogo_restringido_en_tienda' => 1]);
    }

    /**
     * Una fila del pivote article_price_type.
     *
     * @param  int        $article_id
     * @param  int        $price_type_id
     * @param  int|null   $visible
     * @param  float|null $percentage
     * @return void
     */
    protected function atar($article_id, $price_type_id, $visible, $percentage = null)
    {
        DB::table('article_price_type')->insert([
            'article_id'          => $article_id,
            'price_type_id'       => $price_type_id,
            'percentage'          => $percentage,
            'setear_precio_final' => 0,
            'visible_en_tienda'   => $visible,
        ]);
    }

    /**
     * Las filas del pivote de un artículo en una lista, tal como están en la base.
     *
     * @param  int $article_id
     * @param  int $price_type_id
     * @return \Illuminate\Support\Collection
     */
    protected function filas($article_id, $price_type_id)
    {
        return DB::table('article_price_type')
                    ->where('article_id', $article_id)
                    ->where('price_type_id', $price_type_id)
                    ->orderBy('id')
                    ->get();
    }

    /**
     * El visible_en_tienda de la ÚNICA fila del par (falla si hay cero o más de una): null, 0 o 1.
     *
     * @param  int $article_id
     * @param  int $price_type_id
     * @return int|null
     */
    protected function visible($article_id, $price_type_id)
    {
        $filas = $this->filas($article_id, $price_type_id);

        $this->assertCount(1, $filas, 'Tiene que haber exactamente una fila del artículo ' . $article_id . ' en la lista ' . $price_type_id);

        $valor = $filas->first()->visible_en_tienda;

        return is_null($valor) ? null : (int) $valor;
    }

    /**
     * El interruptor de una lista tal como está en la base: null, 0 o 1.
     *
     * @param  int $price_type_id
     * @return int|null
     */
    protected function interruptor($price_type_id)
    {
        $valor = DB::table('price_types')->where('id', $price_type_id)->value('catalogo_restringido_en_tienda');

        return is_null($valor) ? null : (int) $valor;
    }
}
