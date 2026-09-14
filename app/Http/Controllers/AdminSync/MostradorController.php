<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\UserHelper;
use App\Jobs\CalcularHechosMostradorJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\MostradorMemoria;
use App\Models\MostradorReporte;
use App\Models\User;
use App\Services\Mostrador\MostradorContenidoValidator;
use App\Services\Mostrador\RecolectorDeHechos;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Endpoints del mostrador para la skill /mostrador (misión modulo-ia-mostrador),
 * bajo admin-sync/mostrador/* con el middleware admin.api.key (header
 * X-Admin-Api-Key contra services.admin_api.api_key, validado solo si
 * ADMIN_SYNC_REQUIRE_API_KEY=true — el mismo límite conocido de todo admin-sync).
 *
 * El reparto: este API calcula los HECHOS (RecolectorDeHechos, PHP determinista) y
 * guarda/sirve informes, conversaciones y memoria; el TEXTO de cada informe lo
 * redacta la skill desde Claude Code y lo deposita acá (PUT reportes/{id}), validado
 * por MostradorContenidoValidator.
 *
 * Todo se resuelve por el user_id del DUEÑO (owner_id null) con la extensión
 * asistente_ia; nunca por sesión (no hay).
 *
 * Compras y stock en un catálogo grande no se calculan adentro del request: por encima
 * de config('mostrador.umbral_async') artículos candidatos, POST hechos deja la fila en
 * 'calculando', despacha CalcularHechosMostradorJob y responde 202; la skill hace polling
 * con GET reportes/{id} hasta que el estado sea 'hechos' / 'listo' (o 'error', con el
 * motivo). Por debajo del umbral —y siempre para dia y tienda— el cálculo es sincrónico
 * y la respuesta es 200 con los hechos.
 */
class MostradorController extends Controller
{
    /** Slug de la extensión que gatea el módulo. */
    const EXTENSION = 'asistente_ia';

    /** Días hacia atrás de los informes no leídos que se informan en el contexto. */
    const DIAS_NO_LEIDOS = 7;

    /** Techo de mensajes nuevos que viajan en una respuesta de contexto. */
    const MAX_MENSAJES_CONTEXTO = 300;

    /** Techo del texto de la memoria. */
    const MAX_TEXTO_MEMORIA = 20000;

    /**
     * GET admin-sync/mostrador/duenos
     *
     * Dueños (owner_id null) con la extensión asistente_ia. Si la instancia tiene
     * app.USER_ID, solo ese: en las bases compartidas por varios comercios cada
     * instancia atiende al suyo, y devolver los demás haría que la skill los
     * calculara una vez por instancia.
     *
     * @return JsonResponse
     */
    public function duenos(): JsonResponse
    {
        $query = User::whereNull('owner_id')
            ->whereHas('extencions', function ($q) {
                $q->where('slug', self::EXTENSION);
            })
            ->orderBy('id');

        $user_id_instancia = config('app.USER_ID');

        if (!empty($user_id_instancia)) {
            $query->where('id', (int) $user_id_instancia);
        }

        $duenos = [];

        foreach ($query->get() as $owner) {
            $ultimo = MostradorReporte::where('user_id', $owner->id)
                ->listos()
                ->max('generado_at');

            $duenos[] = [
                'user_id'            => (int) $owner->id,
                'nombre'             => (string) ($owner->company_name ?: $owner->name),
                'email'              => (string) $owner->email,
                'tiene_tienda'       => trim((string) $owner->online) !== ''
                    || DB::table('orders')->where('user_id', $owner->id)->exists(),
                'sucursales'         => (int) DB::table('addresses')->where('user_id', $owner->id)->count(),
                'ultimo_reporte_at'  => is_null($ultimo) ? null : Carbon::parse($ultimo)->toDateTimeString(),
            ];
        }

        return response()->json(['duenos' => $duenos], 200);
    }

    /**
     * POST admin-sync/mostrador/hechos
     *
     * Body: {user_id, tipo, fecha?: 'Y-m-d', forzar?: bool}. Calcula los hechos del tipo
     * para ese dueño y esa fecha y hace upsert en mostrador_reportes por la unique
     * (user_id, tipo, fecha) SIN pisar el contenido. Si el informe ya está 'listo' y no
     * viene forzar, devuelve los hechos guardados sin recalcular.
     *
     * La fecha: para compras y stock es SIEMPRE hoy (la reposición y los traslados se
     * deciden con el stock de esta mañana); si el body trae otra, se ignora y la
     * respuesta lo avisa con "fecha_ignorada": true. Para dia y tienda es ayer por
     * defecto y tiene que ser un día cerrado: hoy o más adelante es 422.
     *
     * Respuestas:
     *   200 {reporte_id, tipo, fecha, estado, hechos, hechos_at, error_mensaje, fecha_ignorada}
     *       con los hechos calculados (o los guardados si ya estaba listo);
     *   202 lo mismo con estado 'calculando' y hechos null: el cálculo quedó en la cola
     *       (CalcularHechosMostradorJob) porque el catálogo supera mostrador.umbral_async;
     *       se consulta con GET reportes/{reporte_id}. Un POST repetido mientras está
     *       calculando responde 202 sin despachar otro job, salvo que la fila lleve más
     *       de mostrador.timeout_job segundos colgada (worker caído): ahí se vuelve a
     *       despachar;
     *   404 si el dueño no existe o no tiene la extensión; 422 si el tipo no es uno de
     *       los cuatro, la fecha no es Y-m-d válida o el día no está cerrado; 500 si el
     *       cálculo sincrónico reventó.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function hechos(Request $request): JsonResponse
    {
        $tipo = $request->input('tipo');

        if (!MostradorReporte::es_tipo_valido($tipo)) {
            return response()->json([
                'message' => 'El tipo tiene que ser uno de: ' . implode(', ', MostradorReporte::TIPOS) . '.',
            ], 422);
        }

        $fecha_pedida = null;

        if (!is_null($request->input('fecha')) && $request->input('fecha') !== '') {
            $fecha_pedida = $this->parsear_fecha($request->input('fecha'));

            if (is_null($fecha_pedida)) {
                return response()->json(['message' => 'La fecha tiene que venir en formato Y-m-d y ser un día válido.'], 422);
            }
        }

        $owner = $this->dueno_con_extension($request->input('user_id'));

        if (is_null($owner)) {
            return response()->json(['message' => 'Dueño no encontrado o sin la extensión ' . self::EXTENSION . '.'], 404);
        }

        $hoy = now()->startOfDay();
        $fecha_ignorada = false;

        if (RecolectorDeHechos::es_de_hoy($tipo)) {
            $fecha = RecolectorDeHechos::fecha_por_defecto($tipo);
            $fecha_ignorada = !is_null($fecha_pedida) && !$fecha_pedida->isSameDay($fecha);
        } else {
            $fecha = is_null($fecha_pedida) ? RecolectorDeHechos::fecha_por_defecto($tipo) : $fecha_pedida;

            if ($fecha->gte($hoy)) {
                return response()->json([
                    'message' => 'El día tiene que estar cerrado: un informe de ' . $tipo . ' habla de ayer o de un día anterior, no de hoy.',
                ], 422);
            }
        }

        $extra = ['fecha_ignorada' => $fecha_ignorada];

        $forzar = filter_var($request->input('forzar', false), FILTER_VALIDATE_BOOLEAN);

        $reporte = $this->buscar_reporte($owner, $tipo, $fecha);

        if ($reporte && $reporte->estado === MostradorReporte::ESTADO_LISTO && !$forzar) {
            return response()->json($this->respuesta_hechos($reporte, $extra), 200);
        }

        // Ya hay un cálculo en la cola para esta fila: se informa, no se duplica.
        if ($reporte && $reporte->esta_calculando() && !$this->calculo_vencido($reporte)) {
            return response()->json($this->respuesta_hechos($reporte, $extra), 202);
        }

        $recolector = (new RecolectorDeHechos())->recolector_para($tipo);

        if ($this->va_a_la_cola($recolector->cantidad_de_candidatos($owner))) {
            $reporte = $this->marcar_calculando($owner, $tipo, $fecha, $reporte);

            CalcularHechosMostradorJob::dispatch($reporte->id);

            return response()->json($this->respuesta_hechos($reporte, $extra), 202);
        }

        // Cálculo sincrónico. Se levanta el techo de PHP (y SOLO el de PHP, como en
        // DemoSetupHelper: el del proxy no se toca desde acá) para el caso que quedó por
        // debajo del umbral pero igual tarda.
        set_time_limit(0);

        try {
            $hechos = $recolector->recolectar($owner, $fecha->copy()->startOfDay());
        } catch (\Throwable $e) {
            Log::error('AdminSync\MostradorController: falló el cálculo de hechos', [
                'user_id' => $owner->id,
                'tipo'    => $tipo,
                'fecha'   => $fecha->format('Y-m-d'),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'No se pudieron calcular los hechos: ' . $e->getMessage(),
            ], 500);
        }

        $reporte = $this->guardar_hechos($owner, $tipo, $fecha, $reporte, $hechos);

        return response()->json($this->respuesta_hechos($reporte, $extra), 200);
    }

    /**
     * GET admin-sync/mostrador/reportes/{id}
     *
     * El estado y los hechos de un informe, para el polling de la skill después de un
     * 202: {reporte_id, tipo, fecha, estado, hechos, hechos_at, error_mensaje}. Mientras
     * el estado es 'calculando' los hechos viajan null; en 'error', error_mensaje dice por
     * qué. 404 si el informe no existe.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function mostrar($id): JsonResponse
    {
        $reporte = MostradorReporte::find($id);

        if (is_null($reporte)) {
            return response()->json(['message' => 'Informe no encontrado.'], 404);
        }

        return response()->json($this->respuesta_hechos($reporte), 200);
    }

    /**
     * PUT admin-sync/mostrador/reportes/{id}
     *
     * Body: {titulo, resumen, contenido}. Valida el contenido con
     * MostradorContenidoValidator, guarda y deja el informe 'listo' (visible para el
     * dueño) con generado_at = ahora. 422 con errores[] si el JSON no cumple; 404 si
     * el informe no existe.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function depositar(Request $request, $id): JsonResponse
    {
        $reporte = MostradorReporte::find($id);

        if (is_null($reporte)) {
            return response()->json(['message' => 'Informe no encontrado.'], 404);
        }

        $errores = [];

        $titulo = $request->input('titulo');
        $resumen = $request->input('resumen');

        if (!is_string($titulo) || trim($titulo) === '' || mb_strlen($titulo) > 120) {
            $errores[] = 'titulo: es obligatorio y tiene que ser un texto de 1 a 120 caracteres';
        }

        if (!is_string($resumen) || trim($resumen) === '' || mb_strlen($resumen) > 300) {
            $errores[] = 'resumen: es obligatorio y tiene que ser un texto de 1 a 300 caracteres';
        }

        $contenido = $request->input('contenido');
        $contenido_legible = true;

        // El contenido puede venir como objeto JSON o como string JSON (el script de la
        // skill arma el body desde un archivo): las dos formas se aceptan.
        if (is_string($contenido)) {
            $decodificado = json_decode($contenido, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $errores[] = 'contenido: no es un JSON válido (' . json_last_error_msg() . ')';
                $contenido_legible = false;
            } else {
                $contenido = $decodificado;
            }
        }

        if ($contenido_legible) {
            $errores = array_merge($errores, (new MostradorContenidoValidator())->validar($contenido));
        }

        if (!empty($errores)) {
            return response()->json([
                'message' => 'El contenido no cumple el formato del mostrador.',
                'errores' => array_values($errores),
            ], 422);
        }

        $reporte->titulo = $titulo;
        $reporte->resumen = $resumen;
        $reporte->contenido = $contenido;
        $reporte->estado = MostradorReporte::ESTADO_LISTO;
        $reporte->generado_at = now();
        $reporte->save();

        return response()->json(['ok' => true, 'reporte_id' => (int) $reporte->id], 200);
    }

    /**
     * GET admin-sync/mostrador/contexto/{user_id}?desde_ai_message_id=
     *
     * Lo que la skill necesita saber del dueño antes de redactar: su memoria, los
     * mensajes nuevos de las conversaciones sobre sus informes (id > desde; default
     * el hasta_ai_message_id de la memoria) y los informes listos que todavía no
     * abrió, de los últimos 7 días. 404 si el dueño no existe.
     *
     * @param Request $request
     * @param int $user_id
     * @return JsonResponse
     */
    public function contexto(Request $request, $user_id): JsonResponse
    {
        $owner = $this->dueno($user_id);

        if (is_null($owner)) {
            return response()->json(['message' => 'Dueño no encontrado.'], 404);
        }

        $memoria = MostradorMemoria::where('user_id', $owner->id)->first();

        $desde = $request->query('desde_ai_message_id');

        if (is_null($desde) || $desde === '' || !is_numeric($desde)) {
            $desde = $memoria && !is_null($memoria->hasta_ai_message_id) ? (int) $memoria->hasta_ai_message_id : 0;
        }

        $desde = max(0, (int) $desde);

        return response()->json([
            'memoria' => is_null($memoria) ? null : [
                'texto'               => $memoria->texto,
                'hasta_ai_message_id' => $memoria->hasta_ai_message_id,
                'updated_at'          => is_null($memoria->updated_at) ? null : $memoria->updated_at->toDateTimeString(),
            ],
            'conversaciones_nuevas' => $this->conversaciones_nuevas($owner, $desde),
            'no_leidos'             => $this->no_leidos($owner),
        ], 200);
    }

    /**
     * PUT admin-sync/mostrador/memoria/{user_id}
     *
     * Body: {texto, hasta_ai_message_id}. Upsert en mostrador_memorias. 404 si el
     * dueño no existe; 422 si el texto no es un string o el id no es un entero.
     *
     * @param Request $request
     * @param int $user_id
     * @return JsonResponse
     */
    public function memoria(Request $request, $user_id): JsonResponse
    {
        $owner = $this->dueno($user_id);

        if (is_null($owner)) {
            return response()->json(['message' => 'Dueño no encontrado.'], 404);
        }

        $texto = $request->input('texto');
        $hasta = $request->input('hasta_ai_message_id');

        if (!is_null($texto) && (!is_string($texto) || mb_strlen($texto) > self::MAX_TEXTO_MEMORIA)) {
            return response()->json([
                'message' => 'El texto tiene que ser un string de hasta ' . self::MAX_TEXTO_MEMORIA . ' caracteres, o null.',
            ], 422);
        }

        if (!is_null($hasta) && $hasta !== '' && (!is_numeric($hasta) || (int) $hasta < 0)) {
            return response()->json(['message' => 'hasta_ai_message_id tiene que ser un entero, o null.'], 422);
        }

        MostradorMemoria::updateOrCreate(
            ['user_id' => $owner->id],
            [
                'texto'               => $texto,
                'hasta_ai_message_id' => is_null($hasta) || $hasta === '' ? null : (int) $hasta,
            ]
        );

        return response()->json(['ok' => true], 200);
    }

    /**
     * Mensajes 'listo' con id > desde de las conversaciones con origen
     * mostrador_reporte sobre informes de este dueño, agrupados por informe.
     *
     * @param User $owner
     * @param int $desde
     * @return array
     */
    protected function conversaciones_nuevas(User $owner, int $desde): array
    {
        $conversaciones = AiConversation::where('user_id', $owner->id)
            ->where('origen', MostradorReporte::ORIGEN_CONVERSACION)
            ->whereNotNull('referencia_id')
            ->get(['id', 'referencia_id']);

        if ($conversaciones->isEmpty()) {
            return [];
        }

        $reporte_por_conversacion = [];

        foreach ($conversaciones as $conversacion) {
            $reporte_por_conversacion[(int) $conversacion->id] = (int) $conversacion->referencia_id;
        }

        $mensajes = AiMessage::whereIn('ai_conversation_id', array_keys($reporte_por_conversacion))
            ->where('id', '>', $desde)
            ->where('estado', 'listo')
            ->whereNotNull('contenido')
            ->orderBy('id')
            ->limit(self::MAX_MENSAJES_CONTEXTO)
            ->get(['id', 'ai_conversation_id', 'rol', 'contenido', 'created_at']);

        if ($mensajes->isEmpty()) {
            return [];
        }

        $reportes = MostradorReporte::where('user_id', $owner->id)
            ->whereIn('id', array_values(array_unique($reporte_por_conversacion)))
            ->get(['id', 'tipo', 'fecha', 'titulo'])
            ->keyBy('id');

        $grupos = [];

        foreach ($mensajes as $mensaje) {
            $reporte_id = $reporte_por_conversacion[(int) $mensaje->ai_conversation_id];

            // La conversación apunta a un informe que ya no es de este dueño (o no existe): se saltea.
            if (!isset($reportes[$reporte_id])) {
                continue;
            }

            if (!isset($grupos[$reporte_id])) {
                $reporte = $reportes[$reporte_id];

                $grupos[$reporte_id] = [
                    'reporte_id' => (int) $reporte->id,
                    'tipo'       => $reporte->tipo,
                    'fecha'      => $reporte->fecha->format('Y-m-d'),
                    'titulo'     => $reporte->titulo,
                    'mensajes'   => [],
                ];
            }

            $grupos[$reporte_id]['mensajes'][] = [
                'id'         => (int) $mensaje->id,
                'rol'        => $mensaje->rol,
                'contenido'  => $mensaje->contenido,
                'created_at' => is_null($mensaje->created_at) ? null : $mensaje->created_at->toDateTimeString(),
            ];
        }

        return array_values($grupos);
    }

    /**
     * Informes 'listo' sin abrir de los últimos 7 días, del más nuevo al más viejo.
     *
     * @param User $owner
     * @return array
     */
    protected function no_leidos(User $owner): array
    {
        $reportes = MostradorReporte::where('user_id', $owner->id)
            ->listos()
            ->whereNull('leido_at')
            ->where('fecha', '>=', now()->subDays(self::DIAS_NO_LEIDOS)->format('Y-m-d'))
            ->orderByDesc('fecha')
            ->orderBy('tipo')
            ->get(['id', 'tipo', 'fecha', 'titulo', 'resumen']);

        $lista = [];

        foreach ($reportes as $reporte) {
            $lista[] = [
                'reporte_id' => (int) $reporte->id,
                'tipo'       => $reporte->tipo,
                'fecha'      => $reporte->fecha->format('Y-m-d'),
                'titulo'     => $reporte->titulo,
                'resumen'    => $reporte->resumen,
            ];
        }

        return $lista;
    }

    /**
     * La fila de un informe por (dueño, tipo, fecha), o null.
     *
     * @param User $owner
     * @param string $tipo
     * @param Carbon $fecha
     * @return MostradorReporte|null
     */
    protected function buscar_reporte(User $owner, string $tipo, Carbon $fecha)
    {
        return MostradorReporte::where('user_id', $owner->id)
            ->where('tipo', $tipo)
            ->where('fecha', $fecha->format('Y-m-d'))
            ->first();
    }

    /**
     * true si el cálculo de un tipo con esa cantidad de artículos candidatos tiene que ir
     * a la cola: por encima de config('mostrador.umbral_async'). Los tipos que no
     * recorren catálogo (dia, tienda) traen 0 y nunca van.
     *
     * @param int $candidatos
     * @return bool
     */
    protected function va_a_la_cola(int $candidatos): bool
    {
        return $candidatos > (int) config('mostrador.umbral_async', 2000);
    }

    /**
     * true si una fila en 'calculando' lleva más de mostrador.timeout_job segundos sin
     * novedad: el job que la tenía murió sin cerrarla (worker caído) y hay que volver a
     * despacharla en vez de responder 202 para siempre.
     *
     * @param MostradorReporte $reporte
     * @return bool
     */
    protected function calculo_vencido(MostradorReporte $reporte): bool
    {
        if (is_null($reporte->updated_at)) {
            return true;
        }

        return $reporte->updated_at->copy()->addSeconds((int) config('mostrador.timeout_job', 1800))->isPast();
    }

    /**
     * Deja la fila lista para que CalcularHechosMostradorJob la tome: la crea si no
     * existe, y la marca 'calculando' sin tocar titulo/resumen/contenido (que son de la
     * skill) ni los hechos anteriores (el job los reemplaza cuando termina).
     *
     * @param User $owner
     * @param string $tipo
     * @param Carbon $fecha
     * @param MostradorReporte|null $reporte
     * @return MostradorReporte
     */
    protected function marcar_calculando(User $owner, string $tipo, Carbon $fecha, $reporte): MostradorReporte
    {
        if (is_null($reporte)) {
            $reporte = $this->crear_fila($owner, $tipo, $fecha);
        }

        $reporte->estado = MostradorReporte::ESTADO_CALCULANDO;
        $reporte->error_mensaje = null;
        $reporte->save();

        return $reporte;
    }

    /**
     * Crea la fila de un informe (sin hechos todavía), atajando la carrera por la
     * unique (user_id, tipo, fecha): si dos POST del mismo informe entran a la vez, el
     * segundo INSERT choca contra el primero; en vez de un 500 se relee la fila que ganó
     * y se sigue sobre ella.
     *
     * @param User $owner
     * @param string $tipo
     * @param Carbon $fecha
     * @return MostradorReporte
     */
    protected function crear_fila(User $owner, string $tipo, Carbon $fecha): MostradorReporte
    {
        try {
            return MostradorReporte::create([
                'user_id' => $owner->id,
                'tipo'    => $tipo,
                'fecha'   => $fecha->format('Y-m-d'),
                'estado'  => MostradorReporte::ESTADO_HECHOS,
            ]);
        } catch (QueryException $e) {
            $reporte = $this->buscar_reporte($owner, $tipo, $fecha);

            // No era la unique: el error es otro y tiene que subir.
            if (is_null($reporte)) {
                throw $e;
            }

            return $reporte;
        }
    }

    /**
     * Upsert de los hechos calculados en el request: solo hechos, hechos_at y el estado
     * ('listo' se conserva si la fila ya tenía texto depositado; si no, 'hechos'); titulo,
     * resumen y contenido son de la skill y no se tocan.
     *
     * @param User $owner
     * @param string $tipo
     * @param Carbon $fecha
     * @param MostradorReporte|null $reporte
     * @param array $hechos
     * @return MostradorReporte
     */
    protected function guardar_hechos(User $owner, string $tipo, Carbon $fecha, $reporte, array $hechos): MostradorReporte
    {
        if (is_null($reporte)) {
            $reporte = $this->crear_fila($owner, $tipo, $fecha);
        }

        $reporte->hechos = $hechos;
        $reporte->hechos_at = now();
        $reporte->error_mensaje = null;
        $reporte->estado = is_null($reporte->contenido)
            ? MostradorReporte::ESTADO_HECHOS
            : MostradorReporte::ESTADO_LISTO;
        $reporte->save();

        return $reporte;
    }

    /**
     * Forma de la respuesta de POST hechos y de GET reportes/{id}. Mientras el estado es
     * 'calculando' los hechos viajan null aunque la fila conserve los de un cálculo
     * anterior: la skill tiene que esperar los nuevos, no redactar sobre los viejos.
     *
     * @param MostradorReporte $reporte
     * @param array $extra Claves que se suman (fecha_ignorada en el POST)
     * @return array
     */
    protected function respuesta_hechos(MostradorReporte $reporte, array $extra = []): array
    {
        return array_merge([
            'reporte_id'    => (int) $reporte->id,
            'tipo'          => $reporte->tipo,
            'fecha'         => $reporte->fecha->format('Y-m-d'),
            'estado'        => $reporte->estado,
            'hechos'        => $reporte->esta_calculando() ? null : $reporte->hechos,
            'hechos_at'     => is_null($reporte->hechos_at) ? null : $reporte->hechos_at->toDateTimeString(),
            'error_mensaje' => $reporte->error_mensaje,
        ], $extra);
    }

    /**
     * El dueño (owner_id null) por id, o null.
     *
     * @param mixed $user_id
     * @return User|null
     */
    protected function dueno($user_id)
    {
        if (!is_numeric($user_id) || (int) $user_id <= 0) {
            return null;
        }

        return User::whereNull('owner_id')->find((int) $user_id);
    }

    /**
     * El dueño por id, solo si tiene la extensión del módulo (con las extensiones
     * cargadas, que es lo que mira UserHelper::hasExtencion).
     *
     * @param mixed $user_id
     * @return User|null
     */
    protected function dueno_con_extension($user_id)
    {
        $owner = $this->dueno($user_id);

        if (is_null($owner)) {
            return null;
        }

        $owner->load('extencions');

        if (!UserHelper::hasExtencion(self::EXTENSION, $owner)) {
            return null;
        }

        return $owner;
    }

    /**
     * Carbon desde un 'Y-m-d' exacto, o null si no cumple el formato.
     *
     * @param mixed $valor
     * @return Carbon|null
     */
    protected function parsear_fecha($valor)
    {
        if (!is_string($valor) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) !== 1) {
            return null;
        }

        // Un '2026-13-45' tiene la forma pero no es un día: según la versión de Carbon
        // vuelve desbordado (y no coincide con lo pedido) o tira InvalidFormatException.
        // Las dos cosas son "fecha inválida" y valen un 422, nunca un 500.
        try {
            $fecha = Carbon::createFromFormat('Y-m-d', $valor);
        } catch (\Throwable $e) {
            return null;
        }

        if ($fecha === false || $fecha->format('Y-m-d') !== $valor) {
            return null;
        }

        return $fecha->startOfDay();
    }
}
