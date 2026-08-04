<?php

namespace Tests\Import\Helpers;

use App\Models\Article;

/**
 * Toma una foto normalizada de TODOS los artículos de un tenant, incluyendo las
 * relaciones que la importación puede modificar.
 *
 * Se usa en el test de reversión: si el rollback está bien hecho, la foto
 * posterior al rollback tiene que ser idéntica, campo por campo, a la anterior a
 * la importación. Comparar campos sueltos a mano deja pasar justamente los bugs
 * que tuvo el rollback históricamente (relaciones que no se revierten).
 *
 * NOTA (verificado contra develop, prompt 06 del grupo 238): el modelo Article no
 * expone relaciones `discounts()` ni `surchages()` como pide el spec original;
 * las relaciones reales se llaman `article_discounts()` y `article_surchages()`,
 * y además son hasMany directos (no belongsToMany con pivot como price_types /
 * addresses / providers). El campo `percentage` vive como atributo propio de
 * ArticleDiscount/ArticleSurchage, no como `->pivot->percentage`. Se corrigieron
 * los nombres y se generalizó pivot() para leer el atributo directo cuando el
 * modelo relacionado no tiene pivot, para no perder la detección de cambios.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe, argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class ArticleSnapshot
{
    /**
     * Columnas directas de `articles` que la importación puede tocar.
     *
     * @var array
     */
    protected static $campos = [
        'bar_code',
        'sku',
        'provider_code',
        'name',
        'cost',
        'percentage_gain',
        'price',
        'final_price',
        'stock',
        'stock_min',
        'iva_id',
        'provider_id',
        'brand_id',
        'category_id',
        'sub_category_id',
        'unidad_medida_id',
        'cost_in_dollars',
        'in_offer',
        'online',
    ];

    /**
     * @param  int $user_id
     * @return array  [article_id => ['campo' => valor, ...]]
     */
    public static function tomar($user_id)
    {
        $articulos = Article::where('user_id', $user_id)
                            ->with(['addresses', 'price_types', 'article_discounts', 'article_surchages', 'providers'])
                            ->orderBy('id')
                            ->get();

        $foto = [];

        foreach ($articulos as $articulo) {

            $fila = [];

            foreach (self::$campos as $campo) {
                $fila[$campo] = self::normalizar($articulo->$campo);
            }

            $fila['__depositos']  = self::pivot($articulo->addresses, 'amount');
            $fila['__listas']     = self::pivot($articulo->price_types, 'price');
            $fila['__descuentos'] = self::pivot($articulo->article_discounts, 'percentage');
            $fila['__recargos']   = self::pivot($articulo->article_surchages, 'percentage');
            $fila['__proveedores'] = self::pivot($articulo->providers, 'cost');

            $foto[(int) $articulo->id] = $fila;
        }

        ksort($foto);

        return $foto;
    }

    /**
     * Aplana una relación a [id => valor], ordenado, para que la comparación no
     * dependa del orden en que Eloquent traiga las filas.
     *
     * Soporta dos formas: relaciones belongsToMany con pivot (addresses,
     * price_types, providers), donde el valor vive en `$modelo->pivot->$campo`,
     * y relaciones hasMany sin pivot (article_discounts, article_surchages),
     * donde el valor es un atributo propio del modelo relacionado. Se intenta
     * primero el pivot y, si no existe, se cae al atributo directo.
     *
     * @param  \Illuminate\Support\Collection $relacion
     * @param  string                         $campo
     * @return array
     */
    protected static function pivot($relacion, $campo)
    {
        $salida = [];

        if (is_null($relacion)) {
            return $salida;
        }

        foreach ($relacion as $modelo) {

            $valor = null;

            if (!is_null($modelo->pivot) && isset($modelo->pivot->$campo)) {
                /* Relación belongsToMany: el valor vive en la tabla pivot. */
                $valor = $modelo->pivot->$campo;
            } elseif (isset($modelo->$campo)) {
                /* Relación hasMany directa: el valor es un atributo propio del modelo. */
                $valor = $modelo->$campo;
            }

            $salida[(int) $modelo->id] = self::normalizar($valor);
        }

        ksort($salida);

        return $salida;
    }

    /**
     * MySQL devuelve los decimales como string ("100.00", "100.000000") y el
     * casteo de Eloquent cambia según el modelo. Se normaliza todo a string con
     * 6 decimales para que 100 y "100.00" no cuenten como diferencia.
     *
     * @param  mixed $valor
     * @return string|null
     */
    protected static function normalizar($valor)
    {
        if (is_null($valor)) {
            return null;
        }

        if (is_bool($valor)) {
            return $valor ? '1' : '0';
        }

        if (is_numeric($valor)) {
            return number_format((float) $valor, 6, '.', '');
        }

        return (string) $valor;
    }

    /**
     * Diferencias entre dos fotos, en texto legible, para que cuando el test falle
     * se vea exactamente qué campo no se revirtió.
     *
     * @param  array $antes
     * @param  array $despues
     * @return string
     */
    public static function diferencias(array $antes, array $despues)
    {
        $lineas = [];

        foreach ($antes as $article_id => $fila) {

            if (!array_key_exists($article_id, $despues)) {
                $lineas[] = 'Articulo ' . $article_id . ': desapareció';
                continue;
            }

            foreach ($fila as $campo => $valor) {

                $valor_despues = array_key_exists($campo, $despues[$article_id])
                                    ? $despues[$article_id][$campo]
                                    : null;

                if ($valor !== $valor_despues) {
                    $lineas[] = 'Articulo ' . $article_id . ' campo ' . $campo . ': '
                                . json_encode($valor) . ' -> ' . json_encode($valor_despues);
                }
            }
        }

        foreach ($despues as $article_id => $fila) {
            if (!array_key_exists($article_id, $antes)) {
                $lineas[] = 'Articulo ' . $article_id . ': quedó creado y no se borró';
            }
        }

        return implode("\n", $lineas);
    }
}
