<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Article;
use App\Models\ArticleMeliAttribute;
use App\Models\MeliAttribute;
use App\Models\MeliAttributeValue;
use App\Models\MeliCategory;
use Illuminate\Http\Request;

/**
 * Guarda la categoría de Mercado Libre y sus atributos que el formulario del artículo manda junto
 * con el resto del artículo (misión mercado-libre-integracion, 27/10/2026).
 *
 * 🔴 SOLO SI EL REQUEST TRAE LA CLAVE. La SPA vieja manda el artículo sin `meli_category_id` ni
 * `meli_attributes`: si se pisaran con null, cada guardado desde la SPA vieja le borraría al
 * artículo la categoría y los atributos cargados desde el modal del predictor.
 */
class ArticleMeliHelper
{
    /**
     * `meli_category_id` (id LOCAL de `meli_categories`) y `meli_attributes`
     * (`[{meli_attribute_id, value_id, value_name}]`, con `meli_attribute_id` = id local de
     * `meli_attributes`).
     *
     * - `meli_category_id` vacío borra la categoría; un id que no existe se ignora (no se le pisa
     *   la categoría buena al artículo por un dato roto).
     * - `meli_attributes` REEMPLAZA los atributos del artículo: un renglón sin valor se borra, y
     *   un atributo que no es de la categoría del artículo se descarta.
     * - Si cambió la categoría y no vinieron atributos, se borran los de la categoría anterior
     *   (mandarlos con otra categoría hace que ML rechace la publicación).
     *
     * @param Article $article Artículo ya guardado.
     * @param Request $request
     * @return bool true si se tocó algo.
     */
    public static function guardar_categoria_y_atributos(Article $article, Request $request)
    {
        $vino_categoria = $request->has('meli_category_id');
        $vinieron_atributos = $request->has('meli_attributes');

        if (!$vino_categoria && !$vinieron_atributos) {
            return false;
        }

        $cambio_categoria = false;

        if ($vino_categoria) {
            $nueva = self::categoria_valida($request->input('meli_category_id'), $article->meli_category_id);

            if ((string) $nueva !== (string) $article->meli_category_id) {
                $article->meli_category_id = $nueva;
                $article->save();
                $cambio_categoria = true;
            }
        }

        if ($vinieron_atributos) {
            $filas = $request->input('meli_attributes');
            self::reemplazar_atributos($article, is_array($filas) ? $filas : []);
        } elseif ($cambio_categoria) {
            self::borrar_atributos_de_otra_categoria($article);
        }

        $article->unsetRelation('meli_attributes');
        $article->unsetRelation('meli_category');

        return true;
    }

    /**
     * La categoría que queda: null si vino vacía, el id si existe, o la actual si vino un id roto.
     *
     * @param mixed $valor Lo que mandó la SPA.
     * @param mixed $actual Lo que tiene el artículo.
     * @return int|null|mixed
     */
    protected static function categoria_valida($valor, $actual)
    {
        if (is_null($valor) || $valor === '' || $valor === 0 || $valor === '0') {
            return null;
        }

        if (is_numeric($valor) && MeliCategory::where('id', (int) $valor)->exists()) {
            return (int) $valor;
        }

        return $actual;
    }

    /**
     * Reemplaza los atributos del artículo por los que vinieron.
     *
     * @param Article $article
     * @param array<int, mixed> $filas `[{meli_attribute_id, value_id, value_name}]`.
     * @return void
     */
    protected static function reemplazar_atributos(Article $article, array $filas)
    {
        $validos = [];

        if (!empty($article->meli_category_id)) {
            foreach (MeliAttribute::where('meli_category_id', (int) $article->meli_category_id)->pluck('id') as $id) {
                $validos[(int) $id] = true;
            }
        }

        // Un renglón por atributo: si la SPA manda dos para el mismo, gana el último.
        $por_atributo = [];

        foreach ($filas as $fila) {
            if (!is_array($fila)) {
                continue;
            }

            $meli_attribute_id = isset($fila['meli_attribute_id']) ? (int) $fila['meli_attribute_id'] : 0;

            if (!isset($validos[$meli_attribute_id])) {
                continue;
            }

            $value_id = isset($fila['value_id']) ? trim((string) $fila['value_id']) : '';
            $value_name = isset($fila['value_name']) ? trim((string) $fila['value_name']) : '';

            if ($value_id === '' && $value_name === '') {
                unset($por_atributo[$meli_attribute_id]);

                continue;
            }

            $por_atributo[$meli_attribute_id] = [
                'value_id'   => $value_id === '' ? null : $value_id,
                'value_name' => $value_name === '' ? null : $value_name,
            ];
        }

        ArticleMeliAttribute::where('article_id', $article->id)->delete();

        foreach ($por_atributo as $meli_attribute_id => $valor) {
            $meli_attribute_value_id = null;

            if (!is_null($valor['value_id'])) {
                $meli_attribute_value_id = MeliAttributeValue::where('meli_attribute_id', $meli_attribute_id)
                    ->where('meli_id', $valor['value_id'])
                    ->value('id');
            }

            ArticleMeliAttribute::create([
                'article_id'              => $article->id,
                'meli_attribute_id'       => $meli_attribute_id,
                'meli_attribute_value_id' => $meli_attribute_value_id,
                'value_id'                => $valor['value_id'],
                'value_name'              => $valor['value_name'],
            ]);
        }
    }

    /**
     * Borra los atributos del artículo que no son de su categoría actual (o todos, si quedó sin
     * categoría).
     *
     * @param Article $article
     * @return void
     */
    protected static function borrar_atributos_de_otra_categoria(Article $article)
    {
        $query = ArticleMeliAttribute::where('article_id', $article->id);

        if (!empty($article->meli_category_id)) {
            $de_la_categoria = MeliAttribute::where('meli_category_id', (int) $article->meli_category_id)->pluck('id')->all();
            $query->whereNotIn('meli_attribute_id', $de_la_categoria);
        }

        $query->delete();
    }
}
