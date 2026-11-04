<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un evento de «El local» que todavía no llegó al admin (ver App\Services\LocalActividad).
 */
class LocalActividadEvento extends Model
{
    protected $table = 'local_actividad_eventos';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['detalle' => 'array', 'ocurrido_at' => 'datetime', 'intentos' => 'integer'];

    public function scopeWithAll($query)
    {
    }
}
