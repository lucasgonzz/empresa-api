<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Description;
use App\Services\ProductFacts\ArticleWebFactsService;
use App\Services\ProductFacts\ProductFactsException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Une evidencia y escritura: el modelo no puede sustituir la ficha verificada por texto inventado. */
class FichaWebArticuloIaHelper
{
    public static function buscar($owner_id, AiConversation $conversation, array $input, AiMessage $mensaje = null): array
    {
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);
        if ((int) $owner_id !== $contexto->owner_id || !self::autorizado($contexto, 'article.index')) {
            return ['error' => 'Tu usuario no tiene permiso para consultar las fichas de artículos de este negocio.'];
        }
        if (!isset($input['preparar_descripcion']) || !is_bool($input['preparar_descripcion'])) {
            return ['error' => 'Reenviá buscar_ficha_de_articulo indicando preparar_descripcion: true si la persona pidió agregar/asignar la ficha a Descripción, false si pidió sólo consultarla. No pidas otro permiso para preparar la propuesta: todavía no guarda nada.'];
        }
        if ($input['preparar_descripcion'] && (!$mensaje || !$mensaje->acciones_habilitadas
            || (int) $mensaje->ai_conversation_id !== (int) $conversation->id)) {
            return ['error' => 'Para preparar la descripción tienen que estar habilitadas las acciones en esta conversación. No se guardó ningún cambio.'];
        }
        $query = Article::where('user_id', $owner_id);
        if (!empty($input['articulo_id'])) {
            $query->where('id', (int) $input['articulo_id']);
        } else {
            $nombre = trim((string) ($input['articulo'] ?? ''));
            if ($nombre === '') {
                return ['error' => 'Necesito identificar el artículo del negocio por nombre o código.'];
            }
            $exacto = (clone $query)->where(function ($q) use ($nombre) {
                $q->where('name', $nombre)->orWhere('bar_code', $nombre);
            })->pluck('id');
            if ($exacto->count() === 1) {
                $query->where('id', $exacto[0]);
            } else {
                $query->where('name', 'like', '%' . $nombre . '%');
            }
        }
        $articles = $query->with('brand')->limit(11)->get();
        if ($articles->count() !== 1) {
            return ['error' => $articles->isEmpty() ? 'No encontré ese artículo en este negocio.' : 'Hay varios artículos posibles: preguntá cuál es antes de buscar.',
                'opciones' => $articles->map(function ($a) { return ['articulo_id' => $a->id, 'nombre' => $a->name]; })->all()];
        }
        $article = $articles->first();
        try {
            $result = app()->makeWith(ArticleWebFactsService::class, ['owner' => $contexto->owner, 'person' => $contexto->persona])
                ->investigar($article, mb_substr((string) ($input['pedido'] ?? ''), 0, 600), mb_substr((string) ($input['url'] ?? ''), 0, 2000));
        } catch (ProductFactsException $e) {
            return ['error' => $e->getMessage()];
        } catch (\Throwable $e) {
            // No transformar una falla transitoria en una negación permanente de la capacidad.
            return ['error' => 'No pude completar la búsqueda web en este momento. Podés reintentar; no generé ni guardé información sin verificar.'];
        }
        $result['articulo_id'] = (int) $article->id;
        $result['articulo'] = $article->name;
        if (!empty($result['found'])) {
            $id = (string) Str::uuid();
            Cache::put(self::clave($id), ['owner_id' => $contexto->owner_id, 'conversation_id' => $conversation->id,
                'auth_user_id' => $conversation->auth_user_id, 'article_id' => $article->id,
                'identidad' => self::identidad($article), 'ficha' => $result], now()->addHours(24));
            $result['consulta_id'] = $id;
            $result['descripcion_actual'] = $article->descriptions->map(function ($d) {
                return ['title' => $d->title, 'content' => strip_tags((string) $d->content)];
            })->all();
            $result['siguiente'] = 'Si la persona pidió asignarla, llamá ahora a proponer_descripcion_verificada con consulta_id. Conserva todas las descripciones anteriores y espera confirmación.';
            if ($input['preparar_descripcion']) {
                // Un pedido de buscar Y asignar no debe terminar con "¿la agrego?" sin nada que
                // confirmar. Preparar aquí es reversible; la escritura sigue exigiendo el único sí.
                $propuesta = self::proponer($contexto, $mensaje, ['consulta_id' => $id]);
                if (!empty($propuesta['ok'])) {
                    $propuesta['nota'] = $mensaje->confirma_por_texto()
                        ? 'La propuesta real ya está preparada y espera el sí de la persona. Mostrá sus datos y preguntá si confirma; no digas tarjeta ni que ya quedó guardado.'
                        : AccionesIaHelper::NOTA_PROPUESTA;
                }
                $result['propuesta'] = $propuesta;
                $result['siguiente'] = !empty($propuesta['ok'])
                    ? 'La propuesta real YA se creó. No armes otra ni pidas permiso para prepararla: presentá la ficha y sus fuentes y esperá la confirmación para guardarla.'
                    : 'La búsqueda se completó, pero no se pudo preparar la propuesta: explicá el error de propuesta y no digas que quedó guardada.';
            }
        }
        return $result;
    }

    public static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input): array
    {
        if (!self::autorizado($contexto, 'article.update')) {
            return RespuestaDeCargaIa::error('Tu usuario no tiene permiso para modificar descripciones de artículos.');
        }
        $data = Cache::get(self::clave((string) ($input['consulta_id'] ?? '')));
        if (!is_array($data) || (int) $data['owner_id'] !== $contexto->owner_id
            || (int) $data['conversation_id'] !== (int) $contexto->conversation->id
            || (int) $data['auth_user_id'] !== (int) $contexto->conversation->auth_user_id) {
            return RespuestaDeCargaIa::error('La ficha no pertenece a esta conversación o venció. Volvé a buscarla.');
        }
        $ficha = $data['ficha'];
        $renglones = [['etiqueta' => 'Artículo', 'valor' => $ficha['articulo']]];
        foreach ($ficha['sections'] as $section) {
            $renglones[] = ['etiqueta' => $section['title'], 'valor' => $section['content']];
        }
        foreach ($ficha['sources'] as $source) {
            $renglones[] = ['etiqueta' => 'Fuente', 'valor' => $source['url']];
        }
        $creada = AccionesIaHelper::crear($contexto, $mensaje, AiMessageAction::TIPO_DESCRIPCION_VERIFICADA,
            'descripcion_verificada:' . $data['article_id'], $data,
            ['titulo' => 'Agregar ficha verificada al artículo', 'renglones' => $renglones,
                'aviso' => 'Se agregan estas secciones sin borrar ni reemplazar ninguna descripción existente.'],
            isset($input['reemplaza_a']) ? $input['reemplaza_a'] : null);
        return AccionesIaHelper::respuesta_de_propuesta($creada, 'Agregar ficha verificada a ' . $ficha['articulo'],
            ['sections' => $ficha['sections'], 'sources' => $ficha['sources'], 'requiere_confirmacion' => true]);
    }

    public static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion): array
    {
        if (!self::autorizado($contexto, 'article.update')) {
            throw new AccionIaException(422, 'Tu usuario no tiene permiso para modificar descripciones de artículos.');
        }
        $data = $accion->datos;
        $article = Article::where('user_id', $contexto->owner_id)->where('id', $data['article_id'])->lockForUpdate()->first();
        if (!$article || self::identidad($article) !== $data['identidad']) {
            throw new AccionIaException(422, 'El artículo ya no existe o cambió su identificación. Volvé a buscar la ficha.');
        }
        $ids = [];
        foreach ($data['ficha']['sections'] as $section) {
            // La tienda usa v-html: la evidencia y la salida del modelo nunca se guardan como HTML ejecutable.
            $content = nl2br(htmlspecialchars($section['content'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            $description = Description::where('article_id', $article->id)->where('title', $section['title'])->where('content', $content)->first();
            if (!$description) {
                $description = Description::create(['article_id' => $article->id, 'title' => $section['title'], 'content' => $content,
                    'ai_generated' => true, 'ai_confidence' => 'medium', 'ai_sources' => $data['ficha']['sources'], 'ai_reviewed_at' => now()]);
            }
            $ids[] = (int) $description->id;
        }
        if (config('app.USA_TIENDA_NUBE') || env('USA_TIENDA_NUBE', false)) {
            dispatch((new \App\Jobs\ProcessSyncArticleDescriptionTiendaNube($article))->afterCommit());
        }
        return ['texto' => 'Se agregó la ficha verificada a ' . $article->name . ', conservando sus descripciones anteriores.',
            'ruta' => ['name' => 'article', 'params' => new \stdClass(), 'texto' => 'Ver en el Listado'],
            'entidad' => 'article', 'article_id' => (int) $article->id, 'description_ids' => $ids];
    }

    protected static function autorizado(ContextoDeCargaIa $contexto, $permiso): bool
    {
        return $contexto->owner && $contexto->persona
            && ((int) $contexto->persona->id === $contexto->owner_id || (int) $contexto->persona->owner_id === $contexto->owner_id)
            && PermisosIaHelper::tiene_extencion($contexto->owner, 'agentes_ia')
            && PermisosIaHelper::puede($contexto->persona, $permiso);
    }

    protected static function identidad(Article $article): string
    {
        return hash('sha256', json_encode([$article->name, $article->bar_code, $article->brand_id, $article->bodega_id]));
    }

    protected static function clave($id): string
    {
        return 'ficha_articulo_web:' . hash('sha256', (string) $id);
    }
}
