<?php

namespace App\Jobs;

use App\Exceptions\RecordatorioCobroException;
use App\Http\Controllers\Helpers\sale\VentasSinCobrarHelper;
use App\Models\Client;
use App\Models\User;
use App\Services\RecordatorioCobroSenderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Un recordatorio de cobro por WhatsApp para UN cliente (misión recordatorio-cobro-whatsapp).
 * La unidad es el cliente y no la venta: un cliente con seis ventas vencidas recibe UN mensaje
 * con las cinco más viejas listadas y una línea "y 1 venta más", no seis mensajes.
 *
 * 🔴 EL JOB RECALCULA TODO EN `handle()` Y NO CONFÍA EN NADA DE LO QUE RESOLVIÓ EL REQUEST.
 * Recibe ids y un umbral de días, nunca la lista de ventas ni el modelo. Dos motivos, los dos
 * duros:
 *
 * 1. SEGURIDAD. Si el request pudiera dictar qué ventas se le mandan a un cliente, un empleado
 *    con `ver_solo_las_ventas_suyas` podría hacerle llegar ventas que no le corresponde ver. El
 *    recorte se vuelve a derivar acá del usuario que disparó el envío, con la misma regla que
 *    usa `SaleController::ventas_sin_cobrar()`.
 * 2. FRESCURA. Entre el POST y la corrida del job puede pasar un rato largo: una venta pudo
 *    cobrarse, y mandarle a un cliente el reclamo de algo que ya pagó es peor que no mandarle
 *    nada. Por eso el freno de 24 h también se revalida acá (lo hace `enviar()` en el service,
 *    con el mismo estático que consulta el controller).
 *
 * Todo el cuerpo va dentro de un try/catch: este job corre desacoplado del request, que ya
 * respondió 202, así que una excepción acá no puede propagarse a ningún lado. Y los errores se
 * loguean partidos en dos, igual que `SendSaleWhatsappJob`: `CODE_ENVIO_NO_CONFIRMADO` como
 * `Log::error` (hay algo que arreglar) y el resto como `Log::info` (condición esperable).
 */
class SendRecordatorioCobroJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Un solo intento, igual que `SendSaleWhatsappJob` y por el mismo motivo: un reintento
     * automático de la cola podría volver a mandarle el recordatorio al cliente si el primer
     * intento sí llegó a salir por Kapso pero el job falló después (al persistir o al
     * broadcastear). El freno de 24 h tapa la mayoría de esos casos, pero no el borde en el que
     * el envío salió y la fila no se llegó a escribir.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Techo de tiempo del job. Un recordatorio son a lo sumo dos queries y un POST a Kapso; si
     * pasa de dos minutos hay algo colgado y conviene que la cola lo corte.
     *
     * @var int
     */
    public $timeout = 120;

    /**
     * Cuántos fallos se guardan con nombre y apellido adentro del lote. El resto sigue contado en
     * `motivos_fallo`. El tope existe para que el lote no crezca sin techo con un masivo grande:
     * la pantalla muestra los primeros y explica el resto por motivo.
     *
     * @var int
     */
    const MAX_FALLOS_DETALLADOS = 50;

    /** @var int Dueño de la empresa (`sales.user_id`). */
    protected $owner_id;

    /** @var int Cliente al que se le manda el recordatorio. */
    protected $client_id;

    /** @var int Umbral de antigüedad en días, ya resuelto por el controller. */
    protected $dias;

    /** @var int|null Empleado que disparó el envío. Define el recorte de ventas. */
    protected $sent_by_user_id;

    /** @var string|null Uuid del lote, para mover los contadores del progreso. */
    protected $lote_uuid;

    /**
     * 🔴 Ids, no modelos. Serializar un `Client` o una lista de `Sale` metería en la cola un
     * estado congelado en el momento del POST, que es justo lo que este job NO tiene que usar.
     *
     * @param  int       $owner_id
     * @param  int       $client_id
     * @param  int       $dias
     * @param  int|null  $sent_by_user_id
     * @param  string|null  $lote_uuid
     */
    public function __construct($owner_id, $client_id, $dias, $sent_by_user_id = null, $lote_uuid = null)
    {
        $this->owner_id = (int) $owner_id;
        $this->client_id = (int) $client_id;
        $this->dias = (int) $dias;
        $this->sent_by_user_id = is_null($sent_by_user_id) ? null : (int) $sent_by_user_id;
        $this->lote_uuid = $lote_uuid;
    }

    /**
     * Clave de Cache donde vive el progreso de un lote.
     *
     * 🔴 EL `{owner_id}` EN LA CLAVE ES EL GUARD, no un prefijo estético: sin él, alguien que
     * adivinara (o viera) un uuid podría leer el progreso de un lote de otra empresa. Con el
     * owner adentro, el endpoint de estado sólo puede encontrar lo que le pertenece al usuario
     * autenticado.
     *
     * @param  int     $owner_id
     * @param  string  $lote_uuid
     * @return string
     */
    public static function cache_key($owner_id, $lote_uuid)
    {
        return 'recordatorio_cobro_lote:'.(int) $owner_id.':'.$lote_uuid;
    }

    /**
     * Recalcula el recorte y las ventas del cliente, y manda el recordatorio.
     *
     * @return void
     */
    public function handle()
    {
        $enviado = false;
        $motivo = null;
        $client_name = null;

        try {

            $client = Client::where('id', $this->client_id)
                ->where('user_id', $this->owner_id)
                ->first();

            if (is_null($client)) {
                // El cliente se borró (o nunca fue de esta empresa) entre el POST y la corrida.
                Log::info('SendRecordatorioCobroJob: el cliente ya no existe, no se envía.', [
                    'client_id' => $this->client_id,
                    'owner_id'  => $this->owner_id,
                ]);

                $this->marcar_procesado(false, 'cliente_no_encontrado', null);
                return;
            }

            $client_name = $client->name;

            $ventas = VentasSinCobrarHelper::query_de_ventas($this->owner_id, $this->employee_id_del_recorte(), $this->dias)
                ->where('client_id', $this->client_id)
                ->with('current_acount')
                ->orderBy('created_at', 'ASC')
                ->get();

            if ($ventas->count() === 0) {
                // Se cobraron mientras el job esperaba en la cola. Reclamarle a alguien algo que
                // ya pagó es peor que no mandarle nada.
                Log::info('SendRecordatorioCobroJob: el cliente ya no tiene ventas sin cobrar en el filtro, no se envía.', [
                    'client_id' => $this->client_id,
                ]);

                $this->marcar_procesado(false, RecordatorioCobroSenderService::CODE_SIN_VENTAS, $client_name);
                return;
            }

            $service = new RecordatorioCobroSenderService();

            // El freno de 24 h se revalida adentro de `enviar()`: entre el POST y esta línea
            // otro operador pudo haberle mandado el mismo recordatorio.
            $service->enviar($this->owner_id, $client, $ventas, $this->sent_by_user_id);

            $enviado = true;

            // 🔴 Esta línea sólo se alcanza si `enviar()` NO lanzó, y `enviar()` lanza cuando
            // Kapso no confirmó el envío. Si algún día alguien hace que el service devuelva "ok"
            // sin confirmación, este log vuelve a mentir: la garantía vive allá, no acá.
            Log::info('SendRecordatorioCobroJob: recordatorio de cobro enviado.', [
                'client_id' => $this->client_id,
                'ventas'    => $ventas->count(),
            ]);

        } catch (RecordatorioCobroException $e) {

            // 🔴 EL MOTIVO SE GUARDA, NO SÓLO SE LOGUEA. Estos cuatro casos (la plantilla que no
            // está aprobada, las ventas que se cobraron mientras el job esperaba, el envío que
            // Kapso no confirmó y el freno de 24 h revalidado) se detectan recién acá, o sea
            // después de que el 202 ya contestó. Si sólo fueran a un `Log`, en pantalla salen como
            // un número pelado —"3 fallidos"— y el operador no tiene con qué accionar. La decisión
            // de Lucas es que los salteados y los fallidos se muestren AGRUPADOS POR MOTIVO.
            $motivo = $e->error_code();

            if ($e->error_code() === RecordatorioCobroSenderService::CODE_ENVIO_NO_CONFIRMADO) {
                // NO es una condición esperable del negocio: el recordatorio tenía que salir y no
                // salió (clave de Kapso vencida, servicio caído, rate limit, número rechazado por
                // Meta). Va como error porque hay algo que arreglar y porque el cliente se quedó
                // sin aviso sin que nadie más se entere: el masivo no tiene pantalla donde avisar.
                Log::error('SendRecordatorioCobroJob: WhatsApp no confirmó el envío del recordatorio.', [
                    'client_id'  => $this->client_id,
                    'error_code' => $e->error_code(),
                    'message'    => $e->getMessage(),
                ]);
            } else {
                // Condición esperable (sin teléfono, sin config, plantilla no aprobada, ya recibió
                // el recordatorio hoy). NO es un error: si esto fuera `Log::error`, un cliente sin
                // teléfono cargado ensuciaría el canal y taparía los fallos de verdad.
                Log::info('SendRecordatorioCobroJob: no se envió el recordatorio (condición controlada).', [
                    'client_id'  => $this->client_id,
                    'error_code' => $e->error_code(),
                    'message'    => $e->getMessage(),
                ]);
            }

        } catch (\Throwable $e) {

            $motivo = 'error_inesperado';

            Log::error('SendRecordatorioCobroJob: error inesperado al enviar el recordatorio.', [
                'client_id' => $this->client_id,
                'error'     => $e->getMessage(),
            ]);
        }

        $this->marcar_procesado($enviado, $motivo, $client_name);
    }

    /**
     * Se ejecuta si el job agota los reintentos sin éxito. Sólo loguea y cierra el renglón del
     * lote, para que la barra de progreso no se quede colgada esperando un job que ya murió.
     *
     * @param  \Throwable  $e
     * @return void
     */
    public function failed($e)
    {
        Log::error('SendRecordatorioCobroJob: fallo definitivo tras agotar reintentos.', [
            'client_id' => $this->client_id,
            'error'     => $e->getMessage(),
        ]);

        $this->marcar_procesado(false, 'fallo_definitivo', null);
    }

    /**
     * Vuelve a derivar el recorte `ver_solo_las_ventas_suyas` a partir del usuario que disparó
     * el envío, con la MISMA regla que `SaleController::ventas_sin_cobrar()`.
     *
     * 🔴 Se recalcula en vez de recibirse por constructor a propósito. El recorte es una
     * propiedad del usuario, no del request: si viajara como parámetro, cualquiera que
     * despachara el job podría pedir "sin recorte" y llevarse las ventas de toda la empresa.
     * Acá el único dato que entra de afuera es QUIÉN lo disparó.
     *
     * @return int|null  Id del empleado si hay que recortar; null si ve todo.
     */
    private function employee_id_del_recorte()
    {
        if (is_null($this->sent_by_user_id)) {
            // Sin usuario puntual (envío del sistema): se usa el alcance completo del owner.
            return null;
        }

        $user = User::find($this->sent_by_user_id);

        if (is_null($user)) {
            // El empleado ya no existe: se recorta a sus ventas, que es el criterio conservador.
            return $this->sent_by_user_id;
        }

        // El dueño ve todo.
        if (is_null($user->owner_id) || (int) $user->id === $this->owner_id) {
            return null;
        }

        if ($user->ver_alertas_de_todos_los_empleados) {
            return null;
        }

        return (int) $user->id;
    }

    /**
     * Mueve los contadores del lote en Cache y, si el recordatorio no salió, deja escrito POR QUÉ.
     * Sin `lote_uuid` (o con el lote ya vencido) no hace nada: el envío en sí no depende del
     * progreso.
     *
     * 🔴 `motivos_fallo` ES UN MAPA `motivo => cantidad` y no una lista de todo lo que pasó, para
     * que el lote no crezca con el tamaño del masivo: son cinco o seis claves como mucho, mande a
     * 3 clientes o a 300. `fallos` sí guarda el nombre del cliente, porque "a quién no le llegó"
     * es lo primero que pregunta el operador, pero está TOPEADO: pasado el tope quedan los
     * contadores, que es lo que la pantalla necesita para explicar el resto.
     *
     * ⚠️ Con `CACHE_DRIVER=file` esto es read-modify-write y dos jobs en paralelo pueden pisarse
     * un tick. Hoy no pasa (un worker, serial). Si algún día se levantan varios, la barra puede
     * no llegar al 100%: por eso el front corta también por timeout y no sólo por `terminado`.
     *
     * @param  bool         $enviado
     * @param  string|null  $motivo       Código del fallo; null si salió bien.
     * @param  string|null  $client_name  Nombre del cliente, para que el fallo se pueda leer.
     * @return void
     */
    private function marcar_procesado($enviado, $motivo = null, $client_name = null)
    {
        if (is_null($this->lote_uuid)) {
            return;
        }

        try {

            $key = self::cache_key($this->owner_id, $this->lote_uuid);

            $lote = Cache::get($key);

            if (is_null($lote) || ! is_array($lote)) {
                return;
            }

            $lote['procesados'] = (int) $lote['procesados'] + 1;

            if ($enviado) {
                $lote['enviados'] = (int) $lote['enviados'] + 1;
            } else {
                $lote['fallidos'] = (int) $lote['fallidos'] + 1;

                $lote = $this->anotar_fallo($lote, $motivo, $client_name);
            }

            $lote['terminado'] = $lote['procesados'] >= (int) $lote['total'];

            Cache::put($key, $lote, now()->addHour());

        } catch (\Throwable $e) {
            // El progreso es cosmético: si la cache falla, el envío ya ocurrió igual y lo único
            // que se pierde es la barra. Nunca puede tumbar el job.
            Log::info('SendRecordatorioCobroJob: no se pudo actualizar el progreso del lote.', [
                'lote_uuid' => $this->lote_uuid,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Suma un fallo al lote: una unidad en el contador de su motivo y, mientras haya lugar, un
     * renglón con el nombre del cliente.
     *
     * Los `isset()` de arriba no son paranoia: un lote escrito por la versión anterior del
     * controller puede seguir vivo en Cache (viven una hora) sin ninguna de las dos claves, y
     * este job no puede tumbarse por eso.
     *
     * @param  array        $lote
     * @param  string|null  $motivo
     * @param  string|null  $client_name
     * @return array
     */
    private function anotar_fallo(array $lote, $motivo, $client_name)
    {
        if (is_null($motivo)) {
            $motivo = 'desconocido';
        }

        if (! isset($lote['motivos_fallo']) || ! is_array($lote['motivos_fallo'])) {
            $lote['motivos_fallo'] = [];
        }

        if (! isset($lote['fallos']) || ! is_array($lote['fallos'])) {
            $lote['fallos'] = [];
        }

        $lote['motivos_fallo'][$motivo] = isset($lote['motivos_fallo'][$motivo])
            ? (int) $lote['motivos_fallo'][$motivo] + 1
            : 1;

        if (count($lote['fallos']) < self::MAX_FALLOS_DETALLADOS) {
            $lote['fallos'][] = [
                'client_id'   => $this->client_id,
                'client_name' => $client_name,
                'motivo'      => $motivo,
            ];
        }

        return $lote;
    }
}
