<?php

namespace App\Http\Controllers\Helpers\article;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\CatalogoPorListaHelper;
use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\PriceType;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ArticlePriceTypeHelper {

    static function attach_price_types($article, $price_types) {

        // if (UserHelper::hasExtencion('ventas_en_dolares')) return;

        /*
         * La lista «Precio final» (misión prueba-listas-siempre, 3/11/2026): su pivote es un espejo
         * del precio del artículo, así que lo que la persona edita en esa tarjeta se escribe en el
         * ARTÍCULO (margen y precio manual) y setFinalPrice(), que corre después, vuelve a espejar.
         * Ver write_through_precio_final().
         */
        $lista_precio_final = PriceTypeHelper::lista_precio_final((int) $article->user_id);

        foreach ($price_types as $price_type) {

            if (!is_null($lista_precio_final) && (int) $price_type['id'] === (int) $lista_precio_final->id) {
                Self::write_through_precio_final($article, $price_type);
            }

            $article->price_types()->syncWithoutDetaching($price_type['id']);

            $price_type_model = null;

            $final_price = null;

            if (isset($price_type['pivot']['percentage'])) {

                $percentage = $price_type['pivot']['percentage'];
            } else {

                $price_type_model = Self::get_price_type($price_type['id']);

                $percentage = $price_type_model->percentage;
            }

            if (isset($price_type['pivot']['final_price'])) {
                $final_price = $price_type['pivot']['final_price'];
            }


            $incluir_en_excel_para_clientes = Self::get_incluir_en_excel_para_clientes($price_type, $price_type_model);
            $setear_precio_final = Self::get_setear_precio_final($price_type, $price_type_model);


            // Columnas del pivote que se escriben en cada guardado, como siempre.
            $columnas_del_pivot = [
                'percentage'                        => $percentage,
                'final_price'                       => $final_price,
                'incluir_en_excel_para_clientes'    => $incluir_en_excel_para_clientes,
                'setear_precio_final'               => $setear_precio_final,
            ];

            /*
             * `visible_en_tienda` (misión catalogo-por-lista-tienda, 5/10/2026): SOLO si el request
             * lo trae con un valor válido. Sin la clave, el valor guardado se conserva; en una fila
             * recién atada queda en NULL (= no habilitado), que es como nace un artículo en una
             * lista restringida (decisión de Lucas).
             *
             * 🔴 No "simplificar" copiando el patrón de `incluir_en_excel_para_clientes` de arriba:
             * ése se escribe SIEMPRE y, si el request no lo trae, pisa con el default de la lista.
             * Acá eso sería que un SPA viejo cacheado (que no conoce el check) o el asistente de IA
             * (que arma `price_types` sin esta clave) le saquen el artículo de la tienda a todos los
             * mayoristas en cada guardado de la ficha, sin que nadie lo vea.
             */
            $visible_en_tienda = Self::get_visible_en_tienda($price_type);

            if (!is_null($visible_en_tienda)) {
                $columnas_del_pivot['visible_en_tienda'] = $visible_en_tienda;
            }

            $article->price_types()->updateExistingPivot($price_type['id'], $columnas_del_pivot);
        }
    }

    /**
     * Lo que la persona edita en la tarjeta de la lista «Precio final» se escribe en el artículo
     * (misión prueba-listas-siempre, 3/11/2026, decisión D2 del plan): el `percentage` del pivote →
     * `articles.percentage_gain`; `setear_precio_final` + `final_price` → `articles.price` (null al
     * destildar). Después el pivote se escribe igual que el de cualquier lista, y setFinalPrice() lo
     * vuelve a espejar con el precio del artículo.
     *
     * 🔴 SOLO si el valor que llega difiere del que el pivote tiene GUARDADO. La ficha manda siempre
     * `price_types` entero, también en un negocio de UNA sola lista, donde la tarjeta ni se dibuja y
     * la persona edita el margen en el recuadro de siempre: si acá se tomara el valor del espejo
     * viejo que viajó en el request, se le pisaría el margen que acaba de escribir. Un pivote que
     * todavía no existe (artículo nuevo) no tiene guardado: ahí un valor cargado sí se toma.
     *
     * 🔴 Y un pivote que no existe cuenta como "sin precio fijado", igual que
     * ArticlePriceTypeMonedaHelper::write_through_precio_final(). La SPA manda `price_types` en TODA
     * alta, con `setear_precio_final = 0` para esta lista y sin `final_price`: si ese 0 se leyera como
     * "destildó el precio fijado", un artículo nuevo perdería el precio manual que acaban de tipear
     * en el recuadro (`price: 500` quedaba en NULL y el precio final en 0). Solo un `setear = 1` con
     * un precio en la mano dice algo; el destildado se mide contra un pivote que estaba tildado.
     *
     * @param  \App\Models\Article  $article
     * @param  array                $price_type  Una lista del request: ['id' => int, 'pivot' => [...]].
     * @return void
     */
    static function write_through_precio_final($article, $price_type) {

        $pivot = isset($price_type['pivot']) && is_array($price_type['pivot']) ? $price_type['pivot'] : array();

        $guardado = $article->price_types()->find($price_type['id']);

        $limpiar = function ($valor) {
            return (is_null($valor) || $valor === '') ? null : (float) $valor;
        };

        $percentage  = array_key_exists('percentage', $pivot) ? $limpiar($pivot['percentage']) : null;
        $final_price = array_key_exists('final_price', $pivot) ? $limpiar($pivot['final_price']) : null;

        $setear = array_key_exists('setear_precio_final', $pivot)
                    ? ($pivot['setear_precio_final'] == 1 || $pivot['setear_precio_final'] == '1')
                    : null;

        $percentage_guardado = is_null($guardado) ? null : $limpiar($guardado->pivot->percentage);
        $final_guardado      = is_null($guardado) ? null : $limpiar($guardado->pivot->final_price);
        $setear_guardado     = !is_null($guardado) && (int) $guardado->pivot->setear_precio_final === 1;

        $distinto = function ($a, $b) {
            if (is_null($a) || is_null($b)) {
                return !(is_null($a) && is_null($b));
            }
            return abs((float) $a - (float) $b) > 0.000001;
        };

        $cambio = false;

        $manual = is_null($setear) ? (bool) $setear_guardado : $setear;

        if (!$manual && array_key_exists('percentage', $pivot) && $distinto($percentage, $percentage_guardado)) {
            $article->percentage_gain = $percentage;
            $cambio = true;
        }

        // Tildado sin precio y sin nada guardado (una lista con `setear_precio_final` por defecto en
        // un alta): no hay ningún precio que fijar, y el que el artículo trae no se toca.
        $tildado_sin_precio = $setear === true && is_null($final_price) && is_null($guardado);

        if (!is_null($setear) && $setear !== $setear_guardado && !$tildado_sin_precio) {
            $article->price = $setear ? $final_price : null;

            /*
             * Un precio fijado a mano no convive con un margen del artículo: setFinalPrice() pone
             * `price` en null en cuanto hay `percentage_gain > 0` (el bloque "Pongo el precio en
             * blanco"), que es la regla de siempre del recuadro del artículo ("debe tener vacío el
             * campo margen"). Tildar "setear precio final" en la tarjeta es elegir el precio: el
             * margen se vacía, como lo vaciaría la persona en el recuadro. Al destildar queda el
             * artículo sin margen propio (precio por costo + lo demás), hasta que cargue uno.
             */
            if ($setear) {
                $article->percentage_gain = null;
            }

            $cambio = true;
        } else if ($manual && array_key_exists('final_price', $pivot) && $distinto($final_price, $final_guardado)) {
            $article->price = $final_price;
            $cambio = true;
        }

        if ($cambio) {
            $article->save();
        }
    }

    /**
     * El `visible_en_tienda` que trae una lista del request de la ficha, saneado a 1 o 0, o null
     * si no hay que escribirlo: la clave no vino, vino en null o en '' (que en MySQL estricto
     * revienta al guardarse en un tinyint), o no es un sí/no reconocible. Ver attach_price_types().
     *
     * @param  array $price_type  Una lista del request: ['id' => int, 'pivot' => [...]].
     * @return int|null
     */
    static function get_visible_en_tienda($price_type) {

        if (!isset($price_type['pivot']['visible_en_tienda'])) {
            return null;
        }

        return CatalogoPorListaHelper::sanear_booleano($price_type['pivot']['visible_en_tienda']);
    }

    static function get_setear_precio_final($price_type, $price_type_model) {
        
        if (isset($price_type['pivot']['setear_precio_final'])) {

            return $price_type['pivot']['setear_precio_final'];
        }

        if (is_null($price_type_model)) {
            $price_type_model = Self::get_price_type($price_type['id']);
        }

        return $price_type_model['setear_precio_final'];
    }

    static function get_incluir_en_excel_para_clientes($price_type, $price_type_model) {
        
        if (isset($price_type['pivot']['incluir_en_excel_para_clientes'])) {

            return $price_type['pivot']['incluir_en_excel_para_clientes'];
        }

        if (is_null($price_type_model)) {
            $price_type_model = Self::get_price_type($price_type['id']);
        }

        return $price_type_model['incluir_en_lista_de_precios_de_excel'];
    }

    static function get_price_type($id) {
        return PriceType::find($id);
    }
}