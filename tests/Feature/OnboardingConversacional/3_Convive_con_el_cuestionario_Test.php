<?php

namespace Tests\Feature\OnboardingConversacional;

use App\Http\Controllers\Helpers\asistente_ia\AgenteContextoHelper;
use App\Http\Controllers\Helpers\asistente_ia\IaAppHelper;

/**
 * Misión onboarding-conversacional-whatsapp — CA5: el onboarding por WhatsApp y el formulario «Conozcámonos» de
 * la app conviven. Lo que el dueño contó en el formulario no se le vuelve a preguntar, lo que el agente anota
 * no se pisa al reenviar el formulario, y la app se entera de cómo viene el onboarding.
 */
class Convive_con_el_cuestionario_Test extends OnboardingTestCase
{
    /** Una respuesta completa y válida del formulario. */
    protected function respuestas(array $cambios = [])
    {
        return array_merge([
            'negocio_frase'   => 'Distribuidora ficticia de golosinas para kioscos.',
            'objetivos'       => ['vender más'],
            'objetivos_texto' => 'Abrir un segundo depósito.',
            'areas'           => ['stock'],
            'importa_hoy'     => 'Cobrar a tiempo.',
            'largo'           => 'breve',
            'forma'           => 'explicado',
            'confianza'       => 'cauteloso',
            'extra'           => null,
        ], $cambios);
    }

    /** El dueño completa el formulario de la app. */
    protected function completa_el_formulario(array $cambios = [])
    {
        return $this->actingAs($this->comercio, 'sanctum')->postJson('api/ia-app/cuestionario', $this->respuestas($cambios));
    }

    /**
     * @group onboarding-conversacional
     * @test
     */
    public function test_siembra_4_temas()
    {
        $this->completa_el_formulario()->assertStatus(200);

        $marcados = $this->temas_marcados();

        $claves = array_keys($marcados);
        sort($claves);

        $this->assertSame(['como_responder', 'meta_del_anio', 'prioridad_hoy', 'que_vende_y_a_quien'], $claves);

        foreach ($marcados as $clave => $fila) {
            $this->assertSame('sabido', $fila->estado, $clave);
            $this->assertSame('cuestionario', $fila->fuente, $clave);
        }

        // Reenviar el formulario no duplica nada.
        $this->completa_el_formulario()->assertStatus(200);
        $this->assertCount(4, $this->temas_marcados());

        // Y el primer mensaje por WhatsApp después del formulario: no se lo vuelve a preguntar.
        $this->el_dueno_escribe('Hola, ¿andás?');

        $bloque = $this->bloque_de_onboarding();

        $this->assertStringContainsString('Ya te contó en la app', $bloque);
        $this->assertStringNotContainsString('- que_vende_y_a_quien → ', $bloque, 'Lo que contó en el formulario no figura como faltante.');
        $this->assertStringNotContainsString('- meta_del_anio → ', $bloque);
        $this->assertStringContainsString('- historia → ', $bloque);
    }

    /**
     * Lo que el agente anota fuera de los bloques del formulario sobrevive a un reenvío; y lo que el agente marcó
     * antes conserva su fuente.
     *
     * @group onboarding-conversacional
     * @test
     */
    public function test_no_pisa_lo_del_agente()
    {
        $this->el_agente_guarda(['que_vende_y_a_quien'], 'negocio/resumen.md');

        $this->completa_el_formulario(['negocio_frase' => 'Primera versión del formulario.'])->assertStatus(200);

        $this->completa_el_formulario(['negocio_frase' => 'Segunda versión del formulario.'])->assertStatus(200);

        $contenido = AgenteContextoHelper::leer($this->comercio, 'negocio/resumen.md')['contenido'];

        $this->assertStringContainsString('Dato de prueba de la suite', $contenido, 'Lo del agente sobrevive al reenvío.');
        $this->assertStringContainsString('Segunda versión del formulario.', $contenido, 'El bloque del formulario se actualiza.');
        $this->assertStringNotContainsString('Primera versión del formulario.', $contenido);

        $this->assertSame('agente', $this->temas_marcados()['que_vende_y_a_quien']->fuente, 'El formulario no pisa un tema que el agente ya marcó.');
        $this->assertSame('cuestionario', $this->temas_marcados()['meta_del_anio']->fuente);
    }

    /**
     * La app recibe `onboarding` (contrato C2): en `cuestionario` de `ia-app/yo` y en la raíz de
     * `ia-app/cuestionario`.
     *
     * @group onboarding-conversacional
     * @test
     */
    public function test_app_recibe_onboarding()
    {
        $this->el_dueno_escribe('Hola, ¿andás?');

        $this->actingAs($this->comercio, 'sanctum')->getJson('api/ia-app/cuestionario')
            ->assertStatus(200)
            ->assertJsonPath('onboarding.estado', 'en_curso')
            ->assertJsonPath('onboarding.esenciales_completos', false)
            ->assertJsonPath('onboarding.completo', false);

        $token = IaAppHelper::emitir_token($this->comercio, 'Telefono de prueba');

        $this->app['auth']->forgetGuards();

        $this->getJson('api/ia-app/yo', ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'])
            ->assertStatus(200)
            ->assertJsonPath('cuestionario.onboarding.estado', 'en_curso')
            ->assertJsonPath('cuestionario.onboarding.esenciales_completos', false)
            ->assertJsonPath('cuestionario.onboarding.completo', false)
            ->assertJsonStructure(['cuestionario' => ['completado', 'salteado', 'completado_en']]);
    }
}
