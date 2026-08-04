<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessArticleBatchDescriptionsJob;
use App\Models\Article;
use App\Models\Description;
use App\Models\GeocoderCounter;
use App\Models\User;
use App\Services\ArticleDescriptionAiService;
use App\Services\ProductInfoLookupService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Controlador del flujo de "descripciones inteligentes": preview individual sincrono,
 * guardado de lo confirmado por el usuario, generacion masiva en background (job + Pusher)
 * y endpoints de revision humana para las descripciones de baja confianza.
 *
 * IMPORTANTE (bloqueo conocido, ver resumen del prompt 369): este controlador usa
 * Description::pendingAiReview()/human() y las columnas ai_generated/ai_confidence/
 * ai_sources/ai_reviewed_at, que deben existir via la migracion del prompt 366. Si esa
 * migracion todavia no se aplico, estos endpoints van a fallar en tiempo de ejecucion
 * (columna inexistente / metodo de scope inexistente).
 */
class ArticleDescriptionAiController extends Controller
{
    /** @var string ID del motor de busqueda personalizado de Google (mismo que imagenes). */
    const GOOGLE_CX = 'c442e5f346f314951';

    /**
     * Genera un preview de descripcion para UN articulo, de forma sincrona.
     * NO guarda nada: el usuario ve el resultado, lo edita si quiere, y recien ahi confirma
     * llamando a store().
     *
     * @param  Request $request
     * @param  int     $article_id  ID del articulo sobre el que se quiere generar la descripcion.
     * @return \Illuminate\Http\JsonResponse
     */
    function preview(Request $request, $article_id)
    {
        // El articulo debe pertenecer al usuario autenticado; se trae la marca para el prompt de la IA.
        $article = Article::where('id', $article_id)
                        ->where('user_id', $this->userId())
                        ->with('brand')
                        ->first();

        if (!$article) {
            return response()->json(['message' => 'Articulo no encontrado'], 404);
        }

        $owner = User::find($this->userId());

        // Contador diario de busquedas de Google, compartido con el flujo de imagenes inteligentes.
        $counter = $this->get_or_create_counter();

        if ($counter->counter >= $this->get_google_cuota($owner)) {
            return response()->json([
                'found'  => false,
                'reason' => 'Se alcanzo el limite diario de busquedas de Google. Podes volver a intentar manana.',
                'quota_reached' => true,
            ], 200);
        }

        // Recolecta evidencia verificable (UPCitemdb / Google Custom Search) sobre el producto.
        $lookup_service = new ProductInfoLookupService(
            (int) $this->userId(),
            $this->get_google_api_key($owner),
            self::GOOGLE_CX,
            $this->get_google_cuota($owner)
        );

        $lookup = $lookup_service->lookup($article, $counter);

        if (!$lookup['found']) {
            return response()->json([
                'found'         => false,
                'reason'        => $lookup['quota_exceeded']
                    ? 'Se alcanzo el limite diario de busquedas de Google.'
                    : 'No se encontro informacion sobre este producto.',
                'quota_reached' => $lookup['quota_exceeded'],
            ], 200);
        }

        // Con evidencia disponible, Claude sintetiza la descripcion (o found=false si no puede).
        $ai_service = new ArticleDescriptionAiService();
        $result     = $ai_service->generate($article, $lookup);

        return response()->json([
            'found'         => $result['found'],
            'sections'      => $result['sections'],
            'confidence'    => $result['confidence'],
            'reason'        => $result['reason'],
            'sources'       => $result['sources'],
            'quota_reached' => false,
        ], 200);
    }

    /**
     * Guarda las secciones que el usuario confirmo (posiblemente editadas) en el preview.
     * Al confirmar manualmente, la descripcion queda marcada como ya revisada por una persona
     * (ai_reviewed_at), sin importar la confianza declarada.
     *
     * @param  Request $request
     * @param  int     $article_id
     * @return \Illuminate\Http\JsonResponse
     */
    function store(Request $request, $article_id)
    {
        $request->validate([
            'sections'           => 'required|array|min:1',
            'sections.*.title'   => 'required|string|max:255',
            'sections.*.content' => 'required|string',
            'confidence'         => 'nullable|string|in:high,medium,low',
            'sources'            => 'nullable|array',
        ]);

        $article = Article::where('id', $article_id)
                        ->where('user_id', $this->userId())
                        ->first();

        if (!$article) {
            return response()->json(['message' => 'Articulo no encontrado'], 404);
        }

        // Si el frontend no manda confianza (p. ej. el usuario escribio todo a mano en el modal), se
        // asume 'low' por prudencia: nunca se sobreestima la certeza de un contenido generado por IA.
        $confidence = $request->confidence ? $request->confidence : 'low';

        /*
         * Si el usuario edito y confirmo en el preview, ya paso por revision humana:
         * se marca ai_reviewed_at para que no aparezca en la bandeja de pendientes.
         */
        foreach ($request->sections as $section) {
            Description::create([
                'title'          => $section['title'],
                'content'        => $section['content'],
                'article_id'     => $article->id,
                'ai_generated'   => true,
                'ai_confidence'  => $confidence,
                'ai_sources'     => $request->sources ? $request->sources : [],
                'ai_reviewed_at' => Carbon::now(),
            ]);
        }

        // Ya revisado por una persona: se sincroniza a TiendaNube sin restriccion de confianza.
        $this->sync_tienda_nube($article);

        return response()->json([
            'model' => $this->fullModel('Article', $article->id),
        ], 201);
    }

    /**
     * Encola la generacion masiva de descripciones para varios articulos. El resultado
     * (contadores de procesados/salteados/pendientes de revision) llega por Pusher cuando
     * el job termina.
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    function batch_generate(Request $request)
    {
        $request->validate([
            'article_ids'   => 'required|array|min:1',
            'article_ids.*' => 'integer',
            'overwrite'     => 'nullable|boolean',
        ]);

        $owner = User::find($this->userId());

        ProcessArticleBatchDescriptionsJob::dispatch(
            $request->article_ids,
            (int) $this->userId(),
            $this->get_google_api_key($owner),
            self::GOOGLE_CX,
            $this->get_google_cuota($owner),
            (bool) $request->overwrite
        );

        return response()->json(['status' => 'processing'], 200);
    }

    /**
     * Bandeja de descripciones generadas por IA con baja confianza, pendientes de revision
     * humana. Estas descripciones ya estan guardadas en la BD pero NO se sincronizaron a
     * TiendaNube todavia.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    function pending_review()
    {
        $models = Description::pendingAiReview()
                        ->whereHas('article', function ($q) {
                            $q->where('user_id', $this->userId());
                        })
                        ->with('article')
                        ->orderBy('created_at', 'DESC')
                        ->get();

        return response()->json(['models' => $models], 200);
    }

    /**
     * Aprueba una descripcion pendiente de revision (opcionalmente con el texto editado por
     * el usuario). Recien al aprobarla se sincroniza a TiendaNube: la baja confianza no se
     * publica sola.
     *
     * @param  Request $request
     * @param  int     $id
     * @return \Illuminate\Http\JsonResponse
     */
    function approve(Request $request, $id)
    {
        $description = $this->find_owned_description($id);

        if (!$description) {
            return response()->json(['message' => 'Descripcion no encontrada'], 404);
        }

        if ($request->filled('title')) {
            $description->title = $request->title;
        }

        if ($request->filled('content')) {
            $description->content = $request->content;
        }

        $description->ai_reviewed_at = Carbon::now();
        $description->save();

        $this->sync_tienda_nube($description->article);

        return response()->json(['model' => $description->fresh()], 200);
    }

    /**
     * Descarta (borra) una descripcion generada por IA que el usuario no acepto. Dispara el
     * sync a TiendaNube por si esa descripcion ya se habia publicado antes (para que quede
     * consistente tras la baja).
     *
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse
     */
    function discard($id)
    {
        $description = $this->find_owned_description($id);

        if (!$description) {
            return response()->json(['message' => 'Descripcion no encontrada'], 404);
        }

        $article = $description->article;

        $description->delete();

        $this->sync_tienda_nube($article);

        return response(null, 204);
    }

    /**
     * Busca una Description que pertenezca a un articulo del usuario autenticado. Usado por
     * approve()/discard() para garantizar el aislamiento por usuario (404 si es de otro).
     *
     * @param  int $id
     * @return Description|null
     */
    protected function find_owned_description($id)
    {
        return Description::where('id', $id)
                    ->whereHas('article', function ($q) {
                        $q->where('user_id', $this->userId());
                    })
                    ->with('article')
                    ->first();
    }

    /**
     * Resuelve la clave de Google Custom Search API a usar: la del owner si la tiene
     * configurada, o la clave global por defecto.
     *
     * @param  User|null $owner
     * @return string
     */
    protected function get_google_api_key($owner)
    {
        if ($owner && $owner->google_custom_search_api_key) {
            return $owner->google_custom_search_api_key;
        }

        // Key de fallback global: viene de config/services.php (.env del servidor). Antes estaba
        // hardcodeada aca; el repositorio es publico, prohibido volver a escribir el valor real
        // como default (grupo 220, prompt 02). Si tampoco esta configurada en el .env, queda
        // vacia y el error lo termina devolviendo la propia llamada a la API de Google.
        return config('services.google_search.api_key');
    }

    /**
     * Resuelve la cuota diaria de busquedas de Google del owner (o el default de 10).
     *
     * @param  User|null $owner
     * @return int
     */
    protected function get_google_cuota($owner)
    {
        if ($owner && $owner->google_cuota) {
            return (int) $owner->google_cuota;
        }

        return 10;
    }

    /**
     * Obtiene (o crea si no existe) el contador diario de busquedas de Google del usuario.
     * Mismo contador (GeocoderCounter) que usa el flujo de imagenes inteligentes.
     *
     * @return GeocoderCounter
     */
    protected function get_or_create_counter()
    {
        $counter = GeocoderCounter::where('user_id', $this->userId())
                        ->whereDate('created_at', Carbon::today())
                        ->first();

        if (!$counter) {
            $counter = GeocoderCounter::create([
                'counter' => 0,
                'user_id' => $this->userId(),
            ]);
        }

        return $counter;
    }

    /**
     * Mismo gate que DescriptionController::check_tienda_nube(): solo se sincroniza si la
     * integracion con TiendaNube esta habilitada en el entorno.
     *
     * @param  Article|null $article
     * @return void
     */
    protected function sync_tienda_nube($article)
    {
        if (!$article) {
            return;
        }

        if (env('USA_TIENDA_NUBE', false)) {
            dispatch(new \App\Jobs\ProcessSyncArticleDescriptionTiendaNube($article));
        }
    }
}
