<?php

namespace App\Services;

use App\Models\LocalActividadEvento;
use App\Models\ProviderOrderScan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lo que este sistema le cuenta al admin para «El local» (misión el-local-fase-2, 3/11/2026): quién entró, quién
 * está usando el sistema, qué factura se escaneó y cuándo contestó el agente del negocio.
 *
 * 🔴 Dos mitades que no se mezclan. Registrar es una fila en una tabla, sin red, y NUNCA tira una excepción: un aviso
 * a un panel interno no puede frenar un login, un escaneo ni una respuesta del bot. Enviar corre aparte, en el comando
 * `local:enviar-actividad` de cada minuto, y es el único que habla con el admin.
 *
 * Contrato: `POST {ADMIN_API_URL}/api/inbound/local/actividad`, con `X-Admin-Api-Key` = `ADMIN_API_OUTBOUND_KEY`
 * (en el admin, `clients.inbound_api_key`), la misma clave que usa el consumo de Serper. Es lo único que identifica
 * al cliente. El admin deduplica por (cliente, tipo, hora, usuario), así que reintentar no duplica.
 *
 * Sin `ADMIN_API_URL`, sin clave o sin `USER_ID` no hace nada y no loguea: ver activa().
 */
class LocalActividad
{
    /** Los tipos que el admin acepta. Otro valor le daría 422 a todo el lote: acá se filtra antes. */
    const TIPOS = ['login', 'actividad', 'logout', 'escaneo', 'agente_negocio', 'asistente', 'otro'];

    /** Tope de eventos por POST (contrato). */
    const TOPE_LOTE = 100;

    /** Tope del `detalle` en bytes de `json_encode` con los flags por defecto, que es como lo mide el admin. */
    const TOPE_DETALLE_BYTES = 2048;

    /** Tope de `usuario` en caracteres (contrato). */
    const TOPE_USUARIO = 120;

    /** Como máximo un evento `actividad` por usuario cada estos minutos. */
    const MINUTOS_ENTRE_ACTIVIDADES = 5;

    /** Intentos de un evento antes de descartarlo. Con la pausa de pausar() son unos 90 minutos. */
    const TOPE_INTENTOS = 8;

    /** Pausa máxima entre intentos, en minutos. */
    const PAUSA_MAXIMA_MINUTOS = 30;

    /** Horas después de las que un evento sin enviar ya no le sirve a la escena y se descarta. */
    const HORAS_DE_VIGENCIA = 24;

    /** Clave de cache de la pausa: mientras exista, el scheduler no arranca el comando. */
    const CLAVE_PAUSA = 'local_actividad:pausa';

    /**
     * ¿Esta instancia puede avisarle al admin?
     *
     * `USER_ID` también hace falta: la clave representa a UN dueño, y en una base compartida es lo que dice cuál.
     *
     * @return bool
     */
    public static function activa()
    {
        return trim((string) config('services.admin_api.url')) !== ''
            && trim((string) config('services.admin_api.inbound_key')) !== ''
            && (int) config('app.USER_ID') > 0;
    }

    /**
     * Encola un evento. No toca la red y no tira excepciones.
     *
     * 🔴 Lo de otro dueño no se encola: en una base compartida ninguna instancia lo mandaría nunca y quedaría
     * huérfano. Puede pasar: en esa base el login no valida que el documento sea del comercio de la instalación.
     *
     * @param  string      $tipo      Uno de TIPOS.
     * @param  int         $owner_id  Dueño del comercio.
     * @param  string|null $usuario   Nombre de la persona, si la hay.
     * @param  array|null  $detalle   Datos chicos, sin nada del negocio.
     * @return void
     */
    public static function registrar($tipo, $owner_id, $usuario = null, array $detalle = null)
    {
        try {
            if (!self::activa() || !in_array($tipo, self::TIPOS, true) || (int) $owner_id !== (int) config('app.USER_ID')) {
                return;
            }

            LocalActividadEvento::create([
                'owner_id'    => (int) $owner_id,
                'tipo'        => $tipo,
                'usuario'     => self::usuario_o_null($usuario),
                'detalle'     => self::detalle_o_null($detalle),
                'ocurrido_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('LocalActividad: no se pudo registrar el evento (lo que el usuario estaba haciendo sigue).', [
                'tipo'    => $tipo,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Encola un evento de una persona: el dueño sale de ella, sea dueño o empleado.
     *
     * @param  string $tipo
     * @param  User   $persona
     * @return void
     */
    public static function de_la_persona($tipo, $persona)
    {
        self::registrar($tipo, $persona->owner_id ?: $persona->id, $persona->name);
    }

    /**
     * Encola el `login` y arranca el contador de `actividad`: el primer request después de entrar no tiene nada
     * que agregar, y mandarlo sería un segundo evento por el mismo hecho.
     *
     * @param  User $persona
     * @return void
     */
    public static function login_de($persona)
    {
        self::de_la_persona('login', $persona);

        try {
            if (self::activa()) {
                Cache::put(self::clave_de_actividad($persona), 1, Carbon::now()->addMinutes(self::MINUTOS_ENTRE_ACTIVIDADES));
            }
        } catch (\Throwable $e) {
            Log::warning('LocalActividad: no se pudo arrancar el contador de actividad.', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Encola `actividad` si pasaron los minutos desde la última de esta persona. `Cache::add` es lo que hace que dos
     * requests simultáneos no manden dos.
     *
     * @param  User $persona
     * @return void
     */
    public static function actividad_de($persona)
    {
        try {
            if (self::activa() && Cache::add(self::clave_de_actividad($persona), 1, Carbon::now()->addMinutes(self::MINUTOS_ENTRE_ACTIVIDADES))) {
                self::de_la_persona('actividad', $persona);
            }
        } catch (\Throwable $e) {
            Log::warning('LocalActividad: no se pudo registrar la actividad.', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Encola el fin de un escaneo de factura de compra, bien o mal. El dueño sale del escaneo: esto corre en la cola,
     * donde no hay sesión.
     *
     * @param  ProviderOrderScan $scan
     * @return void
     */
    public static function escaneo_terminado(ProviderOrderScan $scan)
    {
        try {
            $persona = empty($scan->auth_user_id) ? null : User::find($scan->auth_user_id);

            self::registrar('escaneo', $scan->user_id, is_null($persona) ? null : $persona->name, [
                'scan_id'           => $scan->id,
                'estado'            => $scan->estado,
                'necesito_revision' => !empty($scan->resultado['lectura']['revisada']),
            ]);
        } catch (\Throwable $e) {
            Log::warning('LocalActividad: no se pudo registrar el escaneo.', ['message' => $e->getMessage()]);
        }
    }

    /**
     * ¿Hay algo para mandar ahora? Es el `->when()` del scheduler: decide dentro del `schedule:run` que ya está
     * booteado, y sin trabajo (o en pausa) no se paga el arranque de un `php artisan` (regla del schedule en
     * App\Console\Kernel).
     *
     * @return bool
     */
    public static function hay_para_enviar()
    {
        try {
            return self::activa()
                && !Cache::has(self::CLAVE_PAUSA)
                && LocalActividadEvento::where('owner_id', (int) config('app.USER_ID'))->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Manda un lote al admin. Un lote por corrida alcanza: son 100 eventos por minuto.
     *
     *   - 200 con `ok`: el admin los tiene. Se borran.
     *   - 422: el lote está mal y reintentarlo daría lo mismo. Se borra (contrato).
     *   - Cualquier otra cosa (red, 5xx, 401, 404, 429, un 200 que no es la respuesta del admin): se conserva y se
     *     reintenta con tope.
     *
     * @return int Eventos que el admin confirmó.
     */
    public function enviar()
    {
        if (!self::hay_para_enviar()) {
            return 0;
        }

        $eventos = LocalActividadEvento::where('owner_id', (int) config('app.USER_ID'))
            ->orderBy('id')
            ->limit(self::TOPE_LOTE)
            ->get();

        if ($eventos->isEmpty()) {
            return 0;
        }

        $payload = $eventos->map(function ($evento) {
            return [
                'tipo'        => $evento->tipo,
                'ocurrido_at' => $evento->ocurrido_at->toIso8601String(),
                'usuario'     => $evento->usuario,
                'detalle'     => $evento->detalle,
            ];
        })->all();

        try {
            $response = Http::acceptJson()
                ->withHeaders(['X-Admin-Api-Key' => trim((string) config('services.admin_api.inbound_key'))])
                ->withOptions(['connect_timeout' => 3])
                ->timeout(8)
                ->post($this->url(), ['eventos' => $payload]);
        } catch (\Throwable $e) {
            $this->reintentar($eventos, 'sin respuesta');

            return 0;
        }

        if ($response->status() === 422) {
            LocalActividadEvento::whereIn('id', $eventos->pluck('id'))->delete();

            Log::warning('LocalActividad: el admin rechazó el lote (422) y se descartó.', ['eventos' => $eventos->count()]);

            return 0;
        }

        $cuerpo = $response->json();

        if ($response->successful() && is_array($cuerpo) && !empty($cuerpo['ok'])) {
            LocalActividadEvento::whereIn('id', $eventos->pluck('id'))->delete();

            return $eventos->count();
        }

        $this->reintentar($eventos, 'HTTP ' . $response->status());

        return 0;
    }

    /**
     * El lote no llegó: suma un intento, descarta lo que llegó al tope o ya no sirve, y pausa los envíos. La causa casi
     * siempre es de toda la instancia (admin caído, clave mal cargada), así que la pausa es de la cola entera: el resto
     * fallaría igual, y con la pausa una clave rota no arranca un proceso por minuto todo el día.
     *
     * @param  \Illuminate\Support\Collection $eventos
     * @param  string                         $motivo
     * @return void
     */
    private function reintentar($eventos, $motivo)
    {
        $ids = $eventos->pluck('id');

        LocalActividadEvento::whereIn('id', $ids)->increment('intentos');
        LocalActividadEvento::whereIn('id', $ids)->where('intentos', '>=', self::TOPE_INTENTOS)->delete();

        LocalActividadEvento::where('owner_id', (int) config('app.USER_ID'))
            ->where('ocurrido_at', '<', Carbon::now()->subHours(self::HORAS_DE_VIGENCIA))
            ->delete();

        $intentos = (int) $eventos->max('intentos') + 1;
        $minutos  = min(self::PAUSA_MAXIMA_MINUTOS, pow(2, $intentos - 1));

        Cache::put(self::CLAVE_PAUSA, 1, Carbon::now()->addMinutes($minutos));

        Log::warning('LocalActividad: el admin no recibió el lote, se reintenta.', [
            'motivo'  => $motivo,
            'eventos' => $eventos->count(),
            'minutos' => $minutos,
        ]);
    }

    /**
     * La URL del endpoint. Hay clientes con ADMIN_API_URL cargada con `/api` al final y otros sin.
     *
     * @return string
     */
    private function url()
    {
        $base = rtrim(trim((string) config('services.admin_api.url')), '/');

        if (substr($base, -4) !== '/api') {
            $base .= '/api';
        }

        return $base . '/inbound/local/actividad';
    }

    /**
     * @param  User $persona
     * @return string
     */
    private static function clave_de_actividad($persona)
    {
        return 'local_actividad:actividad:' . $persona->id;
    }

    /**
     * El nombre recortado al tope del contrato, o null si vino vacío.
     *
     * @param  mixed $usuario
     * @return string|null
     */
    private static function usuario_o_null($usuario)
    {
        $usuario = trim((string) $usuario);

        return $usuario === '' ? null : mb_substr($usuario, 0, self::TOPE_USUARIO);
    }

    /**
     * El detalle, si entra en el tope del contrato. Uno que se pasa se reemplaza por una marca: un `detalle` de más
     * le daría 422 a todo el lote, y con él se irían los eventos de los demás.
     *
     * @param  array|null $detalle
     * @return array|null
     */
    private static function detalle_o_null($detalle)
    {
        if (empty($detalle)) {
            return null;
        }

        $json = json_encode($detalle);

        return ($json === false || strlen($json) > self::TOPE_DETALLE_BYTES) ? ['recortado' => true] : $detalle;
    }
}
