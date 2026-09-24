<?php

namespace App\Http\Controllers\Helpers;

/**
 * Criterio UNICO para decidir si una oferta por cantidad de un articulo
 * (`article_price_ranges`, lo que el ABM llama "Oferta por cantidad") fija un PRECIO ABSOLUTO o
 * descuenta un PORCENTAJE (mision oferta-por-cantidad-porcentaje, 24/9/2026).
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * 🔴 POR QUE ESTA CLASE EXISTE, Y POR QUE ES UNA CLASE Y NO UN if EN CADA LADO
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * Lucas pidio "uno o el otro": si el comercio carga el porcentaje se le deshabilita el monto, y al
 * reves. Eso es una REGLA DE EXCLUSION MUTUA, y esa clase de error ya rompio produccion en este
 * mismo repo: `CriterioDePrecioHelper` (margen de ganancia vs precio manual) nacio de un
 * `percentage_gain = 0` que caia en el hueco entre tres criterios distintos y dejaba al articulo
 * SIN NINGUNA FORMA de cambiarle el precio desde la interfaz. Esta clase es su hermana, y reusa
 * `es_positivo()` y `normalizar()` literalmente para no volver a abrir ese hueco.
 *
 * La regla esta escrita en CUATRO lugares del ecosistema y los cuatro tienen que coincidir borde
 * por borde:
 *
 *   1. Esta clase                                              -- empresa-api, quien PERSISTE
 *   2. empresa-spa/src/utils/criterio_de_oferta_por_cantidad.js -- el ABM y el ERP al vender
 *   3. tienda-api/.../ArticlePriceRangeHelper                  -- quien COBRA en la tienda
 *   4. tienda-spa/src/mixins/generals.js                       -- quien MUESTRA en la tienda
 *
 * Si difieren en un solo borde, el comprador ve un numero en la pantalla y le cobran otro. Es la
 * clase documentada en `APRENDER_NO_PARCHEAR.md`, "el mismo invariante decidido con dos criterios
 * distintos en front y back", y ya se midio lo que cuesta: la mision del 16/9/2026 encontro un
 * tramo que MOSTRABA $3.000 y COBRABA $3.948, sin error y sin log.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * LOS CRITERIOS, LITERALES
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 *   1. `price > 0`  -> PRECIO FIJO. Gana siempre, aunque tambien haya porcentaje.
 *      Es el valor explicito y absoluto, el numero que el comercio escribio pensando en un
 *      numero. Y es lo unico que existia hasta esta mision, asi que cualquier fila vieja de
 *      cualquier cliente sigue comportandose EXACTAMENTE igual que antes.
 *
 *   2. Si no, `porcentaje > 0 && porcentaje < 100` -> PORCENTAJE.
 *      El 100 queda AFUERA a proposito: dejaria el precio en cero, y un articulo regalado no es
 *      un descuento por cantidad, es un dato mal cargado. Mismo lado seguro que el `<= 0` del
 *      precio.
 *
 *   3. Cualquier otra cosa -> NINGUNO: la oferta NO aplica y la linea sale al precio normal.
 *      Nunca un default permisivo. Un valor que nadie escribio todavia no puede empezar a
 *      descontar plata solo.
 *
 * 🔴 El orden importa y no es cosmetico: quien elige el tramo GANADOR lo hace solo por `amount`,
 * SIN mirar estos valores, y recien sobre el ganador se pregunta el modo. Filtrar por modo antes
 * del desempate daria otro precio en el borde exacto de dos tramos, uno de ellos sin valor
 * usable. Esta medido y documentado en `ArticlePriceRangeHelper` de tienda-api.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe, argumentos nombrados, union types,
 * promocion de constructor, readonly, enum ni atributos.
 */
class CriterioDeOfertaPorCantidadHelper
{
    /** La oferta fija un precio unitario absoluto (`price` > 0). */
    const MODO_PRECIO_FIJO = 'precio_fijo';

    /** La oferta descuenta un porcentaje sobre el precio que la linea iba a tener. */
    const MODO_PORCENTAJE = 'porcentaje';

    /** La oferta no tiene ningun valor usable: no aplica. */
    const MODO_NINGUNO = 'ninguno';

    /**
     * True si el valor representa un numero mayor a cero.
     *
     * Delega en `CriterioDePrecioHelper` a proposito: es EL MISMO criterio de "hay un valor
     * cargado" que ya resolvio el bug hermano, con los mismos bordes (null, cadena vacia, "0",
     * "0.00", texto no numerico). Reescribirlo aca seria abrir de nuevo el hueco que esa clase
     * cerro.
     *
     * @param  mixed $valor
     * @return bool
     */
    public static function es_positivo($valor)
    {
        return CriterioDePrecioHelper::es_positivo($valor);
    }

    /**
     * True si el valor es un porcentaje de descuento USABLE: mayor a 0 y menor a 100.
     *
     * @param  mixed $valor
     * @return bool
     */
    public static function es_porcentaje_usable($valor)
    {
        if (!self::es_positivo($valor)) {
            return false;
        }

        return (float) $valor < 100;
    }

    /**
     * Resuelve el modo de una oferta a partir de sus dos valores sueltos, sin modelo de por medio
     * (asi sirve igual sobre una fila de la base, sobre el payload de un request o sobre lo que
     * va a quedar DESPUES de guardar).
     *
     * @param  mixed $price      el precio absoluto del tramo
     * @param  mixed $porcentaje el porcentaje de descuento del tramo
     * @return string  una de las tres constantes de esta clase
     */
    public static function resolver($price, $porcentaje)
    {
        if (self::es_positivo($price)) {
            return self::MODO_PRECIO_FIJO;
        }

        if (self::es_porcentaje_usable($porcentaje)) {
            return self::MODO_PORCENTAJE;
        }

        return self::MODO_NINGUNO;
    }

    /**
     * El precio unitario que le corresponde a una oferta, dado el precio que la linea IBA A TENER.
     *
     * 🔴 `$precio_base` es el precio de esa linea EN LA MISMA ESCALA en la que el llamador va a
     * usar el resultado. Esa es la decision de Lucas del 24/9/2026 y es lo que hace que la oferta
     * siga al precio: si el comercio cambia el precio del articulo, el precio de la oferta cambia
     * solo, sin tocar el tramo.
     *
     * @param  mixed $price       el precio absoluto del tramo
     * @param  mixed $porcentaje  el porcentaje de descuento del tramo
     * @param  mixed $precio_base el precio que la linea iba a tener
     * @return float|null  el precio unitario con la oferta aplicada, o null si la oferta no aplica
     */
    public static function precio($price, $porcentaje, $precio_base)
    {
        $modo = self::resolver($price, $porcentaje);

        if ($modo === self::MODO_PRECIO_FIJO) {
            return (float) $price;
        }

        if ($modo === self::MODO_PORCENTAJE) {

            /* Sin precio base no hay a que aplicarle el porcentaje. Devolver 0 seria regalar el
               articulo; devolver null lo manda al precio normal, que es el lado seguro. */
            if (!is_numeric($precio_base)) {
                return null;
            }

            return (float) $precio_base * (1 - ((float) $porcentaje / 100));
        }

        return null;
    }

    /**
     * El porcentaje como lo lee una persona: sin los decimales que no aportan y con coma decimal.
     *
     * `15.00` -> `'15'`, `12.50` -> `'12,5'`, `7.25` -> `'7,25'`. La columna es decimal(8,2), asi
     * que sin esto todo cartel diria "15.00% de descuento".
     *
     * Es el gemelo de `porcentaje_legible()` de `tienda-spa/src/mixins/generals.js` y del de
     * `empresa-spa/src/utils/criterio_de_oferta_por_cantidad.js`. Si cambia el formato de un lado,
     * cambia del otro: es el mismo numero anunciado al mismo comprador, en el cartel del local y
     * en la pantalla.
     *
     * 🔴 EL trim() NO ES DECORATIVO, y esto se midio (24/9/2026, chequeo cruzado contra el espejo
     * de JS sobre 33 bordes). `es_positivo()` hace trim antes de `is_numeric()`, pero
     * `is_numeric('15 ')` con un espacio AL FINAL es **false** en PHP 7.4 (acepta el espacio
     * adelante y no atras). Sin este trim, un porcentaje con un espacio al final resolvia
     * MODO_PORCENTAJE y DESCONTABA la plata, y este metodo devolvia cadena vacia: la hoja de
     * oferta para colgar en el local decia "= % de descuento", sin numero, mientras la venta si
     * hacia el descuento. Los dos criterios de "es un numero" tienen que ser EL MISMO adentro de
     * esta clase, no solo entre lenguajes.
     *
     * @param  mixed $valor
     * @return string
     */
    public static function porcentaje_legible($valor)
    {
        /* El mismo trim que hace es_positivo(), y por el mismo motivo. */
        if (is_string($valor)) {
            $valor = trim($valor);
        }

        if (!is_numeric($valor)) {
            return '';
        }

        /* rtrim de los ceros a la derecha y despues del punto que queda suelto: 15.00 -> "15",
           12.50 -> "12.5". El orden importa, con un solo rtrim("0.") un 100 quedaria en "1". */
        $texto = rtrim(rtrim(number_format((float) $valor, 2, '.', ''), '0'), '.');

        return str_replace('.', ',', $texto);
    }

    /**
     * Normaliza el PAR de valores antes de persistirlos: el modo que gana queda, el otro se
     * guarda en null.
     *
     * 🔴 Es lo que impide que el estado sucio (los dos cargados) NAZCA desde la interfaz, que es
     * exactamente el agujero por el que entro el bug hermano: el front bloqueaba los dos inputs
     * porque en la base habia quedado un par imposible que la interfaz sola no podia deshacer.
     *
     * Un valor no usable pero distinto de cero (por ejemplo un porcentaje de 150) se guarda TAL
     * CUAL si es el unico cargado: descartarlo en silencio seria borrarle un dato al usuario sin
     * avisarle. No aplica igual, porque `resolver()` lo manda a MODO_NINGUNO.
     *
     * @param  mixed $price
     * @param  mixed $porcentaje
     * @return array  ['price' => mixed|null, 'porcentaje' => mixed|null]
     */
    public static function normalizar_par($price, $porcentaje)
    {
        $price = CriterioDePrecioHelper::normalizar($price);
        $porcentaje = CriterioDePrecioHelper::normalizar($porcentaje);

        /* Con los dos cargados gana el precio fijo (criterio 1), asi que el porcentaje se limpia:
           guardarlo seria dejar en la fila un valor que nada va a leer nunca y que la proxima
           persona que abra el ABM va a interpretar como "esta oferta tiene porcentaje". */
        if (self::es_positivo($price)) {
            return array('price' => $price, 'porcentaje' => null);
        }

        if (self::es_positivo($porcentaje)) {
            return array('price' => null, 'porcentaje' => $porcentaje);
        }

        return array('price' => $price, 'porcentaje' => $porcentaje);
    }
}
