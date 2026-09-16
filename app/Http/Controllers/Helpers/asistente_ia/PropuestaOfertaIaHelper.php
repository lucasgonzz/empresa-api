<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\ofertas\ClientOfertaAltaHelper;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Client;
use App\Models\ClientOffer;
use Illuminate\Support\Carbon;

/**
 * Oferta por cliente propuesta por el asistente de IA (misión agente-ia-mano-derecha, §D2 del plan):
 * un descuento sobre UN artículo para UN cliente, plano o por tramos de cantidad comprada.
 *
 * Se activa por ClientOfertaAltaHelper::activar(), el MISMO camino que `POST api/client-offer` del
 * modal de Promociones: el mismo lockForUpdate sobre el par (comercio, cliente, artículo) —único
 * sostén del invariante "una sola oferta activa por par", que no está en la base a propósito—, el
 * mismo techo de descuento, el mismo tope de vigencia y la misma validación de tramos.
 *
 * 🔴 LA DIFERENCIA CON LA PANTALLA ES UNA SOLA, Y ES DELIBERADA: el asistente NO manda el aviso al
 * cliente (mail + link de WhatsApp). Mandarle un mail a un cliente real desde una tarjeta del chat
 * es un efecto que no se puede deshacer y no es lo que se pidió; el canal principal de una oferta es
 * la tienda mostrándosela al comprador cuando entra, y eso funciona igual. La tarjeta lo dice.
 *
 * 🔴 Y NO SE TOCA LA FORMA DE `client_offers` NI DE `client_offer_ranges`: `tienda-api` las lee
 * directo de la base compartida con la query escrita textual en la migración
 * 2026_08_17_100200_create_client_offers_table.php:15-33. Toda oferta que cree el asistente cae
 * adentro de esa query.
 */
class PropuestaOfertaIaHelper {

    /**
     * Herramienta proponer_oferta.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input  cliente_id*, articulo_id*, hasta*, porcentaje | tramos, reemplaza_a
     * @return array  Respuesta de la herramienta (propuesta creada o respuesta de negocio).
     */
    static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input) {

        $corte = self::verificar_acceso($contexto);

        if (!is_null($corte)) {

            return RespuestaDeCargaIa::error($corte);
        }

        $client_id = (int) EntradaDeCargaIa::valor($input, 'cliente_id');
        $article_id = (int) EntradaDeCargaIa::valor($input, 'articulo_id');
        $hasta_pedido = EntradaDeCargaIa::valor($input, 'hasta');

        $tramos = EntradaDeCargaIa::valor($input, 'tramos');
        $tramos = is_array($tramos) ? $tramos : [];

        $porcentaje_pedido = EntradaDeCargaIa::valor($input, 'porcentaje');

        $faltan = [];

        if ($client_id <= 0) {

            $faltan[] = 'a qué cliente es la oferta';
        }

        if ($article_id <= 0) {

            $faltan[] = 'sobre qué artículo es la oferta';
        }

        if (!count($tramos) && EntradaDeCargaIa::vacio($porcentaje_pedido)) {

            $faltan[] = 'de cuánto es el descuento';
        }

        if (EntradaDeCargaIa::vacio($hasta_pedido)) {

            $faltan[] = 'hasta qué día vale la oferta';
        }

        if (count($faltan)) {

            // Sin `opciones`: el cliente y el artículo los busca la IA con consultar_clientes y
            // consultar_stock_de_articulos, que son herramientas de lectura y están siempre.
            return RespuestaDeCargaIa::faltan($faltan);
        }

        $client = Client::where('user_id', $contexto->owner_id)->where('id', $client_id)->first();

        if (is_null($client)) {

            return RespuestaDeCargaIa::error('No encontré ese cliente entre los tuyos.');
        }

        $article = Article::where('user_id', $contexto->owner_id)->where('id', $article_id)->first();

        if (is_null($article)) {

            return RespuestaDeCargaIa::error('No encontré ese artículo entre los tuyos.');
        }

        $hasta = ClientOfertaAltaHelper::validar_hasta($hasta_pedido);

        if (!($hasta instanceof Carbon)) {

            return RespuestaDeCargaIa::error($hasta);
        }

        /*
         * 🔴 EL TECHO DE DESCUENTO, CON LOS DATOS DE HOY. Es la regla que impide vender bajo costo,
         * y no se recorta en silencio: si el dueño pide más, se le dice cuánto es el máximo y se
         * frena. Recortar callado es exactamente cómo se vende bajo costo sin que nadie lo note
         * hasta el cierre del mes.
         */
        $techo = ClientOfertaAltaHelper::techo_de_hoy($contexto->owner_id, $client->id, $article->id);

        if (!is_array($techo)) {

            return RespuestaDeCargaIa::error($techo);
        }

        $tipo_descuento = count($tramos) ? 'cantidad' : 'unidad';
        $porcentaje = null;

        if ($tipo_descuento === 'cantidad') {

            $tramos = self::normalizar_tramos($tramos);

            $error = ClientOfertaAltaHelper::validar_tramos($tramos, $techo['techo']);

            if (!is_null($error)) {

                return RespuestaDeCargaIa::error($error, ['porcentaje_maximo' => (int) $techo['techo']]);
            }

        } else {

            $porcentaje = (int) $porcentaje_pedido;

            if ($porcentaje < 1 || $porcentaje > 100) {

                return RespuestaDeCargaIa::error('El porcentaje de descuento tiene que ser un número entero entre 1 y 100.');
            }

            if ($porcentaje > $techo['techo']) {

                return RespuestaDeCargaIa::error(
                    'Con el costo y el precio de hoy, el descuento máximo de este artículo es '.$techo['techo'].'%.',
                    ['porcentaje_maximo' => (int) $techo['techo']]
                );
            }
        }

        $renglones = [
            ['etiqueta' => 'Cliente', 'valor' => (string) $client->name],
            ['etiqueta' => 'Artículo', 'valor' => (string) $article->name],
        ];

        if ($tipo_descuento === 'cantidad') {

            foreach ($tramos as $tramo) {

                $renglones[] = ['etiqueta' => 'Descuento', 'valor' => self::texto_de_tramo($tramo)];
            }

        } else {

            $renglones[] = ['etiqueta' => 'Descuento', 'valor' => $porcentaje.'%'];
        }

        $renglones[] = ['etiqueta' => 'Hasta', 'valor' => FormatoIaHelper::fecha_con_dia($hasta)];

        $datos = [
            'client_id'      => (int) $client->id,
            'article_id'     => (int) $article->id,
            'tipo_descuento' => $tipo_descuento,
            'porcentaje'     => $porcentaje,
            'hasta'          => $hasta->format('Y-m-d'),
            'tramos'         => $tramos,
        ];

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_OFERTA,
            self::clave($client->id, $article->id),
            $datos,
            ['titulo' => 'Oferta', 'renglones' => $renglones, 'aviso' => self::aviso($contexto, $client->id, $article->id)],
            EntradaDeCargaIa::valor($input, 'reemplaza_a')
        );

        $descuento = $tipo_descuento === 'cantidad'
            ? count($tramos).' '.(count($tramos) === 1 ? 'tramo' : 'tramos').' por cantidad'
            : $porcentaje.'%';

        $resumen = 'Oferta para '.$client->name.' en '.$article->name.' · '.$descuento.' · hasta el '.FormatoIaHelper::fecha_con_dia($hasta);

        return AccionesIaHelper::respuesta_de_propuesta($creada, $resumen, ['techo_de_descuento' => (int) $techo['techo']]);
    }

    /**
     * Identidad de una oferta para el reemplazo: el par (cliente, artículo), que es exactamente el
     * par sobre el que corre el invariante "una sola activa". Una corrección del porcentaje o de la
     * fecha reemplaza la tarjeta; una oferta de otro artículo o para otro cliente no la pisa.
     *
     * @param  int  $client_id
     * @param  int  $article_id
     * @return string
     */
    static function clave($client_id, $article_id) {

        return 'oferta:'.(int) $client_id.':'.(int) $article_id;
    }

    /**
     * Activa la oferta de la tarjeta. Corre adentro de la transacción de EjecutorAccionesIaHelper,
     * autenticado como la persona que confirma.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array  resultado {texto, ruta}
     *
     * @throws AccionIaException  422 si la oferta ya no se puede activar.
     */
    static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion) {

        $corte = self::verificar_acceso($contexto);

        if (!is_null($corte)) {

            throw new AccionIaException(422, $corte);
        }

        $datos = is_array($accion->datos) ? $accion->datos : [];

        $client_id = isset($datos['client_id']) ? (int) $datos['client_id'] : 0;
        $article_id = isset($datos['article_id']) ? (int) $datos['article_id'] : 0;

        $client = Client::where('user_id', $contexto->owner_id)->where('id', $client_id)->first();

        if (is_null($client)) {

            throw new AccionIaException(422, 'El cliente de la tarjeta ya no existe entre los tuyos.');
        }

        $article = Article::where('user_id', $contexto->owner_id)->where('id', $article_id)->first();

        if (is_null($article)) {

            throw new AccionIaException(422, 'El artículo de la tarjeta ya no existe en tu catálogo.');
        }

        /*
         * 🔴 LA FECHA Y EL TECHO SE VUELVEN A VALIDAR CON LOS DATOS DE HOY, igual que hace la
         * pantalla al activar. Entre la tarjeta y el clic pueden pasar hasta 24 horas
         * (AiMessageAction::HORAS_VENCIMIENTO) y en el medio pudo subir el costo o bajar el precio.
         * Activar contra un techo viejo es exactamente cómo se termina vendiendo bajo costo con el
         * sistema diciendo que estaba todo bien.
         */
        $hasta = ClientOfertaAltaHelper::validar_hasta(isset($datos['hasta']) ? $datos['hasta'] : null);

        if (!($hasta instanceof Carbon)) {

            throw new AccionIaException(422, $hasta.' Pedímelo de nuevo.');
        }

        $techo = ClientOfertaAltaHelper::techo_de_hoy($contexto->owner_id, $client->id, $article->id);

        if (!is_array($techo)) {

            throw new AccionIaException(422, $techo);
        }

        $tipo_descuento = isset($datos['tipo_descuento']) && $datos['tipo_descuento'] === 'cantidad' ? 'cantidad' : 'unidad';
        $tramos = isset($datos['tramos']) && is_array($datos['tramos']) ? $datos['tramos'] : [];
        $porcentaje = isset($datos['porcentaje']) && !is_null($datos['porcentaje']) ? (int) $datos['porcentaje'] : null;

        if ($tipo_descuento === 'cantidad') {

            $error = ClientOfertaAltaHelper::validar_tramos($tramos, $techo['techo']);

            if (!is_null($error)) {

                throw new AccionIaException(422, $error);
            }

        } elseif (is_null($porcentaje) || $porcentaje > $techo['techo']) {

            throw new AccionIaException(422, 'Con el costo y el precio de hoy, el descuento máximo de este artículo es '.$techo['techo'].'%.');
        }

        /*
         * 🔴 El anteúltimo parámetro en null es la línea sugerida: esta oferta no nació de ninguna
         * corrida del motor, la pidió el dueño. Y el último en false es el aviso al cliente, que
         * desde el chat NO sale (ver el docblock de la clase).
         */
        $offer = ClientOfertaAltaHelper::activar(
            $contexto->owner_id,
            $client->id,
            $article->id,
            $tipo_descuento,
            $porcentaje,
            $hasta,
            $tramos,
            null,
            false
        );

        return [
            'texto' => 'Oferta activada para '.$client->name.' en '.$article->name.' hasta el '.$hasta->format('d/m/Y'),
            'ruta'  => [
                'name'   => 'ofertas',
                'params' => new \stdClass(),
                'texto'  => 'Ver en Promociones',
            ],
        ];
    }

    /**
     * El motivo por el que esta persona no puede armar ofertas, o null si puede. Los dos gates: la
     * extensión del comercio (la misma que pide el middleware de `POST api/client-offer`) y el
     * permiso de la persona.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @return string|null
     */
    protected static function verificar_acceso(ContextoDeCargaIa $contexto) {

        if (!PermisosIaHelper::tiene_extencion($contexto->owner, PermisosIaHelper::EXTENCION_OFERTAS)) {

            return PermisosIaHelper::mensaje_sin_extencion('Promociones');
        }

        if (!PermisosIaHelper::puede($contexto->persona, PermisosIaHelper::OFERTAS)) {

            return PermisosIaHelper::mensaje_sin_permiso('ofertas para clientes');
        }

        return null;
    }

    /**
     * El aviso de la tarjeta. Dice siempre que desde el chat no sale ningún mensaje —la pantalla sí
     * lo manda, y no avisarlo haría que el dueño creyera que el cliente ya se enteró— y, si ya hay
     * una oferta activa de ese par, que al confirmar esa queda cancelada: es lo que va a pasar, y
     * el dueño lo tiene que leer ANTES de tocar Confirmar.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  int  $client_id
     * @param  int  $article_id
     * @return string
     */
    protected static function aviso(ContextoDeCargaIa $contexto, $client_id, $article_id) {

        $aviso = 'La oferta va a verse en la tienda cuando ese cliente entre. Desde acá no se le manda ningún mail ni WhatsApp.';

        $activa = ClientOffer::where('user_id', $contexto->owner_id)
                            ->where('client_id', (int) $client_id)
                            ->where('article_id', (int) $article_id)
                            ->where('estado', 'activa')
                            ->exists();

        if ($activa) {

            $aviso = 'Ya hay una oferta activa de este artículo para este cliente: al confirmar, esa queda cancelada. '.$aviso;
        }

        return $aviso;
    }

    /**
     * Deja los tramos con la forma exacta que espera ClientOfertaAltaHelper: `min` y `porcentaje`
     * enteros y `max` entero o null ("sin techo", que es siempre el último). Un modelo de lenguaje
     * manda los números como strings o el `max` del último tramo como 0, y sin normalizar eso el
     * validador leería un tramo distinto del que la persona pidió.
     *
     * 🔴 El `max` en 0 se lee como "sin techo" y no como el número 0: un tramo que termina en 0 no
     * existe, y el único lugar donde un modelo lo pone es en el último (donde el contrato pide null).
     *
     * @param  array  $tramos
     * @return array
     */
    protected static function normalizar_tramos(array $tramos) {

        $normalizados = [];

        foreach ($tramos as $tramo) {

            if (!is_array($tramo)) {

                continue;
            }

            $max = ClientOfertaAltaHelper::max_del_tramo($tramo);

            $normalizados[] = [
                'min'        => isset($tramo['min']) ? (int) $tramo['min'] : 0,
                'max'        => is_null($max) || $max === 0 ? null : $max,
                'porcentaje' => isset($tramo['porcentaje']) ? (int) $tramo['porcentaje'] : 0,
            ];
        }

        return $normalizados;
    }

    /**
     * "1 a 5 unidades: 10%", "6 o más unidades: 15%", "1 unidad: 10%".
     *
     * @param  array  $tramo
     * @return string
     */
    protected static function texto_de_tramo(array $tramo) {

        $min = (int) $tramo['min'];
        $max = ClientOfertaAltaHelper::max_del_tramo($tramo);
        $porcentaje = (int) $tramo['porcentaje'];

        if (is_null($max)) {

            return $min.' o más unidades: '.$porcentaje.'%';
        }

        if ($max === $min) {

            return $min.($min === 1 ? ' unidad: ' : ' unidades: ').$porcentaje.'%';
        }

        return $min.' a '.$max.' unidades: '.$porcentaje.'%';
    }
}
