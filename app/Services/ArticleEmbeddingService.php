<?php

namespace App\Services;

use App\Http\Controllers\Helpers\AiTokenUsageHelper;
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
 *
 * 🔴 LA BÚSQUEDA EN MYSQL NO LEE `articles.embedding` (misión rag-whatsapp-memoria-acotada,
 * 28/9/2026). Hasta esa misión, cada mensaje del cliente traía a memoria el JSON de 1536 floats
 * (~28 KB) de TODOS los artículos del dueño y los decodificaba a la vez; en catálogos grandes el
 * proceso moría por memoria (demo3: 768 MB agotados en cada "Sugerir respuesta") y el navegador lo
 * veía como un error de CORS. Hoy cada artículo tiene además un vector COMPACTO en la tabla
 * `article_compact_embeddings` —los primeros 512 valores, normalizados, en float32 binario: 2 KB—
 * y la búsqueda recorre esa tabla por tandas quedándose solo con los mejores K, así que la memoria
 * no depende del tamaño del catálogo. El JSON completo sigue siendo la fuente de verdad: el
 * compacto se deriva de él (en `persistir_embedding()` o, de forma perezosa, en la propia
 * búsqueda) y nunca se le pide nada a OpenAI para armarlo.
 */
class ArticleEmbeddingService
{
    /**
     * Cantidad de dimensiones del vector compacto que usa la búsqueda en MySQL.
     *
     * `text-embedding-3-small` está entrenado con Matryoshka Representation Learning: OpenAI
     * documenta que sus vectores se pueden acortar quedándose con los primeros N valores y
     * re-normalizando, sin que el vector pierda las propiedades que representan el concepto. Así se
     * evita re-generar nada ni pagarle a OpenAI otra vez: el compacto sale del vector que ya está.
     *
     * 512 es el punto medio razonable: 3x menos multiplicaciones que 1536 por artículo y 2048 bytes
     * por fila en vez de ~28 KB de JSON (14x menos bytes leídos de MySQL por búsqueda), con una
     * calidad de ranking que OpenAI reporta muy cerca de la del vector completo.
     *
     * 🔴 Si alguien cambia este número, los compactos ya guardados quedan con otra longitud: el
     * producto punto corta en la más corta de las dos y el ranking se degrada sin ningún error. En
     * ese caso hay que vaciar `article_compact_embeddings` y volver a correr
     * `articles:compactar-embeddings`.
     */
    const DIMENSIONES_COMPACTAS = 512;

    /**
     * Artículos por tanda en el recorrido de la búsqueda.
     *
     * Es lo que acota la memoria: en el peor caso (artículos todavía sin compacto, que traen su
     * JSON de ~28 KB para compactarlo al vuelo) una tanda son ~14 MB; con todo compactado, ~1 MB.
     * Tandas más chicas suman idas y vueltas a MySQL sin ganar nada que importe; más grandes
     * vuelven a acercar el pico de memoria al tamaño del catálogo, que es justo lo que se quiere
     * evitar.
     */
    const TANDA_BUSQUEDA = 500;

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
     * Proveedor que se imputa en `ai_token_usages`. Este servicio es el único que NO le
     * paga a Anthropic, y por eso la columna existe: `text-embedding-3-small` no dice
     * "openai" en ningún lado y el costo se calcula por proveedor.
     */
    const PROVEEDOR = 'openai';

    /** Indexar el catálogo: una llamada por artículo que cambió. Lo dispara el scheduler. */
    const PROCESO_ARTICULOS = 'embeddings_articulos';

    /**
     * La búsqueda semántica del RAG: una llamada por CADA respuesta del agente de WhatsApp.
     *
     * Va separado de `embeddings_articulos` a propósito. Son gastos de naturaleza distinta
     * —uno es de alta, el otro es de tráfico— y mezclarlos esconde justo lo que se quiere
     * ver: indexar el catálogo se paga una vez, buscar se paga en cada conversación.
     */
    const PROCESO_BUSQUEDA = 'embeddings_busqueda';

    /**
     * Genera el vector de embedding para un texto arbitrario llamando a la API de OpenAI.
     *
     * Los tres parámetros de metering van AL FINAL y son opcionales para no romper a ningún
     * llamador: este método es público y lo usa también el comando que hornea los embeddings
     * de la semilla, que no le imputa el gasto a ningún comercio.
     *
     * @param string   $text          Texto a embeddear. Debe ser no vacío.
     * @param int|null $user_id       Dueño al que se le imputa el consumo. Sin él no se registra nada.
     * @param string|null $proceso    PROCESO_ARTICULOS | PROCESO_BUSQUEDA.
     * @param int|null $referencia_id Id del artículo, cuando el gasto es de indexación.
     *
     * @return array<int, float> Array de floats con las 1536 dimensiones del vector.
     *
     * @throws \RuntimeException Si la API responde con error o el payload es inesperado.
     */
    public function generate_embedding(string $text, $user_id = null, $proceso = null, $referencia_id = null): array
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

        /*
         * El registro va acá: la llamada salió bien (o sea, se pagó) y el body todavía está
         * entero. Unas líneas más abajo el método se queda solo con el vector y el bloque
         * `usage` deja de existir. Si la llamada hubiera fallado no se registra nada: OpenAI
         * no cobra una request rechazada.
         *
         * 🔴 UNA FILA POR LLAMADA HTTP, sin agregar. GenerateArticleEmbeddingJob tiene
         * $tries = 3: un artículo que reintenta deja tres filas, y está bien — se pagaron las
         * tres. Un contador "por artículo" mostraría un tercio del gasto real.
         */
        $this->registrar_consumo($response->json(), $user_id, $proceso, $referencia_id);

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
     * Imputa el consumo de una llamada de embeddings, traduciendo el `usage` de OpenAI al
     * esquema de `ai_token_usages`, que nació con la forma del de Anthropic.
     *
     * 🔴 LA TRADUCCIÓN NO ES OBVIA Y ES LO ÚNICO DELICADO DE ESTE MÉTODO. OpenAI devuelve
     * `usage.prompt_tokens` y `usage.total_tokens`; no manda `output_tokens` porque un
     * embedding no genera texto, y tampoco tiene caché de prompt. El mapeo es:
     *
     *   prompt_tokens  ->  input_tokens
     *   output_tokens  ->  0   (no existe: el vector no se cobra como salida)
     *   las dos de caché -> 0  (OpenAI no cachea prompts acá)
     *
     * `total_tokens` NO se guarda: en esta API es igual a `prompt_tokens`, y guardarlo en
     * `input_tokens` sería contar lo mismo dos veces el día que dejen de coincidir.
     *
     * Sin dueño o sin proceso no se registra: es el mismo criterio del helper, adelantado
     * acá para no ensuciar el log con un warning por cada artículo del comando de semilla,
     * que legítimamente no le imputa el gasto a ningún comercio.
     *
     * @param  mixed       $body          Respuesta ya decodificada de OpenAI.
     * @param  int|null    $user_id
     * @param  string|null $proceso
     * @param  int|null    $referencia_id
     * @return void
     */
    protected function registrar_consumo($body, $user_id, $proceso, $referencia_id): void
    {
        if (empty($user_id) || empty($proceso)) {
            return;
        }

        $body  = is_array($body) ? $body : [];
        $usage = isset($body['usage']) && is_array($body['usage']) ? $body['usage'] : [];

        /*
         * 🔴 EL GUARD TIENE QUE ESTAR ACÁ Y NO EN EL HELPER, porque abajo el `usage` se traduce
         * a las cuatro claves de Anthropic y a partir de esa línea las cuatro existen siempre,
         * con valor cero. O sea que el aviso del helper nunca se dispararía para OpenAI: este
         * es el último punto donde todavía se puede ver que `prompt_tokens` no vino.
         *
         * Importa porque el síntoma de que OpenAI cambie el formato no sería un error sino que
         * el gasto de embeddings de todos los clientes baje a cero de un día para el otro, que
         * es exactamente lo mismo que se ve cuando un comercio no usa la IA.
         */
        if (! isset($usage['prompt_tokens'])) {

            Log::channel('daily')->warning(
                'ArticleEmbeddingService: OpenAI respondió sin usage.prompt_tokens; el consumo se registra en cero.',
                [
                    'proceso'          => (string) $proceso,
                    'claves_recibidas' => array_keys($usage),
                ]
            );
        }

        AiTokenUsageHelper::registrar([
            'user_id'   => (int) $user_id,
            'proceso'   => (string) $proceso,
            'proveedor' => self::PROVEEDOR,

            // El modelo, tal como lo devolvió OpenAI; si no vino, el que pedimos.
            'modelo' => isset($body['model']) && (string) $body['model'] !== ''
                ? (string) $body['model']
                : self::EMBEDDING_MODEL,

            'usage' => [
                'input_tokens'                => isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : 0,
                'output_tokens'               => 0,
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens'     => 0,
            ],

            /*
             * auth_user_id queda null siempre: las dos puntas que llaman acá son automáticas
             * (el job del scheduler que indexa, y el agente de WhatsApp contestándole a un
             * cliente del comercio, que no es una persona del sistema).
             */
            'referencia_id' => is_null($referencia_id) ? null : (int) $referencia_id,
        ]);
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
     * de la tabla articles.
     *
     * @param Article $article Artículo con relaciones category, brand y descriptions cargadas.
     *
     * @return bool `true` si se escribió un vector nuevo; `false` si no había texto que
     *              vectorizar y el artículo quedó tal cual estaba.
     *
     * 🔴 EL BOOL NO ES DECORACIÓN: es lo único que distingue "generé un embedding" de
     * "salí sin hacer nada". Este método tiene un `return` mudo cuando el texto queda vacío
     * (artículo sin nombre, sin categoría, sin marca, sin código y sin descripciones — pasa
     * con filas a medio importar). Mientras devolvía `void`, el caller no tenía forma de
     * enterarse y contaba ese caso como embedding generado, que es justo el número que la
     * tanda le termina mostrando al comerciante en el toast. Si alguien viene a "limpiar"
     * esto devolviendo `void` de nuevo, el contador vuelve a mentir en silencio.
     *
     * @throws \RuntimeException Si generate_embedding() falla.
     */
    public function update_article_embedding(Article $article): bool
    {
        // Construir texto representativo del artículo.
        $text = $this->embedding_for_article($article);

        if ($text === '') {
            Log::channel('daily')->warning('ArticleEmbeddingService: artículo sin texto para embeddear.', [
                'article_id' => $article->id,
            ]);
            return false;
        }

        // Obtener vector como array de floats. El gasto se le imputa al dueño del artículo,
        // como `embeddings_articulos`: es el costo de tener el catálogo indexado.
        $embedding = $this->generate_embedding(
            $text,
            (int) $article->user_id,
            self::PROCESO_ARTICULOS,
            (int) $article->id
        );

        $this->persistir_embedding((int) $article->id, $embedding);

        return true;
    }

    /**
     * Persiste un vector YA CALCULADO en articles.embedding, en el formato del driver activo.
     *
     * Se usa SQL crudo porque el tipo vector() de pgvector no tiene soporte nativo en el ORM
     * de Laravel 8; el cast ::vector es necesario en Postgres. En MySQL la columna es JSON y
     * el vector viaja como array serializado.
     *
     * 🔴 POR QUÉ ESTO ES PÚBLICO Y ESTÁ SEPARADO DE update_article_embedding(), aunque a
     * primera vista parezca que sobra un método:
     *
     * - Hay un segundo escritor de vectores que NO pasa por OpenAI: el seeder de la
     *   ferretería, que copia embeddings horneados de un archivo commiteado en el repo. Ese
     *   camino necesita persistir un vector que ya tiene en la mano, sin texto, sin llamada
     *   paga y sin hash nuevo.
     * - La decisión pgvector-vs-JSON NO PUEDE QUEDAR DUPLICADA. Es una rama de dos líneas y
     *   por eso da ganas de copiarla al seeder; el problema es que el día que el proyecto se
     *   mude a Postgres, la copia del seeder se olvida y horneás vectores que en producción
     *   se guardan como texto JSON en una columna vector. El síntoma sería una búsqueda
     *   semántica que devuelve cualquier cosa, sin ningún error.
     *
     * 🔴 EN MYSQL, ADEMÁS, SINCRONIZA EL VECTOR COMPACTO (misión rag-whatsapp-memoria-acotada).
     * Este método es el ÚNICO escritor de `articles.embedding` —lo usan el job que indexa y el
     * seeder de semilla—, así que actualizar `article_compact_embeddings` acá alcanza para que el
     * compacto nunca quede atrás del JSON. Si el vector nuevo no se puede compactar (vacío o de
     * norma cero), la fila compacta se BORRA: quedarse con la vieja haría que la búsqueda rankee
     * el artículo por un vector que ya no es el suyo. Un artículo sin fila compacta no se pierde:
     * la búsqueda lo vuelve a intentar desde el JSON.
     *
     * @param int               $article_id Artículo destino.
     * @param array<int, float> $embedding  Vector completo, tal como lo devuelve OpenAI.
     *
     * @return void
     */
    public function persistir_embedding(int $article_id, array $embedding): void
    {
        if ($this->uses_pgvector()) {
            // PostgreSQL: literal [f1,f2,...] con cast ::vector. La búsqueda por pgvector no usa
            // el compacto, así que en esta rama no se toca article_compact_embeddings.
            $vector_string = '['.implode(',', $embedding).']';

            DB::statement(
                'UPDATE articles SET embedding = ?::vector WHERE id = ?',
                [$vector_string, $article_id]
            );

            return;
        }

        // MySQL / otros: persistir el array como JSON.
        DB::table('articles')
            ->where('id', $article_id)
            ->update(['embedding' => json_encode($embedding)]);

        // Vector compacto derivado del mismo array (null si no hay nada que normalizar).
        $compacto = $this->compactar_vector($embedding);

        if (is_null($compacto)) {
            DB::table('article_compact_embeddings')->where('article_id', $article_id)->delete();

            return;
        }

        // El dueño se lee de articles: la firma de este método no lo trae (y no se cambia, porque
        // la usa el seeder de semilla). Si el artículo no existe no hay nada que sincronizar.
        $user_id = DB::table('articles')->where('id', $article_id)->value('user_id');

        if (is_null($user_id)) {
            return;
        }

        $this->guardar_compactos([$article_id => $compacto], (int) $user_id);
    }

    /**
     * Arma el vector compacto de un embedding, listo para guardar en `article_compact_embeddings`.
     *
     * Se queda con los primeros `DIMENSIONES_COMPACTAS` valores (o con todos, si el vector es más
     * corto: los tests usan vectores de 3), los normaliza a norma 1 y los empaqueta como float32
     * little-endian con `pack('g*')`. Con 512 dimensiones son exactamente 2048 bytes.
     *
     * Por qué float32 y no float64 (`pack('e*')`): la mitad de bytes, y la precisión de float32
     * (~7 dígitos) sobra para ordenar por similitud; los vectores de OpenAI ni siquiera traen más
     * dígitos significativos que esos.
     *
     * @param array<int, float> $vector Vector completo (típicamente 1536 floats de OpenAI).
     *
     * @return string|null Binario empaquetado, o null si el vector está vacío o su norma es cero
     *                     (no hay dirección que comparar).
     */
    public function compactar_vector(array $vector): ?string
    {
        $normalizado = $this->vector_normalizado($vector);

        if (is_null($normalizado)) {
            return null;
        }

        // Spread de un array con claves numéricas: permitido en PHP 7.4.
        return pack('g*', ...$normalizado);
    }

    /**
     * Trunca un vector a `DIMENSIONES_COMPACTAS` valores y lo normaliza a norma 1.
     *
     * Es la mitad común entre el vector de la consulta (que se compara en memoria y nunca se
     * empaqueta) y el de cada artículo (que se empaqueta en `compactar_vector()`). Tener los dos
     * normalizados es lo que permite que la similitud de coseno sea un simple producto punto.
     *
     * @param array<int, float> $vector Vector de cualquier largo.
     *
     * @return array<int, float>|null Lista de floats (claves 0..n-1) de norma 1, o null si el
     *                                vector está vacío o tiene norma cero.
     */
    protected function vector_normalizado(array $vector): ?array
    {
        // array_values: el vector puede venir con claves no consecutivas (p. ej. de un unpack).
        $truncado = array_slice(array_values($vector), 0, self::DIMENSIONES_COMPACTAS);

        if (empty($truncado)) {
            return null;
        }

        // Suma de cuadrados, casteando cada valor (el JSON puede traer enteros como 1 o 0).
        $suma_cuadrados = 0.0;

        foreach ($truncado as $indice => $valor) {
            $valor              = (float) $valor;
            $truncado[$indice]  = $valor;
            $suma_cuadrados    += $valor * $valor;
        }

        if ($suma_cuadrados <= 0.0) {
            return null;
        }

        $norma = sqrt($suma_cuadrados);

        foreach ($truncado as $indice => $valor) {
            $truncado[$indice] = $valor / $norma;
        }

        return $truncado;
    }

    /**
     * Producto punto entre dos vectores, hasta la longitud del más corto.
     *
     * Con los dos vectores normalizados (norma 1) es exactamente la similitud de coseno, sin las
     * dos raíces ni las dos normas por artículo que calculaba el método viejo. Recorre hasta
     * `min(count)` por si alguna vez conviven compactos de distinto largo: no rompe, aunque en ese
     * caso el ranking pierde calidad (ver el bloque rojo de `DIMENSIONES_COMPACTAS`).
     *
     * @param array<int, float> $vector_a Lista con claves 0..n-1.
     * @param array<int, float> $vector_b Lista con claves 0..n-1.
     *
     * @return float Entre -1 y 1 para vectores normalizados; mayor = más similar.
     */
    protected function producto_punto(array $vector_a, array $vector_b): float
    {
        $largo = min(count($vector_a), count($vector_b));
        $suma  = 0.0;

        for ($indice = 0; $indice < $largo; $indice++) {
            $suma += $vector_a[$indice] * $vector_b[$indice];
        }

        return $suma;
    }

    /**
     * Guarda (inserta o reemplaza) vectores compactos de artículos de un mismo dueño.
     *
     * Un solo `INSERT ... ON DUPLICATE KEY UPDATE` por llamada, por la clave primaria
     * `article_id`: es lo que permite que la búsqueda persista una tanda entera de compactos
     * nuevos en una sola ida a MySQL.
     *
     * El binario viaja como parámetro del statement preparado (Laravel usa prepares nativos en
     * MySQL), así que no pasa por ningún escape de texto ni por el charset de la conexión.
     *
     * @param array<int, string> $compactos Mapa article_id => binario de `compactar_vector()`.
     * @param int                $user_id   Dueño de todos esos artículos.
     *
     * @return void
     */
    protected function guardar_compactos(array $compactos, int $user_id): void
    {
        if (empty($compactos)) {
            return;
        }

        // Filas a insertar, una por artículo.
        $filas = [];

        foreach ($compactos as $article_id => $binario) {
            $filas[] = [
                'article_id' => (int) $article_id,
                'user_id'    => $user_id,
                'vector'     => $binario,
            ];
        }

        DB::table('article_compact_embeddings')->upsert($filas, ['article_id'], ['user_id', 'vector']);
    }

    /**
     * Compacta, por tandas, los artículos que tienen `articles.embedding` y todavía no tienen fila
     * en `article_compact_embeddings`. Es el motor del comando `articles:compactar-embeddings`.
     *
     * Adelanta el mismo trabajo que el backfill perezoso de la búsqueda, para que el primer
     * mensaje de cada cliente después del deploy no lo pague. Diferencias con ese camino:
     *
     * - No filtra por status ni por borrado: compacta todo lo que tenga vector, así un artículo
     *   que se reactiva ya tiene su compacto listo. La búsqueda igual filtra sobre `articles`, así
     *   que un compacto de un artículo inactivo nunca aparece en una respuesta.
     * - No toca los que ya tienen compacto (el `LEFT JOIN ... IS NULL`): correrlo dos veces no hace
     *   nada la segunda.
     * - NO llama a OpenAI: solo re-empaqueta el vector que ya está guardado. Por eso no le afecta
     *   `EMBEDDINGS_GENERACION_PAUSADA` y no cuesta plata.
     *
     * La paginación es por id (`chunkById`), así que el hecho de que las filas ya compactadas
     * dejen de cumplir el `IS NULL` a medida que se recorre no hace saltear ninguna.
     *
     * @param int|null $user_id Si viene, solo los artículos de ese dueño; si no, toda la base
     *                          (una base compartida puede tener varios comercios adentro).
     * @param int      $tanda   Artículos por tanda (cada uno trae su JSON de ~28 KB).
     *
     * @return array{compactados: int, salteados: int} `salteados` son los que tienen un JSON
     *                                                 inválido, vacío o de norma cero.
     */
    public function compactar_pendientes($user_id = null, int $tanda = 200): array
    {
        $totales = ['compactados' => 0, 'salteados' => 0];

        $consulta = DB::table('articles as a')
            ->leftJoin('article_compact_embeddings as e', 'e.article_id', '=', 'a.id')
            ->whereNotNull('a.embedding')
            ->whereNull('e.article_id')
            ->select('a.id', 'a.user_id', 'a.embedding');

        if (! is_null($user_id)) {
            $consulta->where('a.user_id', (int) $user_id);
        }

        $consulta->chunkById($tanda, function ($filas) use (&$totales) {

            // Compactos de la tanda agrupados por dueño: user_id => [article_id => binario].
            $por_dueno = [];

            foreach ($filas as $fila) {
                $completo = json_decode((string) $fila->embedding, true);
                $binario  = is_array($completo) ? $this->compactar_vector($completo) : null;

                if (is_null($binario)) {
                    $totales['salteados']++;
                    continue;
                }

                $por_dueno[(int) $fila->user_id][(int) $fila->id] = $binario;
            }

            foreach ($por_dueno as $dueno_id => $compactos) {
                $this->guardar_compactos($compactos, (int) $dueno_id);
                $totales['compactados'] += count($compactos);
            }
        }, 'a.id', 'id');

        return $totales;
    }

    /**
     * Busca artículos similares semánticamente a una consulta de texto.
     *
     * Genera el embedding del query y busca los artículos más cercanos del dueño, filtrando por
     * user_id, status activo, registros no eliminados y con embedding cargado. La búsqueda es
     * EXACTA contra todo el catálogo (nada queda afuera por un índice aproximado):
     *
     * - En PostgreSQL, con el operador <=> (distancia de coseno) de pgvector.
     * - En MySQL (toda la flota hoy), con `search_similar_articles_in_php()`: recorrido por
     *   tandas sobre el vector compacto de `article_compact_embeddings`, quedándose solo con los
     *   `$limit` mejores. La memoria no depende del tamaño del catálogo; el detalle está en el
     *   docblock de ese método.
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
        // Generar embedding del query para comparar contra los artículos. Se imputa como
        // `embeddings_busqueda`, que es el gasto que corre en CADA respuesta del agente de
        // WhatsApp — no el de indexar, que se paga una sola vez por artículo.
        $query_embedding = $this->generate_embedding($query, $user_id, self::PROCESO_BUSQUEDA);

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
     * Búsqueda exacta por similitud de coseno en PHP, para entornos sin pgvector (MySQL: toda la
     * flota), con memoria acotada.
     *
     * 🔴 POR QUÉ ESTÁ ESCRITO ASÍ (misión rag-whatsapp-memoria-acotada, 28/9/2026). La versión
     * anterior hacía `->get()` de TODOS los artículos del dueño CON `articles.embedding` (JSON de
     * 1536 floats, ~28 KB cada uno), los decodificaba todos y recién ahí ordenaba. Por cada mensaje
     * del cliente. En demo3 eso agotaba los 768 MB del proceso en `Connection.php` y el "Sugerir
     * respuesta" moría antes del middleware de CORS. Comparar contra TODO el catálogo está bien (es
     * lo que da la mejor respuesta); lo que estaba mal era transferir y decodificar el vector
     * gigante de cada artículo y tenerlos todos en memoria a la vez. Ahora:
     *
     * 1. **Vector compacto**: se compara contra `article_compact_embeddings` (512 dims normalizadas,
     *    2 KB en float32), no contra el JSON. `unpack` es C, no el parser JSON, y con los dos
     *    vectores normalizados el coseno es un producto punto de 512 términos.
     * 2. **Tandas de `TANDA_BUSQUEDA`** con `chunkById`, guardando solo el top-K mientras se
     *    recorre. La memoria no depende del tamaño del catálogo.
     * 3. **Los datos del artículo se traen al final**, en una segunda consulta y solo para los K
     *    ganadores: el recorrido lee apenas `id` y el vector.
     *
     * El recorrido va en DOS PASADAS, y separarlas no es cosmético:
     *
     * - **Pasada 1, los que ya tienen compacto** (`JOIN` con `article_compact_embeddings`). 🔴 ESTA
     *   CONSULTA NO NOMBRA `a.embedding` EN NINGÚN LADO, NI SIQUIERA EN UN `IS NOT NULL`, y es a
     *   propósito: medido con 8.000 artículos (28/9/2026), cualquier referencia a esa columna hace
     *   que InnoDB lea el JSON de ~28 KB de cada fila aunque no lo devuelva —la misma consulta tarda
     *   1,47 s con `a.embedding IS NOT NULL` y 0,08 s sin él—. El filtro "con embedding" está
     *   garantizado igual por construcción: una fila compacta solo nace de un embedding no nulo
     *   (en `persistir_embedding()`, el único escritor de vectores, o en la pasada 2), y ningún
     *   código de empresa-api ni de tienda-api pone `articles.embedding` en NULL sobre un artículo
     *   existente (verificado por grep el 28/9/2026; `DuplicarRecetaHelper` lo hace, pero sobre la
     *   copia nueva, que todavía no tiene compacto). Si algún día alguien agrega un camino que
     *   borre el embedding, tiene que borrar también la fila compacta, o ese artículo seguiría
     *   apareciendo con su vector viejo.
     * - **Pasada 2, los que todavía no tienen compacto** (backfill perezoso: los de antes del
     *   deploy, o uno cuyo compacto se borró). Primero se listan SOLO sus ids —de nuevo sin tocar
     *   `a.embedding`—, y recién para cada tanda de ids se trae el JSON con `embedding IS NOT NULL`,
     *   que acá sí es el filtro de siempre. Se compactan al vuelo, se usan en esta misma búsqueda y
     *   se guardan al final de la tanda. La primera búsqueda de cada dueño paga ese costo una vez,
     *   con la memoria igual acotada por tanda; de ahí en adelante la pasada 2 solo ve los
     *   artículos sin vector (que son pocos y no traen nada pesado). Guardar es un efecto
     *   secundario: si falla se loguea y la búsqueda sigue, porque la respuesta al cliente no puede
     *   depender de él.
     *
     * El orden de las pasadas importa: si la 2 fuera primero, lo que ella compacta volvería a
     * aparecer en la 1 y se contaría dos veces. Con la 1 primero, lo único que puede pasar es que
     * otro proceso compacte un artículo justo entre las dos pasadas y esta búsqueda puntual no lo
     * vea; la siguiente sí.
     *
     * Los filtros de dueño, activo y no borrado se aplican siempre sobre `articles`: por eso una
     * fila compacta huérfana (de un artículo borrado a mano, inactivo o de otro dueño) nunca
     * aparece, y un compacto de otro dueño no se lee jamás.
     *
     * Desempate determinístico: a igual similitud gana el id menor, para que la misma consulta
     * sobre el mismo catálogo devuelva siempre el mismo orden.
     *
     * @param array<int, float> $query_embedding Vector de la consulta, completo (1536 floats).
     * @param int               $user_id         Tenant propietario del catálogo.
     * @param int               $limit           Cantidad máxima de resultados (el K del top-K).
     *
     * @return Collection Colección de stdClass con id, name, final_price, stock, bar_code, slug y
     *                    online, en orden de similitud descendente. Es la MISMA forma que devolvía
     *                    la versión anterior: `WhatsappBotAiService` depende de ella.
     */
    protected function search_similar_articles_in_php(array $query_embedding, int $user_id, int $limit): Collection
    {
        // Vector de la consulta, truncado y normalizado igual que los compactos.
        $consulta = $this->vector_normalizado($query_embedding);

        if (is_null($consulta) || $limit <= 0) {
            return collect([]);
        }

        /*
         * Top-K acumulado: lista de ['score' => float, 'id' => int] de largo <= $limit. Con K
         * chico (8 en el agente) un recorrido lineal para encontrar el peor es más simple y
         * igual de rápido que un heap.
         */
        $mejores = [];

        // Índice dentro de $mejores del peor candidato actual (se recalcula solo al reemplazar).
        $indice_peor = null;

        // ── Pasada 1: artículos con compacto. Sin referencias a a.embedding (ver docblock). ──
        $this->articulos_del_dueno($user_id)
            ->join('article_compact_embeddings as e', 'e.article_id', '=', 'a.id')
            ->select('a.id', 'e.vector')
            ->chunkById(static::TANDA_BUSQUEDA, function ($filas) use ($consulta, $limit, &$mejores, &$indice_peor) {
                foreach ($filas as $fila) {
                    // unpack devuelve claves 1..n: array_values las deja en 0..n-1 para el producto punto.
                    $vector = array_values(unpack('g*', $fila->vector));

                    $this->considerar_candidato(
                        $mejores,
                        $indice_peor,
                        $limit,
                        $this->producto_punto($consulta, $vector),
                        (int) $fila->id
                    );
                }
            }, 'a.id', 'id');

        // ── Pasada 2: artículos sin compacto (backfill perezoso). Primero solo los ids. ──
        $this->articulos_del_dueno($user_id)
            ->leftJoin('article_compact_embeddings as e', 'e.article_id', '=', 'a.id')
            ->whereNull('e.article_id')
            ->select('a.id')
            ->chunkById(static::TANDA_BUSQUEDA, function ($filas) use ($consulta, $limit, $user_id, &$mejores, &$indice_peor) {

                // El JSON se trae recién acá, por clave primaria y solo para esta tanda de ids.
                $con_json = DB::table('articles')
                    ->whereIn('id', $filas->pluck('id')->all())
                    ->whereNotNull('embedding')
                    ->select('id', 'embedding')
                    ->get();

                // Compactos armados en esta tanda desde el JSON: article_id => binario.
                $compactos_nuevos = [];

                foreach ($con_json as $fila) {
                    $completo = json_decode((string) $fila->embedding, true);

                    if (! is_array($completo) || empty($completo)) {
                        // JSON inválido o vacío: se saltea, igual que hacía la versión anterior.
                        continue;
                    }

                    $binario = $this->compactar_vector($completo);

                    if (is_null($binario)) {
                        // Vector de norma cero: no hay dirección con la que comparar.
                        continue;
                    }

                    $compactos_nuevos[(int) $fila->id] = $binario;

                    // Se compara con el compacto recién armado, no con el JSON: así la primera
                    // búsqueda y las siguientes rankean exactamente igual.
                    $this->considerar_candidato(
                        $mejores,
                        $indice_peor,
                        $limit,
                        $this->producto_punto($consulta, array_values(unpack('g*', $binario))),
                        (int) $fila->id
                    );
                }

                // Backfill perezoso: efecto secundario que nunca corta la búsqueda.
                if (! empty($compactos_nuevos)) {
                    try {
                        $this->guardar_compactos($compactos_nuevos, $user_id);
                    } catch (\Throwable $e) {
                        Log::channel('daily')->warning(
                            'ArticleEmbeddingService: no se pudieron guardar vectores compactos durante la búsqueda; se reintenta en la próxima.',
                            [
                                'user_id'   => $user_id,
                                'articulos' => count($compactos_nuevos),
                                'error'     => $e->getMessage(),
                            ]
                        );
                    }
                }
            }, 'a.id', 'id');

        if (empty($mejores)) {
            return collect([]);
        }

        // Orden final del ranking: score descendente y, a igual score, id ascendente.
        usort($mejores, function ($izquierda, $derecha) {
            if ($izquierda['score'] == $derecha['score']) {
                return $izquierda['id'] <=> $derecha['id'];
            }

            return $izquierda['score'] < $derecha['score'] ? 1 : -1;
        });

        // Ids ganadores, en el orden del ranking.
        $ids = array_map(function ($candidato) {
            return $candidato['id'];
        }, $mejores);

        /*
         * Segunda consulta, solo para los K ganadores: los datos que el agente necesita. `online`
         * viaja igual que en la rama pgvector, y por el mismo motivo: se selecciona para que el
         * agente sepa si puede pasar el link, pero NO se filtra, para que el artículo siga estando
         * en el catálogo con su precio y su stock. El razonamiento completo está en el docblock de
         * `search_similar_articles()`.
         */
        $datos = DB::table('articles')
            ->select('id', 'name', 'final_price', 'stock', 'bar_code', 'slug', 'online')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        // Se devuelve en el orden del ranking; whereIn no garantiza ningún orden.
        $resultados = [];

        foreach ($ids as $id) {
            if (isset($datos[$id])) {
                $resultados[] = $datos[$id];
            }
        }

        return collect($resultados);
    }

    /**
     * Consulta base de las dos pasadas de la búsqueda: los artículos de un dueño que están activos
     * y no borrados, con el alias `a`.
     *
     * Existe para que los filtros de siempre vivan en UN solo lugar y las dos pasadas no puedan
     * divergir. A propósito NO incluye `embedding IS NOT NULL`: ver el bloque rojo de
     * `search_similar_articles_in_php()`.
     *
     * @param int $user_id Dueño del catálogo.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function articulos_del_dueno(int $user_id)
    {
        return DB::table('articles as a')
            ->where('a.user_id', $user_id)
            ->where('a.status', 'active')
            ->whereNull('a.deleted_at');
    }

    /**
     * Ofrece un candidato al top-K: entra si todavía hay lugar, o si le gana al peor.
     *
     * @param array<int, array{score: float, id: int}> $mejores     Top-K acumulado (por referencia).
     * @param int|null                                 $indice_peor Índice del peor (por referencia).
     * @param int                                      $limit       K.
     * @param float                                    $score       Similitud del candidato.
     * @param int                                      $article_id  Id del candidato.
     *
     * @return void
     */
    protected function considerar_candidato(array &$mejores, &$indice_peor, int $limit, float $score, int $article_id): void
    {
        if (count($mejores) < $limit) {
            // Todavía hay lugar: entra directo y el peor se recalcula.
            $mejores[]   = ['score' => $score, 'id' => $article_id];
            $indice_peor = $this->indice_del_peor($mejores);

            return;
        }

        // Lleno: entra solo si le gana al peor (a igual score, gana el id menor).
        if ($this->es_mejor_candidato($score, $article_id, $mejores[$indice_peor])) {
            $mejores[$indice_peor] = ['score' => $score, 'id' => $article_id];
            $indice_peor           = $this->indice_del_peor($mejores);
        }
    }

    /**
     * Devuelve el índice del peor candidato de un top-K: el de menor score y, a igual score, el de
     * id mayor (el que perdería el desempate).
     *
     * @param array<int, array{score: float, id: int}> $candidatos Lista no vacía.
     *
     * @return int Índice dentro de `$candidatos`.
     */
    protected function indice_del_peor(array $candidatos): int
    {
        $indice_peor = null;

        foreach ($candidatos as $indice => $candidato) {
            if (is_null($indice_peor)) {
                $indice_peor = $indice;
                continue;
            }

            $peor = $candidatos[$indice_peor];

            if ($candidato['score'] < $peor['score']
                || ($candidato['score'] == $peor['score'] && $candidato['id'] > $peor['id'])) {
                $indice_peor = $indice;
            }
        }

        return (int) $indice_peor;
    }

    /**
     * Decide si un candidato nuevo le gana al peor del top-K.
     *
     * @param float                         $score      Similitud del candidato.
     * @param int                           $article_id Id del candidato.
     * @param array{score: float, id: int}  $peor       Peor candidato actual del top-K.
     *
     * @return bool `true` si tiene más score, o el mismo score y un id menor.
     */
    protected function es_mejor_candidato(float $score, int $article_id, array $peor): bool
    {
        if ($score > $peor['score']) {
            return true;
        }

        return $score == $peor['score'] && $article_id < $peor['id'];
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
