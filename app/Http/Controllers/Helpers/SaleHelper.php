<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\AfipWsController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CommissionController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\CurrentAcountController;
use App\Http\Controllers\Helpers\Afip\AfipNotaCreditoHelper;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CurrentAcountAndCommissionHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\DiscountHelper;
use App\Http\Controllers\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SellerCommissionController;
use App\Models\Article;
use App\Models\Client;
use App\Models\Commissioner;
use App\Models\CurrentAcount;
use App\Models\Discount;
use App\Models\Sale;
use App\Models\SaleType;
use App\Models\SellerCommission;
use App\Models\Service;
use App\Models\Variant;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SaleHelper extends Controller {

    static function updatePreivusClient($sale, $previus_client_id) {
        if (!is_null($sale->client_id) && $sale->client_id != $previus_client_id) {
            CurrentAcountHelper::checkSaldos('client', $previus_client_id);
        }
    }

    static function sendUpdateClient($instance, $sale) {
        if (!is_null($sale->client_id)) {
            $instance->sendAddModelNotification('Client', $sale->client_id);
        }
    }

    static function deleteSaleFrom($model_name, $model_id, $instance) {
        $sale = Sale::where($model_name.'_id', $model_id)
                        ->first();
        if (!is_null($sale)) {
            $sale->delete();
            $instance->sendDeleteModelNotification('sale', $sale->id, false);
        }
    }

    static function getEmployeeId($request = null) {
        if (!is_null($request) && $request->employee_id != 0) {
            return $request->employee_id;
        }
        $user = Auth()->user();
        if (!is_null($user->owner_id)) {
            return $user->id;
        }
        return null;
    }

    static function getCurrentAcountPaymentMethodId($request) {
        if (is_null($request->client_id)) {
            return $request->current_acount_payment_method_id;
        }
        return null;
    }

    static function saveAfipTicket($sale) {
        if (!is_null($sale->afip_information_id) && $sale->afip_information_id != 0) {
            $ct = new AfipWsController($sale);
            $afip_ticket = $ct->init();
            return $afip_ticket;
        } 
    }

    static function getSelectedAddress($request) {
        return !is_null($request->selected_address) ? $request->selected_address['id'] : null;
    }

    static function getNumSaleFromSaleId($sale_id) {
        $sale = Sale::where('id', $sale_id)
                    ->select('num')
                    ->first();
        if ($sale) {
            return $sale->num;
        }
        return null;
    }

    static function attachProperies($model, $request, $from_store = true) {
        Self::attachArticles($model, $request->items, $from_store);
        Self::attachCombos($model, $request->items);
        Self::attachServices($model, $request->items);
        Self::attachDiscounts($model, $request->discounts_id);
        Self::attachSurchages($model, $request->surchages_id);
        if ($from_store) {
            Self::attachCurrentAcountsAndCommissions($model);
            $afip_ticket = Self::saveAfipTicket($model);
        } else {
            Self::checkNotaCredito($model, $request);
        }
    }

    static function checkNotaCredito($sale, $request) {
        if ($request->save_nota_credito) {
            $haber = 0;
            foreach ($request->returned_articles as $article) {
                $total_item = (float)$article['price_vender'] * (float)$article['returned_amount'];
                if (!is_null($article['discount']) && $article['discount'] != 0) {
                    $total_item -= $total_item * $article['discount'] / 100;
                }
                Log::info('se agrego a la nota de credito '.$article['returned_amount'].' unidades de '.$article['name']);
                $haber += $total_item;
            }
            Log::info('El total quedo en '.$haber);
            if (count($sale->discounts) >= 1) {
                foreach ($sale->discounts as $discount) {
                    $haber -= (float)$discount->pivot->percentage * $haber / 100;
                }
            }
            if (count($sale->surchages) >= 1) {
                foreach ($sale->surchages as $surchage) {
                    $haber += (float)$surchage->pivot->percentage * $haber / 100;
                }
            }
            $nota_credito = CurrentAcountHelper::notaCredito($haber, $request->nota_credito_description, 'client', $request->client_id, $sale->id, $request->returned_articles);
            CurrentAcountHelper::checkSaldos('client', $request->client_id);

            $ct = new Controller();
            $ct->sendAddModelNotification('client', $request->client_id);
            if (!is_null($sale->afip_ticket)) {
                $afip_helper = new AfipNotaCreditoHelper($sale, $nota_credito);
                $afip_helper->init();
            }
        }
    }

    static function createNotaCreditoFromDestroy($sale) {
        $haber = 0;
        $articles = [];
        foreach ($sale->articles as $article) {
            $haber += $article->pivot->price * $article->pivot->amount;
            $article->returned_amount = $article->pivot->amount;
            $articles[] = $article;
        }
        if (count($sale->discounts) >= 1) {
            foreach ($sale->discounts as $discount) {
                $haber -= (float)$discount->pivot->percentage * $haber / 100;
            }
        }
        if (count($sale->surchages) >= 1) {
            foreach ($sale->surchages as $surchage) {
                $haber += (float)$surchage->pivot->percentage * $haber / 100;
            }
        }
        $description = 'Venta N°'.$sale->num.' eliminada';
        $nota_credito = CurrentAcountHelper::notaCredito($haber, $description, 'client', $sale->client_id, $sale->id, $articles);
        if (!is_null($sale->client)) {
            CurrentAcountHelper::checkSaldos('client', $sale->client_id);
        }
        $afip_helper = new AfipNotaCreditoHelper($sale, $nota_credito);
        $afip_helper->init();
    }

    static function attachDiscounts($sale, $discounts_id) {
        $sale->discounts()->detach();
        $discounts = GeneralHelper::getModelsFromId('Discount', $discounts_id);
        foreach ($discounts as $discount) {
            $sale->discounts()->attach($discount['id'], [
                'percentage' => $discount['percentage'],
            ]);
        }
    }

    static function attachSurchages($sale, $surchages_id) {
        $sale->surchages()->detach();
        $surchages = GeneralHelper::getModelsFromId('Surchage', $surchages_id);
        foreach ($surchages as $surchage) {
            $sale->surchages()->attach($surchage['id'], [
                'percentage' => $surchage['percentage']
            ]);
        }
    }

    static function attachCurrentAcountsAndCommissions($sale) {
        if (!is_null($sale->client_id) && $sale->save_current_acount) {
            $helper = new CurrentAcountAndCommissionHelper($sale);
            $helper->attachCommissionsAndCurrentAcounts();

            // CurrentAcountHelper::checkSaldos('client', $sale->client_id);
        }
    }

    static function attachArticles($sale, $articles, $from_store) {
        if (!$from_store) {
            // Log::info('Actualizado venta id: '.$sale->id.' num: '.$sale->num);
            // Log::info('Llegaron estos articulos');
            // Log::info($articles);
        }
        foreach ($articles as $article) {
            if (isset($article['is_article'])) {
                $sale->articles()->attach($article['id'], [
                                                            'amount'            => (float)$article['amount'],
                                                            'cost'              => Self::getCost($article),
                                                            'price'             => $article['price_vender'],
                                                            'returned_amount'   => Self::getReturnedAmount($article),
                                                            'delivered_amount'   => Self::getDeliveredAmount($article),
                                                            'discount'          => Self::getDiscount($article),
                                                            'created_at'        => Carbon::now(),
                                                        ]);
                ArticleHelper::discountStock($article['id'], $article['amount'], $sale);
                ArticleHelper::setArticleStockFromAddresses($article);
            }
        }
    }

    static function updateItemsPrices($sale, $items) {
        foreach ($items as $item) {
            if (isset($item['is_article']) && $item['price_vender'] != '') {
                $sale->articles()->updateExistingPivot($item['id'], [
                                                        'price' => $item['price_vender'],
                                                    ]);
            } else if (isset($item['is_service']) && $item['price_vender'] != '') {
                $service = Service::find($item['id']);
                $service->price = $item['price_vender'];
                $service->save();
                $sale->services()->updateExistingPivot($item['id'], [
                                                        'price' => $item['price_vender'],
                                                    ]);
            }
        }
    }

    static function attachCombos($sale, $combos) {
        foreach ($combos as $combo) {
            if (isset($combo['is_combo'])) {
                $sale->combos()->attach($combo['id'], [
                                                            'amount' => (float)$combo['amount'],
                                                            'price' => $combo['price'],
                                                            'created_at' => Carbon::now(),
                                                        ]);
            }
        }
    }

    static function attachServices($sale, $services) {
        foreach ($services as $service) {
            if (isset($service['is_service'])) {
                $sale->services()->attach($service['id'], [
                    'price' => $service['price_vender'],
                    'amount' => $service['amount'],
                    'discount' => Self::getDiscount($service),
                ]);
            }
        }
    }

    static function updateCurrentAcountsAndCommissions($sale) {
        Self::deleteCurrentAcountFromSale($sale);
        Self::deleteSellerCommissionsFromSale($sale);

        $helper = new CurrentAcountAndCommissionHelper($sale);
        $helper->attachCommissionsAndCurrentAcounts();

        $sale->client->pagos_checkeados = 0;
        $sale->client->save();

        CurrentAcountHelper::checkSaldos('client', $sale->client_id);

    }

    static function deleteCurrentAcountFromSale($sale) {
        $current_acount = CurrentAcount::where('sale_id', $sale->id)
                                        ->whereNull('haber')
                                        ->first();
        if (!is_null($current_acount)) {
            $current_acount->pagado_por()->detach();
            $current_acount->delete();
        }
    }

    static function deleteSellerCommissionsFromSale($sale) {
        $seller_commissions = SellerCommission::where('sale_id', $sale->id)
                                            ->whereNull('haber')
                                            ->pluck('id');
        SellerCommission::destroy($seller_commissions);
    }

    static function getDiscount($item) {
        if (isset($item['discount'])) {
            return $item['discount'];
        }
        return null;
    }

    static function getReturnedAmount($item) {
        if (isset($item['returned_amount'])) {
            return $item['returned_amount'];
        }
        return null;
    }

    static function getDeliveredAmount($item) {
        if (isset($item['delivered_amount'])) {
            return $item['delivered_amount'];
        }
        return null;
    }

    static function getCost($item) {
        if (isset($item['cost'])) {
            return $item['cost'];
        }
        return null;
    }

    static function getDolar($article, $dolar_blue) {
        if (isset($article['with_dolar']) && $article['with_dolar']) {
            return $dolar_blue;
        }
        return null;
    }

    static function attachArticlesFromOrder($sale, $articles) {
        foreach ($articles as $article) {
            $sale->articles()->attach($article->id, [
                                            'amount' => $article->pivot->amount,
                                            'cost' => isset($article->pivot->cost)
                                                        ? $article->pivot->cost
                                                        : null,
                                            'price' => $article->pivot->price,
                                        ]);
            
        }
    }

    static function detachItems($sale) {
        Log::info('detachItems');
        foreach ($sale->articles as $article) {
            Log::info($sale->address_id);
            if (count($article->addresses) >= 1 && !is_null($sale->address_id)) {
                Log::info('detachItems entro en addresses');
                foreach ($article->addresses as $article_address) {
                    if ($article_address->pivot->address_id == $sale->address_id) {
                        $new_amount = $article_address->pivot->amount + $article->pivot->amount;
                        Log::info('Se regreso el stock de '.$article_address->street.' a '.$new_amount);
                        $article->addresses()->updateExistingPivot($article_address->id, [
                            'amount'    => $new_amount,
                        ]);
                    }
                }
                ArticleHelper::setArticleStockFromAddresses($article);
            } else if (!is_null($article->stock)) {
                Log::info('detachItems NO entro en addresses');
                $stock = 0;
                $stock = (int)$article->pivot->amount;
                $article->stock += $stock;
                $article->save();
            }
        }

        foreach ($sale->articles as $article) {
            ArticleHelper::setArticleStockFromAddresses($article);
        }

        $sale->articles()->detach();
        $sale->combos()->detach();
        $sale->services()->detach();
    }

    static function getTotalSale($sale, $with_discount = true, $with_surchages = true, $with_seller_commissions = false) {
        $total_articles = 0;
        $total_combos = 0;
        $total_services = 0;
        foreach ($sale->articles as $article) {
            $total_articles += Self::getTotalItem($article);
        }
        foreach ($sale->combos as $combo) {
            $total_combos += Self::getTotalItem($combo);
        }
        foreach ($sale->services as $service) {
            $total_services += Self::getTotalItem($service);
        }
        if ($with_discount) {
            foreach ($sale->discounts as $discount) {
                $total_articles -= $total_articles * $discount->pivot->percentage / 100;
                $total_combos -= $total_combos * $discount->pivot->percentage / 100;
                if ($sale->discounts_in_services) {
                    $total_services -= $total_services * $discount->pivot->percentage / 100;
                }
            }
        }
        if ($with_surchages) {
            foreach ($sale->surchages as $surchage) {
                $total_articles += $total_articles * $surchage->pivot->percentage / 100;
                $total_combos += $total_combos * $surchage->pivot->percentage / 100;
                if ($sale->surchages_in_services) {
                    $total_services += $total_services * $surchage->pivot->percentage / 100;
                }
            }
        }
        $total = $total_articles + $total_services + $total_combos;
        if (!is_null($sale->percentage_card)) {
            $total += ($total * Numbers::percentage($sale->percentage_card));
        }
        if ($with_seller_commissions) {
            foreach ($sale->seller_commissions as $seller_commission) {
                $total -= $seller_commission->debe;
            }
        }
        return $total;
    }

    static function getTotalItem($item) {
        $total = $item->pivot->price * $item->pivot->amount;
        if (!is_null($item->pivot->discount)) {
            $total -= $total * ($item->pivot->discount / 100);
        }
        return $total;
    }

    static function getTotalSaleFromArticles($sale, $articles) {
        $total = 0;
        foreach ($articles as $article) {
            if (!is_null($sale->percentage_card)) {
                $total += ($article->pivot->price * Numbers::percentage($sale->percentage_card)) * $article->pivot->amount;
            } else {
                $total += $article->pivot->price * $article->pivot->amount;
            }
        }
        return $total;
    }

    static function getTotalCostSale($sale) {
        $total = 0;
        foreach ($sale->articles as $article) {
            if (!is_null($article->pivot->cost)) {
                $total += $article->pivot->cost * $article->pivot->amount;
            }
        }
        return $total;
    }

    static function isSaleType($sale_type_name, $sale) {
        $sale_type = SaleType::where('user_id', UserHelper::userId())
                                    ->where('name', $sale_type_name)
                                    ->first();
        if (!is_null($sale_type) && $sale->sale_type_id == $sale_type->id) {
            return true;
        } 
        return false;
    }

    static function getPrecioConDescuento($sale) {
        // $discount = DiscountHelper::getTotalDiscountsPercentage($sale->discounts, true);
        $total = Self::getTotalSale($sale);
        foreach ($sale->discounts as $discount) {
            $total -= $total * Numbers::percentage($discount->pivot->percentage); 
        }
        return $total;
        // return Self::getTotalSale($sale) - (Self::getTotalSale($sale) * Numbers::percentage($discount));
    }

    static function getPrecioConDescuentoFromArticles($sale, $articles) {
        $discount = DiscountHelper::getTotalDiscountsPercentage($sale->discounts, true);
        $total = 0;
        foreach ($articles as $article) {
            if (!is_null($sale->percentage_card)) {
                $total += ($article->pivot->price * Numbers::percentage($sale->percentage_card)) * $article->pivot->amount;
            } else {
                $total += $article->pivot->price * $article->pivot->amount;
            }
        }
        return $total - ($total * Numbers::percentage($discount));
    }

    static function getTotalWithDiscountsAndSurchages($sale, $total_articles, $total_combos, $total_services) {
        foreach ($sale->discounts as $discount) {
            // Log::info('total_services: '.$total_services);
            if ($sale->discounts_in_services) {
                // Log::info('restando '.$total_services * Numbers::percentage($discount->pivot->percentage).' a los servicios');
                $total_services -= $total_services * Numbers::percentage($discount->pivot->percentage);
            } else {
                // Log::info('No se resto a los servicios');
            }
            // Log::info('total_services quedo en: '.$total_services);

            // Log::info('------------------------------------');
            // Log::info('total_articles: '.$total_articles);
            $total_articles -= $total_articles * Numbers::percentage($discount->pivot->percentage);
            // Log::info('total_articles quedo en: '.$total_articles);

            // Log::info('------------------------------------');
            // Log::info('total_combos: '.$total_combos);
            $total_combos -= $total_combos * Numbers::percentage($discount->pivot->percentage);
            // Log::info('total_combos quedo en: '.$total_combos);
        }
        foreach ($sale->surchages as $surchage) {
            if ($sale->surchages_in_services) {
                $total_services += $total_services * Numbers::percentage($surchage->pivot->percentage);
            }
            $total_articles += $total_articles * Numbers::percentage($surchage->pivot->percentage);
            $total_combos += $total_combos * Numbers::percentage($surchage->pivot->percentage);
        }
        if (!is_null($sale->order) && !is_null($sale->order->cupon)) {
            if (!is_null($sale->order->cupon->percentage)) {
                $total -= $total * $sale->order->cupon->percentage / 100;
            } else if (!is_null($sale->order->cupon->amount)) {
                $total -= $sale->order->cupon->amount;
            }
        }
        $total = $total_articles + $total_combos + $total_services;
        Log::info('------------------------------------');
        Log::info('retornando '.$total);

        return $total;
    }

    static function getTotalMenosDescuentos($sale, $total) {
        foreach ($sale->discounts as $discount) {
            $total -= $total * Numbers::percentage($discount->pivot->percentage);
        }
        return $total;
    }

}

