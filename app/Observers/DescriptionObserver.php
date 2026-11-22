<?php

namespace App\Observers;

use App\Services\ArticleEmbeddingDispatcher;
use App\Models\Article;
use App\Models\Description;

/**
 * Observer del modelo Description: mantiene el embedding del artículo dueño
 * sincronizado cuando cambia su descripción.
 *
 * POR QUÉ EXISTE ESTE ARCHIVO
 * --------------------------
 * El texto que se vectoriza (ArticleEmbeddingService::embedding_for_article()) incluye
 * la descripción del artículo, pero la descripción NO vive en la tabla `articles`: vive
 * en `descriptions`, en otra fila y con su propio ciclo de vida. Eso deja dos agujeros
 * que, juntos, hacen que la IA nunca se entere de un cambio de descripción:
 *
 *   1. ArticleObserver::updated() mira wasChanged() sobre columnas de `articles`.
 *      Guardar una descripción no toca ninguna de esas columnas, así que ese observer
 *      ni siquiera se dispara.
 *   2. La red de seguridad (articles:generate-embeddings, cada 30 minutos) filtra por
 *      `articles.updated_at > articles.embedding_generated_at`. Sin nada que bumpee
 *      `articles.updated_at`, esa consulta tampoco lo levanta.
 *
 * Resultado antes de este observer: el dueño escribía o corregía la descripción de un
 * producto y el índice semántico quedaba con la versión vieja para siempre. Y la
 * descripción es JUSTO el campo que hace funcionar el caso de uso real: alguien pregunta
 * "algo para pintar una pared con humedad" y el producto correcto se encuentra por la
 * descripción, porque el nombre no dice nada de humedad.
 *
 * Este observer tapa el agujero 1 (disparo inmediato). El agujero 2 NO está tapado: hay UNA
 * sola defensa, no dos. La revectorización inmediata depende enteramente de este observer,
 * que dispara en created, updated y deleted.
 *
 * 🔴 Para taparlo NO agregues `Description::$touches = ['article']`. Estuvo y se sacó el
 * 15/8/2026, no se perdió: bumpear `articles.updated_at` al guardar una descripción hace que
 * la SPA se baje el catálogo ENTERO después de una importación (ese reloj es la clave del
 * sync incremental y del export por keyset) y deja 20.000 artículos por encima de
 * `embedding_generated_at`, o sea 20.000 jobs que hidratan el artículo entero antes de poder
 * cortar por hash. El razonamiento completo está escrito en `App\Models\Description`, donde
 * antes estaba la propiedad. Si en algún momento hace falta la segunda defensa, la forma
 * correcta es que `articles:generate-embeddings` mire también `descriptions.updated_at`, en
 * vez de ensuciar el reloj del artículo, que lo lee medio sistema.
 *
 * El criterio para disparar (extensión agentes_ia, y nada mientras haya una importación
 * en curso) NO se reimplementa acá: se reusa ArticleObserver::debe_generar_embedding(),
 * que además comparte el memo por proceso.
 */
class DescriptionObserver
{
    /**
     * Columnas de `descriptions` cuyo cambio puede mover el texto que se vectoriza.
     *
     * Son las dos que guardan texto que lee una persona. El resto de la tabla es
     * trazabilidad de las descripciones inteligentes (ai_generated, ai_confidence,
     * ai_sources, ai_reviewed_at) y no entra en ningún texto: que un humano apruebe una
     * descripción de baja confianza mueve ai_reviewed_at y nada más, y eso no justifica
     * encolar un job.
     *
     * `article_id` está en la lista porque el alta de artículo en dos pasos crea la
     * descripción con `temporal_id` y `article_id` en null, y recién después la ata al
     * artículo. Ese UPDATE no toca title ni content, pero es el momento en que el
     * artículo pasa a tener descripción: si no se dispara acá, no se dispara nunca.
     *
     * @var array<int, string>
     */
    private const EMBEDDING_RELEVANT_FIELDS = [
        'title',
        'content',
        'article_id',
    ];

    /**
     * Descripción nueva: el artículo pasó a tener texto que antes no tenía.
     *
     * @param Description $description Descripción recién creada.
     *
     * @return void
     */
    public function created(Description $description): void
    {
        $this->encolar_para_articulo($description->article_id);
    }

    /**
     * Descripción editada: solo se encola si cambió algo que el embedding puede mirar.
     *
     * El chequeo de wasChanged() va PRIMERO porque no toca la base (compara lo que
     * Eloquent ya tiene en memoria después del save), igual que en ArticleObserver.
     *
     * @param Description $description Descripción recién actualizada.
     *
     * @return void
     */
    public function updated(Description $description): void
    {
        if (! $description->wasChanged(self::EMBEDDING_RELEVANT_FIELDS)) {
            return;
        }

        $this->encolar_para_articulo($description->article_id);

        // Si la descripción se mudó de artículo, el que la perdió también quedó con el
        // texto viejo. getOriginal() todavía devuelve el valor de antes del save porque
        // Eloquent dispara `updated` antes de sincronizar los originales.
        $article_id_anterior = $description->getOriginal('article_id');

        if ((int) $article_id_anterior !== (int) $description->article_id) {
            $this->encolar_para_articulo($article_id_anterior);
        }
    }

    /**
     * Descripción borrada: el artículo perdió texto, el vector viejo quedó de más.
     *
     * @param Description $description Descripción recién eliminada.
     *
     * @return void
     */
    public function deleted(Description $description): void
    {
        $this->encolar_para_articulo($description->article_id);
    }

    /**
     * Encola la regeneración del embedding de un artículo, si corresponde.
     *
     * @param int|string|null $article_id Artículo dueño de la descripción.
     *
     * @return void
     */
    private function encolar_para_articulo($article_id): void
    {
        // Descripción todavía sin artículo (alta en dos pasos, atada por temporal_id).
        // No hay a quién revectorizar; cuando se ate, el UPDATE de article_id lo dispara.
        if (empty($article_id)) {
            return;
        }

        $article_id = (int) $article_id;

        // Solo el user_id, que es lo único que el gate necesita: hidratar el Article
        // entero sería traer una fila ancha al pedo. La consulta respeta el soft delete
        // de Article, así que si el artículo está eliminado devuelve null y cortamos acá
        // en vez de encolar un job que igual no encontraría nada.
        $user_id = Article::where('id', $article_id)->value('user_id');

        if (! ArticleObserver::debe_generar_embedding($user_id)) {
            return;
        }

        // Un solo job por artículo (misión embedding-un-solo-job): ArticleEmbeddingDispatcher
        // reclama la marca `embedding_queued_at` y despacha sólo si no había un job pendiente.
        // Un artículo con N descripciones nuevas o editadas seguidas deja UN job, y ese job lee
        // el texto vigente al correr (y corta por huella si quedó igual al guardado).
        //
        // El job fuerza afterCommit en su constructor (misión alertas-descripciones, 14/10/2026):
        // aprobar, editar o quitar una descripción desde Alertas → Catálogo → Descripciones se
        // escribe adentro de un DB::transaction y en redis `after_commit` está en false; sin eso
        // un worker libre podía leer el artículo sin el cambio. La marca se escribe dentro de la
        // misma transacción: si se revierte, se revierte también la marca.
        ArticleEmbeddingDispatcher::dispatch_if_not_queued($article_id);
    }
}
