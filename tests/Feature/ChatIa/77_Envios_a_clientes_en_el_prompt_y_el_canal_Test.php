<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\AdminSync\AsistenteController;
use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\Mcp\McpHerramientasHelper;
use App\Services\AsistenteIa\Mcp\McpServidor;
use App\Models\User;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-envios-a-clientes (16/10/2026) — lo que el modelo lee sobre los envíos.
 *
 * Lo que protege este archivo:
 *  - el prompt ya no dice "lo que queda afuera de verdad: mandar mensajes a terceros" (la frase vieja
 *    que hacía que el asistente le pasara el link al dueño en vez de mandarlo), y sí dice que a los
 *    clientes se les manda SOLO con las tres herramientas, siempre con el sí;
 *  - en "directo" las tres están entre lo que SIEMPRE espera el sí, y por WhatsApp también;
 *  - el bloque de módulos apagados nombra WhatsApp y los mails a clientes;
 *  - MCP: las tres se nombran entre lo que siempre confirma y llevan la pista de "sale del negocio";
 *  - el texto fijo del audio sin transcribir ofrece la nota de voz.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Envios_a_clientes_en_el_prompt_y_el_canal_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio(['agente_confianza' => ConfianzaDelAgenteIaHelper::DIRECTO]);
    }

    protected function prompt($modo, $canal = AiMessage::CANAL_WHATSAPP)
    {
        User::where('id', $this->dueno->id)->update(['agente_confianza' => $modo]);

        list($conversation) = $this->vuelta('hola', null, $canal);

        return (new AsistenteIaService())->build_system_prompt($conversation, $this->dueno->fresh(), true, !is_null($canal));
    }

    /**
     * 🔴 La frase vieja ya no está, y la nueva regla nombra las tres herramientas y el sí.
     *
     * @test
     */
    public function el_prompt_ya_no_prohibe_mandar_y_dice_como_se_manda()
    {
        foreach (ConfianzaDelAgenteIaHelper::MODOS as $modo) {

            $prompt = $this->prompt($modo);

            $this->assertStringNotContainsString('mandar mensajes a terceros', $prompt, $modo);
            $this->assertStringContainsString('Lo que queda afuera', $prompt, $modo);
            $this->assertStringContainsString('A los CLIENTES del negocio les mandás algo SOLO con las tres', $prompt, $modo);
            $this->assertStringContainsString('proponer_envio_de_comprobante', $prompt, $modo);
            $this->assertStringContainsString('proponer_recordatorios_de_cobro con monto_minimo = X y dias = N', $prompt, $modo);
            $this->assertStringContainsString('primero proponer_combo', $prompt, $modo);
            $this->assertStringContainsString('NO le pases el link a la', $prompt, $modo);
            // Corrección 6: sin Combos, la promo de los artículos; nunca un combo que no existe.
            $this->assertStringContainsString('Si el negocio NO tiene el módulo de Combos', $prompt, $modo);
            $this->assertStringContainsString('nunca mandes una promo que nombre un combo que no existe', $prompt, $modo);
            // Corrección 8: la oferta no se manda, pero una promoción por WhatsApp sí.
            $this->assertStringContainsString('Una PROMOCIÓN por WhatsApp a los', $prompt, $modo);
            // Corrección 1: el bloque lo agrega el sistema, el modelo no lo repite.
            $this->assertStringContainsString('los agrega el sistema solo', $prompt, $modo);
        }

        $directo = $this->prompt(ConfianzaDelAgenteIaHelper::DIRECTO);

        // El número cuenta las que siguen dejando tarjeta: nueve con los tres envíos de esta misión, doce desde
        // agente-mejoras-uso-real (29/10/2026: compra sin artículos, márgenes que pierden el precio fijo o lo
        // bajan, cambio de proveedor con proveedor nuevo). Lo que este test cuida es que los envíos estén.
        $this->assertStringContainsString('DOCE cosas siguen dejando tarjeta SIEMPRE', $directo);
        $this->assertStringContainsString('los tres ENVÍOS A CLIENTES', $directo);
        // Por WhatsApp, la lista de lo que siempre espera el sí también los nombra.
        $this->assertStringContainsString('mandarle algo a un cliente: un comprobante', $directo);

        $resuelto = $this->prompt(ConfianzaDelAgenteIaHelper::RESUELTO);

        $this->assertStringContainsString('los tres envíos a clientes (comprobante, recordatorios de cobro', $resuelto);
    }

    /**
     * Sin WhatsApp ni mails a clientes, el modelo lo sabe desde el primer mensaje.
     *
     * @test
     */
    public function los_modulos_apagados_nombran_whatsapp_y_los_mails()
    {
        $this->sacar_extension('agentes_ia');
        $this->sacar_extension('enviar_mail_a_clientes');

        $prompt = $this->prompt(ConfianzaDelAgenteIaHelper::DIRECTO);

        $this->assertStringContainsString('WhatsApp (no podés mandar recordatorios de cobro, promociones ni comprobantes por WhatsApp)', $prompt);
        $this->assertStringContainsString('Mails a clientes (no podés mandar comprobantes por mail)', $prompt);

        $this->dar_extension('agentes_ia');
        $this->dar_extension('enviar_mail_a_clientes');

        $con = $this->prompt(ConfianzaDelAgenteIaHelper::DIRECTO);

        $this->assertStringNotContainsString('WhatsApp (no podés mandar', $con);
        $this->assertStringNotContainsString('Mails a clientes (no podés', $con);
    }

    /**
     * MCP: los tres envíos NO se exponen (un agente externo no le escribe a los clientes) y siguen
     * nombrados entre lo que siempre confirma (la lista se deriva de NUNCA_AUTO_CONFIRMABLES).
     *
     * @test
     */
    public function mcp_no_expone_los_envios()
    {
        foreach ([
            AiMessageAction::TIPO_ENVIO_COMPROBANTE,
            AiMessageAction::TIPO_RECORDATORIOS_COBRO,
            AiMessageAction::TIPO_PROMOCION_WHATSAPP,
        ] as $tipo) {
            $this->assertArrayHasKey($tipo, McpServidor::NOMBRES_DE_LO_QUE_SIEMPRE_CONFIRMA);
        }

        foreach (['proponer_envio_de_comprobante', 'proponer_recordatorios_de_cobro', 'proponer_promocion_por_whatsapp'] as $nombre) {
            $this->assertFalse(McpHerramientasHelper::existe($nombre), $nombre . ' no tiene que estar en el MCP');
            $this->assertContains($nombre, McpHerramientasHelper::EXCLUIDAS);
        }
    }

    /**
     * El audio que no se pudo transcribir (S19, un audio reenviado como archivo): la respuesta fija
     * ofrece escribirlo o mandar una nota de voz.
     *
     * @test
     */
    public function el_audio_sin_transcribir_ofrece_la_nota_de_voz()
    {
        $this->assertSame(
            'No me llegó lo que dijiste en el audio. ¿Me lo escribís o me mandás una nota de voz?',
            AsistenteController::RESPUESTA_AUDIO_SIN_TRANSCRIPCION
        );
    }
}
