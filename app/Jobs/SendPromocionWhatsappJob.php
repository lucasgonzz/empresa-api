<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\asistente_ia\PropuestaPromocionWhatsappIaHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Manda la promoción del asistente a UN cliente por WhatsApp (misión asistente-envios-a-clientes,
 * 16/10/2026). Molde de `SendRecordatorioCobroJob`: un job por cliente, `$tries = 1`, y toda la
 * decisión adentro de `PropuestaPromocionWhatsappIaHelper::enviar_a_cliente()`, que revalida los dos
 * topes (250 por negocio en 24 horas, uno por cliente cada 7 días), la ventana y la plantilla justo
 * antes de mandar.
 *
 * 🔴 `$tries = 1`, Y NO ES UN DESCUIDO: un reintento de un envío que llegó a Kapso pero cuyo id se
 * perdió le mandaría la misma promo dos veces al mismo cliente. Un cliente que no la recibió es
 * preferible a uno que la recibe repetida y marca el número como spam.
 *
 * 🔴 NO LANZA: una condición del negocio (sin teléfono, tope, plantilla no aprobada) o una falla de
 * Kapso se loguean y el job termina. Con `QUEUE_CONNECTION=sync` una excepción volvería a la
 * confirmación del dueño, que ya encoló al resto.
 *
 * 🔴 Revalidar, mandar y persistir van adentro del candado del negocio
 * (`Cache::lock("promocion_whatsapp:{owner_id}")`, en enviar_a_cliente()): dos jobs del mismo negocio
 * no pueden leer el mismo cupo a la vez.
 *
 * Recibe ids y textos, no modelos: entre el despacho y la corrida el cliente pudo cambiar, y el
 * helper lo vuelve a leer.
 */
class SendPromocionWhatsappJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Un solo intento: ver el docblock de la clase.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Hasta cuándo se puede reintentar (I4 del segundo chequeo): solo vuelve a la cola cuando el candado
     * del negocio estaba tomado (no se mandó nada), y como mucho durante 10 minutos. Con retryUntil
     * definido, Laravel 8 deja de mirar `$tries` para decidir si el job falló por intentos.
     *
     * @return \DateTime
     */
    public function retryUntil()
    {
        return now()->addMinutes(10);
    }

    /**
     * El mismo margen que el recordatorio: una sola llamada a Kapso.
     *
     * @var int
     */
    public $timeout = 120;

    /** @var int */
    protected $owner_id;

    /** @var int */
    protected $client_id;

    /** @var string */
    protected $texto;

    /** @var string|null */
    protected $link;

    /** @var string La vigencia que dijo el dueño ('' = no dijo). */
    protected $vigencia;

    /** @var int|null */
    protected $sent_by_user_id;

    /**
     * La tarjeta que encoló este envío: sus clientes son reservas para los topes de las OTRAS promos,
     * no de sí misma (ver PropuestaPromocionWhatsappIaHelper::reservas()).
     *
     * @var int|null
     */
    protected $accion_id;

    /**
     * @param  int  $owner_id
     * @param  int  $client_id
     * @param  string  $texto  El texto de la promo, tal cual lo vio el dueño en la tarjeta.
     * @param  string|null  $link
     * @param  string  $vigencia
     * @param  int|null  $sent_by_user_id  La persona que la pidió al asistente.
     * @param  int|null  $accion_id  La tarjeta que lo encoló.
     */
    public function __construct($owner_id, $client_id, $texto, $link, $vigencia, $sent_by_user_id = null, $accion_id = null)
    {
        $this->accion_id = is_null($accion_id) ? null : (int) $accion_id;
        $this->owner_id = (int) $owner_id;
        $this->client_id = (int) $client_id;
        $this->texto = (string) $texto;
        $this->link = is_null($link) ? null : (string) $link;
        $this->vigencia = (string) $vigencia;
        $this->sent_by_user_id = is_null($sent_by_user_id) ? null : (int) $sent_by_user_id;
    }

    /**
     * Manda (o no, con su motivo en el log).
     *
     * @return void
     */
    public function handle()
    {
        try {

            $salida = PropuestaPromocionWhatsappIaHelper::enviar_a_cliente(
                $this->owner_id,
                $this->client_id,
                $this->texto,
                $this->link,
                $this->vigencia,
                $this->sent_by_user_id,
                $this->accion_id
            );

            if ($salida['ok']) {

                Log::info('SendPromocionWhatsappJob: promoción enviada.', [
                    'owner_id'  => $this->owner_id,
                    'client_id' => $this->client_id,
                ]);

                return;
            }

            /*
             * I4 del segundo chequeo: el candado del negocio estaba tomado por otro envío. No se mandó
             * NADA, así que reintentar no duplica: vuelve a la cola en 30 segundos, hasta retryUntil().
             */
            if ($salida['motivo'] === 'candado_ocupado') {

                Log::warning('SendPromocionWhatsappJob: el candado del negocio estaba tomado; se reintenta en 30 segundos.', [
                    'owner_id'  => $this->owner_id,
                    'client_id' => $this->client_id,
                ]);

                $this->release(30);

                return;
            }

            // Kapso que no confirma es una falla de verdad; el resto son condiciones del negocio.
            $nivel = $salida['motivo'] === 'envio_no_confirmado' ? 'error' : 'info';

            Log::$nivel('SendPromocionWhatsappJob: la promoción no salió.', [
                'owner_id'  => $this->owner_id,
                'client_id' => $this->client_id,
                'motivo'    => $salida['motivo'],
            ]);

        } catch (\Throwable $e) {

            Log::error('SendPromocionWhatsappJob: error inesperado al mandar la promoción.', [
                'owner_id'  => $this->owner_id,
                'client_id' => $this->client_id,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
