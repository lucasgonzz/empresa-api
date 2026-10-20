<?php

namespace Tests\Feature\Whatsapp;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Http\Controllers\Helpers\WhatsappDemoHelper;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DemoPersonalidadObjetivoTest extends TestCase
{
    use DatabaseTransactions;

    public function test_personalidad_base_sigue_seleccionada_despues_de_personalizar_y_el_put_parcial_la_respeta()
    {
        $dueno = User::create(['name' => 'Configuración demo', 'email' => uniqid('config-demo-') . '@test.local', 'password' => bcrypt('local')]);
        $this->actingAs($dueno, 'web');
        $this->putJson('/api/whatsapp-bot/config', ['agent_personality' => 'Soy el asistente de Costa Demo.', 'agent_personality_template' => 'amigable', 'agent_objective' => 'buy_online'])->assertOk();
        $this->putJson('/api/whatsapp-bot/config', ['agent_personality' => 'Soy el asistente de Costa Demo. Respondo corto.'])->assertOk();
        $config = WhatsappBotConfig::where('user_id', $dueno->id)->firstOrFail();
        $this->assertSame('amigable', $config->agent_personality_template);
        $this->assertSame('buy_online', $config->agent_objective);
        $this->putJson('/api/whatsapp-bot/config', ['agent_personality_template' => 'desconocida'])->assertStatus(422);
    }

    public function test_demo_nace_con_objetivo_simulacion_y_deepseek_sin_pisar_una_demo_ya_configurada()
    {
        $dueno = User::create(['name' => 'Demo', 'company_name' => 'Costa Demo', 'email' => uniqid('semilla-demo-') . '@test.local', 'password' => bcrypt('local')]);
        config(['demo_industria_actual' => 'indumentaria']);
        $metodo = new \ReflectionMethod(WhatsappDemoHelper::class, 'preparar_config');
        $metodo->setAccessible(true);
        $metodo->invoke(null, $dueno->id);
        $config = WhatsappBotConfig::where('user_id', $dueno->id)->firstOrFail();
        $this->assertSame('amigable', $config->agent_personality_template);
        $this->assertSame('buy_online', $config->agent_objective);
        $this->assertTrue((bool) $config->chat_simulation_enabled);
        $this->assertSame(10, (int) $config->ai_reply_delay_seconds);
        $this->assertStringContainsString('Costa Demo', $config->agent_personality);
        $this->assertSame('deepseek', $dueno->fresh()->agente_proveedor);
        $this->assertSame('deepseek_flash', $dueno->fresh()->ia_modelo_whatsapp);
        $config->update(['agent_objective' => 'custom', 'agent_objective_detail' => 'Objetivo del usuario']);
        $metodo->invoke(null, $dueno->id);
        $this->assertSame('custom', $config->fresh()->agent_objective);
    }

    public function test_industria_desconocida_se_rechaza_antes_del_primer_comando_destructivo()
    {
        config(['app.FOR_USER' => 'demo']);
        Artisan::shouldReceive('call')->never();
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        DemoSetupHelper::run(['business_type' => 'otro', 'demo_industria' => 'no-existe']);
    }
}
