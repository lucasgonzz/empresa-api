<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\ArticleController;
use App\Http\Controllers\Helpers\ConsultasSistemaIaHelper;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Address;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * El stock por depósito que mueve el asistente (misión asistente-capacidades-y-hilos, 22/9/2026):
 * mover stock de un depósito a otro, y dejar el stock de UN depósito en un número.
 *
 * 🔴 DE DÓNDE SALE ESTA MISIÓN. El 22/9/2026, en demo3, el agente contestó dos veces que no podía:
 * "ese movimiento de stock entre sucursales no lo puedo hacer… solo llego a editar el stock total"
 * (mensaje #24) y "no puedo cargarle stock a Florida desde acá: el cambio que sí puedo proponerte es
 * sobre el total del artículo, no sobre un depósito puntual" (#42). Las dos cosas las hace la
 * pantalla del Listado, con dos endpoints que ya existen.
 *
 * 🔴 LAS DOS SE HACEN LLAMANDO AL MISMO CONTROLLER QUE USA LA PANTALLA, con el mismo payload:
 *   - mover → `POST api/stock-movement` (`Stock\StockMovementController@store`), el mismo que manda
 *     el modal "Movimiento de depósitos" (listado/modals/address-movement/Index.vue:266).
 *   - dejar el stock de un depósito → `PUT api/article-update-addresses`
 *     (`ArticleController@update_addresses_stock`), el mismo que manda la edición de stock por
 *     sucursal del Listado.
 * Nada entra por SQL ni por Eloquent directo: el stock tiene pivot, stock global, variantes, avisos
 * y sincronización a Tienda Nube y Mercado Libre colgando de `SetArticleStock`, y una segunda forma
 * de moverlo es una segunda verdad que envejece por su lado.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * 🔴 LAS DOS TRAMPAS DE ESTOS ENDPOINTS, QUE SON LO QUE JUSTIFICA QUE ESTE ARCHIVO EXISTA
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * 1. **`POST api/stock-movement` NO VALIDA NADA.** `store()` copia siete claves del request, llama a
 *    `crear()` y devuelve `response(null, 201)` — cuerpo vacío. `crear()` hace `Article::find()` y
 *    `if (!$article) return;`, así que un `model_id` que no existe devuelve **201 sin haber hecho
 *    nada**: un éxito silencioso. Y el signo del movimiento lo decide el NOMBRE DEL CONCEPTO:
 *    `CheckFromAddress::get_amount_for_from_address()` invierte el monto en el origen SOLO si el
 *    concepto se llama `Mov entre depositos` o `Mov manual entre depositos`. Con el nombre mal
 *    escrito, `SetConcepto` deja el concepto en null, `CheckFromAddress` no invierte y el stock se
 *    SUMA EN LOS DOS DEPÓSITOS en vez de moverse. Por eso acá el concepto es una constante, todo se
 *    valida antes de llamar, y el resultado se CONFIRMA LEYENDO EL PIVOT DESPUÉS.
 *
 * 2. **`pivot.amount` de `update_addresses_stock` es el stock FINAL del depósito, no un delta.**
 *    `UpdateAddressesStockHelper::update_addresses()` calcula `pivot.amount - lo que hay hoy` y
 *    manda esa diferencia como movimiento. O sea que "sumale 10 unidades" mandado como `amount: 10`
 *    PISA el stock del depósito y lo deja en 10. Acá se lee el actual y se manda la suma, y la
 *    herramienta tiene un `modo` explícito (sumar / restar / fijar) para que el modelo no tenga que
 *    adivinar cuál de las dos cosas le pidieron.
 *
 * ⚠️ Y UNA TERCERA, que es la que decide cuál de las dos herramientas va: **`CheckFromAddress` no
 * toca el origen si el artículo todavía no tiene NINGÚN depósito** (`count($article->addresses) >= 1`
 * es su guarda). Un "movimiento" sobre un artículo sin depósitos sumaría en el destino y no restaría
 * en ningún lado. Por eso `proponer_movimiento()` exige que el artículo YA tenga fila en el depósito
 * de origen, y manda a la otra herramienta cuando no la tiene: abrir el primer depósito es
 * exactamente lo que hace `update_addresses_stock` (sus conceptos `Creacion de deposito` y
 * `Actualizacion de deposito` están en `CheckToAddress::CONCEPTOS_QUE_REPARTEN`).
 *
 * PHP 7.4: sin enum, sin match, sin operador nullsafe.
 */
class PropuestaStockIaHelper
{
    /**
     * Permiso para editar el stock: el input de stock del Listado se dibuja con
     * `can('article.edit_stock')` (src/components/listado/components/StockInput.vue:5) y el botón de
     * movimiento, igual (table-props/stock-btn/Index.vue:7).
     */
    const PERMISO = 'article.edit_stock';

    /**
     * El permiso acotado a la sucursal de la persona. La pantalla de stock por sucursal lo mira
     * ANTES que el general (table-props/address-stock/Index.vue:27-38): con él, la persona solo
     * puede tocar el depósito que tiene asignado en su ficha (`users.address_id`). Se espeja tal
     * cual, porque un empleado de una sucursal que pueda mover stock de otra es justo lo que ese
     * permiso existe para impedir.
     */
    const PERMISO_SOLO_SU_SUCURSAL = 'article.edit_stock_only_sucursal';

    /**
     * 🔴 EL NOMBRE EXACTO DEL CONCEPTO, Y NO SE TOCA. Es el string que
     * `CheckFromAddress::get_amount_for_from_address()` (`:99-104`) compara para invertir el signo en
     * el depósito de origen, y también el que `CheckToAddress::CONCEPTOS_QUE_REPARTEN` (`:22`) mira
     * para dejar que el movimiento abra un depósito. Escrito de cualquier otra forma —con tilde, con
     * mayúscula distinta, en plural— el movimiento queda SIN concepto y el stock se suma en los dos
     * depósitos. Es el mismo literal que manda el modal de la pantalla
     * (listado/modals/address-movement/Index.vue:273) y el que siembra
     * `ConceptoStockMovementSeeder` (13º del array).
     */
    const CONCEPTO_MOVIMIENTO = 'Mov manual entre depositos';

    /** Los tres modos de `proponer_stock_en_deposito`. */
    const MODO_SUMAR = 'sumar';

    const MODO_RESTAR = 'restar';

    const MODO_FIJAR = 'fijar';

    /** Tope de candidatos que se ofrecen cuando un nombre es ambiguo. */
    const TOPE_CANDIDATOS = 10;

    /** Columnas del artículo que hacen falta: las mismas que usa PropuestaFotoArticuloIaHelper, más el stock. */
    const COLUMNAS = ['id', 'user_id', 'name', 'bar_code', 'provider_code', 'stock'];

    /**
     * Ruta de la SPA donde se ve el stock por depósito: el Listado de artículos.
     */
    const RUTA_LISTADO = 'listado';

    // =====================================================================================
    // (a) Mover stock entre depósitos
    // =====================================================================================

    /**
     * Herramienta proponer_movimiento_de_stock.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input  articulo|articulo_id*, cantidad*, desde*, hacia*, observaciones, reemplaza_a
     * @return array
     */
    public static function proponer_movimiento(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input): array
    {
        if (!PermisosIaHelper::puede($contexto->persona, self::PERMISO)
            && !PermisosIaHelper::puede($contexto->persona, self::PERMISO_SOLO_SU_SUCURSAL)) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_permiso('movimientos de stock'));
        }

        $articulo = self::resolver_articulo($contexto, $input, 'de qué artículo querés mover stock');

        if (RespuestaDeCargaIa::es_negativa($articulo)) {

            return $articulo;
        }

        $depositos = self::depositos_del_dueno($contexto->owner_id);

        if (count($depositos) < 2) {

            return RespuestaDeCargaIa::error(
                'Este negocio tiene una sola sucursal cargada, así que no hay entre qué depósitos mover stock.'
            );
        }

        $desde = self::resolver_deposito($contexto, $depositos, EntradaDeCargaIa::texto($input, 'desde'), 'de qué depósito sale el stock');

        if (RespuestaDeCargaIa::es_negativa($desde)) {

            return $desde;
        }

        $hacia = self::resolver_deposito($contexto, $depositos, EntradaDeCargaIa::texto($input, 'hacia'), 'a qué depósito entra el stock');

        if (RespuestaDeCargaIa::es_negativa($hacia)) {

            return $hacia;
        }

        if ((int) $desde->id === (int) $hacia->id) {

            return RespuestaDeCargaIa::error('El depósito de origen y el de destino son el mismo: decime a cuál otro va.');
        }

        $cantidad = EntradaDeCargaIa::monto_positivo(
            EntradaDeCargaIa::valor($input, 'cantidad'),
            'La cantidad a mover tiene que ser un número mayor a 0.'
        );

        if (is_array($cantidad)) {

            return $cantidad;
        }

        $cantidad = round((float) $cantidad, 2);

        $pivots = self::pivots_del_articulo($articulo->id);

        /*
         * 🔴 LA GUARDA DE CheckFromAddress, DICHA COMO UNA RESPUESTA Y NO COMO UN MOVIMIENTO ROTO.
         * Sin fila en el depósito de origen, el movimiento sumaría en el destino y no restaría en
         * ningún lado (ver el 🔴 del docblock de la clase). La salida es la otra herramienta, que es
         * la única que puede abrir un depósito.
         */
        if (!array_key_exists((int) $desde->id, $pivots)) {

            return RespuestaDeCargaIa::error(
                'El artículo "' . self::nombre_de_articulo($articulo) . '" no tiene stock cargado en ' . self::nombre_de_deposito($desde)
                . ', así que no hay de dónde sacarlo. Si lo que querés es cargarle stock a ese depósito, usá proponer_stock_en_deposito.'
            );
        }

        $stock_origen = (float) $pivots[(int) $desde->id]['amount'];

        $aviso = null;

        if ($cantidad > $stock_origen) {

            $aviso = self::numero($stock_origen) . ' es todo lo que hay en ' . self::nombre_de_deposito($desde)
                . ': moviendo ' . self::numero($cantidad) . ' ese depósito queda en ' . self::numero($stock_origen - $cantidad) . '.';
        }

        $observaciones = EntradaDeCargaIa::texto($input, 'observaciones');

        $payload = self::payload_de_movimiento($articulo, $cantidad, $desde, $hacia, $observaciones);

        $renglones = [
            ['etiqueta' => 'Artículo', 'valor' => self::nombre_de_articulo($articulo)],
            ['etiqueta' => 'Cantidad', 'valor' => self::numero($cantidad)],
            ['etiqueta' => 'Desde', 'valor' => self::nombre_de_deposito($desde) . ' (hoy ' . self::numero($stock_origen) . ')'],
            ['etiqueta' => 'Hacia', 'valor' => self::nombre_de_deposito($hacia) . ' (hoy ' . self::numero(self::amount_de($pivots, $hacia->id)) . ')'],
        ];

        if ($observaciones !== '') {

            $renglones[] = ['etiqueta' => 'Observaciones', 'valor' => $observaciones];
        }

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_MOVIMIENTO_STOCK,
            self::clave_movimiento($articulo->id, $desde->id, $hacia->id),
            ['payload' => $payload, 'esperado' => [
                'article_id'      => (int) $articulo->id,
                'from_address_id' => (int) $desde->id,
                'to_address_id'   => (int) $hacia->id,
                'cantidad'        => $cantidad,
            ]],
            ['titulo' => 'Movimiento de stock', 'renglones' => $renglones, 'aviso' => $aviso],
            EntradaDeCargaIa::valor($input, 'reemplaza_a')
        );

        $resumen = self::numero($cantidad) . ' de ' . self::nombre_de_articulo($articulo)
            . ' de ' . self::nombre_de_deposito($desde) . ' a ' . self::nombre_de_deposito($hacia);

        return AccionesIaHelper::respuesta_de_propuesta($creada, $resumen);
    }

    /**
     * Identidad del movimiento para el reemplazo: el mismo artículo entre los mismos dos depósitos.
     * Una corrección ("no, eran 5") pisa la tarjeta; otro artículo o el camino inverso, no.
     *
     * @param  int  $article_id
     * @param  int  $from_address_id
     * @param  int  $to_address_id
     * @return string
     */
    public static function clave_movimiento($article_id, $from_address_id, $to_address_id): string
    {
        return 'movimiento_stock:' . (int) $article_id . ':' . (int) $from_address_id . ':' . (int) $to_address_id;
    }

    /**
     * Registra el movimiento por `StockMovementController::store()`, con el payload guardado al
     * proponer, y CONFIRMA el resultado leyendo el pivot después.
     *
     * 🔴 POR QUÉ SE VUELVE A LEER EL STOCK. `store()` devuelve `response(null, 201)` pase lo que
     * pase: con el artículo borrado en el medio, con el concepto sin resolver, con el pivot que no
     * se pudo abrir. Un 201 de ese endpoint NO es la prueba de que algo se movió, y el defecto más
     * grave del diagnóstico de demo3 fue justamente el agente anunciando cargas que no existían. Lo
     * único que prueba que el movimiento pasó es el pivot: se lee antes, se llama, se lee después y
     * se compara contra lo esperado.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array  resultado {texto, ruta}
     *
     * @throws AccionIaException
     */
    public static function ejecutar_movimiento(ContextoDeCargaIa $contexto, AiMessageAction $accion): array
    {
        $datos = is_array($accion->datos) ? $accion->datos : [];

        $payload = isset($datos['payload']) && is_array($datos['payload']) ? $datos['payload'] : [];

        $esperado = isset($datos['esperado']) && is_array($datos['esperado']) ? $datos['esperado'] : [];

        $persona = self::persona_autenticada($contexto, 'El movimiento de stock');

        $articulo = self::articulo_de_la_tarjeta($contexto, isset($esperado['article_id']) ? $esperado['article_id'] : 0);

        self::verificar_permiso_de_ejecucion($contexto, [
            isset($esperado['from_address_id']) ? (int) $esperado['from_address_id'] : 0,
            isset($esperado['to_address_id']) ? (int) $esperado['to_address_id'] : 0,
        ]);

        $desde_id = isset($esperado['from_address_id']) ? (int) $esperado['from_address_id'] : 0;
        $hacia_id = isset($esperado['to_address_id']) ? (int) $esperado['to_address_id'] : 0;
        $cantidad = isset($esperado['cantidad']) ? (float) $esperado['cantidad'] : 0.0;

        if ($desde_id <= 0 || $hacia_id <= 0 || $cantidad <= 0) {

            throw new AccionIaException(422, 'La tarjeta del movimiento está incompleta. Pedímela de nuevo.');
        }

        self::verificar_depositos($contexto, [$desde_id, $hacia_id]);

        $antes = self::pivots_del_articulo($articulo->id);

        if (!array_key_exists($desde_id, $antes)) {

            throw new AccionIaException(422, 'El artículo ya no tiene stock cargado en el depósito de origen. Pedime el movimiento de nuevo.');
        }

        $request = Request::create('/api/stock-movement', 'POST', $payload);
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(function () use ($persona) {
            return $persona;
        });

        app(StockMovementController::class)->store($request);

        $despues = self::pivots_del_articulo($articulo->id);

        $origen_antes = self::amount_de($antes, $desde_id);
        $origen_despues = self::amount_de($despues, $desde_id);
        $destino_antes = self::amount_de($antes, $hacia_id);
        $destino_despues = self::amount_de($despues, $hacia_id);

        /*
         * 🔴 Las dos comparaciones que denuncian la trampa 1: si el concepto no se hubiera resuelto,
         * el origen quedaría IGUAL o incluso más alto (se suma en los dos lados) en vez de bajar.
         * La tolerancia es de centavos, por el decimal(12,2) del pivot.
         */
        if (abs(($origen_antes - $cantidad) - $origen_despues) > 0.01
            || abs(($destino_antes + $cantidad) - $destino_despues) > 0.01) {

            throw new AccionIaException(
                422,
                'El sistema no registró el movimiento como corresponde: ' . self::nombre_de_deposito_por_id($desde_id)
                . ' quedó en ' . self::numero($origen_despues) . ' y el destino en ' . self::numero($destino_despues)
                . '. No lo doy por hecho: revisalo en el Listado antes de volver a pedírmelo.'
            );
        }

        return [
            'texto' => 'Movimiento de ' . self::numero($cantidad) . ' registrado: ' . self::nombre_de_deposito_por_id($desde_id)
                . ' quedó en ' . self::numero($origen_despues) . ' y ' . self::nombre_de_deposito_por_id($hacia_id)
                . ' en ' . self::numero($destino_despues),
            'ruta'  => [
                'name'   => self::RUTA_LISTADO,
                'params' => new \stdClass(),
                'texto'  => 'Ver en el Listado',
            ],
        ];
    }

    /**
     * El payload de `POST api/stock-movement`, clave por clave como lo manda el modal "Movimiento de
     * depósitos" de la pantalla (listado/modals/address-movement/Index.vue:266-274).
     *
     * `article_variant_id` va en 0 (que `getArticleVariantId()` lee como null) porque el asistente no
     * trabaja con variantes: el modal de la pantalla sí las ofrece, y ese es su camino.
     *
     * @param  \App\Models\Article  $articulo
     * @param  float  $cantidad
     * @param  \App\Models\Address  $desde
     * @param  \App\Models\Address  $hacia
     * @param  string  $observaciones
     * @return array<string, mixed>
     */
    public static function payload_de_movimiento(Article $articulo, $cantidad, Address $desde, Address $hacia, $observaciones): array
    {
        return [
            'model_id'                     => (int) $articulo->id,
            // 🔴 SIEMPRE POSITIVO: el signo del origen lo pone CheckFromAddress por el concepto.
            'amount'                       => (float) $cantidad,
            'observations'                 => $observaciones,
            'from_address_id'              => (int) $desde->id,
            'to_address_id'                => (int) $hacia->id,
            'article_variant_id'           => 0,
            'concepto_stock_movement_name' => self::CONCEPTO_MOVIMIENTO,
        ];
    }

    // =====================================================================================
    // (b) El stock de UN depósito
    // =====================================================================================

    /**
     * Herramienta proponer_stock_en_deposito.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje
     * @param  array  $input  articulo|articulo_id*, deposito*, cantidad*, modo, reemplaza_a
     * @return array
     */
    public static function proponer_stock_en_deposito(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input): array
    {
        if (!PermisosIaHelper::puede($contexto->persona, self::PERMISO)
            && !PermisosIaHelper::puede($contexto->persona, self::PERMISO_SOLO_SU_SUCURSAL)) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_permiso('el stock de los depósitos'));
        }

        $articulo = self::resolver_articulo($contexto, $input, 'de qué artículo querés cambiar el stock');

        if (RespuestaDeCargaIa::es_negativa($articulo)) {

            return $articulo;
        }

        $depositos = self::depositos_del_dueno($contexto->owner_id);

        if (!count($depositos)) {

            return RespuestaDeCargaIa::error('Este negocio no tiene ninguna sucursal cargada: el stock se edita sobre el total del artículo.');
        }

        $deposito = self::resolver_deposito($contexto, $depositos, EntradaDeCargaIa::texto($input, 'deposito'), 'en qué depósito');

        if (RespuestaDeCargaIa::es_negativa($deposito)) {

            return $deposito;
        }

        $modo = EntradaDeCargaIa::texto($input, 'modo');

        if ($modo === '') {

            $modo = self::MODO_FIJAR;
        }

        if (!in_array($modo, [self::MODO_SUMAR, self::MODO_RESTAR, self::MODO_FIJAR], true)) {

            return RespuestaDeCargaIa::error('El modo tiene que ser "sumar", "restar" o "fijar".');
        }

        $cantidad = EntradaDeCargaIa::valor($input, 'cantidad');

        if (EntradaDeCargaIa::vacio($cantidad)) {

            return RespuestaDeCargaIa::faltan(['cuántas unidades']);
        }

        if (!is_numeric($cantidad)) {

            return RespuestaDeCargaIa::error('La cantidad tiene que ser un número.');
        }

        $cantidad = round((float) $cantidad, 2);

        if ($modo !== self::MODO_FIJAR && $cantidad <= 0) {

            return RespuestaDeCargaIa::error('La cantidad a ' . $modo . ' tiene que ser un número mayor a 0.');
        }

        if ($modo === self::MODO_FIJAR && $cantidad < 0) {

            return RespuestaDeCargaIa::error('El stock de un depósito no puede quedar en un número negativo.');
        }

        $pivots = self::pivots_del_articulo($articulo->id);

        $actual = self::amount_de($pivots, $deposito->id);

        /*
         * 🔴 ACÁ SE TAPA LA TRAMPA 2. `pivot.amount` es el stock FINAL, no un delta: "sumale 10"
         * mandado como 10 deja el depósito EN 10. El modo lo dice la herramienta y la cuenta se hace
         * acá, con el número que hay hoy leído del pivot en esta misma vuelta.
         */
        if ($modo === self::MODO_SUMAR) {

            $final = $actual + $cantidad;

        } elseif ($modo === self::MODO_RESTAR) {

            $final = $actual - $cantidad;

        } else {

            $final = $cantidad;
        }

        $final = round($final, 2);

        if ($final < 0) {

            return RespuestaDeCargaIa::error(
                'En ' . self::nombre_de_deposito($deposito) . ' hay ' . self::numero($actual) . ': restando ' . self::numero($cantidad)
                . ' quedaría en ' . self::numero($final) . ', y el stock de un depósito no puede quedar negativo.'
            );
        }

        $abre_deposito = !array_key_exists((int) $deposito->id, $pivots);

        $payload = self::payload_de_stock_en_deposito($articulo, $depositos, $pivots, $deposito->id, $final);

        $renglones = [
            ['etiqueta' => 'Artículo', 'valor' => self::nombre_de_articulo($articulo)],
            ['etiqueta' => 'Depósito', 'valor' => self::nombre_de_deposito($deposito)],
            ['etiqueta' => 'Stock', 'valor' => self::numero($actual) . ' → ' . self::numero($final)],
        ];

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_STOCK_DEPOSITO,
            self::clave_deposito($articulo->id, $deposito->id),
            /*
             * ⚠️ `payload` queda guardado como registro de lo que se propuso, pero al confirmar NO
             * se usa: se rearma con los números de ese momento. Ver el 🔴 de
             * ejecutar_stock_en_deposito(). Lo que manda al ejecutar es `esperado`.
             */
            ['payload' => $payload, 'esperado' => [
                'article_id' => (int) $articulo->id,
                'address_id' => (int) $deposito->id,
                'final'      => $final,
            ]],
            [
                'titulo'    => 'Stock en un depósito',
                'renglones' => $renglones,
                'aviso'     => $abre_deposito
                    ? 'Este artículo todavía no tenía stock en ' . self::nombre_de_deposito($deposito) . ': el depósito se le abre con esta carga.'
                    : null,
            ],
            EntradaDeCargaIa::valor($input, 'reemplaza_a')
        );

        $resumen = self::nombre_de_articulo($articulo) . ' en ' . self::nombre_de_deposito($deposito)
            . ': ' . self::numero($actual) . ' → ' . self::numero($final);

        return AccionesIaHelper::respuesta_de_propuesta($creada, $resumen);
    }

    /**
     * Identidad del cambio de stock para el reemplazo: el mismo artículo en el mismo depósito.
     *
     * @param  int  $article_id
     * @param  int  $address_id
     * @return string
     */
    public static function clave_deposito($article_id, $address_id): string
    {
        return 'stock_deposito:' . (int) $article_id . ':' . (int) $address_id;
    }

    /**
     * Deja el stock del depósito en el número de la tarjeta, por
     * `ArticleController::update_addresses_stock()`, y lo confirma leyendo el pivot después.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array  resultado {texto, ruta}
     *
     * @throws AccionIaException
     */
    public static function ejecutar_stock_en_deposito(ContextoDeCargaIa $contexto, AiMessageAction $accion): array
    {
        $datos = is_array($accion->datos) ? $accion->datos : [];

        $esperado = isset($datos['esperado']) && is_array($datos['esperado']) ? $datos['esperado'] : [];

        $persona = self::persona_autenticada($contexto, 'El stock de un depósito');

        $articulo = self::articulo_de_la_tarjeta($contexto, isset($esperado['article_id']) ? $esperado['article_id'] : 0);

        $address_id = isset($esperado['address_id']) ? (int) $esperado['address_id'] : 0;

        if ($address_id <= 0) {

            throw new AccionIaException(422, 'La tarjeta del stock está incompleta. Pedímela de nuevo.');
        }

        self::verificar_permiso_de_ejecucion($contexto, [$address_id]);

        self::verificar_depositos($contexto, [$address_id]);

        $final = isset($esperado['final']) ? (float) $esperado['final'] : 0.0;

        $pivots = self::pivots_del_articulo($articulo->id);

        $antes = self::amount_de($pivots, $address_id);

        /*
         * 🔴 EL PAYLOAD SE REARMA ACÁ, CON LOS NÚMEROS DE AHORA, Y NO SE USA EL QUE GUARDÓ LA
         * TARJETA. Es la otra cara de la trampa 2: el payload lleva TODOS los depósitos del
         * artículo, y `UpdateAddressesStockHelper` calcula, para cada uno, la diferencia contra lo
         * que hay. Entre proponer y confirmar pueden pasar horas: si en el medio se vendió algo de
         * OTRO depósito, el payload viejo lo "corregiría" a su número anterior — un ajuste de stock
         * que nadie pidió, en un depósito que la tarjeta ni nombra. Rearmado con los pivots de
         * ahora, los que no cambian viajan con su valor actual (diferencia 0, no se tocan) y el que
         * cambia va al número que la persona confirmó en la tarjeta.
         */
        $payload = self::payload_de_stock_en_deposito(
            $articulo,
            self::depositos_del_dueno($contexto->owner_id),
            $pivots,
            $address_id,
            $final
        );

        $request = Request::create('/api/article-update-addresses', 'PUT', $payload);
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(function () use ($persona) {
            return $persona;
        });

        app(ArticleController::class)->update_addresses_stock($request);

        $despues = self::amount_de(self::pivots_del_articulo($articulo->id), $address_id);

        if (abs($final - $despues) > 0.01) {

            throw new AccionIaException(
                422,
                'El sistema no dejó el stock donde corresponde: ' . self::nombre_de_deposito_por_id($address_id)
                . ' quedó en ' . self::numero($despues) . ' y tenía que quedar en ' . self::numero($final)
                . '. No lo doy por hecho: revisalo en el Listado.'
            );
        }

        return [
            'texto' => 'Stock de ' . self::nombre_de_deposito_por_id($address_id) . ' actualizado: '
                . self::numero($antes) . ' → ' . self::numero($despues),
            'ruta'  => [
                'name'   => self::RUTA_LISTADO,
                'params' => new \stdClass(),
                'texto'  => 'Ver en el Listado',
            ],
        ];
    }

    /**
     * El payload de `PUT api/article-update-addresses`, con la forma que manda la pantalla: el
     * objeto address COMPLETO de cada depósito, con su `pivot`.
     *
     * 🔴 VAN TODOS LOS DEPÓSITOS, NO SOLO EL QUE CAMBIA, y cada uno con el número que tiene HOY.
     * `UpdateAddressesStockHelper` recorre lo que le mandan y para cada uno calcula la diferencia
     * contra el pivot: un depósito que no viaja no se toca (bien), pero uno que viaja con un número
     * viejo se "corrige" a ese número viejo (mal). Mandar los actuales es lo mismo que hace la
     * pantalla, que manda el modelo entero.
     *
     * 🔴 Y `stock_min` / `stock_max` van con lo que hay hoy, incluidos los null.
     * `set_stock_min_max()` saltea el depósito cuando los DOS vienen en null, así que los que no
     * tienen mínimo ni máximo no se tocan; pero si mandáramos uno solo, el otro se escribiría en
     * null en silencio.
     *
     * @param  \App\Models\Article  $articulo
     * @param  \Illuminate\Support\Collection  $depositos
     * @param  array  $pivots  [address_id => ['amount' =>, 'stock_min' =>, 'stock_max' =>]]
     * @param  int  $address_id  El depósito que cambia.
     * @param  float  $final  Stock FINAL de ese depósito.
     * @return array<string, mixed>
     */
    public static function payload_de_stock_en_deposito(Article $articulo, $depositos, array $pivots, $address_id, $final): array
    {
        $addresses = [];

        foreach ($depositos as $deposito) {

            $id = (int) $deposito->id;

            $tiene_pivot = array_key_exists($id, $pivots);

            // Un depósito que el artículo todavía no tiene NO viaja, salvo que sea el que se abre:
            // mandarlo con amount 0 le abriría un pivot en cero a todos, de una.
            if (!$tiene_pivot && $id !== (int) $address_id) {

                continue;
            }

            $addresses[] = [
                'id'    => $id,
                'pivot' => [
                    'amount'    => $id === (int) $address_id ? (float) $final : (float) $pivots[$id]['amount'],
                    'stock_min' => $tiene_pivot ? $pivots[$id]['stock_min'] : null,
                    'stock_max' => $tiene_pivot ? $pivots[$id]['stock_max'] : null,
                ],
            ];
        }

        return [
            'article_id' => (int) $articulo->id,
            'addresses'  => $addresses,
        ];
    }

    // =====================================================================================
    // Resolución y lecturas comunes
    // =====================================================================================

    /**
     * El artículo que nombró la persona (por nombre, código o id), o la respuesta de negocio. Misma
     * cascada que PropuestaFotoArticuloIaHelper::resolver_articulo(): id → LIKE por nombre y los dos
     * códigos → coincidencia exacta normalizada → `faltan` con los candidatos.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $input
     * @param  string  $que_falta
     * @return \App\Models\Article|array
     */
    protected static function resolver_articulo(ContextoDeCargaIa $contexto, array $input, $que_falta)
    {
        $base = Article::query()->where('user_id', $contexto->owner_id);

        $articulo_id = EntradaDeCargaIa::valor($input, 'articulo_id');

        if (!EntradaDeCargaIa::vacio($articulo_id) && is_numeric($articulo_id) && (int) $articulo_id > 0) {

            $articulo = (clone $base)->where('id', (int) $articulo_id)->first(self::COLUMNAS);

            if (is_null($articulo)) {

                return RespuestaDeCargaIa::error('Ese artículo no existe entre los tuyos. Buscalo por nombre.');
            }

            return $articulo;
        }

        $texto = EntradaDeCargaIa::texto($input, 'articulo');

        if ($texto === '') {

            return RespuestaDeCargaIa::faltan([$que_falta]);
        }

        $normalizado = ConsultasSistemaIaHelper::normalize_text($texto);

        /* El `%` y el `_` que escriba la persona son literales, no comodines de LIKE. */
        $escapado = addcslashes($texto, '%_\\');

        $candidatos = (clone $base)
            ->where(function ($sub) use ($escapado) {
                $sub->where('name', 'LIKE', '%' . $escapado . '%')
                    ->orWhere('bar_code', 'LIKE', '%' . $escapado . '%')
                    ->orWhere('provider_code', 'LIKE', '%' . $escapado . '%');
            })
            ->orderBy('name')
            ->limit(self::TOPE_CANDIDATOS + 1)
            ->get(self::COLUMNAS);

        if (count($candidatos) === 0) {

            return RespuestaDeCargaIa::error(
                'No encontré ningún artículo que se llame "' . $texto . '" ni con ese código. Buscalo con otra palabra o decime el código.'
            );
        }

        if (count($candidatos) === 1) {

            return $candidatos[0];
        }

        foreach ($candidatos as $candidato) {

            if (ConsultasSistemaIaHelper::normalize_text((string) $candidato->name) === $normalizado
                || (trim((string) $candidato->bar_code) !== '' && ConsultasSistemaIaHelper::normalize_text((string) $candidato->bar_code) === $normalizado)
                || (trim((string) $candidato->provider_code) !== '' && ConsultasSistemaIaHelper::normalize_text((string) $candidato->provider_code) === $normalizado)) {

                return $candidato;
            }
        }

        $opciones = [];

        foreach ($candidatos->take(self::TOPE_CANDIDATOS) as $candidato) {

            $opciones[] = [
                'articulo_id' => (int) $candidato->id,
                'nombre'      => self::nombre_de_articulo($candidato),
            ];
        }

        return RespuestaDeCargaIa::faltan(['a cuál de estos artículos'], ['articulos' => $opciones]);
    }

    /**
     * Las sucursales/depósitos del dueño.
     *
     * 🔴 CON `whereNull('buyer_id')`, que es la mitad del bug #38-#42 del diagnóstico de demo3: la
     * tabla `addresses` guarda también los domicilios de los compradores de la tienda (los escribe
     * `tienda-api`, que comparte la base) y esas filas llevan el `user_id` del dueño. Sin el filtro,
     * el asistente le ofrecería al dueño mover stock al domicilio particular de un comprador.
     *
     * @param  int  $owner_id
     * @return \Illuminate\Support\Collection
     */
    public static function depositos_del_dueno($owner_id)
    {
        return Address::where('user_id', (int) $owner_id)
            ->whereNull('buyer_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * El depósito que nombró la persona, o la respuesta de negocio con la lista para elegir.
     *
     * 🔴 Y ACÁ SE ESPEJA `article.edit_stock_only_sucursal`: la pantalla de stock por sucursal solo
     * le deja tocar a la persona el depósito de su propia ficha (`users.address_id`), así que un
     * depósito ajeno se rechaza con el motivo, no se ofrece.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \Illuminate\Support\Collection  $depositos
     * @param  string  $texto
     * @param  string  $que_falta
     * @return \App\Models\Address|array
     */
    protected static function resolver_deposito(ContextoDeCargaIa $contexto, $depositos, $texto, $que_falta)
    {
        $opciones = [];

        foreach ($depositos as $deposito) {

            $opciones[] = ['nombre' => self::nombre_de_deposito($deposito)];
        }

        if (trim((string) $texto) === '') {

            return RespuestaDeCargaIa::faltan([$que_falta], ['depositos' => $opciones]);
        }

        $normalizado = ConsultasSistemaIaHelper::normalize_text((string) $texto);

        $exactos = [];
        $parciales = [];

        foreach ($depositos as $deposito) {

            $nombre = ConsultasSistemaIaHelper::normalize_text(self::nombre_de_deposito($deposito));

            if ($nombre === $normalizado) {

                $exactos[] = $deposito;

            } elseif ($nombre !== '' && $normalizado !== '' && strpos($nombre, $normalizado) !== false) {

                $parciales[] = $deposito;
            }
        }

        $encontrados = count($exactos) ? $exactos : $parciales;

        if (!count($encontrados)) {

            return RespuestaDeCargaIa::error(
                'No encontré ningún depósito que se llame "' . trim((string) $texto) . '" entre los de este negocio.',
                ['depositos' => $opciones]
            );
        }

        if (count($encontrados) > 1) {

            $candidatos = [];

            foreach ($encontrados as $deposito) {

                $candidatos[] = ['nombre' => self::nombre_de_deposito($deposito)];
            }

            return RespuestaDeCargaIa::faltan(['cuál de estos depósitos'], ['depositos' => $candidatos]);
        }

        $elegido = $encontrados[0];

        if (!self::puede_en_deposito($contexto->persona, $elegido->id)) {

            return RespuestaDeCargaIa::error(
                'Tu usuario solo puede tocar el stock de la sucursal que tiene asignada, y ' . self::nombre_de_deposito($elegido) . ' no es esa.'
            );
        }

        return $elegido;
    }

    /**
     * Espejo de `puede_editar_stock` de la pantalla
     * (listado/components/table-props/address-stock/Index.vue:28-37): admin → sí; con
     * `article.edit_stock_only_sucursal` → solo su propia sucursal; si no, `article.edit_stock`.
     *
     * @param  \App\Models\User|null  $persona
     * @param  int  $address_id
     * @return bool
     */
    public static function puede_en_deposito($persona, $address_id): bool
    {
        if (PermisosIaHelper::es_admin($persona)) {

            return true;
        }

        if (PermisosIaHelper::puede($persona, self::PERMISO_SOLO_SU_SUCURSAL)) {

            return !is_null($persona) && (int) $persona->address_id === (int) $address_id;
        }

        return PermisosIaHelper::puede($persona, self::PERMISO);
    }

    /**
     * Los pivots de `address_article` del artículo, indexados por address_id.
     *
     * Se lee por query builder y no por la relación para que no quede cacheada en el modelo: entre
     * la lectura de antes y la de después hay una escritura de por medio, y una relación ya cargada
     * devolvería los mismos números las dos veces.
     *
     * @param  int  $article_id
     * @return array<int, array<string, mixed>>
     */
    public static function pivots_del_articulo($article_id): array
    {
        $filas = DB::table('address_article')
            ->where('article_id', (int) $article_id)
            ->get(['address_id', 'amount', 'stock_min', 'stock_max']);

        $pivots = [];

        foreach ($filas as $fila) {

            $pivots[(int) $fila->address_id] = [
                'amount'    => is_null($fila->amount) ? 0.0 : (float) $fila->amount,
                'stock_min' => is_null($fila->stock_min) ? null : (int) $fila->stock_min,
                'stock_max' => is_null($fila->stock_max) ? null : (int) $fila->stock_max,
            ];
        }

        return $pivots;
    }

    /**
     * El `amount` del pivot de ese depósito, o 0 si el artículo todavía no lo tiene.
     *
     * @param  array  $pivots
     * @param  int  $address_id
     * @return float
     */
    protected static function amount_de(array $pivots, $address_id): float
    {
        return array_key_exists((int) $address_id, $pivots) ? (float) $pivots[(int) $address_id]['amount'] : 0.0;
    }

    /**
     * La persona autenticada, que los dos controllers necesitan: `StockMovementController::crear()`
     * resuelve el dueño y el empleado con `UserHelper::userId()`, que sin sesión devuelve el
     * `USER_ID` de config, o sea OTRO comercio. Misma guarda que PropuestaVentaIaHelper.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $que
     * @return \App\Models\User
     *
     * @throws AccionIaException
     */
    protected static function persona_autenticada(ContextoDeCargaIa $contexto, $que)
    {
        $persona = $contexto->persona;

        if (is_null($persona) || is_null(Auth::id()) || (int) Auth::id() !== (int) $persona->id) {

            throw new AccionIaException(500, $que . ' no se puede registrar sin la persona autenticada.');
        }

        return $persona;
    }

    /**
     * El artículo de la tarjeta, revisado de nuevo al confirmar: entre la propuesta y el clic pueden
     * pasar horas y el artículo se pudo borrar.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed  $article_id
     * @return \App\Models\Article
     *
     * @throws AccionIaException
     */
    protected static function articulo_de_la_tarjeta(ContextoDeCargaIa $contexto, $article_id)
    {
        $articulo = Article::where('user_id', $contexto->owner_id)
            ->where('id', (int) $article_id)
            ->first(self::COLUMNAS);

        if (is_null($articulo)) {

            throw new AccionIaException(422, 'El artículo de la tarjeta ya no existe entre los tuyos. Pedímelo de nuevo.');
        }

        return $articulo;
    }

    /**
     * Los depósitos de la tarjeta siguen siendo del dueño y siguen sin ser domicilios de compradores.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array<int,int>  $ids
     * @return void
     *
     * @throws AccionIaException
     */
    protected static function verificar_depositos(ContextoDeCargaIa $contexto, array $ids)
    {
        foreach ($ids as $id) {

            $existe = Address::where('user_id', $contexto->owner_id)
                ->whereNull('buyer_id')
                ->where('id', (int) $id)
                ->exists();

            if (!$existe) {

                throw new AccionIaException(422, 'Uno de los depósitos de la tarjeta ya no existe entre los de este negocio. Pedímelo de nuevo.');
            }
        }
    }

    /**
     * El permiso, revisado de nuevo al confirmar y depósito por depósito (el `only_sucursal` se
     * puede haber sacado, o la persona pudo cambiar de sucursal entre la propuesta y el clic).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array<int,int>  $address_ids
     * @return void
     *
     * @throws AccionIaException
     */
    protected static function verificar_permiso_de_ejecucion(ContextoDeCargaIa $contexto, array $address_ids)
    {
        foreach ($address_ids as $address_id) {

            if (!self::puede_en_deposito($contexto->persona, $address_id)) {

                throw new AccionIaException(422, PermisosIaHelper::mensaje_sin_permiso('el stock de ese depósito'));
            }
        }
    }

    /**
     * El nombre visible de un depósito. 🔴 La columna se llama `street`: en `addresses` NO existe
     * ninguna columna `name`, y es la que muestra el ABM de Sucursales.
     *
     * @param  \App\Models\Address  $deposito
     * @return string
     */
    public static function nombre_de_deposito(Address $deposito): string
    {
        $nombre = trim((string) $deposito->street);

        return $nombre === '' ? 'Sucursal ' . $deposito->id : $nombre;
    }

    /**
     * El nombre de un depósito por su id, para los textos del resultado.
     *
     * @param  int  $address_id
     * @return string
     */
    protected static function nombre_de_deposito_por_id($address_id): string
    {
        $deposito = Address::find((int) $address_id);

        return is_null($deposito) ? 'Sucursal ' . (int) $address_id : self::nombre_de_deposito($deposito);
    }

    /**
     * El nombre del artículo, con su código de respaldo: "Artículo 123" sin nada atrás no le dice a
     * nadie qué está por confirmar.
     *
     * @param  \App\Models\Article  $articulo
     * @return string
     */
    public static function nombre_de_articulo(Article $articulo): string
    {
        $nombre = trim((string) $articulo->name);

        if ($nombre !== '') {

            return $nombre;
        }

        $codigo = trim((string) $articulo->bar_code);

        if ($codigo === '') {

            $codigo = trim((string) $articulo->provider_code);
        }

        return $codigo === '' ? 'Artículo ' . $articulo->id : $codigo;
    }

    /**
     * Una cantidad de stock como la lee una persona: sin decimales si es entera.
     *
     * @param  float  $numero
     * @return string
     */
    protected static function numero($numero): string
    {
        $numero = round((float) $numero, 2);

        return $numero == (int) $numero
            ? (string) (int) $numero
            : rtrim(rtrim(number_format($numero, 2, ',', ''), '0'), ',');
    }
}
