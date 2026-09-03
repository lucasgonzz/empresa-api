<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Estado anti-CSRF de una autorizacion OAuth en curso.
 *
 * Nacio como `MercadoPagoOauthState` (grupo 170, prompt 598) sobre la tabla
 * `mercado_pago_oauth_states`. Al mudar los cobros de Mercado Pago a `platform_connectors` se
 * generalizo, porque en el repo convivian dos mecanismos de `state` de calidad muy distinta:
 *
 * - este, aleatorio (`Str::random(64)`, generador criptografico), con vencimiento corto y de un
 *   solo uso;
 * - el del OAuth generico, que manda el ID del conector como `state`: predecible, reusable y
 *   sin vencimiento.
 *
 * Quedo este, que es el bueno. La tabla se llama `oauth_states` y un state puede estar atado a
 * un `PlatformConnector` (`create_for_connector`) o solo a un comercio
 * (`create_for_user`, el flujo viejo).
 *
 * Responsabilidad:
 * - Generar y persistir un `state` aleatorio con vencimiento corto cuando el comercio pide la
 *   URL de autorizacion.
 * - Validar y "consumir" (marcar usado) ese `state` cuando la plataforma vuelve al `callback`,
 *   para confirmar que el `code` recibido corresponde a quien inicio el flujo y que no es un
 *   replay.
 */
class OauthState extends Model
{
    protected $table = 'oauth_states';

    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
    ];

    /**
     * Scope estándar del repo (requerido por Controller::fullModel()). Este modelo no se expone
     * por endpoints CRUD/fullModel, pero se define igual por consistencia con el resto de los
     * modelos Eloquent del proyecto.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query;
    }

    /**
     * Conector al que pertenece este state, cuando se genero con `create_for_connector()`.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function platform_connector()
    {
        return $this->belongsTo(PlatformConnector::class, 'platform_connector_id');
    }

    /**
     * Genera un `state` aleatorio, lo persiste atado al comercio autenticado con un vencimiento
     * corto, y lo devuelve para armar la URL de autorización.
     *
     * Se conserva del flujo viejo (prompt 598): un state creado asi solo sabe a que comercio
     * pertenece, no a que conector. Sigue vivo para que una ventana de autorizacion abierta
     * ANTES del deploy de esta mision pueda completarse despues sin errores.
     *
     * @param int $user_id Comercio (owner) que inicia el connect.
     * @param int $ttl_minutes Minutos de validez del state (ventana para completar el OAuth).
     * @return string El `state` generado.
     */
    public static function create_for_user(int $user_id, int $ttl_minutes = 15): string
    {
        // Str::random(64) usa un generador criptográficamente seguro (random_bytes por debajo):
        // no es adivinable, requisito para que el state sirva como protección anti-CSRF real.
        $state = Str::random(64);

        static::create([
            'state'                 => $state,
            'user_id'               => $user_id,
            'platform_connector_id' => null,
            'expires_at'            => now()->addMinutes($ttl_minutes),
            'used_at'               => null,
        ]);

        return $state;
    }

    /**
     * Igual que `create_for_user()`, pero ademas deja atado el conector concreto que se esta
     * autorizando. Es el mecanismo que reemplaza al "id del conector como state" del OAuth
     * generico: el callback no tiene que confiar en un numero que viene de la URL, sino que
     * resuelve el conector desde una fila que este backend escribio.
     *
     * @param PlatformConnector $platform_connector Conector que se esta autorizando.
     * @param int $ttl_minutes Minutos de validez del state.
     * @return string El `state` generado.
     */
    public static function create_for_connector(PlatformConnector $platform_connector, int $ttl_minutes = 15): string
    {
        $state = Str::random(64);

        static::create([
            'state'                 => $state,
            'user_id'               => (int) $platform_connector->user_id,
            'platform_connector_id' => (int) $platform_connector->id,
            'expires_at'            => now()->addMinutes($ttl_minutes),
            'used_at'               => null,
        ]);

        return $state;
    }

    /**
     * Valida un `state` recibido en el callback y, si es válido, lo marca consumido para que no
     * pueda reutilizarse. Devuelve la fila (con `user_id` y, si lo tiene,
     * `platform_connector_id`), o null si el state no existe, ya venció o ya fue usado (posible
     * replay o link viejo).
     *
     * OJO al cambiar el tipo de retorno: antes de esta mision devolvia el `user_id` (int). Ahora
     * devuelve el modelo, porque el llamador necesita saber tambien a que conector va.
     *
     * @param string $state Valor recibido en el query `state` del callback.
     * @return self|null
     */
    public static function consume(string $state): ?self
    {
        $record = static::where('state', $state)
            ->whereNull('used_at')
            ->where('expires_at', '>=', now())
            ->first();

        if (!$record) {
            return null;
        }

        $record->used_at = now();
        $record->save();

        return $record;
    }
}
