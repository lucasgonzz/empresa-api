<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Article;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de UN turno del agente de WhatsApp (misión ft-agente-fotos-alternativas-t172, ticket 172).
 *
 * 🔴 POR QUÉ EXISTE. El agente armaba el catálogo de cada turno SÓLO con la búsqueda de los mensajes sin
 * responder, y resolvía la foto SÓLO por `bar_code` (nullable). Así «¿me pasás la foto?» llegaba sin las
 * cajas de las que se venía hablando, y un artículo sin código de barras nunca podía mandar foto. Este
 * helper concentra lo que hace falta para arreglarlo, fuera del service de 1.900 líneas:
 *
 *  - un solo criterio de «tiene foto» (`photo_urls_by_article_id`), que usan el catálogo (flag
 *    `Foto: sí|no`) y el job (la URL a adjuntar);
 *  - la memoria del catálogo entre turnos (`previous_catalog_article_ids` + `carried_articles`);
 *  - la resolución de las marcas `[FOTO:...]` contra el catálogo del turno, nunca contra toda la base
 *    (`resolve_photo_article_ids`): una Ref de otro dueño no resuelve porque no está en su catálogo.
 */
class WhatsappTurnCatalogHelper
{
    /** Tope de artículos que se recuerdan de un turno al siguiente (y de líneas del catálogo en total). */
    const CARRIED_MAX = 16;

    /** Máximo de fotos que puede mandar una respuesta. */
    const PHOTOS_MAX = 4;

    /**
     * Línea que abre el bloque de productos arrastrados del turno anterior.
     * Texto exacto fijado por los tests de la misión.
     */
    const CARRIED_HEADER = '[Nota interna del sistema, el cliente no la ve] Productos de los que ya se venía hablando en esta conversación:';

    /**
     * URL pública de la foto de cada artículo del dueño que tiene una usable.
     *
     * Elige la imagen como `ArticleHelper::primera_imagen_publica` (la marcada `first`, si no la primera) y
     * la normaliza con `ApiUrlHelper::url_publica_de_imagen`. Un artículo sin imagen, con la URL vacía o
     * con una que no se puede armar NO aparece en el resultado: «tiene foto» es exactamente «aparece acá».
     * Acotado al dueño: un id de otro negocio nunca devuelve nada.
     *
     * @param array<int,int|string> $article_ids Ids de artículos.
     * @param int                   $owner_id    Dueño del catálogo (`articles.user_id`).
     * @return array<int,string>                 id => URL absoluta.
     */
    public static function photo_urls_by_article_id(array $article_ids, int $owner_id): array
    {
        // Ids enteros, sin repetidos y sin ceros: lo único que puede viajar a la query.
        $ids = array_values(array_unique(array_filter(array_map('intval', $article_ids))));

        if (empty($ids)) {
            return [];
        }

        // URLs encontradas, por id de artículo.
        $urls = [];

        $articles = Article::where('user_id', $owner_id)
            ->whereIn('id', $ids)
            ->with('images')
            ->get(['id', 'user_id']);

        foreach ($articles as $article) {
            $url = ArticleHelper::primera_imagen_publica($article);

            if (! is_null($url) && trim((string) $url) !== '') {
                $urls[(int) $article->id] = (string) $url;
            }
        }

        return $urls;
    }

    /**
     * Ids de los artículos que el modelo vio en la respuesta MÁS RECIENTE del agente que los registró.
     *
     * Se mira el historial que ya leyó el service (los últimos 20 sin los borradores): si un operador
     * escribió a mano después, igual se recuerda lo del último mensaje del agente (se prefiere recordar
     * de más que olvidar; el encabezado del catálogo ya manda usar sólo lo que sirve).
     *
     * @param \Illuminate\Support\Collection<int, WhatsappChatMessage> $history_messages Historial en orden cronológico.
     * @return array<int,int> Ids, sin repetidos, como mucho `CARRIED_MAX`.
     */
    public static function previous_catalog_article_ids($history_messages): array
    {
        // Del más nuevo al más viejo: la primera salida con ids es la que vale.
        foreach ($history_messages->reverse() as $message) {
            if ($message->direction !== 'out') {
                continue;
            }

            $ids = array_values(array_unique(array_filter(array_map('intval', (array) $message->ai_catalog_article_ids))));

            if (! empty($ids)) {
                return array_slice($ids, 0, self::CARRIED_MAX);
            }
        }

        return [];
    }

    /**
     * Lee los artículos arrastrados del turno anterior, con las mismas columnas que trae la búsqueda.
     *
     * Sólo los activos, sin borrar y del dueño, y sin los que ya están en el catálogo actual. Se devuelven
     * en el orden en que se recordaban.
     *
     * @param array<int,int> $ids             Ids recordados, en orden.
     * @param array<int,int> $already_present Ids que el catálogo del turno ya trae (no se repiten).
     * @param int            $owner_id        Dueño del catálogo.
     * @return array<int,object>              Filas stdClass (id, name, final_price, stock, bar_code, slug, online).
     */
    public static function carried_articles(array $ids, array $already_present, int $owner_id): array
    {
        $pending = array_values(array_diff($ids, $already_present));

        if (empty($pending)) {
            return [];
        }

        $rows = DB::table('articles')
            ->select('id', 'name', 'final_price', 'stock', 'bar_code', 'slug', 'online')
            ->where('user_id', $owner_id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->whereIn('id', $pending)
            ->get();

        // Filas por id, para devolverlas en el orden recordado.
        $by_id = [];
        foreach ($rows as $row) {
            $by_id[(int) $row->id] = $row;
        }

        $ordered = [];
        foreach ($pending as $id) {
            if (isset($by_id[$id])) {
                $ordered[] = $by_id[$id];
            }
        }

        return $ordered;
    }

    /**
     * Resuelve los códigos de las marcas `[FOTO:...]` a ids de artículos DEL CATÁLOGO DEL TURNO.
     *
     * Cada código es `art<ID>` (sin distinguir mayúsculas; el id tiene que estar en el catálogo) o, si no,
     * un `bar_code` igual al de algún artículo del catálogo. Sin repetidos, en orden de escritura y como
     * mucho `PHOTOS_MAX`. Lo que no está en el catálogo del turno (inventado, de otro dueño) se descarta.
     *
     * @param array<int,string>      $codes    Códigos tal como los escribió el modelo.
     * @param array<int,array|object> $articles Artículos que vio el modelo en el turno.
     * @return array<int,int>
     */
    public static function resolve_photo_article_ids(array $codes, array $articles): array
    {
        // Ids del catálogo y mapa bar_code => id (el primero gana si se repite).
        $catalog_ids  = [];
        $by_bar_code  = [];

        foreach ($articles as $article) {
            $id       = (int) (is_array($article) ? (isset($article['id']) ? $article['id'] : 0) : (isset($article->id) ? $article->id : 0));
            $bar_code = trim((string) (is_array($article) ? (isset($article['bar_code']) ? $article['bar_code'] : '') : (isset($article->bar_code) ? $article->bar_code : '')));

            if ($id <= 0) {
                continue;
            }

            $catalog_ids[$id] = true;

            if ($bar_code !== '' && ! isset($by_bar_code[$bar_code])) {
                $by_bar_code[$bar_code] = $id;
            }
        }

        $resolved = [];

        foreach ($codes as $code) {
            $code = trim((string) $code);
            $id   = null;

            if (preg_match('/^art(\d+)$/i', $code, $matches) && isset($catalog_ids[(int) $matches[1]])) {
                $id = (int) $matches[1];
            } elseif (isset($by_bar_code[$code])) {
                $id = $by_bar_code[$code];
            }

            if (! is_null($id) && ! in_array($id, $resolved, true)) {
                $resolved[] = $id;
            }

            if (count($resolved) >= self::PHOTOS_MAX) {
                break;
            }
        }

        return $resolved;
    }
}
