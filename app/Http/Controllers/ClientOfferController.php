<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\ofertas\ClientOfertaAltaHelper;
use App\Models\ClientOffer;
use App\Models\OfferSuggestionLine;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * 🔴 LA PUERTA HTTP de `client_offers`, LA TABLA QUE LEE LA TIENDA (misión 5). No
 * hay endpoint entre los dos sistemas: comparten la base del cliente y la tienda
 * entra por SQL con la query textual del docblock de
 * 2026_08_17_100200_create_client_offers_table.php.
 *
 * 🔴 LA ESCRITURA EN SÍ NO VIVE ACÁ desde la misión agente-ia-mano-derecha
 * (16/9/2026): está en ClientOfertaAltaHelper, público, porque el asistente de IA
 * también activa ofertas —las que el dueño pide en palabras, sin línea sugerida— y
 * no puede pasar por este controller. Acá queda lo que es del HTTP: leer y validar
 * el request, resolver la línea sugerida del comercio y armar la respuesta. El
 * payload y las respuestas de `POST api/client-offer` no cambiaron.
 *
 * Activar sigue haciendo TRES cosas y el orden importa (§A.8): (a) registra la
 * promoción vigente, EN UNA TRANSACCIÓN junto con el vencimiento de la anterior
 * del mismo par; (b) manda el mail; (c) arma el link de WhatsApp click-to-chat.
 * (b) y (c) van por el parámetro `$avisar_al_cliente` del helper, que este camino
 * —el de la pantalla— prende siempre.
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
     * 180 escrito acá: apunta al del helper, que apunta al del servicio, que es de donde
     * también sale el tope que se le permite proponer a la IA
     * (OfertaSugeridaService::tope_de_vigencia()). Antes eran dos números independientes y el
     * motor precargaba en el datepicker fechas que esta misma activación rechazaba con 422.
     * Se conserva acá porque hay tests que la leen por esta constante.
     */
    const MAX_DIAS_VIGENCIA = ClientOfertaAltaHelper::MAX_DIAS_VIGENCIA;

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
        $hasta = ClientOfertaAltaHelper::validar_hasta($request->input('hasta'));
        if (!($hasta instanceof Carbon)) {
            return response()->json(['message' => $hasta], 422);
        }
        $techo = ClientOfertaAltaHelper::techo_de_hoy($this->userId(), $linea->client_id, $linea->article_id);
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
        /*
         * 🔴 El último parámetro en `true` es el aviso al cliente (mail + link de WhatsApp): este
         * camino —el de la pantalla, donde el comerciante tocó "Activar" mirando a quién se la
         * manda— lo prende siempre. El del asistente de IA lo deja apagado.
         */
        $offer = ClientOfertaAltaHelper::activar(
            $this->userId(),
            $linea->client_id,
            $linea->article_id,
            $tipo_descuento,
            $porcentaje,
            $hasta,
            $tramos,
            $linea,
            true
        );

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
     * Valida los tramos de una oferta 'cantidad'. 🔴 La regla vive en
     * ClientOfertaAltaHelper::validar_tramos() desde la misión agente-ia-mano-derecha
     * (16/9/2026), junto con el resto de la activación; este método queda como delegación
     * de una línea porque hay un test del motor de ofertas que lo invoca por reflexión sobre
     * este controller para atar las dos puntas (lo que la IA propone tiene que poder
     * activarse): tests/Feature/MotorDeOfertas/5_Recorte_de_la_ia_Test.php.
     *
     * @param array $tramos
     * @param int $techo
     * @return string|null El error, o null si están bien.
     */
    protected function validar_tramos(array $tramos, $techo) {
        return ClientOfertaAltaHelper::validar_tramos($tramos, $techo);
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
