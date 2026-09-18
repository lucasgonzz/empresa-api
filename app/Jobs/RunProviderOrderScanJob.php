<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Models\ProviderOrderScan;
use App\Models\User;
use App\Notifications\GlobalNotification;
use App\Services\EscaneoFacturaCompraService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job que lee en segundo plano las fotos de una factura de compra (misión
 * escaneo-factura-compra) y deja el resultado en `provider_order_scans.resultado`.
 *
 * Va a la cola por lo mismo que RunExcelAnalysisJob: una llamada de visión con hasta seis
 * páginas tarda bastante más que el timeout de un request HTTP, y el usuario que sacó la
 * foto desde el teléfono no puede quedarse mirando una pantalla en blanco hasta que
 * termine. Cuando el escaneo termina —bien o mal— le llega un aviso por broadcast.
 *
 * 🔴 ESTE JOB NO TOCA LA COMPRA. Ni un artículo, ni un costo, ni el stock, ni la cuenta
 * corriente, ni la factura. Solo escanea, matchea contra el catálogo y persiste lo leído.
 * Todo lo que modifica la compra pasa en el request de confirmación, que sí está
 * autenticado (ProviderOrderScanController@confirmar).
 *
 * El motivo, medido y no supuesto: NewProviderOrderHelper::__construct() hace
 * `$this->user = UserHelper::user()`, y UserHelper::user() devuelve null cuando no hay
 * sesión ni Auth (que es exactamente el caso adentro de un worker). Ese null no explota en
 * el constructor: explota más adelante y sin guarda en cinco líneas de ese helper
 * (ArticlePricesHelper::el_iva_participa_del_precio($this->user), `$this->user->dollar`,
 * `!$this->user->iva_included`, y dos llamadas a el_costo_cargado_es_bruto()).
 *
 * Corolario que vale para todo este archivo: el dueño de los datos sale de `$scan->user_id`
 * y la persona a la que hay que avisarle, de `$scan->auth_user_id`. Acá no aparecen
 * `UserHelper::`, `auth()->` ni `$this->userId()`.
 *
 * Constraint del repo: PHP 7.4.
 */
class RunProviderOrderScanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Id del escaneo (provider_order_scans.id) a procesar.
     *
     * Se pasa solo el id, no el modelo: el payload serializado queda chico y no arrastra un
     * `resultado` que puede haber cambiado entre el dispatch (dentro del request) y la
     * ejecución real (en el worker, potencialmente minutos después).
     *
     * @var int
     */
    protected $provider_order_scan_id;

    /**
     * Tiempo máximo (segundos) que el worker le da al job antes de matarlo. La llamada a
     * Anthropic ya tiene su propio timeout (180 s por config), y antes de eso hay que
     * redimensionar y encodear hasta seis fotos de teléfono.
     *
     * @var int
     */
    public $timeout = 600;

    /**
     * No se reintenta. Si el escaneo falló, el problema está en la foto o en la respuesta
     * de la IA, y reintentar solo quemaría otros diez minutos de worker (y otra llamada
     * paga) para llegar al mismo error. El usuario ve el motivo y saca la foto de nuevo.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * @param  int  $provider_order_scan_id  Id del ProviderOrderScan a procesar.
     */
    public function __construct(int $provider_order_scan_id)
    {
        $this->provider_order_scan_id = $provider_order_scan_id;
    }

    /**
     * Corre el escaneo y persiste el resultado.
     *
     * @return void
     */
    public function handle()
    {
        /* Si el escaneo no existe (borrado, id inválido), no hay nada que hacer. */
        $scan = ProviderOrderScan::find($this->provider_order_scan_id);

        if (is_null($scan)) {
            Log::warning('RunProviderOrderScanJob: no se encontró el ProviderOrderScan', [
                'provider_order_scan_id' => $this->provider_order_scan_id,
            ]);
            return;
        }

        /*
         * Registro visible del proceso (misión procesos-en-segundo-plano, 18/9/2026): mismo
         * patrón que RunExcelAnalysisJob. Se abre cuando el worker levanta el escaneo y cada hito
         * de `progreso` que ya se escribía se replica con etapa(), como porcentaje sobre 100.
         */
        $this->iniciar_proceso_en_segundo_plano($scan);

        /* Hito de progreso inicial: recién estamos por abrir las fotos. */
        $scan->update([
            'estado'   => 'procesando',
            'progreso' => 10,
            'paso'     => 'Leyendo las fotos…',
        ]);
        $this->etapa_del_proceso_en_segundo_plano($scan, 'Leyendo las fotos…', 10);

        try {
            /* Hito de progreso: entramos a la parte lenta (la llamada de visión). */
            $scan->update([
                'progreso' => 40,
                'paso'     => 'Analizando la factura con IA…',
            ]);
            $this->etapa_del_proceso_en_segundo_plano($scan, 'Analizando la factura con IA…', 40);

            $service   = new EscaneoFacturaCompraService();
            $resultado = $service->escanear($scan);

            $scan->update([
                'estado'    => 'listo',
                'progreso'  => 100,
                'paso'      => null,
                'error'     => null,
                'resultado' => $resultado,
            ]);

            /* El hito del 100 es el cierre: completar() deja porcentaje 100 y los artículos leídos. */
            $this->completar_proceso_en_segundo_plano($scan);

            $this->notificar_fin($scan);

        } catch (\RuntimeException $e) {
            /*
             * Errores esperables y explicables: sin clave de API, fotos ilegibles, la IA
             * respondió con error o devolvió algo que no es JSON. El mensaje del servicio
             * ya está escrito para que lo lea un usuario, así que se muestra tal cual.
             */
            Log::warning('RunProviderOrderScanJob: error de escaneo', [
                'provider_order_scan_id' => $scan->id,
                'message'                => $e->getMessage(),
            ]);

            $this->finalizar_con_error($scan, $e->getMessage());

        } catch (\Throwable $e) {
            Log::error('RunProviderOrderScanJob: error inesperado', [
                'provider_order_scan_id' => $scan->id,
                'message'                => $e->getMessage(),
                'trace'                  => $e->getTraceAsString(),
            ]);

            $this->finalizar_con_error($scan, 'Ocurrió un error inesperado al escanear la factura: ' . $e->getMessage());
        }
    }

    /**
     * Deja el escaneo en error y le avisa igual al usuario.
     *
     * Existe para que ninguna de las salidas de error pueda olvidarse del aviso: el usuario
     * sacó la foto y se fue a otra pantalla, y un error sin aviso es una espera infinita.
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @param  string                         $mensaje  Texto legible para el usuario
     * @return void
     */
    protected function finalizar_con_error(ProviderOrderScan $scan, string $mensaje)
    {
        $scan->update([
            'estado' => 'error',
            'error'  => $mensaje,
        ]);

        /*
         * Registro visible del proceso: mismo mensaje que ve el usuario. Cubre las salidas de
         * error del handle() y el failed() del job (que entra por acá); fallar() es idempotente.
         */
        $this->fallar_proceso_en_segundo_plano($scan, $mensaje);

        $this->notificar_fin($scan);
    }

    /**
     * Abre el registro visible del proceso para este escaneo (misión procesos-en-segundo-plano).
     *
     * El proveedor sale de contexto_para_frontend(), que ya resuelve compra → proveedor sin
     * romperse si alguno no está. `total` va en 100 desde el alta para que la barra sea
     * determinada desde el primer cuadro (los hitos viajan como porcentaje).
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @return void
     */
    protected function iniciar_proceso_en_segundo_plano(ProviderOrderScan $scan)
    {
        try {
            $proveedor = $this->nombre_del_proveedor($scan);

            BackgroundProcessHelper::iniciar($scan->user_id, 'escaneo_factura', 'Escaneo de factura de compra', [
                'auth_user_id' => empty($scan->auth_user_id) ? null : $scan->auth_user_id,
                'referencia'   => $scan,
                'detalle'      => is_null($proveedor) ? null : 'Proveedor ' . $proveedor,
                'total'        => 100,
                /* La misma etapa que el primer hito, para que el alta no viaje con la etapa vacía. */
                'etapa'        => 'Leyendo las fotos…',
                'resultado'    => is_null($proveedor) ? [] : ['proveedor' => $proveedor],
            ]);
        } catch (\Throwable $e) {
            Log::warning('RunProviderOrderScanJob: no se pudo abrir el proceso en segundo plano (el escaneo sigue).', [
                'provider_order_scan_id' => $scan->id,
                'message'                => $e->getMessage(),
            ]);
        }
    }

    /**
     * Replica un hito de `progreso` del escaneo en el registro visible del proceso.
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @param  string                         $paso      Texto del hito (el mismo `paso` del escaneo).
     * @param  int                            $progreso  0..100.
     * @return void
     */
    protected function etapa_del_proceso_en_segundo_plano(ProviderOrderScan $scan, $paso, $progreso)
    {
        try {
            $proceso = BackgroundProcessHelper::por_referencia($scan);

            if (!is_null($proceso)) {
                BackgroundProcessHelper::etapa($proceso, $paso, $progreso);
            }
        } catch (\Throwable $e) {
            Log::warning('RunProviderOrderScanJob: no se pudo registrar el hito del proceso en segundo plano (el escaneo sigue).', [
                'provider_order_scan_id' => $scan->id,
                'message'                => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cierra el registro visible del proceso como terminado, con cuántos artículos se leyeron.
     *
     * @param  \App\Models\ProviderOrderScan  $scan  Ya con `resultado` persistido.
     * @return void
     */
    protected function completar_proceso_en_segundo_plano(ProviderOrderScan $scan)
    {
        try {
            $proceso = BackgroundProcessHelper::por_referencia($scan);

            if (is_null($proceso)) {
                return;
            }

            $contexto  = $scan->contexto_para_frontend();
            $proveedor = isset($contexto['provider_nombre']) ? trim((string) $contexto['provider_nombre']) : '';

            $resultado = ['articulos' => isset($contexto['cantidad_articulos']) ? (int) $contexto['cantidad_articulos'] : 0];

            if ($proveedor !== '') {
                $resultado['proveedor'] = $proveedor;
            }

            BackgroundProcessHelper::completar($proceso, $resultado);
        } catch (\Throwable $e) {
            Log::warning('RunProviderOrderScanJob: no se pudo cerrar el proceso en segundo plano (el escaneo terminó igual).', [
                'provider_order_scan_id' => $scan->id,
                'message'                => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cierra el registro visible del proceso como fallido.
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @param  string                         $mensaje  El mismo texto que ve el usuario.
     * @return void
     */
    protected function fallar_proceso_en_segundo_plano(ProviderOrderScan $scan, $mensaje)
    {
        try {
            $proceso = BackgroundProcessHelper::por_referencia($scan);

            if (!is_null($proceso)) {
                BackgroundProcessHelper::fallar($proceso, $mensaje);
            }
        } catch (\Throwable $e) {
            Log::warning('RunProviderOrderScanJob: no se pudo marcar el fallo del proceso en segundo plano.', [
                'provider_order_scan_id' => $scan->id,
                'message'                => $e->getMessage(),
            ]);
        }
    }

    /**
     * Nombre del proveedor de la compra escaneada, o null si no se puede resolver. Reusa
     * contexto_para_frontend() en vez de repetir la cadena compra → proveedor con sus guardas.
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @return string|null
     */
    protected function nombre_del_proveedor(ProviderOrderScan $scan)
    {
        $contexto = $scan->contexto_para_frontend();

        $nombre = isset($contexto['provider_nombre']) ? trim((string) $contexto['provider_nombre']) : '';

        return $nombre === '' ? null : $nombre;
    }

    /**
     * Avisa por broadcast que el escaneo terminó (bien o mal).
     *
     * El aviso lleva lo mínimo para decir de qué compra habla y para poder ir a buscar el
     * detalle: uuid, compra, estado, error, cuántos artículos salieron y de qué proveedor.
     * 🔴 El `resultado` NO viaja acá: el aviso solo dice que terminó, y el resumen se pide
     * recién si el usuario aprieta el botón. Es lo que le permite ignorar el aviso sin
     * haber pagado nada por él.
     *
     * Nunca deja que un problema al notificar tumbe el escaneo: el resultado ya está
     * guardado y el usuario lo va a encontrar igual por /provider-order-scan/en-curso la
     * próxima vez que cargue la SPA.
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @return void
     */
    protected function notificar_fin(ProviderOrderScan $scan)
    {
        /*
         * Sin auth_user_id no se avisa. El canal global_notification.{owner_id} lo escuchan
         * TODOS los empleados del comercio: avisar sin saber a quién sería interrumpir a los
         * cuatro compañeros del que sacó la foto. Mejor mudo que a todos — el escaneo sigue
         * estando y aparece igual en el listado.
         */
        if (empty($scan->auth_user_id)) {
            return;
        }

        try {
            /*
             * La notificación se emite sobre el canal del owner (así lo hace
             * GlobalNotification::broadcastOn) y se filtra en el frontend por
             * is_only_for_auth_user.
             */
            $owner = User::find($scan->user_id);

            if (is_null($owner)) {
                return;
            }

            $contexto = $scan->contexto_para_frontend();

            $provider_nombre = isset($contexto['provider_nombre']) ? $contexto['provider_nombre'] : null;

            /* "la factura de Distribuidora del Sur" si sabemos el proveedor; si no, "la factura". */
            $de_quien = (!is_null($provider_nombre) && trim((string) $provider_nombre) !== '')
                ? 'la factura de ' . $provider_nombre
                : 'la factura';

            $message_text = $scan->estado === 'listo'
                ? 'Terminó el escaneo de ' . $de_quien
                : 'No se pudo escanear ' . $de_quien;

            $owner->notify(new GlobalNotification([
                'message_text'          => $message_text,
                'color_variant'         => $scan->estado === 'listo' ? 'success' : 'danger',
                /*
                 * info_to_show y functions_to_execute son el plan B: si el SPA que recibe
                 * esto todavía no conoce el modal provider_order_scan_ready, cae en el
                 * global-notification genérico y ahí estos dos campos son lo único que se
                 * muestra. El aviso queda pobre, pero no queda mudo.
                 */
                'info_to_show'          => [],
                'functions_to_execute'  => [
                    [
                        'btn_text'      => 'Entendido',
                        'function_name' => 'close_notification_modal',
                        'btn_variant'   => 'primary',
                    ],
                ],
                'owner_id'              => $scan->user_id,
                /* Solo para quien sacó la foto, no para todo el comercio. */
                'is_only_for_auth_user' => $scan->auth_user_id,
                'notification_modal'    => 'provider_order_scan_ready',
                'provider_order_scan'   => [
                    'uuid'               => $scan->uuid,
                    'provider_order_id'  => $scan->provider_order_id,
                    'estado'             => $scan->estado,
                    'error'              => $scan->error,
                    'cantidad_articulos' => isset($contexto['cantidad_articulos']) ? (int) $contexto['cantidad_articulos'] : 0,
                    'provider_nombre'    => $provider_nombre,
                ],
            ]));

        } catch (\Throwable $e) {
            Log::error('RunProviderOrderScanJob: no se pudo notificar el fin del escaneo', [
                'provider_order_scan_id' => $scan->id,
                'message'                => $e->getMessage(),
            ]);
        }
    }

    /**
     * Se ejecuta cuando el job falla de forma no controlada (el worker lo mata por timeout,
     * error fatal de PHP) y nunca llega a su propio catch dentro de handle(). Sin esto, el
     * escaneo quedaría en "procesando" para siempre y el modal giraría sin fin esperando un
     * estado que no va a cambiar nunca.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        Log::error('RunProviderOrderScanJob: failed()', [
            'provider_order_scan_id' => $this->provider_order_scan_id,
            'message'                => $exception->getMessage(),
            'trace'                  => $exception->getTraceAsString(),
        ]);

        $scan = ProviderOrderScan::find($this->provider_order_scan_id);

        /* Si por alguna carrera rarísima ya quedó "listo", no lo pisamos con error. */
        if (!is_null($scan) && $scan->estado !== 'listo') {
            $this->finalizar_con_error($scan, 'El escaneo falló inesperadamente: ' . $exception->getMessage());
        }
    }
}
