<?php

namespace Tests\Feature\ChatIa;

use App\Models\AiConversation;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-diario-20261117 (charlas de Truvari del 16/11/2026), B1 y B2: dos reglas del bloque de carga del
 * system prompt.
 *
 *  - B1: nunca nombrar rutas, ids internos, controllers ni campos; y «confirmalo y facturalo» es un solo pedido:
 *    después de confirmar el presupuesto se arma directo la tarjeta de la factura, sin preguntar «¿querés que
 *    busque la venta?».
 *  - B2: si la persona anuncia la foto y no llegó ninguna imagen, se la espera y no se busca una en internet.
 *
 * Las dos van en el bloque de carga: están por WhatsApp y en el sistema, y no en el modo solo lectura (sin cargas).
 */
class T177_Sin_jerga_factura_directa_y_foto_anunciada_Test extends EmpresaTestCase
{
    protected $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: este archivo no sale a la red.
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail();
        $extencion = ExtencionEmpresa::where('slug', 'agentes_ia')->first()
            ?: ExtencionEmpresa::forceCreate(['slug' => 'agentes_ia', 'name' => 'Agentes de IA']);
        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);
        $this->dueno->load('extencions');
    }

    /**
     * Los tres prompts: WhatsApp y sistema con cargas, y el de solo lectura.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    protected function prompts(): array
    {
        $conversacion = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]);
        $servicio = new AsistenteIaService();

        return [
            $servicio->build_system_prompt($conversacion, $this->dueno->fresh(), true, true),
            $servicio->build_system_prompt($conversacion, $this->dueno->fresh(), true, false),
            $servicio->build_system_prompt($conversacion, $this->dueno->fresh(), false, false),
        ];
    }

    public function test_la_regla_de_no_nombrar_rutas_ni_ids_esta_en_el_bloque_de_carga()
    {
        list($whatsapp, $sistema, $solo_lectura) = $this->prompts();

        foreach ([$whatsapp, $sistema] as $prompt) {
            $this->assertStringContainsString('Las rutas, los ids y los nombres de campo son para las herramientas, NO para la persona', $prompt);
            $this->assertStringContainsString('que le escribís nunca nombres una ruta («api/...»), un id interno (sale_id, el id de una', $prompt);
        }

        $this->assertStringNotContainsString('Las rutas, los ids y los nombres de campo son para las herramientas', $solo_lectura);
    }

    public function test_confirmar_y_facturar_es_un_solo_pedido()
    {
        list($whatsapp, $sistema) = $this->prompts();

        foreach ([$whatsapp, $sistema] as $prompt) {
            $this->assertStringContainsString('Si la persona pidió confirmar un presupuesto Y facturarlo («confirmalo y facturalo»), es un', $prompt);
            $this->assertStringContainsString('no se deduce— y nunca le preguntes «¿querés que busque la venta?» ni si querés seguir con la', $prompt);
        }
    }

    public function test_la_foto_anunciada_que_no_llego_se_espera_sin_buscar_en_internet()
    {
        list($whatsapp, $sistema, $solo_lectura) = $this->prompts();

        foreach ([$whatsapp, $sistema] as $prompt) {
            $this->assertStringContainsString('foto») y en ESTE mensaje no llegó ninguna imagen, esperala: decile en una línea que todavía no', $prompt);
            $this->assertStringContainsString('te llegó y que la esperás para seguir. En ese turno no busques una foto en internet ni armes el', $prompt);
        }

        $this->assertStringNotContainsString('y en ESTE mensaje no llegó ninguna imagen, esperala', $solo_lectura);
    }
}
