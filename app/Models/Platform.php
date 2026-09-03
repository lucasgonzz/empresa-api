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
     * Conectores OAuth de usuarios del ERP hacia esta plataforma.
     *
     * @return HasMany
     */
    public function connectors(): HasMany
    {
        return $this->hasMany(PlatformConnector::class, 'platform_id');
    }
}
