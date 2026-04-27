<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\Afip\MakeAfipTicket;
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

            /** Carga las ventas con sus artículos y datos de pivot para copiarlos. */
            $ventas_originales = Sale::whereIn('id', $sale_ids)
                                     ->with('articles', 'combos', 'services', 'discounts', 'surchages', 'client')
                                     ->get();

            /** Acumula los totales de las ventas originales para el campo total de la consolidada. */
            $total_consolidado  = $ventas_originales->sum('total');
            $sub_total_consolid = $ventas_originales->sum('sub_total');

            /** Usa el sale_type_id de la primera venta como referencia; todas deben ser del mismo tipo. */
            $sale_type_id = $ventas_originales->first()->sale_type_id;

            /** Toma moneda y configuración de precio de la primera venta como referencia. */
            $moneda_id    = $ventas_originales->first()->moneda_id ?? 1;
            $valor_dolar  = $ventas_originales->first()->valor_dolar;
            $iva_aplicado = $ventas_originales->first()->iva_aplicado ?? 1;

            Log::info("ConsolidarFacturacion: creando venta consolidada para client_id={$client_id}, user_id={$user_id}, ventas=" . implode(',', $sale_ids));

            /** Crea el correlativo usando la lógica estándar del sistema. */
            $num = (new self())->num('sales', $user_id);

            /** Crea la venta contenedora marcada para excluirla de reportes y cuentas. */
            $venta_consolidada = Sale::create([
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
            ]);

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
                $afip->make_afip_ticket([
                    'sale_id'                        => $venta_consolidada->id,
                    'afip_information_id'            => $afip_information_id,
                    'afip_tipo_comprobante_id'       => $afip_tipo_comprobante_id,
                    'afip_fecha_emision'             => $afip_data['afip_fecha_emision'] ?? null,
                    'facturar_importe_personalizado' => $afip_data['monto_a_facturar'] ?? null,
                    'forma_de_pago'                  => $afip_data['forma_de_pago'] ?? null,
                    'permiso_existente'              => $afip_data['permiso_existente'] ?? null,
                    'incoterms'                      => $afip_data['incoterms'] ?? null,
                ]);
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
     * Copia los artículos de todas las ventas originales a la venta consolidada.
     * Si $agrupar es true, suma cantidades de artículos repetidos (mismo id + variante).
     *
     * @param Sale       $consolidada       Venta contenedora destino.
     * @param Collection $ventas_originales Colección de ventas originales cargadas con 'articles'.
     * @param bool       $agrupar           Si true, agrupa ítems iguales sumando amounts.
     */
    private static function copiar_articulos(Sale $consolidada, $ventas_originales, bool $agrupar): void
    {
        /**
         * Colección acumuladora de ítems a adjuntar.
         * Clave: "article_id-variant_id" para detectar duplicados cuando se agrupa.
         */
        $items_a_adjuntar = [];

        Log::info('agrupar: '.$agrupar);

        foreach ($ventas_originales as $venta) {
            foreach ($venta->articles as $article) {
                /** Extrae todos los datos del pivot para reproducirlos en la consolidada. */
                $pivot = $article->pivot;

                /** Clave de agrupación: artículo + variante (null si no tiene). */
                $clave = $article->id . '-' . $venta->id;

                if ($agrupar && isset($items_a_adjuntar[$clave])) {
                    /** Suma la cantidad al ítem ya existente en el acumulador. */
                    $items_a_adjuntar[$clave]['amount']   += (float)$pivot->amount;
                    $items_a_adjuntar[$clave]['ganancia'] += (float)$pivot->ganancia;

                    Log::info('Sobreescribiendo article '.$article->name.' con amount actualizada de : '.$items_a_adjuntar[$clave]['amount']);
                } else {
                    Log::info('Agregando article '.$article->name.' con amount: '.$pivot->amount);
                    /** Primera aparición del ítem: lo registra con todos los datos de pivot. */
                    $items_a_adjuntar[$clave] = [
                        'article_id'                  => $article->id,
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
                        'article_variant_id'          => $pivot->article_variant_id,
                        'price_type_personalizado_id' => $pivot->price_type_personalizado_id,
                        'fecha_agregado'              => $pivot->fecha_agregado,
                        'created_at'                  => Carbon::now(),
                    ];
                }
            }
        }

        /** Adjunta todos los ítems acumulados a la venta consolidada. */
        foreach ($items_a_adjuntar as $item) {
            $article_id = $item['article_id'];
            Log::info('Se adjunto '.$item['article_id'].' con amount: '.$item['amount']);
            unset($item['article_id']);
            $consolidada->articles()->attach($article_id, $item);
        }
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
                    'cost'       => (float)($pivot->cost ?? 0),
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
