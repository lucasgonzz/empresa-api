<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Medida de etiqueta (ancho/alto en px) asociada a un usuario.
 */
class EtiquetaMedida extends Model
{
    protected $table = 'etiqueta_medidas';

    protected $guarded = [];

    /**
     * Casts de atributos booleanos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'es_predeterminada' => 'boolean',
    ];

    /**
     * Scope requerido por fullModel del Controller base.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query;
    }

    /**
     * Usuario dueño de la medida.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
