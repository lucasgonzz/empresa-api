<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ArticleActualizadosImportHistory extends Pivot
{
    protected $table = 'article_actualizados_import_history';

    // public function getUpdatedPropsAttribute($value)
    // {
    //     return json_decode($value, true);
    // }
}
