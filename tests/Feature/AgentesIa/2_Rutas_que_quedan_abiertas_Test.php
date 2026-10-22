<?php

namespace Tests\Feature\AgentesIa;


/**
 * Misión agentes-ia-extension-unica (22/10/2026) — lo que NO se gatea, decisión por ruta.
 *
 * Quedan abiertas (sin `agentes_ia`) a propósito:
 *
 *  - `GET google/get-current` y `GET google/custom-search/aumentar-contador`: son los contadores de cuota que
 *    usa el buscador MANUAL de imágenes de la ficha del artículo (`SearchImage.vue`, que busca directo contra
 *    Google desde el navegador). Es una búsqueda, no un agente.
 *  - `ai-excel-import/*` (importar Excel con IA): decisión de Lucas, sigue sin extensión.
 *  - `admin-sync/ai-excel-import/*`, `admin-sync/consumo-ia`, `admin-sync/plan-ia`, `admin-sync/modelos-ia`: el
 *    admin las usa para topes, modelos y consumo y tienen que seguir andando aunque el cliente no tenga la
 *    extensión.
 *  - `informe-compartido/{token}`: queda por token (el dueño lo abre desde el celular, sin sesión).
 *  - `online-configuration/generate-palette`: es el logo, no un agente.
 *  - `user/set-chat-ia-preferencias`: preferencia por persona, fuera del gate.
 *
 * Todas se prueban SIN la extensión y la aserción es que el 403 de la extensión no aparece.
 */
class Rutas_que_quedan_abiertas_Test extends AgentesIaTestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->quitar_extension();
    }

    /**
     * El buscador manual de imágenes de la ficha del artículo sigue andando sin la extensión.
     *
     * @test
     * @return void
     */
    public function el_buscador_manual_de_imagenes_de_la_ficha_queda_abierto()
    {
        $this->assertFalse($this->es_el_403_de_la_extension($this->getJson('api/google/get-current')));
        $this->assertFalse($this->es_el_403_de_la_extension($this->getJson('api/google/custom-search/aumentar-contador')));
    }

    /**
     * Importar Excel con IA sigue sin extensión (decisión de Lucas).
     *
     * @test
     * @return void
     */
    public function importar_excel_con_ia_queda_abierto()
    {
        $this->getJson('api/ai-excel-import/analysis-en-curso')->assertStatus(200);

        $this->assertFalse($this->es_el_403_de_la_extension($this->getJson('api/ai-excel-import/analysis/00000000-0000-0000-0000-000000000000')));
        $this->assertFalse($this->es_el_403_de_la_extension($this->postJson('api/ai-excel-import/analysis/00000000-0000-0000-0000-000000000000/visto', [])));

        /* La puerta de admin del importador: se prueba con la clave del admin. */
        $this->assertFalse($this->es_el_403_de_la_extension($this->postJson('api/admin-sync/ai-excel-import/analyze', [], $this->cabeceras_de_admin())));
        $this->assertFalse($this->es_el_403_de_la_extension($this->postJson('api/admin-sync/ai-excel-import/import', [], $this->cabeceras_de_admin())));
    }

    /**
     * Consumo, plan y modelos de IA los usa el admin para topes y modelos: siguen andando sin la extensión.
     *
     * @test
     * @return void
     */
    public function consumo_plan_y_modelos_de_ia_de_admin_sync_quedan_abiertos()
    {
        $this->getJson('api/admin-sync/modelos-ia', $this->cabeceras_de_admin())->assertStatus(200);
        $this->getJson('api/admin-sync/consumo-ia', $this->cabeceras_de_admin())->assertStatus(200);

        $this->putJson('api/admin-sync/plan-ia', ['nombre' => 'Plan de prueba', 'tope_tokens_mensual' => 1000], $this->cabeceras_de_admin())
             ->assertStatus(200)
             ->assertJsonPath('ok', true);
    }

    /**
     * El informe compartido por link queda por token: sin sesión y sin extensión no es el 403 de la extensión
     * (un token que no existe responde otra cosa).
     *
     * @test
     * @return void
     */
    public function el_informe_compartido_por_token_queda_abierto()
    {
        $this->app['auth']->forgetGuards();

        $respuesta = $this->getJson('api/informe-compartido/token-que-no-existe');

        $this->assertFalse($this->es_el_403_de_la_extension($respuesta));
        $this->assertNotEquals(401, $respuesta->getStatusCode(), 'El informe compartido pide sesión: tiene que quedar por token.');
    }

    /**
     * Generar la paleta del logo no es un agente: no pide la extensión (pide ser administrador, que es otra
     * cosa y es un 403 distinto).
     *
     * @test
     * @return void
     */
    public function generar_la_paleta_del_logo_no_pide_la_extension()
    {
        $this->assertFalse($this->es_el_403_de_la_extension($this->postJson('api/online-configuration/generate-palette', [])));
    }

    /**
     * La preferencia de UI del chat es por persona y queda fuera del gate.
     *
     * @test
     * @return void
     */
    public function la_preferencia_de_ui_del_chat_queda_abierta()
    {
        $this->assertFalse($this->es_el_403_de_la_extension($this->putJson('api/user/set-chat-ia-preferencias', [])));
    }
}
