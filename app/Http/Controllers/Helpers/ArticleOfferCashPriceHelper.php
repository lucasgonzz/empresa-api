<?php

namespace App\Http\Controllers\Helpers;

use App\Models\CurrentAcountPaymentMethodDiscount;

/**
 * Precio "pagando en efectivo o transferencia" del cartel de ofertas (misión soporte-t173-m44,
 * ticket 173 de La Martina).
 *
 * El cartel no tiene su propio porcentaje: usa el MISMO descuento que el sistema aplica al cobrar
 * (Métodos de pago -> descuentos), para que el precio del cartel y el de la caja nunca se
 * desincronicen. Esta lógica vive acá y no en `ArticleOfferSheetPdf` para poder probarla sin FPDF.
 */
class ArticleOfferCashPriceHelper
{
    /**
     * Nombres (normalizados) con los que tiene que EMPEZAR el método de pago para contar como
     * efectivo o transferencia.
     *
     * Se identifica por nombre y no por `c_a_payment_method_type` (Efectivo y Transferencia no
     * tienen tipo: los tipos son sólo cheque, tarjeta y retención) ni por id fijo (el catálogo es
     * editable y los ids dependen del orden del seeder). Es la misma estrategia que ya usan
     * `CajaChartsHelper` y `PropuestaVentaIaHelper`.
     */
    const PREFIJOS_EFECTIVO_TRANSFERENCIA = ['efectivo', 'transferencia'];

    /**
     * Nombre de método de pago en minúsculas, sin tildes y con los espacios colapsados.
     *
     * @param  string|null  $name
     * @return string
     */
    public static function normalize_payment_method_name($name)
    {
        $name = mb_strtolower(trim((string) $name), 'UTF-8');
        $name = strtr($name, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);

        return trim(preg_replace('/\s+/', ' ', $name));
    }

    /**
     * Si el método de pago es efectivo o transferencia ("Efectivo", "EFECTIVO", "Transferencia
     * bancaria"). "Débito", "Mercado Pago" o "Crédito" no.
     *
     * @param  string|null  $name
     * @return bool
     */
    public static function is_cash_or_transfer($name)
    {
        $normalized = self::normalize_payment_method_name($name);

        foreach (self::PREFIJOS_EFECTIVO_TRANSFERENCIA as $prefix) {
            if (strpos($normalized, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Porcentaje de descuento por pagar en efectivo o transferencia del dueño, o null si no hay.
     *
     * Reglas (espejo de lo que hace el SPA al cobrar, `select_payment_methods.js`):
     * - Por cada método vale la regla MÁS NUEVA (el SPA recibe la lista ordenada por `created_at`
     *   descendente y toma la primera con `.find()`).
     * - Sólo reglas sin cuotas (`cuotas` NULL = aplica sin importar las cuotas).
     * - Reglas huérfanas (método borrado) no cuentan.
     * - Si hay efectivo y transferencia, manda el MAYOR descuento.
     * - Sólo cuenta un descuento real: 0 < porcentaje < 100. Un recargo (negativo), un cero o un
     *   100 % no dibujan recuadro.
     *
     * @param  int  $owner_id  Id del dueño (`owner_id` NULL) de las reglas.
     * @return float|null
     */
    public static function cash_discount_percentage($owner_id)
    {
        $rules = CurrentAcountPaymentMethodDiscount::where('user_id', $owner_id)
            ->whereNull('cuotas')
            ->whereHas('current_acount_payment_method')
            ->with('current_acount_payment_method')
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get();

        // Regla vigente de cada método: la primera que aparece (la más nueva).
        $current_rule_by_method = [];
        foreach ($rules as $rule) {
            $method_id = $rule->current_acount_payment_method_id;
            if (!isset($current_rule_by_method[$method_id])) {
                $current_rule_by_method[$method_id] = $rule;
            }
        }

        $best_percentage = null;
        foreach ($current_rule_by_method as $rule) {
            if (!self::is_cash_or_transfer($rule->current_acount_payment_method->name)) {
                continue;
            }

            $percentage = (float) $rule->discount_percentage;
            if ($percentage <= 0 || $percentage >= 100) {
                continue;
            }

            if (is_null($best_percentage) || $percentage > $best_percentage) {
                $best_percentage = $percentage;
            }
        }

        return $best_percentage;
    }

    /**
     * Precio con el descuento aplicado, redondeado al peso (2499 con 5 % -> 2374,05 -> 2374).
     *
     * @param  float|int|string  $final_price  Precio que imprime el cartel (lista o artículo).
     * @param  float|int|string  $percentage   Porcentaje de descuento.
     * @return float
     */
    public static function cash_price($final_price, $percentage)
    {
        return round((float) $final_price * (1 - ((float) $percentage / 100)));
    }
}
