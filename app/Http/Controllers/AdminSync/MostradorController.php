<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\MostradorMemoria;
use App\Models\MostradorReporte;
use App\Models\User;
use App\Services\Mostrador\MostradorContenidoValidator;
use App\Services\Mostrador\RecolectorDeHechos;
use Carbon\Carbon;
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
     * para ese dueño y esa fecha (default: ayer para dia/tienda, hoy para compras/stock)
     * y hace upsert en mostrador_reportes por la unique (user_id, tipo, fecha) SIN
     * pisar el contenido. Si el informe ya está 'listo' y no viene forzar, devuelve los
     * hechos guardados sin recalcular.
     *
     * 404 si el dueño no existe o no tiene la extensión; 422 si el tipo no es uno de
     * los cuatro o la fecha no es Y-m-d.
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

        $fecha = null;

        if (!is_null($request->input('fecha')) && $request->input('fecha') !== '') {
            $fecha = $this->parsear_fecha($request->input('fecha'));

            if (is_null($fecha)) {
                return response()->json(['message' => 'La fecha tiene que venir en formato Y-m-d.'], 422);
            }
        }

        $owner = $this->dueno_con_extension($request->input('user_id'));

        if (is_null($owner)) {
            return response()->json(['message' => 'Dueño no encontrado o sin la extensión ' . self::EXTENSION . '.'], 404);
        }

        if (is_null($fecha)) {
            $fecha = RecolectorDeHechos::fecha_por_defecto($tipo);
        }

        $forzar = filter_var($request->input('forzar', false), FILTER_VALIDATE_BOOLEAN);

        $reporte = MostradorReporte::where('user_id', $owner->id)
            ->where('tipo', $tipo)
            ->where('fecha', $fecha->format('Y-m-d'))
            ->first();

        if ($reporte && $reporte->estado === MostradorReporte::ESTADO_LISTO && !$forzar) {
            return response()->json($this->respuesta_hechos($reporte), 200);
        }

        try {
            $hechos = (new RecolectorDeHechos())->recolectar($owner, $tipo, $fecha);
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

        if ($reporte) {
            // Solo los hechos: titulo/resumen/contenido/estado son de la skill y no se tocan.
            $reporte->hechos = $hechos;
            $reporte->hechos_at = now();
            $reporte->save();
        } else {
            $reporte = MostradorReporte::create([
                'user_id'   => $owner->id,
                'tipo'      => $tipo,
                'fecha'     => $fecha->format('Y-m-d'),
                'hechos'    => $hechos,
                'estado'    => MostradorReporte::ESTADO_HECHOS,
                'hechos_at' => now(),
            ]);
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
     * Forma de la respuesta de POST hechos.
     *
     * @param MostradorReporte $reporte
     * @return array
     */
    protected function respuesta_hechos(MostradorReporte $reporte): array
    {
        return [
            'reporte_id' => (int) $reporte->id,
            'tipo'       => $reporte->tipo,
            'fecha'      => $reporte->fecha->format('Y-m-d'),
            'estado'     => $reporte->estado,
            'hechos'     => $reporte->hechos,
        ];
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

        $fecha = Carbon::createFromFormat('Y-m-d', $valor);

        if ($fecha === false || $fecha->format('Y-m-d') !== $valor) {
            return null;
        }

        return $fecha->startOfDay();
    }
}
