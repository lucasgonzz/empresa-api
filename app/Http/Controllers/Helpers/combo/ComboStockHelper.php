<?php

namespace App\Http\Controllers\Helpers\combo;

/**
 * El stock de un combo: cuántos combos se pueden armar con lo que hay de cada componente
 * (misión combos-calculados, 30/9/2026).
 *
 * Es una función PURA, sin base ni modelos: recibe la lista de componentes ya leída y devuelve un
 * número. Eso es a propósito, por dos motivos. Uno, que se puede probar suelta con casos armados a
 * mano (el ejemplo de Lucas y cada borde). Dos, que la tienda (tienda-api, otro repo, otra base de
 * código) implementa LA MISMA regla con una sola consulta agregada en vez de un accessor por combo:
 * las dos puntas tienen que decir lo mismo, y la fuente de verdad de la regla es este docblock.
 *
 * 🔴 EL STOCK DEL COMBO NO SE GUARDA EN NINGUNA COLUMNA, y quien lo quiera "cachear" para que el
 * listado sea más rápido rompe el sistema sin que nada avise. `articles.stock` se escribe por
 * muchísimos caminos, varios de ellos crudos (`DB::table('articles')->update`, ajustes masivos,
 * importaciones, la corrección de stock por depósito, las devoluciones...). Persistir el stock del
 * combo obligaría a enganchar TODOS esos caminos, y el que se olvide deja un combo que dice tener
 * stock que ya no existe (o al revés: agotado con stock de sobra), y ese error se ve recién cuando
 * un cliente compra algo que no hay. Calculado al leer, el número no puede quedar viejo.
 *
 * La regla, por componente (`stock` del artículo dividido la cantidad que lleva el combo, hacia
 * abajo), y el stock del combo es el MÍNIMO de todos:
 *
 *  - Artículo con `stock` NULL: no lleva control de stock, NO limita. (Mismo criterio que el resto
 *    del sistema: NULL es "no controla", no "cero".)
 *  - Artículo borrado (soft delete): el combo no se puede armar, stock 0. Se mira ANTES que el
 *    stock, porque un artículo borrado puede conservar un stock viejo con números que ya no valen.
 *  - Stock negativo (se vendió sin stock): se toma como 0. Un componente en -3 no puede dar un
 *    stock de combo negativo ni "restar" a los demás.
 *  - Si NINGÚN componente lleva stock (todos NULL), el stock del combo es `null`: "sin control",
 *    hay siempre. No 0: un combo de servicios o de artículos sin stock se vende sin límite.
 *  - Cantidad del componente <= 0 o no numérica: ese renglón no aporta límite (evita dividir por
 *    cero con un combo mal cargado).
 *  - 🔴 EL MISMO ARTÍCULO EN MÁS DE UN RENGLÓN se AGRUPA ANTES DE DIVIDIR: clave de agrupación =
 *    `article_id`, y las cantidades de sus renglones se SUMAN (solo las válidas: numéricas y > 0).
 *    Con A de stock 2 y dos renglones de cantidad 1, el combo lleva 2 unidades de A por combo:
 *    floor(2/2) = 1. Dividir cada renglón por separado daba floor(2/1) = 2 y vendía de más. El ABM
 *    permite repetir un artículo (y puede haber datos viejos así), y el stock es UNO solo por
 *    artículo, así que el reparto entre renglones no existe. El renglón que trae `borrado` manda
 *    igual que siempre (si CUALQUIERA de los renglones del combo está borrado, el resultado es 0).
 *    Un renglón sin `article_id` no se agrupa con nadie: cuenta solo (así funciona el llamador que
 *    arma los renglones a mano sin ids).
 *    La regla es IDÉNTICA en tienda-api (`ComboStockHelper` de ese repo): si se cambia acá, se
 *    cambia allá en el mismo release.
 *
 * Es el stock GLOBAL del artículo (`articles.stock`), no el de un depósito.
 *
 * Ejemplo de Lucas: A x2 con stock 2, B x3 con stock 3, C x4 con stock 4 -> floor(2/2)=1,
 * floor(3/3)=1, floor(4/4)=1 -> 1 combo. Si C tiene 3 -> floor(3/4)=0 -> 0: el limitante manda.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class ComboStockHelper {

    /**
     * Cuántos combos se pueden armar con estos componentes.
     *
     * @param  array  $componentes  Lista de renglones, cada uno un array con:
     *                              - `stock`  (int|float|string|null): `articles.stock` del artículo.
     *                              - `amount` (int|float|string): cantidad que lleva UN combo
     *                                (`article_combo.amount`).
     *                              - `borrado` (bool, opcional): true si el artículo está en la papelera.
 *                              - `article_id` (int, opcional): id del artículo; los renglones con el
 *                                mismo id se agrupan y suman su cantidad (ver el encabezado).
     * @return int|null  Cantidad de combos armables, o null si ningún componente lleva stock
     *                   (sin control: hay siempre).
     */
    static function calcular(array $componentes) {

        $minimo = null;

        /*
         * Primera pasada: agrupar por artículo. `$por_articulo` guarda, por clave, el stock y la
         * cantidad SUMADA que el combo lleva de ese artículo. La clave es `article_id`; un renglón
         * sin id usa una clave propia (`renglon:N`) y no se mezcla con ninguno.
         */
        $por_articulo = [];

        foreach ($componentes as $posicion => $componente) {

            /*
             * El borrado corta ANTES que todo: el combo no se puede armar, y no hace falta seguir
             * mirando. Va primero porque un artículo borrado con stock NULL caería en "no limita" y
             * dejaría el combo como armable con un componente que ya no existe.
             */
            if (!empty($componente['borrado'])) {
                return 0;
            }

            $amount = isset($componente['amount']) ? $componente['amount'] : null;

            if (!is_numeric($amount) || (float) $amount <= 0) {
                continue;
            }

            $stock = array_key_exists('stock', $componente) ? $componente['stock'] : null;

            /* NULL (o algo que no es un número) = el artículo no lleva stock: no limita. */
            if (is_null($stock) || $stock === '' || !is_numeric($stock)) {
                continue;
            }

            $clave = (isset($componente['article_id']) && $componente['article_id'] !== '')
                ? 'articulo:' . $componente['article_id']
                : 'renglon:' . $posicion;

            if (!isset($por_articulo[$clave])) {
                $por_articulo[$clave] = ['stock' => $stock, 'amount' => 0.0];
            }

            $por_articulo[$clave]['amount'] += (float) $amount;
        }

        /* Segunda pasada: el limitante es el mínimo de floor(stock / cantidad TOTAL) por artículo. */
        foreach ($por_articulo as $agrupado) {

            /* Stock negativo = 0: ver el encabezado. */
            $disponible = max(0, (float) $agrupado['stock']);

            $armables = (int) floor($disponible / $agrupado['amount']);

            if (is_null($minimo) || $armables < $minimo) {
                $minimo = $armables;
            }
        }

        return $minimo;
    }

    /**
     * El stock de un combo ya cargado con sus artículos (relación `articles` del modelo `Combo`,
     * con `pivot->amount`). Es solo el armado de los renglones para `calcular()`.
     *
     * @param  iterable  $articulos  Artículos del combo, incluidos los borrados (`withTrashed`).
     * @return int|null
     */
    static function calcular_de_articulos($articulos) {

        $componentes = [];

        foreach ($articulos as $articulo) {

            $componentes[] = [
                'article_id' => $articulo->id,
                'stock'   => $articulo->stock,
                'amount'  => isset($articulo->pivot) ? $articulo->pivot->amount : null,
                'borrado' => !is_null($articulo->deleted_at),
            ];
        }

        return self::calcular($componentes);
    }
}
