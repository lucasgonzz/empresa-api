<?php

namespace App\Jobs;

use App\Exceptions\SaleWhatsappSendException;
use App\Models\Sale;
use App\Models\WhatsappBotConfig;
use App\Services\SaleWhatsappSenderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Envío automático opcional del comprobante de una venta por el agente de WhatsApp
 * (grupo 137, Prompt 05). Se despacha DESPUÉS de que la venta ya quedó persistida
 * (`DB::commit()` en `SaleController@store`/`@update`), nunca dentro de la transacción de
 * guardado: si el envío falla acá, la venta ya guardada no se ve afectada.
 *
 * Opt-in: solo hace algo si el owner tiene `WhatsappBotConfig.auto_send_sale_pdf` activo
 * (default false). Todas las condiciones (config activa, opt-in, cliente con teléfono) se
 * revalidan acá adentro, no solo en el punto de despacho, porque el job puede tardar en
 * correr (cola `database`) y esas condiciones pudieron cambiar mientras tanto.
 */
class SendSaleWhatsappJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Un solo intento: un reintento automático de la cola podría volver a mandarle el
     * comprobante al cliente si el primer intento sí llegó a enviarlo por Kapso pero el
     * job falló después (ej: al persistir el mensaje o el broadcast).
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Id de la venta a enviar (se guarda el id, no el modelo, para no serializar de más y
     * para releer el estado más fresco cuando el job efectivamente corre).
     *
     * @var int
     */
    protected $sale_id;

    /**
     * @param  int  $sale_id
     */
    public function __construct($sale_id)
    {
        $this->sale_id = $sale_id;
    }

    /**
     * Revalida las condiciones de envío automático y manda el comprobante. Todo el cuerpo
     * va dentro de un try/catch: este job corre desacoplado del guardado de la venta (que
     * ya terminó y ya hizo commit antes de que este job se despache), así que una excepción
     * acá NUNCA puede afectar la venta ni propagarse hacia otro flujo.
     *
     * @return void
     */
    public function handle()
    {
        try {
            $sale = Sale::find($this->sale_id);
            if (is_null($sale)) {
                return;
            }

            $config = WhatsappBotConfig::getForUser((int) $sale->user_id);
            // Opt-in: sin config activa o sin auto_send_sale_pdf, no se manda nada (comportamiento por defecto).
            if (is_null($config) || ! $config->auto_send_sale_pdf) {
                return;
            }

            $sale->loadMissing('client');
            if (is_null($sale->client) || empty($sale->client->phone)) {
                Log::info('SendSaleWhatsappJob: venta sin cliente/teléfono, no se envía.', [
                    'sale_id' => $this->sale_id,
                ]);
                return;
            }

            $sender_service = new SaleWhatsappSenderService();
            // $sent_by_user_id = null: el envío automático no lo dispara ningún empleado puntual.
            $sender_service->send_sale($sale, null);

            // 🔴 Esta línea solo se alcanza si `send_sale()` NO lanzó, y desde el 15/8/2026
            // `send_sale()` lanza cuando Kapso no confirmó el envío. Antes de eso el service se
            // comía el null de `send_document()`/`send_template()` y este log afirmaba que el
            // comprobante había salido cuando no había salido nada. Si algún día alguien vuelve
            // a hacer que el service devuelva "ok" sin confirmación, este log vuelve a mentir:
            // la garantía vive allá, no acá.
            Log::info('SendSaleWhatsappJob: comprobante enviado automáticamente.', [
                'sale_id' => $this->sale_id,
            ]);
        } catch (SaleWhatsappSendException $e) {
            if ($e->error_code() === SaleWhatsappSenderService::CODE_ENVIO_NO_CONFIRMADO) {
                // Esto NO es una condición esperada del negocio: el comprobante tenía que salir
                // y no salió (clave de Kapso vencida, servicio caído, rate limit, número
                // rechazado por Meta). Va como error porque hay algo que arreglar, y porque el
                // cliente se quedó sin comprobante sin que nadie más se entere: el envío
                // automático no tiene pantalla donde avisar.
                Log::error('SendSaleWhatsappJob: WhatsApp no confirmó el envío del comprobante.', [
                    'sale_id' => $this->sale_id,
                    'error_code' => $e->error_code(),
                    'message' => $e->getMessage(),
                ]);
            } else {
                // Condición esperada (sin config, sin teléfono, plantilla no aprobada, etc.), no un error real.
                Log::info('SendSaleWhatsappJob: no se pudo enviar (condición controlada).', [
                    'sale_id' => $this->sale_id,
                    'error_code' => $e->error_code(),
                    'message' => $e->getMessage(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('SendSaleWhatsappJob: error inesperado al enviar el comprobante.', [
                'sale_id' => $this->sale_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Se ejecuta si el job agota los reintentos ($tries) sin éxito. Solo loguea el fallo
     * definitivo; nunca debe afectar la venta ya guardada.
     *
     * @param  \Throwable  $e
     * @return void
     */
    public function failed($e)
    {
        Log::error('SendSaleWhatsappJob: fallo definitivo tras agotar reintentos.', [
            'sale_id' => $this->sale_id,
            'error' => $e->getMessage(),
        ]);
    }
}
