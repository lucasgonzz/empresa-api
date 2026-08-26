<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Recipe;

/**
 * Potencial de armado: con las partes YA terminadas que hay en stock, cuántas unidades
 * de cada producto se podrían armar.
 *
 *     potencial(P) = min sobre insumos i de floor( stock(i) / cantidad(i) )
 *     vendibles(P) = stock(P) + potencial(P)
 *
 * 🔴 ES DE UN SOLO NIVEL, A PROPÓSITO. NO ES RECURSIVO, Y NO HAY QUE "MEJORARLO"
 * HACIÉNDOLO RECURSIVO.
 *
 * La versión recursiva ingenua —bajar a la receta de cada insumo fabricado y sumarle su
 * propio potencial— SOBREESTIMA en cuanto dos ramas comparten un insumo. Si "Estructura
 * silla" y "Asiento madera silla" consumen los dos remaches del mismo stock, el recursivo
 * calcula el potencial de cada rama por separado usando el MISMO stock de remaches, como
 * si cada rama tuviera su propia reserva, y después combina los dos números. Resultado:
 * promete más sillas de las que se pueden armar de verdad, que es exactamente el error
 * que este número tiene que no cometer (es un número con el que se decide una venta).
 *
 * Resolverlo bien no es una recursión mejor escrita: es un problema de ASIGNACIÓN —a qué
 * subproducto le toca cada unidad del insumo compartido—, o sea programación lineal
 * entera. Fuera de alcance y, además, innecesario: lo que Quino pidió es "cuántas sillas
 * puedo vender con las partes que ya tengo terminadas", que es literalmente un nivel.
 *
 * Este cálculo NO ESCRIBE STOCK. Es de solo lectura y vive solo en la pantalla de
 * producción: no toca el módulo de ventas ni el control de stock de ventas. El stock del
 * producto final sigue subiendo únicamente cuando el lote llega a su estado final.
 */
class PotencialDeArmadoHelper
{
    /**
     * Calcula el potencial de armado de todas las recetas de un comercio.
     *
     * @param  int  $user_id
     * @return array
     */
    public static function calcular($user_id)
    {
        $recipes = Recipe::where('user_id', $user_id)
                        ->with('article', 'recipe_routes.articles.addresses', 'recipe_routes.recipe_route_type')
                        ->get();

        $models = [];

        foreach ($recipes as $recipe) {

            if (is_null($recipe->article)) {
                continue;
            }

            $models[] = self::calcular_fila($recipe);
        }

        // Primero lo que más se puede armar: es el orden con el que se mira la pantalla.
        usort($models, function ($a, $b) {
            return $b['potencial'] <=> $a['potencial'];
        });

        return $models;
    }

    /**
     * Una fila del listado: una receta con su potencial y su insumo limitante.
     *
     * @param  \App\Models\Recipe  $recipe
     * @return array
     */
    private static function calcular_fila(Recipe $recipe)
    {
        /*
         * El stock del producto terminado es SIEMPRE el global, aunque el de los insumos respete
         * el depósito de la ruta. La asimetría es a propósito: el producto se vende desde donde
         * sea, pero lo que se puede armar está limitado por dónde están físicamente los insumos.
         */
        $stock_actual = (float) $recipe->article->stock;

        $fila = [
            'recipe_id'             => (int) $recipe->id,
            'article_id'            => (int) $recipe->article->id,
            'article_name'          => $recipe->article->name,
            'recipe_route_id'       => null,
            'recipe_route_nombre'   => null,
            'address_id'            => null,
            'stock_actual'          => $stock_actual,
            'potencial'             => 0,
            'vendibles'             => $stock_actual,
            'sin_ruta'              => false,
            'sin_insumos'           => false,
            'renglones_ignorados'   => 0,
            'insumo_limitante'      => null,
            'insumos'               => [],
        ];

        $route = self::elegir_ruta($recipe);

        if (is_null($route)) {
            $fila['sin_ruta'] = true;

            return $fila;
        }

        $fila['recipe_route_id'] = (int) $route->id;

        if (!is_null($route->recipe_route_type)) {
            $fila['recipe_route_nombre'] = $route->recipe_route_type->name;
        }

        /*
         * El depósito del que salen los insumos, con la MISMA cascada que usa el consumo real
         * (calculate_planned_inputs): si la ruta declara from_address_id, el stock del insumo es
         * el de ese depósito; si no, el stock global. Sin esto, el potencial diría que se pueden
         * armar 50 sillas con remaches que están en otra sucursal.
         */
        $from_address_id = null;

        if (!is_null($route->from_address_id) && $route->from_address_id != 0) {
            $from_address_id = (int) $route->from_address_id;
        }

        $fila['address_id'] = $from_address_id;

        $insumos = self::agrupar_insumos($route);

        $renglones_ignorados = 0;
        $filas_insumos = [];
        $potencial = null;
        $limitante = null;

        foreach ($insumos as $insumo) {

            $cantidad = $insumo['cantidad'];

            // Un renglón con cantidad 0 (o negativa) sería una división por cero. Se saltea y se
            // cuenta, para que la pantalla pueda decir que la receta tiene renglones sin cargar.
            if ($cantidad <= 0) {
                $renglones_ignorados++;
                continue;
            }

            $article = $insumo['article'];

            $stock = self::stock_del_insumo($article, $from_address_id);

            $posible = $stock <= 0 ? 0 : (int) floor($stock / $cantidad);

            $fila_insumo = [
                'article_id'            => (int) $article->id,
                'article_name'          => $article->name,
                'cantidad_por_unidad'   => $cantidad,
                'stock'                 => $stock,
                'posible'               => $posible,
            ];

            $filas_insumos[] = $fila_insumo;

            if (is_null($potencial) || $posible < $potencial) {
                $potencial = $posible;
                $limitante = $fila_insumo;
            } else if ($posible === $potencial && !is_null($limitante) && strcmp($fila_insumo['article_name'], $limitante['article_name']) < 0) {
                // Empate: gana el de nombre alfabéticamente menor, para que dos corridas seguidas
                // sobre los mismos datos muestren siempre el mismo insumo limitante.
                $limitante = $fila_insumo;
            }
        }

        $fila['renglones_ignorados'] = $renglones_ignorados;
        $fila['insumos'] = $filas_insumos;

        if (is_null($potencial)) {
            $fila['sin_insumos'] = true;

            return $fila;
        }

        $fila['potencial'] = $potencial;
        $fila['insumo_limitante'] = $limitante;
        $fila['vendibles'] = $stock_actual + $potencial;

        return $fila;
    }

    /**
     * Qué ruta de la receta se usa para el cálculo.
     *
     * La que esté marcada como default; si ninguna lo está —que es el caso real, porque
     * RecipeRouteController@store nunca setea is_default—, la de menor id. La respuesta expone
     * cuál se usó (recipe_route_id y recipe_route_nombre) para que el usuario sepa sobre qué
     * ruta se calculó y no tenga que adivinarlo.
     *
     * @param  \App\Models\Recipe  $recipe
     * @return \App\Models\RecipeRoute|null
     */
    private static function elegir_ruta(Recipe $recipe)
    {
        $rutas = $recipe->recipe_routes;

        if (count($rutas) == 0) {
            return null;
        }

        foreach ($rutas as $ruta) {
            if ($ruta->is_default == 1) {
                return $ruta;
            }
        }

        $elegida = null;

        foreach ($rutas as $ruta) {
            if (is_null($elegida) || (int) $ruta->id < (int) $elegida->id) {
                $elegida = $ruta;
            }
        }

        return $elegida;
    }

    /**
     * Agrupa los insumos de la ruta por article_id, SUMANDO las cantidades.
     *
     * 🔴 Los `amount` se suman y no se toma uno solo. Un mismo artículo puede ser insumo de la
     * misma ruta MÁS DE UNA VEZ, en estados distintos: el electrodo que se usa en "soldado" y de
     * nuevo en "pre-armado" son dos renglones de article_recipe_route, y $route->articles
     * devuelve el artículo repetido. Si se tomara un solo renglón, el potencial saldría inflado
     * —diría que alcanza para más unidades de las que alcanza— y es un número con el que se
     * decide una venta. Por eso se agrupa antes de dividir.
     *
     * @param  \App\Models\RecipeRoute  $route
     * @return array  Cada entrada: ['article' => Article, 'cantidad' => float]
     */
    private static function agrupar_insumos($route)
    {
        $agrupados = [];

        foreach ($route->articles as $article) {

            $article_id = (int) $article->id;

            if (!isset($agrupados[$article_id])) {
                $agrupados[$article_id] = [
                    'article'   => $article,
                    'cantidad'  => 0,
                ];
            }

            $agrupados[$article_id]['cantidad'] += (float) $article->pivot->amount;
        }

        return array_values($agrupados);
    }

    /**
     * El stock disponible de un insumo, según el depósito del que la ruta saca los insumos.
     *
     * @param  \App\Models\Article  $article
     * @param  int|null             $from_address_id  null = stock global.
     * @return float
     */
    private static function stock_del_insumo($article, $from_address_id)
    {
        if (is_null($from_address_id)) {
            return (float) $article->stock;
        }

        foreach ($article->addresses as $address) {
            if ((int) $address->id === (int) $from_address_id) {
                return (float) $address->pivot->amount;
            }
        }

        // El insumo no tiene fila en ese depósito: ahí no hay nada.
        return 0;
    }
}
