<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Article;
use App\Models\Balanza;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tickets de balanza (misión balanzas-configurables, 3/10/2026).
 *
 * Toda la lógica de negocio de las balanzas vive acá: VenderController, BalanzaController y el
 * comando `balanzas:migrar-desde-extensiones` solo deciden y responden.
 *
 * Hay dos dinámicas, que elige el dueño en `users.tickets_de_balanza`
 * (UserHelper::modo_tickets_de_balanza()):
 *
 *   'plu'       -> el código trae el PLU del artículo y el peso (La Martina). Es la lectura de la
 *                  extensión vieja `plu_balanza_bar_code`, movida acá SIN CAMBIARLE NADA
 *                  (leer_ticket_por_plu()).
 *   'balanzas'  -> el código empieza con el prefijo de una balanza del ABM y trae un importe o un
 *                  peso (Panchito). Reemplaza a la extensión vieja `balanza_bar_code`, que leía
 *                  solo el prefijo '22' y se lo imputaba a un artículo HARDCODEADO
 *                  (leer_ticket_por_balanzas()).
 *
 * 🔴 La regla de leer_ticket_por_balanzas() la repite la SPA (`src/utils/balanzas.js`) para leer
 * tickets sin conexión o con `usar_articles_cache`. Si se cambia acá, se cambia allá: el mismo
 * ticket no puede leerse distinto online y offline.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class BalanzaHelper
{
    /** `users.tickets_de_balanza`: el dueño eligió no usar balanzas (para la lectura, igual que NULL). */
    const MODO_NINGUNO = 'ninguno';

    /** `users.tickets_de_balanza`: lectura PLU (tipo + PLU + peso). */
    const MODO_PLU = 'plu';

    /** `users.tickets_de_balanza`: lectura por las balanzas del ABM. */
    const MODO_BALANZAS = 'balanzas';

    /**
     * Lista blanca de lo que acepta UserController::update() para `tickets_de_balanza`. Cualquier
     * otro valor se ignora (no se guarda): un valor desconocido en la columna dejaría al comercio
     * sin lectura de balanzas sin ningún error a la vista.
     */
    const MODOS = array(self::MODO_NINGUNO, self::MODO_PLU, self::MODO_BALANZAS);

    /** `balanzas.tipo_dato`: el código trae el precio del renglón. */
    const TIPO_IMPORTE = 'importe';

    /** `balanzas.tipo_dato`: el código trae la cantidad (peso) del renglón. */
    const TIPO_PESO = 'peso';

    /**
     * Dígitos de un importe cuando la balanza no dice otra cosa. Es EXACTAMENTE la lectura de la
     * extensión vieja (`substr($codigo, -8)` y de eso los primeros 7), que coincide al peso con los
     * renglones guardados en Panchito: `2202000085805` -> $8.580, `2203000099557` -> $9.955.
     */
    const DIGITOS_IMPORTE_POR_DEFECTO = 7;

    /** Dígitos de un peso cuando la balanza no dice otra cosa: los mismos 5 del ticket PLU. */
    const DIGITOS_PESO_POR_DEFECTO = 5;

    /** Largo máximo del prefijo de una balanza (el plan dice de 1 a 6 dígitos). */
    const PREFIJO_LARGO_MAXIMO = 6;

    /**
     * Tope de `digitos` configurables. Un EAN-13 no da para más y, sobre todo, el valor se
     * convierte a entero: con 19 dígitos o más PHP se pasa del entero de 64 bits.
     */
    const DIGITOS_MAXIMO = 12;

    /** Id de "Gramo" en `unidad_medidas`: con esa unidad el peso NO se divide por 1000. */
    const UNIDAD_MEDIDA_GRAMO = 2;

    /** El prefijo que leía la extensión vieja `balanza_bar_code`. */
    const PREFIJO_EXTENSION_VIEJA = '22';

    /** Las dos extensiones que esta misión deja sin efecto (sus filas NO se borran). */
    const EXTENCION_PLU = 'plu_balanza_bar_code';
    const EXTENCION_IMPORTE = 'balanza_bar_code';

    // ─────────────────────────────────────────────────────────────────────────────
    //  ABM (BalanzaController)
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Los campos de una balanza, normalizados, tal como se guardan.
     *
     * No se usa `$request->validate()` (regla del repo: la validación va en el front); lo que sí se
     * hace acá es dejar cada valor en la forma que la lectura espera, para que un dato raro no
     * produzca un ticket mal leído.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array  ['nombre', 'prefijo', 'article_id', 'tipo_dato', 'digitos']
     */
    static function datos_desde_request($request)
    {
        $nombre = $request->nombre;
        $nombre = is_scalar($nombre) ? trim((string) $nombre) : '';

        $article_id = $request->article_id;
        $article_id = (is_numeric($article_id) && (int) $article_id > 0) ? (int) $article_id : null;

        return array(
            // La columna es de 80: se recorta acá para que un nombre largo no sea un error de SQL.
            'nombre'     => $nombre === '' ? null : mb_substr($nombre, 0, 80),
            'prefijo'    => self::normalizar_prefijo($request->prefijo),
            'article_id' => $article_id,
            'tipo_dato'  => self::normalizar_tipo_dato($request->tipo_dato),
            'digitos'    => self::normalizar_digitos($request->digitos),
        );
    }

    /**
     * Lo que impide guardar una balanza, o null si se puede.
     *
     * Son dos "razones fuertes" para validar del lado de la API (regla del repo: el back no valida
     * salvo razón fuerte documentada):
     *
     *   - Prefijo vacío o de más de 6 dígitos: una balanza sin prefijo se quedaría con TODOS los
     *     códigos que no se encontraron y le cobraría cualquier cosa al artículo de la balanza.
     *   - Prefijo repetido para el mismo dueño: dos balanzas con el mismo código dejan el escaneo
     *     ambiguo para quien las carga (cuál de los dos artículos se lleva el ticket). La lectura
     *     igual desempata en forma determinista (id más chico), pero eso el operador no lo ve.
     *
     * Prefijos que se pisan ('22' y '2203') SÍ están permitidos: gana el más largo, a propósito.
     *
     * @param  array     $datos            Lo que devolvió datos_desde_request().
     * @param  int       $owner_id
     * @param  int|null  $balanza_id_propia  Al editar, la balanza que se edita (no choca consigo misma).
     * @return string|null
     */
    static function error_de_validacion($datos, $owner_id, $balanza_id_propia = null)
    {
        $prefijo = $datos['prefijo'];

        if ($prefijo === '' || strlen($prefijo) > self::PREFIJO_LARGO_MAXIMO) {
            return 'El código de la balanza tiene que tener entre 1 y '.self::PREFIJO_LARGO_MAXIMO.' números.';
        }

        $repetida = Balanza::where('user_id', $owner_id)
                            ->where('prefijo', $prefijo);

        if (!is_null($balanza_id_propia)) {
            $repetida->where('id', '!=', $balanza_id_propia);
        }

        if ($repetida->exists()) {
            return 'Ya tenés otra balanza con el código '.$prefijo;
        }

        return null;
    }

    /**
     * Deja solo los dígitos del prefijo (" 22-03 " -> "2203").
     *
     * @param  mixed  $prefijo
     * @return string
     */
    static function normalizar_prefijo($prefijo)
    {
        if (!is_scalar($prefijo)) {
            return '';
        }

        return preg_replace('/[^0-9]/', '', (string) $prefijo);
    }

    /**
     * 'peso' o 'importe'. Cualquier otra cosa es 'importe', que es el default de la columna y lo
     * que hacía la extensión vieja.
     *
     * @param  mixed  $tipo_dato
     * @return string
     */
    static function normalizar_tipo_dato($tipo_dato)
    {
        if (is_scalar($tipo_dato) && strtolower(trim((string) $tipo_dato)) === self::TIPO_PESO) {
            return self::TIPO_PESO;
        }

        return self::TIPO_IMPORTE;
    }

    /**
     * Los dígitos configurados, o null (= el default por tipo) si vienen vacíos o fuera de rango
     * (de 1 a DIGITOS_MAXIMO). Fuera de rango se toma como vacío y no como error: un 0 en el input
     * numérico quiere decir "no sé", y el default es la lectura que ya funciona.
     *
     * @param  mixed  $digitos
     * @return int|null
     */
    static function normalizar_digitos($digitos)
    {
        if (is_null($digitos) || $digitos === '' || !is_numeric($digitos)) {
            return null;
        }

        $entero = (int) $digitos;

        if ((float) $digitos != $entero || $entero < 1 || $entero > self::DIGITOS_MAXIMO) {
            return null;
        }

        return $entero;
    }
}
