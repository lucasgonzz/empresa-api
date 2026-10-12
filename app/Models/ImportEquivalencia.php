<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Equivalencia recordada de la importación de Excel: "este valor del Excel es este modelo que ya
 * existe" (misión importacion-relaciones-parecidas, 8/10/2026). La escribe y la lee
 * EquivalenciasResolver; ver la migración create_import_equivalencias_table.
 *
 * @property int    $user_id
 * @property string $tipo
 * @property int    $contexto_id
 * @property string $valor
 * @property string $valor_clave
 * @property int    $model_id
 */
class ImportEquivalencia extends Model
{
    protected $table = 'import_equivalencias';

    protected $guarded = [];

    protected $casts = [
        'user_id'     => 'integer',
        'contexto_id' => 'integer',
        'model_id'    => 'integer',
    ];
}
