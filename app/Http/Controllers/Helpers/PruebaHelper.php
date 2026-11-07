<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Article;
use App\Models\ExcelAnalysisRun;
use App\Models\PruebaConfig;
use App\Models\PruebaHito;
use App\Models\User;
use App\Services\AsistenteUnificadoDePrueba;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La prueba gratuita de 7 días, del lado de empresa (misión prueba-experiencia-empresa, 3/11/2026).
 *
 * Dos cosas distintas, a propósito:
 *
 * - El MODO PRUEBA: la fila de `prueba_config` del dueño, que el demo setup escribe cuando el admin manda el
 *   objeto `prueba` (C1). Con ella andan el modal de primer ingreso, el botón de importar, el borrado de lo
 *   sembrado al primer import y los hitos al admin. Solo cuenta en una instancia de prueba
 *   (`FOR_USER=prueba`, o local/testing): en los ~40 clientes reales `es_instancia_de_prueba()` da false y
 *   ninguno de los enganches paga una query.
 * - El VENCIMIENTO: `users.expired_at`, la columna que `UserHelper::checkUserTrial()` leía desde siempre sin
 *   que nadie la usara. Lo fija el demo setup (`prueba.vence_at`) y lo mueve el admin por C3; `null` lo saca
 *   (el negocio contrató) sin apagar el modo prueba, que sigue hasta que importe sus datos.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin operador nullsafe.
 */
class PruebaHelper
{
    /** Marcador de `.env` de una instancia de prueba (lo escribe el script de alta del admin). */
    const FOR_USER_PRUEBA = 'prueba';

    /** Largo mínimo de la contraseña que manda el alta (decisión de Lucas, 3/11/2026: la única regla). */
    const PASSWORD_MINIMO = 8;

    /** Los hitos de la prueba (C2). Cada uno se emite una sola vez, salvo `prueba.ingreso`. */
    const TIPOS_DE_HITO = [
        'prueba.ingreso',
        'prueba.importo',
        'prueba.articulo_creado',
        'prueba.venta',
        'prueba.cliente_creado',
        'prueba.pago_cc',
        'prueba.compra',
        'prueba.gasto',
        'prueba.tienda_pedido',
        'prueba.agente',
        'prueba.vencida',
    ];

    /** De dónde vino la primera importación (`datos.origen` de `prueba.importo`). */
    const ORIGENES_DE_IMPORTACION = ['excel', 'pdf', 'martin'];

    /** Lo que ve el negocio cuando la prueba venció (y lo que lleva el 403). */
    const MENSAJE_VENCIDA = 'Tu prueba terminó.';

    /** @var array<int, \App\Models\PruebaConfig|false> Config por dueño, memoizada por proceso. */
    private static $configs = [];

    /** @var array<int, \Carbon\Carbon|false> Vencimiento por dueño, memoizado por proceso. */
    private static $vencimientos = [];

    /** @var bool|null Si la tabla `prueba_config` existe, memoizado por proceso. */
    private static $tabla_disponible = null;

    /**
     * ¿En esta instalación puede haber una prueba gratuita? Solo con `FOR_USER=prueba` en el `.env` (lo
     * escribe el script de alta del admin; en local o en la suite se pone en la config para probarlo). Se
     * lee `config()` y NUNCA `env()`: con `config:cache` activo, `env()` fuera de `config/` devuelve null.
     *
     * Es la primera guarda de todos los enganches y del middleware, y no toca la base. Estricta a propósito:
     * `local` y `testing` NO cuentan, porque cada enganche pagaría una query por request en toda la suite
     * (medido: ImportHistoryIndexPaginationTest cuenta las consultas del índice y se puso rojo con una
     * más). Mismo criterio que `DemoSetupHelper::es_instancia_de_prueba()`.
     *
     * @return bool
     */
    public static function puede_haber_prueba()
    {
        return config('app.FOR_USER') === self::FOR_USER_PRUEBA;
    }

    /**
     * Vacía las memorias del proceso (los tests y el worker, que atiende varios jobs seguidos).
     *
     * @return void
     */
    public static function olvidar_cache()
    {
        self::$configs = [];
        self::$vencimientos = [];
        self::$tabla_disponible = null;
    }

    /**
     * ¿La base tiene las tablas de la prueba? (una instancia vieja, o la suite antes de migrar).
     *
     * @return bool
     */
    public static function tabla_disponible()
    {
        if (is_null(self::$tabla_disponible)) {
            try {
                self::$tabla_disponible = DB::connection()->getSchemaBuilder()->hasTable('prueba_config');
            } catch (\Throwable $e) {
                self::$tabla_disponible = false;
            }
        }

        return self::$tabla_disponible;
    }

    /**
     * El dueño de la instancia: `config('app.USER_ID')` o, sin él, el primer dueño de la base.
     *
     * @return \App\Models\User|null
     */
    public static function dueno()
    {
        $user_id = config('app.USER_ID');

        if (!empty($user_id)) {
            $dueno = User::whereNull('owner_id')->where('id', (int) $user_id)->first();

            if (!is_null($dueno)) {
                return $dueno;
            }
        }

        return User::whereNull('owner_id')->orderBy('id')->first();
    }

    /**
     * El dueño al que pertenece un usuario (él mismo, o su dueño si es empleado).
     *
     * @param \App\Models\User|null $user
     * @return int|null
     */
    public static function owner_id_de($user)
    {
        if (is_null($user)) {
            return null;
        }

        return !empty($user->owner_id) ? (int) $user->owner_id : (int) $user->id;
    }

    /**
     * La configuración de la prueba del dueño, o null si no la tiene (no es una prueba).
     *
     * @param int $owner_id
     * @return \App\Models\PruebaConfig|null
     */
    public static function config($owner_id)
    {
        $owner_id = (int) $owner_id;

        if (!array_key_exists($owner_id, self::$configs)) {
            self::$configs[$owner_id] = self::tabla_disponible()
                ? (PruebaConfig::where('owner_id', $owner_id)->first() ?: false)
                : false;
        }

        return self::$configs[$owner_id] === false ? null : self::$configs[$owner_id];
    }

    /**
     * ¿El dueño está en modo prueba? Instancia de prueba Y configuración cargada.
     *
     * @param int $owner_id
     * @return bool
     */
    public static function activa($owner_id)
    {
        return self::puede_haber_prueba() && !is_null(self::config($owner_id));
    }

    public static function tiene_periodo_de_prueba($owner_id): bool
    {
        return self::activa($owner_id) && !is_null(self::vence_at($owner_id));
    }

    public static function en_periodo_vigente($owner_id): bool
    {
        return self::activa($owner_id) && optional(self::vence_at($owner_id))->isFuture() === true;
    }

    /**
     * El hilo pertenece al dueño que pidió la prueba. Las sesiones de soporte y las cuentas de
     * empleados pueden trabajar en el sistema, pero no se hacen pasar por él en esta conversación.
     */
    public static function puede_usar_chat(User $user, Request $request): bool
    {
        return self::puede_haber_prueba()
            && self::es_sesion_web_del_dueno($user, $request)
            && self::en_periodo_vigente((int) $user->id);
    }

    /** La conversación trasladada sigue siendo privada aunque termine la prueba. */
    public static function es_sesion_web_del_dueno(User $user, Request $request): bool
    {
        if (!empty($user->owner_id) || !$request->hasSession()) {
            return false;
        }

        $sesion = $request->session();
        $dueno_web = $request->user('web');

        return $dueno_web !== null && (int) $dueno_web->id === (int) $user->id
            && !$sesion->has('acceso_soporte_token_id')
            && !$sesion->get('master_login_bypass_user_last_activity', false);
    }

    public static function error_catalogo_para_probar_agente(int $owner_id): ?array
    {
        if (SemillaRegistroHelper::semilla_presente($owner_id)) {
            return [
                'code' => 'catalogo_de_ejemplo',
                'message' => 'Importá tu catálogo para probar el agente con tus productos.',
            ];
        }

        // El sello se escribe al generar el vector, que no hace falta leer para validar la prueba.
        if (! Article::where('user_id', $owner_id)->where('status', 'active')->whereNotNull('embedding_generated_at')->exists()) {
            return [
                'code' => 'catalogo_sin_indexar',
                'message' => 'Todavía no hay artículos listos para responder. Si recién importaste tu catálogo, esperá unos minutos y volvé a probar.',
            ];
        }

        return null;
    }

    /**
     * Valida los campos de la prueba del payload del demo setup ANTES de vaciar nada (misma regla que
     * `demo_industria`: un admin anterior no los manda y el setup sigue igual).
     *
     * @param array $data Payload entero del demo setup.
     * @return void
     * @throws \Illuminate\Validation\ValidationException
     */
    public static function validar_payload(array $data)
    {
        if (array_key_exists('password', $data) && !is_null($data['password']) && (string) $data['password'] !== '') {
            if (!is_string($data['password']) || mb_strlen($data['password']) < self::PASSWORD_MINIMO) {
                throw ValidationException::withMessages([
                    'password' => ['La contraseña tiene que tener al menos ' . self::PASSWORD_MINIMO . ' caracteres.'],
                ]);
            }
        }

        if (!array_key_exists('prueba', $data) || is_null($data['prueba'])) {
            return;
        }

        if (!is_array($data['prueba'])) {
            throw ValidationException::withMessages([
                'prueba' => ['`prueba` tiene que ser un objeto con dias, vence_at, video_url y whatsapp_soporte.'],
            ]);
        }

        if (isset($data['prueba']['vence_at']) && is_null(self::parsear_fecha($data['prueba']['vence_at']))) {
            throw ValidationException::withMessages([
                'prueba.vence_at' => ['`vence_at` tiene que ser una fecha ISO 8601.'],
            ]);
        }

        if (isset($data['prueba']['dias']) && (!is_numeric($data['prueba']['dias']) || (int) $data['prueba']['dias'] < 1)) {
            throw ValidationException::withMessages([
                'prueba.dias' => ['`dias` tiene que ser un entero mayor a cero.'],
            ]);
        }
    }

    /**
     * Prende el modo prueba del dueño con lo que mandó el admin (C1): guarda la configuración y fija
     * `users.expired_at`. Sin `vence_at` pero con `dias`, vence a los `dias` días de ahora; sin ninguno de
     * los dos, no vence (el admin lo puede fijar después por C3).
     *
     * @param \App\Models\User $user Dueño recién creado.
     * @param array $prueba `{dias, vence_at, video_url, whatsapp_soporte}` (todas opcionales).
     * @return \App\Models\PruebaConfig
     */
    public static function activar_desde_payload(User $user, array $prueba)
    {
        $dias = isset($prueba['dias']) && is_numeric($prueba['dias']) ? (int) $prueba['dias'] : null;
        $vence_at = isset($prueba['vence_at']) ? self::parsear_fecha($prueba['vence_at']) : null;

        if (is_null($vence_at) && !is_null($dias)) {
            $vence_at = Carbon::now()->addDays($dias);
        }

        $config = PruebaConfig::updateOrCreate(
            ['owner_id' => (int) $user->id],
            [
                'dias'               => $dias,
                'video_url'          => self::texto_o_null(isset($prueba['video_url']) ? $prueba['video_url'] : null, 500),
                'whatsapp_soporte'   => self::texto_o_null(isset($prueba['whatsapp_soporte']) ? $prueba['whatsapp_soporte'] : null, 40),
                'modal_visto_at'     => null,
                'importacion_origen' => null,
                'semilla_borrada_at' => null,
            ]
        );

        self::fijar_vencimiento($user->id, $vence_at);
        self::olvidar_cache();

        return $config;
    }

    /**
     * El vencimiento del dueño (`users.expired_at`), o null si no tiene.
     *
     * @param int $owner_id
     * @return \Carbon\Carbon|null
     */
    public static function vence_at($owner_id)
    {
        $owner_id = (int) $owner_id;

        if (!array_key_exists($owner_id, self::$vencimientos)) {
            $crudo = DB::table('users')->where('id', $owner_id)->value('expired_at');
            self::$vencimientos[$owner_id] = is_null($crudo) ? false : Carbon::parse($crudo);
        }

        return self::$vencimientos[$owner_id] === false ? null : self::$vencimientos[$owner_id]->copy();
    }

    /**
     * ¿La prueba del dueño ya venció?
     *
     * @param int $owner_id
     * @return bool
     */
    public static function vencida($owner_id)
    {
        $vence_at = self::vence_at($owner_id);

        return !is_null($vence_at) && $vence_at->lte(Carbon::now());
    }

    /**
     * ¿La prueba del dueño de este usuario venció? Solo en una instancia de prueba: en un cliente real
     * `users.expired_at` no corta nada (nunca lo hizo), aunque alguien lo haya cargado a mano.
     *
     * @param \App\Models\User|null $user
     * @return bool
     */
    public static function vencida_para($user)
    {
        if (!self::puede_haber_prueba() || is_null($user)) {
            return false;
        }

        return self::vencida(self::owner_id_de($user));
    }

    /**
     * Fija (o saca, con null) el vencimiento del dueño. Es lo que hace C3.
     *
     * @param int $owner_id
     * @param \Carbon\Carbon|null $vence_at
     * @return void
     */
    public static function fijar_vencimiento($owner_id, $vence_at)
    {
        DB::table('users')->where('id', (int) $owner_id)->update([
            'expired_at' => is_null($vence_at) ? null : $vence_at->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
        ]);

        unset(self::$vencimientos[(int) $owner_id]);
    }

    /**
     * El WhatsApp de soporte que se le muestra al negocio cuando la prueba terminó.
     *
     * @param int $owner_id
     * @return string|null
     */
    public static function whatsapp_soporte($owner_id)
    {
        $config = self::config($owner_id);

        return is_null($config) ? null : $config->whatsapp_soporte;
    }

    /**
     * La respuesta 403 de la prueba vencida (middleware y login). Forma: `{error, message, whatsapp_soporte}`.
     *
     * @param int $owner_id
     * @return \Illuminate\Http\JsonResponse
     */
    public static function respuesta_vencida($owner_id)
    {
        $vence_at = self::vence_at($owner_id);

        return response()->json([
            'error'            => 'prueba_vencida',
            'message'          => self::MENSAJE_VENCIDA,
            'whatsapp_soporte' => self::whatsapp_soporte($owner_id),
            'vence_at'         => is_null($vence_at) ? null : self::iso($vence_at),
        ], 403);
    }

    /**
     * Deja registrado que el dueño ya vio el modal de primer ingreso.
     *
     * @param int $owner_id
     * @return void
     */
    public static function marcar_modal_visto($owner_id)
    {
        $config = self::config($owner_id);

        if (is_null($config) || !is_null($config->modal_visto_at)) {
            return;
        }

        $config->modal_visto_at = Carbon::now();
        $config->save();
    }

    /**
     * Deja registrado que se borró lo sembrado y de dónde vino la importación que lo disparó.
     *
     * @param int $owner_id
     * @param string|null $origen `excel` | `pdf` | `martin`
     * @return void
     */
    public static function marcar_semilla_borrada($owner_id, $origen)
    {
        $config = self::config($owner_id);

        if (is_null($config)) {
            return;
        }

        $config->semilla_borrada_at = Carbon::now();
        $config->importacion_origen = self::normalizar_origen($origen);
        $config->save();
    }

    /**
     * De dónde vino la primera importación del dueño (`excel` si nadie lo dijo).
     *
     * @param int $owner_id
     * @return string
     */
    public static function origen_de_importacion($owner_id)
    {
        $config = self::config($owner_id);

        if (is_null($config) || is_null($config->importacion_origen)) {
            return 'excel';
        }

        return self::normalizar_origen($config->importacion_origen);
    }

    /**
     * De dónde vino el archivo de un análisis de Excel que el dueño confirma importar: el admin (M4) lo deja
     * en `excel_analysis_runs.payload['origen']` cuando crea el análisis por admin-sync (`martin` o `pdf`);
     * un análisis que subió el propio dueño no lo trae y es `excel`. Se busca por `excel_path`, que es lo
     * que el import del dueño conoce del análisis.
     *
     * @param int $owner_id
     * @param string|null $excel_path Ruta relativa a storage/app, la misma que guarda el análisis.
     * @return string
     */
    public static function origen_del_analisis($owner_id, $excel_path)
    {
        $excel_path = is_scalar($excel_path) ? trim((string) $excel_path) : '';

        if ($excel_path === '') {
            return 'excel';
        }

        try {
            $analisis = ExcelAnalysisRun::where('user_id', (int) $owner_id)
                ->where('excel_path', $excel_path)
                // La recomendación usa la misma planilla, pero el origen viene de su análisis inicial.
                ->where('tipo', 'analisis')
                ->orderBy('id', 'DESC')
                ->first();
        } catch (\Throwable $e) {
            return 'excel';
        }

        if (is_null($analisis) || !is_array($analisis->payload) || !isset($analisis->payload['origen'])) {
            return 'excel';
        }

        return self::normalizar_origen($analisis->payload['origen']);
    }

    /**
     * `excel` | `pdf` | `martin`; cualquier otra cosa cae a `excel`.
     *
     * @param mixed $origen
     * @return string
     */
    public static function normalizar_origen($origen)
    {
        $origen = is_scalar($origen) ? strtolower(trim((string) $origen)) : '';

        // M4 guarda `payload['origen'] = 'admin'` cuando el admin no manda `origen`: es Martín.
        if ($origen === 'admin') {
            return 'martin';
        }

        return in_array($origen, self::ORIGENES_DE_IMPORTACION, true) ? $origen : 'excel';
    }

    /**
     * El primer import de un negocio en prueba (artículos, clientes O proveedores: Lucas, «en el momento en
     * que importa un archivo Excel ya cuenta como la migración») se lleva todo lo de ejemplo ANTES de aplicar
     * el archivo. El import de artículos lo hace `BorrarSemillaDePruebaJob` al frente de su cadena de jobs;
     * los de clientes y proveedores son sincrónicos (`Excel::import` en el request), así que se hace acá, en
     * el request, antes de importar. Devuelve si borró algo.
     *
     * @param int $owner_id
     * @param string|null $origen `excel` | `pdf` | `martin`
     * @return bool
     */
    public static function borrar_semilla_antes_de_importar($owner_id, $origen = null)
    {
        $owner_id = (int) $owner_id;

        if (!self::activa($owner_id) || !SemillaRegistroHelper::semilla_presente($owner_id)) {
            return false;
        }

        // Borrar ~470 operaciones por sus controladores lleva minutos: mismo criterio que el demo setup.
        set_time_limit(0);
        ignore_user_abort(true);

        SemillaRegistroHelper::borrar_lo_sembrado($owner_id);
        self::marcar_semilla_borrada($owner_id, $origen);

        return true;
    }

    /**
     * Una importación sincrónica (clientes o proveedores) terminó: el hito `prueba.importo` (una sola vez,
     * lo garantiza el job) con el origen que dejó `borrar_semilla_antes_de_importar()`.
     *
     * @param int $owner_id
     * @param string $modelo `client` | `provider`
     * @return void
     */
    public static function importacion_terminada($owner_id, $modelo)
    {
        PruebaHitosHelper::emitir('prueba.importo', [
            'origen' => self::origen_de_importacion($owner_id),
            'modelo' => (string) $modelo,
        ], (int) $owner_id);
    }

    /**
     * Lo que viaja al SPA adentro del usuario (`user.prueba`): null fuera del modo prueba, así un SPA
     * viejo o un cliente real no ven nada nuevo.
     *
     * @param int $owner_id
     * @return array|null
     */
    public static function resumen_para_spa($owner_id)
    {
        if (!self::activa($owner_id)) {
            return null;
        }

        $config = self::config($owner_id);
        $vence_at = self::vence_at($owner_id);
        $vigente = self::en_periodo_vigente($owner_id);

        return [
            'activa'             => true,
            'imagenes_catalogo'  => true,
            'puede_simular_agente' => $vigente,
            'puede_importar_pdf' => $vigente,
            'vence_at'           => is_null($vence_at) ? null : self::iso($vence_at),
            'vencida'            => self::vencida($owner_id),
            'video_url'          => $config->video_url,
            'whatsapp_soporte'   => $config->whatsapp_soporte,
            'modal_visto'        => !is_null($config->modal_visto_at),
            'semilla_presente'   => SemillaRegistroHelper::semilla_presente($owner_id),
        ];
    }

    /**
     * Le cuelga `prueba` al usuario que se le devuelve al SPA (login y GET /api/user). Clave nueva y
     * opcional: un SPA viejo la ignora. Fuera de una instancia de prueba no consulta nada.
     *
     * @param \App\Models\User|null $user
     * @return \App\Models\User|null
     */
    public static function adjuntar_resumen($user)
    {
        if (is_null($user) || !self::puede_haber_prueba()) {
            return $user;
        }

        $user->prueba = self::resumen_para_spa(self::owner_id_de($user));
        $user->puede_usar_chat_prueba = self::puede_usar_chat($user, request());
        $user->puede_unificar_chat_prueba = $user->puede_usar_chat_prueba
            && app(AsistenteUnificadoDePrueba::class)->disponible_para($user);

        return $user;
    }

    /**
     * El estado para el admin (C3, `GET admin-sync/prueba/estado`): `{vence_at, vencida, semilla_presente,
     * hitos: {tipo: ISO|null}}` más `activa`.
     *
     * @param int $owner_id
     * @return array
     */
    public static function estado_para_admin($owner_id)
    {
        $vence_at = self::vence_at($owner_id);
        $hitos = [];

        foreach (self::TIPOS_DE_HITO as $tipo) {
            $hitos[$tipo] = null;
        }

        if (self::tabla_disponible()) {
            foreach (PruebaHito::where('owner_id', (int) $owner_id)->get() as $hito) {
                if (array_key_exists($hito->tipo, $hitos)) {
                    $hitos[$hito->tipo] = self::iso($hito->ocurrido_at);
                }
            }
        }

        return [
            'vence_at'         => is_null($vence_at) ? null : self::iso($vence_at),
            'vencida'          => self::vencida($owner_id),
            'semilla_presente' => SemillaRegistroHelper::semilla_presente($owner_id),
            'hitos'            => $hitos,
            'activa'           => !is_null(self::config($owner_id)),
        ];
    }

    /**
     * Fecha ISO 8601 en la zona horaria de la app (`2026-11-10T14:00:00-03:00`).
     *
     * @param \Carbon\Carbon $fecha
     * @return string
     */
    public static function iso(Carbon $fecha)
    {
        return $fecha->copy()->setTimezone(config('app.timezone'))->toIso8601String();
    }

    /**
     * Carbon a partir de lo que mande el admin, o null si no es una fecha.
     *
     * @param mixed $valor
     * @return \Carbon\Carbon|null
     */
    public static function parsear_fecha($valor)
    {
        if (!is_scalar($valor) || trim((string) $valor) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $valor);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param mixed $valor
     * @param int $largo
     * @return string|null
     */
    private static function texto_o_null($valor, $largo)
    {
        if (!is_scalar($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : mb_substr($texto, 0, $largo);
    }
}
