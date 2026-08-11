<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un artículo que cambió de precio dentro de una corrida de recálculo.
 *
 * Tabla de volumen: sin timestamps, con único compuesto (price_update_run_id, article_id)
 * para que un chunk reintentado no cuente dos veces el mismo artículo.
 */
class PriceUpdateRunArticle extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function price_update_run()
    {
        return $this->belongsTo('App\Models\PriceUpdateRun');
    }
}
