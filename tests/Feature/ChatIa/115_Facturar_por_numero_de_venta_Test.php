<?php

namespace Tests\Feature\ChatIa;

use App\Models\AfipInformation;
use App\Models\AfipTipoComprobante;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\ExtencionEmpresa;
use App\Models\IvaCondition;
use App\Models\Sale;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-mejoras-4-dias (16/11/2026), rama A, P11 — facturar por el N° de venta (V18).
 *
 * 🔴 EL CASO REAL (Truvari, 13/11/2026, conversación 27). «Por ejemplo, la venta treinta y uno cero dos» →
 * «Si facturala» → «Necesito el id interno de la venta… me diste el número, no el id». La persona ve el
 * N° (`sales.num`). Ahora `cuerpo.numero` resuelve la venta por su N°; `sale_id` sigue siendo el id. La
 * emisión no cambia: la tarjeta queda propuesta (facturar siempre confirma) y no se toca ARCA.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 *
 * @group chat-ia
 */
class Facturar_por_numero_de_venta_Test extends EmpresaTestCase
{
    /** @var User */
    protected $dueno;

    /** @var AsistenteIaService */
    protected $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $extencion = ExtencionEmpresa::where('slug', 'agentes_ia')->first() ?: ExtencionEmpresa::forceCreate(['slug' => 'agentes_ia', 'name' => 'agentes_ia']);
        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);
        $this->dueno->load('extencions');

        $this->service = new AsistenteIaService();
    }

    protected function conversacion()
    {
        $conversation = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => 'Facturá la venta', 'estado' => 'listo']);

        $assistant = AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'estado' => 'pendiente', 'acciones_habilitadas' => true]);

        return [$conversation, $assistant];
    }

    protected function herramienta($conversation, $assistant, $herramienta, array $input)
    {
        $resultados = $this->service->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_' . uniqid(),
            'name'  => $herramienta,
            'input' => $input,
        ]], $conversation, $assistant);

        $this->assertArrayNotHasKey('is_error', $resultados[0], 'La herramienta devolvió una falla técnica: ' . $resultados[0]['content']);

        return json_decode($resultados[0]['content'], true);
    }

    /**
     * Una venta del dueño cuyo N° no coincide con ningún id de venta suyo (para que la prueba no pase por casualidad).
     *
     * @return Sale
     */
    protected function venta()
    {
        $num = max((int) Sale::where('user_id', $this->dueno->id)->max('num'), (int) Sale::max('id')) + 1000;

        return Sale::create(['num' => $num, 'user_id' => $this->dueno->id, 'moneda_id' => 1, 'total' => 1530, 'terminada' => 1]);
    }

    protected function emisor_y_tipo()
    {
        $emisor = AfipInformation::create([
            'user_id' => $this->dueno->id, 'cuit' => '20111111112', 'punto_venta' => 7,
            'razon_social' => 'Emisor de prueba c115',
            'iva_condition_id' => IvaCondition::where('name', 'Monotributista')->firstOrFail()->id,
        ]);

        return [$emisor, AfipTipoComprobante::where('codigo', 11)->firstOrFail()];
    }

    /**
     * 🔴 El caso real: el N° de la venta alcanza. La tarjeta queda propuesta con el sale_id resuelto, sin
     * `numero` en el cuerpo, y dice el N°; no se emite nada.
     *
     * @test
     */
    public function facturar_por_el_numero_de_venta_resuelve_el_id_y_deja_la_tarjeta()
    {
        $venta = $this->venta();
        list($emisor, $tipo) = $this->emisor_y_tipo();

        list($conversation, $assistant) = $this->conversacion();

        $tickets_antes = DB::table('afip_tickets')->count();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
            'metodo'      => 'POST',
            'ruta'        => 'api/afip-ticket',
            'cuerpo'      => ['numero' => $venta->num, 'ventas_afip_information_id' => $emisor->id, 'afip_tipo_comprobante_id' => $tipo->id],
            'descripcion' => 'Facturar la venta',
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame((int) $venta->id, (int) $tarjeta->datos['cuerpo']['sale_id']);
        $this->assertArrayNotHasKey('numero', $tarjeta->datos['cuerpo']);
        $this->assertStringContainsString('N° ' . $venta->num, json_encode($tarjeta->presentacion, JSON_UNESCAPED_UNICODE));
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->estado_guardado());
        $this->assertSame($tickets_antes, DB::table('afip_tickets')->count(), 'No se emitió nada.');
    }

    /**
     * Un N° que no existe corta con error y sin tarjeta; sin N° ni id, se pide el N°.
     *
     * @test
     */
    public function un_numero_que_no_existe_o_que_falta_no_deja_tarjeta()
    {
        list($emisor, $tipo) = $this->emisor_y_tipo();
        list($conversation, $assistant) = $this->conversacion();

        $inexistente = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
            'metodo' => 'POST', 'ruta' => 'api/afip-ticket', 'descripcion' => 'Facturar',
            'cuerpo' => ['numero' => 987654321, 'ventas_afip_information_id' => $emisor->id, 'afip_tipo_comprobante_id' => $tipo->id],
        ]);

        $this->assertFalse($inexistente['ok']);
        $this->assertStringContainsString('No encontré la venta N° 987654321', (string) $inexistente['error']);

        $sin_nada = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
            'metodo' => 'POST', 'ruta' => 'api/afip-ticket', 'descripcion' => 'Facturar',
            'cuerpo' => ['ventas_afip_information_id' => $emisor->id, 'afip_tipo_comprobante_id' => $tipo->id],
        ]);

        $this->assertFalse($sin_nada['ok']);
        $this->assertStringContainsString('numero', implode(' ', $sin_nada['faltan']));
        $this->assertSame(0, AiMessageAction::where('ai_message_id', $assistant->id)->count());
    }

    /**
     * Con `sale_id` todo sigue como antes (el id manda).
     *
     * @test
     */
    public function con_sale_id_sigue_siendo_el_id()
    {
        $venta = $this->venta();
        list($emisor, $tipo) = $this->emisor_y_tipo();
        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
            'metodo' => 'POST', 'ruta' => 'api/afip-ticket', 'descripcion' => 'Facturar',
            'cuerpo' => ['sale_id' => $venta->id, 'ventas_afip_information_id' => $emisor->id, 'afip_tipo_comprobante_id' => $tipo->id],
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertSame((int) $venta->id, (int) AiMessageAction::find($respuesta['tarjeta_id'])->datos['cuerpo']['sale_id']);
    }
}
