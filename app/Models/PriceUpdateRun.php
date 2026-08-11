<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una corrida de recálculo de precios finales.
 *
 * Lleva la cuenta de los chunks encolados y procesados para que el finalizador sepa
 * cuándo puede cerrar, y guarda el resultado (cuántos artículos cambiaron de precio y de
 * qué proveedores) que después muestra el modal del SPA.
 */
class PriceUpdateRun extends Model
{
    protected $guarded = [];

    protected $dates = ['started_at', 'finished_at'];

    /**
     * Los artículos de esta corrida que efectivamente cambiaron de precio.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function articles()
    {
        return $this->hasMany('App\Models\PriceUpdateRunArticle');
    }

    /**
     * Traduce el origen a la frase que ve el usuario en el modal.
     *
     * Está acá y no en el SPA porque el mismo texto va a la notificación, que se arma en
     * el backend: si viviera en los dos lados, uno de los dos se queda atrás.
     *
     * @return string
     */
    public function getOrigenTextoAttribute()
    {
        switch ($this->origen) {
            case 'dolar':
                return 'Se recalcularon por un cambio en el dólar';
            case 'tipo_de_precio':
                return 'Se recalcularon por un cambio en un tipo de precio';
            case 'listas_de_precio':
                return 'Se recalcularon por un cambio en las listas de precio';
            case 'proveedor':
                return 'Se recalcularon por un cambio en un proveedor';
            case 'configuracion_usuario':
                return 'Se recalcularon por un cambio en la configuración';
            default:
                return 'Se recalcularon los precios';
        }
    }
}
