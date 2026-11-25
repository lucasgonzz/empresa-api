<?php

namespace Tests\Feature\OnboardingConversacional;

/**
 * Misión onboarding-conversacional-whatsapp — CA9, contrato C1: `GET api/admin-sync/agente-contexto/estado`.
 * El admin consulta si el dueño ya contó su negocio (por la app o por WhatsApp) y marca solo el hito
 * `onboarding_completado`. La clave del admin se exige SIEMPRE, como en `inicializar`.
 */
class Estado_para_el_admin_Test extends OnboardingTestCase
{
    const RUTA = 'api/admin-sync/agente-contexto/estado';

    /** El formulario «Conozcámonos» completo. */
    protected function completa_el_formulario()
    {
        $this->actingAs($this->comercio, 'sanctum')->postJson('api/ia-app/cuestionario', [
            'negocio_frase' => 'Distribuidora ficticia.', 'objetivos' => ['vender más'], 'areas' => ['stock'],
            'importa_hoy' => 'Cobrar.', 'largo' => 'breve', 'forma' => 'explicado', 'confianza' => 'cauteloso',
        ])->assertStatus(200);

        $this->app['auth']->forgetGuards();
    }

    /**
     * @group onboarding-conversacional
     * @test
     */
    public function test_errores()
    {
        $this->getJson(self::RUTA)->assertStatus(401)->assertJsonPath('code', 'clave_invalida');

        $this->getJson(self::RUTA, ['X-Admin-Api-Key' => 'otra'])->assertStatus(401)->assertJsonPath('code', 'clave_invalida');

        $this->comercio->extencions()->detach();
        $this->comercio->load('extencions');

        $this->getJson(self::RUTA, $this->headers())->assertStatus(409)->assertJsonPath('code', 'sin_extension');
    }

    /**
     * @group onboarding-conversacional
     * @test
     */
    public function test_completado_por_app()
    {
        $this->completa_el_formulario();

        $this->getJson(self::RUTA, $this->headers())
            ->assertStatus(200)
            ->assertJsonPath('cuestionario.completado', true)
            ->assertJsonPath('onboarding.estado', null)
            ->assertJsonPath('onboarding.temas.total', 32)
            ->assertJsonPath('onboarding.temas.esenciales_total', 13)
            ->assertJsonPath('onboarding_completado', true)
            ->assertJsonPath('onboarding_completado_por', 'app')
            ->assertJsonStructure(['onboarding_completado_en']);
    }

    /**
     * @group onboarding-conversacional
     * @test
     */
    public function test_completado_por_whatsapp()
    {
        $this->el_dueno_escribe('Hola, ¿andás?');

        $this->el_agente_guarda(self::ESENCIALES);

        $this->getJson(self::RUTA, $this->headers())
            ->assertStatus(200)
            ->assertJsonPath('cuestionario.completado', false)
            ->assertJsonPath('onboarding.estado', 'en_curso')
            ->assertJsonPath('onboarding.origen', 'whatsapp')
            ->assertJsonPath('onboarding.esenciales_completos', true)
            ->assertJsonPath('onboarding.completo', false)
            ->assertJsonPath('onboarding.temas.esenciales_listos', 13)
            ->assertJsonPath('onboarding.temas.sabidos', 13)
            ->assertJsonPath('onboarding.temas.faltan', 19)
            ->assertJsonPath('onboarding_completado', true)
            ->assertJsonPath('onboarding_completado_por', 'whatsapp');
    }

    /**
     * @group onboarding-conversacional
     * @test
     */
    public function test_doce_esenciales_no_alcanzan()
    {
        $this->el_dueno_escribe('Hola, ¿andás?');

        $this->el_agente_guarda(array_slice(self::ESENCIALES, 0, 12));

        $this->getJson(self::RUTA, $this->headers())
            ->assertStatus(200)
            ->assertJsonPath('onboarding.esenciales_completos', false)
            ->assertJsonPath('onboarding.temas.esenciales_listos', 12)
            ->assertJsonPath('onboarding_completado', false)
            ->assertJsonPath('onboarding_completado_por', null)
            ->assertJsonPath('onboarding_completado_en', null);
    }
}
