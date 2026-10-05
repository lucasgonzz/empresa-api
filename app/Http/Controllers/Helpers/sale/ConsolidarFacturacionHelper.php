<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\Afip\MakeAfipTicket;
use App\Http\Controllers\Helpers\currentAcount\CuentaCorrienteLock;
use App\Models\Sale;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Helper que gestiona la consolidación de varias ventas individuales en una
 * única "venta contenedora" a efectos de emitir un solo comprobante AFIP.
 *
 * La venta consolidada resultante:
 *   - No descuenta stock (discount_stock = 0).
 *   - No genera cuenta corriente (omitir_en_cuenta_corriente = 1, save_current_acount = 0).
 *   - Queda marcada con is_consolidacion_facturacion = 1.
 *   - Es excluida de reportes de ventas reales via scopeSoloVentasReales.
 *
 * Las ventas originales reciben consolidacion_facturacion_id apuntando a la nueva venta.
 */
class ConsolidarFacturacionHelper extends Controller
{

    /**
     * Valida que las ventas indicadas puedan consolidarse.
     * Lanza una Exception si alguna condición no se cumple.
     *
     * @param array $sale_ids       IDs de ventas a consolidar.
     * @param int   $client_id      Cliente esperado para todas las ventas.
     * @param int   $user_id        Usuario autenticado; todas las ventas deben pertenecer a él.
     * @throws Exception
     */
    public static function validar(array $sale_ids, int $client_id, int $user_id): void
    {
        if (empty($sale_ids)) {
            throw new Exception('Debe seleccionar al menos una venta para consolidar.');
        }

        /** Carga las ventas a validar con relaciones mínimas necesarias. */
        $sales = Sale::whereIn('id', $sale_ids)
                     ->with('afip_tickets')
                     ->get();

        if ($sales->count() !== count($sale_ids)) {
            throw new Exception('Una o más ventas indicadas no existen.');
        }

        foreach ($sales as $sale) {

            /** Todas las ventas deben pertenecer al usuario autenticado. */
            if ($sale->user_id != $user_id) {
                throw new Exception("La venta #{$sale->num} no pertenece al usuario actual.");
            }

            /** Todas las ventas deben ser del mismo cliente. */
            if ($sale->client_id != $client_id) {
                throw new Exception("La venta #{$sale->num} no corresponde al cliente indicado.");
            }

            /** No se puede consolidar una venta que ya está dentro de otra consolidación. */
            if (!is_null($sale->consolidacion_facturacion_id)) {
                throw new Exception("La venta #{$sale->num} ya fue incluida en una consolidación anterior.");
            }

            /** No se puede consolidar una venta que ya es ella misma una contenedora de facturación. */
            if ($sale->is_consolidacion_facturacion) {
                throw new Exception("La venta #{$sale->num} es una consolidación y no puede volver a consolidarse.");
            }

            /** No se consolidan ventas que ya tienen un comprobante AFIP autorizado (con CAE). */
            $tiene_cae = $sale->afip_tickets->first(function ($t) {
                return !empty($t->cae);
            });
            if ($tiene_cae) {
                throw new Exception("La venta #{$sale->num} ya tiene un comprobante AFIP autorizado.");
            }
        }
    }

    /**
     * Crea la venta consolidada y opcionalmente emite el comprobante AFIP.
     *
     * @param array $sale_ids                   IDs de ventas originales a consolidar.
     * @param int   $client_id                  ID del cliente de todas las ventas.
     * @param int   $user_id                    ID del usuario autenticado.
     * @param int   $afip_information_id        Configuración AFIP a usar para la factura.
     * @param int   $afip_tipo_comprobante_id   Tipo de comprobante AFIP.
     * @param bool  $agrupar_items              Si true, agrupa ítems iguales sumando cantidades.
     * @param array $afip_data                  Datos extra para la factura AFIP (fecha, forma_de_pago, etc.).
     * @param bool  $emitir_afip                Si true, dispara el llamado a AFIP al terminar.
     * @return Sale La venta consolidada creada.
     * @throws Exception Si la validación falla o la transacción no puede completarse.
     */
    public static function consolidar(
        array $sale_ids,
        int   $client_id,
        int   $user_id,
        int   $afip_information_id,
        int   $afip_tipo_comprobante_id,
        bool  $agrupar_items  = false,
        array $afip_data      = [],
        bool  $emitir_afip    = true
    ): Sale {
        /** Validación previa a la transacción para fallar rápido con mensajes claros. */
        // self::validar($sale_ids, $client_id, $user_id);

        DB::beginTransaction();

        try {

            /*
             * Candado de la cuenta corriente del cliente como primera sentencia (misión
             * cuenta-corriente-carrera-y-velocidad, 23/9/2026). La consolidada no genera movimiento,
             * pero lee y marca las ventas del cliente y copia sus renglones: con el dueño tomado
             * primero, el orden es el mismo que en el resto de los caminos (dueño antes que ventas y
             * stock). Ver CuentaCorrienteLock.
             */
            CuentaCorrienteLock::bloquear('client', $client_id);

            /** Carga las ventas con sus artículos y datos de pivot para copiarlos. */
            $ventas_originales = Sale::whereIn('id', $sale_ids)
                                     ->with('articles', 'combos', 'services', 'discounts', 'surchages', 'client')
                                     ->get();

            /** Acumula los totales de las ventas originales para el campo total de la consolidada. */
            $total_consolidado  = $ventas_originales->sum('total');
            $sub_total_consolid = $ventas_originales->sum('sub_total');

            /**
             * El monto del total forzado de la consolidada es la SUMA de los montos de las ventas
             * originales (mision forzar-total-por-monto, 17/9/2026).
             *
             * ─────────────────────────────────────────────────────────────────────────────
             *  🔴 POR QUE ESTA LINEA NO ES OPCIONAL
             * ─────────────────────────────────────────────────────────────────────────────
             *
             *  `$total_consolidado` ya viene con los forzados adentro, porque suma `sales.total` de
             *  cada venta. Pero los renglones se copian con su `price` CRUDO. Sin el monto, la
             *  consolidada queda con `total` = 4.000 y renglones que suman 4.012: el factor de
             *  `AfipItemCalculator` da 1 y a un emisor Responsable Inscripto se le facturan los
             *  4.012 — 12 pesos mas de lo que el cliente pago, en un comprobante fiscal.
             *
             *  Es la misma clase de error que esta mision vino a cerrar: un camino que escribe
             *  `sales.total` sin escribir `forzar_total_monto`. Los dos campos se escriben juntos.
             *
             * El cero se guarda como null, igual que `SaleHelper::normalized_forzar_total_monto()`:
             * ninguna de las ventas forzo nada, y "no se forzo" es null, no 0.
             */
            $forzado_consolidado = (float) $ventas_originales->sum('forzar_total_monto');

            if ($forzado_consolidado == 0) {
                $forzado_consolidado = null;
            }

            /** Usa el sale_type_id de la primera venta como referencia; todas deben ser del mismo tipo. */
            $sale_type_id = $ventas_originales->first()->sale_type_id;

            /** Toma moneda y configuración de precio de la primera venta como referencia. */
            $moneda_id    = $ventas_originales->first()->moneda_id ?? 1;
            $valor_dolar  = $ventas_originales->first()->valor_dolar;
            $iva_aplicado = $ventas_originales->first()->iva_aplicado ?? 1;
            /** Check "Sumar IVA a los articulos sin IVA" de la primera venta (0 si no estaba o no hay columna). */
            $iva_en_articulos_sin_iva = !empty($ventas_originales->first()->iva_en_articulos_sin_iva) ? 1 : 0;

            Log::info("ConsolidarFacturacion: creando venta consolidada para client_id={$client_id}, user_id={$user_id}, ventas=" . implode(',', $sale_ids));

            /** Crea el correlativo usando la lógica estándar del sistema. */
            $num = (new self())->num('sales', $user_id);

            /** Crea la venta contenedora marcada para excluirla de reportes y cuentas. */
            $venta_consolidada = Sale::create(IvaEnArticulosSinIvaEsquemaHelper::quitar_si_no_hay_columna(ForzarTotalEsquemaHelper::agregar_al_payload([
                'num'                           => $num,
                'client_id'                     => $client_id,
                'user_id'                       => $user_id,
                'sale_type_id'                  => $sale_type_id,
                'afip_information_id'           => $afip_information_id,
                'afip_tipo_comprobante_id'      => $afip_tipo_comprobante_id,
                'total'                         => $total_consolidado,
                'sub_total'                     => $sub_total_consolid,
                'moneda_id'                     => $moneda_id,
                'valor_dolar'                   => $valor_dolar,
                'iva_aplicado'                  => $iva_aplicado,
                'iva_en_articulos_sin_iva'      => $iva_en_articulos_sin_iva,
                /** No descuenta stock: la venta consolidada no es una venta real de mercadería. */
                'discount_stock'                => 0,
                /** No genera cuenta corriente: el cobro ya está registrado en las ventas originales. */
                'omitir_en_cuenta_corriente'    => 1,
                'save_current_acount'           => 0,
                /** Marca clave que distingue esta venta contenedora de las ventas reales. */
                'is_consolidacion_facturacion'  => 1,
                /** La venta consolidada queda terminada desde su creación. */
                'terminada'                     => 1,
                'terminada_at'                  => Carbon::now(),
                'descuento'                     => 0,
            /**
             * Ver el bloque de arriba: va junto con `total`, o la factura sale por el bruto.
             *
             * ⚠️ Entra por la guarda de esquema, como los otros seis puntos de escritura: en la
             * ventana entre que el deploy sube los archivos y corre las migraciones, la clave
             * viajaria igual al INSERT —con `$guarded = []` Eloquent la manda aunque valga null— y
             * la consolidacion moriria con `Unknown column`. Ver `ForzarTotalEsquemaHelper`.
             */
            ], $forzado_consolidado, 'sales'), 'sales'));

            /** Copia los ítems de todas las ventas originales a la consolidada. */
            self::copiar_articulos($venta_consolidada, $ventas_originales, $agrupar_items);
            self::copiar_combos($venta_consolidada, $ventas_originales);
            self::copiar_services($venta_consolidada, $ventas_originales);

            /** Vincula cada venta original con la consolidada para trazabilidad. */
            Sale::whereIn('id', $sale_ids)->update([
                'consolidacion_facturacion_id' => $venta_consolidada->id,
            ]);

            Log::info("ConsolidarFacturacion: venta consolidada creada id={$venta_consolidada->id}, num={$num}");

            DB::commit();

            /** Emite el comprobante AFIP sobre la venta consolidada si se indica. */
            if ($emitir_afip) {
                $afip = new MakeAfipTicket();
                $afip->make_afip_ticket(self::build_afip_ticket_data(
                    $afip_data,
                    $venta_consolidada->id,
                    $afip_information_id,
                    $afip_tipo_comprobante_id
                ));
            }

            /** Recarga la venta con todas sus relaciones para devolverla completa. */
            return Sale::withAll()->find($venta_consolidada->id);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("ConsolidarFacturacion error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Arma el payload del ticket AFIP de la venta consolidada.
     *
     * `facturar_importe_personalizado` va SIEMPRE en null: por decision de Lucas del
     * 20/8/2026, si se consolidan varias ventas en una sola factura no se puede informar
     * ningun importe personalizado (ni su reparto por alicuota). Si el request igual trae
     * uno, se descarta y se loguea.
     *
     * Esta extraido a un static publico para poder testear el payload sin salir a la red:
     * `make_afip_ticket()` dispara AfipWsController contra ARCA.
     *
     * @param array $afip_data Datos AFIP extra que llegaron en el request.
     * @param int $sale_id Id de la venta consolidada.
     * @param int $afip_information_id Configuracion fiscal elegida.
     * @param int $afip_tipo_comprobante_id Tipo de comprobante elegido.
     * @return array Payload listo para MakeAfipTicket::make_afip_ticket().
     */
    public static function build_afip_ticket_data(array $afip_data, int $sale_id, int $afip_information_id, int $afip_tipo_comprobante_id): array
    {
        if (isset($afip_data['monto_a_facturar']) && (float) $afip_data['monto_a_facturar'] > 0) {
            Log::warning(
                'ConsolidarFacturacion: se descarto el importe personalizado ('.$afip_data['monto_a_facturar'].') '.
                'de la venta consolidada id='.$sale_id.'. En una factura consolidada no se admite.'
            );
        }

        return [
            'sale_id'                        => $sale_id,
            'afip_information_id'            => $afip_information_id,
            'afip_tipo_comprobante_id'       => $afip_tipo_comprobante_id,
            'afip_fecha_emision'             => $afip_data['afip_fecha_emision'] ?? null,
            'facturar_importe_personalizado' => null,
            'importe_personalizado_ivas'     => null,
            'forma_de_pago'                  => $afip_data['forma_de_pago'] ?? null,
            'permiso_existente'              => $afip_data['permiso_existente'] ?? null,
            'incoterms'                      => $afip_data['incoterms'] ?? null,
        ];
    }

    /**
     * Copia los artículos de todas las ventas originales a la venta consolidada.
     *
     * Sin agrupar, cada renglón de cada venta original es un renglón de la consolidada, aunque
     * se repita el artículo (dos renglones del mismo artículo en una misma venta, por ejemplo con
     * varios precios, siguen siendo dos).
     *
     * Agrupando, los renglones que son el MISMO renglón de factura salvo la cantidad se funden en
     * uno solo, vengan de la venta que vengan: ver clave_de_agrupacion().
     *
     * @param Sale       $consolidada       Venta contenedora destino.
     * @param Collection $ventas_originales Colección de ventas originales cargadas con 'articles'.
     * @param bool       $agrupar           Si true, agrupa renglones iguales sumando cantidades.
     */
    private static function copiar_articulos(Sale $consolidada, $ventas_originales, bool $agrupar): void
    {
        /**
         * Renglones a adjuntar, en el orden en que aparecen en las ventas originales.
         *
         * 🔴 Sin agrupar la clave es la posición (`[]`), una por renglón. Hasta el 5/10/2026 la
         * clave era "artículo-venta" en los dos casos: agrupando no juntaba nada entre ventas (que
         * es justo para lo que existe la opción), y sin agrupar dos renglones del mismo artículo en
         * una venta compartían clave y el segundo pisaba al primero, que desaparecía de la factura.
         */
        $items_a_adjuntar = [];

        foreach ($ventas_originales as $venta) {
            foreach ($venta->articles as $article) {
                /** Extrae todos los datos del pivot para reproducirlos en la consolidada. */
                $pivot = $article->pivot;

                if ($agrupar) {
                    $clave = self::clave_de_agrupacion($article->id, $pivot);

                    if (isset($items_a_adjuntar[$clave])) {
                        /**
                         * Mismo renglón de factura: se suman las cantidades y la ganancia. Lo demás
                         * (costo, lista de precios, fecha) queda el del primer renglón; ver
                         * clave_de_agrupacion() para el porqué de cada campo.
                         */
                        $items_a_adjuntar[$clave]['amount']           += (float)$pivot->amount;
                        $items_a_adjuntar[$clave]['ganancia']         += (float)$pivot->ganancia;
                        $items_a_adjuntar[$clave]['returned_amount']  += (float)($pivot->returned_amount ?? 0);
                        $items_a_adjuntar[$clave]['delivered_amount'] = self::sumar_cantidad_opcional($items_a_adjuntar[$clave]['delivered_amount'], $pivot->delivered_amount);
                        $items_a_adjuntar[$clave]['checked_amount']   = self::sumar_cantidad_opcional($items_a_adjuntar[$clave]['checked_amount'], $pivot->checked_amount);
                        continue;
                    }

                    $items_a_adjuntar[$clave] = self::item_desde_pivot($article->id, $pivot);
                    continue;
                }

                $items_a_adjuntar[] = self::item_desde_pivot($article->id, $pivot);
            }
        }

        /** Adjunta todos los ítems acumulados a la venta consolidada, un renglón por ítem. */
        foreach ($items_a_adjuntar as $item) {
            $article_id = $item['article_id'];
            unset($item['article_id']);
            $consolidada->articles()->attach($article_id, $item);
        }
    }

    /**
     * Arma el renglón de la consolidada a partir del pivot de un renglón original.
     *
     * 🔴 MENOS `price_sin_recargos_de_venta`, A PROPOSITO, y no es un olvido (mision
     * recargos-en-precios-editable, 28/9/2026). La base dice "este precio trae adentro los recargos
     * de ESTE comprobante", y la consolidada no tiene recargos ni la opcion
     * `aplicar_recargos_directo_a_items`: `consolidar()` no copia ni `surchages` ni el flag de las
     * originales (que ademas pueden tener la opcion distinta entre si). Para la consolidada, `price`
     * es el precio final y punto. Si la base se copiara, VENDER —que con el flag en 0 muestra la base
     * cuando la hay— le bajaria el precio a cada renglon al abrirla y el recargo no apareceria en
     * ningun lado. NULL es la verdad de este comprobante. Lo mismo en combos y servicios, mas abajo.
     *
     * `name`, `iva_percentage` y `price_sin_iva` se copian desde el 5/10/2026 (decisión de Lucas).
     * Antes no, y la factura consolidada salía con el IVA ACTUAL del artículo en vez del del momento
     * de la venta (`AfipItemCalculator::resolve_article_iva_percentage()` prioriza el del pivot y
     * cae al del artículo si no hay) y con el nombre del catálogo en vez del nombre personalizado del
     * renglón (`GeneralHelper` imprime el del pivot si lo hay).
     *
     * @param int    $article_id Id del artículo del renglón.
     * @param object $pivot      Pivot del renglón original (article_sale).
     * @return array Datos del renglón, con `article_id` para el attach.
     */
    private static function item_desde_pivot($article_id, $pivot): array
    {
        return [
            'article_id'                  => $article_id,
            'amount'                      => (float)$pivot->amount,
            'cost'                        => (float)$pivot->cost,
            'price'                       => (float)$pivot->price,
            'ganancia'                    => (float)$pivot->ganancia,
            'returned_amount'             => (float)($pivot->returned_amount ?? 0),
            'delivered_amount'            => $pivot->delivered_amount,
            'discount'                    => (float)($pivot->discount ?? 0),
            'with_dolar'                  => $pivot->with_dolar,
            'checked_amount'              => $pivot->checked_amount,
            'variant_description'         => $pivot->variant_description,
            'name'                        => $pivot->name,
            'article_variant_id'          => $pivot->article_variant_id,
            'price_type_personalizado_id' => $pivot->price_type_personalizado_id,
            'iva_percentage'              => $pivot->iva_percentage,
            'price_sin_iva'               => $pivot->price_sin_iva,
            'fecha_agregado'              => $pivot->fecha_agregado,
            'created_at'                  => Carbon::now(),
        ];
    }

    /**
     * Clave con la que se funden renglones al consolidar agrupando.
     *
     * Dos renglones se funden solo si, puestos uno al lado del otro en la factura, son
     * indistinguibles salvo la cantidad:
     *
     *   - artículo y variante: qué se vendió.
     *   - precio: el renglón de la factura es cantidad × precio unitario. Fundir 4 a $1.000 con 6 a
     *     $1.100 facturaría 10 a $1.000 contra una contenedora de $10.600; promediar imprimiría en
     *     un comprobante fiscal un precio que nunca existió, y el redondeo movería centavos. Si el
     *     precio cambió entre una venta y otra quedan dos renglones, a propósito.
     *   - descuento del renglón: se aplica sobre ese precio (AfipItemCalculator).
     *   - with_dolar: la cotización con la que se guardó el renglón.
     *   - alícuota y neto (iva_percentage, price_sin_iva): son la alícuota y el neto del renglón en
     *     la factura; dos alícuotas distintas no se pueden fundir.
     *   - nombre personalizado: se imprime en lugar del del catálogo.
     *
     * El costo NO entra: no se ve en la factura y el del mismo artículo cambia entre ventas, así
     * que separaría justo los renglones que la opción existe para juntar. El renglón fundido lleva
     * el costo del primero; la ganancia, que es lo que se suma, se suma exacta. Tampoco entran la
     * lista de precios, la descripción de la variante ni la fecha: no cambian el renglón facturado.
     *
     * serialize de un array y no un join con separador: el nombre es texto libre y podría traer
     * cualquier separador adentro. Y no json_encode: con un nombre en UTF-8 inválido devuelve false,
     * y todos esos renglones caerían en la misma clave.
     *
     * @param int    $article_id Id del artículo del renglón.
     * @param object $pivot      Pivot del renglón original (article_sale).
     * @return string
     */
    private static function clave_de_agrupacion($article_id, $pivot): string
    {
        return serialize([
            (int)$article_id,
            self::id_opcional($pivot->article_variant_id),
            (float)$pivot->price,
            (float)($pivot->discount ?? 0),
            self::numero_opcional($pivot->with_dolar),
            self::alicuota_normalizada($pivot->iva_percentage),
            self::numero_opcional($pivot->price_sin_iva),
            self::texto_opcional($pivot->name),
        ]);
    }

    /**
     * Suma dos cantidades que pueden no estar cargadas (entregada, chequeada). Si ninguna está, la
     * suma tampoco: "no se registró" es null, no 0.
     *
     * @param mixed $acumulada Cantidad ya acumulada en el renglón fundido.
     * @param mixed $nueva     Cantidad del renglón que se suma.
     * @return float|null
     */
    private static function sumar_cantidad_opcional($acumulada, $nueva)
    {
        if (is_null($acumulada) && is_null($nueva)) {
            return null;
        }

        return (float)$acumulada + (float)$nueva;
    }

    /**
     * Id opcional para la clave: vacío o 0 es "sin id".
     *
     * @param mixed $valor
     * @return int|null
     */
    private static function id_opcional($valor)
    {
        if (empty($valor)) {
            return null;
        }

        return (int)$valor;
    }

    /**
     * Número opcional para la clave: el pivot devuelve los decimales como texto ('1000.00'), así
     * que se pasan a float para que '1000.00' y '1000' den la misma clave. Vacío es null.
     *
     * @param mixed $valor
     * @return float|null
     */
    private static function numero_opcional($valor)
    {
        if (is_null($valor) || $valor === '') {
            return null;
        }

        return (float)$valor;
    }

    /**
     * Alícuota para la clave. El pivot la guarda como TEXTO a propósito ('21.00', 'Exento', 'No
     * Gravado'): las numéricas se pasan a float ('21.00' y '21' son la misma) y las otras quedan
     * como texto, para no confundir Exento con 0%.
     *
     * @param mixed $valor
     * @return float|string|null
     */
    private static function alicuota_normalizada($valor)
    {
        if (is_null($valor) || $valor === '') {
            return null;
        }

        if (is_numeric($valor)) {
            return (float)$valor;
        }

        return trim((string)$valor);
    }

    /**
     * Texto opcional para la clave: recortado, y vacío es null.
     *
     * @param mixed $valor
     * @return string|null
     */
    private static function texto_opcional($valor)
    {
        if (is_null($valor)) {
            return null;
        }

        $texto = trim((string)$valor);

        if ($texto === '') {
            return null;
        }

        return $texto;
    }

    /**
     * Copia los combos de todas las ventas originales a la venta consolidada.
     * No agrupa combos, se replican como están en cada venta.
     *
     * @param Sale       $consolidada       Venta contenedora destino.
     * @param Collection $ventas_originales Colección de ventas originales cargadas con 'combos'.
     */
    private static function copiar_combos(Sale $consolidada, $ventas_originales): void
    {
        foreach ($ventas_originales as $venta) {
            foreach ($venta->combos as $combo) {
                $pivot = $combo->pivot;
                $consolidada->combos()->attach($combo->id, [
                    'amount'     => (float)($pivot->amount ?? 1),
                    'price'      => (float)($pivot->price ?? 0),
                    /*
                     * El costo se copia TAL CUAL, NULL incluido (misión combos-calculados, Parte A2,
                     * 30/9/2026). Antes era `?? 0`, y eso convierte "no sé cuánto costó" (el combo de
                     * una venta anterior a esa misión) en "costó cero": la consolidada sumaría 0 al
                     * costo con aspecto de dato medido. NULL sigue significando "sin costo
                     * resuelto", y `SaleTotalesHelper::costo_de_combos()` lo trata como 0 igual que
                     * en la venta original.
                     */
                    'cost'       => is_null($pivot->cost) ? null : (float)$pivot->cost,
                    'created_at' => Carbon::now(),
                ]);
            }
        }
    }

    /**
     * Copia los servicios de todas las ventas originales a la venta consolidada.
     *
     * @param Sale       $consolidada       Venta contenedora destino.
     * @param Collection $ventas_originales Colección de ventas originales cargadas con 'services'.
     */
    private static function copiar_services(Sale $consolidada, $ventas_originales): void
    {
        foreach ($ventas_originales as $venta) {
            foreach ($venta->services as $service) {
                $pivot = $service->pivot;
                $consolidada->services()->attach($service->id, [
                    'discount'        => (float)($pivot->discount ?? 0),
                    'amount'          => (float)($pivot->amount ?? 1),
                    'price'           => (float)($pivot->price ?? 0),
                    'returned_amount' => (float)($pivot->returned_amount ?? 0),
                    'created_at'      => Carbon::now(),
                ]);
            }
        }
    }

    /**
     * Retorna las ventas de un cliente en un rango de fechas que son elegibles
     * para ser consolidadas: terminadas, sin CAE y sin consolidación previa.
     *
     * @param int         $client_id  ID del cliente a filtrar.
     * @param int         $user_id    ID del usuario autenticado.
     * @param string|null $from       Fecha desde (Y-m-d). Null = sin límite inferior.
     * @param string|null $until      Fecha hasta (Y-m-d). Null = sin límite superior.
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function ventas_por_consolidar(int $client_id, int $user_id, ?string $from, ?string $until)
    {
        $query = Sale::where('user_id', $user_id)
                     ->where('client_id', $client_id)
                     /** Solo ventas reales (excluir contenedoras previas). */
                     ->soloVentasReales()
                     /** Solo ventas terminadas. */
                     ->where('terminada', 1)
                     /** Solo ventas que aún no fueron incluidas en una consolidación. */
                     ->whereNull('consolidacion_facturacion_id')
                     /** Solo ventas sin comprobante AFIP autorizado (sin CAE). */
                     ->whereDoesntHave('afip_tickets', function ($q) {
                         $q->whereNotNull('cae')->where('cae', '!=', '');
                     })
                     ->with('afip_tickets', 'articles')
                     ->orderBy('created_at', 'DESC');

        if (!is_null($from)) {
            $query->whereDate('created_at', '>=', $from);
        }

        if (!is_null($until)) {
            $query->whereDate('created_at', '<=', $until);
        }

        return $query->get();
    }
}
