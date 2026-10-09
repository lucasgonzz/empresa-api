<?php

namespace App\Http\Controllers\Helpers\Devoluciones;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\currentAcount\CuentaCorrienteLock;
use App\Http\Controllers\Helpers\providerOrder\NewProviderOrderHelper;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Article;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Provider;
use App\Models\ProviderOrder;
use App\Models\StockMovement;
use Database\Seeders\ConceptoStockMovementNotaCreditoProveedorSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Devolución de COMPRA: la nota de crédito a proveedor que nace en el módulo de Devoluciones
 * (misión devoluciones-compras-y-rediseno, 1/10/2026). Es la devolución de venta "a la inversa":
 *
 *  - el stock SALE (lo que se le devuelve al proveedor), con el concepto "Nota de credito
 *    proveedor", atado a la compra y a la NC;
 *  - con la opción de cuenta corriente, queda un HABER en la cuenta del proveedor (baja lo que se le
 *    debe), en la moneda de la compra e imputado al débito de esa compra.
 *
 * El camino de venta (DevolucionesController::store) no se toca: el controlador bifurca al
 * principio por `tipo == 'compra'` y todo lo de compra vive acá.
 *
 * 🔴 Toda NC a proveedor nace con `provider_id` cargado, también la que NO va a cuenta corriente.
 * Es el discriminador con el que los reportes de "devoluciones" (que miden devoluciones de VENTA)
 * las dejan afuera: ContabilidadRepository, CajaReportsHelper, PerformanceHelper y RecolectorDia
 * filtran `provider_id IS NULL`. Una NC a proveedor sin `provider_id` contaría como una devolución
 * de un cliente y, peor, su costo restaría del costo de mercadería vendida.
 */
class NotaCreditoProveedorHelper {

    /**
     * Concepto de stock del movimiento que saca lo devuelto. Ver ValidarDevolucionCompraHelper.
     */
    const CONCEPTO = ValidarDevolucionCompraHelper::CONCEPTO;

    /**
     * Registra la devolución de compra. Mismo esqueleto que el camino de venta: validación sin
     * candado antes de la transacción, candados al abrirla, re-validación con candado, y los mismos
     * `catch` (422 para la devolución excedida, `report($e)` + rollback + 500 para el resto).
     *
     * @param  \Illuminate\Http\Request  $request  Contrato en el plan de la misión, §4.1.
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    static function store($request) {

        $items              = Self::normalizar_items($request->items);
        $provider_id        = Self::id_o_null($request->provider_id);
        $provider_order_id  = Self::id_o_null($request->provider_order_id);

        /*
            Datos inconsistentes (sin proveedor, una compra de otro proveedor, sin depósito de
            salida) responden 422 con el motivo y no escriben nada. Antes que la transacción: no
            leen nada que necesite candado.
        */
        $motivo = Self::motivo_de_datos_invalidos($request, $provider_id, $provider_order_id, $items);

        if (!is_null($motivo)) {
            return response()->json(['message' => $motivo], 422);
        }

        /*
            🔴 Tope sin candado: frena el REINTENTO secuencial (la segunda NC por lo mismo, minutos
            después). El doble clic simultáneo lo frena la re-validación con candado de adentro.
            Ver ValidarDevolucionCompraHelper.
        */
        $motivo = ValidarDevolucionCompraHelper::motivo_por_el_que_no_se_puede_devolver($provider_order_id, $items);

        if (!is_null($motivo)) {
            return response()->json(['message' => $motivo, 'devolucion_excedida' => true], 422);
        }

        DB::beginTransaction();

        try {

            /*
                🔴 Primero el candado de la COMPRA y enseguida el de la CUENTA CORRIENTE del
                proveedor, antes de cualquier lectura común. Es el mismo orden que la edición de
                compra (ProviderOrderController::update: compra, después cuenta), así dos requests
                que se crucen no se esperan en círculo. La NC entra a la cadena de saldos del
                proveedor y se imputa contra el débito de la compra: sin el candado de la cuenta
                podía intercalarse con otra escritura sobre la misma cuenta (la carrera de Fenix,
                ver CuentaCorrienteLock).
            */
            if (!is_null($provider_order_id)) {
                ProviderOrder::where('id', $provider_order_id)->lockForUpdate()->first(['id']);
            }

            if ($request->generar_current_acount) {
                CuentaCorrienteLock::bloquear('provider', $provider_id);
            }

            /*
                Re-validación CON candado: el segundo request de un doble clic espera al primero en
                el candado de la compra y recién entonces cuenta lo ya devuelto, con lecturas FOR
                UPDATE que ven lo último commiteado. Si no cierra, la excepción propia cae en su
                catch y responde 422 con el motivo.
            */
            if (!is_null($provider_order_id)) {
                ValidarDevolucionCompraHelper::exigir($provider_order_id, $items);
            }

            $provider_order = is_null($provider_order_id) ? null : ProviderOrder::find($provider_order_id);

            $nota_credito = Self::crear_nota_credito($request, $provider_id, $provider_order, $items);

            if ($request->regresar_stock) {
                Self::sacar_stock($request, $items, $nota_credito, $provider_id, $provider_order);
            }

            DB::commit();

            return response(null, 201);

        } catch (DevolucionExcedidaException $e) {

            DB::rollBack();

            Log::info('Devolucion de compra rechazada: '.$e->getMessage());

            return response()->json(['message' => $e->getMessage(), 'devolucion_excedida' => true], 422);

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::info('Error en nota de credito a proveedor');

            // Capturada para poder hacer rollback y responder 500: sin report() no llega al
            // reporter de errores (el handler global solo ve las excepciones NO manejadas). Mismo
            // criterio que el camino de venta.
            report($e);

            return response(null, 500);
        }
    }

    /**
     * Crea la nota de crédito, con o sin cuenta corriente, y la deja atada al proveedor y a la
     * compra.
     *
     * @param  \Illuminate\Http\Request          $request
     * @param  int                               $provider_id
     * @param  \App\Models\ProviderOrder|null    $provider_order
     * @param  array                             $items
     * @return \App\Models\CurrentAcount
     */
    static function crear_nota_credito($request, $provider_id, $provider_order, $items) {

        $descripcion = Self::descripcion($request, $provider_order);

        $descriptions = is_array($request->descriptions) ? $request->descriptions : null;

        if ($request->generar_current_acount) {

            /*
                La cuenta del proveedor en la MONEDA DE LA COMPRA (una compra en dólares le debe
                dólares al proveedor, y su devolución le baja dólares). Sin compra, pesos: lo mismo
                que hace la devolución de venta sin venta de origen.
            */
            $moneda_id = Self::moneda_id($provider_order);

            $credit_account = Self::credit_account_del_proveedor($provider_id, $moneda_id);

            $to_pay_id = Self::debito_de_la_compra($provider_order, $credit_account->id);

            $nota_credito = CurrentAcountHelper::notaCredito(
                $credit_account->id,
                $request->total_devolucion,
                $descripcion,
                'provider',
                $provider_id,
                null,
                $items,
                $descriptions,
                $to_pay_id
            );

        } else {

            /*
                🔴 Sin cuenta corriente se crea igual que una devolución de venta SIN cliente (sin
                dueño ni cuenta), y RECIÉN DESPUÉS se le asigna el proveedor. No se pasa
                'provider' con la cuenta en null: notaCredito() correría el motor de pagos y el
                recálculo de saldos sobre una cuenta inexistente. Y el proveedor no es opcional:
                es el discriminador de los reportes (ver el docblock de la clase).

                La moneda también se corrige acá: sin cuenta, notaCredito() la deja en pesos, y una
                devolución de una compra en dólares es por dólares.
            */
            $nota_credito = CurrentAcountHelper::notaCredito(
                null,
                $request->total_devolucion,
                $descripcion,
                null,
                null,
                null,
                $items,
                $descriptions
            );

            $nota_credito->provider_id = $provider_id;

            if (!is_null($provider_order)) {
                $nota_credito->moneda_id = Self::moneda_id($provider_order);
            }
        }

        if (!is_null($provider_order)) {
            $nota_credito->devolucion_provider_order_id = $provider_order->id;
        }

        $nota_credito->save();

        return $nota_credito;
    }

    /**
     * Saca del stock lo devuelto: un movimiento NEGATIVO por artículo con el concepto "Nota de
     * credito proveedor".
     *
     * 🔴 Con StockMovementController::crear() y NUNCA con store(): store() arma el movimiento con
     * un subconjunto fijo del request y se pierden `provider_order_id` y `nota_credito_id` (la clase
     * "el store() que lee un subconjunto del request" de APRENDER_NO_PARCHEAR.md). Sin
     * `provider_order_id` el tope por libro no ve lo devuelto y la segunda NC pasa.
     *
     * @param  \Illuminate\Http\Request          $request
     * @param  array                             $items
     * @param  \App\Models\CurrentAcount         $nota_credito
     * @param  int                               $provider_id
     * @param  \App\Models\ProviderOrder|null    $provider_order
     * @return void
     */
    static function sacar_stock($request, $items, $nota_credito, $provider_id, $provider_order) {

        Self::asegurar_concepto();

        $ct = new StockMovementController();

        $address_id = Self::address_id_de_salida($request, $provider_order);

        foreach ($items as $item) {

            if (!isset($item['is_article']) || !isset($item['id'])) {
                continue;
            }

            $unidades = ValidarDevolucionHelper::unidades_del_item($item);

            if ($unidades <= 0) {
                continue;
            }

            /*
                Se lee el artículo de la base y no el `stock` que manda la pantalla: lo que decide si
                el artículo lleva stock es la base, y un renglón armado desde la compra puede no
                traer ese campo.
            */
            $article = Article::find($item['id']);

            if (is_null($article) || is_null($article->stock)) {
                continue;
            }

            /*
                Con compra, sale como mucho lo que la compra ingresó y no se devolvió (gemelo de
                unidades_a_reponer de la venta). Sin compra (NC libre) sale lo que cargó el usuario.
            */
            if (!is_null($provider_order)) {

                $articulo_de_la_compra = ValidarDevolucionCompraHelper::articulo_de_la_compra($provider_order, $article->id);

                if (!is_null($articulo_de_la_compra)) {
                    $unidades = ValidarDevolucionCompraHelper::unidades_a_sacar($provider_order, $articulo_de_la_compra, $unidades);
                }
            }

            if ($unidades <= 0) {
                continue;
            }

            $data = [
                'model_id'                      => $article->id,
                // Negativo: sale del stock. check_unidades_individuales() lo pasa a unidades si el
                // artículo se compra por bulto (la NC se carga en la unidad de la compra).
                'amount'                        => -$unidades,
                'concepto_stock_movement_name'  => Self::CONCEPTO,
                'nota_credito_id'               => $nota_credito->id,
                'provider_id'                   => $provider_id,
                // 🔴 Sin esto SetProvider le cambia el proveedor al artículo (un movimiento con
                // proveedor "re-asigna" el artículo a ese proveedor). Devolverle algo a un
                // proveedor no cambia a quién se le compra.
                'not_save_provider'             => true,
            ];

            if (!is_null($provider_order)) {
                $data['provider_order_id'] = $provider_order->id;
            }

            if (isset($item['article_variant_id']) && $item['article_variant_id']) {
                $data['article_variant_id'] = $item['article_variant_id'];
            }

            // Depósito del que SALE, sólo si el artículo reparte su stock por depósitos (si no, el
            // movimiento va al stock global, igual que la devolución de venta).
            if (count($article->addresses) >= 1 && !is_null($address_id)) {
                $data['from_address_id'] = $address_id;
            }

            $stock_movement = $ct->crear($data);

            /*
                crear() sólo guarda `provider_id` en los INGRESOS (get_provider_id() lo descarta si
                el monto es menor a 1), porque en un ingreso el proveedor es el que "trae" el stock.
                Acá se guarda después a propósito, para que el libro diga a qué proveedor se le
                devolvió: no pasa por SetProvider (que ya corrió con el proveedor vacío), así que no
                toca ni el proveedor del artículo ni su relación con los proveedores.
            */
            if (!is_null($stock_movement)) {
                $stock_movement->provider_id = $provider_id;
                $stock_movement->save();
            }
        }
    }

    /**
     * Al ELIMINAR una nota de crédito a proveedor desde la cuenta corriente (la llama
     * NotaCreditoHelper::resetUnidadesDevueltas): vuelve a meter en el stock exactamente lo que
     * esa NC había sacado. Gemelo del camino de venta, que al borrar la NC saca lo que había
     * repuesto.
     *
     * Por cada movimiento NEGATIVO de la NC (concepto "Nota de credito proveedor", con su
     * `nota_credito_id`) se crea el inverso:
     *
     *  - 🔴 con el MISMO concepto y monto positivo, a propósito: el libro de la compra queda
     *    neteado en 0 para ese concepto, que es justo lo que leen unidades_ya_sacadas() (el tope
     *    de lo que puede salir en la próxima devolución) y el borrado de la compra
     *    (ProviderOrderHelper::resetArticlesStock). Un concepto aparte ("Eliminacion ...")
     *    obligaría a sumarlo en esos dos lugares, y el día que alguien se olvide de uno el stock
     *    se descuadra. Queda rastreable igual: mismo `nota_credito_id`, monto positivo y la
     *    observación con el número de la NC eliminada.
     *  - 🔴 con `sin_unidades_individuales`: el movimiento original YA quedó en unidades (la NC se
     *    cargó en bultos y check_unidades_individuales() la multiplicó). Volver a pasarlo por la
     *    conversión metería 144 unidades por un bulto de 12.
     *  - al depósito del que había salido (`to_address_id` = el `from_address_id` original).
     *
     * Lo que sigue contando como "ya devuelto" para el tope son los renglones de las NC vivas: al
     * borrarse la NC desaparece de ahí sola (ver ValidarDevolucionCompraHelper).
     *
     * @param  \App\Models\CurrentAcount  $nota_credito
     * @return void
     */
    static function deshacer_stock($nota_credito) {

        $concepto = ValidarDevolucionCompraHelper::concepto();

        // Sin el concepto no puede haber movimientos de esta NC con él: nada que deshacer.
        if (is_null($concepto)) {
            return;
        }

        $movimientos = StockMovement::where('nota_credito_id', $nota_credito->id)
                                    ->where('concepto_stock_movement_id', $concepto->id)
                                    ->where('amount', '<', 0)
                                    ->get();

        $ct = new StockMovementController();

        foreach ($movimientos as $movimiento) {

            $data = [
                'model_id'                      => $movimiento->article_id,
                'amount'                        => -(float)$movimiento->amount,
                'sin_unidades_individuales'     => true,
                'concepto_stock_movement_name'  => Self::CONCEPTO,
                'nota_credito_id'               => $nota_credito->id,
                'not_save_provider'             => true,
                'observations'                  => 'Eliminacion Nota C. proveedor N° '.$nota_credito->num_receipt,
            ];

            if (!is_null($movimiento->provider_order_id)) {
                $data['provider_order_id'] = $movimiento->provider_order_id;
            }

            if (!is_null($movimiento->article_variant_id) && $movimiento->article_variant_id != 0) {
                $data['article_variant_id'] = $movimiento->article_variant_id;
            }

            if (!is_null($movimiento->from_address_id) && $movimiento->from_address_id != 0) {
                $data['to_address_id'] = $movimiento->from_address_id;
            }

            $reverso = $ct->crear($data);

            // Mismo criterio que sacar_stock(): el proveedor se graba después de crear(), para que
            // no pase por SetProvider (que con monto positivo tocaría la relación
            // artículo-proveedor como si fuera una compra).
            if (!is_null($reverso)) {
                $reverso->provider_id = $movimiento->provider_id;
                $reverso->save();
            }
        }
    }

    /**
     * Motivo por el que una compra no se puede borrar, o null: tiene notas de crédito a proveedor
     * vivas (ver ProviderOrderController::destroy).
     *
     * @param  int  $provider_order_id
     * @return string|null
     */
    static function motivo_por_el_que_no_se_puede_borrar_la_compra($provider_order_id) {

        /*
            Solo frenan las NC que fueron a CUENTA CORRIENTE (decisión del orquestador,
            1/10/2026): esas tienen un haber imputado al débito de la compra, que el borrado se
            lleva. Una NC sin C/C no tiene plata en ninguna cuenta, y su stock ya lo cubre la resta
            por libro de ProviderOrderHelper::resetArticlesStock() (saca lo ingresado MENOS lo que
            esa NC ya sacó). Además no hay forma de borrarla desde la pantalla: si frenara, la
            compra quedaría imposible de borrar.
        */
        $numeros = CurrentAcount::where('devolucion_provider_order_id', $provider_order_id)
                                ->where('status', 'nota_credito')
                                ->whereNotNull('credit_account_id')
                                ->orderBy('id')
                                ->pluck('num_receipt')
                                ->all();

        if (count($numeros) == 0) {
            return null;
        }

        return 'Esta compra tiene notas de crédito a proveedor (N° '.implode(', ', $numeros).'). Eliminalas primero desde la cuenta corriente del proveedor.';
    }

    /**
     * 🔴 Guarda del concepto "Nota de credito proveedor": si la base no lo tiene (el seeder del
     * despliegue no corrió, o es una base vieja restaurada), se crea en el momento, ANTES de
     * escribir el primer movimiento. Sin el concepto, SetConcepto deja el movimiento sin etiqueta
     * (no lanza error), y eso rompe dos cosas en silencio: check_unidades_individuales() no
     * multiplica los bultos (devolver 1 caja de 12 sacaba 1 unidad) y el libro no ve lo que salió
     * (el tope de stock y el borrado de la compra lo cuentan mal).
     *
     * Reusa el seeder standalone, que es idempotente por nombre: si ya existe no hace nada.
     *
     * @return void
     */
    static function asegurar_concepto() {

        if (!is_null(ValidarDevolucionCompraHelper::concepto())) {
            return;
        }

        Log::warning('NotaCreditoProveedorHelper: faltaba el concepto "'.Self::CONCEPTO.'" en concepto_stock_movements; se crea ahora (¿no corrió ConceptoStockMovementNotaCreditoProveedorSeeder en el despliegue?).');

        (new ConceptoStockMovementNotaCreditoProveedorSeeder())->run();
    }

    /**
     * Agrega a cada artículo de la compra lo que la pantalla necesita para armar la devolución
     * (contrato del plan, §4.1): `cantidad_efectiva`, `ya_devueltas` y `costo_unitario_devolucion`.
     *
     * 🔴 CRITERIO DEL COSTO. Se le devuelve al proveedor lo que la compra le cobró por esa unidad,
     * con el MISMO armado con el que la compra calculó su total, reusando NewProviderOrderHelper:
     *
     *  1. get_total_article(): costo del renglón × cantidad efectiva (recibida si se completó, si no
     *     la pedida) × presentación, en pesos al dólar si el costo es en dólares y la compra en
     *     pesos, MENOS el descuento propio del renglón.
     *  2. Las bonificaciones de la compra (`descuentos_compra`) se prorratean por el peso BRUTO del
     *     renglón en el subtotal, que es exactamente como las reparte la factura automática
     *     (ModoFacturacionHelper::get_ivas). Se usan los totales que set_totales() dejó guardados en
     *     la compra (`sub_total`, `descuentos_compra`) y no se recalculan: set_totales() escribe la
     *     compra y esto corre en un GET.
     *  3. IVA, solo si la compra EFECTIVAMENTE lo cobró por encima de su total:
     *     NewProviderOrderHelper::cobro_iva_por_encima(), que usa la misma cuenta de set_totales()
     *     (iva_sumado_al_total()). Se suma con la alícuota del renglón que resuelve
     *     get_total_article() (vacía si el usuario carga con IVA incluido). 🔴 No alcanza con
     *     `total_with_iva`: la SPA lo manda en 1 en TODA compra, y el IVA de la compra sale siempre
     *     de sus comprobantes (misión `devolucion-proveedor-iva-sin-factura`, 9/10/2026: una compra
     *     sin factura de 10 × $2.444 acreditaba $5.914,48 al devolver 2, en vez de $4.888). Por
     *     modo de facturación:
     *       - automático: suma, y cuadra exacto (la factura se calcula con el mismo armado por
     *         renglón que este costo).
     *       - sin factura: no suma (la compra no tiene comprobantes, no cobró IVA).
     *       - manual: suma con la alícuota de cada renglón si las facturas cargadas traen IVA, y no
     *         suma si no traen (Factura C/B sin desglose, o ninguna factura cargada todavía).
     *       - compra legada (`total_iva` NULL, de antes del 2/10/2024): suma si tiene
     *         `total_with_iva`, como la sumaba el ProviderOrderHelper::getTotal() de entonces.
     *     Con `precios_incluyen_iva` el costo ya lo trae y un Monotributista no lo suma: las dos
     *     patas viven en suma_iva_al_total().
     *  4. Dividido por la cantidad efectiva: costo por unidad DE LA COMPRA (bultos).
     *
     * Lo que NO entra: los costos extra (flete, seguro): no se le devuelven al proveedor con la
     * mercadería. Y tres límites conocidos que la pantalla salva porque el costo es editable: el
     * dólar es el de HOY (get_total_article() lee la cotización actual, la de la compra no queda
     * guardada por renglón); en una compra con el total tomado de las facturas
     * (`total_from_provider_order_afip_tickets`) el costo sale de los renglones, no de la factura;
     * y en modo manual, si el IVA de la factura cargada no coincide con el de los renglones, el IVA
     * del costo sale de los renglones. No se prorratea el `total_iva` de la compra a propósito: en
     * manual puede incluir el IVA del flete, que no se le devuelve al proveedor.
     *
     * @param  \App\Models\ProviderOrder  $provider_order  Con `articles` cargados (withAll).
     * @return \App\Models\ProviderOrder
     */
    static function agregar_datos_de_devolucion($provider_order) {

        $helper = new NewProviderOrderHelper($provider_order, []);

        // Solo si la compra cobró IVA de verdad (ver el punto 3 del docblock): una compra sin
        // factura, o manual sin IVA cargado, no lo cobró aunque traiga `total_with_iva`. La regla
        // (incluido el caso de las compras legadas) vive en NewProviderOrderHelper.
        $suma_iva = $helper->cobro_iva_por_encima();

        $sub_total_compra   = (float)$provider_order->sub_total;
        $descuentos_compra  = (float)$provider_order->descuentos_compra;

        // Qué parte del bruto se llevaron las bonificaciones de la compra (0 si no hubo).
        $proporcion_bonificacion = $sub_total_compra > 0 ? $descuentos_compra / $sub_total_compra : 0;

        foreach ($provider_order->articles as $article) {

            $cantidad_efectiva = ValidarDevolucionCompraHelper::cantidad_efectiva($article);

            $res = $helper->get_total_article($article);

            $costo_del_renglon = (float)$res['total_article'] - (float)$res['sub_total_article'] * $proporcion_bonificacion;

            if ($suma_iva) {
                $costo_del_renglon *= 1 + Self::alicuota($helper, $res['article_iva']['iva_id']) / 100;
            }

            $costo_unitario = $cantidad_efectiva > 0 ? $costo_del_renglon / $cantidad_efectiva : 0;

            $article->cantidad_efectiva          = $cantidad_efectiva;
            $article->ya_devueltas               = round(ValidarDevolucionCompraHelper::unidades_ya_devueltas($provider_order, $article), 4);
            $article->costo_unitario_devolucion  = round($costo_unitario, 2);
        }

        return $provider_order;
    }

    /**
     * Alícuota numérica de un IVA (0 si no hay, o si es 'Exento'/'No Gravado', que se guardan como
     * texto). Mismo cuidado que NewProviderOrderHelper::get_alicuota_numerica(), que es privado.
     *
     * @param  \App\Http\Controllers\Helpers\providerOrder\NewProviderOrderHelper  $helper
     * @param  int|null                                                            $iva_id
     * @return float
     */
    static function alicuota($helper, $iva_id) {

        if (is_null($iva_id) || (int)$iva_id <= 0) {
            return 0;
        }

        $iva = $helper->get_iva($iva_id);

        if (is_null($iva) || !isset($iva->percentage) || !is_numeric($iva->percentage)) {
            return 0;
        }

        return (float)$iva->percentage;
    }

    /**
     * Motivo por el que la devolución no se puede registrar con los datos que llegaron, o null.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int|null                  $provider_id
     * @param  int|null                  $provider_order_id
     * @param  array                     $items
     * @return string|null
     */
    static function motivo_de_datos_invalidos($request, $provider_id, $provider_order_id, $items) {

        if (is_null($provider_id)) {
            return 'Elegí el proveedor al que se le hace la devolución.';
        }

        $provider_existe = Provider::where('id', $provider_id)
                                    ->where('user_id', UserHelper::userId())
                                    ->exists();

        if (!$provider_existe) {
            return 'El proveedor elegido no existe.';
        }

        $provider_order = null;

        if (!is_null($provider_order_id)) {

            $provider_order = ProviderOrder::where('id', $provider_order_id)
                                            ->where('user_id', UserHelper::userId())
                                            ->first();

            if (is_null($provider_order)) {
                return 'La compra elegida no existe.';
            }

            // Una NC atada a una compra de OTRO proveedor bajaría la cuenta del proveedor
            // equivocado y sacaría stock contra una compra ajena.
            if ((int)$provider_order->provider_id != (int)$provider_id) {
                return 'El proveedor elegido no es el de la compra N° '.$provider_order->num.'.';
            }
        }

        /*
            Un artículo que reparte su stock por depósitos necesita saber de qué depósito sale. Sin
            esto el movimiento quedaba en el libro sin descontar de ningún lado (CheckGlobalStock no
            actúa sobre artículos con depósitos), o sea el libro diría que salió algo que sigue ahí.
        */
        if ($request->regresar_stock && is_null(Self::address_id_de_salida($request, $provider_order))) {

            foreach ($items as $item) {

                if (!isset($item['is_article']) || !isset($item['id']) || ValidarDevolucionHelper::unidades_del_item($item) <= 0) {
                    continue;
                }

                $article = Article::find($item['id']);

                if (!is_null($article) && !is_null($article->stock) && count($article->addresses) >= 1) {
                    return 'Elegí el depósito del que sale la mercadería devuelta.';
                }
            }
        }

        return null;
    }

    /**
     * Depósito del que sale lo devuelto: el que eligió el usuario, o el de la compra.
     *
     * @param  \Illuminate\Http\Request          $request
     * @param  \App\Models\ProviderOrder|null    $provider_order
     * @return int|null
     */
    static function address_id_de_salida($request, $provider_order) {

        $address_id = Self::id_o_null($request->address_id);

        if (is_null($address_id) && !is_null($provider_order)) {
            $address_id = Self::id_o_null($provider_order->address_id);
        }

        return $address_id;
    }

    /**
     * Cuenta corriente del proveedor en esa moneda. Si no existe (proveedor viejo que nunca operó en
     * esa moneda) se crea con CreditAccountHelper::crear_credit_accounts(), que es el ÚNICO lugar
     * donde nacen las credit_account y es idempotente: no se crea a mano acá.
     *
     * @param  int  $provider_id
     * @param  int  $moneda_id
     * @return \App\Models\CreditAccount
     */
    static function credit_account_del_proveedor($provider_id, $moneda_id) {

        $credit_account = Self::buscar_credit_account($provider_id, $moneda_id);

        if (is_null($credit_account)) {

            CreditAccountHelper::crear_credit_accounts('provider', $provider_id);

            $credit_account = Self::buscar_credit_account($provider_id, $moneda_id);
        }

        return $credit_account;
    }

    /**
     * @param  int  $provider_id
     * @param  int  $moneda_id
     * @return \App\Models\CreditAccount|null
     */
    static function buscar_credit_account($provider_id, $moneda_id) {

        return CreditAccount::where('model_name', 'provider')
                            ->where('model_id', $provider_id)
                            ->where('moneda_id', $moneda_id)
                            ->first();
    }

    /**
     * El débito de la compra en esa cuenta, si todavía no está saldado: es a donde se dirige la
     * imputación de la NC (igual que la NC de venta se imputa a su venta y no a la deuda más vieja).
     * Si ya está saldado, o la compra no fue a cuenta corriente, la NC entra a la cola FIFO de
     * siempre.
     *
     * @param  \App\Models\ProviderOrder|null  $provider_order
     * @param  int                             $credit_account_id
     * @return int|null
     */
    static function debito_de_la_compra($provider_order, $credit_account_id) {

        if (is_null($provider_order)) {
            return null;
        }

        $debito = CurrentAcount::where('provider_order_id', $provider_order->id)
                                ->whereNotNull('debe')
                                ->where('credit_account_id', $credit_account_id)
                                ->whereIn('status', ['sin_pagar', 'pagandose'])
                                ->first();

        return is_null($debito) ? null : $debito->id;
    }

    /**
     * @param  \App\Models\ProviderOrder|null  $provider_order
     * @return int
     */
    static function moneda_id($provider_order) {

        if (!is_null($provider_order) && !is_null($provider_order->moneda_id) && (int)$provider_order->moneda_id != 0) {
            return (int)$provider_order->moneda_id;
        }

        return 1;
    }

    /**
     * Descripción de la NC: la observación que escribió el usuario, o "Devolución de compra N° X".
     *
     * @param  \Illuminate\Http\Request          $request
     * @param  \App\Models\ProviderOrder|null    $provider_order
     * @return string|null
     */
    static function descripcion($request, $provider_order) {

        if (!is_null($request->observaciones) && trim($request->observaciones) !== '') {
            return $request->observaciones;
        }

        if (!is_null($provider_order)) {

            $num = !is_null($provider_order->num) && $provider_order->num !== '' ? $provider_order->num : $provider_order->id;

            return 'Devolución de compra N° '.$num;
        }

        return null;
    }

    /**
     * Deja los renglones en la forma que esperan CurrentAcountHelper::attachNotaCreditoArticles() y
     * los helpers de stock, tolerando lo que la pantalla de compra no manda.
     *
     * 🔴 Se saca el `pivot` del renglón a propósito. Un renglón armado desde la compra trae el pivot
     * DE LA COMPRA, y attachNotaCreditoArticles() toma `pivot.cost` como costo de la NC cuando es
     * mayor a 0 (en la venta es el costo de la línea vendida, que es lo correcto). Acá ese `cost` es
     * el costo bruto tipeado en la compra —sin descuentos, sin IVA y quizás en dólares—: la NC
     * quedaría con un costo distinto del que se le devolvió al proveedor. El costo de la NC a
     * proveedor es `costo_real` (= costo_unitario_devolucion).
     *
     * @param  mixed  $items
     * @return array
     */
    static function normalizar_items($items) {

        if (!is_array($items)) {
            return [];
        }

        $normalizados = [];

        foreach ($items as $item) {

            if (!is_array($item)) {
                continue;
            }

            unset($item['pivot']);

            if (!isset($item['price_vender']) || $item['price_vender'] === '') {
                $item['price_vender'] = 0;
            }

            if (!isset($item['costo_real']) || $item['costo_real'] === '') {
                $item['costo_real'] = $item['price_vender'];
            }

            if (!isset($item['discount']) || $item['discount'] === '') {
                $item['discount'] = 0;
            }

            $normalizados[] = $item;
        }

        return $normalizados;
    }

    /**
     * @param  mixed  $valor
     * @return int|null
     */
    static function id_o_null($valor) {

        if (is_null($valor) || $valor === '' || (int)$valor == 0) {
            return null;
        }

        return (int)$valor;
    }
}
