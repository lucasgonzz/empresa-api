<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\GeneralHelper;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * La app «ComercioCity IA» (ia.comerciocity.com) del lado de este API (misión app-ia-y-memoria,
 * 29/10/2026, contratos C1.1, C1.2 y C5 del plan).
 *
 * La app es una PWA aparte, servida desde otro dominio, que el DUEÑO usa para hablar con su agente
 * sin pasar por WhatsApp. No tiene sesión de Laravel: entra con un token personal de Sanctum que
 * emite `admin-sync/ia-app/token` (el admin valida al usuario contra esta base y le reenvía el
 * token a la app). Todo lo que esa app puede tocar está acá: la habilidad del token, las rutas que
 * puede usar, qué funciones sabe esta versión y la forma en que se describe al usuario y al negocio.
 *
 * 🔴 POR QUÉ UN TOKEN Y NO LA COOKIE DEL SISTEMA. La cookie de Sanctum solo viaja entre dominios
 * "stateful" del mismo sitio, y la app vive en otro subdominio común a TODOS los clientes. Y el login
 * del sistema (AuthController::login) toma el candado de sesión única (`session_id`/`last_activity`):
 * si la app entrara por ahí, abrirla en el teléfono le cerraría la sesión al dueño en la caja. El
 * token no pasa por ese candado: se emite sin `Auth::attempt`, sin `loginLucas()` y sin tocar esas
 * dos columnas, y la app nunca llama a `GET api/user` (que es lo que corre el candado).
 *
 * 🔴 Y POR QUÉ UNA LISTA BLANCA DE RUTAS. Un token de Sanctum, por defecto, abre TODO el API como si
 * fuera la persona. La app es solo el chat: un token suyo que se filtre no puede servir para borrar
 * artículos ni bajar las ventas. El middleware SoloRutasDeLaApp corta cualquier ruta que no esté en
 * RUTAS_DE_LA_APP cuando el token tiene la habilidad `ia-app`.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class IaAppHelper
{
    /** La habilidad del token de la app. Es la única que tiene: con `*` sería un token de todo el API. */
    const HABILIDAD = 'ia-app';

    /** Prefijo del nombre del token en personal_access_tokens; atrás va el dispositivo. */
    const PREFIJO_DEL_TOKEN = 'ia-app:';

    /** Techo del nombre del dispositivo (contrato C1.1). */
    const MAX_DISPOSITIVO = 100;

    /** Versión del contrato C5 que expone este API. La app la lee para saber con qué está hablando. */
    const VERSION_API = '1';

    /** Valor de `ai_messages.canal` de un mensaje que entró por la app. */
    const CANAL = 'app';

    /**
     * Lo que esta versión sabe hacer para la app (contrato C1.1). La app apaga lo que no esté.
     *
     * `contexto` y `cuestionario` los expone la otra mitad de la misión (la memoria del negocio, E2):
     * se anuncian SOLO si esa mitad está en este código (ver funciones()). Anunciar una función cuyas
     * rutas no existen haría que la app muestre una pantalla que da 404.
     */
    const FUNCIONES = ['modelo', 'adjuntos', 'audio', 'bloques', 'imagenes', 'contexto', 'cuestionario', 'preferencias', 'buscar', 'renombrar'];

    /**
     * Un bcrypt de un texto cualquiera (costo 10, el de Laravel), para que "ese documento no existe"
     * tarde lo mismo que "la contraseña está mal". No abre nada: no es la contraseña de nadie.
     */
    const HASH_DE_RELLENO = '$2y$10$D/2UyNmSHOz8DRxOCe5jCubD2nA2JbCjl0kHfW1pl0F7QwGIBJ.16';

    /** Las funciones que dependen de la memoria del negocio (AgenteContextoHelper). */
    const FUNCIONES_DE_LA_MEMORIA = ['contexto', 'cuestionario', 'preferencias'];

    /** La clase de la memoria del negocio (la arma E2). Se usa por nombre para no exigirla. */
    const CLASE_DE_LA_MEMORIA = 'App\\Http\\Controllers\\Helpers\\asistente_ia\\AgenteContextoHelper';

    /**
     * Las rutas que un token `ia-app` puede usar, como "MÉTODO uri" tal cual están declaradas en
     * routes/api.php. Todo lo demás le da 403 `fuera_de_la_app` (SoloRutasDeLaApp).
     *
     * 🔴 Incluye las de la memoria y el cuestionario (E2) aunque no estén en este código todavía: es
     * una lista de lo PERMITIDO, y una ruta que no existe da 404 igual. Así el día que se mergea la otra
     * mitad no hay que acordarse de volver acá.
     *
     * 🔴 NO incluye `GET api/user` (corre el candado de sesión única) ni `api/mcp` (el servidor MCP
     * tiene su propio token con la habilidad `mcp`).
     */
    const RUTAS_DE_LA_APP = [
        // Propias de la app (C5).
        'GET api/ia-app/yo',
        'DELETE api/ia-app/token',
        'POST api/ia-app/transcribir',
        'GET api/ia-imagenes/{uuid}',
        // La memoria del negocio y el cuestionario (E2).
        'GET api/ia-app/cuestionario',
        'POST api/ia-app/cuestionario',
        'POST api/ia-app/cuestionario/saltear',
        'PATCH api/ia-app/preferencias',
        'GET api/agente-contexto',
        'PUT api/agente-contexto',
        // El chat, igual que el del sistema.
        'GET api/ai-conversations',
        'POST api/ai-conversations',
        'PATCH api/ai-conversations/{id}',
        'DELETE api/ai-conversations/{id}',
        'GET api/ai-conversations/{id}/messages',
        'POST api/ai-conversations/{id}/messages',
        'GET api/ai-conversations/{id}/messages/{message_id}',
        'POST api/ai-conversations/{id}/acciones/{accion_id}/confirmar',
        'POST api/ai-conversations/{id}/acciones/{accion_id}/cancelar',
        'GET api/ai-mensajes/{message_id}/imagen/{orden}',
        'GET api/ai-mensajes/{message_id}/audio',
        'GET api/ai-mensajes/{message_id}/archivo/{orden}',
        // Lo que abren las menciones.
        'GET api/articles/{id}/ficha-asistente',
        'GET api/clients/{id}/para-cuenta-corriente',
        // La configuración del asistente y el consumo.
        'GET api/user/asistente-config',
        'PUT api/user/asistente-config',
        'GET api/mi-consumo-ia',
        // La conexión MCP, administrada desde la configuración de la app.
        'GET api/mcp/conexion',
        'POST api/mcp/conexion',
        'DELETE api/mcp/conexion',
    ];

    /**
     * Las funciones que esta instalación anuncia, en el orden fijo de FUNCIONES.
     *
     * @return array<int, string>
     */
    public static function funciones(): array
    {
        $hay_memoria = class_exists(self::CLASE_DE_LA_MEMORIA);

        $funciones = [];

        foreach (self::FUNCIONES as $funcion) {

            if (!$hay_memoria && in_array($funcion, self::FUNCIONES_DE_LA_MEMORIA, true)) {

                continue;
            }

            $funciones[] = $funcion;
        }

        return $funciones;
    }

    /**
     * El bloque `capacidades` de C1.1 y de `ia-app/yo`.
     *
     * @return array{version_api: string, funciones: array<int, string>}
     */
    public static function capacidades(): array
    {
        return [
            'version_api' => self::VERSION_API,
            'funciones'   => self::funciones(),
        ];
    }

    /**
     * `{id, nombre}` de la persona que entra.
     *
     * @param  \App\Models\User  $persona
     * @return array{id: int, nombre: string}
     */
    public static function usuario(User $persona): array
    {
        $nombre = trim((string) $persona->name);

        return [
            'id'     => (int) $persona->id,
            'nombre' => $nombre === '' ? 'Dueño' : $nombre,
        ];
    }

    /**
     * `{nombre, logo_url}` del negocio. El logo es el de Configuración general (users.image_url), en
     * URL absoluta; null si no hay o no se puede resolver.
     *
     * @param  \App\Models\User|null  $dueno
     * @return array{nombre: string, logo_url: string|null}
     */
    public static function negocio($dueno): array
    {
        $nombre = '';

        if (!is_null($dueno)) {
            $nombre = trim((string) ($dueno->company_name ?: $dueno->name));
        }

        return [
            'nombre'   => $nombre === '' ? 'Tu negocio' : $nombre,
            'logo_url' => is_null($dueno) ? null : self::logo_url($dueno->image_url),
        ];
    }

    /**
     * Una imagen guardada en users.image_url como URL absoluta, con el mismo criterio que
     * AdminSync\BrandingController: si ya es http(s) (Cloudinary, lo común) va tal cual; si es un
     * path local se resuelve contra /storage; si no se puede, null.
     *
     * @param  string|null  $valor
     * @return string|null
     */
    public static function logo_url($valor)
    {
        $valor = trim((string) $valor);

        if ($valor === '') {

            return null;
        }

        if (preg_match('~^https?://~i', $valor)) {

            return $valor;
        }

        try {

            $local = GeneralHelper::storage_public_path_from_image_url($valor);

        } catch (\Throwable $e) {

            return null;
        }

        return is_null($local) ? null : url('storage/' . basename($local));
    }

    /**
     * La persona que corresponde a estas credenciales en ESTA instancia, o null.
     *
     * 🔴 Es una comprobación de contraseña y nada más: ni `Auth::attempt` (abriría una sesión), ni el
     * acceso maestro de `loginLucas()`, ni el candado de sesión única. Lo único que se lee es
     * `users.password`.
     *
     * 🔴 SOLO ENTRE EL DUEÑO DE ESTE FRENTE Y SUS USUARIOS (`id = dueño` u `owner_id = dueño`), nunca
     * en toda la base. Una base compartida del shared tiene decenas de comercios juntos separados por
     * `users.owner_id`, y el mismo documento con la misma contraseña puede estar en dos de ellos:
     * buscar en toda la base le emitiría a un usuario de OTRO negocio un token por el frente equivocado.
     * Sin dueño resoluble no se busca a nadie (el controller contesta 409 `dueno_no_resoluble` antes de
     * llegar acá; si igual llega, null).
     *
     * El documento se prueba tal cual y, si no aparece, sin puntos ni espacios (lo que la gente tipea
     * en el teléfono: "20.123.456"), siempre dentro de ese mismo dueño.
     *
     * @param  \App\Models\User|null  $dueno  El dueño de este frente (AsistenteCanalHelper::dueno()).
     * @param  string  $doc_number
     * @param  string  $password
     * @return \App\Models\User|null
     */
    public static function persona_por_credenciales($dueno, $doc_number, $password)
    {
        $doc_number = trim((string) $doc_number);
        $password   = (string) $password;

        $candidatos = self::candidatos($dueno, $doc_number);

        $sin_puntos = preg_replace('/[\s\.]+/', '', $doc_number);

        if (!count($candidatos) && $sin_puntos !== $doc_number && $sin_puntos !== '') {
            $candidatos = self::candidatos($dueno, $sin_puntos);
        }

        /*
         * Sin candidatos igual se hace un Hash::check contra un hash fijo: así "ese documento no
         * existe" y "la contraseña está mal" tardan lo mismo y el tiempo de respuesta no dice quién es
         * cliente.
         */
        if (!count($candidatos)) {

            try {
                Hash::check($password, self::HASH_DE_RELLENO);
            } catch (\Throwable $e) {
                /* El relleno es solo para emparejar tiempos: si el hasher lo rechaza, no pasa nada. */
            }

            return null;
        }

        foreach ($candidatos as $candidato) {

            $hash = (string) $candidato->password;

            if ($hash === '') {
                continue;
            }

            try {

                if (Hash::check($password, $hash)) {

                    return $candidato;
                }

            } catch (\Throwable $e) {

                /* Un hash con otro algoritmo (usuarios viejos) no puede voltear el login de los demás. */
                Log::info('IaAppHelper: no se pudo comparar la contraseña de un usuario -- ' . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Los usuarios con ese documento del dueño de este frente: él o sus usuarios (máximo 5). Sin dueño,
     * ninguno.
     *
     * @param  \App\Models\User|null  $dueno
     * @param  string  $doc_number
     * @return \Illuminate\Support\Collection
     */
    protected static function candidatos($dueno, $doc_number)
    {
        if ($doc_number === '' || is_null($dueno) || (int) $dueno->id <= 0) {

            return collect();
        }

        return User::where('doc_number', $doc_number)
            ->where(function ($q) use ($dueno) {
                $q->where('id', (int) $dueno->id)
                  ->orWhere('owner_id', (int) $dueno->id);
            })
            ->orderBy('id')
            ->limit(5)
            ->get();
    }

    /**
     * El dueño de la cuenta de una persona (ella misma si no tiene owner_id).
     *
     * @param  \App\Models\User  $persona
     * @return \App\Models\User|null
     */
    public static function dueno_de(User $persona)
    {
        if (empty($persona->owner_id)) {

            return $persona;
        }

        return User::find((int) $persona->owner_id);
    }

    /**
     * true si la persona puede usar el asistente: el MISMO criterio que solo_el_dueno_ia
     * (MostradorHelper::puede_ver()), aplicado a una persona que todavía no está autenticada —el
     * dueño, o un empleado con admin_access—. El acceso maestro no existe acá: sin `loginLucas()` no
     * hay forma de entrar como él.
     *
     * @param  \App\Models\User  $persona
     * @return bool
     */
    public static function puede_usar_el_asistente(User $persona): bool
    {
        if (empty($persona->owner_id)) {

            return true;
        }

        return (bool) $persona->admin_access;
    }

    /**
     * Emite el token de la app para la persona y devuelve el texto plano ("<id>|<texto>"). El texto
     * viaja UNA sola vez: Sanctum guarda el hash.
     *
     * A diferencia de la clave MCP, NO revoca los anteriores: el dueño puede tener la app abierta en el
     * teléfono y en la compu, y cada una tiene su token (por eso el nombre lleva el dispositivo).
     *
     * @param  \App\Models\User  $persona
     * @param  string|null  $dispositivo
     * @return string
     */
    public static function emitir_token(User $persona, $dispositivo): string
    {
        $dispositivo = trim((string) $dispositivo);

        if ($dispositivo === '') {
            $dispositivo = 'app';
        }

        $dispositivo = mb_substr($dispositivo, 0, self::MAX_DISPOSITIVO);

        return $persona->createToken(self::PREFIJO_DEL_TOKEN . $dispositivo, [self::HABILIDAD])->plainTextToken;
    }

    /**
     * true si el token es de la app: tiene la habilidad `ia-app` y no la comodín `*`.
     *
     * @param  mixed  $token
     * @return bool
     */
    public static function es_token_de_la_app($token): bool
    {
        if (!($token instanceof PersonalAccessToken)) {

            return false;
        }

        $habilidades = is_array($token->abilities) ? $token->abilities : [];

        return in_array(self::HABILIDAD, $habilidades, true) && !in_array('*', $habilidades, true);
    }

    /**
     * El token con el que se autenticó el pedido, si es uno de la app; null si entró por la cookie
     * del sistema, por un token de otro uso o sin autenticar.
     *
     * Se pregunta sobre $request->user() porque es el modelo que tiene el access token colgado
     * (UserHelper::user() hace un find nuevo, sin él).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Laravel\Sanctum\PersonalAccessToken|null
     */
    public static function token_de_la_app(Request $request)
    {
        $autenticado = $request->user();

        if (is_null($autenticado) || !method_exists($autenticado, 'currentAccessToken')) {

            return null;
        }

        $token = $autenticado->currentAccessToken();

        return self::es_token_de_la_app($token) ? $token : null;
    }

    /**
     * true si el pedido entró con un token de la app.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    public static function es_pedido_de_la_app(Request $request): bool
    {
        return !is_null(self::token_de_la_app($request));
    }

    /**
     * true si la ruta del pedido está en la lista blanca de la app.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    public static function ruta_permitida(Request $request): bool
    {
        $ruta = $request->route();

        if (is_null($ruta) || !is_object($ruta) || !method_exists($ruta, 'uri')) {

            return false;
        }

        $metodo = strtoupper((string) $request->getMethod());

        /* HEAD es un GET sin cuerpo: si el GET está permitido, el HEAD también. */
        if ($metodo === 'HEAD') {
            $metodo = 'GET';
        }

        return in_array($metodo . ' ' . $ruta->uri(), self::RUTAS_DE_LA_APP, true);
    }

    /**
     * El estado del cuestionario de inicio del dueño (contrato C5, `ia-app/yo`).
     *
     * Lo resuelve la memoria del negocio (E2, AgenteContextoHelper::estado_cuestionario()). Si esa
     * mitad no está en este código, o falla, va el estado "no completado ni salteado": la app ofrece el
     * cuestionario y, como `cuestionario` no está en las funciones, no lo muestra.
     *
     * @param  \App\Models\User|null  $dueno
     * @return array{completado: bool, salteado: bool, completado_en: string|null}
     */
    public static function cuestionario($dueno): array
    {
        $por_defecto = ['completado' => false, 'salteado' => false, 'completado_en' => null];

        $clase = self::CLASE_DE_LA_MEMORIA;

        if (is_null($dueno) || !class_exists($clase) || !method_exists($clase, 'estado_cuestionario')) {

            return $por_defecto;
        }

        try {

            $estado = call_user_func([$clase, 'estado_cuestionario'], $dueno);

        } catch (\Throwable $e) {

            Log::warning('IaAppHelper: no se pudo leer el estado del cuestionario', [
                'owner_id' => $dueno->id,
                'error'    => $e->getMessage(),
            ]);

            return $por_defecto;
        }

        if (!is_array($estado)) {

            return $por_defecto;
        }

        return [
            'completado'    => !empty($estado['completado']),
            'salteado'      => !empty($estado['salteado']),
            'completado_en' => isset($estado['completado_en']) && $estado['completado_en'] !== '' ? (string) $estado['completado_en'] : null,
        ];
    }

    /**
     * Asegura que el dueño tenga su memoria armada (E2, AgenteContextoHelper::asegurar(), idempotente).
     * Protegido: si esa mitad no está o falla, la app abre igual.
     *
     * @param  \App\Models\User|null  $dueno
     * @return void
     */
    public static function asegurar_la_memoria($dueno)
    {
        $clase = self::CLASE_DE_LA_MEMORIA;

        if (is_null($dueno) || !class_exists($clase) || !method_exists($clase, 'asegurar')) {

            return;
        }

        try {

            call_user_func([$clase, 'asegurar'], $dueno);

        } catch (\Throwable $e) {

            Log::warning('IaAppHelper: no se pudo asegurar la memoria del dueño', [
                'owner_id' => $dueno->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
