<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\BudgetController;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Budget;
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

        $items = EntradaDeCargaIa::valor($input, 'items');

        /*
         * Misión asistente-diario-20261119 (rama B, A6): «poné lo que interpretes y yo después lo reviso». Con
         * `con_lo_que_encuentres`, cada renglón se resuelve por separado: el ambiguo va con su mejor coincidencia y
         * el que no se encuentra queda afuera y se lista aparte. Ver interpretar_items().
         */
        $interpretacion = null;

        if (self::pide_lo_que_encuentres($input)) {

            $interpretacion = self::interpretar_items($contexto, $items, $lista);

            if (RespuestaDeCargaIa::es_negativa($interpretacion)) {

                return $interpretacion;
            }

            $items = $interpretacion['items'];
        }

        /* Sin cobro no hay descuento de método de pago: el tercer argumento va en null a propósito. */
        $renglones = PropuestaVentaIaHelper::resolver_renglones($contexto, $items, $lista, null, $faltan, $opciones);

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

        $anterior = self::presupuesto_anterior($contexto, $cliente);

        $extra = [];

        if (!is_null($interpretacion)) {

            $presentacion = self::con_lo_interpretado($presentacion, $interpretacion);
            $extra = array_merge($extra, self::extra_de_lo_interpretado($interpretacion));
        }

        if (!is_null($anterior)) {

            $aviso = 'Este es un presupuesto NUEVO: el N° ' . $anterior['numero'] . ' sigue existiendo. Si querías corregirlo, después podés borrar el anterior.';

            $presentacion['aviso'] = trim((string) $presentacion['aviso'] . ' ' . $aviso);

            $extra['presupuesto_anterior'] = [
                'numero'         => $anterior['numero'],
                'presupuesto_id' => $anterior['presupuesto_id'],
                'nota'           => 'Esta tarjeta crea un presupuesto NUEVO, con otro número: el N° ' . $anterior['numero'] . ' para este cliente sigue existiendo. '
                    . 'No digas que lo corregiste. Si la persona quería cambiar el anterior, ofrecé borrarlo con proponer_borrado_por_pantalla '
                    . '(ruta api/budget/{budget}, parametros {"numero": ' . $anterior['numero'] . '}).',
            ];
        }

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

        return AccionesIaHelper::respuesta_de_propuesta($creada, $resumen, $extra);
    }

    // -------------------------------------------------------------------------------------------
    // «Poné lo que interpretes» (misión asistente-diario-20261119, rama B, A6)
    // -------------------------------------------------------------------------------------------

    /**
     * Cuántas otras opciones se le cuentan al modelo por renglón interpretado (para que la persona sepa qué más
     * había).
     */
    const OTRAS_OPCIONES_POR_RENGLON = 3;

    /**
     * true si el modelo pidió armar el presupuesto con lo que se encuentre (`con_lo_que_encuentres`).
     *
     * @param  array  $input
     * @return bool
     */
    protected static function pide_lo_que_encuentres(array $input): bool
    {
        $valor = EntradaDeCargaIa::valor($input, 'con_lo_que_encuentres');

        return $valor === true || $valor === 1 || $valor === '1' || (is_string($valor) && in_array(mb_strtolower(trim($valor)), ['true', 'si', 'sí'], true));
    }

    /**
     * Los renglones de una lista larga resueltos de a uno, con la mejor coincidencia para los ambiguos y los no
     * encontrados aparte.
     *
     * 🔴 EL CASO REAL (Ferre Total, conv 62, 18/11/2026). Pablo mandó 37 renglones para un presupuesto de Fideicomiso
     * El Cantón, dijo «Buscalo por nombre» y después «Poné lo que interpretes y yo después lo reviso y corrijo lo que
     * haya que corregir». La herramienta cortaba con el PRIMER renglón que no existía («No encontré ningún artículo
     * activo que se llame…») y preguntaba cada ambiguo, así que con una lista así no había forma de armar nada: el
     * asistente pidió los códigos dos veces y el presupuesto nunca se armó, ni con lo que sí había.
     *
     * Con esta opción, cada renglón se resuelve por el MISMO camino que siempre (PropuestaVentaIaHelper::
     * resolver_renglones(), uno por vez):
     *   - el que se resuelve, queda tal cual;
     *   - el AMBIGUO (varios artículos encajan) va con la primera opción, que es la mejor coincidencia —la búsqueda
     *     ya ordena por la frase entera (BusquedaPorPalabrasIaHelper::ordenar_por_frase)— y queda anotado en
     *     `interpretados` con lo que se eligió y las otras opciones, para que la persona lo revise;
     *   - el que no se puede poner (no existe, no tiene precio, se vende por variante) queda AFUERA y anotado en
     *     `no_encontrados` con su texto y el motivo.
     *
     * No inventa precios ni artículos: el precio de cada renglón sale de la lista, como siempre, y la tarjeta espera
     * el sí como cualquier presupuesto (o se hace en el acto en "directo", que es lo de siempre para un presupuesto:
     * no toca stock, ni caja, ni cuenta corriente). Si no se encuentra NINGUNO, es un error que lo dice.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed  $items
     * @param  \App\Models\PriceType|null  $lista
     * @return array{items: array, interpretados: array, no_encontrados: array}|array  O la respuesta negativa.
     */
    protected static function interpretar_items(ContextoDeCargaIa $contexto, $items, $lista)
    {
        if (!is_array($items) || !count($items)) {

            return RespuestaDeCargaIa::faltan(['qué artículos lleva el presupuesto y cuántos de cada uno']);
        }

        $quedan = [];
        $interpretados = [];
        $no_encontrados = [];

        foreach (array_values($items) as $indice => $item) {

            $item = is_array($item) ? $item : [];
            $texto = EntradaDeCargaIa::texto($item, 'articulo');
            $pedido = $texto !== '' ? $texto : 'renglón ' . ($indice + 1);

            $faltan = [];
            $opciones = [];
            $resuelto = PropuestaVentaIaHelper::resolver_renglones($contexto, [$item], $lista, null, $faltan, $opciones);

            if (RespuestaDeCargaIa::es_negativa($resuelto)) {

                $no_encontrados[] = ['pedido' => $pedido, 'motivo' => self::motivo_de($resuelto)];
                continue;
            }

            if (!count($faltan)) {

                $quedan[] = $item;
                continue;
            }

            /* Ambiguo: la primera opción es la mejor coincidencia. Se vuelve a resolver por su id (precio incluido). */
            $candidatas = self::articulos_de_las_opciones($opciones);

            if (!count($candidatas)) {

                $no_encontrados[] = ['pedido' => $pedido, 'motivo' => 'no se pudo decidir a qué artículo corresponde'];
                continue;
            }

            $elegida = $candidatas[0];
            $con_id = $item;
            $con_id['articulo_id'] = (int) $elegida['articulo_id'];
            unset($con_id['articulo']);

            $faltan = [];
            $opciones = [];
            $verificado = PropuestaVentaIaHelper::resolver_renglones($contexto, [$con_id], $lista, null, $faltan, $opciones);

            if (RespuestaDeCargaIa::es_negativa($verificado) || count($faltan)) {

                $no_encontrados[] = ['pedido' => $pedido, 'motivo' => self::motivo_de($verificado)];
                continue;
            }

            $otras = [];

            foreach (array_slice($candidatas, 1, self::OTRAS_OPCIONES_POR_RENGLON) as $otra) {

                $otras[] = (string) $otra['nombre'];
            }

            $interpretados[] = ['pedido' => $pedido, 'elegido' => (string) $elegida['nombre'], 'otras_opciones' => $otras];
            $quedan[] = $con_id;
        }

        if (!count($quedan)) {

            $lista_de_pedidos = [];

            foreach ($no_encontrados as $no) {

                $lista_de_pedidos[] = '«' . $no['pedido'] . '»';
            }

            return RespuestaDeCargaIa::error(
                'No encontré ninguno de los artículos de la lista entre los artículos activos con precio (' . implode(', ', array_slice($lista_de_pedidos, 0, 10))
                    . (count($lista_de_pedidos) > 10 ? ' y ' . (count($lista_de_pedidos) - 10) . ' más' : '') . '), así que no hay presupuesto para armar. '
                    . 'Contáselo así y ofrecé buscarlos con otra palabra o que te pase los códigos.'
            );
        }

        return ['items' => $quedan, 'interpretados' => $interpretados, 'no_encontrados' => $no_encontrados];
    }

    /**
     * Las opciones de artículos que devolvió un renglón ambiguo, en orden (la primera es la mejor coincidencia).
     *
     * @param  array|\stdClass  $opciones
     * @return array<int, array{articulo_id: int, nombre: string}>
     */
    protected static function articulos_de_las_opciones($opciones): array
    {
        $opciones = is_object($opciones) ? (array) $opciones : $opciones;

        if (!is_array($opciones) || !isset($opciones['articulos']) || !is_array($opciones['articulos'])) {

            return [];
        }

        $articulos = [];

        foreach ($opciones['articulos'] as $opcion) {

            $opcion = is_object($opcion) ? (array) $opcion : $opcion;

            if (is_array($opcion) && isset($opcion['articulo_id']) && (int) $opcion['articulo_id'] > 0) {

                $articulos[] = ['articulo_id' => (int) $opcion['articulo_id'], 'nombre' => isset($opcion['nombre']) ? (string) $opcion['nombre'] : ''];
            }
        }

        return $articulos;
    }

    /**
     * El motivo legible de una respuesta negativa (el `error`, o lo primero que `faltan`).
     *
     * @param  mixed  $respuesta
     * @return string
     */
    protected static function motivo_de($respuesta): string
    {
        if (is_array($respuesta) && !empty($respuesta['error'])) {

            return (string) $respuesta['error'];
        }

        if (is_array($respuesta) && !empty($respuesta['faltan']) && is_array($respuesta['faltan'])) {

            return 'falta saber ' . (string) $respuesta['faltan'][0];
        }

        return 'no se encontró';
    }

    /**
     * La presentación de la tarjeta con dos renglones más: lo que se interpretó y lo que quedó afuera. Así la
     * persona lo ve en la tarjeta (o en el texto por WhatsApp) antes de confirmar.
     *
     * @param  array  $presentacion
     * @param  array  $interpretacion
     * @return array
     */
    protected static function con_lo_interpretado(array $presentacion, array $interpretacion): array
    {
        $renglones = isset($presentacion['renglones']) && is_array($presentacion['renglones']) ? $presentacion['renglones'] : [];

        if (count($interpretacion['interpretados'])) {

            $partes = [];

            foreach ($interpretacion['interpretados'] as $uno) {

                $partes[] = '«' . $uno['pedido'] . '» → ' . $uno['elegido'];
            }

            $renglones[] = ['etiqueta' => 'Interpretados (revisar)', 'valor' => implode('; ', $partes)];
        }

        if (count($interpretacion['no_encontrados'])) {

            $partes = [];

            foreach ($interpretacion['no_encontrados'] as $no) {

                $partes[] = '«' . $no['pedido'] . '»';
            }

            $renglones[] = ['etiqueta' => 'No encontrados (afuera)', 'valor' => implode('; ', $partes)];
        }

        $presentacion['renglones'] = $renglones;

        return $presentacion;
    }

    /**
     * Lo que viaja en la respuesta de la herramienta para que el modelo lo cuente.
     *
     * @param  array  $interpretacion
     * @return array
     */
    protected static function extra_de_lo_interpretado(array $interpretacion): array
    {
        return [
            'interpretados'  => $interpretacion['interpretados'],
            'no_encontrados' => $interpretacion['no_encontrados'],
            'nota_de_lo_interpretado' => 'Armaste el presupuesto con lo que encontraste, como pidió la persona. Contale en el mismo mensaje: '
                . 'cuántos renglones entraron, los interpretados (lo que pidió → lo que pusiste) para que los revise, y los no '
                . 'encontrados por su texto tal cual, que quedaron afuera. No le vuelvas a pedir los códigos: si quiere, los corrige él.',
        ];
    }

    /**
     * El último presupuesto que esta conversación ya CREÓ para el mismo cliente y que todavía existe,
     * o null (misión asistente-truvari-pedidos-fallidos, 13/11/2026, B7).
     *
     * 🔴 EL CASO REAL. Fernando pidió corregir las cantidades del presupuesto N° 25 y el asistente le
     * dijo «Corrijo el presupuesto 25»: la tarjeta creó el N° 26 y el 25 quedó vivo. Esta herramienta
     * no edita: crea. Se mira por CLIENTE y no por la clave de la tarjeta (artículos + cliente) a
     * propósito: una corrección que además suma o saca un artículo cambia la clave y es el mismo
     * malentendido. Que la tarjeta avise «el N° X sigue existiendo» es verdad en los dos casos.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\Client  $cliente
     * @return array{numero: int, presupuesto_id: int}|null
     */
    protected static function presupuesto_anterior(ContextoDeCargaIa $contexto, $cliente)
    {
        $creadas = AiMessageAction::where('ai_conversation_id', $contexto->conversation->id)
            ->where('tipo', AiMessageAction::TIPO_PRESUPUESTO)
            ->where('estado', AiMessageAction::ESTADO_CONFIRMADA)
            ->orderBy('id', 'DESC')
            ->get();

        foreach ($creadas as $creada) {

            $datos = is_array($creada->datos) ? $creada->datos : [];

            $client_id = isset($datos['payload']['client_id']) ? (int) $datos['payload']['client_id'] : 0;

            $resultado = $creada->resultado;

            $presupuesto_id = is_object($resultado) && isset($resultado->presupuesto_id) ? (int) $resultado->presupuesto_id : 0;

            if ($client_id !== (int) $cliente->id || $presupuesto_id <= 0) {
                continue;
            }

            $budget = Budget::where('user_id', $contexto->owner_id)->where('id', $presupuesto_id)->first(['id', 'num']);

            if (is_null($budget)) {
                continue;
            }

            return ['numero' => (int) $budget->num, 'presupuesto_id' => (int) $budget->id];
        }

        return null;
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
     *   - `aplicar_descuentos_directo_a_items` en 0 (misión descuentos-aplicar-al-precio-del-articulo,
     *     8/10/2026): el asistente SÍ puede agregar un descuento de venta, pero lo arma al pie —los
     *     renglones van con el precio de lista—, y con el flag prendido `getTotal()` dejaría de
     *     restarlo y el total del presupuesto no cerraría.
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
            'aplicar_descuentos_directo_a_items' => 0,
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
