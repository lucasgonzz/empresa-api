<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessageAction;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Illuminate\Support\Facades\Http;
use Tests\EmpresaTestCase;

/**
 * Misión agente-mejoras-uso-real (29/10/2026) — arreglo de la revisión independiente de E3:
 * LAS LISTAS CERRADAS DEL PROMPT nombran lo nuevo de esta misión: la compra sin artículos, la edición de
 * márgenes que saca un precio fijo o baja un precio y el cambio de proveedor con un proveedor nuevo (lo que
 * siempre espera el sí), y el cambio de proveedor a uno que existe y el reescaneo de una factura (lo que en
 * "directo" o "resuelto" se hace en el acto). Y lo que dice el prompt es lo que hace el código.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Listas_cerradas_del_prompt_al_dia_Test extends EmpresaTestCase
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
    // 2 · Las listas cerradas del prompt
    // =====================================================================

    /**
     * Las listas de lo que siempre espera el sí (directo, resuelto y por WhatsApp) nombran lo nuevo.
     *
     * @test
     */
    public function las_listas_cerradas_nombran_las_cargas_nuevas()
    {
        $servicio = new AsistenteIaService();
        $conversation = $this->conversacion_de();

        User::where('id', $this->dueno->id)->update(['agente_confianza' => ConfianzaDelAgenteIaHelper::DIRECTO]);

        $directo = $servicio->build_system_prompt($conversation, $this->dueno->fresh(), true, false);

        $this->assertStringContainsString('DOCE cosas siguen dejando tarjeta SIEMPRE', $directo);
        $this->assertStringContainsString('una compra sin artículos (proponer_compra_sin_articulos)', $directo);
        $this->assertStringContainsString('edición de márgenes por lista que le saca a un artículo su precio fijo o le baja el precio', $directo);
        $this->assertStringContainsString('cambio de proveedor de una compra que da de alta un proveedor nuevo', $directo);
        $this->assertStringContainsString('En esas doce decí que queda', $directo);
        // Y lo que sí se hace en el acto en "directo" (los tipos de AUTO_CONFIRMABLES_DIRECTO).
        $this->assertStringContainsString('proveedor que ya existe (proponer_cambio_de_proveedor_de_compra)', $directo);
        $this->assertStringContainsString('volver a escanear una', $directo);
        // La regla de las genéricas en "directo": la edición de márgenes con aviso también espera.
        $this->assertStringContainsString('espera su confirmación (la respuesta te lo dice con requiere_confirmacion): contale el aviso', $directo);

        $por_whatsapp = $servicio->build_system_prompt($conversation, $this->dueno->fresh(), true, true);

        $this->assertStringContainsString('Lo que SIEMPRE espera el sí (borrar', $por_whatsapp);
        $this->assertStringContainsString('lo que emite ante ARCA, una compra sin artículos, una edición de márgenes que', $por_whatsapp);
        $this->assertStringContainsString('proveedor nuevo, y mandarle algo a un cliente: un comprobante', $por_whatsapp);

        User::where('id', $this->dueno->id)->update(['agente_confianza' => ConfianzaDelAgenteIaHelper::RESUELTO]);

        $resuelto = $servicio->build_system_prompt($conversation, $this->dueno->fresh(), true, false);

        $this->assertStringContainsString('y la compra sin artículos (proponer_compra_sin_articulos) SIEMPRE', $resuelto);
        $this->assertStringContainsString('escanear una factura cuyo escaneo falló', $resuelto);

        // Lo que el prompt dice tiene que ser lo que hace el código: la compra sin artículos nunca va sola.
        $this->assertContains(AiMessageAction::TIPO_COMPRA_SIN_ARTICULOS, HerramientasDeCarga::NUNCA_AUTO_CONFIRMABLES);
        $this->assertContains(AiMessageAction::TIPO_REINTENTO_ESCANEO, HerramientasDeCarga::AUTO_CONFIRMABLES);
        $this->assertContains(AiMessageAction::TIPO_CAMBIO_PROVEEDOR_COMPRA, HerramientasDeCarga::AUTO_CONFIRMABLES_DIRECTO);
    }
}
