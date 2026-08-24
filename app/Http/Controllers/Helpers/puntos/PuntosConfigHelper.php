<?php

namespace App\Http\Controllers\Helpers\puntos;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\SistemaDePuntos;
use App\Models\User;

/**
 * Resuelve las dos preguntas de gate del módulo de puntos, y las memoiza.
 *
 *   1. ¿El comercio tiene prendida la extensión 'puntos_clientes'?
 *   2. ¿Tiene un `sistemas_de_puntos` con activo = 1?
 *
 * 🔴 POR QUÉ ESTO ES UNA CLASE CON MEMOIZACIÓN Y NO DOS QUERIES SUELTAS. El reconciliador de
 * acumulación cuelga del final de `CurrentAcountHelper::checkPagos()`, que recorre TODAS las
 * ventas de la cuenta corriente de un cliente y se dispara en cada alta y baja de pago, en
 * cada guardado de venta, y en dos jobs de fondo que barren todas las cuentas de todos los
 * clientes (ProcessCheckSaldosChunk, iniciar_credit_accounts). Sin memoización, un comercio
 * que NI SIQUIERA TIENE la extensión pagaría dos SELECT por venta recorrida en esos jobs.
 * El requisito es explícito: costo permanente CERO consultas por venta para el comercio sin
 * la extensión, más allá de la primera del request. Por eso las dos respuestas se guardan en
 * `static` por user_id y duran lo que dura el proceso.
 *
 * ⚠️ La memoización tiene vida de request/corrida de comando, que es lo que se quiere. En los
 * tests, donde un mismo proceso prende y apaga la extensión, hay que llamar a
 * `limpiar_memo()` después de cambiar la configuración: si no, se lee la respuesta vieja.
 */
class PuntosConfigHelper {

    /**
     * El slug con el que la extensión está sembrada en `extencion_empresas`
     * (ExtencionPuntosClientesSeeder). El front y el back leen el MISMO slug.
     */
    const SLUG_EXTENCION = 'puntos_clientes';

    /**
     * @var array  user_id => bool
     */
    private static $memo_extencion = [];

    /**
     * @var array  user_id => \App\Models\SistemaDePuntos|null
     */
    private static $memo_programa = [];

    /**
     * @var array  sistema_de_puntos_id => array(price_type_id => multiplicador|null)
     */
    private static $memo_listas = [];

    /**
     * Tira la memoria. Sin argumento tira todo; con user_id, solo lo de ese comercio.
     *
     * La usan los tests y cualquier flujo que cambie la configuración y necesite volver a
     * preguntar en el mismo proceso (el ABM del programa, el comando de vencimiento).
     *
     * @param  int|null  $user_id
     * @return void
     */
    static function limpiar_memo($user_id = null) {

        if (is_null($user_id)) {
            self::$memo_extencion = [];
            self::$memo_programa  = [];
            self::$memo_listas    = [];
            return;
        }

        $user_id = (int) $user_id;

        unset(self::$memo_extencion[$user_id]);
        unset(self::$memo_programa[$user_id]);

        /*
         * Las listas se memoizan por id de programa, no por comercio: se tira todo el mapa
         * porque no vale la pena mantener el índice inverso para un caso que corre en tests y
         * al guardar el ABM.
         */
        self::$memo_listas = [];
    }

    /**
     * ¿El comercio tiene prendida la extensión del módulo?
     *
     * @param  int  $user_id  El dueño del comercio (sales.user_id).
     * @return bool
     */
    static function tiene_extencion($user_id) {

        if (is_null($user_id)) {
            return false;
        }

        $user_id = (int) $user_id;

        if (array_key_exists($user_id, self::$memo_extencion)) {
            return self::$memo_extencion[$user_id];
        }

        $user = User::find($user_id);

        if (is_null($user)) {
            self::$memo_extencion[$user_id] = false;
            return false;
        }

        /*
         * Las extensiones son de la EMPRESA, o sea del dueño: si el user_id que llegó es el de
         * un empleado, la respuesta es la del owner. Mismo criterio que
         * UserHelper::uses_listas_de_precio().
         */
        if ($user->owner_id) {
            $owner = User::find($user->owner_id);
            if (!is_null($owner)) {
                $user = $owner;
            }
        }

        $tiene = UserHelper::hasExtencion(self::SLUG_EXTENCION, $user);

        self::$memo_extencion[$user_id] = $tiene;

        return $tiene;
    }

    /**
     * El programa de puntos activo del comercio, o null.
     *
     * 🔴 Devuelve el PRIMERO por id ascendente. El invariante "uno solo activo por comercio"
     * lo sostiene SistemaDePuntosController al guardar (apaga los demás en la misma
     * transacción), no la base: la tabla no tiene unique en user_id a propósito. Si por lo
     * que sea quedaran dos activos, este orden hace que la respuesta sea al menos estable
     * entre llamadas, en vez de depender del orden físico de las filas.
     *
     * @param  int  $user_id
     * @return \App\Models\SistemaDePuntos|null
     */
    static function programa_activo($user_id) {

        if (is_null($user_id)) {
            return null;
        }

        $user_id = (int) $user_id;

        if (array_key_exists($user_id, self::$memo_programa)) {
            return self::$memo_programa[$user_id];
        }

        /*
         * La salida barata va PRIMERO: sin extensión no se toca `sistemas_de_puntos` ni para
         * mirarla. Es la mitad del "cero consultas por venta" del comercio sin el módulo.
         */
        if (!self::tiene_extencion($user_id)) {
            self::$memo_programa[$user_id] = null;
            return null;
        }

        $sistema = SistemaDePuntos::where('user_id', $user_id)
                                    ->where('activo', 1)
                                    ->orderBy('id', 'ASC')
                                    ->first();

        self::$memo_programa[$user_id] = $sistema;

        return $sistema;
    }

    /**
     * Atajo de las dos preguntas juntas: ¿este comercio acumula puntos hoy?
     *
     * @param  int  $user_id
     * @return bool
     */
    static function activo_para($user_id) {
        return !is_null(self::programa_activo($user_id));
    }

    /**
     * Las listas de precio habilitadas del programa, como mapa price_type_id => multiplicador.
     *
     * El multiplicador viene tal cual está en el pivot, o sea que puede ser null: quien lo use
     * tiene que resolver null como 1 (ver PuntosBaseHelper::multiplicador_efectivo()). No se
     * normaliza acá para que "no configurado" siga siendo distinguible de "configurado en 1".
     *
     * 🔴 Un array VACÍO significa "el programa no filtra por lista y aplica a todo", NO
     * "no aplica a ninguna". Es la regla de borde que decide plata y está escrita también en
     * la migración del pivot.
     *
     * @param  \App\Models\SistemaDePuntos|null  $sistema
     * @return array
     */
    static function listas_habilitadas($sistema) {

        if (is_null($sistema)) {
            return [];
        }

        $sistema_id = (int) $sistema->id;

        if (array_key_exists($sistema_id, self::$memo_listas)) {
            return self::$memo_listas[$sistema_id];
        }

        $listas = [];

        foreach ($sistema->price_types as $price_type) {

            $multiplicador = null;

            if (!is_null($price_type->pivot) && !is_null($price_type->pivot->multiplicador)) {
                $multiplicador = (float) $price_type->pivot->multiplicador;
            }

            $listas[(int) $price_type->id] = $multiplicador;
        }

        self::$memo_listas[$sistema_id] = $listas;

        return $listas;
    }

    /**
     * ¿El programa filtra por lista de precio?
     *
     * @param  \App\Models\SistemaDePuntos|null  $sistema
     * @return bool
     */
    static function tiene_filtro_de_listas($sistema) {
        return count(self::listas_habilitadas($sistema)) > 0;
    }
}
