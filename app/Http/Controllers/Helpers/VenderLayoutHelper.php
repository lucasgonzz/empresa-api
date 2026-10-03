<?php

namespace App\Http\Controllers\Helpers;

use App\Models\User;
use App\Models\VenderLayout;
use Illuminate\Support\Facades\DB;

/**
 * Lógica de los "Diseños de Vender" (misión diseno-vender-configurable, 28/9/2026) que comparten el
 * controller (`VenderLayoutController`), el seeder (`VenderLayoutSeeder`) y los setups de sistema
 * nuevo y de demo.
 *
 * Tres responsabilidades:
 *
 *   1. El "Diseño predeterminado": que todo dueño tenga uno, sin duplicarlo nunca.
 *   2. El invariante "exactamente un diseño en uso por dueño": `poner_en_uso()`.
 *   3. La normalización del JSON que manda el SPA: `normalizar_layout()`.
 *
 * -------------------------------------------------------------------------------------------
 * 🔴 EL BACKEND NO CONOCE EL CATÁLOGO DE CAMPOS, Y ES A PROPÓSITO
 * -------------------------------------------------------------------------------------------
 *
 * Qué campos existen, en qué etapa van por defecto y cuáles son obligatorios lo sabe solamente el
 * SPA (`src/components/vender/layout/elementos.js`). Acá se normaliza la FORMA del diseño para que
 * no entre basura en la base (claves con otro formato, anchos fuera de la grilla, listas
 * gigantes), pero NO se valida que cada `key` exista. Así un diseño guardado sigue siendo válido
 * cuando el SPA agregue un campo nuevo o retire uno viejo: el resolver del SPA ignora las keys que
 * no conoce y ubica solo los campos que el diseño no nombra.
 *
 * PHP 7.4 estricto: ninguna sintaxis de PHP 8 en ningún camino de este archivo.
 */
class VenderLayoutHelper
{
    /** Nombre del diseño que se crea para cada dueño que no tiene ninguno. */
    const NOMBRE_PREDETERMINADO = 'Diseño predeterminado';

    /** Versión del formato del JSON. Es la misma `VERSION_DEL_FORMATO` de diseno_predeterminado.js. */
    const VERSION_DEL_FORMATO = 1;

    /** Las etapas de Vender. Cualquier otra clave de `etapas` se descarta. */
    const ETAPAS = array('etapa_1', 'etapa_2', 'etapa_3');

    /*
     * 🔴 Los dos patrones llevan el modificador `D` (PCRE_DOLLAR_ENDONLY), y no es decoración. Sin él,
     * en PHP el `$` también matchea ANTES de un salto de línea final, así que 'caja' seguido de "\n"
     * pasaría el filtro y quedaría guardado con el salto adentro. En JavaScript el `$` sin la bandera
     * `m` ya se comporta así, o sea que con la `D` el backend valida exactamente lo mismo que
     * `PATRON_ID_SEPARADOR` de resolver_diseno.js.
     */

    /** Patrón de la `key` de un campo (y de cada entrada de `sacados`). */
    const PATRON_KEY = '/^[a-z0-9_]{1,60}$/D';

    /** Patrón del `id` opcional de un ítem (lo usan los separadores, que pueden ser varios). */
    const PATRON_ID = '/^[a-z0-9_]{1,40}$/D';

    /*
     * Topes de tamaño. El catálogo de Vender tiene unos 35 campos, así que ningún diseño legítimo
     * se acerca: los topes están para que un cliente no pueda guardar un JSON arbitrariamente grande.
     */

    /** Máximo de ítems por etapa. Los que sobran se descartan (se conservan los primeros). */
    const MAXIMO_DE_ITEMS_POR_ETAPA = 100;

    /** Máximo de keys en `sacados`, ya sin repetidos. */
    const MAXIMO_DE_SACADOS = 100;

    /** Ancho mínimo y máximo de un ítem, en columnas de la grilla de Bootstrap. */
    const COLS_MINIMO = 1;
    const COLS_MAXIMO = 12;

    /** El ancho que toma un `cols` que no es un número: todo el ancho de la fila. */
    const COLS_SI_NO_ES_NUMERO = 12;

    /*
     * ---------------------------------------------------------------------------------------
     *  El diseño predeterminado
     * ---------------------------------------------------------------------------------------
     */

    /**
     * Si el dueño no tiene NINGÚN diseño, le crea el "Diseño predeterminado" (`layout` null, en uso).
     *
     * Lo llaman el index del ABM (la primera vez que un dueño abre el sistema después del deploy)
     * y `VenderLayoutSeeder` (para las bases existentes y los setups de sistema nuevo y de demo).
     *
     * `layout = null` es "el diseño del sistema": el SPA lo arma en código
     * (`diseno_predeterminado.js`), así el default no se duplica entre PHP y JS y un negocio que no
     * tocó nada sigue viendo Vender exactamente como hasta hoy.
     *
     * -------------------------------------------------------------------------------------------
     * 🔴 POR QUÉ EL CANDADO SOBRE LA FILA DEL DUEÑO EN `users`
     * -------------------------------------------------------------------------------------------
     *
     * "Mirar si tiene y, si no, crear" son dos pasos, y el index lo corren a la vez varias pestañas
     * y varios empleados del mismo negocio al iniciar sesión (todos piden los catálogos juntos por
     * `recursos-iniciales`). Sin serializar, dos pedidos ven "no tiene" al mismo tiempo y el dueño
     * termina con dos predeterminados, los dos en uso.
     *
     * No hay un unique que lo impida (ver la migración), y bloquear filas de `vender_layouts` no
     * alcanza: cuando el dueño todavía no tiene ninguna, no hay fila que bloquear. La fila del dueño
     * en `users` sí existe siempre, así que el `SELECT ... FOR UPDATE` sobre ella hace que el segundo
     * pedido espere a que el primero termine. Es el mismo recurso que usa `Controller::num()` para
     * los correlativos por dueño.
     *
     * El "¿ya tiene alguno?" se pregunta con un `exists()` común y NO con un SELECT bloqueante sobre
     * `vender_layouts`, por dos motivos:
     *   - Alcanza: en REPEATABLE READ la foto de las lecturas comunes se toma en la primera lectura
     *     común de la transacción, que acá es DESPUÉS de conseguir el candado. El segundo pedido ya
     *     ve lo que el primero commiteó.
     *   - Un SELECT bloqueante sobre un rango vacío de `user_id` toma un "gap lock", y dos dueños
     *     DISTINTOS sembrándose a la vez (el seeder en una base compartida, dos negocios entrando al
     *     mismo tiempo) se trabarían entre sí hasta que InnoDB matara a uno por deadlock. El candado
     *     sobre `users` es por clave primaria: no traba a nadie más que al mismo dueño.
     *
     * Si el id no existe o es de un empleado no crea nada: los diseños son siempre del dueño.
     *
     * @param  int  $owner_id  El dueño (`owner_id` null en `users`).
     * @return \App\Models\VenderLayout|null  El diseño creado, o null si no hacía falta crearlo.
     */
    static function crear_predeterminado_si_no_tiene($owner_id)
    {
        return DB::transaction(function () use ($owner_id) {

            $dueno = self::bloquear_dueno($owner_id);

            if (is_null($dueno) || !is_null($dueno->owner_id)) {
                return null;
            }

            if (VenderLayout::where('user_id', $owner_id)->exists()) {
                return null;
            }

            return VenderLayout::create([
                'user_id' => $owner_id,
                'name'    => self::NOMBRE_PREDETERMINADO,
                'layout'  => null,
                'en_uso'  => true,
            ]);
        });
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  El invariante "exactamente uno en uso por dueño"
     * ---------------------------------------------------------------------------------------
     */

    /**
     * Toma el candado de la fila del dueño en `users` (`SELECT ... FOR UPDATE`) y la devuelve.
     *
     * Toda escritura sobre los diseños de un dueño pasa por acá primero, así el alta del
     * predeterminado, el "poner en uso" y el borrado de un mismo dueño quedan en fila y el
     * invariante no se puede romper por dos pedidos cruzados (por ejemplo, borrar un diseño justo
     * mientras otro pedido lo pone en uso). Ver el porqué completo en
     * `crear_predeterminado_si_no_tiene()`.
     *
     * 🔴 Solo sirve ADENTRO de una transacción: fuera de una, el candado se suelta en el mismo
     * instante en que termina la consulta.
     *
     * @param  int  $owner_id
     * @return \App\Models\User|null  El dueño bloqueado, o null si el id no existe.
     */
    static function bloquear_dueno($owner_id)
    {
        return User::where('id', $owner_id)->lockForUpdate()->first();
    }

    /**
     * El diseño pedido, solo si es de este dueño.
     *
     * 🔴 El filtro por `user_id` va en la consulta y no en un `if` después de un `find()`: es lo
     * único que separa los diseños de un negocio de los de otro en una base compartida.
     *
     * @param  mixed  $id
     * @param  int    $owner_id
     * @return \App\Models\VenderLayout|null
     */
    static function diseno_del_dueno($id, $owner_id)
    {
        return VenderLayout::where('id', $id)
                            ->where('user_id', $owner_id)
                            ->first();
    }

    /**
     * Deja el diseño en uso y apaga todos los demás del mismo dueño.
     *
     * Se llama siempre adentro de la MISMA transacción que la escritura del diseño y con el candado
     * del dueño tomado (`bloquear_dueno()`): si el apagado quedara afuera, un error en el medio
     * dejaría dos diseños en uso y cada sesión de Vender podría quedarse con uno distinto.
     *
     * Los que se apagan se buscan primero y se actualizan después por clave primaria, en vez de un
     * único `UPDATE ... WHERE user_id = ?`, por dos motivos: el controller necesita los ids para
     * notificar a las otras sesiones que esos diseños cambiaron, y un UPDATE por rango de `user_id`
     * toma gap locks que pueden trabar a otro dueño que esté guardando al mismo tiempo. Con el
     * candado del dueño tomado, entre la búsqueda y el UPDATE nadie más puede tocar sus diseños.
     *
     * El UPDATE de Eloquent también renueva el `updated_at` de los apagados, que es lo que el SPA
     * usa para desempatar si alguna vez viera dos en uso.
     *
     * @param  \App\Models\VenderLayout  $model
     * @return array  Los ids de los diseños que se apagaron (vacío si no había otro en uso).
     */
    static function poner_en_uso($model)
    {
        if (!$model->en_uso) {
            $model->en_uso = true;
            $model->save();
        }

        $apagados = VenderLayout::where('user_id', $model->user_id)
                                ->where('id', '!=', $model->id)
                                ->where('en_uso', 1)
                                ->pluck('id')
                                ->all();

        if (!empty($apagados)) {
            VenderLayout::whereIn('id', $apagados)->update(['en_uso' => false]);
        }

        return $apagados;
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  Normalización del JSON del diseño
     * ---------------------------------------------------------------------------------------
     */

    /**
     * Normaliza el `layout` que manda el SPA antes de guardarlo.
     *
     * Formato de salida (versión 1):
     *
     *   [
     *     'version' => 1,
     *     'etapas'  => [
     *       'etapa_1' => [ ['key' => 'metodo_de_pago', 'cols' => 4], ['key' => 'separador', 'id' => 'separador_1', 'cols' => 12] ],
     *       'etapa_2' => [ ... ],
     *       'etapa_3' => [ ... ],
     *     ],
     *     'sacados' => ['combos'],
     *   ]
     *
     * Reglas:
     *   - `null` (o el string JSON `null`) es válido y queda `null`: el diseño del sistema.
     *   - Acepta un array o un string JSON. Lo que no es JSON, no es un objeto o no trae `etapas`
     *     como objeto es INVÁLIDO: el controller responde 422.
     *   - De `etapas` quedan solo `etapa_1`, `etapa_2` y `etapa_3`; las que falten quedan `[]`.
     *   - Cada ítem necesita una `key` que cumpla `PATRON_KEY`; si no, se DESCARTA el ítem (no el
     *     diseño entero). `cols` se acota a 1..12 y, si no es un número, toma 12. El `id` es
     *     opcional: si no cumple `PATRON_ID` se descarta el id, no el ítem (el resolver del SPA le
     *     asigna uno estable al separador que no lo trae). Cualquier otra propiedad se descarta.
     *   - Máximo 100 ítems por etapa y 100 `sacados` (sin repetidos, mismo patrón que la key).
     *   - Siempre se guarda `version: 1`, venga lo que venga.
     *
     * Los repetidos DENTRO de las etapas no se sacan acá: los separadores comparten la key
     * `separador` y se distinguen por el id, y deduplicar requiere saber qué es un separador, que es
     * conocimiento del catálogo del SPA. Lo hace `resolver_diseno()`.
     *
     * `cols` se trunca (4.9 -> 4) igual que el `parseInt` de `acotar_cols()` en elementos.js, así un
     * diseño se ve con el mismo ancho recién guardado que después de recargar.
     *
     * Un JSON `{}` y un `[]` llegan iguales a PHP (los dos son `array()`), así que un `etapas` vacío
     * se toma como objeto vacío: las tres etapas quedan vacías y el SPA ubica cada campo en su etapa
     * por defecto. Una lista NO vacía en `etapas`, en cambio, es inválida.
     *
     * @param  mixed  $valor
     * @return array  `[ok (bool), layout (array|null)]`. Con `ok` en false el layout es null y el
     *                controller responde 422.
     */
    static function normalizar_layout($valor)
    {
        if (is_null($valor)) {
            return array(true, null);
        }

        if (is_string($valor)) {
            $valor = json_decode($valor, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return array(false, null);
            }

            // El string era el literal JSON `null`: vale lo mismo que mandar null.
            if (is_null($valor)) {
                return array(true, null);
            }
        }

        if (!is_array($valor)
            || !array_key_exists('etapas', $valor)
            || !self::es_objeto($valor['etapas'])) {
            return array(false, null);
        }

        $etapas = array();

        foreach (self::ETAPAS as $etapa) {
            $items = array_key_exists($etapa, $valor['etapas']) ? $valor['etapas'][$etapa] : array();

            $etapas[$etapa] = self::normalizar_etapa($items);
        }

        $sacados = array_key_exists('sacados', $valor) ? $valor['sacados'] : array();

        return array(true, array(
            'version' => self::VERSION_DEL_FORMATO,
            'etapas'  => $etapas,
            'sacados' => self::normalizar_sacados($sacados),
        ));
    }

    /**
     * Los ítems válidos de una etapa, en el orden en que vinieron, hasta `MAXIMO_DE_ITEMS_POR_ETAPA`.
     *
     * Una etapa que no es una lista (un número, un string, un objeto) queda vacía: es lo mismo que
     * hace el resolver del SPA con `Array.isArray()`.
     *
     * @param  mixed  $items
     * @return array
     */
    private static function normalizar_etapa($items)
    {
        $normalizados = array();

        if (!self::es_lista($items)) {
            return $normalizados;
        }

        foreach ($items as $item) {

            // Se corta al llegar al tope en vez de filtrar todo y recortar después: un arreglo
            // enorme no cuesta más que sus primeros ítems válidos.
            if (count($normalizados) >= self::MAXIMO_DE_ITEMS_POR_ETAPA) {
                break;
            }

            $normalizado = self::normalizar_item($item);

            if (!is_null($normalizado)) {
                $normalizados[] = $normalizado;
            }
        }

        return $normalizados;
    }

    /**
     * Un ítem de etapa normalizado a `{key, id?, cols}`, o null si no tiene una key válida.
     *
     * @param  mixed  $item
     * @return array|null
     */
    private static function normalizar_item($item)
    {
        if (!self::es_objeto($item)
            || !array_key_exists('key', $item)
            || !is_string($item['key'])
            || !preg_match(self::PATRON_KEY, $item['key'])) {
            return null;
        }

        $normalizado = array('key' => $item['key']);

        if (array_key_exists('id', $item)
            && is_string($item['id'])
            && preg_match(self::PATRON_ID, $item['id'])) {
            $normalizado['id'] = $item['id'];
        }

        $normalizado['cols'] = self::normalizar_cols(array_key_exists('cols', $item) ? $item['cols'] : null);

        return $normalizado;
    }

    /**
     * El ancho de un ítem acotado a 1..12. Lo que no es un número (null, un texto, un booleano)
     * toma 12.
     *
     * Se compara como float ANTES de pasar a entero: un número gigante (o infinito) pasado directo
     * a int es indefinido en PHP 7 y puede volver negativo. Comparado primero, cae en 12.
     *
     * @param  mixed  $cols
     * @return int
     */
    private static function normalizar_cols($cols)
    {
        if (!is_numeric($cols)) {
            return self::COLS_SI_NO_ES_NUMERO;
        }

        $numero = (float) $cols;

        if (is_nan($numero)) {
            return self::COLS_SI_NO_ES_NUMERO;
        }

        if ($numero < self::COLS_MINIMO) {
            return self::COLS_MINIMO;
        }

        if ($numero > self::COLS_MAXIMO) {
            return self::COLS_MAXIMO;
        }

        return (int) $numero;
    }

    /**
     * Las keys sacadas: strings con el patrón de key, sin repetidos (gana la primera), hasta
     * `MAXIMO_DE_SACADOS`. Si `sacados` no es una lista, queda vacío.
     *
     * @param  mixed  $sacados
     * @return array
     */
    private static function normalizar_sacados($sacados)
    {
        $normalizados = array();

        if (!self::es_lista($sacados)) {
            return $normalizados;
        }

        $vistos = array();

        foreach ($sacados as $key) {

            if (count($normalizados) >= self::MAXIMO_DE_SACADOS) {
                break;
            }

            if (!is_string($key) || !preg_match(self::PATRON_KEY, $key) || isset($vistos[$key])) {
                continue;
            }

            $vistos[$key] = true;
            $normalizados[] = $key;
        }

        return $normalizados;
    }

    /**
     * Si el valor es un array con forma de LISTA de JSON: claves 0, 1, 2... en orden. El array vacío
     * cuenta como lista.
     *
     * @param  mixed  $valor
     * @return bool
     */
    private static function es_lista($valor)
    {
        if (!is_array($valor)) {
            return false;
        }

        $esperada = 0;

        foreach ($valor as $clave => $ignorado) {
            if ($clave !== $esperada) {
                return false;
            }
            $esperada++;
        }

        return true;
    }

    /**
     * Si el valor es un array con forma de OBJETO de JSON: no es una lista, o está vacío (`{}` y `[]`
     * llegan iguales después del `json_decode` del request, así que el vacío vale como objeto).
     *
     * @param  mixed  $valor
     * @return bool
     */
    private static function es_objeto($valor)
    {
        return is_array($valor) && ($valor === array() || !self::es_lista($valor));
    }
}
