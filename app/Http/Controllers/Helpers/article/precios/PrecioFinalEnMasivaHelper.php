<?php

namespace App\Http\Controllers\Helpers\article\precios;

use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\combo\ComboCalculadoHelper;
use App\Models\Provider;
use App\Models\User;

/**
 * "Subile X % el precio" en una actualización masiva (misión asistente-masiva-precio-manual,
 * 10/10/2026): la fuente única de "¿con qué se mueve el precio de ESTE artículo?" para la masiva
 * (MasiveUpdateHelper::apply_form_change()) y para la propuesta del asistente
 * (PropuestaActualizacionMasivaIaHelper::proponer()).
 *
 * POR QUÉ EXISTE. La masiva solo sabía subir columnas: "precio manual sube 10 %" sobre artículos que
 * calculan con costo + margen guardaba `price = 0` (null * 10 / 100) sin contarlo como cambio, y el
 * precio que se cobra no se movía. Medido en demo (4.3.8) con "subile 10 % a Adhesivos y
 * selladores": masiva completada, 0 afectados, 0 cambios. Esta operación sube el precio FINAL, y la
 * PALANCA con la que lo mueve se decide por artículo, en el momento de correr, con el comercio a mano
 * (regla de Lucas: "donde el margen no mueve el precio, se sube el costo"):
 *
 *  - PRECIO MANUAL (sin listas de precio, sin margen que mande y con precio > 0): sube ese precio.
 *  - COSTO: calculado con costo y, o el comercio trabaja con listas (`listas_de_precio`, o la
 *    extensión `lista_de_precios_por_categoria`: las dos salen de la base de antes del margen del
 *    artículo), o el proveedor tiene `price_from_cost_mas_iva` (precio = costo de lista + IVA, el
 *    margen no entra). Sube el costo y los márgenes quedan iguales.
 *  - MARGEN: calculado con costo en los demás casos. Recalcula el margen del artículo para que el
 *    precio final suba X %.
 *  - Nada: sin costo ni precio, o con el precio manual en $0. No escribe nada.
 *
 * Que la palanca se decida al correr (y no al proponer) es a propósito: si el comercio cambia de modo
 * entre la tarjeta y el clic, la masiva igual hace lo correcto.
 *
 * 🔴 ESPEJA ArticleHelper::setFinalPrice() (precio único). Si cambia la regla de allá, cambia acá:
 *
 *  - manda_el_margen() es la condición del bloque "Pongo el precio en blanco si corresponde"
 *    (`!is_null(percentage_gain) && percentage_gain > 0`, o costo + apply_provider_percentage_gain
 *    + proveedor con percentage_gain): cuando se cumple, setFinalPrice() pone `price` en null.
 *  - La palanca PRECIO MANUAL es la negación de la condición del `if` que arma el precio calculado
 *    (`is_null(price) || price == '' || uses_listas_de_precio`): sin listas, sin margen que mande y
 *    con `price` cargado, el precio final ES el precio manual (la rama `else` de setFinalPrice()).
 *  - La palanca COSTO para `price_from_cost_mas_iva` es la rama `$usar_lista_mas_iva` de
 *    setFinalPrice(), y la de listas son las dos formas que setFinalPrice() trata como listas.
 *
 * CUÁNTO SUBE DE VERDAD. El margen y el costo multiplican el precio, y casi todo lo que viene después
 * en setFinalPrice() también (recargos y descuentos porcentuales, IIBB por división, IVA), así que el
 * precio final sube X % con tres salvedades: los recargos y descuentos en PESOS (no porcentuales) no
 * escalan, el margen se guarda con 2 decimales (decimal(8,2): la suba puede diferir en centésimas de
 * punto) y el redondeo del comercio (redondear()) se aplica después, igual que siempre.
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

    /** Palanca: sube el precio cargado a mano. */
    const PALANCA_PRECIO_MANUAL = 'precio_manual';

    /** Palanca: ajusta el margen del artículo. */
    const PALANCA_MARGEN = 'margen';

    /** Palanca: sube el costo (los márgenes quedan iguales). */
    const PALANCA_COSTO = 'costo';

    /** Sin palanca: no tiene costo ni precio cargado. */
    const SIN_PRECIO = 'sin_precio';

    /** Sin palanca: el precio manual está en $0 (un porcentaje de nada es nada). */
    const PRECIO_MANUAL_EN_CERO = 'precio_manual_en_cero';

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
     * Lo que la palanca (y la tarjeta) necesitan saber del comercio: si trabaja con listas de precio,
     * si tiene la extensión de listas por categoría y si tiene la de precios en blanco
     * (`articulos_precios_en_blanco`, la que mira setFinalPrice() para llamar a
     * ArticlePricesHelper::set_precios_en_blanco(), que calcula el precio en blanco desde el costo y
     * el margen en blanco, sin el margen del artículo). Con la memoria de la corrida se resuelve UNA
     * vez por corrida.
     *
     * Sin dueño no se le pasa null a UserHelper: caería en Auth, que en la cola no existe. Se toma
     * como un comercio sin listas ni extensiones.
     *
     * @param  \App\Models\User|null  $owner
     * @param  \ArrayObject|null      $memoria
     * @return array{listas_de_precio: bool, listas_por_categoria: bool, precios_en_blanco: bool}
     */
    public static function comercio($owner, $memoria = null)
    {
        if (is_null($owner)) {
            return ['listas_de_precio' => false, 'listas_por_categoria' => false, 'precios_en_blanco' => false];
        }

        $clave = 'precio_final:comercio:' . (int) $owner->id;

        if (!is_null($memoria) && $memoria->offsetExists($clave)) {
            return $memoria[$clave];
        }

        $comercio = [
            'listas_de_precio'     => UserHelper::uses_listas_de_precio($owner),
            'listas_por_categoria' => UserHelper::hasExtencion('lista_de_precios_por_categoria', $owner),
            'precios_en_blanco'    => UserHelper::hasExtencion('articulos_precios_en_blanco', $owner),
        ];

        if (!is_null($memoria)) {
            $memoria[$clave] = $comercio;
        }

        return $comercio;
    }

    /**
     * Si el precio final de este artículo lo manda el margen: la misma condición del bloque "Pongo
     * el precio en blanco si corresponde" de ArticleHelper::setFinalPrice(). Cuando da true,
     * setFinalPrice() pone `price` en null y el precio manual no cuenta.
     *
     * @param  \App\Models\Article  $article
     * @param  \ArrayObject|null    $memoria  La memoria de la corrida de la masiva. Con ella, el
     *                                        proveedor se lee UNA vez por proveedor (ver
     *                                        datos_del_proveedor()); sin ella se usa la relación
     *                                        `provider`, que quien llama debería traer cargada.
     * @return bool
     */
    public static function manda_el_margen($article, $memoria = null)
    {
        if (!is_null($article->percentage_gain) && (float) $article->percentage_gain > 0) {
            return true;
        }

        if (is_null($article->cost) || !$article->apply_provider_percentage_gain) {
            return false;
        }

        $proveedor = self::datos_del_proveedor($article, $memoria);

        return !is_null($proveedor) && !is_null($proveedor['percentage_gain']);
    }

    /**
     * Con qué se mueve el precio final de este artículo: PALANCA_PRECIO_MANUAL, PALANCA_COSTO,
     * PALANCA_MARGEN, o SIN_PRECIO / PRECIO_MANUAL_EN_CERO si no hay nada que mover. Ver la clase.
     *
     * @param  \App\Models\Article  $article
     * @param  array                $comercio  Lo que devuelve comercio().
     * @param  \ArrayObject|null    $memoria   Ver manda_el_margen().
     * @return string
     */
    public static function palanca($article, array $comercio, $memoria = null)
    {
        $con_listas = !empty($comercio['listas_de_precio']);

        // Con listas de precio setFinalPrice() nunca usa el precio manual: todo sale del costo.
        if (
            !$con_listas
            && !self::manda_el_margen($article, $memoria)
            && !(is_null($article->price) || $article->price == '')
        ) {
            return (float) $article->price > 0 ? self::PALANCA_PRECIO_MANUAL : self::PRECIO_MANUAL_EN_CERO;
        }

        if (is_null($article->cost) || (float) $article->cost <= 0) {
            return self::SIN_PRECIO;
        }

        if ($con_listas || !empty($comercio['listas_por_categoria'])) {
            return self::PALANCA_COSTO;
        }

        $proveedor = self::datos_del_proveedor($article, $memoria);

        if (!is_null($proveedor) && $proveedor['price_from_cost_mas_iva']) {
            return self::PALANCA_COSTO;
        }

        return self::PALANCA_MARGEN;
    }

    /**
     * El margen que hace que el precio final suba (o baje) el porcentaje pedido.
     *
     * El margen del artículo multiplica el precio por (1 + margen / 100), así que para que el final
     * quede multiplicado por (1 ± p / 100) alcanza con que el factor del margen se multiplique por lo
     * mismo. A 2 decimales, que es lo que guarda `articles.percentage_gain` (decimal(8,2)): por eso la
     * suba puede diferir del porcentaje pedido en centésimas de punto.
     *
     * @param  float|string|null  $margen_actual  null cuenta como 0.
     * @param  float              $porcentaje
     * @param  bool               $sube
     * @return float
     */
    public static function nuevo_margen($margen_actual, $porcentaje, $sube)
    {
        $margen = is_null($margen_actual) ? 0 : (float) $margen_actual;

        return round(((1 + $margen / 100) * self::factor($porcentaje, $sube) - 1) * 100, 2);
    }

    /**
     * El precio manual nuevo: redondeado a entero si se pide, y si no a los 2 decimales de la columna
     * (`articles.price` es decimal(22,2)), así la comparación de "hubo cambio" mira lo mismo que queda
     * guardado.
     *
     * @param  float|string  $precio
     * @param  float         $porcentaje
     * @param  bool          $sube
     * @param  bool          $redondear
     * @return float
     */
    public static function nuevo_precio_manual($precio, $porcentaje, $sube, $redondear)
    {
        $nuevo = (float) $precio * self::factor($porcentaje, $sube);

        return $redondear ? round($nuevo, 0, PHP_ROUND_HALF_UP) : round($nuevo, 2);
    }

    /**
     * Si cambiar el precio manual le mueve el precio final a este artículo.
     *
     *  - Con listas de precio, a ninguno: setFinalPrice() no usa el precio manual.
     *  - setear: sí, si no manda el margen (setFinalPrice() usaría el precio que se fija).
     *  - subir/bajar: solo si hoy usa precio manual y es mayor a 0 (un porcentaje de nada es nada).
     *
     * @param  \App\Models\Article  $article
     * @param  bool                 $es_setear
     * @param  array                $comercio  Lo que devuelve comercio().
     * @return bool
     */
    public static function el_precio_manual_lo_mueve($article, $es_setear, array $comercio)
    {
        if (!empty($comercio['listas_de_precio'])) {
            return false;
        }

        if ($es_setear) {
            return !self::manda_el_margen($article);
        }

        return self::palanca($article, $comercio) === self::PALANCA_PRECIO_MANUAL;
    }

    /**
     * Aplica el ítem a un artículo y devuelve el cambio con la forma de
     * MasiveUpdateHelper::apply_form_change() (prop_key, old_value, new_value, operation, form_key),
     * o null si no hubo cambio. La reversión de siempre lo restaura: es una columna de `articles`.
     *
     *  - PRECIO MANUAL: nuevo_precio_manual() (con `round`, a entero). Si al bajar y redondear queda
     *    en 0 no se escribe: un precio manual de $0 es un artículo que se regala.
     *  - MARGEN: `percentage_gain` = nuevo_margen(). `round` no aplica: el redondeo del precio lo hace
     *    después el del comercio, como siempre.
     *  - COSTO: `cost` × el factor, a los 6 decimales de la columna. `round` no aplica.
     *  - Sin palanca: null, sin escribir nada.
     *
     * El precio final NO se calcula acá: process_update() recalcula el artículo con su tanda.
     *
     * @param  \App\Models\Article     $model
     * @param  array                   $form
     * @param  \App\Models\User|null   $owner    El dueño del comercio (la masiva corre en cola, sin
     *                                           sesión). Sin él se busca por el `user_id` del artículo.
     * @param  \ArrayObject|null       $memoria_de_la_corrida  Ver manda_el_margen() y comercio().
     * @return array|null
     */
    public static function aplicar($model, $form, $owner = null, $memoria_de_la_corrida = null)
    {
        if (!self::es_de_la_masiva($form) || !isset($form['value']) || !is_numeric($form['value'])) {
            return null;
        }

        $porcentaje = (float) $form['value'];
        $sube = $form['key'] === self::SUBIR;

        if ($porcentaje <= 0 || (!$sube && $porcentaje >= 100)) {
            return null;
        }

        if (is_null($owner)) {
            $owner = self::dueno_del_articulo($model, $memoria_de_la_corrida);
        }

        $palanca = self::palanca($model, self::comercio($owner, $memoria_de_la_corrida), $memoria_de_la_corrida);

        if ($palanca === self::PALANCA_PRECIO_MANUAL) {

            $prop_key = 'price';
            $nuevo = self::nuevo_precio_manual($model->price, $porcentaje, $sube, !empty($form['round']));

            if ($nuevo <= 0) {
                return null;
            }

        } elseif ($palanca === self::PALANCA_MARGEN) {

            $prop_key = 'percentage_gain';
            $nuevo = self::nuevo_margen($model->percentage_gain, $porcentaje, $sube);

        } elseif ($palanca === self::PALANCA_COSTO) {

            $prop_key = 'cost';
            $nuevo = round((float) $model->cost * self::factor($porcentaje, $sube), 6);

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
     * Cuántos artículos mueve cada palanca, para los renglones de la tarjeta del asistente.
     *
     *  - precio_manual, margen, costo: cuántos se mueven por cada una.
     *  - sin_precio, precio_manual_en_cero: los que no cambian.
     *  - debajo_del_costo: SOLO AL BAJAR, los que quedarían con el precio por debajo de lo que cuesta
     *    una unidad, en pesos. El precio contra el que se mide es por unidad y en pesos (`base_margen`
     *    sale de ArticleHelper::calcular_base_antes_de_listas(), que divide por
     *    `unidades_individuales` y cotiza el dólar), así que el costo también: el de
     *    ComboCalculadoHelper::costo_unitario_de_articulo(), la misma regla que
     *    SaleHelper::getCost() sin venta (costo real ÷ unidades, cotizado si está en dólares).
     *    Margen → `base_margen × (1 + margen nuevo / 100)`; precio manual → el precio nuevo. La
     *    palanca de costo no cambia márgenes: no cuenta. Sin `base_margen`, sin costo o sin el dueño
     *    (la cotización lo necesita) no se puede medir y no cuenta.
     *  - ids_costo: los ids de los que suben el costo (para mirar sus listas fijadas a mano).
     *
     * Los artículos tienen que venir con `provider` (la cotización lee su dólar), `base_margen`,
     * `costo_real`, `unidades_individuales` y `cost_in_dollars` cargados.
     *
     * @param  iterable               $articulos
     * @param  float                  $porcentaje
     * @param  bool                   $sube
     * @param  array                  $comercio   Lo que devuelve comercio().
     * @param  bool                   $redondear  Si el precio manual se redondea a entero.
     * @param  \App\Models\User|null  $owner      El dueño (su dólar y si cotiza en dólares).
     * @return array
     */
    public static function clasificar($articulos, $porcentaje, $sube, array $comercio, $redondear = false, $owner = null)
    {
        $conteo = [
            self::PALANCA_PRECIO_MANUAL => 0,
            self::PALANCA_MARGEN        => 0,
            self::PALANCA_COSTO         => 0,
            self::SIN_PRECIO            => 0,
            self::PRECIO_MANUAL_EN_CERO => 0,
            'debajo_del_costo'          => 0,
            'ids_costo'                 => [],
        ];

        // Subir el precio no deja a nadie por debajo de su costo (y un artículo que ya lo estaba no es
        // obra de esta masiva).
        $medir_el_costo = !$sube && !is_null($owner);

        foreach ($articulos as $articulo) {

            $palanca = self::palanca($articulo, $comercio);

            $conteo[$palanca]++;

            if ($palanca === self::PALANCA_COSTO) {

                $conteo['ids_costo'][] = (int) $articulo->id;

                continue;
            }

            if (!$medir_el_costo) {
                continue;
            }

            if ($palanca === self::PALANCA_MARGEN && !is_null($articulo->base_margen)) {

                $costo_unitario = ComboCalculadoHelper::costo_unitario_de_articulo($articulo, $owner);
                $nuevo = self::nuevo_margen($articulo->percentage_gain, $porcentaje, $sube);

                if ($costo_unitario > 0 && (float) $articulo->base_margen * (1 + $nuevo / 100) < $costo_unitario) {
                    $conteo['debajo_del_costo']++;
                }

            } elseif ($palanca === self::PALANCA_PRECIO_MANUAL) {

                $costo_unitario = ComboCalculadoHelper::costo_unitario_de_articulo($articulo, $owner);

                if ($costo_unitario > 0 && self::nuevo_precio_manual($articulo->price, $porcentaje, $sube, $redondear) < $costo_unitario) {
                    $conteo['debajo_del_costo']++;
                }
            }
        }

        return $conteo;
    }

    /**
     * (1 ± p / 100).
     *
     * @param  float  $porcentaje
     * @param  bool   $sube
     * @return float
     */
    protected static function factor($porcentaje, $sube)
    {
        return $sube ? 1 + (float) $porcentaje / 100 : 1 - (float) $porcentaje / 100;
    }

    /**
     * Lo que la palanca mira del proveedor del artículo: su margen y si calcula costo de lista + IVA.
     * null si no tiene proveedor (o está borrado).
     *
     * Con la memoria de la corrida (la masiva), se lee por `provider_id` en UNA consulta con las dos
     * columnas y se recuerda: una consulta por proveedor distinto en toda la corrida, y no una por
     * artículo. process_update() resuelve los artículos con find() de a uno, sin la relación cargada,
     * así que leer `$article->provider` acá serían hasta 3000 SELECT extra. Por id y no por la
     * relación también porque un ítem anterior de la misma masiva pudo haber cambiado el
     * `provider_id` y dejado la relación vieja en memoria. El scope de SoftDeletes de Provider aplica
     * igual que en la relación: un proveedor borrado no cuenta.
     *
     * Sin memoria (la propuesta del asistente, que trae los artículos con `provider` cargado) se usa
     * la relación, igual que setFinalPrice().
     *
     * @param  \App\Models\Article  $article
     * @param  \ArrayObject|null    $memoria
     * @return array{percentage_gain: mixed, price_from_cost_mas_iva: bool}|null
     */
    protected static function datos_del_proveedor($article, $memoria = null)
    {
        if (is_null($article->provider_id)) {
            return null;
        }

        if (is_null($memoria)) {

            $proveedor = $article->provider;

            return is_null($proveedor) ? null : [
                'percentage_gain'         => $proveedor->percentage_gain,
                'price_from_cost_mas_iva' => (bool) $proveedor->price_from_cost_mas_iva,
            ];
        }

        $clave = 'precio_final:proveedor:' . (int) $article->provider_id;

        if (!$memoria->offsetExists($clave)) {

            $fila = Provider::where('id', $article->provider_id)->first(['percentage_gain', 'price_from_cost_mas_iva']);

            // false y no null para "no existe": así el valor guardado nunca es ambiguo.
            $memoria[$clave] = is_null($fila) ? false : [
                'percentage_gain'         => $fila->percentage_gain,
                'price_from_cost_mas_iva' => (bool) $fila->price_from_cost_mas_iva,
            ];
        }

        return $memoria[$clave] === false ? null : $memoria[$clave];
    }

    /**
     * El dueño del artículo, para cuando aplicar() no recibe el comercio. Con la memoria, una sola
     * búsqueda por dueño en toda la corrida.
     *
     * @param  \App\Models\Article  $model
     * @param  \ArrayObject|null    $memoria
     * @return \App\Models\User|null
     */
    protected static function dueno_del_articulo($model, $memoria = null)
    {
        $clave = 'precio_final:dueno:' . (int) $model->user_id;

        if (!is_null($memoria) && $memoria->offsetExists($clave)) {
            return $memoria[$clave] === false ? null : $memoria[$clave];
        }

        $dueno = User::find($model->user_id);

        if (!is_null($memoria)) {
            $memoria[$clave] = is_null($dueno) ? false : $dueno;
        }

        return $dueno;
    }
}
