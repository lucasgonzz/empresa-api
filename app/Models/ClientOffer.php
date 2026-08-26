<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 🔴 La oferta ACTIVA: la promoción vigente de un cliente sobre un artículo.
 * Es la tabla que LA TIENDA LEE (misión 5) — no por un endpoint, sino por SQL
 * sobre la base compartida. La query exacta que corre la tienda está textual
 * en el docblock de la migración
 * 2026_08_17_100200_create_client_offers_table.php: si algo de este modelo va
 * a cambiar la forma de esas columnas, se avisa antes, no después.
 *
 * tipo_descuento 'unidad' → el número está en `porcentaje`.
 * tipo_descuento 'cantidad' → `porcentaje` queda NULL y los números están en
 * los `ranges` (client_offer_ranges), para que nadie lea el campo equivocado.
 *
 * estado: 'activa' | 'vencida' (barrido de higiene de ofertas:generar) |
 * 'cancelada' (el comerciante la dio de baja, o la venció una activación nueva
 * del mismo par). 🔴 El DELETE del endpoint NO borra la fila: pone
 * estado = 'cancelada'. El historial de qué se ofreció y a quién no se pierde.
 *
 * 🔴 El invariante "una sola oferta ACTIVA por (user_id, client_id,
 * article_id)" NO está en la base (no hay unique, porque una cancelada tiene
 * que poder convivir con una activa nueva): se sostiene EN CÓDIGO al activar,
 * dentro de la transacción que vence la anterior. Si alguien crea un
 * ClientOffer por fuera de ese camino, lo rompe.
 *
 * notificada_email_at / whatsapp_url en null significan "no se pudo avisar",
 * no "falló la activación": la oferta vale igual porque el canal principal es
 * la tienda mostrándola. Medido: el 84% de los clientes no tiene ni mail ni
 * teléfono cargado.
 */
class ClientOffer extends Model
{
    protected $guarded = [];

    /**
     * client_nombre viaja SIEMPRE junto al modelo (index() y store()/fullModel() lo necesitan
     * los dos, y las dos rutas pasan por acá): es aditivo, así que no puede romper a nadie que
     * ya lea `client.name`.
     */
    protected $appends = ['client_nombre'];

    function scopeWithAll($q) {
        // client.buyer y no client: getClientNombreAttribute() lee $client->buyer, y sin el
        // eager-load acá cada fila de la tabla de Vigentes pega su propia consulta a buyers.
        $q->with(['client.buyer', 'article', 'ranges']);
        return $q;
    }

    /**
     * El nombre a mostrar de esta oferta: el del comprador de la tienda, cayendo al del
     * cliente del ERP (OfertaComunicacionHelper::nombre_para_mostrar()). Aditivo: `client.name`
     * sigue viajando intacto en la respuesta, que es lo que usan `display_name` del chat de
     * WhatsApp (TablaActivas.vue) y los `<select>` de filtro — a propósito NO se tocan.
     *
     * @return string
     */
    public function getClientNombreAttribute()
    {
        return \App\Http\Controllers\Helpers\OfertaComunicacionHelper::nombre_para_mostrar($this->client);
    }

    /**
     * Los tramos por cantidad. Vacío cuando tipo_descuento es 'unidad'.
     */
    function ranges() {
        return $this->hasMany(ClientOfferRange::class);
    }

    /**
     * El cliente al que se le hizo la oferta. Es la punta del join con
     * buyers.comercio_city_client_id que hace la tienda.
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
     * La línea sugerida de la que salió esta oferta. Nullable: el esquema deja
     * la puerta abierta a cargar una oferta a mano sin pasar por el motor.
     */
    function offer_suggestion_line() {
        return $this->belongsTo(OfferSuggestionLine::class);
    }
}
