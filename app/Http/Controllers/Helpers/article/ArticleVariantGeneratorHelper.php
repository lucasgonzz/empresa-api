<?php

namespace App\Http\Controllers\Helpers\article;

use App\Models\Article;
use App\Models\ArticleVariant;
use Illuminate\Support\Facades\Log;

/**
 * ArticleVariantGeneratorHelper
 *
 * Responsable de calcular el producto cartesiano de variantes de un artículo a partir de sus
 * `article_properties` (con los `article_property_values` seleccionados) y aplicar un diff
 * incremental NO destructivo contra las `ArticleVariant` ya existentes.
 *
 * Reemplaza el cálculo que antes hacía el front (`article_variants.js`) y la lógica vieja de
 * `ArticleVariantHelper::check_cambio_en_cantidad_propiedades` / `delete_current_variants`, que
 * borraba TODAS las variantes ante cualquier cambio en la cantidad de propiedades (perdiendo
 * stock, precio e imagen cargados por variante).
 *
 * Reglas del diff:
 * - Combinación nueva (no existía)   -> se crea la ArticleVariant con oculta=true y bar_code='0'+id.
 * - Combinación ya existente         -> no se toca (conserva stock/price/image_url/oculta/bar_code).
 * - Variante existente que ya no matchea ninguna combinación válida -> se oculta (oculta=true),
 *   NUNCA se borra (puede estar referenciada por `article_sale.article_variant_id`).
 */
class ArticleVariantGeneratorHelper
{
    /** @var int Id del artículo sobre el que se generan/actualizan las variantes. */
    protected $article_id;

    /** @var Article Artículo con sus article_properties.article_property_values ya cargados. */
    protected $article;

    /** @var \Illuminate\Support\Collection Variantes ya existentes del artículo, indexadas luego por firma. */
    protected $existing_variants;

    /**
     * @param int $article_id Id del artículo cuyas variantes se van a generar/actualizar.
     */
    function __construct($article_id)
    {
        $this->article_id = $article_id;

        // Fuente de verdad: las propiedades y valores seleccionados se leen siempre desde la DB,
        // nunca se confía en lo que mande el front para calcular el cartesiano.
        $this->article = Article::where('id', $article_id)
                                    ->with('article_properties.article_property_values')
                                    ->first();

        $this->existing_variants = ArticleVariant::where('article_id', $article_id)
                                                    ->with('article_property_values')
                                                    ->get();
    }

    /**
     * Punto de entrada: calcula el cartesiano y aplica el diff incremental no destructivo.
     *
     * @return \Illuminate\Support\Collection Variantes del artículo luego de aplicar el diff.
     */
    function generate()
    {
        if (is_null($this->article)) {
            Log::info('ArticleVariantGeneratorHelper: no se encontro el articulo '.$this->article_id);
            return $this->existing_variants;
        }

        // Combinaciones válidas (cartesiano) según las propiedades/valores seleccionados hoy.
        $combinations = $this->build_combinations();

        // Índice de variantes existentes por firma, para poder comparar en O(1) contra el cartesiano.
        $existing_by_signature = $this->index_existing_by_signature();

        // Firmas de las combinaciones calculadas (para detectar al final qué variantes quedaron obsoletas).
        $valid_signatures = [];

        foreach ($combinations as $combination) {
            $signature = $this->signature_for_combination($combination);
            $valid_signatures[$signature] = true;

            if (!isset($existing_by_signature[$signature])) {
                // Combinación nueva: se crea la variante oculta por defecto, sin pisar nada existente.
                $this->create_variant_for_combination($combination);
            }
            // Si ya existe, no se toca: conserva stock/price/image_url/oculta/bar_code.
        }

        // Cualquier variante existente cuya firma ya no está entre las combinaciones válidas
        // (se quitó una propiedad o un valor) se oculta, nunca se borra.
        $this->hide_variants_not_in($valid_signatures, $existing_by_signature);

        return ArticleVariant::where('article_id', $this->article_id)
                                ->withAll()
                                ->get();
    }

    /**
     * Arma la lista de "grupos de valores" (uno por propiedad con al menos un valor seleccionado)
     * y calcula el producto cartesiano entre ellos.
     *
     * @return array Lista de combinaciones; cada combinación es un array de ArticlePropertyValue.
     */
    protected function build_combinations()
    {
        // Grupos de valores por propiedad, ignorando propiedades sin ningún valor seleccionado.
        $groups_of_values = [];

        foreach ($this->article->article_properties as $article_property) {
            if (count($article_property->article_property_values) >= 1) {
                $groups_of_values[] = $article_property->article_property_values;
            }
        }

        if (count($groups_of_values) == 0) {
            return [];
        }

        // Cartesiano armado a mano con foreach (sin array_map/reduce) para mantener el estilo del proyecto.
        $combinations = [[]];

        foreach ($groups_of_values as $values_of_property) {
            $new_combinations = [];

            foreach ($combinations as $existing_combination) {
                foreach ($values_of_property as $value) {
                    $new_combination = $existing_combination;
                    $new_combination[] = $value;
                    $new_combinations[] = $new_combination;
                }
            }

            $combinations = $new_combinations;
        }

        return $combinations;
    }

    /**
     * Firma determinística de una combinación: ids de article_property_value ordenados ascendente
     * y unidos por '-' (ej. "3-8"). Permite comparar combinaciones sin importar el orden de armado.
     *
     * @param array $combination Array de ArticlePropertyValue.
     * @return string Firma de la combinación.
     */
    protected function signature_for_combination($combination)
    {
        $ids = [];

        foreach ($combination as $value) {
            $ids[] = $value->id;
        }

        sort($ids, SORT_NUMERIC);

        return implode('-', $ids);
    }

    /**
     * Indexa las variantes ya existentes del artículo por su firma (misma lógica que
     * signature_for_combination pero a partir de los article_property_values ya attacheados).
     *
     * @return array Mapa firma => ArticleVariant.
     */
    protected function index_existing_by_signature()
    {
        $existing_by_signature = [];

        foreach ($this->existing_variants as $existing_variant) {
            $ids = [];

            foreach ($existing_variant->article_property_values as $value) {
                $ids[] = $value->id;
            }

            sort($ids, SORT_NUMERIC);

            $signature = implode('-', $ids);

            $existing_by_signature[$signature] = $existing_variant;
        }

        return $existing_by_signature;
    }

    /**
     * Crea una ArticleVariant nueva para una combinación que todavía no existía.
     * Se crea oculta (no disponible por defecto) y sin precio; el bar_code se setea
     * recién después de tener el id ('0'.$id), tal como pide el prompt 519.
     *
     * @param array $combination Array de ArticlePropertyValue de la combinación.
     * @return ArticleVariant Variante creada.
     */
    protected function create_variant_for_combination($combination)
    {
        // Descripción de la variante: nombres de los valores, en el mismo orden de la combinación, separados por espacio.
        $names = [];

        foreach ($combination as $value) {
            $names[] = $value->name;
        }

        $variant_description = implode(' ', $names);

        $article_variant = ArticleVariant::create([
            'article_id'            => $this->article_id,
            'price'                 => null,
            'variant_description'   => $variant_description,
            'oculta'                => true,
        ]);

        foreach ($combination as $value) {
            $article_variant->article_property_values()->attach($value->id);
        }

        // El bar_code depende del id autoincremental, por eso se setea en un segundo paso.
        $article_variant->bar_code = '0'.$article_variant->id;
        $article_variant->save();

        Log::info('Se creo variante (oculta) '.$article_variant->variant_description.' bar_code '.$article_variant->bar_code);

        return $article_variant;
    }

    /**
     * Marca oculta=true a las variantes existentes cuya firma ya no está entre las combinaciones
     * válidas actuales. Nunca hace delete: las variantes pueden estar referenciadas desde
     * `article_sale.article_variant_id` y borrarlas rompería el historial de ventas.
     *
     * @param array $valid_signatures Mapa firma => true de las combinaciones vigentes.
     * @param array $existing_by_signature Mapa firma => ArticleVariant existente.
     * @return void
     */
    protected function hide_variants_not_in($valid_signatures, $existing_by_signature)
    {
        foreach ($existing_by_signature as $signature => $existing_variant) {
            if (!isset($valid_signatures[$signature]) && !$existing_variant->oculta) {
                $existing_variant->oculta = true;
                $existing_variant->save();

                Log::info('Se oculto variante '.$existing_variant->variant_description.' (firma '.$signature.' ya no es valida)');
            }
        }
    }
}
