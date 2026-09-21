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
 * Sincroniza los descuentos de la ficha de un proveedor a sus articulos, en segundo plano.
 *
 * 🔴 POR QUE EN COLA Y NO EN EL REQUEST. `propagar_a_articulos()` corre sincrona y el propio modal
 * de la SPA ya admite que "en un catalogo grande puede cortarse por tiempo". El modo "todos" es
 * mucho mas grande: alcanza TODOS los articulos del proveedor, no solo los que ya tienen descuentos
 * tagueados. En un proveedor de un comercio grande son miles, y cada uno lleva un DELETE, N INSERT
 * y un `setFinalPrice()`. En un request eso es un timeout garantizado, y —peor— un timeout A MITAD
 * DE CAMINO, con parte del catalogo sincronizado y parte no, sin nadie que sepa cual es cual.
 *
 * Patron copiado de `ProcessArticleExportJob`, que es la convencion del repo:
 *   - al job van ESCALARES, nunca modelos Eloquent;
 *   - `$timeout` y `$tries` explicitos;
 *   - `catch (\Throwable)` que loguea y RE-LANZA, MAS un `failed()`. Los dos hacen falta: cuando el
 *     proceso muere por OOM o por el kill del LVE de CloudLinux nunca llega al catch, y `failed()`
 *     corre en un proceso fresco;
 *   - al terminar, se avisa con `GlobalNotification`, que ya sale por `onConnection('sync')` (sin
 *     eso el aviso llega hasta 75 minutos tarde en el shared hosting; esta explicado en
 *     `GlobalNotification::toBroadcast()`).
 *
 * 🔴 EN EL WORKER NO HAY SESION NI `Auth::user()`. El owner viaja explicito y el helper le pasa
 * `$article->user_id` a `setFinalPrice()`. Es el pozo que ya esta documentado dos veces en
 * `MasiveUpdateHelper` y en `ProcessRow`: dejarlo resolver solo da `false` siempre, con la
 * funcionalidad muerta y sin un solo error que lo delate.
 */
class ProcessSincronizarDescuentosProveedorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Timeout amplio: el modo "todos" sobre un proveedor de miles de articulos hace un
     * `setFinalPrice()` por articulo. Mismo valor que el export y la masiva.
     *
     * @var int
     */
    public $timeout = 3600;

    /**
     * Un solo intento. Reintentar una sincronizacion que murio a la mitad la volveria a correr
     * entera sobre un catalogo que ya quedo parcialmente tocado, y el usuario no se enteraria.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * @var int
     */
    protected $provider_id;

    /**
     * Comercio dueño de los datos: es quien recibe el aviso y quien scopea el proveedor.
     *
     * @var int
     */
    protected $owner_user_id;

    /**
     * Usuario que apreto el boton (puede ser un empleado). Hace que el modal se abra solo en SU
     * sesion, igual que en el export.
     *
     * @var int
     */
    protected $auth_user_id;

    /**
     * @var string
     */
    protected $alcance;

    /**
     * @var bool
     */
    protected $pisar_editados_a_mano;

    /**
     * @var string
     */
    protected $accion_sobre_compras;

    /**
     * Identificador de ESTA corrida. Se genera en el controller y viaja serializado, asi que el
     * `handle()` y el `failed()` —que corren en procesos distintos— ven el mismo valor. Es lo que
     * permite que el aviso de error salga una sola vez.
     *
     * @var string
     */
    protected $operacion_id;

    /**
     * @param int    $provider_id
     * @param int    $owner_user_id
     * @param int    $auth_user_id
     * @param string $alcance
     * @param bool   $pisar_editados_a_mano
     * @param string $accion_sobre_compras
     * @param string $operacion_id
     */
    public function __construct(
        $provider_id,
        $owner_user_id,
        $auth_user_id,
        $alcance,
        $pisar_editados_a_mano,
        $accion_sobre_compras,
        $operacion_id
    ) {
        $this->provider_id           = (int) $provider_id;
        $this->owner_user_id         = (int) $owner_user_id;
        $this->auth_user_id          = (int) $auth_user_id;
        $this->alcance               = (string) $alcance;
        $this->pisar_editados_a_mano = (bool) $pisar_editados_a_mano;
        $this->accion_sobre_compras  = (string) $accion_sobre_compras;
        $this->operacion_id          = (string) $operacion_id;
    }

    /**
     * @return void
     */
    /**
     * En shared hosting va a la cola 'excel' (separada del asistente por WhatsApp/panel), para
     * que un import o export grande no retenga el mismo worker. En el VPS, null: sigue en
     * 'default', la única que el supervisor de cada cliente consume hoy — cambiar eso es un
     * cambio de infraestructura aparte, no de este job.
     *
     * @return string|null
     */
    public function viaQueue()
    {
        return config('app.VPS') ? null : 'excel';
    }

    public function handle()
    {
        try {

            /*
             * El proveedor se busca scopeado al owner, igual que en el controller: entre que se
             * apreto el boton y que el worker lo levanta puede haber pasado un rato largo y el
             * proveedor puede haberse borrado. Sin el scope, un id reciclado seria la produccion de
             * otro comercio.
             */
            $provider = Provider::where('id', $this->provider_id)
                                    ->where('user_id', $this->owner_user_id)
                                    ->first();

            if (is_null($provider)) {

                Log::warning('ProcessSincronizarDescuentosProveedorJob: proveedor no encontrado', [
                    'provider_id'   => $this->provider_id,
                    'owner_user_id' => $this->owner_user_id,
                ]);

                return;
            }

            /*
             * Registro visible (misión procesos-en-segundo-plano, 18/9/2026). Sin total: el
             * loop por artículo vive en ArticleProviderDiscountHelper::sincronizar_a_articulos()
             * y no expone avance, así que la barra es indeterminada y lo que se muestra es el
             * cierre con los números. La referencia es el proveedor: es lo que permite que
             * failed() —que corre sobre otra instancia— encuentre la misma fila.
             */
            BackgroundProcessHelper::iniciar(
                $this->owner_user_id,
                'sincronizar_descuentos',
                'Sincronización de descuentos de ' . $provider->name,
                [
                    'auth_user_id' => $this->auth_user_id,
                    'referencia'   => $provider,
                    'unidad'       => 'artículos',
                    'detalle'      => $this->alcance === ArticleProviderDiscountHelper::ALCANCE_TODOS
                        ? 'Todos los artículos del proveedor'
                        : 'Solo los artículos que ya tenían descuentos',
                    'etapa'        => 'Sincronizando los artículos',
                ]
            );

            $resultado = ArticleProviderDiscountHelper::sincronizar_a_articulos(
                $provider,
                $this->alcance,
                $this->pisar_editados_a_mano,
                $this->accion_sobre_compras
            );

            BackgroundProcessHelper::completar(BackgroundProcessHelper::por_referencia($provider), [
                'creados'      => (int) $resultado['creados'],
                'actualizados' => (int) $resultado['actualizados'],
                'al_dia'       => (int) $resultado['al_dia'],
            ]);

            $this->avisar_que_termino($provider, $resultado);

        } catch (\Throwable $e) {

            Log::error('ProcessSincronizarDescuentosProveedorJob: error al sincronizar', [
                'provider_id'   => $this->provider_id,
                'owner_user_id' => $this->owner_user_id,
                'auth_user_id'  => $this->auth_user_id,
                'alcance'       => $this->alcance,
                'accion'        => $this->accion_sobre_compras,
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
     * catch del handle() (OOM, kill del LVE de CloudLinux, timeout de proceso, worker reiniciado).
     * Corre en un proceso fresco: puede notificar aunque el anterior se haya quedado sin memoria.
     *
     * @param  \Throwable $e
     * @return void
     */
    public function failed($e)
    {
        $motivo = !is_null($e) ? $e->getMessage() : null;

        $this->avisar_que_fallo($motivo);
    }

    /**
     * Aviso final con el resumen de lo que hizo.
     *
     * @param  \App\Models\Provider $provider
     * @param  array $resultado
     * @return void
     */
    private function avisar_que_termino($provider, $resultado)
    {
        $owner_user = User::find($this->owner_user_id);

        if (is_null($owner_user)) {
            return;
        }

        $parrafos = [
            $resultado['creados'] . ' articulos sin descuentos recibieron los de la ficha',
            $resultado['actualizados'] . ' articulos actualizados',
            $resultado['al_dia'] . ' ya estaban al dia',
        ];

        if ($resultado['respetados']) {
            $parrafos[] = $resultado['respetados'] . ' con descuentos editados a mano quedaron como estaban';
        }

        if ($resultado['de_compra_salteados']) {
            $parrafos[] = $resultado['de_compra_salteados'] . ' con descuentos de compra o importacion se saltearon';
        }

        if ($resultado['de_compra_pisados']) {
            $parrafos[] = $resultado['de_compra_pisados'] . ' con descuentos de compra o importacion se reemplazaron por los de la ficha';
        }

        if ($resultado['de_compra_agregados']) {
            $parrafos[] = $resultado['de_compra_agregados'] . ' con descuentos de compra o importacion recibieron ademas los de la ficha';
        }

        $owner_user->notify(new GlobalNotification([
            'message_text'          => 'Se terminaron de sincronizar los descuentos de ' . $provider->name,
            'color_variant'         => 'success',
            'functions_to_execute'  => [
                [
                    'btn_text'    => 'Entendido',
                    'btn_variant' => 'primary',
                ],
            ],
            'info_to_show'          => [
                [
                    'title'    => 'Resultado de la sincronizacion',
                    'parrafos' => $parrafos,
                ],
            ],
            'owner_id'              => $owner_user->id,
            'is_only_for_auth_user' => $this->auth_user_id,
        ]));
    }

    /**
     * Aviso de error, UNA sola vez.
     *
     * ⚠️ Idempotencia con `Cache::add()`, que devuelve true solo la primera vez que se escribe esa
     * clave. Es la unica forma de coordinar el `catch` del handle() con el `failed()`, que corre en
     * otro proceso y sobre otra instancia del job deserializada del payload: una bandera de
     * instancia no sobrevive ese salto.
     *
     * A diferencia del export, aca no hay un `ExportHistory` con estado que sirva de candado, asi
     * que el candado es la cache. Si el driver de cache estuviera caido, el peor caso es un aviso
     * de error duplicado — bastante mejor que ninguno.
     *
     * @param  string|null $motivo
     * @return void
     */
    private function avisar_que_fallo($motivo = null)
    {
        /*
         * El registro visible se cierra ANTES del candado de la cache y no después: fallar() ya
         * es idempotente por el status de la fila, así que llamarlo desde el catch y desde
         * failed() no duplica nada, y así cierra aunque el driver de cache esté caído. Se busca
         * por el proveedor (la referencia con la que se abrió), scopeado al dueño igual que en
         * handle().
         */
        $provider = Provider::where('id', $this->provider_id)
                            ->where('user_id', $this->owner_user_id)
                            ->first();

        BackgroundProcessHelper::fallar(
            BackgroundProcessHelper::por_referencia($provider),
            is_null($motivo) || $motivo === ''
                ? 'La sincronización se interrumpió antes de terminar.'
                : $motivo
        );

        $clave = 'sincronizar_descuentos_proveedor_fallo_' . $this->operacion_id;

        if (!Cache::add($clave, 1, 3600)) {
            return;
        }

        /*
         * El motivo tecnico vive ACA y no en la notificacion. Cuando viene vacio es porque lo
         * disparo `failed()` sin excepcion capturable, que es el escenario tipico de la muerte
         * silenciosa: se deja escrito para que el que lea el log no crea que se perdio el dato.
         */
        Log::warning('ProcessSincronizarDescuentosProveedorJob: sincronizacion marcada como fallida', [
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
         * 🔴 AL USUARIO NO LE VA EL MENSAJE CRUDO DE LA EXCEPCION. De `$e->getMessage()` sale un
         * fragmento de SQL, un nombre de tabla o una ruta del servidor, y eso no le dice nada al
         * comerciante mientras le cuenta al que este mirando la pantalla como esta hecho el sistema
         * por dentro. El detalle tecnico ya quedo arriba, en el `Log::error` del handle() y en el
         * `Log::warning` de unas lineas mas arriba, que es donde se lo busca cuando hace falta.
         *
         * Mismo criterio que `BackgroundJobFailureHandler::marcar_export_fallido()`, que es el punto
         * unico de los exports: al usuario un texto fijo en español, el motivo tecnico al registro
         * interno.
         *
         * Lo que si le sirve saber, y por eso va: que puede reintentar, y que lo que ya se proceso
         * quedo bien. Sin esa segunda frase, lo razonable seria pensar que quedo todo a medias y
         * que hay que revisar el catalogo a mano.
         */
        $owner_user->notify(new GlobalNotification([
            'message_text'          => 'No se pudieron sincronizar los descuentos del proveedor',
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
                        'La sincronizacion se interrumpio antes de terminar y no se completo.',
                        'Los articulos que alcanzo a procesar quedaron con los descuentos nuevos de la ficha del proveedor; el resto quedo como estaba.',
                        'Podes volver a intentarlo desde el boton "Sincronizar articulos" de la ficha del proveedor. Si vuelve a pasar, avisanos.',
                    ],
                ],
            ],
            'owner_id'              => $owner_user->id,
            'is_only_for_auth_user' => $this->auth_user_id,
        ]));
    }
}
