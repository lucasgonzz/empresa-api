<?php

namespace Tests\Feature\AgentesIa;

/**
 * Misión agentes-ia-extension-unica (22/10/2026) — `POST admin-sync/sistema-query` (el canal «sistema:» de
 * WhatsApp de soporte).
 *
 * 🔴 Decisión: este canal NO es parte de «Agentes de IA». Está prometido por escrito como incluido en TODOS los
 * planes (`precios.md`, regla 11 del agente de leads), así que queda abierto aunque el comercio no tenga la
 * extensión. Estos tests lo fijan para que nadie lo gatee por error al seguir unificando la IA:
 *
 *  - Sin la extensión → 200 con los datos (no 403 ni 404).
 *  - Con la extensión → 200 con los datos, igual.
 *  - Sin la clave de admin (instancia con clave cargada) → 401, como el resto del grupo.
 */
class Sistema_query_de_admin_sync_Test extends AgentesIaTestCase
{
    /**
     * @test
     * @return void
     */
    public function el_canal_sistema_sigue_abierto_sin_la_extension()
    {
        $cuerpo = ['query' => 'cuánto stock tengo de martillos', 'user_id' => $this->comercio()->id];

        $this->quitar_extension();

        $this->postJson('api/admin-sync/sistema-query', $cuerpo, $this->cabeceras_de_admin())
             ->assertStatus(200)
             ->assertJsonStructure(['data', 'query_type']);

        $this->dar_extension();

        $this->postJson('api/admin-sync/sistema-query', $cuerpo, $this->cabeceras_de_admin())
             ->assertStatus(200)
             ->assertJsonStructure(['data', 'query_type']);
    }

    /**
     * @test
     * @return void
     */
    public function sin_la_clave_de_admin_es_401()
    {
        $this->quitar_extension();

        $this->postJson('api/admin-sync/sistema-query', ['query' => 'stock de clavos', 'user_id' => $this->comercio()->id])
             ->assertStatus(401);
    }
}
