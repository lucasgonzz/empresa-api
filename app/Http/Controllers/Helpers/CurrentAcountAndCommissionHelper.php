<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Article;
use App\Models\Client;
use App\Models\Commission;
use App\Models\Commissioner;
use App\Models\CurrentAcount;
use App\Models\Discount;
use Carbon\Carbon;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\DiscountHelper;
use App\Http\Controllers\Helpers\CommissionHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Sale;
use Illuminate\Support\Facades\Log;
use App\Models\SaleType;

class CurrentAcountAndCommissionHelper extends Controller {

    function __construct($sale, $discounts, $surchages, $only_commissions, $index = null) {
        $this->user = UserHelper::getFullModel();
        $this->sale = $sale;
        $this->discounts = $discounts;
        $this->surchages = $surchages;
        $this->client = $sale->client;
        $this->only_commissions = $only_commissions;
        if ($index) {
            $this->index = $index;
        } else {
            $this->index = null;
        }
    }

    function detachCommissioners() {
        foreach ($this->sale->commissions as $commission) {
            $commission->delete();
        }
    }

    function attachCommissionsAndCurrentAcounts() {
        $this->items_en_pagina = 0;
        $this->items_en_venta = 0;
        $this->pagina = 0;
        $this->debe = 0;
        $this->total_articles = 0;
        $this->total_combos = 0;
        $this->total_services = 0;
        $this->debe = 0;
        foreach ($this->sale->articles as $article) {
            $this->items_en_venta++;
            $this->items_en_pagina++;
            $this->debe += SaleHelper::getTotalItem($article);
            $this->total_articles += SaleHelper::getTotalItem($article);
            if ($this->isLimitPerPage() || $this->items_en_venta == $this->totalItems()) {
                $this->proccessCurrentAcount();
            }
        }
        foreach ($this->sale->combos as $combo) {
            $this->items_en_venta++;
            $this->items_en_pagina++;
            $this->debe += SaleHelper::getTotalItem($combo);
            $this->total_combos += SaleHelper::getTotalItem($combo);
            if ($this->isLimitPerPage() || $this->items_en_venta == $this->totalItems()) {
                $this->proccessCurrentAcount();
            }
        }
        foreach ($this->sale->services as $service) {
            $this->items_en_venta++;
            $this->items_en_pagina++;
            $this->debe += SaleHelper::getTotalItem($service);
            $this->total_services += SaleHelper::getTotalItem($service);
            if ($this->isLimitPerPage() || $this->items_en_venta == $this->totalItems()) {
                $this->proccessCurrentAcount();
            }
        }
    }

    function totalItems() {
        $total_items = 0;
        $total_items += count($this->sale->articles);
        $total_items += count($this->sale->combos);
        $total_items += count($this->sale->services);
        return $total_items;
    }

    function proccessCurrentAcount() {
        $this->pagina++;
        $this->debe_sin_descuentos = $this->debe;
        $this->debe = SaleHelper::getTotalWithDiscountsAndSurchages($this->sale, $this->total_articles, $this->total_combos, $this->total_services);
        $this->createCurrentAcount();

        // -------------------
        if ($this->isSaleFromSeller()) {
            $this->commissionForSeller();
            if ($this->isSellerFromSeller() && $this->isDiscountMenosQue10()) {
                $this->commissionForSellerOwner();
            }
        } else {
            $this->commissionForPerdidas();
        }
        $this->commissionOscarFedePapi();
        // -------------------
        
        $this->items_en_pagina = 0;
        $this->debe = 0;
    }

    function isLimitPerPage() {
        if (!is_null($this->user->configuration->limit_items_in_sale_per_page) && $this->user->configuration->limit_items_in_sale_per_page == $this->items_en_pagina) {
            return true;
        }
        return false;
    }

    function hasSaleDiscounts() {
        return !is_null($this->sale->discounts);
    }

    function createCurrentAcount() {
        if (!$this->only_commissions) {
            Log::info('por poner debe de '.$this->debe);
            $current_acount = CurrentAcount::create([
                'detalle'     => 'Rto '.$this->sale->num.' pag '.$this->pagina,
                'page'        => $this->pagina,
                'debe'        => $this->debe,
                'status'      => 'sin_pagar',
                'client_id'   => $this->sale->client_id,
                'seller_id'   => $this->sale->client->seller_id,
                'sale_id'     => $this->sale->id,
                'description' => CurrentAcountHelper::getDescription($this->sale, $this->debe_sin_descuentos),
                'created_at' => $this->getCreatedAt(),
            ]);
            $current_acount->saldo = Numbers::redondear(CurrentAcountHelper::getSaldo('client', $this->sale->client_id, $current_acount) + $this->debe);
            $current_acount->save();
            $client = Client::find($this->sale->client_id);
            $client->saldo = $current_acount->saldo;
            $client->save();
        }
    }

    function isSaleFromSeller() {
        if (!is_null($this->client) && !is_null($this->client->seller_id)) {
            return true;
        } 
        return false;
    }

    function commissionForSeller() {
        $seller_commissioner = $this->getSellerCommissioner();
        $commission = Commission::create([
            'commissioner_id' => $seller_commissioner->id,
            'sale_id'         => $this->sale->id,
            'page'            => $this->pagina,
            'percentage'      => $this->getSellerPercentage(),
            'monto'           => $this->getSellerMonto(),
            'status'          => 'inactive',
            'detalle'         => $this->getDetalle(),
            'is_seller'       => 1,
            'updated_at'      => null,
            'created_at'      => $this->getCreatedAt(),
        ]);
    }

    function getDetalle() {
        $detalle = 'Comision '.$this->client->name.' remito '.$this->sale->num;
        $detalle .= ' pag '.$this->pagina;
        $detalle .= ' ($'.Numbers::price($this->debe).')';
        return $detalle;
    }

    function commissionForSellerOwner() {
        $seller_seller_commissioner = Commissioner::where('seller_id', $this->client->seller->seller_id)
                                            ->first();
        $commission = Commission::create([
            'commissioner_id' => $seller_seller_commissioner->id,
            'sale_id'         => $this->sale->id,
            'page'            => $this->pagina,
            'percentage'      => $this->getSellerSellerPercentage(),
            'detalle'         => $this->getDetalle(),
            'monto'           => $this->getSellerSellerMonto(),
            'status'          => 'inactive',
            'detalle'         => $this->getDetalle(),
            'is_seller'       => 0,
            'updated_at'      => null,
            'created_at' => $this->getCreatedAt(),
        ]);
    }

    function getSellerCommissioner() {
        $seller_commissioner = Commissioner::where('seller_id', $this->client->seller_id)
                                            ->first();
        return $seller_commissioner;
    }

    function getSellerPercentage() {
        $discounts_percentage = DiscountHelper::getTotalDiscountsPercentage($this->discounts);
        if (SaleHelper::isSaleType('varios', $this->sale)) {
            if ($discounts_percentage > 10) {
                return 3;
            } else {
                return 5;
            }
        } else {
            if ($discounts_percentage < 20) {
                return 10;
            } else if ($discounts_percentage < 25 || $this->tieneUn10MasUn20()) {
                return 7;
            } else if ($discounts_percentage >= 25) {
                return 5;
            }
        }
    }

    function tieneUn10MasUn20() {
        $has_10 = false;
        $has_20 = false;
        foreach ($this->discounts as $discount) {
            if ($discount->percentage == 10) {
                $has_10 = true;
            }
            if ($discount->percentage == 20) {
                $has_20 = true;
            }
        }
        return $has_10 && $has_20;
    }

    function getSellerSellerPercentage() {
        if (SaleHelper::isSaleType('varios', $this->sale)) {
            return 5;
        } else {
            return 10;
        }
    }

    function getSellerMonto() {
        $commission_seller = $this->debe * Numbers::percentage($this->getSellerPercentage());
        return Numbers::redondear($commission_seller);
    }

    function getSellerSellerMonto() {
        $total_a_restar = $this->debe;
        $total_a_restar -= $this->getSellerMonto();
        $commission_seller_owner = $total_a_restar * Numbers::percentage($this->getSellerSellerPercentage());
        return Numbers::redondear($commission_seller_owner);
    }

    function isSellerFromSeller() {
        if (!is_null($this->client->seller) && !is_null($this->client->seller->seller_id)) {
            return true;
        }
        return false;
    }

    function isDiscountMenosQue10() {
        if (DiscountHelper::getTotalDiscountsPercentage($this->discounts) < 10) {
            return true;
        }
        return false;
    }

    function commissionForPerdidas() {
        if (UserHelper::isOscar()) {
            $perdidas_commissioner = Commissioner::where('user_id', UserHelper::userId())
                                                    ->where('name', 'Perdidas')
                                                    ->first();
            $commission = Commission::create([
                'commissioner_id' => $perdidas_commissioner->id,
                'sale_id'         => $this->sale->id,
                'detalle'         => $this->getDetalle(),
                'saldo'           => CommissionHelper::getCommissionerSaldo($perdidas_commissioner) + $this->getPerdidaMonto(),
                'page'            => $this->pagina,
                'percentage'      => $this->getPerdidaPercentage(),
                'monto'           => $this->getPerdidaMonto(),
                'updated_at'      => null,
                'created_at' => $this->getCreatedAt(),
            ]);
        }
    }

    function getPerdidaPercentage() {
        $discounts_percentage = DiscountHelper::getTotalDiscountsPercentage($this->discounts);
        if ($discounts_percentage < 30) {
            return 5;
        } else {
            return 2;
        } 
    }

    function getPerdidaMonto() {
        $commission_perdidas = $this->debe * Numbers::percentage($this->getPerdidaPercentage());
        return Numbers::redondear($commission_perdidas);
    }

    function commissionOscarFedePapi() {
        if (UserHelper::isOscar()) {
            $commissioners = Commissioner::where('user_id', UserHelper::userId())
                                        ->where('name', '!=', 'Perdidas')
                                        ->whereNull('seller_id')
                                        ->get();
            foreach ($commissioners as $commissioner) {
                $commission = Commission::create([
                    'commissioner_id' => $commissioner->id,
                    'sale_id'         => $this->sale->id,
                    'saldo'           => CommissionHelper::getCommissionerSaldo($commissioner) + $this->getMontoForOscarFedePapi($commissioner),
                    'detalle'         => $this->getDetalle(),
                    'page'            => $this->pagina,
                    'percentage'      => $commissioner->percentage,
                    'monto'           => $this->getMontoForOscarFedePapi($commissioner),
                    'updated_at'      => null,
                    'created_at' => $this->getCreatedAt(),
                ]);
            }
        }
    }

    function getMontoForOscarFedePapi($commissioner) {
        $total_a_restar = $this->debe;
        if ($this->isSellerFromSeller() && $this->isDiscountMenosQue10()) {
            $total_a_restar -= $this->getSellerMonto();
        } 
        $commission = $total_a_restar * Numbers::percentage($commissioner->percentage);
        return Numbers::redondear($commission);
    }

    function getCreatedAt() {
        // $created_at = Carbon::now();
        $created_at = $this->sale->created_at;
        if ($this->pagina > 1) {
            $created_at = $this->sale->created_at->addSeconds($this->pagina);
        }
        if ($this->index) {
            $created_at->subDays($this->index);
        }
        return $created_at;
    }
}

