<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\asistente_ia\BusquedaHibridaDeArticulosHelper;
use App\Models\Combo;
use App\Models\PromocionVinoteca;

/**
 * Capa de «mixes, combos y promos» del agente de WhatsApp (misión truvari-agente-mix-promos-t190, ticket 190).
 *
 * POR QUE EXISTE. El agente arma el catálogo de cada turno sólo con artículos. Cuando el cliente pide
 * «el mix Catena», la palabra «mix» se descarta (menos de 4 letras), la búsqueda trae los vinos Catena Zapata
 * y el modelo no tiene otra cosa que ofrecer, aunque «Mix Ernesto Catena» y «Mix Catena Zapata» existan en
 * Promos. Este helper detecta la palabra disparadora, busca por nombre las promos de vinoteca y los combos del
 * dueño, y devuelve un bloque de nota interna para pegar al final del último turno `user`.
 *
 * Modos: '' (sin capa), 'soft' (dijo «promo»: se suma el bloque pero los artículos quedan) y 'strict' (dijo
 * «mix» o «combo»: además los artículos ajenos al pedido no llegan al modelo).
 */
class WhatsappAgentPromosHelper
{
    /** Palabras (ya normalizadas) que activan el modo estricto. */
    const STRICT_WORDS = ['mix', 'mixes', 'combo', 'combos'];

    /** Palabras (ya normalizadas) que activan el modo suave. */
    const SOFT_WORDS = ['promo', 'promos', 'promocion', 'promociones'];

    /** Tope de líneas del bloque. */
    const MAX_LINES = 10;

    /** Encabezado del bloque cuando hay resultados. */
    const HEADER = '[Nota interna del sistema, el cliente no la ve] El cliente nombró un mix, un combo o una promo. Estas son las promos y combos del negocio que coinciden con lo que pidió. Cada uno se vende como una unidad, con su propio precio y su propio stock: no son los productos sueltos del catálogo.';

    /** Regla cuando coincide más de uno. */
    const RULE_SEVERAL = 'Hay más de uno que coincide: antes de confirmar precio o stock, preguntale cuál quiere, nombrándolos.';

    /** Regla cuando coincide uno solo. */
    const RULE_ONE = 'Coincide uno solo: contestá con su precio y su stock.';

    /** Regla cuando el cliente no dijo cuál (lista completa). */
    const RULE_LIST = 'El cliente no dijo cuál: si pregunta qué hay, podés nombrarlos; antes de confirmar precio o stock, preguntale cuál quiere.';

    /** Regla para los que no traen dato de stock. */
    const RULE_NO_STOCK = 'Si uno de la lista no trae Stock, no confirmes que hay: decile que lo consultás y le confirmás.';

    /** Regla cuando la lista se cortó en el tope. */
    const RULE_MORE = 'El negocio tiene más promos y combos que los de esta lista: si busca uno que no está, pedile el nombre.';

    /** Regla del modo estricto. */
    const RULE_STRICT = 'Si no podés confirmar lo que pidió, decilo y no le ofrezcas otros productos en su lugar.';

    /** Bloque del modo estricto cuando ninguna promo ni combo coincide. */
    const NONE = '[Nota interna del sistema, el cliente no la ve] El cliente nombró un mix, un combo o una promo, y ninguna promo ni combo del negocio coincide con lo que pidió. No digas que no existe ni que no hay: decile que lo consultás y le confirmás. No le ofrezcas otro producto en su lugar.';

    /**
     * Resuelve la capa del turno: modo y bloque de texto.
     *
     * @param \Illuminate\Support\Collection $history  Mensajes del chat en orden cronológico.
     * @param string                         $pending  Texto pendiente del cliente (entrantes sin responder).
     * @param int                            $user_id  Dueño del chat (aislamiento entre negocios).
     * @param \App\Models\User|null          $owner_user Dueño, con sus extensiones.
     *
     * @return array{mode: string, block: string, free_words: array} `free_words`: palabras de producto de los
     *         mensajes pendientes SIN disparadora estricta (sólo con el modo derivado del texto pendiente);
     *         el service se las pasa a `keep_strict_articles` para no sacar lo que el cliente pidió además del mix.
     */
    public static function for_turn($history, $pending, $user_id, $owner_user): array
    {
        $empty = ['mode' => '', 'block' => '', 'free_words' => []];

        // Sin dueño no se sabe qué módulos tiene.
        if (is_null($owner_user)) {
            return $empty;
        }

        // Módulos del negocio: promos con vinoteca, combos con combos. Sin ninguno, nada cambia.
        $with_promos = UserHelper::hasExtencion('vinoteca', $owner_user);
        $with_combos = UserHelper::hasExtencion('combos', $owner_user);
        if (! $with_promos && ! $with_combos) {
            return $empty;
        }

        $pending = (string) $pending;

        // Modo por el texto pendiente; si no dispara, por los mensajes ENTRANTES (`in`) del intercambio
        // anterior. El texto saliente (agente, operador, campaña) nunca activa el modo (CA14): sólo sirve
        // para la coincidencia de nombres cuando el modo ya vino de un entrante.
        $mode            = self::mode_of_text($pending);
        $previous        = self::previous_exchange_text($history);
        $from_previous   = false;
        if ($mode === '') {
            $previous_incoming = self::previous_exchange_text($history, true);
            if ($previous_incoming !== '') {
                $mode = self::mode_of_text($previous_incoming);
                $from_previous = ($mode !== '');
            }
        }

        if ($mode === '') {
            return $empty;
        }

        // Coincidencias con el texto pendiente; si el modo vino del intercambio anterior y no hubo
        // ninguna («el primero»), se repite sumando ese intercambio.
        $found = self::matching($pending, $user_id, $with_promos, $with_combos);
        if ($from_previous && empty($found['entries'])) {
            $found = self::matching($pending . "\n" . $previous, $user_id, $with_promos, $with_combos);
        }

        // Palabras libres (CA15): sólo si el modo salió del texto pendiente; con el modo derivado del
        // intercambio anterior se filtra como siempre.
        $free_words = ($mode === 'strict' && ! $from_previous) ? self::free_words_of($pending) : [];

        if (empty($found['entries'])) {
            // Modo estricto: se avisa que no hay coincidencia; modo suave: el turno queda como hoy.
            return ['mode' => $mode, 'block' => $mode === 'strict' ? self::NONE : '', 'free_words' => $free_words];
        }

        $entries = $found['entries'];
        $total   = count($entries);
        $cut     = $found['cut'];

        // Una línea por promo o combo.
        $lines = [];
        foreach ($entries as $entry) {
            $lines[] = self::format_line($entry);
        }

        // Regla según cuántos coinciden o si el cliente no nombró ninguno en particular.
        if ($found['is_list']) {
            $rule = self::RULE_LIST;
        } else {
            $rule = $total > 1 ? self::RULE_SEVERAL : self::RULE_ONE;
        }

        $block = self::HEADER . "\n" . implode("\n", $lines) . "\n" . $rule . "\n" . self::RULE_NO_STOCK;
        if ($cut) {
            $block .= "\n" . self::RULE_MORE;
        }
        if ($mode === 'strict') {
            $block .= "\n" . self::RULE_STRICT;
        }

        return ['mode' => $mode, 'block' => $block, 'free_words' => $free_words];
    }

    /**
     * En modo estricto deja los artículos cuyo nombre trae «mix» o «combo» como palabra y, además, los que
     * coinciden (misma palabra) con alguna palabra libre: lo que el cliente pidió en un mensaje aparte del mix.
     * Acepta objetos o arrays (lo que devuelve la búsqueda y lo que arrastra el turno anterior).
     *
     * @param array $articles
     * @param array $free_words Palabras libres que devolvió `for_turn` (vacío = sólo mix/combo, como antes).
     *
     * @return array Mismos elementos, sin los ajenos al pedido, con índices renumerados.
     */
    public static function keep_strict_articles(array $articles, array $free_words = []): array
    {
        $kept = [];

        foreach ($articles as $article) {
            // Nombre del artículo, venga como array u objeto.
            if (is_array($article)) {
                $name = isset($article['name']) ? $article['name'] : '';
            } else {
                $name = (is_object($article) && isset($article->name)) ? $article->name : '';
            }

            // Es mix/combo por nombre, o el cliente nombró algo de su nombre en un mensaje aparte.
            if (self::has_word_of((string) $name, self::STRICT_WORDS)
                || (! empty($free_words) && self::has_word_of((string) $name, $free_words))) {
                $kept[] = $article;
            }
        }

        return $kept;
    }

    /**
     * Modo de un texto: estricto gana sobre suave; '' si no hay disparadora.
     *
     * @param string $text
     *
     * @return string
     */
    protected static function mode_of_text(string $text): string
    {
        $tokens = BusquedaHibridaDeArticulosHelper::tokens($text);

        foreach ($tokens as $token) {
            if (in_array($token, self::STRICT_WORDS, true)) {
                return 'strict';
            }
        }
        foreach ($tokens as $token) {
            if (in_array($token, self::SOFT_WORDS, true)) {
                return 'soft';
            }
        }

        return '';
    }

    /**
     * Indica si algún token del texto es una de las palabras dadas (palabra entera, singular/plural).
     *
     * @param string $text
     * @param array  $words
     *
     * @return bool
     */
    protected static function has_word_of(string $text, array $words): bool
    {
        foreach (BusquedaHibridaDeArticulosHelper::tokens($text) as $token) {
            foreach ($words as $word) {
                if (BusquedaHibridaDeArticulosHelper::misma_palabra($token, $word)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Palabras libres del texto pendiente: palabras de producto (sólo letras) de los mensajes pendientes
     * que NO traen disparadora estricta. Los pendientes se parten por línea (el service los une con «\n»).
     * Si el mix y el vino van en el mismo mensaje no hay palabras libres (limitación aceptada).
     *
     * @param string $pending
     *
     * @return array<int, string>
     */
    protected static function free_words_of(string $pending): array
    {
        $words = [];

        foreach (preg_split('/\R/u', $pending) as $line) {
            // Un mensaje con disparadora estricta no aporta palabras libres.
            if (self::mode_of_text($line) === 'strict') {
                continue;
            }

            foreach (BusquedaHibridaDeArticulosHelper::palabras_de_producto($line) as $word) {
                if (! BusquedaHibridaDeArticulosHelper::es_de_letras($word)) {
                    continue;
                }
                if (in_array($word, self::SOFT_WORDS, true) || in_array($word, $words, true)) {
                    continue;
                }
                $words[] = $word;
            }
        }

        return $words;
    }

    /**
     * Texto del intercambio anterior a los entrantes pendientes: el bloque `out` inmediatamente anterior
     * más el bloque `in` previo a ese `out`. Vacío si el historial termina en `out` o no hay intercambio.
     *
     * @param \Illuminate\Support\Collection $history
     * @param bool                           $only_incoming Si es true devuelve sólo el bloque `in` previo (la
     *                                                      disparadora se lee únicamente de entrantes).
     *
     * @return string
     */
    protected static function previous_exchange_text($history, bool $only_incoming = false): string
    {
        $count = $history->count();

        // Inicio de los entrantes pendientes (bloque `in` final).
        $index = $count - 1;
        while ($index >= 0 && $history->get($index)->direction === 'in') {
            $index--;
        }

        // El historial termina en `out` (o está vacío): no hay intercambio anterior.
        if ($index === $count - 1) {
            return '';
        }

        // Bloque `out` anterior y, antes de él, el bloque `in`.
        $bodies = [];
        while ($index >= 0 && $history->get($index)->direction !== 'in') {
            // Los salientes sólo cuentan para la coincidencia de nombres, nunca para el modo.
            if (! $only_incoming) {
                array_unshift($bodies, trim((string) $history->get($index)->body));
            }
            $index--;
        }
        while ($index >= 0 && $history->get($index)->direction === 'in') {
            array_unshift($bodies, trim((string) $history->get($index)->body));
            $index--;
        }

        return trim(implode("\n", array_filter($bodies, function ($body) {
            return $body !== '';
        })));
    }

    /**
     * Busca por nombre las promos y combos del dueño que coinciden con el texto.
     *
     * @param string $text
     * @param int    $user_id
     * @param bool   $with_promos
     * @param bool   $with_combos
     *
     * @return array{entries: array, cut: bool, is_list: bool}
     */
    protected static function matching(string $text, int $user_id, bool $with_promos, bool $with_combos): array
    {
        // Palabras de letras de la consulta, sin las disparadoras.
        $query_words = [];
        foreach (BusquedaHibridaDeArticulosHelper::palabras_de_producto($text) as $word) {
            if (! BusquedaHibridaDeArticulosHelper::es_de_letras($word)) {
                continue;
            }
            if (in_array($word, self::STRICT_WORDS, true) || in_array($word, self::SOFT_WORDS, true)) {
                continue;
            }
            $query_words[] = $word;
        }

        // Candidatos del dueño (Eloquent respeta el soft delete). Cada uno: type, name, price, model.
        $candidates = [];
        if ($with_promos) {
            $promos = PromocionVinoteca::where('user_id', $user_id)->get(['id', 'name', 'final_price', 'stock']);
            foreach ($promos as $promo) {
                $candidates[] = ['type' => 'promo', 'order' => 0, 'name' => (string) $promo->name, 'price' => $promo->final_price, 'model' => $promo];
            }
        }
        if ($with_combos) {
            $combos = Combo::where('user_id', $user_id)->get(['id', 'name', 'price']);
            foreach ($combos as $combo) {
                $candidates[] = ['type' => 'combo', 'order' => 1, 'name' => (string) $combo->name, 'price' => $combo->price, 'model' => $combo];
            }
        }

        // Cobertura de cada candidato: palabras de la consulta que encajan con un token de su nombre.
        $is_list = empty($query_words);
        $best    = 0;
        foreach ($candidates as $key => $candidate) {
            $name_tokens = BusquedaHibridaDeArticulosHelper::tokens($candidate['name']);
            $coverage    = 0;
            foreach ($query_words as $word) {
                foreach ($name_tokens as $name_token) {
                    if (BusquedaHibridaDeArticulosHelper::misma_palabra($word, $name_token)) {
                        $coverage++;
                        break;
                    }
                }
            }
            $candidates[$key]['coverage'] = $coverage;
            $best = max($best, $coverage);
        }

        // Lista: todos. Si no: los de cobertura máxima, que tiene que ser al menos 1.
        $selected = [];
        if ($is_list) {
            $selected = $candidates;
        } elseif ($best >= 1) {
            foreach ($candidates as $candidate) {
                if ($candidate['coverage'] === $best) {
                    $selected[] = $candidate;
                }
            }
        }

        // Orden por nombre; a igual nombre, promos antes que combos.
        usort($selected, function ($a, $b) {
            $by_name = strcmp(mb_strtolower($a['name'], 'UTF-8'), mb_strtolower($b['name'], 'UTF-8'));

            return $by_name !== 0 ? $by_name : ($a['order'] - $b['order']);
        });

        // Tope de líneas.
        $cut = count($selected) > self::MAX_LINES;
        if ($cut) {
            $selected = array_slice($selected, 0, self::MAX_LINES);
        }

        // Stock: el de la promo es una columna; el del combo se calcula sólo para los que quedaron.
        $entries = [];
        foreach ($selected as $candidate) {
            $stock = $candidate['type'] === 'promo' ? $candidate['model']->stock : $candidate['model']->stock_disponible;
            $entries[] = [
                'type'  => $candidate['type'],
                'name'  => $candidate['name'],
                'price' => $candidate['price'],
                'stock' => $stock,
            ];
        }

        return ['entries' => $entries, 'cut' => $cut, 'is_list' => $is_list];
    }

    /**
     * Línea de una promo o combo: sin código de barras ni link; precio y stock sólo si hay dato.
     *
     * @param array $entry type, name, price, stock.
     *
     * @return string
     */
    protected static function format_line(array $entry): string
    {
        $line = '- ' . ($entry['type'] === 'promo' ? 'Promo' : 'Combo') . ': ' . $entry['name'];

        if (! is_null($entry['price']) && $entry['price'] !== '') {
            $line .= ' | Precio: $' . number_format((float) $entry['price'], 2, ',', '.');
        }

        if (! is_null($entry['stock']) && $entry['stock'] !== '' && is_numeric($entry['stock'])) {
            $stock = (float) $entry['stock'];
            $line .= ' | Stock: ' . (floor($stock) == $stock ? (string) (int) $stock : (string) $stock);
        }

        return $line;
    }
}
