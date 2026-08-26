<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una sugerencia concreta del motor de ofertas: este cliente, este artículo,
 * este porcentaje. Es un BORRADOR: la promoción que la tienda lee recién existe
 * cuando el comerciante activa la línea y se crea el ClientOffer
 * (POST api/client-offer); `client_offer_id` cierra ese vínculo y es lo que la
 * vista mira para mostrar "ya activada".
 *
 * porcentaje_sugerido viene SIEMPRE recortado a [porcentaje_piso,
 * porcentaje_techo]. El techo es determinista y sale del margen del artículo
 * (TechoDeDescuentoService), así que ningún número que devuelva la IA puede
 * dejar una oferta por debajo del costo.
 *
 * El detalle columna por columna está en el docblock de la migración
 * 2026_08_17_100100_create_offer_suggestion_lines_table.php.
 */
class OfferSuggestionLine extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        // client.buyer y no client: OfertaComunicacionHelper::nombre_para_mostrar() lee
        // $client->buyer, y sin el eager-load acá cada fila de la grilla pega su propia
        // consulta a buyers (N+1 con 500 líneas por página).
        $q->with(['client.buyer', 'article']);
        return $q;
    }

    /**
     * La corrida a la que pertenece esta línea.
     */
    function offer_suggestion() {
        return $this->belongsTo(OfferSuggestion::class);
    }

    /**
     * El cliente al que se le sugiere la oferta.
     */
    function client() {
        return $this->belongsTo(Client::class);
    }

    /**
     * El artículo ofertado.
     */
    function article() {
        return $this->belongsTo(Article::class);
    }

    /**
     * La oferta activa que salió de esta línea. Null mientras el comerciante
     * no la haya activado, que es el estado de la enorme mayoría: una corrida
     * propone y él elige cuáles van.
     */
    function client_offer() {
        return $this->belongsTo(ClientOffer::class);
    }
}
