<?php

namespace App\Http\Controllers\Helpers\article;

/**
 * EL RENOMBRE (REEMPLAZAR UN TEXTO POR OTRO EN EL NOMBRE) EN UNA ACTUALIZACIÓN MASIVA (misión
 * asistente-diario-20261119, rama C, A4, 19/11/2026).
 *
 * 🔴 EL CASO REAL. Pablo (Ferre Total, conversación 72, mensajes 700-723) pidió «cambiá de cada
 * artículo ESMALTE AC. MULT. por ESMALTE ACCIÓN MÚLTIPLE» sobre 145 artículos. El agente le dijo que
 * iba «como actualización masiva», le mostró diez ejemplos, Pablo confirmó («Ok hacé el cambio») y
 * recién después le contestó que no había herramienta que renombre en masa y que lo hiciera uno por
 * uno desde Artículos. La actualización masiva no tocaba el nombre.
 *
 * Esta clase es la rama del update_form que cambia SOLO un pedazo del nombre: busca un texto (sin
 * distinguir mayúsculas de minúsculas) y lo reemplaza por otro, dejando el resto del nombre como
 * estaba. La usa la tarjeta del asistente (PropuestaRenombreMasivoIaHelper), que la encola por el
 * MISMO camino que cualquier actualización masiva (MasiveUpdateHelper::encolar_actualizacion(), el
 * job ProcessMasiveUpdateJob y el historial de actualizaciones masivas).
 *
 * La forma del ítem del update_form:
 *
 *     ['type' => 'reemplazo_de_texto', 'key' => 'name', 'buscar' => 'AC. MULT.', 'reemplazo' => 'ACCIÓN MÚLTIPLE']
 *
 * `type` es propio y ninguna rama genérica de apply_form_change() lo lee (las genéricas miran
 * number / search / select / checkbox), así que tiene su rama ANTES que ellas —el mismo patrón que
 * StockMinimoMaximoEnMasivaHelper y PrecioFinalEnMasivaHelper—.
 *
 * 🔴 LA REVERSIÓN. El cambio se devuelve con la forma de siempre (`prop_key` = 'name', `old_value` =
 * el nombre ENTERO de antes, `new_value` = el nombre entero de después), y eso queda en el
 * `changes_json` del historial. La reversión genérica de MasiveUpdateHelper::revert_article_pivot_changes()
 * hace `$model->name = $change['old']`: vuelve el nombre de antes, tal cual, sin rama propia. Por eso
 * se guarda el nombre completo y no "el pedazo": revertir no tiene que adivinar nada.
 *
 * Reglas al aplicar:
 *  - El artículo tiene que ser del dueño de la masiva (la selección manual resuelve por id con
 *    find(), sin dueño; la propuesta y encolar_actualizacion() ya filtran, esto es la tercera guarda).
 *  - Un artículo cuyo nombre ya no tiene el texto (lo cambiaron entre la tarjeta y la cola) no se
 *    toca y no cuenta como cambio.
 *  - Nunca deja un artículo sin nombre: si el reemplazo lo vaciara, no se escribe.
 *  - 🔴 El slug (la URL pública del artículo en la tienda) NO se recalcula, a propósito: un link de
 *    la tienda compartido por WhatsApp se rompería, y la reversión de la masiva solo restaura lo que
 *    está en el changes_json (un cambio por columna). La pantalla de edición sí lo recalcula al
 *    cambiar el nombre; acá se prioriza que los links sigan andando y que revertir sea exacto.
 *
 * El permiso NO se mira acá: en la cola no hay sesión. Lo miran la propuesta y la ejecución de la
 * tarjeta, con `article.update` sobre la persona que confirma.
 *
 * PHP 7.4: sin match, str_contains, nullsafe, argumentos nombrados ni union types.
 */
class RenombreEnMasivaHelper
{
    /** `type` del ítem del update_form: no lo lee ninguna rama genérica de apply_form_change(). */
    const TIPO = 'reemplazo_de_texto';

    /** La columna que se cambia. */
    const CLAVE = 'name';

    /** La `operation` que queda en el historial (las genéricas dejan set / increment / decrement). */
    const OPERACION = 'reemplazo';

    /** El `form_key` que queda en el historial. */
    const FORM_KEY = 'reemplazo_de_texto_name';

    /**
     * El ítem del update_form de un renombre.
     *
     * @param  string  $buscar
     * @param  string  $reemplazo
     * @return array
     */
    public static function item_del_formulario($buscar, $reemplazo)
    {
        return [
            'type'      => self::TIPO,
            'key'       => self::CLAVE,
            'buscar'    => (string) $buscar,
            'reemplazo' => (string) $reemplazo,
        ];
    }

    /**
     * true si el ítem del update_form es un renombre.
     *
     * @param  mixed  $form
     * @return bool
     */
    public static function es_de_la_masiva($form)
    {
        return is_array($form)
            && isset($form['type'])
            && $form['type'] === self::TIPO;
    }

    /**
     * El nombre con el texto reemplazado. Sin distinguir mayúsculas de minúsculas ("Ac. Mult." y
     * "AC. MULT." son lo mismo para el dueño), con soporte de acentos y eñes (modificador `u`), y
     * reemplazando TODAS las apariciones. El reemplazo va literal: un `$1` o una barra que escriba
     * el dueño no se interpretan (por eso es preg_replace_callback y no preg_replace).
     *
     * Con el reemplazo vacío ("sacale «OFERTA» al nombre") se lleva también los espacios de alrededor
     * y deja uno solo, para que «TORNILLO OFERTA 6X2» quede «TORNILLO 6X2» y no con dos espacios.
     *
     * @param  string  $nombre
     * @param  string  $buscar
     * @param  string  $reemplazo
     * @return string  El nombre nuevo (igual al de antes si no tenía el texto).
     */
    public static function reemplazar($nombre, $buscar, $reemplazo)
    {
        $nombre = (string) $nombre;
        $buscar = (string) $buscar;
        $reemplazo = (string) $reemplazo;

        if ($buscar === '' || $nombre === '') {

            return $nombre;
        }

        $patron = preg_quote($buscar, '/');

        if ($reemplazo === '') {

            /*
             * Revisión independiente (19/11/2026): antes `\s*X\s*` dejaba SIEMPRE un espacio, y
             * «SUPEROFERTAX» quedaba «SUPER X». Ahora queda un espacio solo si había espacio de los DOS lados
             * («TORNILLO OFERTA 6X2» → «TORNILLO 6X2»); con espacio de un solo lado o de ninguno, el texto se
             * va con ese espacio y nada más («SUPEROFERTAX» → «SUPERX», «OFERTA TORNILLO» → «TORNILLO»).
             */
            $nuevo = preg_replace_callback('/(\s*)' . $patron . '(\s*)/iu', function ($m) {
                return ($m[1] !== '' && $m[2] !== '') ? ' ' : '';
            }, $nombre);

            return is_null($nuevo) ? $nombre : trim($nuevo);
        }

        $nuevo = preg_replace_callback('/' . $patron . '/iu', function () use ($reemplazo) {
            return $reemplazo;
        }, $nombre);

        // null = la expresión falló (UTF-8 inválido en un nombre importado): el nombre queda como estaba.
        return is_null($nuevo) ? $nombre : $nuevo;
    }

    /**
     * true si el texto aparece PEGADO a otra letra o número en el nombre: «MULT» dentro de «MULTIUSO».
     * El reemplazo es literal y ahí también cambia (revisión independiente, 19/11/2026); la tarjeta
     * prioriza mostrar un ejemplo así para que la persona lo vea antes del sí.
     *
     * @param  string  $nombre
     * @param  string  $buscar
     * @return bool
     */
    public static function queda_dentro_de_otra_palabra($nombre, $buscar)
    {
        $buscar = (string) $buscar;

        if ($buscar === '') {

            return false;
        }

        $patron = preg_quote($buscar, '/');

        return preg_match('/[\p{L}\p{N}]' . $patron . '|' . $patron . '[\p{L}\p{N}]/iu', (string) $nombre) === 1;
    }

    /**
     * Aplica el renombre a un artículo y devuelve el cambio con la forma de siempre, o null si no hubo
     * cambio (otro dueño, el nombre ya no tiene el texto, o quedaría vacío).
     *
     * @param  \App\Models\Article  $model
     * @param  array  $form
     * @param  \App\Models\User|null  $owner  El dueño de la masiva (en la cola no hay sesión).
     * @return array|null
     */
    public static function aplicar($model, array $form, $owner = null)
    {
        if (!$model || !isset($model->id)) {

            return null;
        }

        // 🔴 El artículo tiene que ser del dueño de la masiva: un id ajeno no se toca.
        if (!is_null($owner) && (int) $model->user_id !== (int) $owner->id) {

            return null;
        }

        $buscar = isset($form['buscar']) ? (string) $form['buscar'] : '';
        $reemplazo = isset($form['reemplazo']) ? (string) $form['reemplazo'] : '';

        if ($buscar === '') {

            return null;
        }

        $antes = (string) $model->name;
        $despues = self::reemplazar($antes, $buscar, $reemplazo);

        if ($despues === $antes || trim($despues) === '') {

            return null;
        }

        $model->name = $despues;
        $model->save();

        return [
            'prop_key'  => self::CLAVE,
            'old_value' => $antes,
            'new_value' => $despues,
            'operation' => self::OPERACION,
            'form_key'  => self::FORM_KEY,
        ];
    }
}
