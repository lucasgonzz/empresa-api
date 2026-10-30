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
 * Misión agente-mejoras-uso-real (E1, punto 8, 29/10/2026) — EL REENVÍO DESPUÉS DE UN ESCANEO FALLIDO.
 *
 * 🔴 EL CASO REAL (Ferre Total, CONV 20): el escaneo de la Compra N° 1358 falló («La IA no devolvió
 * contenido de texto»), Pablo reenvió la foto y el agente creó la 1361. Ahora la compra con factura
 * reusa la 1358 («cuyo escaneo anterior falló»), y además se puede pedir «volvé a escanearla» sin
 * mandar la foto: las páginas del escaneo fallido siguen guardadas.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
class Reenvio_tras_escaneo_fallido_Test extends ComprasDelAgenteTestCase
{
    // ----------------------------------------------------------- 8 · reenvío tras un escaneo fallido

    /**
     * 🔴 CONV 20: el escaneo de la compra falló; el dueño reenvía la foto → la factura va a ESA compra
     * («cuyo escaneo anterior falló») y no a una nueva.
     *
     * @test
     */
    public function reenviar_la_factura_despues_de_un_escaneo_fallido_reusa_la_compra()
    {
        $cavallfer = $this->crear_proveedor('EDUARDO JORGE CAVALLO (CAVALLFER)');

        list(, $compra) = $this->compra_con_factura_del_agente($cavallfer);
        $this->escaneo_fallido($compra);

        $conversation = $this->conversacion_whatsapp();
        $del_dueno = $this->mensaje($conversation, 'user', 'listo', ['contenido' => '']);
        $this->guardar_foto($del_dueno);
        $assistant = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $respuesta = PropuestaCompraConFacturaIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, ['proveedor' => 'cavallfer']);
        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertStringContainsString('Se usa la compra N° ' . $compra->num . ', cuyo escaneo anterior falló', json_encode(AiMessageAction::find($respuesta['tarjeta_id'])->presentacion, JSON_UNESCAPED_UNICODE));

        $resultado = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);
        $this->assertTrue($resultado['ok'], json_encode($resultado));

        $this->assertSame(1, ProviderOrder::where('user_id', $this->comercio->id)->where('provider_id', $cavallfer->id)->count(), '🔴 No se creó la 1361.');
        $this->assertSame(2, ProviderOrderScan::where('provider_order_id', $compra->id)->count());
        $this->assertStringContainsString('Compra N° ' . $compra->num, $resultado['resultado']);
        $this->assertStringContainsString('falló', $resultado['resultado']);
        $this->assertStringNotContainsString('otra factura', $resultado['resultado'], 'Un escaneo que falló no leyó ninguna factura.');
    }

    /**
     * «Volvé a escanearla»: con las páginas del escaneo fallido guardadas, se reescanea sin pedir la
     * foto. En "resuelto" se hace en el acto, como la compra con factura.
     *
     * @test
     */
    public function volver_a_escanear_usa_las_fotos_guardadas_sin_pedirlas()
    {
        list($conversation, $compra) = $this->compra_con_factura_del_agente($this->ferretotal);
        $fallido = $this->escaneo_fallido($compra);
        $this->assertSame(1, ProviderOrderScanImage::where('provider_order_scan_id', $fallido->id)->count());

        User::where('id', $this->comercio->id)->update(['agente_confianza' => 'resuelto']);

        $assistant = $this->siguiente($conversation, 'Volvé a escanearla');

        $resultado = HerramientasDeCarga::ejecutar('proponer_reintento_de_escaneo', [], $conversation, $assistant);
        $datos = json_decode($resultado['content'], true);

        $this->assertTrue($datos['ok'], $resultado['content']);
        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, AiMessageAction::find($datos['tarjeta_id'])->estado_guardado(), 'En "resuelto" se hace en el acto.');

        $nuevo = ProviderOrderScan::where('provider_order_id', $compra->id)->orderBy('id', 'DESC')->first();
        $this->assertNotSame((int) $fallido->id, (int) $nuevo->id);
        $this->assertSame('pendiente', $nuevo->estado);
        $this->assertSame(1, ProviderOrderScanImage::where('provider_order_scan_id', $nuevo->id)->count());
        Queue::assertPushed(RunProviderOrderScanJob::class);
        $this->assertSame(1, ProviderOrder::where('user_id', $this->comercio->id)->count());
    }

    /**
     * Si el último escaneo terminó bien, no hay nada que reintentar.
     *
     * @test
     */
    public function no_se_reescanea_una_factura_que_se_leyo_bien()
    {
        list($conversation, $compra) = $this->compra_con_factura_del_agente($this->ferretotal);
        ProviderOrderScan::where('provider_order_id', $compra->id)->update(['estado' => 'listo', 'resultado' => json_encode(['version' => 1, 'articulos' => []])]);

        $assistant = $this->siguiente($conversation, 'Volvé a escanear la compra ' . $compra->num);

        $resultado = HerramientasDeCarga::ejecutar('proponer_reintento_de_escaneo', ['compra_num' => $compra->num], $conversation, $assistant);
        $datos = json_decode($resultado['content'], true);

        $this->assertFalse($datos['ok']);
        $this->assertStringContainsString('ya terminó bien', (string) $datos['error']);
    }

    // ------------------------------------------------------------------------- el registro

    /**
     * Las tres herramientas nuevas van AL FINAL de la lista común (regla del prefijo del caché), las
     * declara también el MCP, y cada una está en la lista de confianza que le toca.
     *
     * @test
     */
    public function las_herramientas_nuevas_van_al_final_y_en_sus_listas()
    {
        $nombres = HerramientasDeCarga::nombres();

        $this->assertSame(
            ['proponer_compra_sin_articulos', 'proponer_cambio_de_proveedor_de_compra', 'proponer_reintento_de_escaneo'],
            array_slice($nombres, -3)
        );

        foreach (['proponer_compra_sin_articulos', 'proponer_cambio_de_proveedor_de_compra', 'proponer_reintento_de_escaneo'] as $nombre) {
            $this->assertContains($nombre, McpHerramientasHelper::nombres(), $nombre . ' no está en el MCP');
        }

        $this->assertContains(AiMessageAction::TIPO_REINTENTO_ESCANEO, HerramientasDeCarga::AUTO_CONFIRMABLES);
        $this->assertContains(AiMessageAction::TIPO_REINTENTO_ESCANEO, HerramientasDeCarga::AUTO_CONFIRMABLES_DIRECTO);
        $this->assertContains(AiMessageAction::TIPO_CAMBIO_PROVEEDOR_COMPRA, HerramientasDeCarga::AUTO_CONFIRMABLES_DIRECTO);
        $this->assertNotContains(AiMessageAction::TIPO_CAMBIO_PROVEEDOR_COMPRA, HerramientasDeCarga::AUTO_CONFIRMABLES);
        $this->assertNotContains(AiMessageAction::TIPO_COMPRA_SIN_ARTICULOS, HerramientasDeCarga::auto_confirmables_de('directo'));
    }
}
