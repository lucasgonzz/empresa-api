<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionPorTextoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaCompraConFacturaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaCompraSinArticulosIaHelper;
use App\Jobs\RunProviderOrderScanJob;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\Article;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Iva;
use App\Models\Provider;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Models\ProviderOrderAfipTicketIva;
use App\Models\ProviderOrderScan;
use App\Models\ProviderOrderScanImage;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use App\Services\AsistenteIa\Mcp\McpHerramientasHelper;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
/**
 * La base de los tests de las compras del agente de la misión agente-mejoras-uso-real (E1, puntos 6 a 8,
 * 29/10/2026): el proveedor FERRETOTAL con sus cuentas corrientes (como lo deja la pantalla) y las
 * escenas de Ferre Total del mediodía del 29/10 — la compra sin artículos de la CONV 19, la compra con
 * factura del agente y el escaneo que falló en la CONV 20.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
abstract class ComprasDelAgenteTestCase extends AsistenteWhatsappTestCase
{
    /** @var \App\Models\Provider */
    protected $ferretotal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dar_extension(self::SLUG_ASISTENTE);

        Queue::fake();
        Storage::fake('local');

        $this->ferretotal = $this->crear_proveedor('FERRETOTAL');

        /*
         * "cauteloso" para que cada test diga el modo que prueba: el default de la columna es
         * "directo" (migración cambiar_defaults_agente_ia_deepseek_directo) y ahí algunas de estas
         * cargas se ejecutan en el acto.
         */
        User::where('id', $this->comercio->id)->update(['agente_confianza' => 'cauteloso']);
    }

    // --------------------------------------------------------------------------- fixtures

    /**
     * Un proveedor con sus cuentas corrientes, como lo deja la pantalla.
     *
     * @param  string  $nombre
     * @return Provider
     */
    protected function crear_proveedor($nombre)
    {
        $proveedor = Provider::create(['user_id' => $this->comercio->id, 'name' => $nombre]);

        CreditAccountHelper::crear_credit_accounts('provider', $proveedor->id, $this->comercio->id);

        return $proveedor;
    }

    /**
     * La conversación con el pedido del dueño y el assistant que propone.
     *
     * @param  string  $pedido
     * @return array{0: \App\Models\AiConversation, 1: AiMessage}
     */
    protected function escena($pedido)
    {
        $conversation = $this->conversacion_whatsapp();

        $this->mensaje($conversation, 'user', 'listo', ['contenido' => $pedido]);

        return [$conversation, $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true])];
    }

    /**
     * Cierra el turno que propuso, el dueño dice «sí» y se confirma por texto (el camino de WhatsApp).
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  AiMessage  $propuso
     * @param  int  $tarjeta_id
     * @return array
     */
    protected function confirmar($conversation, AiMessage $propuso, $tarjeta_id)
    {
        $propuso->estado = 'listo';
        $propuso->contenido = 'Te dejo la carga, ¿la hago?';
        $propuso->save();

        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Sí']);

        $contestando = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        return ConfirmacionPorTextoIaHelper::confirmar($conversation, $contestando, $tarjeta_id);
    }

    /**
     * El assistant del turno siguiente, con lo que dijo el dueño.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  string  $texto
     * @return AiMessage
     */
    protected function siguiente($conversation, $texto)
    {
        AiMessage::where('ai_conversation_id', $conversation->id)->where('estado', 'pendiente')->update(['estado' => 'listo', 'contenido' => 'Listo.']);

        $this->mensaje($conversation, 'user', 'listo', ['contenido' => $texto]);

        return $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);
    }

    /**
     * La compra sin artículos de Ferre Total, propuesta y confirmada.
     *
     * @param  array  $input
     * @return array{0: \App\Models\AiConversation, 1: ProviderOrder}
     */
    protected function compra_sin_articulos_confirmada(array $input = [])
    {
        list($conversation, $assistant) = $this->escena('Me cargás una compra sin artículos con una factura sin iva de 198.522 al proveedor ferretotal');

        $respuesta = PropuestaCompraSinArticulosIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, array_merge([
            'proveedor' => 'ferretotal',
            'total'     => 198522,
            'iva'       => 'sin_iva',
        ], $input));

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $resultado = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $this->assertTrue($resultado['ok'], json_encode($resultado));

        return [$conversation, ProviderOrder::find(AiMessageAction::find($respuesta['tarjeta_id'])->resultado->provider_order_id)];
    }

    /**
     * El movimiento de cuenta corriente de la compra, o null.
     *
     * @param  ProviderOrder  $compra
     * @return CurrentAcount|null
     */
    protected function movimiento_de(ProviderOrder $compra)
    {
        return CurrentAcount::where('provider_order_id', $compra->id)->first();
    }

    /**
     * La cuenta corriente en pesos de un proveedor.
     *
     * @param  Provider  $proveedor
     * @return CreditAccount
     */
    protected function cuenta_de(Provider $proveedor)
    {
        return CreditAccount::where('model_name', 'provider')->where('model_id', $proveedor->id)->where('moneda_id', 1)->first();
    }

    /**
     * Una foto de factura colgada de un mensaje del dueño.
     *
     * @param  AiMessage  $mensaje
     * @return AiMessageImagen
     */
    protected function guardar_foto(AiMessage $mensaje)
    {
        $recurso = imagecreatetruecolor(40, 40);
        ob_start();
        imagepng($recurso);
        $binario = ob_get_clean();

        $path = 'asistente_imagenes/' . $this->comercio->id . '/' . $mensaje->id . '/1.webp';
        Storage::disk('local')->put($path, $binario);

        return AiMessageImagen::create([
            'ai_message_id' => $mensaje->id,
            'user_id'       => $this->comercio->id,
            'orden'         => 1,
            'path'          => $path,
            'mime'          => 'image/webp',
            'bytes'         => strlen($binario),
        ]);
    }

    /**
     * Una compra con factura del agente (CONV 20): foto, tarjeta y confirmación por el camino real.
     *
     * @param  Provider  $proveedor
     * @return array{0: \App\Models\AiConversation, 1: ProviderOrder}
     */
    protected function compra_con_factura_del_agente(Provider $proveedor)
    {
        $conversation = $this->conversacion_whatsapp();
        $del_dueno = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Proveedor ' . $proveedor->name . '. Cargar compra']);
        $this->guardar_foto($del_dueno);
        $assistant = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $respuesta = PropuestaCompraConFacturaIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, ['proveedor' => $proveedor->name]);
        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $resultado = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);
        $this->assertTrue($resultado['ok'], json_encode($resultado));

        return [$conversation, ProviderOrder::find(AiMessageAction::find($respuesta['tarjeta_id'])->resultado->provider_order_id)];
    }

    /**
     * Deja el escaneo de la compra como lo dejó la IA en la CONV 20: en error, sin resultado, con su
     * página guardada en el disco privado.
     *
     * @param  ProviderOrder  $compra
     * @return ProviderOrderScan
     */
    protected function escaneo_fallido(ProviderOrder $compra)
    {
        $scan = ProviderOrderScan::where('provider_order_id', $compra->id)->orderBy('id', 'DESC')->first();

        $scan->estado = 'error';
        $scan->resultado = null;
        $scan->error = 'La IA no devolvió contenido de texto';
        $scan->save();

        return $scan;
    }

}
