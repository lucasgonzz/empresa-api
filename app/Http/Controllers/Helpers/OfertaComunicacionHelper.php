<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Buyer;
use App\Models\ClientOffer;
use Illuminate\Support\Carbon;

/**
 * Todo lo que hace falta para AVISARLE al cliente que tiene una oferta: a qué
 * mail escribirle, el texto del mensaje, y el link de WhatsApp click-to-chat.
 *
 * 🔴 NADA DE ACÁ ES UNA PRECONDICIÓN DE LA ACTIVACIÓN. La promoción se registra
 * en client_offers ANTES de pasar por este helper, y si acá no sale nada la
 * oferta queda vigente igual, marcada como "sin notificar". Medido el 15/8/2026
 * sobre `prueba` (496 clientes no borrados): 26 con email (5%), 74 con teléfono
 * (15%), **417 sin ninguno de los dos (84%)** — si avisar fuera obligatorio, el
 * motor no serviría para 8 de cada 10 clientes. El canal principal es la tienda
 * mostrándole la oferta cuando entra (misión 5); el mail y el WhatsApp son el
 * empujón. **No convertir esto en un guard que frene la activación.**
 */
class OfertaComunicacionHelper
{
    /**
     * Mail al que avisarle la oferta: el del Buyer del ecommerce si existe, si
     * no el de `clients.email`. null si no hay ninguno válido.
     *
     * 🔴 EL ORDEN NO ES CAPRICHOSO: el Buyer del ecommerce SIEMPRE tiene email
     * porque es como se registra en la tienda, mientras que `clients.email` está
     * casi siempre vacío (medido: `empresa` 2 de 11, `prueba` 26 de 541). Dar
     * vuelta el orden le pega al caso que más importa: el cliente que ya compra
     * online, que es justo al que la oferta le puede llegar.
     *
     * @param int $client_id
     * @param int $user_id Comercio dueño de la oferta (los buyers son por comercio).
     * @param mixed $client El Client ya resuelto, para no volver a buscarlo.
     * @return string|null
     */
    public static function email_del_cliente($client_id, $user_id, $client = null)
    {
        $buyer = self::buyer_del_cliente($client_id, $user_id, 'email');

        foreach ([$buyer ? $buyer->email : null, $client ? $client->email : null] as $candidato) {
            $email = self::email_valido($candidato);

            if (!is_null($email)) {
                return $email;
            }
        }

        return null;
    }

    /**
     * Misma validación que ComercioCityMailHelper::new_sale() (:39-42): trim y
     * filter_var. Un email inválido cargado a mano no puede tirar el envío.
     *
     * @param string|null $raw
     * @return string|null
     */
    public static function email_valido($raw)
    {
        $email = is_null($raw) ? '' : trim((string) $raw);

        return $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
    }

    /**
     * La URL de la tienda del comercio, normalizada, o null si no tiene.
     *
     * 🔴 EL FORMATO DE `users.online` NO ES UNIFORME: medido el 15/8/2026,
     * `empresa` la tiene como `tienda.local:8001` (SIN esquema) y `leudinox`
     * como `http://tienda.local:8081` (CON esquema); `prueba`, `ferretotal` y
     * `golonorte_bien` la tienen VACÍA. Sin normalizar, el link sin esquema se
     * abre como ruta relativa y no lleva a ninguna parte. Y si está vacía, el
     * mensaje sale SIN link: degrada, no rompe.
     * ⚠️ No confundir con `articles.online`, que es un booleano (si el artículo
     * se publica en la tienda).
     *
     * @param mixed $user
     * @return string|null
     */
    public static function url_de_la_tienda($user)
    {
        $online = is_null($user) || is_null($user->online) ? '' : trim((string) $user->online);

        if ($online === '') {
            return null;
        }

        // substr y no str_starts_with: el repo es PHP 7.4.
        return substr($online, 0, 4) === 'http' ? $online : 'https://' . $online;
    }

    /**
     * El descuento en criollo, para el mail y para el WhatsApp. 'unidad' →
     * "15% de descuento"; 'cantidad' → los tramos, en orden.
     *
     * @param ClientOffer $offer Con la relación `ranges` cargable.
     * @return string
     */
    public static function descripcion_del_descuento(ClientOffer $offer)
    {
        if ($offer->tipo_descuento !== 'cantidad') {
            return self::numero($offer->porcentaje) . '% de descuento';
        }

        $offer->loadMissing('ranges');

        $partes = [];

        foreach ($offer->ranges->sortBy('min') as $range) {
            // max null = sin techo, la convención de category_price_type_ranges.
            $cantidad = is_null($range->max)
                ? (int) $range->min . ' o más'
                : 'de ' . (int) $range->min . ' a ' . (int) $range->max;

            $partes[] = 'llevando ' . $cantidad . ', ' . self::numero($range->porcentaje) . '%';
        }

        return empty($partes) ? 'descuento por cantidad' : 'descuento por cantidad: ' . implode('; ', $partes);
    }

    /**
     * El texto del mensaje de WhatsApp, en criollo y con el link a la tienda
     * cuando lo hay. Texto PLANO: de encodearlo se ocupa link_de_whatsapp().
     *
     * @param ClientOffer $offer
     * @param mixed $user Dueño del comercio (para users.online).
     * @return string
     */
    public static function texto_del_mensaje(ClientOffer $offer, $user)
    {
        $offer->loadMissing('client', 'article');

        $nombre_cliente = $offer->client && !empty($offer->client->name) ? $offer->client->name : '';
        $nombre_articulo = $offer->article && !empty($offer->article->name) ? $offer->article->name : 'un artículo';

        $texto = ($nombre_cliente !== '' ? 'Hola ' . $nombre_cliente . '! ' : 'Hola! ')
            . 'Te preparamos una oferta en ' . $nombre_articulo . ': '
            . self::descripcion_del_descuento($offer) . '. '
            . 'Válida hasta el ' . self::fecha($offer->hasta) . '.';

        $tienda = self::url_de_la_tienda($user);

        if (!is_null($tienda)) {
            $texto .= ' Entrá a la tienda: ' . $tienda;
        }

        return $texto;
    }

    /**
     * El par (teléfono E.164, link click-to-chat) de una oferta, o los dos en
     * null si el teléfono cargado no da para armar un link confiable.
     *
     * @param ClientOffer $offer
     * @param mixed $user
     * @return array ['telefono' => string|null, 'url' => string|null]
     */
    public static function whatsapp(ClientOffer $offer, $user)
    {
        $offer->loadMissing('client');

        $telefono = self::a_e164_del_cliente($offer->client_id, $offer->user_id, $offer->client);

        if (is_null($telefono)) {
            // Sin número usable no hay botón: mejor ninguno que uno que abre
            // WhatsApp en la nada. La oferta ya quedó activa igual.
            return ['telefono' => null, 'url' => null];
        }

        return [
            'telefono' => $telefono,
            'url'      => self::link_de_whatsapp($telefono, self::texto_del_mensaje($offer, $user)),
        ];
    }

    /**
     * Molde: empresa-spa/src/common-vue/sale-print-buttons/WhatsappBtn.vue:204-208.
     *
     * @param string $telefono_e164 Solo dígitos.
     * @param string $texto Texto plano.
     * @return string
     */
    public static function link_de_whatsapp($telefono_e164, $texto)
    {
        /*
         * 🔴 rawurlencode() NO ES DEFENSIVO: sin él, un `&` en el nombre del
         * artículo ("Tuerca & Bulón") cierra el parámetro `text` y el mensaje
         * llega cortado a la mitad — el comentario de WhatsappBtn.vue:194-202
         * cuenta exactamente ese caso. Tampoco sirve urlencode() a secas: manda
         * `+` por los espacios, que WhatsApp muestra literales.
         */
        return 'https://api.whatsapp.com/send?phone=' . $telefono_e164 . '&text=' . rawurlencode($texto);
    }

    /**
     * Teléfono del cliente en E.164, primero de `clients.phone` y cayendo al
     * `buyers.phone`. El orden es el INVERSO al del email a propósito: el
     * teléfono medido es el de `clients` (74 de 496 lo tienen), mientras que
     * `buyers.phone` es opcional en el registro de la tienda — el buyer queda
     * como red y solo puede agregar links donde no había ninguno.
     *
     * @param int $client_id
     * @param int $user_id
     * @param mixed $client
     * @return string|null
     */
    protected static function a_e164_del_cliente($client_id, $user_id, $client = null)
    {
        $buyer = self::buyer_del_cliente($client_id, $user_id, 'phone');

        foreach ([$client ? $client->phone : null, $buyer ? $buyer->phone : null] as $candidato) {
            $telefono = TelefonoArgentinoHelper::a_e164($candidato);

            if (!is_null($telefono)) {
                return $telefono;
            }
        }

        return null;
    }

    /**
     * El buyer del ecommerce vinculado a este cliente que tenga cargada la
     * columna pedida. El vínculo `buyers.comercio_city_client_id` es OPCIONAL y
     * MANUAL (BuyerController.php:50, ProcessArchivoDeIntercambioClientes.php:113):
     * la enorme mayoría de los clientes no tiene ninguno, así que null acá es el
     * caso normal y no un error.
     *
     * @param int $client_id
     * @param int $user_id
     * @param string $columna 'email' | 'phone'
     * @return Buyer|null
     */
    protected static function buyer_del_cliente($client_id, $user_id, $columna)
    {
        return Buyer::where('comercio_city_client_id', $client_id)
            ->where('user_id', $user_id)
            ->whereNotNull($columna)
            ->orderBy('id', 'DESC')
            ->first();
    }

    /**
     * @param mixed $valor
     * @return string Porcentaje sin decimales muertos ("15" y no "15,00").
     */
    protected static function numero($valor)
    {
        return (string) ((float) $valor + 0);
    }

    /** @param mixed $fecha @return string dd/mm/aaaa */
    protected static function fecha($fecha)
    {
        return empty($fecha) ? '' : Carbon::parse($fecha)->format('d/m/Y');
    }
}
