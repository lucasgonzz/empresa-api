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
     * @var array<string, string>
     */
    protected $casts = [
        'extra_config' => 'array',
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
