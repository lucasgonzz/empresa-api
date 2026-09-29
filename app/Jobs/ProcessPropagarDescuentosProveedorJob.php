<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Http\Controllers\Helpers\article\ArticleProviderDiscountHelper;
use App\Models\Provider;
use App\Models\User;
use App\Notifications\GlobalNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Propaga los descuentos de la ficha de un proveedor a sus articulos EN SEGUNDO PLANO (mision
 * recalculo-precios-motor-rapido, seguimiento del 29/9/2026).
 *
 * Es la misma operacion que `PUT provider/{id}/propagar-descuentos` hacia siempre adentro del
 * request (ArticleProviderDiscountHelper::propagar_a_articulos()), para cuando son muchos articulos.
 * La decision la toma ArticleProviderDiscountHelper::propagar_o_encolar(): arriba del tamaño de una
 * tanda del motor, viene aca; con esa cantidad o menos, sigue en el request como siempre.
 *
 * 🔴 POR QUE. En Servian (preferencia prendida) editar un descuento de Rejovot (40.393 articulos) o
 * de ETMAN (64.662) son 41 a 65 tandas del motor adentro de un HTTP. Con el `max_execution_time`
 * de 120 s del VPS la propagacion se puede cortar a la mitad, con parte del catalogo con los
 * descuentos nuevos y parte no, sin que nadie sepa cuales.
 *
 * Mismo molde que ProcessSincronizarDescuentosProveedorJob, que es la convencion del repo:
 *   - al job van ESCALARES, nunca modelos Eloquent;
 *   - `$timeout` y `$tries` explicitos; cola 'excel' en el shared y 'default' en el VPS;
 *   - `catch (\Throwable)` que loguea y RE-LANZA, MAS un `failed()`: cuando el proceso muere por OOM
 *     o por el kill del LVE de CloudLinux nunca llega al catch, y `failed()` corre en un proceso
 *     fresco;
 *   - al terminar (bien o mal) se avisa con GlobalNotification.
 *
 * Dos diferencias a proposito con la sincronizacion:
 *
 *   - El registro visible nace al ENCOLAR (anunciar(), llamado desde el request) y viaja por id
 *     (`$background_process_id`), no por referencia al proveedor. Si fuera por referencia, una
 *     sincronizacion y una propagacion del mismo proveedor corriendo a la vez se cerrarian el
 *     registro una a la otra: la sincronizacion cierra el suyo con por_referencia($provider), que
 *     devuelve el activo mas nuevo de ese proveedor. Por id, cada uno cierra el suyo.
 *   - Los price_changes quedan a nombre de la persona que confirmo la ventana (`$auth_user_id`), lo
 *     mismo que pasaba con la propagacion en el request (alla lo resolvia la sesion). En el worker
 *     no hay sesion: sin pasarlo explicito quedarian a nombre de config('app.USER_ID').
 *
 * 🔴 EN EL WORKER NO HAY SESION NI `Auth::user()`. El dueño viaja explicito y es el que decide la
 * preferencia `aplicar_descuentos_proveedor_al_asignar`: resolverla sola daria false siempre (el
 * pozo documentado en MasiveUpdateHelper y en ProcessRow) y la propagacion no haria nada, sin un
 * solo error que lo delate.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
class ProcessPropagarDescuentosProveedorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Mismo tope que la sincronizacion, el export y la masiva. Con el motor en bloque un proveedor
     * de 65.000 articulos son minutos, no una hora; el tope esta para que un worker colgado no quede
     * tomado para siempre.
     *
     * @var int
     */
    public $timeout = 3600;

    /**
     * Un solo intento. Reintentar sola una propagacion que murio a la mitad es seguro (clasifica de
     * nuevo y solo toca lo que siga desactualizado), pero lo decide el usuario: el aviso de error le
     * dice que puede volver a intentarlo. Mismo criterio que la sincronizacion.
     *
     * @var int
     */
    public $tries = 1;

    /** @var int */
    protected $provider_id;

    /**
     * Comercio dueño de los datos: scopea el proveedor, decide la preferencia y recibe el aviso.
     *
     * @var int
     */
    protected $owner_user_id;

    /**
     * Persona que confirmo la ventana (dueño o empleado): queda en los price_changes y es la unica
     * que ve el aviso.
     *
     * @var int
     */
    protected $auth_user_id;

    /** @var bool */
    protected $pisar_editados_a_mano;

    /**
     * Identificador de ESTA corrida, generado en el request. El `handle()` y el `failed()` corren en
     * procesos distintos y los dos lo ven: es lo que permite que el aviso de error salga una sola vez.
     *
     * @var string
     */
    protected $operacion_id;

    /**
     * El registro visible (background_processes) abierto al encolar, en `pendiente`. Viaja
     * serializado: el `failed()` de otra instancia lo encuentra por aca. Null si el registro no se
     * pudo crear (el helper no tira nunca); en ese caso handle() abre uno propio.
     *
     * @var int|null
     */
    public $background_process_id = null;

    /**
     * @param int      $provider_id
     * @param int      $owner_user_id
     * @param int      $auth_user_id
     * @param bool     $pisar_editados_a_mano
     * @param string   $operacion_id
     * @param int|null $background_process_id  El de anunciar().
     */
    public function __construct(
        $provider_id,
        $owner_user_id,
        $auth_user_id,
        $pisar_editados_a_mano,
        $operacion_id,
        $background_process_id = null
    ) {
        $this->provider_id           = (int) $provider_id;
        $this->owner_user_id         = (int) $owner_user_id;
        $this->auth_user_id          = (int) $auth_user_id;
        $this->pisar_editados_a_mano = (bool) $pisar_editados_a_mano;
        $this->operacion_id          = (string) $operacion_id;
        $this->background_process_id = is_null($background_process_id) ? null : (int) $background_process_id;

        /*
         * En shared hosting va a la cola 'excel' (la de los jobs pesados), para no retener el worker
         * del asistente. En el VPS, null: 'default', la unica que el supervisor de cada cliente
         * consume hoy.
         *
         * 🔴 Va en el constructor (propiedad publica $queue del trait Queueable), no como metodo
         * viaQueue(): ese hook de Laravel es solo para event listeners en cola, nunca se invoca para
         * Jobs (ver tests/Feature/Infraestructura/2_Jobs_pesados_cola_excel_en_shared_Test.php).
         */
        $this->queue = config('app.VPS') ? null : 'excel';
    }

    /**
     * Abre el registro visible en `pendiente`, en el request, al encolar. Nunca tira: si el registro
     * no se pudo crear devuelve null y la propagacion sale igual (handle() abre uno propio).
     *
     * El tipo es `sincronizar_descuentos` a proposito: es la misma familia de operacion (llevar los
     * descuentos de la ficha a los articulos) y la SPA ya le tiene icono y etiqueta.
     *
     * @param  \App\Models\Provider $provider
     * @param  int  $owner_user_id
     * @param  int  $auth_user_id
     * @param  bool $pisar_editados_a_mano
     * @param  int  $total  Articulos que se van a actualizar (los del plan del request).
     * @return int|null
     */
    public static function anunciar($provider, $owner_user_id, $auth_user_id, $pisar_editados_a_mano, $total)
    {
        $proceso = BackgroundProcessHelper::iniciar(
            $owner_user_id,
            'sincronizar_descuentos',
            self::titulo($provider),
            [
                'auth_user_id' => $auth_user_id,
                'total'        => (int) $total,
                'unidad'       => 'artículos',
                'status'       => 'pendiente',
                'etapa'        => 'En espera del procesador',
                'detalle'      => self::detalle($pisar_editados_a_mano),
            ]
        );

        return is_null($proceso) ? null : (int) $proceso->id;
    }

    /**
     * @return void
     */
    public function handle()
    {
        try {

            /*
             * El proveedor se busca scopeado al dueño, igual que en el controller: entre el click y
             * el worker pudo pasar un rato y el proveedor pudo haberse borrado. Sin el scope, un id
             * reciclado seria el catalogo de otro comercio.
             */
            $provider = Provider::where('id', $this->provider_id)
                                    ->where('user_id', $this->owner_user_id)
                                    ->first();

            if (is_null($provider)) {

                Log::warning('ProcessPropagarDescuentosProveedorJob: proveedor no encontrado', [
                    'provider_id'   => $this->provider_id,
                    'owner_user_id' => $this->owner_user_id,
                ]);

                /*
                 * El registro nacio al encolar: sin esto quedaria en `pendiente` para siempre (hasta
                 * que lo cierre cerrar_colgados() tres horas despues). No se toca nada mas.
                 */
                BackgroundProcessHelper::fallar(
                    $this->background_process_id,
                    'No se encontró el proveedor: se borró antes de que se pudieran actualizar sus artículos.'
                );

                return;
            }

            /*
             * El dueño, explicito: decide la preferencia (ver el docblock de la clase). Un dueño que
             * ya no existe da false en la preferencia y la propagacion no toca nada, como en el
             * request.
             */
            $owner_user = User::find($this->owner_user_id);

            $proceso_id = $this->retomar_o_abrir_registro($provider);

            $resultado = ArticleProviderDiscountHelper::propagar_a_articulos(
                $provider,
                $this->pisar_editados_a_mano,
                $owner_user,
                function ($procesados, $total) use ($proceso_id) {
                    // "X de Y articulos", una vez al arrancar y una por tanda escrita.
                    BackgroundProcessHelper::avanzar($proceso_id, $procesados, ['total' => $total]);
                },
                $this->auth_user_id
            );

            BackgroundProcessHelper::completar($proceso_id, [
                'actualizados' => (int) $resultado['actualizados'],
                'respetados'   => (int) $resultado['respetados'],
            ]);

            $this->avisar_que_termino($provider, $resultado, $owner_user);

        } catch (\Throwable $e) {

            Log::error('ProcessPropagarDescuentosProveedorJob: error al propagar', [
                'provider_id'   => $this->provider_id,
                'owner_user_id' => $this->owner_user_id,
                'auth_user_id'  => $this->auth_user_id,
                'message'       => $e->getMessage(),
                'archivo'       => $e->getFile(),
                'linea'         => $e->getLine(),
            ]);

            $this->avisar_que_fallo($e->getMessage());

            // Re-lanzamos: deja traza en failed_jobs y evita que el job se marque como exitoso.
            throw $e;
        }
    }

    /**
     * Corre cuando el job falla en forma definitiva, INCLUIDO cuando el proceso murio sin llegar al
     * catch del handle() (OOM, kill del LVE, timeout de proceso, worker reiniciado). Corre en un
     * proceso fresco, sobre otra instancia: encuentra el registro por el id que viajo serializado.
     *
     * @param  \Throwable|null $e
     * @return void
     */
    public function failed($e)
    {
        $motivo = !is_null($e) ? $e->getMessage() : null;

        $this->avisar_que_fallo($motivo);
    }

    /**
     * El registro visible, ya en `en_proceso`: el que nacio al encolar, o uno nuevo si no hay (un
     * job despachado sin anunciar(), o un registro que no se pudo crear).
     *
     * @param  \App\Models\Provider $provider
     * @return int|null  Id del registro (null si ni siquiera se pudo abrir: el helper no tira).
     */
    protected function retomar_o_abrir_registro($provider)
    {
        if (!is_null($this->background_process_id)) {

            $proceso = BackgroundProcessHelper::avanzar($this->background_process_id, 0, [
                'etapa'            => 'Actualizando los artículos',
                'forzar_broadcast' => true,
            ]);

            if (!is_null($proceso)) {
                return (int) $proceso->id;
            }
        }

        $proceso = BackgroundProcessHelper::iniciar(
            $this->owner_user_id,
            'sincronizar_descuentos',
            self::titulo($provider),
            [
                'auth_user_id' => $this->auth_user_id,
                'unidad'       => 'artículos',
                'etapa'        => 'Actualizando los artículos',
                'detalle'      => self::detalle($this->pisar_editados_a_mano),
            ]
        );

        $this->background_process_id = is_null($proceso) ? null : (int) $proceso->id;

        return $this->background_process_id;
    }

    /**
     * @param  \App\Models\Provider $provider
     * @return string
     */
    protected static function titulo($provider)
    {
        return 'Actualización de descuentos de ' . $provider->name;
    }

    /**
     * @param  bool $pisar_editados_a_mano
     * @return string
     */
    protected static function detalle($pisar_editados_a_mano)
    {
        return $pisar_editados_a_mano
            ? 'Incluye los artículos con descuentos editados a mano'
            : 'Sin tocar los artículos con descuentos editados a mano';
    }

    /**
     * Aviso final con el resumen, solo para la persona que confirmo la ventana.
     *
     * @param  \App\Models\Provider  $provider
     * @param  array                 $resultado
     * @param  \App\Models\User|null $owner_user
     * @return void
     */
    private function avisar_que_termino($provider, $resultado, $owner_user)
    {
        if (is_null($owner_user)) {
            return;
        }

        $parrafos = [
            $resultado['actualizados'] == 1
                ? '1 articulo actualizado'
                : $resultado['actualizados'] . ' articulos actualizados',
        ];

        if ($resultado['respetados']) {
            $parrafos[] = $resultado['respetados'] . ' con descuentos editados a mano quedaron como estaban';
        }

        $owner_user->notify(new GlobalNotification([
            'message_text'          => 'Se terminaron de actualizar los descuentos de ' . $provider->name,
            'color_variant'         => 'success',
            'functions_to_execute'  => [
                [
                    'btn_text'    => 'Entendido',
                    'btn_variant' => 'primary',
                ],
            ],
            'info_to_show'          => [
                [
                    'title'    => 'Resultado',
                    'parrafos' => $parrafos,
                ],
            ],
            'owner_id'              => $owner_user->id,
            'is_only_for_auth_user' => $this->auth_user_id,
        ]));
    }

    /**
     * Aviso de error, UNA sola vez, con el registro cerrado en fallo.
     *
     * El registro se cierra ANTES del candado de la cache: fallar() ya es idempotente por el status
     * de la fila, asi que llamarlo desde el catch y desde failed() no duplica nada, y cierra aunque
     * el driver de cache este caido. La notificacion, en cambio, no tiene estado propio: la
     * coordina `Cache::add()`, que devuelve true solo la primera vez que se escribe la clave (una
     * bandera de instancia no sobrevive el salto entre el handle() y el failed()).
     *
     * @param  string|null $motivo
     * @return void
     */
    private function avisar_que_fallo($motivo = null)
    {
        BackgroundProcessHelper::fallar(
            $this->background_process_id,
            is_null($motivo) || $motivo === ''
                ? 'La actualización de descuentos se interrumpió antes de terminar.'
                : $motivo
        );

        if (!Cache::add('propagar_descuentos_proveedor_fallo_' . $this->operacion_id, 1, 3600)) {
            return;
        }

        Log::warning('ProcessPropagarDescuentosProveedorJob: propagacion marcada como fallida', [
            'provider_id'   => $this->provider_id,
            'owner_user_id' => $this->owner_user_id,
            'operacion_id'  => $this->operacion_id,
            'motivo'        => is_null($motivo) || $motivo === ''
                ? 'El proceso se interrumpio sin dejar traza (probable falta de memoria, timeout del proceso o worker reiniciado).'
                : $motivo,
        ]);

        $owner_user = User::find($this->owner_user_id);

        if (is_null($owner_user)) {
            return;
        }

        /*
         * 🔴 Al usuario no le va el mensaje crudo de la excepcion (SQL, rutas del servidor): un texto
         * fijo, y el motivo tecnico al log. Mismo criterio que la sincronizacion. Lo que si le sirve
         * saber: que lo que se proceso quedo bien y que puede volver a intentarlo (la propagacion
         * clasifica de nuevo y solo toca lo que siga desactualizado).
         */
        $owner_user->notify(new GlobalNotification([
            'message_text'          => 'No se pudieron actualizar los descuentos de los articulos',
            'color_variant'         => 'danger',
            'functions_to_execute'  => [
                [
                    'btn_text'    => 'Entendido',
                    'btn_variant' => 'primary',
                ],
            ],
            'info_to_show'          => [
                [
                    'title'    => 'Que paso',
                    'parrafos' => [
                        'La actualizacion se interrumpio antes de terminar.',
                        'Los articulos que alcanzo a procesar quedaron con los descuentos nuevos del proveedor; el resto quedo como estaba.',
                        'Podes volver a intentarlo guardando el proveedor y confirmando la actualizacion. Si vuelve a pasar, avisanos.',
                    ],
                ],
            ],
            'owner_id'              => $owner_user->id,
            'is_only_for_auth_user' => $this->auth_user_id,
        ]));
    }
}
