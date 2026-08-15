<?php

namespace App\Services;

use App\Models\Article;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Servicio de embeddings vectoriales para artículos del catálogo.
 *
 * Genera representaciones vectoriales de artículos usando el modelo
 * text-embedding-3-small de OpenAI (1536 dimensiones) y las persiste
 * en la columna embedding de la tabla articles para búsqueda semántica.
 *
 * El patrón de cliente HTTP sigue el mismo esquema de build_http_client()
 * utilizado en SupportAiSuggestionService (admin-api), incluyendo el
 * manejo de verificación TLS configurable por entorno.
 */
class ArticleEmbeddingService
{
    /**
     * Endpoint de la API de embeddings de OpenAI.
     */
    private const OPENAI_EMBEDDINGS_URL = 'https://api.openai.com/v1/embeddings';

    /**
     * Modelo de embeddings a usar. text-embedding-3-small produce
     * vectores de 1536 dimensiones con buena relación costo/calidad.
     */
    private const EMBEDDING_MODEL = 'text-embedding-3-small';

    /**
     * Techo de caracteres del bloque de descripciones dentro del texto a vectorizar.
     *
     * `descriptions.content` es una columna `text` y un artículo puede tener varias
     * descripciones, así que sin techo un caso patológico (una ficha técnica pegada entera)
     * se pasa de los 8192 tokens que acepta text-embedding-3-small. OpenAI no trunca: responde
     * error, y `GenerateArticleEmbeddingJob` reintenta tres veces antes de darse por vencido.
     * 4000 caracteres son ~1000 tokens, muy holgado para una descripción real de catálogo y
     * lejísimos del límite aun sumando nombre, categoría, marca y código.
     */
    private const DESCRIPTIONS_MAX_CHARS = 4000;

    /**
     * Genera el vector de embedding para un texto arbitrario llamando a la API de OpenAI.
     *
     * @param string $text Texto a embeddear. Debe ser no vacío.
     *
     * @return array<int, float> Array de floats con las 1536 dimensiones del vector.
     *
     * @throws \RuntimeException Si la API responde con error o el payload es inesperado.
     */
    public function generate_embedding(string $text): array
    {
        // Validación mínima: evitar llamadas vacías que OpenAI rechazaría.
        $text = trim($text);
        if ($text === '') {
            throw new \RuntimeException('ArticleEmbeddingService: el texto para embeddear no puede estar vacío.');
        }

        $http = $this->build_http_client();

        $response = $http->post(self::OPENAI_EMBEDDINGS_URL, [
            'model' => self::EMBEDDING_MODEL,
            'input' => $text,
        ]);

        if ($response->failed()) {
            // Extraer mensaje de error de OpenAI si está disponible.
            $error_body  = $response->json();
            $error_msg   = '';

            if (is_array($error_body) && isset($error_body['error']['message'])) {
                $error_msg = (string) $error_body['error']['message'];
            }

            throw new \RuntimeException(
                'ArticleEmbeddingService: error HTTP '.$response->status().' en OpenAI.'
                .($error_msg !== '' ? ' '.$error_msg : '')
            );
        }

        // El vector viene en data[0].embedding
        $embedding = $response->json('data.0.embedding');

        if (! is_array($embedding) || empty($embedding)) {
            throw new \RuntimeException(
                'ArticleEmbeddingService: respuesta inesperada de OpenAI; no se encontró el vector en data[0].embedding.'
            );
        }

        return $embedding;
    }

    /**
     * Construye el texto que se enviará a OpenAI para representar un artículo.
     *
     * Concatena los campos relevantes del artículo usando el formato
     * "campo: valor | campo: valor", omitiendo silenciosamente los que sean nulos.
     *
     * 🔴 LA DESCRIPCIÓN ES EL CAMPO QUE MÁS PESA ACÁ, y hasta la misión whatsapp-agente no
     * entraba. El código leía `descriptions->first()->body`, y la tabla `descriptions` NO
     * tiene columna `body`: sus columnas de texto son `title` y `content`. El `?? ''` se comía
     * el nulo sin ruido, así que desde que existe el módulo el texto vectorizado de todos los
     * artículos fue siempre "nombre | categoría | marca | código", sin una palabra de
     * descripción — y el comentario de este bloque afirmaba lo contrario.
     *
     * Por qué importa tanto: el nombre comercial y la categoría no dicen nada sobre para qué
     * sirve un producto. Que un cliente pregunte "algo para pintar una pared con humedad" y el
     * agente encuentre el producto correcto depende enteramente de que la descripción esté
     * adentro del vector. Sin ella la búsqueda semántica corría sobre casi nada.
     *
     * @param Article $article Artículo. Idealmente con category, brand y descriptions ya
     *                         cargadas (así lo hace `GenerateArticleEmbeddingJob`); si no
     *                         vinieran, se resuelven por lazy load como el resto de los campos.
     *
     * @return string Texto listo para embeddear. Puede ser vacío si ningún campo está disponible.
     */
    public function embedding_for_article(Article $article): string
    {
        // Partes del texto; cada campo se agrega solo si tiene valor.
        $parts = [];

        // Nombre del artículo: campo más importante para la búsqueda.
        $name = trim((string) ($article->name ?? ''));
        if ($name !== '') {
            $parts[] = 'nombre: '.$name;
        }

        // Categoría: ayuda a filtrar por tipo de producto en lenguaje natural.
        $category_name = trim((string) ($article->category->name ?? ''));
        if ($category_name !== '') {
            $parts[] = 'categoría: '.$category_name;
        }

        // Marca: relevante para búsquedas como "aceite de marca X".
        $brand_name = trim((string) ($article->brand->name ?? ''));
        if ($brand_name !== '') {
            $parts[] = 'marca: '.$brand_name;
        }

        // Código de barras: permite búsquedas exactas por EAN/UPC.
        $bar_code = trim((string) ($article->bar_code ?? ''));
        if ($bar_code !== '') {
            $parts[] = 'código: '.$bar_code;
        }

        // Descripción: lo único que le da significado semántico al artículo. Ver el bloque
        // rojo del docblock.
        $descriptions_text = $this->descriptions_text($article);
        if ($descriptions_text !== '') {
            $parts[] = 'descripción: '.$descriptions_text;
        }

        return implode(' | ', $parts);
    }

    /**
     * Arma el bloque de descripciones del artículo para el texto a vectorizar.
     *
     * Decisiones, porque ninguna es obvia:
     *
     * - **Se leen `title` Y `content`, no solo `content`.** Son las dos columnas de texto
     *   reales de la tabla, y las dos las escribe una persona desde el ABM de artículos
     *   (`Descripciones` → campo "Titulo" + textarea). El título funciona como la clave de qué
     *   habla el contenido —"Uso", "Material", "Aplicación", "Medidas"— y es justo el eje que
     *   necesita una consulta del estilo "algo para pintar una pared con humedad". Cuesta unos
     *   pocos tokens y aporta el encabezado semántico, así que entran los dos.
     *
     * - **Se usan TODAS las descripciones, no la primera.** `Article::descriptions()` es un
     *   `hasMany` y el ABM deja cargar varias, una por aspecto del producto. Quedarse con la
     *   primera tira justamente los aspectos que no son el primero, que es donde suele estar
     *   el dato por el que el cliente pregunta.
     *
     * - **Se ordena explícitamente por id.** El texto que devuelve este método se hashea
     *   (`sha1`) en `GenerateArticleEmbeddingJob` y ese hash es lo que decide si el artículo se
     *   re-vectoriza. Un `hasMany` sin `orderBy` no garantiza orden estable, y si el orden
     *   cambiara entre corridas el hash cambiaría solo, pagándole a OpenAI una llamada por
     *   artículo sin que nadie haya tocado nada.
     *
     * - **No hay guard de `relationLoaded()`.** El que estaba salteaba la descripción entera y
     *   en silencio cuando la relación no venía precargada. Hoy el único caller
     *   (`GenerateArticleEmbeddingJob`) la carga con `Article::with([...])`, pero si mañana
     *   aparece otro que no, es preferible una query de más que un embedding mudo. Es además
     *   el mismo criterio que ya usan `category` y `brand` un par de líneas más arriba.
     *
     * @param Article $article
     *
     * @return string Vacío si el artículo no tiene descripciones con texto.
     */
    private function descriptions_text(Article $article): string
    {
        $descriptions = $article->descriptions;

        if (is_null($descriptions) || $descriptions->isEmpty()) {
            return '';
        }

        $bloques = [];

        foreach ($descriptions->sortBy('id') as $description) {
            $title   = trim((string) ($description->title ?? ''));
            $content = trim((string) ($description->content ?? ''));

            if ($title !== '' && $content !== '') {
                $bloques[] = $title.': '.$content;
            } elseif ($content !== '') {
                $bloques[] = $content;
            } elseif ($title !== '') {
                // Descripción cargada solo con título: poco, pero sigue siendo señal.
                $bloques[] = $title;
            }
        }

        if (empty($bloques)) {
            return '';
        }

        // Separador distinto al ' | ' de los campos de afuera, para que se lea dónde termina
        // una descripción y empieza la otra.
        $texto = implode(' ; ', $bloques);

        // Techo defensivo contra el límite de tokens de OpenAI (ver DESCRIPTIONS_MAX_CHARS).
        if (mb_strlen($texto) > self::DESCRIPTIONS_MAX_CHARS) {
            $texto = mb_substr($texto, 0, self::DESCRIPTIONS_MAX_CHARS);
        }

        return $texto;
    }

    /**
     * Genera el embedding del artículo y lo persiste en la columna embedding
     * de la tabla articles usando una sentencia SQL cruda.
     *
     * Se usa SQL crudo porque el tipo vector() de pgvector no tiene soporte
     * nativo en el ORM de Laravel 8; el cast ::vector es necesario en Postgres.
     *
     * @param Article $article Artículo con relaciones category, brand y descriptions cargadas.
     *
     * @return void
     *
     * @throws \RuntimeException Si generate_embedding() falla.
     */
    public function update_article_embedding(Article $article): void
    {
        // Construir texto representativo del artículo.
        $text = $this->embedding_for_article($article);

        if ($text === '') {
            Log::channel('daily')->warning('ArticleEmbeddingService: artículo sin texto para embeddear.', [
                'article_id' => $article->id,
            ]);
            return;
        }

        // Obtener vector como array de floats.
        $embedding = $this->generate_embedding($text);

        if ($this->uses_pgvector()) {
            // PostgreSQL: literal [f1,f2,...] con cast ::vector.
            $vector_string = '['.implode(',', $embedding).']';

            DB::statement(
                'UPDATE articles SET embedding = ?::vector WHERE id = ?',
                [$vector_string, $article->id]
            );

            return;
        }

        // MySQL / otros: persistir el array como JSON.
        DB::table('articles')
            ->where('id', $article->id)
            ->update(['embedding' => json_encode($embedding)]);
    }

    /**
     * Busca artículos similares semánticamente a una consulta de texto.
     *
     * Genera el embedding del query y ejecuta una búsqueda de vecinos más
     * cercanos usando el operador <=> (distancia de coseno) de pgvector,
     * filtrando por user_id, status activo y registros no eliminados.
     *
     * @param string $query   Texto de búsqueda en lenguaje natural.
     * @param int    $user_id ID del usuario/tenant propietario de los artículos.
     * @param int    $limit   Número máximo de resultados a retornar (default: 8).
     *
     * @return Collection Colección de objetos stdClass con id, name, final_price, stock,
     *                    bar_code, slug y online. El slug viaja para que el agente de WhatsApp
     *                    pueda armar el link público del artículo en la tienda online del
     *                    negocio, y `online` para que sepa si ese link se puede visitar.
     *
     * 🔴 `articles.online` SE SELECCIONA PERO NO SE FILTRA, y la diferencia es deliberada.
     * `online` es el flag de "publicado en la tienda": el ecommerce lista con
     * `->where('online', 1)`, así que un artículo con `online = 0` existe en el ERP pero su
     * URL pública da 404. Un negocio típico tiene miles de artículos cargados y solo una
     * fracción publicada.
     *
     * Filtrar acá sería el error fácil: el artículo tiene que SEGUIR apareciendo en el catálogo
     * que ve la IA, porque el cliente puede preguntar por precio o stock de algo que se vende
     * en el mostrador y no en la web, y esa respuesta es correcta y útil. Lo que no corresponde
     * es darle un link a una página que no puede visitar. Por eso la columna viaja hasta
     * `WhatsappBotAiService::article_url()`, que es donde se decide armar el link o no.
     *
     * @throws \RuntimeException Si generate_embedding() falla.
     */
    public function search_similar_articles(string $query, int $user_id, int $limit = 8): Collection
    {
        // Generar embedding del query para comparar contra los artículos.
        $query_embedding = $this->generate_embedding($query);

        if ($this->uses_pgvector()) {
            $vector_string = '['.implode(',', $query_embedding).']';

            $results = DB::select(
                'SELECT id, name, final_price, stock, bar_code, slug, online
                 FROM articles
                 WHERE user_id = ?
                   AND status = ?
                   AND deleted_at IS NULL
                   AND embedding IS NOT NULL
                 ORDER BY embedding <=> ?::vector
                 LIMIT ?',
                [$user_id, 'active', $vector_string, $limit]
            );

            return collect($results);
        }

        return $this->search_similar_articles_in_php($query_embedding, $user_id, $limit);
    }

    /**
     * Indica si el driver activo soporta pgvector (solo PostgreSQL).
     *
     * @return bool
     */
    protected function uses_pgvector(): bool
    {
        return DB::getDriverName() === 'pgsql';
    }

    /**
     * Búsqueda por similitud de coseno en PHP para entornos sin pgvector (p. ej. MySQL en WAMP).
     *
     * @param array<int, float> $query_embedding Vector de la consulta.
     * @param int               $user_id         Tenant propietario del catálogo.
     * @param int               $limit           Cantidad máxima de resultados.
     *
     * @return Collection
     */
    protected function search_similar_articles_in_php(array $query_embedding, int $user_id, int $limit): Collection
    {
        // `online` viaja igual que en la rama pgvector, y por el mismo motivo: se selecciona
        // para que el agente sepa si puede pasar el link, pero NO se filtra, para que el
        // artículo siga estando en el catálogo con su precio y su stock. El razonamiento
        // completo está en el docblock de `search_similar_articles()`.
        $rows = DB::table('articles')
            ->select('id', 'name', 'final_price', 'stock', 'bar_code', 'slug', 'online', 'embedding')
            ->where('user_id', $user_id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->whereNotNull('embedding')
            ->get();

        $scored = [];

        foreach ($rows as $row) {
            $article_embedding = json_decode((string) $row->embedding, true);

            if (! is_array($article_embedding) || empty($article_embedding)) {
                continue;
            }

            $similarity = $this->cosine_similarity($query_embedding, $article_embedding);

            $scored[] = [
                'similarity' => $similarity,
                'row'        => $row,
            ];
        }

        usort($scored, function ($left, $right) {
            if ($left['similarity'] === $right['similarity']) {
                return 0;
            }

            return $left['similarity'] < $right['similarity'] ? 1 : -1;
        });

        $top_rows = array_slice($scored, 0, $limit);
        $results  = [];

        foreach ($top_rows as $item) {
            $row = $item['row'];
            unset($row->embedding);
            $results[] = $row;
        }

        return collect($results);
    }

    /**
     * Similitud de coseno entre dos vectores del mismo tamaño.
     *
     * @param array<int, float> $vector_a
     * @param array<int, float> $vector_b
     *
     * @return float Valor entre -1 y 1; mayor = más similar.
     */
    protected function cosine_similarity(array $vector_a, array $vector_b): float
    {
        $length = min(count($vector_a), count($vector_b));

        if ($length === 0) {
            return 0.0;
        }

        $dot_product = 0.0;
        $norm_a      = 0.0;
        $norm_b      = 0.0;

        for ($index = 0; $index < $length; $index++) {
            $value_a = (float) $vector_a[$index];
            $value_b = (float) $vector_b[$index];

            $dot_product += $value_a * $value_b;
            $norm_a      += $value_a * $value_a;
            $norm_b      += $value_b * $value_b;
        }

        if ($norm_a <= 0.0 || $norm_b <= 0.0) {
            return 0.0;
        }

        return $dot_product / (sqrt($norm_a) * sqrt($norm_b));
    }

    /**
     * Construye el cliente HTTP hacia la API de OpenAI con las cabeceras
     * de autenticación y la configuración TLS del entorno.
     *
     * El manejo de verify_ssl y ca_bundle sigue el mismo patrón que
     * SupportAiSuggestionService::build_http_client() en admin-api,
     * necesario en entornos Windows/WAMP donde el CA bundle suele fallar.
     *
     * @return PendingRequest
     */
    protected function build_http_client(): PendingRequest
    {
        // Clave de API de OpenAI configurada en services.openai.api_key.
        $api_key = (string) config('services.openai.api_key', '');

        $http = Http::withHeaders([
            'Authorization' => 'Bearer '.$api_key,
            'Content-Type'  => 'application/json',
        ])->timeout(30);

        // Usar la misma configuración TLS que Anthropic para consistencia entre entornos.
        $verify_ssl = (bool) config('services.anthropic.verify_ssl', true);
        $ca_bundle  = config('services.anthropic.ca_bundle');

        if (! $verify_ssl) {
            $http = $http->withoutVerifying();
        } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
            $http = $http->withOptions(['verify' => $ca_bundle]);
        }

        return $http;
    }
}
