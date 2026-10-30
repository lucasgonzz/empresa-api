<?php

namespace App\Http\Controllers\Helpers;

use App\Models\AccesoSoporteToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Toda la lógica del acceso de soporte de un solo uso (misión acceso-soporte-firmado, 30/10/2026).
 *
 * LA IDEA. El admin (autenticado con la clave compartida `X-Admin-Api-Key`, ver el middleware
 * `admin.api.key:siempre`) le pide a este sistema un token; este helper lo EMITE (`emitir()`), guarda solo su
 * hash y lo devuelve en claro una única vez. Después, el navegador que abre la URL de entrada lo CANJEA
 * (`canjear()`) por una sesión del dueño. Es el mismo molde que `extensiones/acceso`, `recursos/canjear-pase`
 * y `demo/ingreso`, con una diferencia que importa: ESTE token es de UN SOLO USO y vive segundos.
 *
 * Los dos controladores (`AdminSync\AccesoSoporteController` y `AccesoSoporteController`) solo orquestan: toda
 * decisión de negocio está acá, para que haya una sola definición de «qué es un token canjeable».
 *
 * 🔴 EL TOKEN EN CLARO NUNCA SE LOGUEA NI SE GUARDA. Existe en tres lugares y nada más: la memoria de
 * `emitir()`, el cuerpo de esa respuesta HTTP y lo que manda el navegador al canjear. Ningún `Log::*` de este
 * archivo lo recibe, y a la base solo llega `hash('sha256', $token)`.
 *
 * PHP 7.4: sin `?->`, sin `match`, sin `str_contains`, sin argumentos nombrados, sin union types.
 */
class AccesoSoporteHelper
{
    /** Largo del token en claro: 64 caracteres alfanuméricos (≈ 380 bits de azar con `Str::random`). */
    const LARGO_TOKEN = 64;

    /** Vida del token si el .env no dice nada, en segundos. */
    const TTL_POR_DEFECTO = 120;

    /** Piso del TTL: menos que esto vence antes de que el navegador llegue a abrir la URL. */
    const TTL_MINIMO = 30;

    /** Techo del TTL: un token de un solo uso que vive horas ya no es de «un solo uso» en la práctica. */
    const TTL_MAXIMO = 600;

    /** A quién se le atribuye el acceso cuando el pedido no dice quién lo hace. */
    const SOLICITANTE_POR_DEFECTO = 'claude';

    /** Largos máximos de los campos de texto libre (iguales a los de la columna). */
    const LARGO_SOLICITANTE = 60;
    const LARGO_MOTIVO = 120;
    const LARGO_IP = 45;
    const LARGO_USER_AGENT = 150;

    /**
     * ¿Está prendido el acceso de soporte en este cliente? Lee `config/acceso_soporte.php`
     * (variable `ACCESO_SOPORTE_HABILITADO`, default true). Apagado: la emisión responde 403 y el canje, el 401
     * genérico.
     *
     * @return bool
     */
    public static function habilitado()
    {
        return (bool) config('acceso_soporte.habilitado', true);
    }

    /**
     * Segundos que vive un token recién emitido, acotados a TTL_MINIMO–TTL_MAXIMO.
     *
     * Un valor que no es un número positivo (la variable mal escrita da 0 al castear a entero) no se «acota
     * al piso»: se interpreta como «no se entendió» y vale el default. Un número positivo fuera de rango sí se
     * lleva al piso o al techo.
     *
     * @return int
     */
    public static function ttl_segundos()
    {
        $ttl = (int) config('acceso_soporte.ttl_segundos', self::TTL_POR_DEFECTO);

        if ($ttl <= 0) {
            return self::TTL_POR_DEFECTO;
        }

        return max(self::TTL_MINIMO, min(self::TTL_MAXIMO, $ttl));
    }

    /**
     * Emite un token de acceso de soporte para el dueño y lo devuelve EN CLARO. Es la única vez que el claro
     * existe fuera de la memoria: a la base va solo el sha256.
     *
     * Sanea `solicitante` y `motivo` acá adentro (no confía en que el controlador lo haya hecho): quien llame a
     * este método con texto crudo queda cubierto igual. El saneo es idempotente.
     *
     * Deja un `Log::info` con el id de la fila, el dueño, el solicitante, el motivo, la IP y el vencimiento.
     * 🔴 Nunca el token: el log vive más que el token y lo leen otras personas.
     *
     * @param  User         $user         El dueño al que da acceso (owner_id null; lo resuelve el controlador).
     * @param  mixed        $solicitante  Quién lo pide. Vacío o inválido → 'claude'.
     * @param  mixed        $motivo       Para qué. Opcional; vacío o inválido → null.
     * @param  string|null  $ip           IP del pedido de emisión (la del admin).
     * @return string                     El token en claro: 64 caracteres alfanuméricos.
     */
    public static function emitir($user, $solicitante = null, $motivo = null, $ip = null)
    {
        /** El token en claro. `Str::random` usa `random_bytes` y devuelve solo [A-Za-z0-9]. */
        $token = Str::random(self::LARGO_TOKEN);

        /** Hasta cuándo se puede canjear. */
        $vence_en = Carbon::now()->addSeconds(self::ttl_segundos());

        $solicitante = self::sanear_texto($solicitante, self::LARGO_SOLICITANTE, self::SOLICITANTE_POR_DEFECTO);
        $motivo = self::sanear_texto($motivo, self::LARGO_MOTIVO, null);
        $ip = self::sanear_texto($ip, self::LARGO_IP, null);

        $fila = AccesoSoporteToken::create([
            // Solo el hash: si la base se filtra, esto no sirve para entrar.
            'token_hash'   => hash('sha256', $token),
            'owner_id'     => $user->id,
            'solicitante'  => $solicitante,
            'motivo'       => $motivo,
            'ip_solicitud' => $ip,
            'vence_en'     => $vence_en,
        ]);

        Log::info('Acceso de soporte: token emitido', [
            'token_id'    => $fila->id,
            'user_id'     => $user->id,
            'solicitante' => $solicitante,
            'motivo'      => $motivo,
            'ip'          => $ip,
            'vence_en'    => $vence_en->toDateTimeString(),
        ]);

        return $token;
    }

    /**
     * Canjea un token por el dueño al que da acceso. Devuelve el dueño y el id de la fila, o null si el token no
     * vale por CUALQUIER motivo (el llamador contesta siempre lo mismo: no se filtra el motivo).
     *
     * Devuelve null, sin tocar nada, cuando:
     *   - el acceso de soporte está apagado en este cliente;
     *   - el token no tiene el formato `^[A-Za-z0-9]{64}$` (ni siquiera se consulta la base);
     *   - no hay una fila con ese hash;
     *   - la fila ya se usó, o ya venció;
     *   - el usuario ya no existe, o dejó de ser dueño (`owner_id` no es null).
     *
     * 🔴 EL CONSUMO ES ATÓMICO Y ES LO ÚNICO QUE DECIDE. Antes se mira la fila, pero eso es solo para ahorrar
     * un UPDATE inútil y para tener el usuario: lo que impide que dos canjes simultáneos del mismo token
     * entren los dos es la condición DENTRO del UPDATE (`usado_en IS NULL AND vence_en > ahora`) y mirar cuántas
     * filas cambió. Con «buscar, ver que está libre y después marcarlo» hay una ventana entre el SELECT y el
     * UPDATE en la que dos pedidos ven la fila libre; con la condición en el UPDATE, la base serializa y solo
     * uno obtiene 1 fila afectada. Quien obtiene 0 perdió la carrera → null.
     *
     * La comparación del hash no es de tiempo constante a propósito: se busca por el índice de `token_hash`, y
     * el atacante no controla el prefijo del sha256 de un token que no conoce, así que no hay nada que adivinar
     * midiendo tiempos.
     *
     * @param  mixed        $token_plano  Lo que mandó el navegador. Cualquier cosa que no sea un string válido → null.
     * @param  string|null  $ip           IP del navegador que canjea (queda en la auditoría).
     * @param  string|null  $user_agent   User-Agent del navegador (queda en la auditoría, recortado).
     * @return array|null                 ['user' => User, 'token_id' => int] o null.
     */
    public static function canjear($token_plano, $ip = null, $user_agent = null)
    {
        if (!self::habilitado()) {
            return null;
        }

        if (!self::formato_valido($token_plano)) {
            return null;
        }

        /** La fila de ese token, buscada por su hash (el claro no existe en la base). */
        $fila = AccesoSoporteToken::where('token_hash', hash('sha256', $token_plano))->first();

        if (!$fila) {
            return null;
        }

        /** «Ahora», fijado una vez: el chequeo previo y el UPDATE comparan contra el mismo instante. */
        $ahora = Carbon::now();

        // Chequeo previo barato (no decide nada, ver el docblock): si ya se usó o ya venció, no vale la pena
        // seguir. Una fila sin vencimiento (NULL) tampoco es canjeable nunca.
        if (!is_null($fila->usado_en) || is_null($fila->vence_en) || $fila->vence_en->lte($ahora)) {
            return null;
        }

        /** El dueño al que da acceso. */
        $user = User::find($fila->owner_id);

        // Tiene que seguir existiendo y seguir siendo DUEÑO: si entre la emisión y el canje alguien lo volvió
        // empleado, el acceso de soporte no le sirve de puerta a una cuenta que no es la del comercio.
        if (!$user || !is_null($user->owner_id)) {
            return null;
        }

        // Base compartida entre frentes: el token solo vale en el frente (USER_ID) del dueño para el que se emitió.
        // Los frentes de una misma base comparten la tabla, así que sin esto un token emitido para el dueño del
        // frente A se podría canjear por la API del frente B. No escalaba privilegios (es el dueño correcto), pero
        // abría una sesión de ese dueño en un dominio que no es el suyo. Va ANTES del consumo para no quemar el token.
        $user_id_del_frente = config('app.USER_ID');

        if (!empty($user_id_del_frente) && (int) $user_id_del_frente !== (int) $user->id) {
            return null;
        }

        // El consumo atómico: una sola sentencia que solo cambia la fila si TODAVÍA está libre y vigente.
        $filas_afectadas = AccesoSoporteToken::where('id', $fila->id)
            ->whereNull('usado_en')
            ->where('vence_en', '>', $ahora)
            ->update([
                'usado_en'         => $ahora,
                'ip_canje'         => self::sanear_texto($ip, self::LARGO_IP, null),
                'user_agent_canje' => self::sanear_texto($user_agent, self::LARGO_USER_AGENT, null),
            ]);

        // 1 = lo consumió este pedido. 0 = otro pedido llegó antes (o venció en el medio): perdió la carrera.
        if ($filas_afectadas !== 1) {
            return null;
        }

        return [
            'user'     => $user,
            'token_id' => (int) $fila->id,
        ];
    }

    /**
     * ¿Tiene el formato de un token emitido por este helper? Exactamente 64 caracteres [A-Za-z0-9].
     *
     * Se ancla con `\z` y NO con `$`: en PHP, `$` también acepta un salto de línea final, y «<64 caracteres>\n»
     * pasaría por válido. Un token mal formado se descarta antes de hacer cualquier consulta.
     *
     * @param  mixed $token
     * @return bool
     */
    public static function formato_valido($token)
    {
        return is_string($token) && preg_match('/^[A-Za-z0-9]{' . self::LARGO_TOKEN . '}\z/', $token) === 1;
    }

    /**
     * Deja un texto libre apto para guardarse y para escribirse en un log: sin caracteres de control ni saltos
     * de línea (un `\n` en el motivo permitiría falsificar renglones del log), sin caracteres invisibles de
     * formato (el marcador de derecha a izquierda cambia cómo se LEE un texto), UTF-8 válido, con los espacios
     * colapsados y recortado a `$largo_maximo` caracteres.
     *
     * @param  mixed       $valor          Lo que llegó (se espera un string; un array o un objeto no es texto).
     * @param  int         $largo_maximo   Cantidad máxima de caracteres (no de bytes).
     * @param  string|null $por_defecto    Qué devolver si no queda nada utilizable.
     * @return string|null
     */
    public static function sanear_texto($valor, $largo_maximo, $por_defecto = null)
    {
        if (!is_scalar($valor) || is_bool($valor)) {
            return $por_defecto;
        }

        $texto = (string) $valor;

        // Bytes que no son UTF-8: se reemplazan en vez de dejar que rompan el INSERT o el json del log.
        if (!mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'UTF-8');
        }

        // Control (\p{Cc}: \n, \r, \t, NUL...), formato invisible (\p{Cf}), separadores de línea y de párrafo
        // (\p{Zl}, \p{Zp}) y el resto de \p{C}: cada tanda se vuelve un espacio.
        $texto = preg_replace('/[\p{C}\p{Zl}\p{Zp}]+/u', ' ', $texto);

        if (!is_string($texto)) {
            return $por_defecto;
        }

        // Espacios repetidos → uno; sin espacios en los extremos.
        $texto = preg_replace('/\s+/u', ' ', $texto);

        if (!is_string($texto)) {
            return $por_defecto;
        }

        $texto = trim(mb_substr(trim($texto), 0, (int) $largo_maximo, 'UTF-8'));

        return $texto === '' ? $por_defecto : $texto;
    }
}
