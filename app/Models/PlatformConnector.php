<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Conector OAuth de un usuario del ERP hacia una `Platform` (ML, TN, etc.).
 *
 * Responsabilidad:
 * - Vincular `user_id` + `platform_id` y persistir tokens OAuth del tenant.
 * - Exponer `auth_url` (appended) usando las credenciales de la plataforma asociada.
 */
class PlatformConnector extends Model
{
    use HasFactory;

    /** Estado inicial: falta completar OAuth en el navegador. */
    public const STATUS_SIN_CONECTAR = 'sin_conectar';

    /** OAuth completado y tokens válidos guardados. */
    public const STATUS_CONECTADO = 'conectado';

    /** Último intento de token/callback falló (ver `error_message`). */
    public const STATUS_ERROR = 'error';

    protected $guarded = [];

    /**
     * Atributos virtuales incluidos en la serialización JSON de la API.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'auth_url',
    ];

    /**
     * Casts de columnas nativas.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * Scope estándar del proyecto: carga la plataforma para URLs y callbacks.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Query base.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query->with('platform');
    }

    /**
     * Usuario dueño del conector (tenant del ERP).
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Plataforma (app Comercio City) a la que apunta este conector.
     *
     * @return BelongsTo
     */
    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class, 'platform_id');
    }

    /**
     * URL que el operador debe abrir para autorizar la app en la plataforma.
     *
     * Notas:
     * - Mercado Libre: `redirect_uri` debe coincidir con `MERCADO_LIBRE_REDIRECT_URI`.
     * - Tienda Nube: `app_id` en `platform.extra_config['app_id']` o el `client_id` de la plataforma.
     * - `state` transporta el id del conector para correlacionar el callback.
     *
     * @return string
     */
    public function getAuthUrlAttribute(): string
    {
        if (!$this->relationLoaded('platform')) {
            $this->load('platform');
        }
        $platform = $this->platform;
        if (!$platform || empty($platform->client_id)) {
            return '';
        }

        if ($platform->slug === Platform::SLUG_MERCADO_LIBRE) {
            $redirect_uri = env('MERCADO_LIBRE_REDIRECT_URI');
            if (empty($redirect_uri)) {
                return '';
            }
            $query = http_build_query([
                'response_type' => 'code',
                'client_id'     => $platform->client_id,
                'redirect_uri'  => $redirect_uri,
                'state'         => (string) $this->id,
            ]);

            return 'https://auth.mercadolibre.com.ar/authorization?' . $query;
        }

        if ($platform->slug === Platform::SLUG_TIENDA_NUBE) {
            $app_id = null;
            if (is_array($platform->extra_config) && !empty($platform->extra_config['app_id'])) {
                $app_id = $platform->extra_config['app_id'];
            }
            if (empty($app_id)) {
                $app_id = $platform->client_id;
            }
            if (empty($app_id)) {
                return '';
            }
            $query = http_build_query([
                'state' => (string) $this->id,
            ]);

            return 'https://www.tiendanube.com/apps/' . rawurlencode((string) $app_id) . '/authorize?' . $query;
        }

        return '';
    }
}
