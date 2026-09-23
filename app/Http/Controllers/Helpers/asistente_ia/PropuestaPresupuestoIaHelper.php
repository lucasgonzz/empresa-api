<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\BudgetController;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * El presupuesto que propone el asistente (misión asistente-capacidades-y-hilos, 22/9/2026): el
 * mensaje #56 del 22/9 en demo3, *"no puedo armar presupuestos desde acá: no está entre las cargas
 * que puedo proponerte"*.
 *
 * 🔴 SE CREA POR `BudgetController::store()` CON EL MISMO PAYLOAD QUE ARMA
 * `mixins/vender_presupuestos.js::crear()`, que es el toggle "guardar como presupuesto" de VENDER.
 * Nada de `Budget::create` a mano: el alta tiene correlativo, lista de precios obligatoria,
 * renglones con su costo congelado, descuentos y la validación del total, y una segunda forma de
 * crearlo es una segunda verdad que envejece por su lado.
 *
 * 🔴 UN PRESUPUESTO ES EL HERMANO DE UNA VENTA HASTA EL MOMENTO DE COBRAR, así que los renglones,
 * el cliente, la lista de precios, el descuento y la sucursal se resuelven con los MISMOS métodos
 * de `PropuestaVentaIaHelper` (que por eso son públicos). Lo que no tiene es cobro: no hay método
 * de pago, no hay caja y no hay cuenta corriente.
 *
 * 🔴 Y NO TOCA NADA: `BudgetHelper::checkStatus()` solo crea la venta si el estado es "Confirmado",
 * y el alta nace con `budget_status_id = 1` (sin confirmar), igual que la pantalla. Stock, caja y
 * cuenta corriente se mueven recién en `POST api/budget/{id}/confirmar`, que el asistente NO llama.
 * Por eso la tarjeta entra en AUTO_CONFIRMABLES_DIRECTO: un presupuesto de más se borra.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * 🔴 LA TRAMPA DEL TOTAL, QUE ES UN 500 EN PRODUCCIÓN SI SE IGNORA
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * `BudgetController::store()` compara `(int) BudgetHelper::getTotal($model)` contra
 * `(int) $model->total` y, si difieren en más de 3, lanza una Exception: rollback y **500**. O sea
 * que el `total` del payload no es informativo, es una ASERCIÓN sobre los renglones. `getTotal()`
 * suma `pivot.price * pivot.amount` (menos el `bonus` de la línea), le aplica los descuentos del
 * presupuesto y le suma los recargos, así que el total que mandamos tiene que salir de la misma
 * cuenta — que es exactamente la que hace `PropuestaVentaIaHelper::totales()` para la venta
 * (`vender_set_total.js`). Se calcula con ella y no con una cuenta propia.
 *
 * ⚠️ Y por eso tampoco se mandan servicios, promociones de vinoteca ni combos: son cuatro buckets
 * que `getTotal()` suma y que el asistente no arma. Un bucket que entrara en el `total` y no en la
 * cuenta (o al revés) es el mismo 500.
 *
 * PHP 7.4: sin enum, sin match, sin operador nullsafe.
 */
class PropuestaPresupuestoIaHelper
{
    /**
     * Permiso para presupuestar: la ruta `/presupuestos` de la SPA pide `can: 'budget.index'`, pero
     * lo que este helper hace es el ALTA, y el botón "Nuevo" de una vista se dibuja con
     * `can(model_name + '.store')` (common-vue/components/view/header/Index.vue:262). Mismo origen
     * que `expense.store` de los gastos y `sale.store` de las ventas.
     */
    const PERMISO = 'budget.store';

    /**
     * La extensión que enciende el módulo: la ruta de la SPA pide
     * `if_has_extencion: ['budgets', 'comerciocity_interno']` y el toggle de VENDER se dibuja con
     * `hasExtencion('budgets') && client`.
     *
     * 🔴 EL BACKEND NO LA CHEQUEA EN NINGÚN LADO: `BudgetController::store()` no llama a
     * `hasExtencion('budgets')` (el único gate de extensión del controller es el de `duplicate()`,
     * y es por otro slug). El gate vive solo en la SPA, así que si el asistente no lo espeja, una
     * cuenta sin el módulo tendría presupuestos que no puede ver en ninguna pantalla.
     */
    const EXTENSION = 'budgets';

    /**
     * Estado "sin confirmar" (`budget_statuses`). Es el que manda la pantalla
     * (`vender_presupuestos.js:249`: *"Id 1 es el estado sin confirmar"*) y es lo que hace que
     * `BudgetHelper::checkStatus()` NO cree la venta.
     */
    const ESTADO_SIN_CONFIRMAR = 1;

    /** Ruta de la SPA donde ver el presupuesto (`src/router/routes.js`: `name: 'budget'`). */
    const RUTA_PRESUPUESTOS = 'budget';

    /**
     * Herramienta proponer_presupuesto.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input  items*, cliente*, lista_de_precios, descuento_porcentaje,
     *                        observaciones, sucursal, reemplaza_a
     * @return array
     */
    public static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input): array
    {
        if (!PermisosIaHelper::puede($contexto->persona, self::PERMISO)) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_permiso('presupuestos'));
        }

        if (!PermisosIaHelper::tiene_extencion($contexto->owner, self::EXTENSION)) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_extencion('Presupuestos'));
        }

        if (is_null($contexto->owner)) {

            return RespuestaDeCargaIa::error('No pude resolver el dueño de la cuenta. Probá de nuevo en unos segundos.');
        }

        /** Lo que falta para armar el presupuesto, para preguntarlo todo en un solo mensaje. */
        $faltan = [];

        /** Las opciones que acompañan a lo que falta. */
        $opciones = [];

        /*
         * 🔴 EL CLIENTE ES OBLIGATORIO, y no es una decisión nuestra: el toggle "guardar como
         * presupuesto" de VENDER solo se dibuja con un cliente elegido, `crear()` manda
         * `this.client.id` sin guarda, y `store()` resuelve la lista de precios desde ese cliente —
         * sin él, una cuenta con lista obligatoria se come un 422. Un presupuesto es para alguien.
         */
        $cliente = PropuestaVentaIaHelper::resolver_cliente($contexto, EntradaDeCargaIa::valor($input, 'cliente'));

        if (RespuestaDeCargaIa::es_negativa($cliente)) {

            return $cliente;
        }

        if (is_null($cliente)) {

            return RespuestaDeCargaIa::faltan(['para qué cliente es el presupuesto']);
        }

        $lista = PropuestaVentaIaHelper::resolver_lista_de_precios($contexto, $cliente, EntradaDeCargaIa::texto($input, 'lista_de_precios'));

        if (RespuestaDeCargaIa::es_negativa($lista)) {

            return $lista;
        }

        /* Sin cobro no hay descuento de método de pago: el tercer argumento va en null a propósito. */
        $renglones = PropuestaVentaIaHelper::resolver_renglones($contexto, EntradaDeCargaIa::valor($input, 'items'), $lista, null, $faltan, $opciones);

        if (RespuestaDeCargaIa::es_negativa($renglones)) {

            return $renglones;
        }

        $descuento = PropuestaVentaIaHelper::resolver_descuento($contexto, $cliente, EntradaDeCargaIa::valor($input, 'descuento_porcentaje'));

        if (RespuestaDeCargaIa::es_negativa($descuento)) {

            return $descuento;
        }

        $sucursal = PropuestaVentaIaHelper::resolver_sucursal($contexto, EntradaDeCargaIa::texto($input, 'sucursal'), $faltan, $opciones);

        if (RespuestaDeCargaIa::es_negativa($sucursal)) {

            return $sucursal;
        }

        $observaciones = EntradaDeCargaIa::texto($input, 'observaciones');

        if (count($faltan)) {

            return RespuestaDeCargaIa::faltan($faltan, $opciones);
        }

        $totales = PropuestaVentaIaHelper::totales($renglones, $descuento);

        $payload = self::payload($renglones, $cliente, $lista, $descuento, $sucursal, $observaciones, $totales);

        $presentacion = self::presentacion($renglones, $cliente, $lista, $descuento, $sucursal, $observaciones, $totales);

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_PRESUPUESTO,
            self::clave($renglones, $cliente),
            ['payload' => $payload],
            $presentacion,
            EntradaDeCargaIa::valor($input, 'reemplaza_a')
        );

        $resumen = 'Presupuesto para ' . $cliente->name . ' · ' . count($renglones)
            . (count($renglones) === 1 ? ' artículo' : ' artículos')
            . ' · ' . FormatoIaHelper::monto($totales['total']);

        return AccionesIaHelper::respuesta_de_propuesta($creada, $resumen);
    }

    /**
     * Identidad del presupuesto para el reemplazo: la misma combinación de artículos para el mismo
     * cliente. Una corrección ("no, eran 5 palas") pisa la tarjeta; otro presupuesto con otros
     * artículos, no.
     *
     * @param  array  $renglones
     * @param  \App\Models\Client  $cliente
     * @return string
     */
    public static function clave(array $renglones, $cliente): string
    {
        $ids = [];

        foreach ($renglones as $renglon) {
            $ids[] = (int) $renglon['article']->id;
        }

        sort($ids);

        return 'presupuesto:' . substr(md5(implode(',', $ids) . '|' . (int) $cliente->id), 0, 16);
    }

    /**
     * Crea el presupuesto por `BudgetController::store()` con el payload guardado al proponer.
     *
     * 🔴 CORRE CON LA TRANSACCIÓN DEL EJECUTOR YA CERRADA (`TIPOS_DE_DOS_ETAPAS`), por el mismo
     * motivo que la venta y el ABM genérico: `store()` abre su PROPIA transacción con
     * `DB::beginTransaction()` y la cierra con `commit()`, que anidado adentro de otra solo
     * decrementa el contador; y emite la notificación de alta. Con la de afuera cerrada, el
     * controller corre en las mismas condiciones en las que corre cuando lo llama la pantalla.
     *
     * 🔴 EL 500 DEL TOTAL VUELVE COMO TEXTO DE LA TARJETA, no como una falla técnica muda: si la
     * validación de margen 3 rechaza el alta, la persona tiene que enterarse de que el presupuesto
     * NO se creó, y el modelo tiene que decir ese motivo y no inventar otro.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array  resultado {texto, ruta, presupuesto_id, numero, total}
     *
     * @throws AccionIaException
     */
    public static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion): array
    {
        if (!PermisosIaHelper::puede($contexto->persona, self::PERMISO)) {

            throw new AccionIaException(422, PermisosIaHelper::mensaje_sin_permiso('presupuestos'));
        }

        if (!PermisosIaHelper::tiene_extencion($contexto->owner, self::EXTENSION)) {

            throw new AccionIaException(422, PermisosIaHelper::mensaje_sin_extencion('Presupuestos'));
        }

        $persona = $contexto->persona;

        /*
         * `store()` resuelve el dueño, el empleado y el correlativo con `UserHelper::userId()`, que
         * sin sesión devuelve el USER_ID de config, o sea OTRO comercio. Misma guarda que la venta.
         */
        if (is_null($persona) || is_null(Auth::id()) || (int) Auth::id() !== (int) $persona->id) {

            throw new AccionIaException(500, 'El presupuesto no se puede crear sin la persona autenticada.');
        }

        $datos = is_array($accion->datos) ? $accion->datos : [];

        $payload = isset($datos['payload']) && is_array($datos['payload']) ? $datos['payload'] : [];

        if (!isset($payload['articles']) || !is_array($payload['articles']) || !count($payload['articles'])) {

            throw new AccionIaException(422, 'La tarjeta no tiene artículos. Pedímela de nuevo.');
        }

        self::verificar_para_ejecutar($contexto, $payload);

        $request = Request::create('/api/budget', 'POST', $payload);
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(function () use ($persona) {
            return $persona;
        });

        $respuesta = app(BudgetController::class)->store($request);

        $status = (int) $respuesta->getStatusCode();
        $body = json_decode((string) $respuesta->getContent(), true);

        if ($status === 201 && is_array($body) && isset($body['model']) && is_array($body['model'])) {

            $presupuesto = $body['model'];

            $numero = isset($presupuesto['num']) ? (int) $presupuesto['num'] : 0;

            return [
                'texto'          => 'Presupuesto N° ' . $numero . ' creado',
                'ruta'           => [
                    'name'   => self::RUTA_PRESUPUESTOS,
                    'params' => new \stdClass(),
                    'texto'  => 'Ver en Presupuestos',
                ],
                'presupuesto_id' => isset($presupuesto['id']) ? (int) $presupuesto['id'] : 0,
                'numero'         => $numero,
                'total'          => isset($presupuesto['total']) ? (float) $presupuesto['total'] : (float) $payload['total'],
            ];
        }

        $mensaje = is_array($body) && isset($body['message']) && trim((string) $body['message']) !== ''
            ? trim((string) $body['message'])
            : 'No se pudo crear el presupuesto.';

        if ($status >= 500) {
            $mensaje = 'No se pudo crear el presupuesto: ' . $mensaje;
        }

        throw new AccionIaException(422, mb_substr($mensaje, 0, 1000));
    }

    /**
     * Lo que pudo cambiar entre la propuesta y el clic: los artículos siguen siendo del dueño y el
     * cliente sigue existiendo. Mismo criterio que PropuestaVentaIaHelper::verificar_para_ejecutar,
     * sin la parte de la caja (un presupuesto no cobra).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $payload
     * @return void
     *
     * @throws AccionIaException
     */
    protected static function verificar_para_ejecutar(ContextoDeCargaIa $contexto, array $payload)
    {
        $ids = [];

        foreach ($payload['articles'] as $articulo) {
            if (is_array($articulo) && isset($articulo['id'])) {
                $ids[] = (int) $articulo['id'];
            }
        }

        $existen = Article::where('user_id', $contexto->owner_id)
            ->whereIn('id', $ids)
            ->count('id');

        if ($existen !== count(array_unique($ids))) {

            throw new AccionIaException(422, 'Alguno de los artículos de la tarjeta ya no existe entre los tuyos. Pedímela de nuevo.');
        }

        $client_id = isset($payload['client_id']) ? (int) $payload['client_id'] : 0;

        if (!Client::where('user_id', $contexto->owner_id)->where('id', $client_id)->exists()) {

            throw new AccionIaException(422, 'El cliente de la tarjeta ya no existe entre los tuyos. Pedímela de nuevo.');
        }
    }

    /**
     * El payload de `POST api/budget`, clave por clave como lo manda
     * `mixins/vender_presupuestos.js::crear()` (empresa-spa, develop del 22/9/2026).
     *
     * Lo que NO viaja y por qué:
     *   - `services`, `promocion_vinotecas` y `combos` van vacíos: son buckets que `getTotal()`
     *     suma y que el asistente no arma (ver el 🔴 del docblock de la clase).
     *   - `forzar_total_monto` y `valor_dolar` van en null: el asistente no fuerza totales ni
     *     presupuesta en dólares.
     *   - `omitir_en_cuenta_corriente` va en 0 SIEMPRE, y además `store()` lo fija en 0 pase lo que
     *     pase (decisión de Lucas del 18/9/2026: un presupuesto confirmado va siempre a la cuenta).
     *   - `aplicar_recargos_directo_a_items` en 0: sin recargos no hay nada que aplicar, y con el
     *     flag prendido `getTotal()` dejaría de sumarlos.
     *
     * Los artículos van PLANOS (sin `pivot`), que es la forma del alta: con `pivot` es la forma del
     * update, y `attachArticles()` lee una u otra según la clave (`:643`).
     *
     * @param  array  $renglones
     * @param  \App\Models\Client  $cliente
     * @param  \App\Models\PriceType|null  $lista
     * @param  \App\Models\Discount|null  $descuento
     * @param  \App\Models\Address|null  $sucursal
     * @param  string  $observaciones
     * @param  array  $totales
     * @return array<string, mixed>
     */
    public static function payload(array $renglones, $cliente, $lista, $descuento, $sucursal, $observaciones, array $totales): array
    {
        $articles = [];

        foreach ($renglones as $renglon) {

            $article = $renglon['article'];

            $articles[] = [
                'id'                          => (int) $article->id,
                'status'                      => (string) $article->status,
                'cost_in_dollars'             => $article->cost_in_dollars,
                'name'                        => (string) $article->name,
                'name_vender_personalizado'   => null,
                'amount'                      => (float) $renglon['cantidad'],
                'price'                       => (float) $renglon['precio'],
                'cost'                        => $article->cost,
                'costo_real'                  => $article->costo_real,
                'unidades_individuales'       => $article->unidades_individuales,
                'presentacion'                => $article->presentacion,
                'price_type_personalizado_id' => null,
                // El descuento POR LÍNEA, que la pantalla llama `discount`. El asistente no lo usa:
                // su descuento es el de venta, que va en `discounts` y `getTotal()` aplica aparte.
                'bonus'                       => null,
                'location'                    => null,
            ];
        }

        return [
            'client_id'                        => (int) $cliente->id,
            'price_type_id'                    => is_null($lista) ? null : (int) $lista->id,
            'start_at'                         => null,
            'finish_at'                        => null,
            'observations'                     => $observaciones,
            'total'                            => $totales['total'],
            'address_id'                       => is_null($sucursal) ? null : (int) $sucursal->id,
            'moneda_id'                        => 1,
            'surchages_in_services'            => 0,
            'discounts_in_services'            => 0,
            'aplicar_recargos_directo_a_items' => 0,
            'forzar_total_monto'               => null,
            'valor_dolar'                      => null,
            'omitir_en_cuenta_corriente'       => 0,
            'budget_status_id'                 => self::ESTADO_SIN_CONFIRMAR,
            'discounts'                        => is_null($descuento)
                ? []
                : [['id' => (int) $descuento->id, 'name' => (string) $descuento->name, 'percentage' => (float) $descuento->percentage]],
            'surchages'                        => [],
            'articles'                         => $articles,
            'services'                         => [],
            'promocion_vinotecas'              => [],
            'combos'                           => [],
            'discount_stock'                   => 1,
            'sale_status_id'                   => 0,
            'iva_aplicado'                     => 1,
        ];
    }

    /**
     * La tarjeta: un renglón por artículo, el cliente, el descuento, la lista, la sucursal, las
     * observaciones y el total. El aviso dice lo que el presupuesto NO hace, que es justo lo que
     * una persona puede dar por sentado que sí.
     *
     * @return array{titulo: string, renglones: array, aviso: string|null}
     */
    protected static function presentacion(array $renglones, $cliente, $lista, $descuento, $sucursal, $observaciones, array $totales): array
    {
        $filas = [];

        foreach ($renglones as $renglon) {

            $filas[] = ['etiqueta' => 'Artículo', 'valor' => PropuestaVentaIaHelper::renglon_de_articulo($renglon)];
        }

        $filas[] = ['etiqueta' => 'Cliente', 'valor' => (string) $cliente->name];

        if (!is_null($descuento)) {

            $filas[] = [
                'etiqueta' => 'Descuento',
                'valor'    => $descuento->name . ' ' . PropuestaVentaIaHelper::porcentaje((float) $descuento->percentage)
                    . ' % (−' . FormatoIaHelper::monto($totales['descuento_monto']) . ')',
            ];
        }

        if (!is_null($lista)) {

            $filas[] = ['etiqueta' => 'Lista de precios', 'valor' => (string) $lista->name];
        }

        if (!is_null($sucursal)) {

            $filas[] = ['etiqueta' => 'Sucursal', 'valor' => (string) $sucursal->street];
        }

        if ($observaciones !== '') {

            $filas[] = ['etiqueta' => 'Observaciones', 'valor' => $observaciones];
        }

        $filas[] = ['etiqueta' => 'Total', 'valor' => FormatoIaHelper::monto($totales['total'])];

        return [
            'titulo'    => 'Presupuesto',
            'renglones' => $filas,
            'aviso'     => 'Un presupuesto no descuenta stock, no mueve caja y no toca la cuenta corriente: '
                . 'eso pasa recién cuando lo confirmás desde la pantalla de Presupuestos.',
        ];
    }
}
