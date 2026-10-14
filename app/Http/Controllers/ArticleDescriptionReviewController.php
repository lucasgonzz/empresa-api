<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\AiDescriptionReviewHelper;
use Illuminate\Http\Request;

/**
 * Alertas → Catálogo → Descripciones (misión alertas-descripciones, 14/10/2026): las descripciones
 * que la IA no pudo confirmar ("A revisar") y las de IA que ya se ven ("Publicadas"). Contrato: §1.4
 * del plan de la misión.
 *
 * Solo traduce a HTTP: la lógica vive en AiDescriptionReviewHelper.
 *
 * Permisos como Imágenes (decisión D2): cualquier sesión del comercio. Todo se acota por el DUEÑO
 * (`$this->userId()`) y lo de otro comercio responde 404; quién resolvió cada propuesta es la persona
 * real (`$this->userId(false)`).
 */
class ArticleDescriptionReviewController extends Controller
{
    /**
     * GET article-description-ai/resumen — liviano, para el número rojo.
     *
     * @return \Illuminate\Http\JsonResponse  {a_revisar, publicadas, badge}
     */
    public function resumen()
    {
        return response()->json(AiDescriptionReviewHelper::resumen($this->userId()), 200);
    }

    /**
     * GET article-description-ai/revision?solapa=a_revisar|publicadas&pagina=&por_pagina=&buscar=
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse  {models: [Item], total, pagina, por_pagina, conteos}
     */
    public function revision(Request $request)
    {
        $solapa = $request->query('solapa');

        if (is_null($solapa) || $solapa === '') {
            $solapa = AiDescriptionReviewHelper::SOLAPA_A_REVISAR;
        }

        /*
         * Con la forma del 422 estándar de Laravel ({message, errors}), pero armado a mano: el
         * validate() de esta versión pone en `message` el genérico "Los datos enviados no son
         * válidos." y el comerciante tiene que leer qué pasó.
         */
        if (!is_string($solapa) || !in_array($solapa, AiDescriptionReviewHelper::SOLAPAS, true)) {
            $mensaje = 'Esa solapa no existe: elegí «A revisar» o «Publicadas».';

            return response()->json(['message' => $mensaje, 'errors' => ['solapa' => [$mensaje]]], 422);
        }

        return response()->json(AiDescriptionReviewHelper::revision(
            $this->userId(),
            $solapa,
            $request->query('pagina', 1),
            $request->query('por_pagina', AiDescriptionReviewHelper::POR_PAGINA_DEFECTO),
            $request->query('buscar', '')
        ), 200);
    }

    /**
     * POST article-description-ai/revision/{article_id}/aprobar {secciones?: [{title, content}]}
     *
     * Sin `secciones` se aprueba tal cual la propuso la IA; con ellas, lo que escribió la persona.
     * Si el artículo ya tiene una descripción escrita a mano, la sugerencia se descarta y responde
     * 409 {message, conteos}.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $article_id
     * @return \Illuminate\Http\JsonResponse  {conteos} | 404 {message} | 409 {message, conteos}
     */
    public function aprobar(Request $request, $article_id)
    {
        $request->validate([
            'secciones'           => 'nullable|array|min:1|max:'.AiDescriptionReviewHelper::MAXIMO_DE_SECCIONES,
            'secciones.*'         => 'array',
            'secciones.*.title'   => 'required|string|max:'.AiDescriptionReviewHelper::MAXIMO_DEL_TITULO,
            'secciones.*.content' => 'required|string',
        ], $this->mensajes_de_secciones());

        // Sin el campo, o en null, es "tal cual": las secciones de la propuesta.
        $secciones = is_array($request->input('secciones')) ? $request->input('secciones') : null;

        return $this->respuesta_con_conteos(AiDescriptionReviewHelper::aprobar(
            $this->userId(),
            (int) $article_id,
            $secciones,
            $this->userId(false)
        ));
    }

    /**
     * POST article-description-ai/revision/{article_id}/descartar
     *
     * @param  int $article_id
     * @return \Illuminate\Http\JsonResponse  {conteos} | 404 {message}
     */
    public function descartar($article_id)
    {
        return $this->respuesta_con_conteos(AiDescriptionReviewHelper::descartar(
            $this->userId(),
            (int) $article_id,
            $this->userId(false)
        ));
    }

    /**
     * POST article-description-ai/revision/aprobar-varios {article_ids: []}
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse  {resueltos, conteos}
     */
    public function aprobar_varios(Request $request)
    {
        return $this->en_lote($request, 'aprobar');
    }

    /**
     * POST article-description-ai/revision/descartar-varios {article_ids: []}
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse  {resueltos, conteos}
     */
    public function descartar_varios(Request $request)
    {
        return $this->en_lote($request, 'descartar');
    }

    /**
     * PUT article-description-ai/revision/{article_id}/publicada {secciones: [{id, title, content}]}
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $article_id
     * @return \Illuminate\Http\JsonResponse  {model: Item, conteos} | 404 | 422 {message}
     */
    public function publicada(Request $request, $article_id)
    {
        $request->validate([
            'secciones'           => 'required|array|min:1|max:'.AiDescriptionReviewHelper::MAXIMO_DE_SECCIONES,
            'secciones.*'         => 'array',
            'secciones.*.id'      => 'required|integer',
            'secciones.*.title'   => 'required|string|max:'.AiDescriptionReviewHelper::MAXIMO_DEL_TITULO,
            'secciones.*.content' => 'required|string',
        ], $this->mensajes_de_secciones());

        $resultado = AiDescriptionReviewHelper::editar_publicada(
            $this->userId(),
            (int) $article_id,
            $request->input('secciones')
        );

        if ($resultado['status'] === 422) {
            // Misma forma que el 422 estándar de Laravel, así la SPA lo lee igual que los demás.
            return response()->json([
                'message' => $resultado['message'],
                'errors'  => ['secciones' => [$resultado['message']]],
            ], 422);
        }

        if ($resultado['status'] !== 200) {
            return response()->json(['message' => $resultado['message']], $resultado['status']);
        }

        return response()->json([
            'model'   => AiDescriptionReviewHelper::item_publicado($this->userId(), (int) $article_id),
            'conteos' => AiDescriptionReviewHelper::conteos($this->userId()),
        ], 200);
    }

    /**
     * POST article-description-ai/revision/{article_id}/quitar
     *
     * @param  int $article_id
     * @return \Illuminate\Http\JsonResponse  {conteos} | 404 {message}
     */
    public function quitar($article_id)
    {
        return $this->respuesta_con_conteos(AiDescriptionReviewHelper::quitar(
            $this->userId(),
            (int) $article_id,
            $this->userId(false)
        ));
    }

    /**
     * El cuerpo común de aprobar-varios y descartar-varios.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  string $accion  aprobar | descartar
     * @return \Illuminate\Http\JsonResponse
     */
    protected function en_lote(Request $request, $accion)
    {
        $request->validate([
            'article_ids'   => 'required|array|min:1|max:'.AiDescriptionReviewHelper::MAXIMO_EN_LOTE,
            'article_ids.*' => 'integer',
        ], [
            'article_ids.required' => 'No se mandó ningún artículo.',
            'article_ids.array'    => 'No se mandó ningún artículo.',
            'article_ids.min'      => 'No se mandó ningún artículo.',
            'article_ids.max'      => 'Se pueden resolver hasta '.AiDescriptionReviewHelper::MAXIMO_EN_LOTE.' artículos por vez.',
            'article_ids.*.integer' => 'Alguno de los artículos no es válido.',
        ]);

        $resueltos = AiDescriptionReviewHelper::en_lote(
            $accion,
            $this->userId(),
            $request->input('article_ids'),
            $this->userId(false)
        );

        return response()->json([
            'resueltos' => $resueltos,
            'conteos'   => AiDescriptionReviewHelper::conteos($this->userId()),
        ], 200);
    }

    /**
     * Traduce el resultado de aprobar / descartar / quitar.
     *
     * @param  array $resultado  ['status', 'message']
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respuesta_con_conteos(array $resultado)
    {
        // El 409 de aprobar (ya tenía descripción a mano) igual cambió algo: la propuesta quedó
        // descartada, y la SPA necesita los conteos nuevos para el número rojo.
        if ($resultado['status'] === 409) {
            return response()->json([
                'message' => $resultado['message'],
                'conteos' => AiDescriptionReviewHelper::conteos($this->userId()),
            ], 409);
        }

        if ($resultado['status'] !== 200) {
            return response()->json(['message' => $resultado['message']], $resultado['status']);
        }

        return response()->json(['conteos' => AiDescriptionReviewHelper::conteos($this->userId())], 200);
    }

    /**
     * Los mensajes de validación de las secciones, para el comerciante.
     *
     * @return array
     */
    protected function mensajes_de_secciones()
    {
        return [
            'secciones.required'           => 'Faltan las secciones de la descripción.',
            'secciones.array'              => 'Las secciones de la descripción no tienen el formato esperado.',
            'secciones.min'                => 'La descripción tiene que tener al menos una sección.',
            'secciones.max'                => 'La descripción puede tener hasta '.AiDescriptionReviewHelper::MAXIMO_DE_SECCIONES.' secciones.',
            'secciones.*.array'            => 'Las secciones de la descripción no tienen el formato esperado.',
            'secciones.*.id.required'      => 'Falta indicar qué sección se edita.',
            'secciones.*.id.integer'       => 'Falta indicar qué sección se edita.',
            'secciones.*.title.required'   => 'Cada sección necesita un título.',
            'secciones.*.title.string'     => 'Cada sección necesita un título.',
            'secciones.*.title.max'        => 'El título de una sección puede tener hasta '.AiDescriptionReviewHelper::MAXIMO_DEL_TITULO.' caracteres.',
            'secciones.*.content.required' => 'Cada sección necesita un texto.',
            'secciones.*.content.string'   => 'Cada sección necesita un texto.',
        ];
    }
}
