<?php

namespace App\Http\Controllers\Helpers\address;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Ajuste de precios de una sucursal (mision sucursal-recargo-descuento, 2/10/2026): normaliza,
 * valida y decide si se escribe el par de columnas `ajuste_precio_tipo` / `ajuste_precio_porcentaje`
 * de `addresses`. Es el unico lugar donde la API nombra esas dos columnas.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  QUE ES EL AJUSTE, EN UNA LINEA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Una sucursal puede llevar un recargo o un descuento en porcentaje que la SPA mete ADENTRO del
 *  precio de cada articulo, combo y promocion cuando el vendedor elige esa sucursal en Vender. La API
 *  NO calcula precios con el: solo lo persiste y lo devuelve (`GET address` ya trae todas las
 *  columnas). El renglon de una venta guarda el precio que manda la SPA, como siempre.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  LA INVARIANTE
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  O las dos columnas tienen un valor valido, o las dos son NULL:
 *
 *   - tipo `recargo`   + porcentaje mayor que 0 y hasta 999.99;
 *   - tipo `descuento` + porcentaje mayor que 0 y MENOR que 100 (un 100 % deja el precio en cero, y
 *     mas de 100 lo deja negativo);
 *   - las dos en NULL = la sucursal no lleva ajuste.
 *
 *  Cualquier otra combinacion NO se guarda: `normalizar()` la rechaza con un mensaje en espanol y el
 *  controlador responde 422 sin haber escrito nada. Se rechaza y no se "arregla en silencio" porque
 *  es plata: un 150 % de descuento tipeado por 15 no puede terminar guardado como otra cosa.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 POR QUE HAY GUARDA DE ESQUEMA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Un deploy de empresa sube los archivos ANTES de migrar (`DeploymentService::execute_steps()` de
 *  admin-api: `upload_api` -> `sync_env_keys` -> `run_migrations`). En esa ventana el cliente tiene
 *  este codigo y no tiene las columnas. Si `AddressController@store` o `@update` las nombraran a
 *  secas, crear o editar una sucursal daria un 500 (`Unknown column`) hasta que termine la
 *  migracion. Por eso NINGUN camino escribe las columnas sin preguntar antes `columnas_existen()`.
 *
 *  Mismo patron que `Helpers/sale/RecargosEnPreciosEsquemaHelper`.
 *
 *  Memoizada: `Schema::hasColumn()` es una consulta a `information_schema` y se pregunta una sola vez
 *  por proceso. En PHP-FPM el static muere con el request; el caso que podria quedar clavado en
 *  `false` es un `queue:work` booteado DENTRO de la ventana, y ese no escribe sucursales. Los tests
 *  llaman a `olvidar()` para volver a preguntar despues de esconder y devolver una columna.
 */
class AjusteDePreciosDeSucursalHelper {

    /** Columna del tipo de ajuste. */
    const COLUMNA_TIPO = 'ajuste_precio_tipo';

    /** Columna del porcentaje del ajuste. */
    const COLUMNA_PORCENTAJE = 'ajuste_precio_porcentaje';

    /** Valores validos de `ajuste_precio_tipo`. */
    const TIPO_RECARGO = 'recargo';
    const TIPO_DESCUENTO = 'descuento';

    /**
     * Valor que la SPA puede mandar en el tipo para decir "sin ajuste" (ademas de '' y null).
     * Se trata igual que vacio: quita el ajuste.
     */
    const TIPO_SIN_AJUSTE = 'sin_ajuste';

    /** Tope del recargo: la columna es DECIMAL(8,2), pero un recargo de seis cifras seria un error de tipeo. */
    const MAXIMO_RECARGO = 999.99;

    /** Cota (exclusiva) del descuento. */
    const COTA_DESCUENTO = 100;

    /**
     * Memo de la guarda de esquema. `null` = todavia no se pregunto en este proceso.
     *
     * Un solo booleano alcanza (una sola tabla), pero responde por las DOS columnas juntas: una base a
     * medio migrar con una sola de las dos no sirve para escribir el par, y la invariante (las dos o
     * ninguna) no se puede cumplir escribiendo una sola.
     *
     * @var bool|null
     */
    private static $existen = null;

    /**
     * ¿La tabla `addresses` ya tiene las dos columnas del ajuste?
     *
     * @return bool
     */
    static function columnas_existen() {

        if (is_null(self::$existen)) {
            self::$existen = Schema::hasColumn('addresses', self::COLUMNA_TIPO)
                          && Schema::hasColumn('addresses', self::COLUMNA_PORCENTAJE);
        }

        return self::$existen;
    }

    /**
     * Borra la memoizacion de la guarda.
     *
     * La usa el test de la guarda, que necesita que la respuesta se vuelva a preguntar despues de
     * esconder y devolver una columna. Sin esto, el memo de un test se le filtraria a todos los que
     * corren despues en el mismo proceso de PHPUnit.
     *
     * @return void
     */
    static function olvidar() {
        self::$existen = null;
    }

    /**
     * ¿El request trae alguna de las dos claves del ajuste?
     *
     * 🔴 ES LO QUE HACE QUE `update` NO PISE EL AJUSTE CON UNA SPA VIEJA. Una SPA anterior a esta
     * mision no conoce las claves y no las manda; si `update` escribiera el par "siempre", el primer
     * guardado de una sucursal desde esa SPA le borraria el ajuste en silencio (tipo y porcentaje a
     * NULL). Clave ausente = "el que manda no sabe nada del ajuste" = no se toca.
     *
     * `has()` y no `filled()`: una clave presente en null o vacia es la SPA nueva diciendo "quitale
     * el ajuste a esta sucursal", y eso si se escribe. (`ConvertEmptyStringsToNull` pasa los '' a
     * null pero la clave sigue estando.)
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    static function request_trae_ajuste(Request $request) {

        return $request->has(self::COLUMNA_TIPO) || $request->has(self::COLUMNA_PORCENTAJE);
    }

    /**
     * Lo que hay que hacer con el ajuste en un `store` / `update`, resuelto del request.
     *
     * Devuelve `null` cuando NO hay que tocar el ajuste: las columnas no existen todavia (ventana del
     * deploy) o el request no trae ninguna de las dos claves (SPA vieja). Un `null` no es un error:
     * el controlador sigue con lo suyo de siempre.
     *
     * Si hay que escribir, devuelve lo mismo que `normalizar()`.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|null
     */
    static function resolver_del_request(Request $request) {

        if (!self::columnas_existen() || !self::request_trae_ajuste($request)) {
            return null;
        }

        return self::normalizar(
            $request->input(self::COLUMNA_TIPO),
            $request->input(self::COLUMNA_PORCENTAJE)
        );
    }

    /**
     * Normaliza y valida el par (tipo, porcentaje) contra la invariante.
     *
     * Devuelve siempre un array con tres claves:
     *
     *  - `valido`  (bool)         si el par se puede guardar.
     *  - `valores` (array)        `['ajuste_precio_tipo' => ..., 'ajuste_precio_porcentaje' => ...]`
     *                             listo para asignar al modelo (las dos NULL = sin ajuste). Vacio si
     *                             no es valido.
     *  - `mensaje` (string|null)  el texto en espanol para el 422. `null` si es valido.
     *
     * Reglas:
     *
     *  - Tipo `''`, `null` o `'sin_ajuste'` = quitar el ajuste (las dos columnas a NULL). Si en ese
     *    caso llega un porcentaje con valor, es un porcentaje SIN tipo y se rechaza (la invariante).
     *    Un porcentaje en 0 no cuenta como "con valor": 0 % es lo mismo que no tener ajuste, y un
     *    campo numerico que arranca en 0 no tiene que impedir crear una sucursal comun.
     *  - Tipo distinto de `recargo` / `descuento` (comparado sin mayusculas ni espacios) = rechazado.
     *  - Tipo valido sin porcentaje = rechazado.
     *  - Porcentaje: numero, o texto numerico con coma o punto decimal (`"10,5"` = 10.5). Lo que no es
     *    un numero se rechaza; no se adivina.
     *  - Se redondea a DOS decimales (los de la columna) ANTES de validar el rango: lo que se valida es
     *    lo que se guarda. Un descuento de 99,999 queda en 100,00 y se rechaza, no se guarda como 99,99.
     *  - Mayor que 0; descuento menor que 100; recargo hasta 999.99.
     *
     * @param  mixed  $tipo
     * @param  mixed  $porcentaje
     * @return array  ['valido' => bool, 'valores' => array, 'mensaje' => string|null]
     */
    static function normalizar($tipo, $porcentaje) {

        $tipo_normalizado = self::normalizar_tipo($tipo);

        if ($tipo_normalizado === false) {
            return self::rechazar('El tipo de ajuste tiene que ser "recargo" o "descuento".');
        }

        $porcentaje_vacio = self::es_vacio($porcentaje);

        /* Sin tipo: o no hay nada que guardar (quitar el ajuste), o es un porcentaje huerfano. */
        if (is_null($tipo_normalizado)) {

            if ($porcentaje_vacio || self::es_cero($porcentaje)) {
                return self::aceptar(null, null);
            }

            return self::rechazar('Cargaste un porcentaje pero no elegiste si es un recargo o un descuento.');
        }

        if ($porcentaje_vacio) {
            return self::rechazar('Si elegís un recargo o un descuento, cargá también el porcentaje.');
        }

        $numero = self::a_numero($porcentaje);

        if (is_null($numero)) {
            return self::rechazar('El porcentaje del ajuste tiene que ser un número (por ejemplo 10 o 5,5).');
        }

        $numero = round($numero, 2);

        if ($numero <= 0) {
            return self::rechazar('El porcentaje del ajuste tiene que ser mayor a 0.');
        }

        if ($tipo_normalizado === self::TIPO_DESCUENTO && $numero >= self::COTA_DESCUENTO) {
            return self::rechazar('Un descuento tiene que ser menor al 100%.');
        }

        if ($tipo_normalizado === self::TIPO_RECARGO && $numero > self::MAXIMO_RECARGO) {
            return self::rechazar('Un recargo no puede pasar del 999,99%.');
        }

        return self::aceptar($tipo_normalizado, $numero);
    }

    /**
     * Lleva el tipo que mando la SPA a su forma canonica.
     *
     * @param  mixed  $tipo
     * @return string|null|false  `'recargo'` / `'descuento'`; `null` = sin ajuste; `false` = invalido.
     */
    private static function normalizar_tipo($tipo) {

        if (is_null($tipo)) {
            return null;
        }

        /* Un array, un bool o un numero en el tipo no es un tipo: se rechaza, no se castea. */
        if (!is_string($tipo)) {
            return false;
        }

        $tipo = strtolower(trim($tipo));

        if ($tipo === '' || $tipo === self::TIPO_SIN_AJUSTE) {
            return null;
        }

        if ($tipo === self::TIPO_RECARGO || $tipo === self::TIPO_DESCUENTO) {
            return $tipo;
        }

        return false;
    }

    /**
     * ¿El porcentaje esta vacio (null o texto en blanco)?
     *
     * El 0 NO es vacio: se valida aparte ("mayor a 0").
     *
     * @param  mixed  $porcentaje
     * @return bool
     */
    private static function es_vacio($porcentaje) {

        return is_null($porcentaje) || (is_string($porcentaje) && trim($porcentaje) === '');
    }

    /**
     * ¿El porcentaje es un numero que vale cero?
     *
     * @param  mixed  $porcentaje
     * @return bool
     */
    private static function es_cero($porcentaje) {

        $numero = self::a_numero($porcentaje);

        return !is_null($numero) && $numero == 0;
    }

    /**
     * Convierte lo que mando la SPA en un float, o null si no es un numero.
     *
     * Acepta int, float y texto numerico con coma o punto decimal. NO usa `is_numeric()` pelado:
     * aceptaria notacion cientifica (`"1e1"` = 10) y espacios raros, y un porcentaje de plata no
     * tiene por que entender eso.
     *
     * @param  mixed  $valor
     * @return float|null
     */
    private static function a_numero($valor) {

        if (is_int($valor) || is_float($valor)) {
            return is_finite((float) $valor) ? (float) $valor : null;
        }

        if (!is_string($valor)) {
            return null;
        }

        $texto = str_replace(',', '.', trim($valor));

        if (!preg_match('/^-?(\d+(\.\d*)?|\.\d+)$/', $texto)) {
            return null;
        }

        return (float) $texto;
    }

    /**
     * @param  string|null  $tipo
     * @param  float|null   $porcentaje
     * @return array
     */
    private static function aceptar($tipo, $porcentaje) {

        return [
            'valido'  => true,
            'valores' => [
                self::COLUMNA_TIPO       => $tipo,
                self::COLUMNA_PORCENTAJE => $porcentaje,
            ],
            'mensaje' => null,
        ];
    }

    /**
     * @param  string  $mensaje
     * @return array
     */
    private static function rechazar($mensaje) {

        return [
            'valido'  => false,
            'valores' => [],
            'mensaje' => $mensaje,
        ];
    }
}
