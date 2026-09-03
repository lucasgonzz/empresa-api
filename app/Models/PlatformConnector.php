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
     * Secretos que nunca deben viajar en una respuesta JSON de la API.
     *
     * Mismo criterio que `OnlineConfiguration::$hidden` (mail_password, mp_access_token): los
     * tokens OAuth del comercio se guardan cifrados y ademas se ocultan de toda serializacion.
     * La UI se entera del estado de la conexion por `GET /api/integraciones`
     * (`IntegracionesController`), nunca del token crudo.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    /**
     * Casts de columnas nativas.
     *
     * `access_token` / `refresh_token` usan el cast nativo `encrypted` de Laravel (encripta y
     * desencripta con la APP_KEY al escribir y leer el atributo), igual que
     * `OnlineConfiguration::$casts['mp_access_token']`. Hasta esta mision estos dos se guardaban
     * en texto plano: mudar Mercado Pago aca sin llevarse el cifrado habria sido un retroceso.
     * La migracion `encrypt_platform_connector_tokens` cifra las filas de ML/TN que ya existian.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'expires_at'    => 'datetime',
        'access_token'  => 'encrypted',
        'refresh_token' => 'encrypted',
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
     * Filtra conectores de Mercado Libre en estado conectado.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Query base.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeMercadoLibreConnected($query)
    {
        return $query
            ->where('status', self::STATUS_CONECTADO)
            ->whereHas('platform', function ($platform_query) {
                $platform_query->where('slug', Platform::SLUG_MERCADO_LIBRE);
            });
    }

    /**
     * Conector ML conectado del usuario del ERP (polling, listados, etc.).
     *
     * @param int $user_id Usuario interno dueño del conector.
     * @return self|null
     */
    public static function find_connected_mercado_libre_for_user(int $user_id): ?self
    {
        $platform_connector = static::with('platform')
            ->mercadoLibreConnected()
            ->where('user_id', $user_id)
            ->whereNotNull('access_token')
            ->where('access_token', '!=', '')
            ->first();

        if (!$platform_connector || empty($platform_connector->platform_user_id)) {
            return null;
        }

        return $platform_connector;
    }

    /**
     * Conector ML por id de vendedor en Mercado Libre (webhooks `user_id` del payload).
     *
     * @param string $platform_user_id Identificador del vendedor en ML (`platform_user_id`).
     * @return self|null
     */
    public static function find_connected_mercado_libre_by_platform_user_id(string $platform_user_id): ?self
    {
        return static::mercadoLibreConnected()
            ->where('platform_user_id', $platform_user_id)
            ->first();
    }

    /**
     * Conector del comercio hacia una plataforma dada por slug, sin crearlo si no existe.
     *
     * @param int $user_id Comercio (owner) dueño del conector.
     * @param string $platform_slug Slug de `platforms` (ver constantes de `Platform`).
     * @return self|null
     */
    public static function find_for_user_and_slug(int $user_id, string $platform_slug): ?self
    {
        return static::with('platform')
            ->where('user_id', $user_id)
            ->whereHas('platform', function ($platform_query) use ($platform_slug) {
                $platform_query->where('slug', $platform_slug);
            })
            ->orderBy('id', 'DESC')
            ->first();
    }

    /**
     * Igual que `find_for_user_and_slug()`, pero si el comercio todavia no tiene conector para
     * esa plataforma lo crea en estado `sin_conectar`. Devuelve null solo si la plataforma no
     * existe en el catalogo (`platforms`), caso en el que no hay a que apuntar `platform_id`.
     *
     * @param int $user_id Comercio (owner) dueño del conector.
     * @param string $platform_slug Slug de `platforms`.
     * @return self|null
     */
    public static function find_or_create_for_user_and_slug(int $user_id, string $platform_slug): ?self
    {
        $platform_connector = static::find_for_user_and_slug($user_id, $platform_slug);

        if ($platform_connector) {
            return $platform_connector;
        }

        $platform = Platform::where('slug', $platform_slug)->first();

        if (!$platform) {
            return null;
        }

        $platform_connector = static::create([
            'user_id'          => $user_id,
            'platform_id'      => $platform->id,
            'status'           => self::STATUS_SIN_CONECTAR,
            'auth_code'        => null,
            'access_token'     => null,
            'refresh_token'    => null,
            'expires_at'       => null,
            'platform_user_id' => null,
            'error_message'    => null,
        ]);

        $platform_connector->setRelation('platform', $platform);

        return $platform_connector;
    }

    /**
     * Indica si el conector tiene una credencial usable, sin exponer ni desencriptar el token.
     *
     * Mismo criterio exacto que `OnlineConfiguration::getMpConnectedAttribute()`, que es de
     * donde se muda Mercado Pago: hay access_token guardado Y (no hay fecha de vencimiento
     * registrada, o esa fecha todavia es futura). Se lee el atributo crudo (`attributes`) en vez
     * de `$this->access_token` para no forzar el desencriptado del cast solo para ver si esta
     * vacio.
     *
     * @return bool
     */
    public function is_connected(): bool
    {
        $has_token = !empty($this->attributes['access_token']);
        $not_expired = empty($this->expires_at) || $this->expires_at->isFuture();

        return $has_token && $not_expired;
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
