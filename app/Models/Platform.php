<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plataforma de integración (una app Comercio City en ML, TN, etc.).
 *
 * Responsabilidad:
 * - Centralizar `client_id` y `client_secret` de la aplicación (no cambian por tenant).
 * - Opcionalmente `extra_config` (ej. `app_id` de Tienda Nube para la URL de OAuth).
 */
class Platform extends Model
{
    use HasFactory;

    /** Slug persistido para Mercado Libre. */
    public const SLUG_MERCADO_LIBRE = 'mercado_libre';

    /** Slug persistido para Tienda Nube. */
    public const SLUG_TIENDA_NUBE = 'tienda_nube';

    /**
     * Slug persistido para Mercado Pago. Es la plataforma que ancla el conector de cobros de
     * cada comercio: hasta esta mision sus tokens vivian en `online_configurations.mp_*`.
     */
    public const SLUG_MERCADO_PAGO = 'mercado_pago';

    protected $guarded = [];

    /**
     * Oculta secretos en respuestas JSON hacia la SPA.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'client_id',
        'client_secret',
    ];

    /**
     * `client_secret` usa el cast nativo `encrypted` de Laravel: es el secreto de la APLICACION
     * de ComercioCity en cada plataforma y hasta esta mision se guardaba en texto plano. La
     * migracion `encrypt_platform_connector_tokens` cifra las filas de ML/TN ya existentes.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'extra_config'  => 'array',
        'client_secret' => 'encrypted',
    ];

    /**
     * Plataformas que el ABM genérico de conectores (solapa "Sistema") sabe conectar.
     *
     * Son exactamente las que tienen una rama en `PlatformConnector::getAuthUrlAttribute()`.
     * Mercado Pago NO está: se conecta por su propia pantalla, con
     * `GET integraciones/mercadopago/connect`, porque su URL de autorización necesita un `state`
     * aleatorio recién generado y eso no se puede hacer dentro de un accessor que corre en cada
     * serialización (escribiría una fila de `oauth_states` por conector en cada listado).
     *
     * Sin este filtro, la fila `mercado_pago` que siembra `PlatformSeeder` aparecía en el select
     * del ABM y se podían crear conectores de Mercado Pago con `auth_url` vacío: registros
     * muertos que no conectan nada y que el operador no entiende por qué no funcionan.
     *
     * @var array<int, string>
     */
    public const SLUGS_CONECTABLES_POR_ABM = [
        self::SLUG_MERCADO_LIBRE,
        self::SLUG_TIENDA_NUBE,
    ];

    /**
     * Scope estándar del proyecto para `fullModel` / listados.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Query base.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query;
    }

    /**
     * Filtra el catálogo a las plataformas que se conectan desde el ABM de conectores.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Query base.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeConectablesPorAbm($query)
    {
        return $query->whereIn('slug', self::SLUGS_CONECTABLES_POR_ABM);
    }

    /**
     * Conectores OAuth de usuarios del ERP hacia esta plataforma.
     *
     * @return HasMany
     */
    public function connectors(): HasMany
    {
        return $this->hasMany(PlatformConnector::class, 'platform_id');
    }
}
