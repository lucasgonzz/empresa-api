<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\OfertaComunicacionHelper;
use App\Jobs\GenerateOfferSuggestionChunksJob;
use App\Jobs\GenerarResumenSugerenciaOfertaJob;
use App\Models\Client;
use App\Models\OfferSuggestion;
use App\Models\OfferSuggestionLine;
use App\Services\OfertasClientes\ResumenIaOfertasService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Los 6 endpoints de las sugerencias del motor de ofertas por cliente (contrato
 * CONGELADO en el plan de la misión, §B → A4: la SPA de A5 se escribió en
 * paralelo contra él, así que rutas, claves de respuesta y nombres de campo no
 * se tocan sin avisar antes). Molde: PurchaseSuggestionController. Sin endpoint
 * update: reprocesar una corrida es crear una nueva, no editar la existente.
 *
 * La ACTIVACIÓN de una línea no vive acá: es ClientOfferController, porque
 * escribe en otra tabla (client_offers, la que lee la tienda) y tiene su propio
 * ciclo de vida — una oferta activa sobrevive al borrado de la corrida que la
 * sugirió.
 */
class OfferSuggestionController extends Controller
{
    /**
     * Límites de los cinco parámetros del motor. El mínimo 1 en todos no es
     * decorativo: la SPA usa v-model.number, así que un campo vaciado manda ""
     * y (int)"" = 0 — con dias_historial_afinidad = 0 la ventana de compras
     * queda vacía y la corrida entera sale sin una sola línea, sin ningún
     * aviso. Los máximos son un techo defensivo por lo que representa cada
     * campo y no un límite exacto del negocio: tres años de historial ya no
     * dicen nada de la afinidad de hoy, un cliente dormido hace tres años es
     * una baja y no una reactivación, un carrito abandonado hace 90 días dejó
     * de ser una señal caliente, más de 20 ofertas a un mismo cliente no es una
     * campaña sino spam, y una promoción de más de medio año dejó de ser oferta
     * para pasar a ser el precio.
     */
    const MIN_DIAS = 1;
    const MAX_DIAS_HISTORIAL_AFINIDAD = 1095;
    const MAX_DIAS_INACTIVIDAD = 1095;
    const MAX_DIAS_CARRITO = 90;
    const MAX_OFERTAS_POR_CLIENTE = 20;
    const MAX_DIAS_VIGENCIA = 180;

    /** Padrón de clientes hasta el cual la corrida se procesa en el mismo request. */
    const LIMITE_SINCRONICO = 500;

    /** Paginador de líneas, contrato congelado: default 50, techo duro 500. */
    const PER_PAGE_MAX = 500;
    const PER_PAGE_DEFAULT = 50;

    public function index() {
        $models = OfferSuggestion::where('user_id', $this->userId())
            ->orderBy('created_at', 'DESC')
            ->withAll()
            ->get();

        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        // 🔴 Se castea ANTES de validar, y no se valida el $request crudo: un
        // campo vacío llega como "" y las reglas min/max de Laravel NO corren
        // sobre strings vacíos salvo que la regla sea "required", así que se
        // saltearían en silencio justo el caso que hay que frenar. Casteando
        // primero, "" y lo no numérico caen a 0 y los atrapa el mínimo.
        $parametros = [
            'dias_historial_afinidad'       => (int) $request->input('dias_historial_afinidad', 180),
            'dias_inactividad_reactivacion' => (int) $request->input('dias_inactividad_reactivacion', 60),
            'dias_carrito_abandonado'       => (int) $request->input('dias_carrito_abandonado', 7),
            'max_ofertas_por_cliente'       => (int) $request->input('max_ofertas_por_cliente', 3),
            'dias_vigencia_sugerida'        => (int) $request->input('dias_vigencia_sugerida', 15),
        ];

        $error = $this->validar_parametros_del_motor($parametros);

        if ($error !== null) {
            return response()->json(['message' => $error], 422);
        }

        $model = OfferSuggestion::create(array_merge([
            'user_id'           => $this->userId(),
            'status'            => 'pendiente',
            'origen_generacion' => 'manual',
        ], $parametros));

        $this->despachar_procesamiento($model);

        return response()->json(['model' => $this->fullModel('OfferSuggestion', $model->id)], 201);
    }

    /**
     * Valida los cinco parámetros del motor ya casteados a int (ver store()).
     * Devuelve el PRIMER mensaje de error en español, o null si están en rango.
     *
     * @param array $parametros
     * @return string|null
     */
    protected function validar_parametros_del_motor(array $parametros) {
        $topes = [
            'dias_historial_afinidad'       => [self::MAX_DIAS_HISTORIAL_AFINIDAD, 'los días de historial de compras'],
            'dias_inactividad_reactivacion' => [self::MAX_DIAS_INACTIVIDAD, 'los días de inactividad'],
            'dias_carrito_abandonado'       => [self::MAX_DIAS_CARRITO, 'los días de carrito abandonado'],
            'max_ofertas_por_cliente'       => [self::MAX_OFERTAS_POR_CLIENTE, 'el tope de ofertas por cliente'],
            'dias_vigencia_sugerida'        => [self::MAX_DIAS_VIGENCIA, 'los días de vigencia sugerida'],
        ];

        $reglas = [];
        $mensajes = [];

        foreach ($topes as $campo => $tope) {
            // 🔴 'integer' no es decorativo acá: sin una regla numérica en el
            // set, Laravel compara min/max por mb_strlen() del valor (regla
            // pensada para strings) y no por su valor numérico -- "0" mide 1
            // carácter y pasaría un min:1 igual. Con 'integer' presente,
            // Validator::getSize() sí compara el número.
            $reglas[$campo] = 'integer|min:' . self::MIN_DIAS . '|max:' . $tope[0];
            $mensajes[$campo . '.min'] = ucfirst($tope[1]) . ' tiene que ser un número entero de al menos ' . self::MIN_DIAS . ': en 0 la corrida sale vacía y nadie se entera de por qué.';
            $mensajes[$campo . '.max'] = ucfirst($tope[1]) . ' no puede superar ' . $tope[0] . '.';
        }

        $validator = Validator::make($parametros, $reglas, $mensajes);

        if ($validator->fails()) {
            return $validator->errors()->first();
        }

        return null;
    }

    /**
     * Camino único de despacho del cálculo (molde
     * PurchaseSuggestionController::despachar_procesamiento():149-176). Padrones
     * chicos: procesamiento inmediato, porque en local (WAMP) casi nunca hay un
     * queue:work corriendo y sin este camino una corrida creada a mano quedaba
     * 'pendiente' para siempre y parecía rota. Padrones grandes: cola, para no
     * comerse el timeout HTTP. 🔴 Se cuentan CLIENTES y no artículos porque el
     * chunk de esta misión es por cliente (§B → A3): lo que define el costo de
     * la corrida es a cuánta gente hay que evaluarle el historial. El conteo se
     * acota al dueño: en una base con varios comercios, contar el padrón ajeno
     * inflaba la decisión.
     *
     * @param OfferSuggestion $model
     * @return void
     */
    protected function despachar_procesamiento($model) {
        $client_count = Client::where('user_id', $model->user_id)->count();

        if ($client_count > self::LIMITE_SINCRONICO) {
            dispatch(new GenerateOfferSuggestionChunksJob($model->id));
            return;
        }

        try {
            (new GenerateOfferSuggestionChunksJob($model->id))->handle();
        } catch (\Throwable $e) {
            // failed() solo corre en la vía de cola: sin esta red, una excepción
            // del camino sincrónico dejaba la corrida 'pendiente' eterna y la
            // vista polleando por un resultado que no iba a llegar. Mismo
            // formato que failed(), y sin pisar una ya 'terminado'.
            $model->refresh();

            if ($model->status !== 'terminado') {
                $model->status = 'error';
                $model->error_mensaje = $e->getMessage();
                $model->save();
            }

            throw $e;
        }
    }

    public function show($id) {
        $model = $this->sugerencia_del_usuario($id);

        if (!$model) {
            return response()->json(['message' => 'Sugerencia no encontrada.'], 404);
        }

        return response()->json(['model' => $this->fullModel('OfferSuggestion', $model->id)], 200);
    }

    public function destroy($id) {
        $model = $this->sugerencia_del_usuario($id);

        if (!$model) {
            return response()->json(['message' => 'Sugerencia no encontrada.'], 404);
        }

        // Una corrida en curso no se borra: sacarle el modelo de abajo a los
        // chunks que la están escribiendo deja líneas huérfanas y hace que el
        // refresh() del polling (firstOrFail) tire ModelNotFoundException.
        if ($model->status === 'pendiente') {
            return response()->json(['message' => 'La corrida se está generando. Esperá a que termine para borrarla.'], 422);
        }

        // Las líneas van en la misma transacción que la cabecera: sin esto
        // quedaban huérfanas en offer_suggestion_lines. 🔴 Las client_offers que
        // salieron de esas líneas NO se tocan: una promoción vigente es un
        // compromiso con el cliente y con la tienda, y borrar el borrador que la
        // propuso no puede darla de baja. Su offer_suggestion_line_id queda
        // apuntando a una línea que ya no está (no hay foreign keys físicas
        // acá), y eso es correcto: es trazabilidad, no una dependencia. Para dar
        // de baja una oferta está DELETE api/client-offer/{id}.
        DB::transaction(function () use ($model) {
            OfferSuggestionLine::where('offer_suggestion_id', $model->id)->delete();
            $model->delete();
        });

        $this->sendDeleteModelNotification('OfferSuggestion', $model->id);

        // 204 explícito: el contrato congelado lo pide así (a diferencia de
        // response(null) sin status, que responde 200).
        return response(null, 204);
    }

    /**
     * Líneas de una corrida, paginadas server-side. Contrato congelado: per_page
     * default 50 y techo 500; filtros client_id, criterio y solo_sin_activar;
     * orden prioridad (default) | descuento | score; respuesta
     * ['models' => paginador] con las claves aplanadas de abajo.
     *
     * @param Request $request page, per_page, client_id, criterio, solo_sin_activar, order
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function lines(Request $request, $id) {
        $suggestion = $this->sugerencia_del_usuario($id);

        if (!$suggestion) {
            return response()->json(['message' => 'Sugerencia no encontrada.'], 404);
        }

        // Un per_page vacío o negativo cae al default, no a 0 (que en Laravel
        // pagina TODO y se come la memoria en una corrida de miles de líneas).
        $per_page = (int) $request->input('per_page', self::PER_PAGE_DEFAULT);
        $per_page = $per_page <= 0 ? self::PER_PAGE_DEFAULT : $per_page;
        $per_page = $per_page > self::PER_PAGE_MAX ? self::PER_PAGE_MAX : $per_page;

        $query = OfferSuggestionLine::withAll()
            ->where('offer_suggestion_id', $suggestion->id);

        if ($request->filled('client_id')) {
            $query->where('client_id', (int) $request->client_id);
        }

        if ($request->filled('criterio')) {
            $query->where('criterio', $request->criterio);
        }

        if ($request->filled('solo_sin_activar')) {
            // Lo que el comerciante todavía no activó: es la vista de trabajo
            // real, porque una corrida ya trabajada queda casi entera activada.
            $query->whereNull('client_offer_id');
        }

        $order = $request->input('order');

        if ($order === 'descuento') {
            $query->orderByRaw('porcentaje_sugerido IS NULL ASC')->orderBy('porcentaje_sugerido', 'DESC');
        } elseif ($order === 'score') {
            $query->orderByRaw('score IS NULL ASC')->orderBy('score', 'DESC');
        } else {
            // Orden default por prioridad. NULLs al final: la prioridad se
            // materializa recién al cerrar la corrida, así que una corrida a
            // medio procesar tiene líneas sin ranking y no pueden quedar arriba.
            $query->orderByRaw('prioridad IS NULL ASC')->orderBy('prioridad', 'ASC');
        }

        $query->orderBy('id', 'ASC');

        $models = $query->paginate($per_page);

        $models->getCollection()->transform(function ($item) {
            $article = $item->article;
            $client = $item->client;

            return [
                'offer_suggestion_line_id'   => $item->id,
                'client_id'                  => $item->client_id,
                // El nombre del comprador de la tienda, cayendo al del cliente del ERP
                // (OfertaComunicacionHelper::nombre_para_mostrar). La CLAVE client_nombre no
                // cambia: es contrato congelado con la SPA (docblock de la clase, :16-25).
                'client_nombre'              => OfertaComunicacionHelper::nombre_para_mostrar($client),
                'article_id'                 => $item->article_id,
                'name'                       => $article && !empty($article->name) ? $article->name : '',
                'provider_code'              => $article && !empty($article->provider_code) ? $article->provider_code : '',
                'bar_code'                   => $article && !empty($article->bar_code) ? $article->bar_code : '',
                'tipo_descuento'             => $item->tipo_descuento,
                // 🔴 Numéricos SIN fallback a 0: null es un dato real acá.
                // es_buen_pagador null = "sin datos de cuenta corriente", que no
                // es lo mismo que false = "mal pagador"; pisarlo con 0 le haría
                // decir a la vista que paga mal cuando en realidad no se sabe.
                'porcentaje_sugerido'        => $item->porcentaje_sugerido,
                'porcentaje_techo'           => $item->porcentaje_techo,
                'criterio'                   => $item->criterio,
                'criterio_detalle'           => $item->criterio_detalle,
                'motivo_ia'                  => $item->motivo_ia,
                'fecha_vencimiento_sugerida' => $item->fecha_vencimiento_sugerida,
                'tramos_sugeridos'           => $this->tramos_decodificados($item->tramos_sugeridos),
                'es_buen_pagador'            => $item->es_buen_pagador,
                'prioridad'                  => $item->prioridad,
                'client_offer_id'            => $item->client_offer_id,
            ];
        });

        return response()->json(['models' => $models], 200);
    }

    /**
     * `tramos_sugeridos` se guarda como longText con un JSON adentro y sale de
     * acá YA DECODIFICADO (array de {min,max,porcentaje}, o null): devolver el
     * string crudo obligaría a la SPA a un JSON.parse sobre una respuesta que ya
     * es JSON, y de ese doble parseo salen los "undefined" silenciosos cuando la
     * columna viene vacía. Un JSON roto (imposible por el camino normal, pero la
     * columna es texto libre) devuelve null y no tira la página entera.
     *
     * @param string|null $raw
     * @return array|null
     */
    protected function tramos_decodificados($raw) {
        $tramos = empty($raw) ? null : json_decode($raw, true);

        return is_array($tramos) ? $tramos : null;
    }

    /**
     * Reintento del resumen IA desde la vista: resetea el estado y vuelve a
     * despachar el job, sin tocar el cálculo ya terminado. Es la contracara de
     * que el job no reintente solo ($tries = 1): un 529 se resuelve con el botón.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function resumen($id) {
        $model = $this->sugerencia_del_usuario($id);

        if (!$model) {
            return response()->json(['message' => 'Sugerencia no encontrada.'], 404);
        }

        // El resumen habla del resultado completo: sobre una corrida en curso o
        // fallida no hay nada serio que redactar.
        if ($model->status !== 'terminado') {
            return response()->json(['message' => 'La corrida no está terminada: el resumen se escribe sobre el resultado completo.'], 422);
        }

        if (!(new ResumenIaOfertasService())->hay_credenciales()) {
            return response()->json(['message' => 'La cuenta no tiene la IA configurada: no hay credenciales para pedir el resumen.'], 422);
        }

        $model->resumen_ia       = null;
        $model->resumen_ia_error = null;
        // 'pendiente' desde ya: la vista muestra "lo estamos escribiendo" sin
        // esperar a que el worker levante el job.
        $model->resumen_ia_estado = 'pendiente';
        $model->save();

        // Sin la posta de la notificación: la corrida ya fue anunciada cuando
        // terminó, esto es solo un reintento del resumen.
        dispatch(new GenerarResumenSugerenciaOfertaJob($model->id));

        return response()->json(['model' => $model], 200);
    }

    /**
     * 🔴 EL ÚNICO CAMINO POR ID: null si no existe o es de otro comercio, y por
     * eso el 404 de "no es tuya" es indistinguible del de "no existe". Filtrar
     * por dueño método por método es como se filtra un método de menos.
     *
     * @param int $id
     * @return OfferSuggestion|null
     */
    protected function sugerencia_del_usuario($id) {
        return OfferSuggestion::where('user_id', $this->userId())
            ->where('id', $id)
            ->first();
    }
}
