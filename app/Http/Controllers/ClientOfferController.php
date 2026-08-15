<?php

namespace App\Http\Controllers;

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
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * 🔴 EL ÚNICO CAMINO DE ESCRITURA DE `client_offers`, LA TABLA QUE LEE LA TIENDA
 * (misión 5). No hay endpoint entre los dos sistemas: comparten la base del
 * cliente y la tienda entra por SQL con la query textual del docblock de
 * 2026_08_17_100200_create_client_offers_table.php. Activar hace TRES cosas y el
 * orden importa (§A.8): (a) registra la promoción vigente, EN UNA TRANSACCIÓN
 * junto con el vencimiento de la anterior del mismo par; (b) manda el mail;
 * (c) arma el link de WhatsApp click-to-chat.
 *
 * 🔴 LA OFERTA SE ACTIVA AUNQUE NO SE PUEDA AVISAR. (b) y (c) van FUERA de la
 * transacción y no pueden fallar hacia arriba: medido, el 84% de los clientes no
 * tiene ni mail ni teléfono (417 de 496 en `prueba`). Sin mail no sale el mail,
 * sin teléfono no hay link, y la promoción queda vigente igual con el motivo
 * registrado (notificada_email_at / whatsapp_url en null). NUNCA un error: el
 * canal principal es la tienda mostrándosela al comprador cuando entra.
 */
class ClientOfferController extends Controller
{
    /**
     * Vigencia máxima: más allá deja de ser una oferta y pasa a ser el precio. 🔴 NO ES UN
     * 180 escrito acá: apunta al del servicio, que es de donde también sale el tope que se le
     * permite proponer a la IA (OfertaSugeridaService::tope_de_vigencia()). Antes eran dos
     * números independientes y el motor precargaba en el datepicker fechas que este mismo
     * controller después rechazaba con 422.
     */
    const MAX_DIAS_VIGENCIA = OfertaSugeridaService::DIAS_VIGENCIA_MAXIMOS;

    /** Paginador, mismo criterio que OfferSuggestionController. */
    const PER_PAGE_MAX = 500;
    const PER_PAGE_DEFAULT = 50;

    /**
     * Listado del comercio. Filtros: estado (default 'activa') y client_id.
     * Ordenado por `hasta` ASC —lo que está por vencerse va arriba— y entra por
     * client_offers_user_estado_hasta_index.
     *
     * @param Request $request estado, client_id, per_page, page
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request) {
        $per_page = (int) $request->input('per_page', self::PER_PAGE_DEFAULT);
        $per_page = $per_page <= 0 ? self::PER_PAGE_DEFAULT : $per_page;
        $per_page = $per_page > self::PER_PAGE_MAX ? self::PER_PAGE_MAX : $per_page;
        $query = ClientOffer::withAll()
            ->where('user_id', $this->userId())
            ->where('estado', $request->filled('estado') ? $request->estado : 'activa');

        if ($request->filled('client_id')) {
            $query->where('client_id', (int) $request->client_id);
        }
        $models = $query->orderBy('hasta', 'ASC')->orderBy('id', 'ASC')->paginate($per_page);

        return response()->json(['models' => $models], 200);
    }

    /**
     * Activa una línea sugerida: la convierte en la promoción vigente que la
     * tienda va a leer. Contrato congelado: 201 con ['model'], 404 si la línea
     * no es del comercio, 422 si el porcentaje supera el techo de HOY.
     *
     * @param Request $request offer_suggestion_line_id, porcentaje, hasta, tipo_descuento, tramos
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request) {
        // 🔴 Casteo a int ANTES de validar: un campo vaciado en la SPA
        // (v-model.number) manda "" y las reglas min/max de Laravel NO corren
        // sobre strings vacíos si la regla no es "required" — se saltearían en
        // silencio justo el caso que hay que frenar. Casteado, "" cae a 0 y lo
        // atrapa el mínimo. El porcentaje es ENTERO en todo el motor: el techo
        // sale de un floor() (§A.4.3) y así lo comunica el comerciante.
        $linea_id  = (int) $request->input('offer_suggestion_line_id', 0);
        $validator = Validator::make(['offer_suggestion_line_id' => $linea_id], [
            // 'integer' obligatorio: sin una regla numérica en el set, Laravel
            // compara min/max por mb_strlen() (regla de strings) y "0" mide 1
            // carácter, así que pasaría un min:1 igual.
            'offer_suggestion_line_id' => 'integer|min:1',
        ], [
            'offer_suggestion_line_id.min' => 'Falta la línea sugerida que se quiere activar.',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }
        $linea = $this->linea_del_usuario($linea_id);
        if (!$linea) {
            return response()->json(['message' => 'Línea sugerida no encontrada.'], 404);
        }
        $tipo_descuento = $request->input('tipo_descuento', $linea->tipo_descuento);
        if ($tipo_descuento !== 'unidad' && $tipo_descuento !== 'cantidad') {
            return response()->json(['message' => 'El tipo de descuento tiene que ser "unidad" o "cantidad".'], 422);
        }
        /*
         * 🔴 EL PORCENTAJE SE VALIDA SOLO EN LA RAMA 'unidad', Y POR ESO tipo_descuento
         * SE LEE ANTES QUE ÉL. En 'cantidad' el número vive en los tramos y la SPA manda
         * `porcentaje: null` a propósito (ModalActivar.vue:328), que es la misma
         * convención con la que se guarda la fila (ver activar()).
         *
         * Lo que había acá castigaba justamente ese contrato: `input('porcentaje', 0)`
         * con la clave PRESENTE en null devuelve null —el default de input() solo aplica
         * cuando la clave FALTA—, `(int) null` da 0, y el `min:1` lo rechazaba. Resultado
         * medido con el payload exacto de la SPA: NINGUNA oferta por cantidad se podía
         * activar, siempre 422, y el toast hablaba de un campo que ni siquiera está en
         * pantalla (el input del porcentaje está detrás de un v-if="tipo_descuento ==
         * 'unidad'"), así que el comerciante no tenía forma de salir del error.
         *
         * 🔴 NO "arreglar" esto mandando un porcentaje de relleno desde la SPA ni
         * casteando null a un número acá: en 'cantidad' un porcentaje suelto es un dato
         * que alguien —la tienda incluida— puede llegar a aplicar en vez del tramo que
         * corresponde. Lo que se valida en 'cantidad' son los tramos, más abajo.
         */
        $porcentaje = null;

        if ($tipo_descuento === 'unidad') {
            $porcentaje = (int) $request->input('porcentaje', 0);
            $validator  = Validator::make(['porcentaje' => $porcentaje], [
                'porcentaje' => 'integer|min:1|max:100',
            ], [
                'porcentaje.min' => 'El porcentaje de descuento tiene que ser un número entero de al menos 1.',
                'porcentaje.max' => 'El porcentaje de descuento no puede superar el 100.',
            ]);
            if ($validator->fails()) {
                return response()->json(['message' => $validator->errors()->first()], 422);
            }
        }
        $hasta = $this->validar_hasta($request->input('hasta'));
        if (!($hasta instanceof Carbon)) {
            return response()->json(['message' => $hasta], 422);
        }
        $techo = $this->techo_de_hoy($linea);
        if (!is_array($techo)) {
            return response()->json(['message' => $techo], 422);
        }
        $tramos = $request->input('tramos', []);
        $tramos = is_array($tramos) ? $tramos : [];

        if ($tipo_descuento === 'cantidad') {
            $error = $this->validar_tramos($tramos, $techo['techo']);
            if ($error !== null) {
                return response()->json(['message' => $error], 422);
            }
        } elseif ($porcentaje > $techo['techo']) {
            // 🔴 NO SE RECORTA EN SILENCIO: 422 con el techo de hoy adentro del
            // mensaje. El comerciante pidió un número y tiene que enterarse de
            // que no se puede, no descubrir después que salió otro. Recortar
            // callado es exactamente cómo se vende bajo costo sin que nadie lo
            // note hasta el cierre del mes.
            return response()->json([
                'message' => 'Con el costo y el precio de hoy, el descuento máximo de este artículo es ' . $techo['techo'] . '%.',
                'porcentaje_techo' => $techo['techo'],
            ], 422);
        }
        $offer = $this->activar($linea, $tipo_descuento, $porcentaje, $hasta, $tramos);
        $this->avisarle_al_cliente($offer);

        return response()->json(['model' => $this->fullModel('ClientOffer', $offer->id)], 201);
    }

    /**
     * Da de baja una oferta. 🔴 NO BORRA LA FILA: le pone estado 'cancelada'. El
     * historial de qué se le ofreció a quién es parte del valor del módulo, y la
     * tienda ya filtra por estado + fechas: una cancelada deja de aplicarse sola.
     *
     * @param int $id
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function destroy($id) {
        $offer = ClientOffer::where('user_id', $this->userId())->where('id', $id)->first();
        if (!$offer) {
            return response()->json(['message' => 'Oferta no encontrada.'], 404);
        }
        DB::transaction(function () use ($offer) {
            $offer->estado = 'cancelada';
            $offer->save();
            // La línea vuelve a quedar "sin activar" para poder re-activarla
            // desde la misma corrida y para que solo_sin_activar la muestre de
            // nuevo. La trazabilidad no se pierde:
            // client_offers.offer_suggestion_line_id la sigue apuntando.
            OfferSuggestionLine::where('client_offer_id', $offer->id)->update(['client_offer_id' => null]);
        });

        $this->sendDeleteModelNotification('ClientOffer', $offer->id);

        return response(null, 204);
    }

    /**
     * (a) La promoción vigente, en UNA transacción con el vencimiento de la
     * anterior del mismo par.
     *
     * @param OfferSuggestionLine $linea
     * @param string $tipo_descuento
     * @param int|null $porcentaje null cuando es 'cantidad': el número vive en los tramos.
     * @param Carbon $hasta
     * @param array $tramos
     * @return ClientOffer
     */
    protected function activar($linea, $tipo_descuento, $porcentaje, $hasta, array $tramos) {
        $user_id = $this->userId();
        return DB::transaction(function () use ($linea, $tipo_descuento, $porcentaje, $hasta, $tramos, $user_id) {
            /*
             * 🔴 ACÁ SE SOSTIENE EL INVARIANTE "una sola oferta ACTIVA por
             * (comercio, cliente, artículo)". No hay unique en la base a
             * propósito (una cancelada tiene que poder convivir con una activa
             * nueva: el historial no se borra), así que el invariante vive en
             * este bloque y en la transacción que lo envuelve. Si alguien crea
             * un ClientOffer por fuera de este método lo rompe, y la tienda
             * pasaría a ver dos ofertas del mismo artículo sin desempate.
             *
             * 🔴 Y POR ESO EL lockForUpdate(), QUE NO ES DECORACIÓN: un UPDATE
             * seguido de un INSERT, sin unique y sin lock, no es atómico frente
             * a otra activación del MISMO par corriendo en paralelo (dos pestañas,
             * un doble clic, dos workers). Las dos transacciones leen "no hay
             * activa", las dos cancelan cero filas y las dos insertan: quedan DOS
             * activas, que es exactamente lo que el docblock de la migración dice
             * que no puede pasar. Con el lock, la segunda espera a que la primera
             * cierre y recién ahí ve la fila que tiene que cancelar.
             *
             * Se lockea el PAR ENTERO y no solo las activas: el invariante es
             * sobre el par, y así el gap lock cubre el lugar donde la otra
             * transacción querría insertar. El historial de un par es un puñado
             * de filas y entra por client_offers_user_client_article_estado_index.
             * 🔴 No cambiar esto por un unique en la base: una cancelada o una
             * vencida TIENEN que poder convivir con la activa nueva.
             */
            $del_par = ClientOffer::where('user_id', $user_id)
                ->where('client_id', $linea->client_id)
                ->where('article_id', $linea->article_id)
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
                 * 🔴 Y LA LÍNEA VIEJA SE DESAPUNTA, igual que en destroy(). Sin
                 * esto, la línea de la corrida anterior se queda con el
                 * client_offer_id de una oferta que ya NO corre y la vista le
                 * sigue mostrando el badge "Activada" apuntando a una cancelada:
                 * el comerciante cree que esa promoción está viva. La
                 * trazabilidad no se pierde, client_offers.offer_suggestion_line_id
                 * sigue apuntando para el otro lado.
                 */
                OfferSuggestionLine::whereIn('client_offer_id', $anteriores)->update(['client_offer_id' => null]);
            }
            $offer = ClientOffer::create([
                'user_id'                  => $user_id,
                'client_id'                => $linea->client_id,
                'article_id'               => $linea->article_id,
                'tipo_descuento'           => $tipo_descuento,
                // 🔴 En 'cantidad' el porcentaje queda NULL a propósito: el
                // número vive en los tramos, y dejarlo cargado haría que un
                // lector distraído (la tienda incluida) aplique el equivocado.
                'porcentaje'               => $tipo_descuento === 'cantidad' ? null : $porcentaje,
                'desde'                    => Carbon::today()->toDateString(),
                'hasta'                    => $hasta->toDateString(),
                'estado'                   => 'activa',
                'offer_suggestion_line_id' => $linea->id,
            ]);
            if ($tipo_descuento === 'cantidad') {
                foreach ($tramos as $tramo) {
                    ClientOfferRange::create([
                        'client_offer_id' => $offer->id,
                        'min'             => (int) $tramo['min'],
                        // max null = sin techo, la convención que la tienda ya
                        // sabe leer (category_price_type_ranges): un número
                        // grande en su lugar rompe el lado de allá.
                        'max'             => self::max_del_tramo($tramo),
                        'porcentaje'      => (int) $tramo['porcentaje'],
                    ]);
                }
            }
            // Lo que la vista mira para mostrar "ya activada".
            $linea->client_offer_id = $offer->id;
            $linea->save();

            return $offer;
        });
    }

    /**
     * (b) y (c): el mail y el link de WhatsApp, SIEMPRE después de que la
     * promoción quedó registrada y siempre fuera de la transacción. 🔴 EL
     * try/catch NO ES DEFENSIVA VAGA: un SMTP mal configurado del comercio o una
     * tabla `jobs` sin permisos tiran una excepción, y sin esto el comerciante
     * vería un 500 sobre una oferta que YA quedó activa en la base: la volvería
     * a activar y se duplicaría el aviso. Se loguea y se sigue.
     *
     * @param ClientOffer $offer
     * @return void
     */
    protected function avisarle_al_cliente($offer) {
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
     * 🔴 RE-VALIDA EL TECHO CON LOS DATOS DE HOY, y no con el `porcentaje_techo`
     * guardado en la línea: entre que el motor sugirió y el comerciante activa
     * pueden pasar días, y en el medio pudo subir el costo o bajar el precio.
     * Activar contra un techo viejo es exactamente cómo se termina vendiendo
     * bajo costo con el sistema diciendo que estaba todo bien.
     *
     * @param OfferSuggestionLine $linea
     * @return array|string El array de TechoDeDescuentoService, o el error.
     */
    protected function techo_de_hoy($linea) {
        $article = Article::where('id', $linea->article_id)
            ->where('user_id', $this->userId())
            ->with('price_types', 'provider')
            ->first();
        if (!$article) {
            return 'El artículo de esta sugerencia ya no existe en el catálogo.';
        }
        $client = Client::where('id', $linea->client_id)->first();
        $techo = TechoDeDescuentoService::calcular($article, $client, User::find($this->userId()));

        if (is_null($techo)) {
            // calcular() devuelve null cuando el artículo quedó excluido HOY
            // (sin costo, dólares con listas, margen no positivo...). Sin techo
            // no hay descuento seguro posible: no se activa nada.
            return 'Hoy no se puede calcular un descuento seguro para este artículo: revisá que tenga costo y precio cargados.';
        }

        return $techo;
    }

    /**
     * @param mixed $raw
     * @return Carbon|string La fecha, o el mensaje de error.
     */
    protected function validar_hasta($raw) {
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
     * Valida los tramos de una oferta 'cantidad': arrancan en 1, son contiguos y
     * sin huecos, el último no tiene techo, y NINGUNO supera el techo de hoy.
     * 🔴 Un hueco (max 5 y el siguiente min 8) deja al comprador que lleva 6
     * unidades sin ningún descuento aplicable, y la tienda no tiene cómo saber
     * si eso fue a propósito: se frena acá.
     *
     * @param array $tramos
     * @param int $techo
     * @return string|null El error, o null si están bien.
     */
    protected function validar_tramos(array $tramos, $techo) {
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
                // Cubre las dos fallas de una: el primero que no arranca en 1, y
                // el hueco o el solapamiento entre dos tramos consecutivos.
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
     * `max` null (o vacío, que es como lo manda un input limpiado en la SPA) =
     * "sin techo", y es siempre el último tramo.
     *
     * @param array $tramo
     * @return int|null
     */
    protected static function max_del_tramo(array $tramo) {
        $vacio = !isset($tramo['max']) || is_null($tramo['max']) || $tramo['max'] === '';
        return $vacio ? null : (int) $tramo['max'];
    }

    /**
     * 🔴 EL ÚNICO CAMINO POR ID hacia una línea: filtra por el dueño de la
     * CORRIDA, no por la línea suelta (offer_suggestion_lines no tiene user_id).
     * Sin este join, cualquiera activaría una oferta sobre el cliente y el
     * artículo de otro comercio mandando un id adivinado.
     *
     * @param int $id
     * @return OfferSuggestionLine|null
     */
    protected function linea_del_usuario($id) {
        return OfferSuggestionLine::where('offer_suggestion_lines.id', $id)
            ->join('offer_suggestions', 'offer_suggestions.id', '=', 'offer_suggestion_lines.offer_suggestion_id')
            ->where('offer_suggestions.user_id', $this->userId())
            ->select('offer_suggestion_lines.*')
            ->first();
    }
}
