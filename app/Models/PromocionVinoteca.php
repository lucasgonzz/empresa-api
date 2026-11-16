<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\TieneMedidasDeEnvio;
use Illuminate\Support\Facades\Schema;

class PromocionVinoteca extends Model
{
    use SoftDeletes;
    use TieneMedidasDeEnvio;
    protected $guarded = [];
    protected $appends = ['peso', 'alto', 'ancho', 'profundidad'];
    protected $hidden = ['medidas_envio'];

    function scopeWithAll($q) {
        $q->with('articles', 'images');
        if (Schema::hasTable('medidas_envio_paquetes')) {
            $q->with('medidas_envio');
        }
    }

    function articles() {
        return $this->belongsToMany(Article::class)->withPivot('amount', 'unidades_por_promo');
    }

    function images() {
        return $this->morphMany('App\Models\Image', 'imageable');
    }
}
