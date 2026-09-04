<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Estado anti-CSRF de una autorización OAuth de Zippin en curso (grupo 171, prompt 599).
 *
 * Responsabilidad:
 * - Generar y persistir un `state` aleatorio atado a un comercio (user_id), con vencimiento
 *   corto, cuando el comercio pide la URL de autorización (`connect`).
 * - Validar y "consumir" (marcar usado) ese `state` cuando Zippin vuelve al `callback`, para
 *   confirmar que el `code` recibido corresponde al comercio que inició el flujo y que no es un
 *   intento de reutilización.
 *
 * Análogo a `App\Models\OauthState` (prompt 598, entonces `MercadoPagoOauthState`), en tabla propia para no mezclar
 * states de proveedores distintos.
 */
class ZippinOauthState extends Model
{
    protected $table = 'zippin_oauth_states';

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
     * Genera un `state` aleatorio, lo persiste atado al comercio autenticado con un vencimiento
     * corto, y lo devuelve para armar la URL de autorización.
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
            'state'      => $state,
            'user_id'    => $user_id,
            'expires_at' => now()->addMinutes($ttl_minutes),
            'used_at'    => null,
        ]);

        return $state;
    }

    /**
     * Valida un `state` recibido en el callback y, si es válido, lo marca consumido para que no
     * pueda reutilizarse. Devuelve el user_id del comercio al que corresponde, o null si el
     * state no existe, ya venció o ya fue usado (posible replay o link viejo).
     *
     * @param string $state Valor recibido en el query `state` del callback.
     * @return int|null
     */
    public static function consume(string $state): ?int
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

        return (int) $record->user_id;
    }
}
