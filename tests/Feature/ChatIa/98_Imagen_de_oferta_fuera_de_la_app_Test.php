<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Models\AiConversation;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\EmpresaTestCase;

/**
 * Misión agente-mejoras-uso-real (29/10/2026) — arreglo de la revisión independiente de E3:
 * LA IMAGEN FUERA DE LA APP (CONV 24 de Ferre Total). «¿Podés hacerme una imagen de oferta?» por WhatsApp
 * y el agente contestó «No tengo forma de generar una imagen». En el sistema y en WhatsApp el prompt manda a
 * la app ComercioCity IA (ia.comerciocity.com, con el mismo usuario) cuando el negocio la tiene, a pedírsela al
 * dueño cuando escribe un empleado sin acceso, y condicional sin la extensión. En la app, nada (ahí está
 * pedir_imagen). Nunca «no se puede».
 *
 * 🔴 Nada sale a la red.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Imagen_de_oferta_fuera_de_la_app_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio(['agente_confianza' => ConfianzaDelAgenteIaHelper::CAUTELOSO]);

        Http::fake(['*' => Http::response([], 200)]);
    }

    /**
     * Una conversación de esa persona.
     *
     * @param  User|null  $persona
     * @return AiConversation
     */
    protected function conversacion_de($persona = null)
    {
        return AiConversation::create([
            'user_id'      => $this->dueno->id,
            'auth_user_id' => is_null($persona) ? $this->dueno->id : $persona->id,
        ]);
    }

    // =====================================================================
    // 1 · La imagen fuera de la app
    // =====================================================================

    /**
     * 🔴 CONV 24: en el sistema y en WhatsApp, a la app; en la app, la regla de siempre (pedir_imagen).
     *
     * @test
     */
    public function fuera_de_la_app_el_prompt_manda_a_la_app_y_nunca_dice_que_no_se_puede()
    {
        $servicio = new AsistenteIaService();
        $conversation = $this->conversacion_de();

        foreach ([[true, false], [true, true], [false, false], [false, true]] as $caso) {

            $prompt = $servicio->build_system_prompt($conversation, $this->dueno->fresh(), $caso[0], $caso[1]);

            $this->assertStringContainsString('la app ComercioCity IA (ia.comerciocity.com), entrando con el', $prompt, json_encode($caso));
            $this->assertStringContainsString('mismo usuario y contraseña del sistema', $prompt, json_encode($caso));
            $this->assertStringContainsString('Nunca digas que no se puede', $prompt, json_encode($caso));
        }

        // En la app no va: ahí la imagen se pide con pedir_imagen.
        $app = $servicio->build_system_prompt($conversation, $this->dueno->fresh(), true, false, true);

        $this->assertStringNotContainsString('ia.comerciocity.com', $app);
        $this->assertStringContainsString('pedir_imagen', $app);
    }

    /**
     * Un empleado que no puede entrar a la app: que se la pida al dueño o a un administrador. Y sin la
     * extensión, condicional: nunca se promete la app.
     *
     * @test
     */
    public function con_un_empleado_sin_acceso_o_sin_la_extension_lo_dice_sin_prometer()
    {
        $servicio = new AsistenteIaService();

        $empleado = User::create([
            'name'         => 'ZZE Empleado sin acceso',
            'email'        => 'zze-empleado-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $this->dueno->id,
            'admin_access' => 0,
        ]);

        $del_empleado = $servicio->build_system_prompt($this->conversacion_de($empleado), $this->dueno->fresh(), true, true);

        $this->assertStringContainsString('que usan el dueño', $del_empleado);
        $this->assertStringContainsString('que se la pida a ellos', $del_empleado);
        $this->assertStringNotContainsString('entrando con el', $del_empleado);

        $this->sacar_extension('agentes_ia');

        $sin_extension = $servicio->build_system_prompt($this->conversacion_de(), $this->dueno->fresh(), true, true);

        $this->assertStringContainsString('si el negocio la tiene activa', $sin_extension);
        $this->assertStringContainsString('que viene con Agentes de IA', $sin_extension);
        $this->assertStringContainsString('Nunca digas que no se puede', $sin_extension);
    }
}
