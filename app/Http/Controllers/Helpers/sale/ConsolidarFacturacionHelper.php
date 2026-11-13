<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\Afip\MakeAfipTicket;
use App\Http\Controllers\Helpers\Afip\CandadoDeFacturaHelper;
use App\Http\Controllers\Helpers\Afip\FacturaDeVentaHelper;
use App\Http\Controllers\Helpers\currentAcount\CuentaCorrienteLock;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Sale;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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

    private const ESCALA_PRECIO_CONSOLIDADO = 12;

    public static function vista_previa(array $sale_ids, int $client_id, int $user_id): array
    {
        $consulta = Sale::whereIn('id', $sale_ids)
            ->where('user_id', $user_id)
            ->where('client_id', $client_id);
        self::aplicar_alcance_de_ventas($consulta);
        $ventas = $consulta
            ->with('articles.iva', 'combos', 'services', 'promocion_vinotecas', 'discounts', 'surchages', 'current_acount_payment_methods')
            ->orderBy('id')
            ->get();
        if ($ventas->count() !== count(array_unique(array_map('intval', $sale_ids)))) {
            throw new Exception('Una o más ventas de la selección ya no están disponibles.');
        }
        self::validar($sale_ids, $client_id, $user_id);
        self::validar_moneda($ventas);

        return [
            'ventas' => $ventas->map(function (Sale $venta) {
                return [
                    'id' => $venta->id,
                    'num' => $venta->num,
                    'total_antes_de_ajustes' => self::importe_de_renglones($venta, [
                        'articles' => 1, 'combos' => 1, 'services' => 1, 'promocion_vinotecas' => 1,
                    ]),
                    'total' => (float) $venta->total,
                ];
            }),
            'moneda_id' => (int) ($ventas->first()->moneda_id ?? 1),
            'total' => round((float) $ventas->sum('total'), 2),
            'firma_vista_previa' => self::firma_vista_previa($ventas),
            'avisos' => $ventas->map(function (Sale $venta) {
                return self::aviso_de_importes($venta, self::factores_de_recargo($venta));
            })->filter()->values(),
        ];
    }

    private static function importe_de_renglones(Sale $venta, array $factores, bool $redondear = true): float
    {
        $importe = 0;
        foreach ($factores as $relacion => $factor) {
            foreach ($venta->{$relacion} as $renglon) {
                $importe += SaleHelper::getTotalItem($renglon) * $factor;
            }
        }
        return $redondear ? round($importe, 2) : $importe;
    }

    private static function aviso_de_importes(Sale $venta, array $factores): ?string
    {
        if (self::importe_de_renglones($venta, $factores) !== round((float) $venta->total, 2)) {
            $numero = $venta->num ?? $venta->id;
            return "Los renglones de la venta N° {$numero} no llegan a su total con los ajustes disponibles. Revisá esa venta antes de consolidar.";
        }
        return null;
    }

    private static function firma_vista_previa($ventas): string
    {
        $datos = $ventas->sortBy('id')->values()->map(function (Sale $venta) {
            $renglones = [];
            foreach (['articles', 'combos', 'services', 'promocion_vinotecas'] as $relacion) {
                $renglones[$relacion] = $venta->{$relacion}->map(function ($item) {
                    return [
                        $item->id,
                        $item->pivot->price,
                        $item->pivot->amount,
                        $item->pivot->discount,
                        $item->pivot->iva_percentage ?? ($item->iva->percentage ?? null),
                        $item->pivot->returned_amount,
                    ];
                })->sortBy(function ($renglon) {
                    return json_encode($renglon);
                })->values()->all();
            }
            return [
                $venta->id, $venta->total, $venta->moneda_id, $venta->valor_dolar,
                $venta->forzar_total_monto, self::factores_de_recargo($venta), $renglones,
            ];
        });

        return hash_hmac('sha256', $datos->toJson(), config('app.key'));
    }

    private static function validar_moneda($ventas): void
    {
        if ($ventas->isEmpty()) {
            throw new Exception('Ya no hay ventas disponibles para consolidar.');
        }
        $primera = $ventas->first();
        foreach ($ventas as $venta) {
            if ((int) ($venta->moneda_id ?? 1) !== (int) ($primera->moneda_id ?? 1)
                || ($primera->moneda_id == 2 && (float) $venta->valor_dolar !== (float) $primera->valor_dolar)) {
                throw new Exception('Para consolidar, las ventas deben tener la misma moneda y cotización.');
            }
        }
    }

    private static function aplicar_alcance_de_ventas($consulta): void
    {
        ListadoVentasHelper::aplicar_alcance($consulta);
        if (ListadoVentasHelper::solo_hoy_permitido()) {
            $consulta->whereDate('sales.created_at', Carbon::today()->toDateString());
        }
    }

    /** Valida existencia, dueño, cliente y estado antes de incluir cada venta. */
    public static function validar(array $sale_ids, int $client_id, int $user_id): void
    {
        /** La misma venta elegida dos veces cuenta una sola: no es una venta inexistente. */
        $sale_ids = array_values(array_unique(array_map('intval', $sale_ids)));

        if (empty($sale_ids)) {
            throw new Exception('Debe seleccionar al menos una venta para consolidar.');
        }

        /**
         * Sin cliente no hay consolidación: las ventas de mostrador no se consolidan. Además, con
         * `client_id = 0` (el cast de un campo ausente) la comparación de abajo daría igual para
         * una venta sin cliente y `CuentaCorrienteLock` no tomaría ningún candado.
         */
        if ($client_id <= 0) {
            throw new Exception('Las ventas de mostrador (sin cliente) no se pueden consolidar.');
        }

        /** Carga las ventas a validar con relaciones mínimas necesarias. */
        $consulta = Sale::whereIn('id', $sale_ids);
        self::aplicar_alcance_de_ventas($consulta);
        $sales = $consulta->with('afip_tickets')->get();

        if ($sales->count() !== count($sale_ids)) {
            throw new Exception('Una o más ventas indicadas no existen.');
        }

        foreach ($sales as $sale) {

            /** Cómo se nombra la venta en los mensajes: su número, o su id si todavía no tiene. */
            $etiqueta = !is_null($sale->num) ? $sale->num : $sale->id;

            /** Todas las ventas deben pertenecer al usuario autenticado. */
            if ($sale->user_id != $user_id) {
                throw new Exception("La venta #{$etiqueta} no pertenece al usuario actual.");
            }

            /** Todas las ventas deben ser del mismo cliente. */
            if ((int) $sale->client_id !== $client_id) {
                throw new Exception("La venta #{$etiqueta} no corresponde al cliente indicado.");
            }

            /**
             * No se puede consolidar una venta que ya está dentro de otra consolidación.
             *
             * Solo cuenta si la contenedora sigue viva: una contenedora que se borró (no se puede
             * borrar con un comprobante, ver DeleteSaleHelper) ya no sostiene a nadie, y sus ventas
             * originales pueden volver a consolidarse, que es lo que Ventas permitía antes.
             */
            if (!is_null($sale->consolidacion_facturacion_id)) {
                $contenedora = Sale::find($sale->consolidacion_facturacion_id);

                if (!is_null($contenedora)) {
                    $num_contenedora = !is_null($contenedora->num) ? $contenedora->num : $contenedora->id;
                    throw new Exception("La venta #{$etiqueta} ya fue incluida en la consolidación N° {$num_contenedora}.");
                }
            }

            /** No se puede consolidar una venta que ya es ella misma una contenedora de facturación. */
            if ($sale->is_consolidacion_facturacion) {
                throw new Exception("La venta #{$etiqueta} es una consolidación y no puede volver a consolidarse.");
            }

            /** No se consolidan ventas que ya tienen un comprobante AFIP autorizado (con CAE). */
            $tiene_cae = FacturaDeVentaHelper::facturas_autorizadas($sale->id)->exists();
            if ($tiene_cae) {
                throw new Exception("La venta #{$etiqueta} ya tiene un comprobante AFIP autorizado.");
            }
            $pendiente = FacturaDeVentaHelper::intento_incierto($sale->id);
            if ($pendiente) {
                throw new Exception("La venta #{$etiqueta} tiene una emisión pendiente de verificar en ARCA. Consultá ese comprobante antes de consolidarla.");
            }
        }
    }

    /**
     * Por qué una venta NO se puede facturar suelta, o null si se puede.
     *
     * Una venta ORIGINAL incluida en una consolidación no tiene factura propia: su comprobante es
     * el de la venta contenedora. Si además se emitiera una factura suelta, la misma venta quedaría
     * facturada dos veces (la suya y la de la consolidación). `POST api/afip-ticket` no lo miraba
     * (misión cc-afip-ticket-rechaza-consolidada, 23/10/2026), y `validar()` solo cubre el orden
     * inverso: facturar suelta y DESPUÉS consolidar.
     *
     * Reglas, a propósito iguales a las de `validar()`:
     *  - Solo cuenta si la contenedora sigue viva. Una contenedora borrada ya no sostiene a nadie
     *    (no se puede borrar con un comprobante, ver DeleteSaleHelper) y sus ventas originales
     *    vuelven a poder facturarse.
     *  - La propia contenedora SÍ se factura por este camino: es lo que se hace para reintentar el
     *    comprobante de una consolidación que quedó sin CAE.
     *
     * @param  \App\Models\Sale  $sale
     * @return string|null  El mensaje para el usuario, o null si se puede facturar.
     */
    public static function motivo_por_el_que_no_se_puede_facturar_suelta($sale)
    {
        if (is_null($sale->consolidacion_facturacion_id)) {
            return null;
        }

        $contenedora = Sale::find($sale->consolidacion_facturacion_id);

        if (is_null($contenedora)) {
            return null;
        }

        $num_venta       = !is_null($sale->num) ? $sale->num : $sale->id;
        $num_contenedora = !is_null($contenedora->num) ? $contenedora->num : $contenedora->id;

        return 'La venta N° '.$num_venta.' está incluida en la consolidación N° '.$num_contenedora.': su factura es la de esa consolidación y no se factura por separado. '
            .'Si esa factura tiene un problema, resolvelo desde la venta consolidada (en Ventas, "Ver consolidadas").';
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
        $argumentos = func_get_args();
        // Compartido con la emisión suelta: la cuenta corriente sola no protege una venta de mostrador.
        return CandadoDeFacturaHelper::ventas($sale_ids, function () use ($argumentos) {
            return self::consolidar_con_candados(...$argumentos);
        });
    }

    private static function consolidar_con_candados(
        array $sale_ids,
        int   $client_id,
        int   $user_id,
        int   $afip_information_id,
        int   $afip_tipo_comprobante_id,
        bool  $agrupar_items  = false,
        array $afip_data      = [],
        bool  $emitir_afip    = true
    ): Sale {
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

            /**
             * La validación va ADENTRO de la transacción y DESPUÉS del candado, no antes de abrirla.
             * Con el candado tomado el segundo envío de la misma selección (doble clic, dos
             * pestañas) espera al primero y recién ahí lee las ventas: las encuentra ya marcadas y
             * se rechaza. Validar antes del candado dejaba pasar a los dos. Si falla, el catch de
             * abajo deshace la transacción (todavía no se escribió nada) y relanza el mensaje.
             */
            self::validar($sale_ids, $client_id, $user_id);

            /** Carga las ventas con sus artículos y datos de pivot para copiarlos. */
            $ventas_originales = Sale::whereIn('id', $sale_ids)
                                     /* articles.iva: la alicuota actual del articulo, para la clave de agrupacion (ver clave_de_agrupacion). */
                                     ->with('articles.iva', 'combos', 'services', 'promocion_vinotecas', 'discounts', 'surchages', 'current_acount_payment_methods', 'client')
                                     ->get();
            self::validar_moneda($ventas_originales);
            if (isset($afip_data['firma_vista_previa'])
                && !hash_equals(self::firma_vista_previa($ventas_originales), (string) $afip_data['firma_vista_previa'])) {
                throw new Exception('Las ventas cambiaron desde la vista previa. Volvé a revisar los importes antes de confirmar.');
            }

            /** Acumula los totales de las ventas originales para el campo total de la consolidada. */
            $total_consolidado  = round((float) $ventas_originales->sum('total'), 2);
            $sub_total_consolid = $ventas_originales->sum('sub_total');

            $factores = [];
            foreach ($ventas_originales as $venta) {
                $factores[$venta->id] = self::factores_de_recargo($venta);
                $aviso = self::aviso_de_importes($venta, $factores[$venta->id]);
                if ($emitir_afip && $aviso !== null) {
                    throw new Exception($aviso);
                }
            }

            $bruto_acumulado = $ventas_originales->sum(function (Sale $venta) use ($factores) {
                return self::importe_de_renglones($venta, $factores[$venta->id], false);
            });
            $primera_venta = $ventas_originales->first();
            $cotizacion = $primera_venta->moneda_id == 2 ? (float) $primera_venta->valor_dolar : 1;
            if (round($bruto_acumulado, 2) !== $total_consolidado
                || ($cotizacion > 0 && round($bruto_acumulado * $cotizacion, 2) !== round($total_consolidado * $cotizacion, 2))) {
                foreach ($ventas_originales as $venta) {
                    $factores[$venta->id] = self::factores_de_recargo($venta, true);
                }
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

            $ajustes_consolidados = [];
            if (ForzarTotalEsquemaHelper::hay_columna_en_sales()
                && Schema::hasColumn('sales', 'ajustes_consolidados_en_renglones')) {
                // El ajuste firmado queda para auditoría. Los precios de abajo ya lo incorporan.
                $monto = round($ventas_originales->sum(function (Sale $venta) {
                    return SaleHelper::get_forzar_total_monto($venta);
                }), 2);
                $ajustes_consolidados = [
                    'forzar_total_monto' => $monto == 0 ? null : $monto,
                    'ajustes_consolidados_en_renglones' => $monto != 0,
                ];
            }

            /** Crea la venta contenedora marcada para excluirla de reportes y cuentas. */
            $venta_consolidada = Sale::create(IvaEnArticulosSinIvaEsquemaHelper::quitar_si_no_hay_columna([
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
            ] + $ajustes_consolidados, 'sales'));

            /** Copia los ítems de todas las ventas originales a la consolidada. */
            self::copiar_articulos($venta_consolidada, $ventas_originales, $agrupar_items, $factores);
            self::copiar_combos($venta_consolidada, $ventas_originales, $factores);
            self::copiar_services($venta_consolidada, $ventas_originales, $factores);

            if ($emitir_afip) {
                FacturaDeVentaHelper::preparar(self::build_afip_ticket_data(
                    $afip_data, $venta_consolidada->id, $afip_information_id, $afip_tipo_comprobante_id
                ), $user_id);
                MakeAfipTicket::validar_total_consolidado($venta_consolidada, $afip_information_id, $afip_tipo_comprobante_id);
            }

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
     * Materializa los ajustes de cada remito sobre su propia base.
     * Los ajustes directos ya vienen en el precio de los renglones originales.
     */
    private static function factores_de_recargo(Sale $venta, bool $materializar_redondeo = false): array
    {
        $recargo = 1.0;
        if (!$venta->aplicar_recargos_directo_a_items) {
            foreach ($venta->surchages as $surchage) {
                $recargo *= 1 + (float) $surchage->pivot->percentage / 100;
            }
        }

        $descuento = 1.0;
        if (!$venta->aplicar_descuentos_directo_a_items) {
            foreach ($venta->discounts as $discount) {
                $descuento *= 1 - (float) $discount->pivot->percentage / 100;
            }
        }

        $factor_total = self::factor_de_ajustes_por_importe($venta);

        $factores = [
            'articles' => $recargo * $descuento * $factor_total * (1 - (float) $venta->descuento / 100),
            'combos' => $recargo * $descuento * $factor_total,
            'services' => $factor_total * ($venta->surchages_in_services ? $recargo : 1.0)
                * ($venta->discounts_in_services ? $descuento : 1.0),
        ];

        // Si la suma ya cierra, conservar los precios precisos originales con sus ajustes.
        if (!$materializar_redondeo) {
            return $factores;
        }

        // Cada remito aporta su total ya redondeado, no la suma sin redondear de todos
        // los remitos. Sólo materializamos ese residuo si los ajustes explican su total
        // AL CENTAVO. Una diferencia real sigue pasando intacta al aviso y a la guarda.
        $brutos = [];
        foreach ($factores as $relacion => $factor) {
            $brutos[$relacion] = $venta->{$relacion}->sum(function ($item) use ($factor) {
                return SaleHelper::getTotalItem($item) * $factor;
            });
        }
        $bruto = array_sum($brutos);
        $total = round((float) $venta->total, 2);
        if (round($bruto, 2) === $total) {
            // Evita que restar importes grandes agregue ruido binario a la clave
            // de agrupación. Ocho decimales siguen muy por debajo de un centavo.
            $residuo = round($total - $bruto, 8);
            // Orden fijo: artículos antes que combos y servicios. Un servicio sin
            // recargo conserva su precio cuando hay artículos sobre los que repartir.
            foreach ($brutos as $relacion => $importe) {
                if ($importe > 0 && $importe + $residuo > 0) {
                    $factores[$relacion] *= ($importe + $residuo) / $importe;
                    break;
                }
            }
        }

        return $factores;
    }

    private static function factor_de_ajustes_por_importe(Sale $venta): float
    {
        $descuento_pago = $venta->current_acount_payment_methods->sum('pivot.discount_amount');
        $forzado = SaleHelper::get_forzar_total_monto($venta);
        if ($descuento_pago == 0 && $forzado == 0) {
            return 1.0;
        }

        // Cada importe fijo se reparte solo entre los renglones de su venta.
        $base = SaleHelper::getTotalSale($venta) - $forzado;
        $total = (float) $venta->total;
        if ($base <= 0 || $total < 0 || round($base - $descuento_pago + $forzado, 2) !== round($total, 2)) {
            return 1.0;
        }

        return $total / $base;
    }

    private static function precio_con_recargo($precio, float $factor): float
    {
        return round((float) $precio * $factor, self::ESCALA_PRECIO_CONSOLIDADO);
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
    private static function copiar_articulos(Sale $consolidada, $ventas_originales, bool $agrupar, array $factores): void
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
                $item = self::item_desde_pivot($article->id, $pivot, $factores[$venta->id]['articles']);

                if ($agrupar) {
                    $clave = self::clave_de_agrupacion($article, $item['price']);

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

                        /**
                         * Si el primer renglón no traía alícuota o neto (las ventas que nacen de un
                         * presupuesto o de producción no los guardan) y este sí, se completan: la
                         * clave ya garantiza que la alícuota efectiva es la misma, así que no cambia
                         * lo que se factura y la consolidada guarda el dato en vez de un null.
                         */
                        if (is_null($items_a_adjuntar[$clave]['iva_percentage']) && !is_null($pivot->iva_percentage)) {
                            $items_a_adjuntar[$clave]['iva_percentage'] = $pivot->iva_percentage;
                        }
                        if (is_null($items_a_adjuntar[$clave]['price_sin_iva']) && !is_null($item['price_sin_iva'])) {
                            $items_a_adjuntar[$clave]['price_sin_iva'] = $item['price_sin_iva'];
                        }
                        continue;
                    }

                    $items_a_adjuntar[$clave] = $item;
                    continue;
                }

                $items_a_adjuntar[] = $item;
            }
        }

        /** Adjunta todos los ítems acumulados a la venta consolidada, un renglón por ítem. */
        $relacion = $consolidada->articles();
        foreach ($items_a_adjuntar as $item) {
            $article_id = $item['article_id'];
            unset($item['article_id']);
            $relacion->attach($article_id, $relacion->conservar_precision($item));
        }
    }

    /**
     * Arma el renglón de la consolidada a partir del pivot de un renglón original.
     *
     * Conserva el precio final del renglón, incluido el recargo global que le corresponde.
     * Los precios base de recargos o descuentos directos y sus flags pertenecen al remito
     * original. Copiarlos permitiría revertir un ajuste que ya forma parte del precio consolidado.
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
    private static function item_desde_pivot($article_id, $pivot, float $factor): array
    {
        return [
            'article_id'                  => $article_id,
            'amount'                      => (float)$pivot->amount,
            'cost'                        => (float)$pivot->cost,
            'price'                       => self::precio_con_recargo($pivot->price, $factor),
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
            'price_sin_iva'               => is_null($pivot->price_sin_iva)
                ? null
                : round((float) $pivot->price_sin_iva * $factor, 2),
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
     *   - alícuota EFECTIVA: la que va a usar la factura, con la misma regla que
     *     `AfipItemCalculator::resolve_article_iva_percentage()` (la del renglón; si no hay, la
     *     actual del artículo; si tampoco, 21). Efectiva y no la del pivot a secas porque las ventas
     *     que nacen de un presupuesto o de producción no guardan la alícuota: con la del pivot, el
     *     mismo artículo al mismo precio no se juntaría entre una de esas y una de VENDER.
     *   - with_dolar: valor heredado; hoy ningún camino de venta lo escribe (queda null), pero en
     *     renglones viejos puede venir cargado, y dos valores distintos no se mezclan.
     *   - nombre personalizado: se imprime en lugar del del catálogo.
     *
     * El neto (`price_sin_iva`) NO entra: es `round(precio / (1 + alícuota), 2)`
     * (`SaleHelper::get_price_sin_iva()`), o sea que con el mismo precio y la misma alícuota es el
     * mismo, y como las ventas de presupuesto no lo guardan, separaría renglones iguales.
     *
     * El costo NO entra: no se ve en la factura y el del mismo artículo cambia entre ventas, así
     * que separaría justo los renglones que la opción existe para juntar. El renglón fundido lleva
     * el costo del primero; la ganancia, que es lo que se suma, se suma exacta. Tampoco entran la
     * lista de precios, la descripción de la variante (sale de la variante, que sí está) ni la
     * fecha: no cambian el renglón facturado.
     *
     * serialize de un array y no un join con separador: el nombre es texto libre y podría traer
     * cualquier separador adentro. Y no json_encode: con un nombre en UTF-8 inválido devuelve false,
     * y todos esos renglones caerían en la misma clave.
     *
     * @param \App\Models\Article $article Artículo de la venta original, con su pivot (article_sale)
     *                                       y la relación `iva` cargada.
     * @return string
     */
    private static function clave_de_agrupacion($article, float $precio): string
    {
        $pivot = $article->pivot;

        $alicuota = $pivot->iva_percentage;

        if (is_null($alicuota) || trim((string)$alicuota) === '') {
            $alicuota = !is_null($article->iva) ? $article->iva->percentage : 21;
        }

        return serialize([
            (int)$article->id,
            self::id_opcional($pivot->article_variant_id),
            $precio,
            (float)($pivot->discount ?? 0),
            self::numero_opcional($pivot->with_dolar),
            self::alicuota_normalizada($alicuota),
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
     * Cada combo conserva su cantidad y el precio con los ajustes de su venta.
     *
     * @param Sale       $consolidada       Venta contenedora destino.
     * @param Collection $ventas_originales Colección de ventas originales cargadas con 'combos'.
     * @param array      $factores          Ajustes por venta, si ya se calcularon.
     */
    private static function copiar_combos(Sale $consolidada, $ventas_originales, array $factores = []): void
    {
        $relacion = $consolidada->combos();
        foreach ($ventas_originales as $venta) {
            $factor = $factores[$venta->id]['combos'] ?? self::factores_de_recargo($venta)['combos'];
            foreach ($venta->combos as $combo) {
                $pivot = $combo->pivot;
                $relacion->attach($combo->id, $relacion->conservar_precision([
                    'amount'     => (float)($pivot->amount ?? 1),
                    'price'      => self::precio_con_recargo($pivot->price, $factor),
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
                ]));
            }
        }
    }

    /**
     * Copia los servicios de todas las ventas originales a la venta consolidada.
     *
     * @param Sale       $consolidada       Venta contenedora destino.
     * @param Collection $ventas_originales Colección de ventas originales cargadas con 'services'.
     */
    private static function copiar_services(Sale $consolidada, $ventas_originales, array $factores): void
    {
        $relacion = $consolidada->services();
        foreach ($ventas_originales as $venta) {
            foreach ($venta->services as $service) {
                $pivot = $service->pivot;
                $relacion->attach($service->id, $relacion->conservar_precision([
                    'discount'        => (float)($pivot->discount ?? 0),
                    'amount'          => (float)($pivot->amount ?? 1),
                    'price'           => self::precio_con_recargo($pivot->price, $factores[$venta->id]['services']),
                    'returned_amount' => (float)($pivot->returned_amount ?? 0),
                ]));
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
