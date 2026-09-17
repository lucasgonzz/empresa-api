<?php

namespace App\Http\Controllers\Helpers\ofertas;

use App\Http\Controllers\Helpers\ComercioCityMailHelper;
use App\Http\Controllers\Helpers\OfertaComunicacionHelper;
use App\Models\Article;
use App\Models\Client;
use App\Models\ClientOffer;
use App\Models\ClientOfferRange;
use App\Models\OfferSuggestionLine;
use App\Models\User;
use App\Services\OfertasClientes\OfertaSugeridaService;
use App\Services\OfertasClientes\TechoDeDescuentoService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 🔴 EL ÚNICO CAMINO DE ESCRITURA DE `client_offers`, LA TABLA QUE LEE LA TIENDA.
 *
 * Es el cuerpo de ClientOfferController::activar() mudado a un helper público en la misión
 * agente-ia-mano-derecha (16/9/2026), con UN solo cambio de firma: recibe `client_id` + `article_id`
 * en vez de un OfferSuggestionLine, y la línea sugerida pasa a ser un parámetro OPCIONAL. El motivo
 * es que el asistente de IA tiene que poder activar una oferta que el dueño pidió en palabras, y esa
 * oferta no nace de ninguna corrida del motor de sugerencias.
 *
 * ✅ POR QUÉ ESO SE PUEDE: el acoplamiento con el motor era de plomería, no de negocio.
 * TechoDeDescuentoService::calcular($article, $client, $user, $factor_credito) NO recibe la línea:
 * toma artículo, cliente y dueño. El controller usaba `$linea` solamente para sacar de ahí esos dos
 * ids. Una oferta nacida de un pedido del dueño calcula su techo con las MISMAS reglas.
 *
 * 🔴 LO QUE NO SE PUEDE PERDER AL EXTRAER, y por eso está todo acá adentro y no repartido:
 * el lockForUpdate() sobre el par (comercio, cliente, artículo) —el único lugar donde vive el
 * invariante "una sola oferta ACTIVA por par"—, el techo de descuento (422 si se pasa, nunca un
 * recorte en silencio), el tope de vigencia, la validación de tramos y el `porcentaje` NULL cuando
 * el tipo es 'cantidad'.
 *
 * 🔴 Y NO CAMBIA LA FORMA DE `client_offers` NI DE `client_offer_ranges`: `tienda-api` las lee
 * directo de la base compartida con la query que está escrita textual en la migración
 * 2026_08_17_100200_create_client_offers_table.php:15-33. Toda oferta que se cree por acá —venga de
 * la pantalla o del chat— cae adentro de esa query: user_id + client_id + article_id + estado
 * 'activa' + desde <= hoy <= hasta.
 *
 * ⚠️ EL AVISO AL CLIENTE (mail + link de WhatsApp) VA POR PARÁMETRO Y NACE APAGADO. La pantalla lo
 * prende; el asistente NO. Mandarle un mail a un cliente real desde una tarjeta del chat es un
 * efecto que no se puede deshacer, y no es lo que se pidió.
 *
 * PHP 7.4: sin match, ?->, str_contains, argumentos nombrados ni tipos union.
 */
class ClientOfertaAltaHelper {

    /**
     * Vigencia máxima: más allá deja de ser una oferta y pasa a ser el precio. 🔴 NO ES UN 180
     * escrito acá: apunta al del servicio, que es de donde también sale el tope que se le permite
     * proponer a la IA (OfertaSugeridaService::tope_de_vigencia()). Antes eran dos números
     * independientes y el motor precargaba en el datepicker fechas que la activación rechazaba
     * con 422. ClientOfferController::MAX_DIAS_VIGENCIA apunta acá.
     */
    const MAX_DIAS_VIGENCIA = OfertaSugeridaService::DIAS_VIGENCIA_MAXIMOS;

    /**
     * Registra la promoción vigente, EN UNA TRANSACCIÓN junto con el vencimiento de la anterior del
     * mismo par, y —solo si se lo pide— avisa después al cliente.
     *
     * 🔴 ACÁ SE SOSTIENE EL INVARIANTE "una sola oferta ACTIVA por (comercio, cliente, artículo)".
     * No hay unique en la base a propósito (una cancelada tiene que poder convivir con una activa
     * nueva: el historial no se borra), así que el invariante vive en este bloque y en la transacción
     * que lo envuelve. Si alguien crea un ClientOffer por fuera de este método lo rompe, y la tienda
     * pasaría a ver dos ofertas del mismo artículo sin desempate.
     *
     * 🔴 Y POR ESO EL lockForUpdate(), QUE NO ES DECORACIÓN: un UPDATE seguido de un INSERT, sin
     * unique y sin lock, no es atómico frente a otra activación del MISMO par corriendo en paralelo
     * (dos pestañas, un doble clic, dos workers, la pantalla y el chat a la vez). Las dos
     * transacciones leen "no hay activa", las dos cancelan cero filas y las dos insertan: quedan DOS
     * activas. Con el lock, la segunda espera a que la primera cierre y recién ahí ve la fila que
     * tiene que cancelar.
     *
     * Se lockea el PAR ENTERO y no solo las activas: el invariante es sobre el par, y así el gap lock
     * cubre el lugar donde la otra transacción querría insertar. El historial de un par es un puñado
     * de filas y entra por client_offers_user_client_article_estado_index. 🔴 No cambiar esto por un
     * unique en la base: una cancelada o una vencida TIENEN que poder convivir con la activa nueva.
     *
     * @param int $user_id Dueño del comercio.
     * @param int $client_id Cliente al que se le ofrece.
     * @param int $article_id Artículo de la oferta.
     * @param string $tipo_descuento 'unidad' | 'cantidad'
     * @param int|null $porcentaje null cuando es 'cantidad': el número vive en los tramos.
     * @param \Illuminate\Support\Carbon $hasta
     * @param array $tramos
     * @param \App\Models\OfferSuggestionLine|null $linea Línea sugerida de origen, si la hay.
     * @param bool $avisar_al_cliente true para mandar el mail y armar el link de WhatsApp.
     * @return ClientOffer
     */
    public static function activar($user_id, $client_id, $article_id, $tipo_descuento, $porcentaje, $hasta, array $tramos, $linea = null, $avisar_al_cliente = false) {

        $user_id = (int) $user_id;
        $client_id = (int) $client_id;
        $article_id = (int) $article_id;

        $offer = DB::transaction(function () use ($user_id, $client_id, $article_id, $tipo_descuento, $porcentaje, $hasta, $tramos, $linea) {

            $del_par = ClientOffer::where('user_id', $user_id)
                ->where('client_id', $client_id)
                ->where('article_id', $article_id)
                ->lockForUpdate()
                ->get(['id', 'estado']);

            $anteriores = [];
            foreach ($del_par as $vieja) {
                if ($vieja->estado === 'activa') {
                    $anteriores[] = $vieja->id;
                }
            }
            if (!empty($anteriores)) {
                ClientOffer::whereIn('id', $anteriores)->update(['estado' => 'cancelada']);
                /*
                 * 🔴 Y LA LÍNEA VIEJA SE DESAPUNTA, igual que en destroy(). Sin esto, la línea de la
                 * corrida anterior se queda con el client_offer_id de una oferta que ya NO corre y la
                 * vista le sigue mostrando el badge "Activada" apuntando a una cancelada: el
                 * comerciante cree que esa promoción está viva. La trazabilidad no se pierde,
                 * client_offers.offer_suggestion_line_id sigue apuntando para el otro lado.
                 *
                 * 🔴 Corre SIEMPRE, también cuando la oferta nueva la pidió el dueño por el chat: lo
                 * que se desapunta es la línea de la oferta que se acaba de cancelar, no la de esta.
                 */
                OfferSuggestionLine::whereIn('client_offer_id', $anteriores)->update(['client_offer_id' => null]);
            }
            $offer = ClientOffer::create([
                'user_id'                  => $user_id,
                'client_id'                => $client_id,
                'article_id'               => $article_id,
                'tipo_descuento'           => $tipo_descuento,
                // 🔴 En 'cantidad' el porcentaje queda NULL a propósito: el número vive en los
                // tramos, y dejarlo cargado haría que un lector distraído (la tienda incluida)
                // aplique el equivocado.
                'porcentaje'               => $tipo_descuento === 'cantidad' ? null : $porcentaje,
                'desde'                    => Carbon::today()->toDateString(),
                'hasta'                    => $hasta->toDateString(),
                'estado'                   => 'activa',
                // null cuando la oferta no nació de una sugerencia: la columna es nullable
                // justamente por eso ("Trazabilidad; null si algún día se carga una oferta a mano",
                // docblock de la migración).
                'offer_suggestion_line_id' => is_null($linea) ? null : $linea->id,
            ]);
            if ($tipo_descuento === 'cantidad') {
                foreach ($tramos as $tramo) {
                    ClientOfferRange::create([
                        'client_offer_id' => $offer->id,
                        'min'             => (int) $tramo['min'],
                        // max null = sin techo, la convención que la tienda ya sabe leer
                        // (category_price_type_ranges): un número grande en su lugar rompe el lado
                        // de allá.
                        'max'             => self::max_del_tramo($tramo),
                        'porcentaje'      => (int) $tramo['porcentaje'],
                    ]);
                }
            }
            if (!is_null($linea)) {
                // Lo que la vista mira para mostrar "ya activada".
                $linea->client_offer_id = $offer->id;
                $linea->save();
            }

            return $offer;
        });

        if ($avisar_al_cliente) {

            self::avisarle_al_cliente($offer);
        }

        return $offer;
    }

    /**
     * El mail y el link de WhatsApp, SIEMPRE después de que la promoción quedó registrada y siempre
     * fuera de la transacción. 🔴 EL try/catch NO ES DEFENSIVA VAGA: un SMTP mal configurado del
     * comercio o una tabla `jobs` sin permisos tiran una excepción, y sin esto el comerciante vería
     * un 500 sobre una oferta que YA quedó activa en la base: la volvería a activar y se duplicaría
     * el aviso. Se loguea y se sigue.
     *
     * @param ClientOffer $offer
     * @return void
     */
    public static function avisarle_al_cliente($offer) {

        $user = User::find($offer->user_id);

        try {
            $email = ComercioCityMailHelper::nueva_oferta($offer);

            if (!is_null($email)) {
                $offer->email_destino = $email;
                $offer->notificada_email_at = Carbon::now();
            }
        } catch (\Throwable $e) {
            Log::error('Motor de ofertas: no salió el mail de la oferta ' . $offer->id . ': ' . $e->getMessage());
        }
        try {
            $whatsapp = OfertaComunicacionHelper::whatsapp($offer, $user);
            $offer->whatsapp_telefono = $whatsapp['telefono'];
            $offer->whatsapp_url = $whatsapp['url'];
        } catch (\Throwable $e) {
            Log::error('Motor de ofertas: no se armó el link de whatsapp de la oferta ' . $offer->id . ': ' . $e->getMessage());
        }

        $offer->save();
    }

    /**
     * 🔴 EL TECHO CON LOS DATOS DE HOY, y no con el `porcentaje_techo` guardado en una línea: entre
     * que el motor sugirió y el comerciante activa pueden pasar días, y en el medio pudo subir el
     * costo o bajar el precio. Activar contra un techo viejo es exactamente cómo se termina vendiendo
     * bajo costo con el sistema diciendo que estaba todo bien. Vale igual para la tarjeta del
     * asistente, que puede quedar sin confirmar hasta 24 horas.
     *
     * @param int $user_id
     * @param int $client_id
     * @param int $article_id
     * @return array|string El array de TechoDeDescuentoService, o el error.
     */
    public static function techo_de_hoy($user_id, $client_id, $article_id) {

        $article = Article::where('id', (int) $article_id)
            ->where('user_id', (int) $user_id)
            ->with('price_types', 'provider')
            ->first();
        if (!$article) {
            return 'El artículo de esta sugerencia ya no existe en el catálogo.';
        }
        $client = Client::where('id', (int) $client_id)->first();
        $techo = TechoDeDescuentoService::calcular($article, $client, User::find((int) $user_id));

        if (is_null($techo)) {
            // calcular() devuelve null cuando el artículo quedó excluido HOY (sin costo, dólares con
            // listas, margen no positivo...). Sin techo no hay descuento seguro posible: no se
            // activa nada.
            return 'Hoy no se puede calcular un descuento seguro para este artículo: revisá que tenga costo y precio cargados.';
        }

        return $techo;
    }

    /**
     * @param mixed $raw
     * @return \Illuminate\Support\Carbon|string La fecha, o el mensaje de error.
     */
    public static function validar_hasta($raw) {

        if (empty($raw)) {
            return 'Falta la fecha hasta la que vale la oferta.';
        }
        try {
            $hasta = Carbon::parse($raw)->startOfDay();
        } catch (\Throwable $e) {
            return 'La fecha hasta la que vale la oferta no es una fecha válida.';
        }
        if ($hasta->lt(Carbon::today())) {
            return 'La oferta no puede vencer antes de hoy.';
        }
        if ($hasta->gt(Carbon::today()->addDays(self::MAX_DIAS_VIGENCIA))) {
            return 'La oferta no puede durar más de ' . self::MAX_DIAS_VIGENCIA . ' días.';
        }

        return $hasta;
    }

    /**
     * Valida los tramos de una oferta 'cantidad': arrancan en 1, son contiguos y sin huecos, el
     * último no tiene techo, y NINGUNO supera el techo de hoy.
     * 🔴 Un hueco (max 5 y el siguiente min 8) deja al comprador que lleva 6 unidades sin ningún
     * descuento aplicable, y la tienda no tiene cómo saber si eso fue a propósito: se frena acá.
     *
     * @param array $tramos
     * @param int $techo
     * @return string|null El error, o null si están bien.
     */
    public static function validar_tramos(array $tramos, $techo) {

        if (empty($tramos)) {
            return 'Una oferta por cantidad necesita al menos un tramo.';
        }
        $ultimo = count($tramos) - 1;
        $esperado_min = 1;

        foreach ($tramos as $i => $tramo) {
            if (!isset($tramo['min']) || !isset($tramo['porcentaje'])) {
                return 'Cada tramo tiene que tener una cantidad mínima y un porcentaje.';
            }
            $min = (int) $tramo['min'];
            $max = self::max_del_tramo($tramo);
            $porcentaje = (int) $tramo['porcentaje'];

            if ($min !== $esperado_min) {
                // Cubre las dos fallas de una: el primero que no arranca en 1, y el hueco o el
                // solapamiento entre dos tramos consecutivos.
                return 'Los tramos tienen que arrancar en 1 unidad y ser contiguos: se esperaba que el tramo ' . ($i + 1) . ' empezara en ' . $esperado_min . '.';
            }
            if ($porcentaje < 1) {
                return 'El porcentaje de cada tramo tiene que ser un número entero de al menos 1.';
            }
            if ($porcentaje > $techo) {
                return 'Con el costo y el precio de hoy, el descuento máximo de este artículo es ' . $techo . '%, y el tramo ' . ($i + 1) . ' pide ' . $porcentaje . '%.';
            }
            if ($i === $ultimo) {
                return is_null($max) ? null : 'El último tramo no lleva cantidad máxima: es el que vale de ahí en adelante.';
            }
            if (is_null($max) || $max < $min) {
                return 'El tramo ' . ($i + 1) . ' necesita una cantidad máxima mayor o igual a su mínima.';
            }
            $esperado_min = $max + 1;
        }

        return null;
    }

    /**
     * `max` null (o vacío, que es como lo manda un input limpiado en la SPA) = "sin techo", y es
     * siempre el último tramo.
     *
     * @param array $tramo
     * @return int|null
     */
    public static function max_del_tramo(array $tramo) {

        $vacio = !isset($tramo['max']) || is_null($tramo['max']) || $tramo['max'] === '';
        return $vacio ? null : (int) $tramo['max'];
    }
}
