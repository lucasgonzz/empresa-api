<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\AgentesIaHelper;
use App\Exceptions\RecordatorioCobroException;
use App\Http\Controllers\Helpers\asistente_ia\EnviosFallidosDelAsistenteHelper;
use App\Http\Controllers\Helpers\sale\VentasSinCobrarHelper;
use App\Models\AiEnvioFallido;
use App\Models\Client;
use App\Models\User;
use App\Models\WhatsappChatMessage;
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
     * Si las ventas se recalculan con el umbral propio de cada venta (el COALESCE de la pantalla) o con
     * los `$dias` tal cual (misión asistente-arreglos-segunda-prueba, 19/10/2026: el asistente, cuando el
     * dueño dijo los días, manda false; ver VentasSinCobrarHelper::query_de_ventas()).
     *
     * 🔴 EL DEFAULT VA EN LA DECLARACIÓN Y NO SOLO EN EL CONSTRUCTOR, Y NO ES UN DETALLE. Un job que ya
     * estaba en la cola (`QUEUE_CONNECTION=database`) cuando se desplegó este cambio se deserializa SIN
     * pasar por el constructor y sin esta propiedad en su payload: PHP le deja el valor de la
     * declaración. Con `true` acá, ese job manda exactamente lo que mandaba antes (el COALESCE con el que
     * se armó la pantalla o la tarjeta que lo encoló). Sin default sería null, y un null en esta
     * bandera cambiaría en silencio qué ventas se le reclaman al cliente.
     *
     * @var bool
     */
    protected $respetar_umbral_propio = true;

    /**
     * La tarjeta del asistente que encoló este recordatorio, o null si lo encoló la pantalla (misión
     * asistente-arreglos-segunda-prueba, 19/10/2026, N3). Con tarjeta, un recordatorio que no sale
     * (Kapso no confirmó, la plantilla dejó de estar aprobada, una falla) queda en `ai_envios_fallidos`
     * y vuelve al dueño que dijo que sí; y el WhatsApp que sí sale queda anotado con ella, por si Meta
     * lo rechaza después.
     *
     * 🔴 Default null EN LA DECLARACIÓN, por lo mismo que `$respetar_umbral_propio`: un job ya encolado
     * se deserializa sin pasar por el constructor.
     *
     * @var int|null
     */
    protected $ai_message_action_id = null;

    /**
     * Los motivos de un recordatorio que no salió que SÍ se le avisan al dueño: los que dicen "tenía que
     * salir y no salió" (infraestructura o una condición que cambió sin que él la tocara).
     *
     * 🔴 Los esperables NO: `sin_ventas` (pagó mientras el job esperaba: buena noticia), `ya_recibio_hoy`
     * (otro recordatorio le llegó igual), `cliente_no_encontrado` y `sin_telefono` (se borró el cliente o
     * su teléfono, cosas que hizo alguien del negocio). Avisarlos sería ruido sobre algo que no hay que
     * arreglar.
     *
     * @var array<int, string>
     */
    const MOTIVOS_QUE_VUELVEN_AL_DUENO = [
        RecordatorioCobroSenderService::CODE_ENVIO_NO_CONFIRMADO,
        RecordatorioCobroSenderService::CODE_PLANTILLA_NO_APROBADA,
        RecordatorioCobroSenderService::CODE_SIN_CONFIGURACION,
        RecordatorioCobroSenderService::CODE_SIN_RESUMEN_DE_CUENTA,
        'error_inesperado',
        'fallo_definitivo',
    ];

    /**
     * 🔴 Ids, no modelos. Serializar un `Client` o una lista de `Sale` metería en la cola un
     * estado congelado en el momento del POST, que es justo lo que este job NO tiene que usar.
     *
     * @param  int       $owner_id
     * @param  int       $client_id
     * @param  int       $dias
     * @param  int|null  $sent_by_user_id
     * @param  string|null  $lote_uuid
     * @param  bool      $respetar_umbral_propio  Ver la propiedad. Al final y con default: el
     *                                            controller de la pantalla no lo manda y no cambia.
     * @param  int|null  $ai_message_action_id    Ver la propiedad. Al final y con default.
     */
    public function __construct($owner_id, $client_id, $dias, $sent_by_user_id = null, $lote_uuid = null, $respetar_umbral_propio = true, $ai_message_action_id = null)
    {
        $this->owner_id = (int) $owner_id;
        $this->client_id = (int) $client_id;
        $this->dias = (int) $dias;
        $this->sent_by_user_id = is_null($sent_by_user_id) ? null : (int) $sent_by_user_id;
        $this->lote_uuid = $lote_uuid;
        $this->respetar_umbral_propio = (bool) $respetar_umbral_propio;
        $this->ai_message_action_id = is_null($ai_message_action_id) ? null : (int) $ai_message_action_id;
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
        // Misión agentes-ia-extension-unica (22/10/2026): el módulo de WhatsApp es de `agentes_ia`. Se revalida
        // con el dueño (no con Auth, que en la cola no existe) y se sale en silencio si ya no la tiene.
        if (! AgentesIaHelper::tiene_el_comercio_de($this->owner_id)) {
            return;
        }

        $enviado = false;
        $motivo = null;
        $client_name = null;
        $client = null;

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

            // Con el MISMO criterio de días que la tarjeta o la pantalla que lo encoló (ver la propiedad).
            $ventas = VentasSinCobrarHelper::query_de_ventas($this->owner_id, $this->employee_id_del_recorte(), $this->dias, true, $this->respetar_umbral_propio !== false)
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
            $mensaje = $service->enviar($this->owner_id, $client, $ventas, $this->sent_by_user_id);

            $enviado = true;

            // Salió: la fila queda con su tarjeta por si Meta lo rechaza después (N3, 19/10/2026).
            $this->anotar_la_tarjeta_en_el_mensaje($mensaje);

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

        // Si lo encoló el asistente y no salió por un motivo que el dueño tiene que saber, vuelve a él.
        if (! $enviado) {
            $this->registrar_si_vuelve_al_dueno($motivo, $client);
        }

        $this->marcar_procesado($enviado, $motivo, $client_name);
    }

    /**
     * Se ejecuta si el job agota los reintentos sin éxito. Loguea, cierra el renglón del lote para que
     * la barra de progreso no se quede colgada esperando un job que ya murió y, si lo encoló el
     * asistente, deja el envío fallido para el dueño (N3, 19/10/2026).
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

        $this->registrar_si_vuelve_al_dueno('fallo_definitivo', null);

        $this->marcar_procesado(false, 'fallo_definitivo', null);
    }

    /**
     * Un recordatorio que el asistente encoló y no salió: si el motivo es de los que el dueño tiene que
     * saber (MOTIVOS_QUE_VUELVEN_AL_DUENO), queda en `ai_envios_fallidos`, junto con los demás fallos de
     * la misma tarjeta (un aviso por lote, no uno por cliente).
     *
     * 🔴 EL CASO (N3 de la misión asistente-arreglos-segunda-prueba, 19/10/2026): el dueño confirmaba el
     * recordatorio por WhatsApp, el asistente le decía "en camino a 12 clientes" y los que no salían
     * morían en un `Log::error` de este job. Nunca lanza: el registro no puede tumbar el job.
     *
     * @param  string|null  $motivo
     * @param  \App\Models\Client|null  $client  El cliente ya leído, si se llegó a leer.
     * @return void
     */
    private function registrar_si_vuelve_al_dueno($motivo, $client)
    {
        if (empty($this->ai_message_action_id) || ! in_array((string) $motivo, self::MOTIVOS_QUE_VUELVEN_AL_DUENO, true)) {
            return;
        }

        try {

            if (is_null($client)) {
                $client = Client::where('id', $this->client_id)->where('user_id', $this->owner_id)->first();
            }

            EnviosFallidosDelAsistenteHelper::registrar(
                $this->ai_message_action_id,
                AiEnvioFallido::CANAL_WHATSAPP,
                is_null($client) ? null : (string) $client->phone,
                is_null($client) ? null : (string) $client->name,
                self::motivo_para_el_dueno((string) $motivo)
            );

        } catch (\Throwable $e) {
            // El registro es un extra: el job termina igual.
        }
    }

    /**
     * El motivo de un recordatorio que no salió, en palabras del dueño (el código queda en el log).
     *
     * @param  string  $motivo
     * @return string
     */
    private static function motivo_para_el_dueno($motivo)
    {
        $motivos = [
            RecordatorioCobroSenderService::CODE_ENVIO_NO_CONFIRMADO   => 'WhatsApp no confirmó el envío',
            RecordatorioCobroSenderService::CODE_PLANTILLA_NO_APROBADA => 'la plantilla del recordatorio dejó de estar aprobada en Meta',
            RecordatorioCobroSenderService::CODE_SIN_CONFIGURACION     => 'el WhatsApp del negocio no está conectado',
            RecordatorioCobroSenderService::CODE_SIN_RESUMEN_DE_CUENTA => 'no había resumen de cuenta para adjuntar a la plantilla',
        ];

        return isset($motivos[$motivo]) ? $motivos[$motivo] : 'una falla del sistema';
    }

    /**
     * Anota la tarjeta del asistente en la fila del WhatsApp que salió, para que un rechazo posterior de
     * Meta (webhook `failed`) vuelva al dueño. Por query (sin eventos del modelo) y sin poder lanzar: el
     * recordatorio ya está en el teléfono del cliente.
     *
     * @param  mixed  $mensaje  El WhatsappChatMessage que devolvió enviar().
     * @return void
     */
    private function anotar_la_tarjeta_en_el_mensaje($mensaje)
    {
        if (empty($this->ai_message_action_id) || ! is_object($mensaje) || empty($mensaje->id)) {
            return;
        }

        try {

            WhatsappChatMessage::where('id', (int) $mensaje->id)->update(['ai_message_action_id' => $this->ai_message_action_id]);

        } catch (\Throwable $e) {

            Log::info('SendRecordatorioCobroJob: no se pudo anotar la tarjeta en el mensaje.', [
                'client_id' => $this->client_id,
                'error'     => $e->getMessage(),
            ]);
        }
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
