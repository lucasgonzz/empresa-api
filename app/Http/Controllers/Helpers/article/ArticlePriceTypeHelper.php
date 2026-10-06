<?php

namespace App\Http\Controllers\Helpers\article;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\CatalogoPorListaHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\PriceType;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ArticlePriceTypeHelper {

    static function attach_price_types($article, $price_types) {

        // if (UserHelper::hasExtencion('ventas_en_dolares')) return; 

        foreach ($price_types as $price_type) {

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