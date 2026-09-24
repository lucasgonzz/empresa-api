<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionPorTextoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaCompraConFacturaIaHelper;
use App\Models\Address;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\CreditAccount;
use App\Models\Provider;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderScan;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Misión asistente-fotos-barras-y-compras (24/9/2026) — A6: la compra con factura como la pidió
 * Lucas ese día, después de ver en demo3 (conv 12) una compra que tardó tres mensajes en armarse
 * (preguntó si el proveedor era el emisor, preguntó la sucursal entre seis y recién ahí la armó).
 *
 * Las tres decisiones de la Fase 2 que se fijan acá:
 *   1. En "resuelto" se ejecuta directo, sin confirmar, INCLUIDA el alta del proveedor si no existe.
 *      En "cauteloso", UNA sola tarjeta que cubre todo.
 *   2. La sucursal no dicha no se pregunta: la nombrada → la de quien escribe → la única → ninguna.
 *   3. El proveedor se busca también por razón social (lo que el modelo lee en la factura).
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class Compra_con_proveedor_nuevo_Test extends AsistenteWhatsappTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dar_extension(self::SLUG_ASISTENTE);
        $this->dar_extension(self::SLUG_ESCANEO);

        Queue::fake();
        Storage::fake('local');
    }

    /**
     * @param  string  $nombre
     * @param  array  $extra
     * @return \App\Models\Provider
     */
    protected function crear_proveedor($nombre, array $extra = [])
    {
        $proveedor = Provider::create(array_merge([
            'user_id' => $this->comercio->id,
            'name'    => $nombre,
        ], $extra));

        CreditAccountHelper::crear_credit_accounts('provider', $proveedor->id, $this->comercio->id);

        return $proveedor;
    }

    /**
     * @param  string  $modo
     * @return void
     */
    protected function confianza($modo)
    {
        User::where('id', $this->comercio->id)->update(['agente_confianza' => $modo]);
    }

    /**
     * La foto de la factura, el "¿de quién es?", la respuesta y el assistant que propone.
     *
     * @param  string  $proveedor_dicho
     * @return array{0: \App\Models\AiConversation, 1: \App\Models\AiMessage}
     */
    protected function escena($proveedor_dicho = 'De Mayorista Nuevo')
    {
        $conversation = $this->conversacion_whatsapp();

        $del_dueno = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Cargame esta factura']);

        $recurso = imagecreatetruecolor(40, 40);
        ob_start();
        imagepng($recurso);
        $binario = ob_get_clean();

        $path = 'asistente_imagenes/' . $this->comercio->id . '/' . $del_dueno->id . '/1.webp';
        Storage::disk('local')->put($path, $binario);

        AiMessageImagen::create([
            'ai_message_id' => $del_dueno->id,
            'user_id'       => $this->comercio->id,
            'orden'         => 1,
            'path'          => $path,
            'mime'          => 'image/webp',
            'bytes'         => strlen($binario),
        ]);

        $this->mensaje($conversation, 'assistant', 'listo', ['contenido' => '¿De qué proveedor es?']);
        $this->mensaje($conversation, 'user', 'listo', ['contenido' => $proveedor_dicho]);

        $generandose = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        return [$conversation, $generandose];
    }

    /**
     * La herramienta por el mismo camino que el loop: HerramientasDeCarga::ejecutar(), que es donde
     * vive la puerta de auto-confirmación.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $mensaje
     * @param  array  $input
     * @return array
     */
    protected function herramienta($conversation, $mensaje, array $input)
    {
        $resultado = HerramientasDeCarga::ejecutar('proponer_compra_con_factura', $input, $conversation, $mensaje);

        $this->assertFalse($resultado['is_error'], $resultado['content']);

        return json_decode($resultado['content'], true);
    }

    /**
     * Cierra el turno que propuso y devuelve el assistant que contesta el sí (ver el mismo helper en
     * 6_Compra_con_factura_Test).
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $propuso
     * @return \App\Models\AiMessage
     */
    protected function cerrar_turno($conversation, AiMessage $propuso)
    {
        $propuso->estado = 'listo';
        $propuso->contenido = 'Doy de alta Mayorista Nuevo y le cargo la compra con esa factura, ¿la registro?';
        $propuso->save();

        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Sí']);

        return $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);
    }

    /**
     * 🔴 Decisión 1: el proveedor que no existe se crea POR LA PANTALLA (con su correlativo y sus
     * cuentas corrientes) y la compra se crea en la MISMA ejecución, con el escaneo andando.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_proveedor_inexistente_se_crea_y_la_compra_en_la_misma_ejecucion()
    {
        $this->confianza('cauteloso');

        list($conversation, $generandose) = $this->escena();

        $respuesta = PropuestaCompraConFacturaIaHelper::proponer(
            ContextoDeCargaIa::de_la_conversacion($conversation),
            $generandose,
            ['proveedor' => 'Mayorista Nuevo']
        );

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $contestando = $this->cerrar_turno($conversation, $generandose);

        $resultado = ConfirmacionPorTextoIaHelper::confirmar($conversation, $contestando, $respuesta['tarjeta_id']);

        $this->assertTrue($resultado['ok'], 'Motivo: ' . json_encode($resultado));

        $proveedor = Provider::where('user_id', $this->comercio->id)->where('name', 'Mayorista Nuevo')->first();

        $this->assertNotNull($proveedor, 'El proveedor se tiene que haber dado de alta.');
        $this->assertNotNull($proveedor->num, 'Por la pantalla: el correlativo lo pone ProviderController::store.');
        $this->assertSame(
            2,
            CreditAccount::where('model_name', 'provider')->where('model_id', $proveedor->id)->count(),
            'Por la pantalla: nace con sus dos cuentas corrientes, como cualquier proveedor.'
        );

        $compra = ProviderOrder::where('user_id', $this->comercio->id)->where('provider_id', $proveedor->id)->first();

        $this->assertNotNull($compra, 'La compra se crea en la misma ejecución que el proveedor.');
        $this->assertSame(1, ProviderOrderScan::where('provider_order_id', $compra->id)->count());

        $this->assertStringContainsString('Compra N° ' . $compra->num, $resultado['resultado']);
        $this->assertStringContainsString('proveedor nuevo, lo di de alta', $resultado['resultado']);
        $this->assertStringContainsString('segundo plano', $resultado['resultado']);
        $this->assertStringContainsString('Compras', $resultado['resultado']);
    }

    /**
     * Si alguien dio de alta el proveedor entre la propuesta y el sí, se usa ése: no se duplica.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function si_el_proveedor_aparecio_despues_de_proponer_no_se_duplica()
    {
        $this->confianza('cauteloso');

        list($conversation, $generandose) = $this->escena();

        $respuesta = PropuestaCompraConFacturaIaHelper::proponer(
            ContextoDeCargaIa::de_la_conversacion($conversation),
            $generandose,
            ['proveedor' => 'Mayorista Nuevo']
        );

        $cargado_a_mano = $this->crear_proveedor('Mayorista Nuevo');

        $contestando = $this->cerrar_turno($conversation, $generandose);

        $resultado = ConfirmacionPorTextoIaHelper::confirmar($conversation, $contestando, $respuesta['tarjeta_id']);

        $this->assertTrue($resultado['ok'], 'Motivo: ' . json_encode($resultado));

        $this->assertSame(1, Provider::where('user_id', $this->comercio->id)->where('name', 'Mayorista Nuevo')->count());
        $this->assertSame(1, ProviderOrder::where('provider_id', $cargado_a_mano->id)->count());
        $this->assertStringNotContainsString('lo di de alta', $resultado['resultado']);
    }

    /**
     * 🔴 Decisión 1, en "resuelto": se ejecuta en el acto, proveedor nuevo incluido. Sin tarjeta.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function en_resuelto_se_ejecuta_en_el_acto_con_el_proveedor_nuevo()
    {
        $this->confianza('resuelto');

        list($conversation, $generandose) = $this->escena();

        $respuesta = $this->herramienta($conversation, $generandose, ['proveedor' => 'Mayorista Nuevo']);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, $respuesta['estado'], 'En "resuelto" la compra no deja tarjeta.');

        $proveedor = Provider::where('user_id', $this->comercio->id)->where('name', 'Mayorista Nuevo')->first();

        $this->assertNotNull($proveedor);
        $this->assertSame(1, ProviderOrder::where('provider_id', $proveedor->id)->count());
        $this->assertStringContainsString('lo di de alta', $respuesta['resultado']);
    }

    /**
     * En "cauteloso" queda UNA tarjeta, y todavía no se creó nada.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function en_cauteloso_queda_una_sola_tarjeta()
    {
        $this->confianza('cauteloso');

        list($conversation, $generandose) = $this->escena();

        $respuesta = $this->herramienta($conversation, $generandose, ['proveedor' => 'Mayorista Nuevo']);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertArrayNotHasKey('estado', $respuesta);

        $tarjetas = AiMessageAction::where('ai_conversation_id', $conversation->id)->get();

        $this->assertCount(1, $tarjetas, 'Una sola confirmación cubre el alta del proveedor y la compra.');
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjetas[0]->estado_guardado());

        $this->assertSame(0, Provider::where('user_id', $this->comercio->id)->where('name', 'Mayorista Nuevo')->count());
        $this->assertSame(0, ProviderOrder::where('user_id', $this->comercio->id)->count());
    }

    /**
     * 🔴 Decisión 2: con varias sucursales y ninguna dicha no se pregunta: va la de quien escribe.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sin_sucursal_dicha_usa_la_de_quien_escribe_sin_preguntar()
    {
        $this->crear_proveedor('Distribuidora Sur');

        Address::create(['user_id' => $this->comercio->id, 'street' => 'Av. Siempreviva']);
        $la_suya = Address::create(['user_id' => $this->comercio->id, 'street' => 'Belgrano']);
        Address::create(['user_id' => $this->comercio->id, 'street' => 'San Martín']);

        User::where('id', $this->comercio->id)->update(['address_id' => $la_suya->id]);

        list($conversation, $generandose) = $this->escena('De Distribuidora Sur');

        $respuesta = PropuestaCompraConFacturaIaHelper::proponer(
            ContextoDeCargaIa::de_la_conversacion($conversation),
            $generandose,
            ['proveedor' => 'Distribuidora Sur']
        );

        $this->assertTrue($respuesta['ok'], 'La sucursal no se pregunta: ' . json_encode($respuesta));
        $this->assertSame((int) $la_suya->id, (int) AiMessageAction::find($respuesta['tarjeta_id'])->datos['address_id']);
    }

    /**
     * El proveedor se encuentra también por su razón social: es lo que el modelo lee en la factura
     * cuando la persona no dijo de quién es.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_proveedor_se_encuentra_por_su_razon_social()
    {
        $proveedor = $this->crear_proveedor('El Turco', ['razon_social' => 'Distribuidora Anatolia SRL']);

        list($conversation, $generandose) = $this->escena('No sé, fijate en la factura');

        $respuesta = PropuestaCompraConFacturaIaHelper::proponer(
            ContextoDeCargaIa::de_la_conversacion($conversation),
            $generandose,
            ['proveedor' => 'Distribuidora Anatolia SRL']
        );

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertArrayNotHasKey('proveedor_nuevo', $respuesta, 'No es un proveedor nuevo: ya existe con esa razón social.');
        $this->assertSame((int) $proveedor->id, (int) AiMessageAction::find($respuesta['tarjeta_id'])->datos['provider_id']);
    }
}
