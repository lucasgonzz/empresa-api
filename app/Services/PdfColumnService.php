<?php

namespace App\Services;

use App\Models\PdfColumnOption;
use App\Models\PdfColumnProfile;

class PdfColumnService
{
    /**
     * Obtiene opciones activas por model_name para selector y normalización.
     */
    public static function get_options($model_name)
    {
        return PdfColumnOption::where('model_name', $model_name)
            ->where('is_active', true)
            ->orderBy('order')
            ->get();
    }

    /**
     * Opciones base fijas por model_name.
     * `name`: texto en ABM / búsquedas en el front; `label`: encabezado en el PDF.
     */
    public static function default_options($model_name)
    {
        if ($model_name === 'sale') {
            return [
                [
                    'name' => 'Índice de fila',
                    'label'                => '#',
                    'value_resolver'               => 'row_index',
                    'default_width'                => 8,
                    'allow_wrap_content'               => false
                ],

                [
                    'name'              => 'Número de artículo',
                    'label'                => 'Num',
                    'value_resolver'               => 'item_id',
                    'default_width'                => 15,
                    'allow_wrap_content'               => false
                ],

                [
                    'name'              => 'Código de barras',
                    'label'                => 'Cod. barras',
                    'value_resolver'               => 'item_bar_code',
                    'default_width'                => 30,
                    'allow_wrap_content'               => false
                ],

                [
                    'name'              => 'Código de proveedor',
                    'label'                => 'Cod. prov',
                    'value_resolver'               => 'item_provider_code',
                    'default_width'                => 30,
                    'allow_wrap_content'               => false
                ],

                [
                    'name'              => 'Nombre del artículo',
                    'label'                => 'Nombre',
                    'value_resolver'               => 'item_name',
                    'default_width'                => 72,
                    'allow_wrap_content'               => true
                ],

                [
                    'name'              => 'Cantidad',
                    'label'                => 'Cant',
                    'value_resolver'               => 'item_amount',
                    'default_width'                => 15,
                    'allow_wrap_content'               => false
                ],

                [
                    'name'              => 'Costo',
                    'label'                => 'Costo',
                    'value_resolver'               => 'item_cost',
                    'default_width'                => 15,
                    'allow_wrap_content'               => false
                ],

                [
                    'name'              => 'Precio sin IVA',
                    'label'                => 'Pre s/IVA',
                    'value_resolver'               => 'item_price_without_iva',
                    'default_width'                => 20,
                    'allow_wrap_content'               => false
                ],

                [
                    'name'              => 'Subtotal sin IVA',
                    'label'                => 'SubT s/IVA',
                    'value_resolver'               => 'item_subtotal_without_iva',
                    'default_width'                => 25,
                    'allow_wrap_content'               => false
                ],

                [
                    'name'              => 'Importe IVA',
                    'label'                => 'Imp IVA',
                    'value_resolver'               => 'item_iva_amount',
                    'default_width'                => 20,
                    'allow_wrap_content'               => false
                ],

                [
                    'name'              => 'Total con IVA',
                    'label'                => 'Total c/IVA',
                    'value_resolver'               => 'item_subtotal_with_iva',
                    'default_width'                => 20,
                    'allow_wrap_content'               => false
                ],

                [
                    'name'              => 'Precio unitario',
                    'label'                => 'Precio',
                    'value_resolver'               => 'item_price',
                    'default_width'                => 22,
                    'allow_wrap_content'               => false
                ],

                [
                    'name'              => 'Descuento porcentaje',
                    'label'                => 'Des',
                    'value_resolver'               => 'item_discount_percentage',
                    'default_width'                => 13,
                    'allow_wrap_content'               => false
                ],

                [
                    'name'              => 'Total descuento',
                    'label'                => 'T Des',
                    'value_resolver'               => 'item_discount_total',
                    'default_width'                => 15,
                    'allow_wrap_content'               => false
                ],

                [
                    'name'              => 'Subtotal línea',
                    'label'                => 'Sub total',
                    'value_resolver'               => 'item_subtotal',
                    'default_width'                => 25,
                    'allow_wrap_content'               => false
                ],

                [
                    'name'              => 'Bonificación porcentaje',
                    'label'                => '% Bonif',
                    'value_resolver'               => 'item_discount_percentage',
                    'default_width'                => 15,
                    'allow_wrap_content'               => false
                ],

            ];
        }

        return [];
    }

    public static function visible_width($columns)
    {
        $sum = 0;
        foreach ((array) $columns as $column) {
            if (! empty($column['visible'])) {
                $sum += (int) ($column['width'] ?? 0);
            }
        }
        return $sum;
    }

    public static function get_profile_for_print($user_id, $model_name, $profile_id = null)
    {
        $query = PdfColumnProfile::where('user_id', $user_id)
            ->where('model_name', $model_name);

        if ($profile_id) {
            $profile = (clone $query)->where('id', $profile_id)->first();
            if ($profile) {
                return $profile;
            }
        }

        $default = (clone $query)->where('is_default', true)->first();
        if ($default) {
            return $default;
        }

        return (clone $query)->orderBy('id', 'asc')->first();
    }

    public static function resolve_value($resolver, $context)
    {
        $item = $context['item'] ?? null;
        $sale = $context['sale'] ?? null;
        $afip_helper = $context['afip_helper'] ?? null;
        $index = $context['index'] ?? null;
        $numbers = $context['numbers'] ?? null;
        $general_helper = $context['general_helper'] ?? null;

        if (! $item) {
            return '';
        }

        switch ($resolver) {
            case 'row_index':
                return $index;
            case 'item_id':
                return $item->id;
            case 'item_bar_code':
                return $item->bar_code ?? '';
            case 'item_provider_code':
                return $item->provider_code ?? '';
            case 'item_name':
                if ($general_helper) {
                    return $general_helper::article_name($item);
                }
                return $item->name ?? '';
            case 'item_amount':
                return isset($item->pivot->amount) ? $numbers::price($item->pivot->amount) : '';
            case 'item_cost':
                return isset($item->pivot->cost) ? $numbers::price($item->pivot->cost) : '';
            case 'item_discount_percentage':
                return isset($item->pivot->discount) ? $item->pivot->discount : '';
            case 'item_discount_total':
                if (! isset($item->pivot->discount) || ! isset($item->pivot->price) || ! isset($item->pivot->amount) || ! $item->pivot->discount) {
                    return '';
                }
                $descuento = $item->pivot->price * $item->pivot->discount / 100;
                return $numbers::price($descuento * $item->pivot->amount);
            case 'item_price':
                if (! isset($item->pivot->price)) {
                    return '';
                }
                if (isset($context['afip_ticket'])) {
                    return $numbers::price($item->pivot->price, true);
                }
                return $numbers::price($item->pivot->price, true, $sale ? $sale->moneda_id : null);
            case 'item_subtotal':
                if (! isset($item->pivot->price) || ! isset($item->pivot->amount)) {
                    return '';
                }
                $total = $item->pivot->price * $item->pivot->amount;
                if (! is_null($item->pivot->discount ?? null)) {
                    $total -= $total * ($item->pivot->discount / 100);
                }

                if (isset($context['afip_ticket'])) {
                    return $numbers::price($total, true);
                }

                return $numbers::price($total, true, $sale ? $sale->moneda_id : null);
            case 'item_price_without_iva':
                /**
                 * Prioriza valor persistido en pivot para evitar recálculo posterior.
                 */
                if (isset($item->pivot->price_sin_iva) && !is_null($item->pivot->price_sin_iva)) {
                    return $numbers::price($item->pivot->price_sin_iva, true);
                }
                if ($afip_helper && $sale) {
                    return $numbers::price($afip_helper->getArticlePrice($sale, $item), true);
                }
                if (isset($item->pivot->price)) {
                    return $numbers::price($item->pivot->price, true);
                }
                return '';
            case 'item_subtotal_without_iva':
                /**
                 * Si existe snapshot unitario sin IVA en pivot, se usa para subtotal.
                 */
                if (
                    isset($item->pivot->price_sin_iva)
                    && !is_null($item->pivot->price_sin_iva)
                    && isset($item->pivot->amount)
                ) {
                    $subtotal_sin_iva = (float) $item->pivot->price_sin_iva * (float) $item->pivot->amount;
                    return $numbers::price($subtotal_sin_iva, true, $sale ? $sale->moneda_id : null);
                }
                if ($afip_helper) {
                    return $numbers::price($afip_helper->subTotal($item), true, $sale ? $sale->moneda_id : null);
                }
                return '';
            case 'item_iva_amount':
                if ($afip_helper && isset($item->pivot->amount)) {
                    $afip_helper->article = $item;
                    $monto_iva = $afip_helper->montoIvaDelPrecio() * $item->pivot->amount;
                    return $numbers::price($monto_iva, true);
                }
                return '';
            case 'item_subtotal_with_iva':
                if ($afip_helper && isset($item->pivot->amount)) {
                    $afip_helper->article = $item;
                    $total = $afip_helper->getArticlePriceWithDiscounts() * $item->pivot->amount;
                    return '$'.$numbers::price($total);
                }
                return '';
            default:
                return '';
        }
    }
}

