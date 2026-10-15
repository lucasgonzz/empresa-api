<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pase de un solo uso para entrar al centro de recursos (misión centro-de-recursos-clientes).
 *
 * Lo crea y lo canjea `CentroRecursosPaseHelper`; nadie más escribe esta tabla. Solo se guarda el
 * hash del pase (`token_hash`), y además va en `$hidden` para que no salga en ningún JSON ni en el
 * registro de auditoría (que enmascara los campos ocultos).
 *
 * `soporte` (misión centro-recursos-soporte-y-tema, 14/10/2026): el pase lo emitió un login maestro
 * y abre la página en SOLO LECTURA. Lo lee el canje para avisárselo al admin.
 */
class CentroRecursosPase extends Model
{
    /**
     * Tabla (el plural en castellano no lo adivina Eloquent).
     *
     * @var string
     */
    protected $table = 'centro_recursos_pases';

    /**
     * Atributos asignables en masa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'token_hash',
        'video',
        'soporte',
        'expires_at',
        'usado_at',
    ];

    /**
     * Nunca se serializa el hash.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'token_hash',
    ];

    /**
     * Casts de columnas.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'user_id'    => 'integer',
        'soporte'    => 'boolean',
        'expires_at' => 'datetime',
        'usado_at'   => 'datetime',
    ];
}
