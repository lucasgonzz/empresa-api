<?php

namespace App\Http\Controllers\Helpers;

use App\Models\CentroRecursosPase;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Crea y canjea los pases de un solo uso del centro de recursos (misión centro-de-recursos-clientes,
 * 13/10/2026). Molde: `VersionSessionTransferHelper`.
 *
 * EL CIRCUITO. El usuario, con su sesión del sistema, toca "Centro de recursos". Esta API crea un
 * pase (`crear()`) y le devuelve la URL de la página con el pase en claro. La página se lo pasa al
 * admin, y el admin lo canjea llamando a ESTA API por el canal admin-sync (`canjear()`), a la URL que
 * él tiene en su base para este cliente —nunca a la que vino del navegador—. Así, solo un usuario
 * logueado acá consigue un pase, y solo esta API lo puede confirmar.
 *
 * Reglas:
 *  - 64 caracteres al azar; en la base queda solo el sha256.
 *  - Vence a los 5 minutos y sirve una sola vez.
 *  - El canje es ATÓMICO: un UPDATE condicionado a `usado_at IS NULL` y no vencido. Si afectó 0
 *    filas, el pase no vale. Dos canjes simultáneos del mismo pase no pueden ganar los dos.
 *  - 🔴 Si la instancia tiene `config('app.USER_ID')`, solo se canjea un pase de un usuario de ESE
 *    dueño. En las bases compartidas viejas (`u767360347_empresa`, 51 comercios adentro) todas las
 *    APIs leen la misma tabla: sin este chequeo, un pase de un comercio se podría canjear por la API
 *    de otro y el admin le abriría la sesión a nombre del cliente equivocado.
 *
 * IMPORTANTE (PHP 7.4): nada de match, str_contains, nullsafe ni argumentos nombrados.
 */
class CentroRecursosPaseHelper
{
    /**
     * Minutos de validez del pase.
     *
     * @var int
     */
    const TTL_MINUTOS = 5;

    /**
     * Largo del pase en claro.
     *
     * @var int
     */
    const LARGO = 64;

    /**
     * Crea un pase para el usuario y devuelve el texto en claro (lo único que sale de acá con él).
     *
     * De paso borra los pases viejos de ese mismo usuario (vencidos hace más de un día): son una
     * fila por clic y no sirven para nada después de los 5 minutos. Va por query builder, sin
     * eventos de Eloquent: es limpieza, no un cambio del negocio.
     *
     * @param  User        $user  Usuario autenticado (dueño o empleado).
     * @param  string|null $video Código del video con el que se abre la página (ej. `T1.29`).
     * @return string Pase en claro.
     */
    public static function crear($user, $video = null)
    {
        $ahora = Carbon::now();

        CentroRecursosPase::where('user_id', $user->id)
            ->where('expires_at', '<', $ahora->copy()->subDay())
            ->delete();

        /** Pase aleatorio que viaja en la URL de la página. */
        $plano = Str::random(self::LARGO);

        CentroRecursosPase::create([
            'user_id'    => $user->id,
            'token_hash' => hash('sha256', $plano),
            'video'      => $video,
            'expires_at' => $ahora->copy()->addMinutes(self::TTL_MINUTOS),
        ]);

        Log::info('Centro de recursos: pase creado para user_id ' . $user->id);

        return $plano;
    }

    /**
     * Canjea un pase: si es válido lo marca usado y devuelve el usuario y el video; si no, null.
     *
     * "No válido" es cualquiera de estos casos, y del otro lado se distinguen igual (404
     * `pase_invalido`): no tiene la forma de un pase, no existe, ya se usó, venció, el usuario ya no
     * existe, o el usuario no es del dueño de esta instancia.
     *
     * Un pase presentado por la API de OTRO comercio no se quema: el canje de la API correcta (la
     * que el admin resuelve para el cliente dueño del pase) tiene que seguir andando.
     *
     * @param  mixed $plano Pase en claro, tal como llegó.
     * @return array|null `['user' => User, 'video' => string|null]` o null.
     */
    public static function canjear($plano)
    {
        if (!is_string($plano)) {
            return null;
        }

        $plano = trim($plano);

        if (strlen($plano) !== self::LARGO) {
            return null;
        }

        $pase = CentroRecursosPase::where('token_hash', hash('sha256', $plano))->first();

        if (is_null($pase)) {
            Log::info('Centro de recursos: canje de un pase que no existe.');
            return null;
        }

        $user = User::find($pase->user_id);

        if (is_null($user)) {
            Log::info('Centro de recursos: canje de un pase cuyo usuario ya no existe (pase ' . $pase->id . ').');
            return null;
        }

        if (!self::es_de_esta_instancia($user)) {
            Log::warning('Centro de recursos: canje rechazado, el usuario ' . $user->id . ' no es del dueño de esta instancia.');
            return null;
        }

        $ahora = Carbon::now();

        /*
         * El canje de verdad: UN update condicionado. La lectura de arriba no decide nada sobre
         * "usado" ni "vencido"; lo decide esta sentencia, que es atómica en la base. Si otro canje
         * del mismo pase ganó un instante antes, acá afecta 0 filas.
         *
         * La hora sale de PHP y no de NOW() de MySQL: el reloj de la base y el de la aplicación
         * pueden estar en zonas distintas, y `expires_at` se escribió con el de PHP.
         */
        $afectadas = CentroRecursosPase::where('id', $pase->id)
            ->whereNull('usado_at')
            ->where('expires_at', '>', $ahora)
            ->update([
                'usado_at'   => $ahora,
                'updated_at' => $ahora,
            ]);

        if ($afectadas !== 1) {
            Log::info('Centro de recursos: canje de un pase usado o vencido (pase ' . $pase->id . ').');
            return null;
        }

        Log::info('Centro de recursos: pase canjeado para user_id ' . $user->id);

        return [
            'user'  => $user,
            'video' => $pase->video,
        ];
    }

    /**
     * ¿El usuario es de esta instancia? Con `app.USER_ID` cargado, tiene que ser ese dueño o uno de
     * sus empleados (`owner_id ?? id`). Sin `app.USER_ID` (instancia de un solo comercio sin la
     * variable, entornos de desarrollo) no hay contra qué comparar y vale cualquiera.
     *
     * @param  User $user
     * @return bool
     */
    protected static function es_de_esta_instancia($user)
    {
        $user_id_de_la_instancia = config('app.USER_ID');

        if (is_null($user_id_de_la_instancia) || trim((string) $user_id_de_la_instancia) === '') {
            return true;
        }

        $dueno_id = !empty($user->owner_id) ? (int) $user->owner_id : (int) $user->id;

        return $dueno_id === (int) $user_id_de_la_instancia;
    }
}
