<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\AvisosDelAsistenteHelper;
use App\Models\AiConversation;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Support\Facades\Cache;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-diario-20261116 (rama C, 16/11/2026), ítem M6-url — la dirección del sistema en el prompt.
 *
 * EL CASO REAL (Ferre Total, 14/11/2026): el dueño pidió la dirección del sistema y el asistente contestó «no
 * la tengo». Lo que protege este archivo:
 *
 *  - con la `spa_url` que anunció el admin, el prompt la trae para darla tal cual;
 *  - sin ninguna conocida, el prompt dice que no la invente, y nunca usa la URL de la API (`app.url`).
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados, union types.
 *
 * @group chat-ia
 */
class T173_Direccion_del_sistema_en_el_prompt_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio();

        config(['services.mostrador.spa_url' => null]);
    }

    /**
     * @return string
     */
    protected function prompt()
    {
        $conversation = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]);

        return (new AsistenteIaService())->build_system_prompt($conversation, $this->dueno, true, true);
    }

    /**
     * 🔴 Con la dirección anunciada por el admin, el prompt la trae.
     *
     * @test
     */
    public function con_la_direccion_anunciada_el_prompt_la_trae()
    {
        AvisosDelAsistenteHelper::anunciar($this->dueno->id, 1, 'https://ferre-zzc173.comerciocity.com/');

        $prompt = $this->prompt();

        $this->assertStringContainsString('La dirección del sistema de este negocio es https://ferre-zzc173.comerciocity.com .', $prompt);

        Cache::forget(AvisosDelAsistenteHelper::CLAVE_SPA_URL . $this->dueno->id);
    }

    /**
     * Sin dirección conocida, no la inventa y no usa la de la API.
     *
     * @test
     */
    public function sin_direccion_conocida_no_la_inventa()
    {
        Cache::forget(AvisosDelAsistenteHelper::CLAVE_SPA_URL . $this->dueno->id);

        $prompt = $this->prompt();

        $this->assertStringContainsString('no la inventes', $prompt);
        $this->assertStringNotContainsString('La dirección del sistema de este negocio es', $prompt);
        $this->assertStringNotContainsString((string) config('app.url') . ' .', $prompt);
    }
}
