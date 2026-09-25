<?php

namespace Tests\Feature\Seguridad;

use Tests\TestCase;

/**
 * Misión asistente-fotos-barras-y-compras (24/9/2026) — un request sin sesión a `api/*` contesta 401,
 * no 500.
 *
 * El caso real (demo3, 24/9): el `<img>` de una foto del asistente (`api/ai-mensajes/{id}/imagen/{orden}`)
 * llegaba sin sesión y sin `Accept: application/json`; `Authenticate::redirectTo()` pedía
 * `route('login')`, que en este proyecto no existe, y el request moría con `Route [login] not
 * defined` (500, y reportado como error). Sin actingAs() a propósito: lo que se prueba es
 * justamente el request sin sesión, como lo manda un navegador.
 */
class No_autenticado_en_api_Test extends TestCase
{
    /** @test */
    public function una_foto_del_asistente_sin_sesion_contesta_401_y_no_500()
    {
        /* Sin headers: así pide un <img>, que no manda Accept: application/json. */
        $respuesta = $this->get('api/ai-mensajes/1/imagen/1');

        $respuesta->assertStatus(401);
    }

    /** @test */
    public function un_pedido_json_sin_sesion_sigue_contestando_401()
    {
        $this->getJson('api/ai-mensajes/1/imagen/1')->assertStatus(401);
    }
}
