<?php

namespace App\Http\Controllers\Helpers;

/**
 * Criterio UNICO para decidir si un articulo se maneja por margen de ganancia o por
 * precio manual (mision 44, 12/8/2026).
 *
 * Antes de esta clase la misma decision estaba escrita cuatro veces con tres criterios
 * distintos -- dos en el front (PriceInput.vue y PercentageGainInput.vue) y uno en el
 * back (ArticleHelper::setFinalPrice()) -- y esas diferencias dejaban articulos con los
 * DOS inputs bloqueados y sin salida:
 *
 *   - percentage_gain = 0 con price cargado: el back no limpiaba el precio (0 no es > 0)
 *     y el front bloqueaba los dos inputs ("0.00" != '' es verdadero en JS).
 *   - articulo con proveedor con margen y apply_provider_percentage_gain pero SIN costo:
 *     el front bloqueaba el precio manual y el margen del proveedor no podia producir
 *     ningun precio, porque sin costo no hay a que aplicarle el porcentaje.
 *
 * La direccion de la alineacion es front -> back: el criterio que vale es el que ya tenia
 * setFinalPrice(), porque el del front borraria precios manuales cargados a mano ante un
 * margen de 0 y dejaria sin ningun precio a un articulo sin costo.
 *
 * La prioridad (margen propio > margen del proveedor > precio manual) es la misma que
 * aplica setFinalPrice() al poner el precio en blanco: si el articulo tiene un margen
 * aplicable, el precio manual no sobrevive.
 *
 * El espejo de esta clase en el front esta en:
 *   empresa-spa/src/components/listado/components/modal-props/PriceInput.vue
 *   empresa-spa/src/components/listado/components/modal-props/PercentageGainInput.vue
 * Si se cambia el criterio aca, hay que cambiarlo tambien alla (y al reves).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promocion de constructor, readonly, enum ni #[...].
 */
class CriterioDePrecioHelper
{
    /** El articulo se maneja por su propio margen de ganancia (percentage_gain > 0). */
    const MARGEN_PROPIO = 'margen_propio';

    /** El articulo se maneja por el margen del proveedor (necesita costo, ver resolver()). */
    const MARGEN_DEL_PROVEEDOR = 'margen_del_proveedor';

    /** El articulo se maneja por un precio cargado a mano (price > 0). */
    const PRECIO_MANUAL = 'precio_manual';

    /** El articulo no tiene ninguno de los dos cargados. */
    const NINGUNO = 'ninguno';

    /**
     * True si el valor representa un numero mayor a cero.
     *
     * null, cadena vacia, cadena no numerica y 0 (en cualquiera de sus formas: 0, "0",
     * "0.00") dan false. Es la unica forma de "hay un valor cargado" que se usa en toda
     * la clase: el bug de origen es justamente que el front trataba "0.00" como cargado.
     *
     * @param  mixed $valor
     * @return bool
     */
    public static function es_positivo($valor)
    {
        if (is_null($valor)) {
            return false;
        }

        if (is_string($valor)) {
            $valor = trim($valor);

            if ($valor === '' || !is_numeric($valor)) {
                return false;
            }
        }

        if (!is_numeric($valor)) {
            return false;
        }

        return (float) $valor > 0;
    }

    /**
     * Devuelve el valor normalizado para persistir en articles.percentage_gain /
     * articles.price: null si no hay un valor positivo cargado, el valor tal cual si lo hay.
     *
     * Es lo que impide que el estado sucio (margen en 0 conviviendo con un precio manual)
     * se vuelva a generar desde la interfaz. Un negativo NO se toca: no es el caso que esta
     * mision resuelve y convertirlo en null seria descartar en silencio un dato del usuario.
     *
     * @param  mixed $valor
     * @return mixed  null, o el mismo valor recibido
     */
    public static function normalizar($valor)
    {
        if (is_null($valor)) {
            return null;
        }

        if (is_string($valor) && trim($valor) === '') {
            return null;
        }

        if (is_numeric($valor) && (float) $valor == 0) {
            return null;
        }

        return $valor;
    }

    /**
     * Resuelve el modo de precio a partir de valores sueltos (sin modelo de por medio),
     * para poder usarse tanto sobre un Article como sobre los valores que van a quedar
     * DESPUES de aplicar una fila de Excel.
     *
     * @param  mixed $percentage_gain                margen propio del articulo
     * @param  mixed $price                          precio manual del articulo
     * @param  mixed $cost                           costo del articulo (sin costo no hay margen del proveedor aplicable)
     * @param  mixed $apply_provider_percentage_gain flag del articulo
     * @param  mixed $provider_percentage_gain       margen configurado en el proveedor del articulo
     * @return string  una de las cuatro constantes de esta clase
     */
    public static function resolver($percentage_gain, $price, $cost, $apply_provider_percentage_gain, $provider_percentage_gain)
    {
        if (self::es_positivo($percentage_gain)) {
            return self::MARGEN_PROPIO;
        }

        /*
         * El margen del proveedor necesita costo: sin costo no hay nada a que aplicarle el
         * porcentaje, asi que el articulo no queda "manejado por margen" y su precio manual
         * tiene que seguir siendo editable. Esta es la condicion que el front no tenia.
         *
         * provider_percentage_gain se compara con !is_null y no con es_positivo() para
         * seguir exactamente lo que hace setFinalPrice(): ahi el margen del proveedor
         * limpia el precio con solo NO ser null.
         */
        if (
            !is_null($cost)
            && $cost !== ''
            && $apply_provider_percentage_gain
            && !is_null($provider_percentage_gain)
            && $provider_percentage_gain !== ''
        ) {
            return self::MARGEN_DEL_PROVEEDOR;
        }

        if (self::es_positivo($price)) {
            return self::PRECIO_MANUAL;
        }

        return self::NINGUNO;
    }

    /**
     * Igual que resolver(), pero leyendo un Article ya cargado.
     *
     * @param  \App\Models\Article $article
     * @param  mixed               $provider  proveedor del articulo, si el llamador ya lo tiene
     *                                        resuelto (evita una query por articulo). Si es null,
     *                                        se usa la relacion del modelo tal como este cargada.
     * @return string
     */
    public static function desde_articulo($article, $provider = null)
    {
        if (is_null($provider)) {
            $provider = $article->provider;
        }

        $provider_percentage_gain = is_null($provider) ? null : $provider->percentage_gain;

        return self::resolver(
            $article->percentage_gain,
            $article->price,
            $article->cost,
            $article->apply_provider_percentage_gain,
            $provider_percentage_gain
        );
    }

    /**
     * True si el modo resuelto es alguno de los dos margenes.
     *
     * @param  string $modo
     * @return bool
     */
    public static function es_margen($modo)
    {
        return $modo === self::MARGEN_PROPIO || $modo === self::MARGEN_DEL_PROVEEDOR;
    }
}
