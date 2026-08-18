<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cabecera de una tanda de generación de embeddings.
 *
 * Modelo flaco a propósito: no tiene relaciones ni scopes. La tabla no tiene foreign keys
 * y los contadores se incrementan con UPDATEs crudos desde los jobs (`generados + 1`), no
 * leyendo el modelo, sumando en PHP y guardando — con varios workers en paralelo eso
 * perdería incrementos.
 *
 * El significado de cada columna está en la migración
 * (2026_08_18_100000_create_embedding_runs_table.php).
 */
class EmbeddingRun extends Model
{
    protected $guarded = [];
}
