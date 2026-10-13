<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\CentroRecursosDisponibilidadHelper;
use App\Http\Controllers\Helpers\CentroRecursosPaseHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El centro de recursos visto desde el sistema (misión centro-de-recursos-clientes, 13/10/2026).
 *
 * Dos puntas del mismo pase:
 *
 *  - `pase()` (C1): el SPA lo pide cuando el usuario toca "Centro de recursos" en el menú. Va bajo
 *    `auth:sanctum` y lo puede pedir cualquier usuario logueado, dueño o empleado.
 *  - `canjear()` (C3): lo llama el admin, por el canal admin-sync con `admin.api.key`, cuando la
 *    página le manda el pase. Devuelve el usuario dueño del pase y lo marca usado.
 *
 * El contrato completo está en el plan de la misión (§2): los nombres de campos y los códigos de
 * error los lee admin-api y la página; no se cambian sin hablarlo.
 *
 * IMPORTANTE (PHP 7.4): nada de match, str_contains, nullsafe ni argumentos nombrados.
 */
class CentroRecursosController extends Controller
{
    /**
     * POST centro-recursos/pase — C1.
     *
     * Body opcional: `{"video": "T1.29"}` para que la página abra directo ese video.
     *
     * 200 `{"url": "https://recursos.comerciocity.com/?pase=…&api=…[&v=…]", "disponible": true}`, o
     * 200 `{"url": null, "disponible": false}` si la página todavía no responde (C4): en ese caso NO
     * se crea ningún pase y el SPA muestra el aviso sin abrir nada.
     *
     * `api` es `url('/')`: la raíz pública de esta API tal como la alcanzó el navegador (con el
     * `/public` del hosting compartido si lo hay). El admin la usa SOLO para reconocer al cliente por
     * el host; para canjear llama a la URL que él tiene cargada, nunca a esta.
     *
     * @param  Request $request
     * @return JsonResponse
     */
    public function pase(Request $request): JsonResponse
    {
        $request->validate([
            'video' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]+$/'],
        ]);

        $video = $request->input('video');

        if (!is_string($video) || trim($video) === '') {
            $video = null;
        }

        if (!CentroRecursosDisponibilidadHelper::disponible()) {
            return response()->json([
                'url'        => null,
                'disponible' => false,
            ], 200);
        }

        $plano = CentroRecursosPaseHelper::crear($request->user(), $video);

        $parametros = [
            'pase' => $plano,
            'api'  => url('/'),
        ];

        if (!is_null($video)) {
            $parametros['v'] = $video;
        }

        $url = CentroRecursosDisponibilidadHelper::url_de_la_pagina()
            . '/?' . http_build_query($parametros, '', '&', PHP_QUERY_RFC3986);

        return response()->json([
            'url'        => $url,
            'disponible' => true,
        ], 200);
    }

    /**
     * POST admin-sync/recursos/canjear-pase — C3.
     *
     * Body: `{"pase": "<64 caracteres>"}`.
     *
     * 200 `{"user": {"id", "name", "email": string|null, "owner_id": int|null}, "video": string|null}`.
     * 404 `{"error": "pase_invalido"}` si no existe, ya se usó, venció, o el usuario no es del dueño
     * de esta instancia (ver CentroRecursosPaseHelper). La clave del admin la valida el middleware
     * `admin.api.key` del grupo, igual que en el resto del canal.
     *
     * `email` va null cuando no es una dirección válida, nunca string vacío (mismo criterio que
     * ContactoDuenoController): del otro lado "hay email" se lee como dato.
     *
     * @param  Request $request
     * @return JsonResponse
     */
    public function canjear(Request $request): JsonResponse
    {
        $resultado = CentroRecursosPaseHelper::canjear($request->input('pase'));

        if (is_null($resultado)) {
            return response()->json(['error' => 'pase_invalido'], 404);
        }

        $user = $resultado['user'];

        return response()->json([
            'user' => [
                'id'       => (int) $user->id,
                'name'     => (string) $user->name,
                'email'    => $this->email_valido($user->email),
                'owner_id' => !empty($user->owner_id) ? (int) $user->owner_id : null,
            ],
            'video' => $resultado['video'],
        ], 200);
    }

    /**
     * La casilla solo si es una dirección válida; si no, null.
     *
     * @param  mixed $valor Valor crudo de users.email.
     * @return string|null
     */
    protected function email_valido($valor)
    {
        if (is_null($valor)) {
            return null;
        }

        $valor = trim((string) $valor);

        if ($valor === '' || filter_var($valor, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $valor;
    }
}
