<?php

namespace App\Http\Controllers\Helpers\sale;

use Illuminate\Support\Facades\DB;

/** Conserva los movimientos históricos cuando la edición no cambia su hecho económico. */
class EfectosFinancierosDeVentaHelper
{
    static function foto($sale)
    {
        $cuenta = [
            'client_id' => (int) $sale->client_id,
            'moneda_id' => CotizacionDeVentaHelper::normalizar_moneda_id($sale->moneda_id),
            'total' => (float) $sale->total,
            'seller_id' => (int) $sale->seller_id,
            'save_current_acount' => (bool) $sale->save_current_acount,
            'omitir_en_cuenta_corriente' => (bool) $sale->omitir_en_cuenta_corriente,
            'to_check' => (bool) $sale->to_check,
            'checked' => (bool) $sale->checked,
            'created_at' => (string) $sale->created_at,
        ];

        $comisiones = $cuenta;
        $comisiones['sale_type_id'] = (int) $sale->sale_type_id;
        $comisiones['afip_information_id'] = (int) $sale->afip_information_id;
        foreach (['iva_aplicado', 'iva_en_articulos_sin_iva', 'aplicar_recargos_directo_a_items', 'aplicar_descuentos_directo_a_items'] as $campo) {
            $comisiones[$campo] = (bool) $sale->$campo;
        }

        // Se lee la base: las relaciones en memoria pueden seguir teniendo los pivotes anteriores.
        // IDs de pivote, fechas técnicas y cotización no son importes de la comisión.
        $tablas = [
            'article_sale' => ['article_id', 'article_variant_id', 'amount', 'price', 'price_sin_iva', 'cost', 'discount', 'returned_amount', 'ganancia'],
            'combo_sale' => ['combo_id', 'amount', 'price', 'cost'],
            'promocion_vinoteca_sale' => ['promocion_vinoteca_id', 'amount', 'price'],
            'sale_service' => ['service_id', 'amount', 'price', 'discount', 'returned_amount'],
            'discount_sale' => ['discount_id', 'percentage'],
            'sale_surchage' => ['surchage_id', 'percentage'],
        ];

        foreach ($tablas as $tabla => $columnas) {
            $filas = [];
            foreach (DB::table($tabla)->where('sale_id', $sale->id)->get($columnas) as $fila) {
                $valores = [];
                foreach ($columnas as $columna) {
                    if ($columna === 'price_sin_iva' && is_null($fila->$columna)) {
                        // Es el fallback del motor de comisión sin IVA: NULL significa precio
                        // entero, mientras que un cero explícito tiene otro efecto económico.
                        $valores[] = (float) $fila->price;
                        continue;
                    }
                    // En porcentajes/cantidades legacy NULL y 0 tienen el mismo efecto económico.
                    $valores[] = (float) $fila->$columna;
                }
                $filas[] = json_encode($valores);
            }
            sort($filas, SORT_STRING);
            $comisiones[$tabla] = $filas;
        }

        return [
            'cuenta' => $cuenta,
            'comisiones' => $comisiones,
            'puntos_canjeados' => $sale->puntos_canjeados,
            'descuento_puntos' => $sale->descuento_puntos,
            'total_cost' => $sale->total_cost,
            'ganancia' => $sale->ganancia,
        ];
    }

    static function conservar_totales_si_equivalente($foto_previa, $sale)
    {
        $actual = self::foto($sale);
        if ($foto_previa['comisiones'] === $actual['comisiones']) {
            // Recalcular por la ficha fiscal de hoy no es una edición de esta venta histórica.
            $sale->total_cost = $foto_previa['total_cost'];
            $sale->ganancia = $foto_previa['ganancia'];
            $sale->save();
        }
    }

    static function conservar_canje($foto_previa, $sale, $request)
    {
        // Un canje histórico intacto ya consumió sus lotes. Volver a aplicarlo cambiaría el FIFO
        // y podría recalcular su descuento con la configuración de puntos actual.
        return is_numeric($request->puntos_canjeados)
            && is_numeric($request->descuento_puntos)
            && (float) $foto_previa['puntos_canjeados'] > 0
            && (float) $request->puntos_canjeados === (float) $foto_previa['puntos_canjeados']
            && (float) $request->descuento_puntos === (float) $foto_previa['descuento_puntos']
            && (int) $sale->client_id === $foto_previa['cuenta']['client_id']
            && CotizacionDeVentaHelper::normalizar_moneda_id($sale->moneda_id) === $foto_previa['cuenta']['moneda_id']
            && (float) $sale->total === $foto_previa['cuenta']['total'];
    }
}
