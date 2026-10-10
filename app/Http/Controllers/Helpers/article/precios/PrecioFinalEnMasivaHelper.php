<?php

namespace App\Http\Controllers\Helpers\article\precios;

use App\Models\Provider;

/**
 * "Subile X % el precio" en una actualización masiva (misión asistente-masiva-precio-manual,
 * 10/10/2026): la fuente única de "¿cómo se calcula el precio de ESTE artículo?" para la masiva
 * (MasiveUpdateHelper::apply_form_change()) y para la propuesta del asistente
 * (PropuestaActualizacionMasivaIaHelper::proponer()).
 *
 * POR QUÉ EXISTE. La masiva solo sabía subir columnas: "precio manual sube 10 %" sobre artículos que
 * calculan con costo + margen guardaba `price = 0` (null * 10 / 100) sin contarlo como cambio, y el
 * precio que se cobra no se movía. Medido en demo (4.3.8) con "subile 10 % a Adhesivos y
 * selladores": masiva completada, 0 afectados, 0 cambios. Esta operación sube el precio FINAL:
 *
 *  - En un artículo de precio manual, sube ese precio.
 *  - En uno de costo + margen, recalcula SU margen para que el precio final suba exactamente X %.
 *  - En uno sin costo ni precio, no hace nada (y no escribe nada).
 *
 * 🔴 ESPEJA ArticleHelper::setFinalPrice() (precio único). Si cambia la regla de allá, cambia acá:
 *
 *  - manda_el_margen() es la condición del bloque "Pongo el precio en blanco si corresponde"
 *    (`!is_null(percentage_gain) && percentage_gain > 0`, o costo + apply_provider_percentage_gain
 *    + proveedor con percentage_gain): cuando se cumple, setFinalPrice() pone `price` en null.
 *  - modo() MANUAL es la negación de la condición del `if` que arma el precio calculado
 *    (`is_null(price) || price == ''`): sin margen que mande y con `price` cargado, el precio final
 *    ES el precio manual (la rama `else` de setFinalPrice()).
 *  - nuevo_margen() es exacto porque el margen del artículo se aplica como
 *    `final_price += final_price * percentage_gain / 100` y todo lo que viene después en
 *    setFinalPrice() es multiplicativo (recargos y descuentos porcentuales, IIBB por división, IVA).
 *    El redondeo del comercio (redondear()) se aplica después, igual que siempre.
 *
 * Con LISTAS DE PRECIO el precio manual no se usa nunca y el margen del artículo no mueve las
 * listas: esta clase no mira listas, eso lo decide quien llama (la propuesta del asistente, con
 * listas, sube el costo en vez de usar esta operación).
 *
 * La clave del update_form empieza con `increment_` / `decrement_` a propósito: el historial de la
 * SPA (MasiveUpdateHistory.vue) arma el renglón como `label + ': ' + valor + ' %'` + "(redondeado)"
 * para esas claves. El `type` distinto de `number` es lo que evita que la agarren las ramas
 * genéricas de apply_form_change(), que harían `$model->precio_final = ...`.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class PrecioFinalEnMasivaHelper
{
    /** `type` del ítem del update_form. */
    const TIPO = 'precio_final';

    /** `key` del ítem para subir el precio final. */
    const SUBIR = 'increment_precio_final';

    /** `key` del ítem para bajar el precio final. */
    const BAJAR = 'decrement_precio_final';

    /** `label` del ítem (lo lee el historial de la SPA). */
    const LABEL_SUBIR = 'Aumentar el precio final';

    const LABEL_BAJAR = 'Disminuir el precio final';

    /** El precio final es el precio cargado a mano. */
    const MODO_MANUAL = 'manual';

    /** El precio final sale del costo + margen. */
    const MODO_CALCULADO = 'calculado';

    /** No tiene costo ni precio cargado: no hay precio que mover. */
    const MODO_SIN_PRECIO = 'sin_precio';

    /**
     * Si el ítem del update_form es esta operación.
     *
     * @param  mixed  $form
     * @return bool
     */
    public static function es_de_la_masiva($form)
    {
        return is_array($form)
            && isset($form['type'])
            && $form['type'] === self::TIPO
            && isset($form['key'])
            && in_array($form['key'], [self::SUBIR, self::BAJAR], true);
    }

    /**
     * Si el precio final de este artículo lo manda el margen: la misma condición del bloque "Pongo
     * el precio en blanco si corresponde" de ArticleHelper::setFinalPrice(). Cuando da true,
     * setFinalPrice() pone `price` en null y el precio manual no cuenta.
     *
     * @param  \App\Models\Article  $article
     * @param  \ArrayObject|null    $memoria  La memoria de la corrida de la masiva
     *                                        (CatalogoPorListaHelper::nueva_memoria_de_corrida()).
     *                                        Con ella, el margen del proveedor se lee UNA vez por
     *                                        proveedor y no una consulta por artículo (ver
     *                                        proveedor_tiene_margen()). Sin ella se usa la relación
     *                                        `provider`, que quien llama debería traer cargada.
     * @return bool
     */
    public static function manda_el_margen($article, $memoria = null)
    {
        if (!is_null($article->percentage_gain) && (float) $article->percentage_gain > 0) {
            return true;
        }

        return !is_null($article->cost)
            && $article->apply_provider_percentage_gain
            && self::proveedor_tiene_margen($article, $memoria);
    }

    /**
     * Cómo se calcula el precio final de este artículo: MANUAL, CALCULADO o SIN_PRECIO.
     *
     * MANUAL usa la misma expresión que setFinalPrice() para decidir si usa el precio manual. Un
     * precio manual en 0 ("0.00" == '' es falso en PHP 7.4) sigue siendo MANUAL: el que llama decide
     * que con 0 no hay nada que subir. No mira listas de precio (ver la clase).
     *
     * @param  \App\Models\Article  $article
     * @param  \ArrayObject|null    $memoria  Ver manda_el_margen().
     * @return string
     */
    public static function modo($article, $memoria = null)
    {
        if (!self::manda_el_margen($article, $memoria) && !(is_null($article->price) || $article->price == '')) {
            return self::MODO_MANUAL;
        }

        if (is_null($article->cost) || (float) $article->cost <= 0) {
            return self::MODO_SIN_PRECIO;
        }

        return self::MODO_CALCULADO;
    }

    /**
     * El margen que hace que el precio final suba (o baje) exactamente el porcentaje pedido.
     *
     * El margen del artículo multiplica el precio por (1 + margen / 100) y todo lo que viene después
     * en setFinalPrice() es multiplicativo, así que para que el final quede multiplicado por
     * (1 ± p / 100) alcanza con que el factor del margen se multiplique por lo mismo. A 2 decimales,
     * que es lo que guarda `articles.percentage_gain` (decimal(8,2)).
     *
     * @param  float|string|null  $margen_actual  null cuenta como 0.
     * @param  float              $porcentaje
     * @param  bool               $sube
     * @return float
     */
    public static function nuevo_margen($margen_actual, $porcentaje, $sube)
    {
        $margen = is_null($margen_actual) ? 0 : (float) $margen_actual;

        $factor = $sube ? 1 + (float) $porcentaje / 100 : 1 - (float) $porcentaje / 100;

        return round(((1 + $margen / 100) * $factor - 1) * 100, 2);
    }

    /**
     * Si cambiar el precio manual le mueve el precio final a este artículo (precio único; con listas
     * de precio no se lo mueve a ninguno, y eso lo decide quien llama).
     *
     *  - setear: sí, si no manda el margen (setFinalPrice() usaría el precio que se fija).
     *  - subir/bajar: solo si hoy usa precio manual y es mayor a 0 (un porcentaje de nada es nada).
     *
     * @param  \App\Models\Article  $article
     * @param  bool                 $es_setear
     * @return bool
     */
    public static function el_precio_manual_lo_mueve($article, $es_setear)
    {
        if ($es_setear) {
            return !self::manda_el_margen($article);
        }

        return self::modo($article) === self::MODO_MANUAL && (float) $article->price > 0;
    }

    /**
     * Aplica el ítem a un artículo y devuelve el cambio con la forma de
     * MasiveUpdateHelper::apply_form_change() (prop_key, old_value, new_value, operation, form_key),
     * o null si no hubo cambio. La reversión de siempre lo restaura: es una columna de `articles`.
     *
     *  - MANUAL con precio > 0: `price * (1 ± p / 100)`, redondeado a entero con `round` (si no, a los
     *    2 decimales de la columna, así la comparación de abajo mira lo mismo que queda guardado).
     *  - CALCULADO: `percentage_gain` = nuevo_margen(). `round` no aplica: el redondeo del precio lo
     *    hace después el del comercio, como siempre.
     *  - SIN_PRECIO, o MANUAL en 0: null, sin escribir nada.
     *
     * El precio final NO se calcula acá: process_update() recalcula el artículo con su tanda.
     *
     * @param  \App\Models\Article  $model
     * @param  array                $form
     * @param  \ArrayObject|null    $memoria_de_la_corrida  Ver manda_el_margen().
     * @return array|null
     */
    public static function aplicar($model, $form, $memoria_de_la_corrida = null)
    {
        if (!self::es_de_la_masiva($form) || !isset($form['value']) || !is_numeric($form['value'])) {
            return null;
        }

        $porcentaje = (float) $form['value'];
        $sube = $form['key'] === self::SUBIR;

        if ($porcentaje <= 0 || (!$sube && $porcentaje >= 100)) {
            return null;
        }

        $modo = self::modo($model, $memoria_de_la_corrida);

        if ($modo === self::MODO_MANUAL && (float) $model->price > 0) {

            $prop_key = 'price';
            $nuevo = (float) $model->price * ($sube ? 1 + $porcentaje / 100 : 1 - $porcentaje / 100);
            $nuevo = !empty($form['round']) ? round($nuevo, 0, PHP_ROUND_HALF_UP) : round($nuevo, 2);

            /*
             * Un precio manual chico que al bajar y redondear queda en 0 no se escribe: un precio
             * manual de $0 es un artículo que se regala (setFinalPrice() lo usaría tal cual).
             */
            if ($nuevo <= 0) {
                return null;
            }

        } elseif ($modo === self::MODO_CALCULADO) {

            $prop_key = 'percentage_gain';
            $nuevo = self::nuevo_margen($model->percentage_gain, $porcentaje, $sube);

        } else {
            return null;
        }

        $old_value = $model->{$prop_key};

        $model->{$prop_key} = $nuevo;
        $model->save();

        if ($old_value == $model->{$prop_key}) {
            return null;
        }

        return [
            'prop_key'  => $prop_key,
            'old_value' => $old_value,
            'new_value' => $model->{$prop_key},
            'operation' => $sube ? 'increment' : 'decrement',
            'form_key'  => $form['key'],
        ];
    }

    /**
     * Cuántos de los artículos sube (o baja) esta operación y por qué camino, para los renglones de
     * la tarjeta del asistente.
     *
     *  - manual: precio manual mayor a 0.
     *  - calculado: costo + margen.
     *  - sin_precio: sin costo ni precio, o con precio manual en 0. No cambian.
     *  - margen_negativo: de los calculados, los que quedarían con margen menor a 0 (solo al bajar).
     *
     * Los artículos tienen que venir con `provider` cargado (ver manda_el_margen()).
     *
     * @param  iterable  $articulos
     * @param  float     $porcentaje
     * @param  bool      $sube
     * @return array{manual: int, calculado: int, sin_precio: int, margen_negativo: int}
     */
    public static function clasificar($articulos, $porcentaje, $sube)
    {
        $conteo = [
            'manual'          => 0,
            'calculado'       => 0,
            'sin_precio'      => 0,
            'margen_negativo' => 0,
        ];

        foreach ($articulos as $articulo) {

            $modo = self::modo($articulo);

            if ($modo === self::MODO_MANUAL && (float) $articulo->price > 0) {

                $conteo['manual']++;

            } elseif ($modo === self::MODO_CALCULADO) {

                $conteo['calculado']++;

                if (!$sube && self::nuevo_margen($articulo->percentage_gain, $porcentaje, false) < 0) {
                    $conteo['margen_negativo']++;
                }

            } else {

                $conteo['sin_precio']++;
            }
        }

        return $conteo;
    }

    /**
     * Si el proveedor del artículo tiene margen (`percentage_gain` no null): la segunda mitad de la
     * condición de setFinalPrice().
     *
     * Con la memoria de la corrida (la masiva), el margen se lee por `provider_id` y se recuerda: una
     * consulta por proveedor distinto en toda la corrida, y no una por artículo. process_update()
     * resuelve los artículos con find() de a uno, sin la relación cargada, así que leer
     * `$article->provider` acá serían hasta 3000 SELECT extra. Por id y no por la relación también
     * porque un ítem anterior de la misma masiva pudo haber cambiado el `provider_id` y dejado la
     * relación vieja en memoria. El scope de SoftDeletes de Provider aplica igual que en la
     * relación: un proveedor borrado no tiene margen.
     *
     * Sin memoria (la propuesta del asistente, que trae los artículos con `provider` cargado) se usa
     * la relación, igual que setFinalPrice().
     *
     * @param  \App\Models\Article  $article
     * @param  \ArrayObject|null    $memoria
     * @return bool
     */
    protected static function proveedor_tiene_margen($article, $memoria = null)
    {
        if (is_null($article->provider_id)) {
            return false;
        }

        if (is_null($memoria)) {
            return !is_null($article->provider) && !is_null($article->provider->percentage_gain);
        }

        $clave = 'precio_final:margen_del_proveedor:' . (int) $article->provider_id;

        if (!$memoria->offsetExists($clave)) {
            $memoria[$clave] = Provider::where('id', $article->provider_id)->value('percentage_gain');
        }

        return !is_null($memoria[$clave]);
    }
}
