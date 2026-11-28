<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\AccionesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\EscaneoDeFacturaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaCompraConFacturaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessageAction;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderScan;
use App\Models\ProviderOrderStatus;
use Illuminate\Support\Str;
use Tests\Feature\AsistenteWhatsapp\ComprasDelAgenteTestCase;

/**
 * Misión asistente-diario-20261128, P5 (CA10, CA11, CA12) — TESTS DE ACEPTACIÓN: una compra BORRADA no figura como
 * cargada y un N° de compra reusado se explica.
 *
 * EL CASO REAL (Pescamayorista, conv. del 27/11): el dueño borraba cada compra que salía mal y la tarjeta seguía
 * diciendo «confirmada (Creé la Compra N° 1469…)»; el modelo la dedujo como «ya está cargada» y se negó a cargarla
 * de nuevo. Además 1487, 1488 y 1489 salieron todas con el N° 1469 porque `Controller::num()` reusa el número de
 * una compra borrada (comportamiento correcto que nadie le explicó al dueño).
 *
 * Qué fija este archivo:
 *   CA10 la línea de historial de una tarjeta `compra_con_factura` confirmada cuya compra ya no existe lo dice;
 *   CA11 `consultar_escaneo_de_factura` no presenta como vigente el escaneo de una compra borrada;
 *   CA12 el resultado de la tarjeta que recibe un N° reusado lo explica (y suma `provider_order_num`).
 *
 * 🔴 Estado esperado en el commit de tests: los tres ROJOS, salvo las guardas explícitas «con la compra viva»
 * (CA10) y «sin compra anterior borrada» (CA12), que ya pasan hoy y protegen que el texto de siempre no cambie.
 *
 * Molde: U211 + ComprasDelAgenteTestCase (la compra se crea por la tarjeta real). Sin red. PHP 7.4.
 *
 * @group chat-ia
 */
class U213_Compra_borrada_no_figura_cargada_Test extends ComprasDelAgenteTestCase
{
    /** El mensaje exacto de un escaneo cuya compra se borró (§2 del plan). */
    const MENSAJE_COMPRA_BORRADA = 'La compra de esta factura se borró: la factura NO está cargada. Si la quieren cargar, se arma de nuevo.';

    /**
     * Crea una compra con factura por la tarjeta real, con una foto nueva en un turno nuevo de la conversación.
     *
     * @param  \App\Models\AiConversation|null  $conversation  La charla (null = una nueva).
     * @return array{0: \App\Models\AiConversation, 1: \App\Models\AiMessageAction, 2: \App\Models\ProviderOrder}
     */
    protected function compra_por_tarjeta($conversation = null)
    {
        if (is_null($conversation)) {
            $conversation = $this->conversacion_whatsapp();
        }

        $del_dueno = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Proveedor ' . $this->ferretotal->name . '. Cargar compra']);
        $this->guardar_foto($del_dueno);
        $assistant = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $respuesta = PropuestaCompraConFacturaIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, ['proveedor' => $this->ferretotal->name]);
        $this->assertTrue($respuesta['ok'], json_encode($respuesta, JSON_UNESCAPED_UNICODE));

        $resultado = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);
        $this->assertTrue($resultado['ok'], json_encode($resultado, JSON_UNESCAPED_UNICODE));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        return [$conversation, $tarjeta, ProviderOrder::find($tarjeta->resultado->provider_order_id)];
    }

    /**
     * Borra la compra como lo hace el borrado de la pantalla (borrado duro: no hay papelera de compras).
     *
     * @param  \App\Models\ProviderOrder  $compra
     * @return void
     */
    protected function borrar_la_compra(ProviderOrder $compra)
    {
        ProviderOrder::where('id', $compra->id)->delete();

        $this->assertNull(ProviderOrder::find($compra->id), 'La compra tenía que quedar borrada.');
    }

    /**
     * Un escaneo «listo» colgado de una compra.
     *
     * @param  int  $compra_id
     * @return \App\Models\ProviderOrderScan
     */
    protected function escaneo_listo($compra_id)
    {
        return ProviderOrderScan::create([
            'uuid'              => Str::uuid()->toString(),
            'user_id'           => $this->comercio->id,
            'auth_user_id'      => $this->comercio->id,
            'provider_order_id' => $compra_id,
            'estado'            => 'listo',
            'progreso'          => 100,
            'resultado'         => ['factura' => ['total' => 10000], 'articulos' => []],
        ]);
    }

    /**
     * Una compra viva (sin pasar por la tarjeta), para los escaneos de CA11.
     *
     * @return \App\Models\ProviderOrder
     */
    protected function compra_viva()
    {
        return ProviderOrder::create([
            'provider_id'              => $this->ferretotal->id,
            'user_id'                  => $this->comercio->id,
            'provider_order_status_id' => ProviderOrderStatus::firstOrCreate(['name' => 'En proceso'])->id,
            // El modelo no genera el N°: se pasa uno explícito (la aserción de CA11 exige que la compra viva tenga número).
            'num'                      => 9001,
        ]);
    }

    /**
     * 🔴 CA10 — La tarjeta confirmada cuya compra se borró lo dice en el historial que ve el modelo; con la compra
     * viva la línea no lo trae (guarda: verde hoy).
     *
     * @test
     */
    public function el_historial_dice_que_la_compra_se_borro()
    {
        list(, $tarjeta, $compra) = $this->compra_por_tarjeta();

        $con_la_compra_viva = AccionesIaHelper::linea_de_historial($tarjeta->fresh());

        $this->assertStringContainsString('confirmada', $con_la_compra_viva);
        $this->assertStringNotContainsString('esa compra ya no existe', $con_la_compra_viva, 'Con la compra viva, la línea es la de siempre.');

        $this->borrar_la_compra($compra);

        $this->assertStringContainsString(
            'esa compra ya no existe (la borraron): la factura NO está cargada',
            AccionesIaHelper::linea_de_historial($tarjeta->fresh())
        );
    }

    /**
     * 🔴 CA11 — Un escaneo «listo» cuya compra se borró no figura cargado: `consultar_escaneo_de_factura` sin
     * número devuelve `compra_borrada` con el mensaje exacto; si hay además un escaneo más viejo con la compra
     * viva, devuelve ese.
     *
     * @test
     */
    public function el_escaneo_de_una_compra_borrada_no_figura_cargado()
    {
        $conversation = $this->conversacion_whatsapp();

        // Sólo el escaneo huérfano.
        $borrada = $this->compra_viva();
        $this->escaneo_listo($borrada->id);
        $this->borrar_la_compra($borrada);

        $datos = EscaneoDeFacturaIaHelper::consultar($this->comercio->id, $conversation);

        $this->assertTrue($datos['ok'], json_encode($datos, JSON_UNESCAPED_UNICODE));
        $this->assertSame('compra_borrada', $datos['estado']);
        $this->assertSame(self::MENSAJE_COMPRA_BORRADA, $datos['mensaje']);
        $this->assertNull($datos['compra_num']);

        // Un escaneo MÁS VIEJO con la compra viva: se prefiere ese al huérfano más nuevo.
        $viva = $this->compra_viva();
        $mas_viejo = $this->escaneo_listo($viva->id);
        $mas_viejo->created_at = now()->subHour();
        $mas_viejo->save();

        $datos = EscaneoDeFacturaIaHelper::consultar($this->comercio->id, $conversation);

        $this->assertTrue($datos['ok'], json_encode($datos, JSON_UNESCAPED_UNICODE));
        $this->assertNotSame('compra_borrada', $datos['estado'], 'Entre un huérfano y uno con compra viva, gana el vivo.');
        $this->assertNotNull($viva->fresh()->num, 'La compra viva tiene número.');
        $this->assertSame((int) $viva->fresh()->num, (int) $datos['compra_num']);
    }

    /**
     * 🔴 CA12 — La compra N° K de la tarjeta 1 se borra y la tarjeta 2 recibe el mismo N° K: su resultado lo
     * explica y suma `provider_order_num`. Sin compra anterior borrada, la frase es la de hoy (guarda: verde hoy).
     *
     * @test
     */
    public function el_numero_reusado_se_explica()
    {
        list($conversation, $tarjeta_1, $compra_1) = $this->compra_por_tarjeta();

        $numero = (int) $compra_1->num;

        // Sin compra anterior borrada: la frase de siempre.
        $this->assertStringStartsWith('Creé la Compra N° ' . $numero . ' de FERRETOTAL', $tarjeta_1->resultado->texto);
        $this->assertStringNotContainsString('quedó libre', $tarjeta_1->resultado->texto);

        $this->borrar_la_compra($compra_1);

        list(, $tarjeta_2, $compra_2) = $this->compra_por_tarjeta($conversation);

        $this->assertSame($numero, (int) $compra_2->num, 'El N° de una compra borrada vuelve a quedar libre.');

        $this->assertStringStartsWith(
            'Creé la Compra N° ' . $numero . ' de FERRETOTAL (el N° ' . $numero . ' quedó libre porque la compra anterior con ese número se borró).',
            $tarjeta_2->resultado->texto
        );
        $this->assertTrue(isset($tarjeta_2->resultado->provider_order_num), 'El resultado tiene que traer provider_order_num.');
        $this->assertSame($numero, (int) $tarjeta_2->resultado->provider_order_num);
    }
}
