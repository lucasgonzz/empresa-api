<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de token de transferencia de sesión entre versiones del SPA.
 */
class VersionSessionTransfer extends Model
{
    /**
     * Atributos asignables en masa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'token_hash',
        'user_id',
        'expires_at',
    ];

    /**
     * Casts de columnas.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'expires_at' => 'datetime',
    ];
}
