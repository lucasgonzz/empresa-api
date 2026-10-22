<?php

namespace Tests\Feature\AgentesIa;

/**
 * Misión agentes-ia-extension-unica (22/10/2026) — `POST admin-sync/sistema-query` (el canal «sistema:» de
 * WhatsApp de soporte lee datos del negocio por IA).
 *
 * No pasa por `auth:sanctum`, así que el gate se hace a mano sobre el dueño cuyos datos se sirven:
 *
 *  - Sin la extensión → **403** (jamás 404, que el admin lee como «versión vieja»).
 *  - Con la extensión → 200 con los datos.
 *  - Sin la clave de admin (instancia con clave cargada) → 401, como el resto del grupo.
 */
class Sistema_query_de_admin_sync_Test extends AgentesIaTestCase
{
    /**
     * @test
     * @return void
     */
    public function sin_la_extension_contesta_403_y_con_ella_devuelve_datos()
    {
        $cuerpo = ['query' => 'cuánto stock tengo de martillos', 'user_id' => $this->comercio()->id];

        $this->quitar_extension();

        $sin = $this->postJson('api/admin-sync/sistema-query', $cuerpo, $this->cabeceras_de_admin());

        $sin->assertStatus(403);
        $this->assertStringContainsString('agentes_ia', (string) $sin->json('message'));

        $this->dar_extension();

        $this->postJson('api/admin-sync/sistema-query', $cuerpo, $this->cabeceras_de_admin())
             ->assertStatus(200)
             ->assertJsonStructure(['data', 'query_type']);
    }

    /**
     * @test
     * @return void
     */
    public function sin_la_clave_de_admin_es_401_antes_de_mirar_la_extension()
    {
        $this->dar_extension();

        $this->postJson('api/admin-sync/sistema-query', ['query' => 'stock de clavos', 'user_id' => $this->comercio()->id])
             ->assertStatus(401);
    }
}
