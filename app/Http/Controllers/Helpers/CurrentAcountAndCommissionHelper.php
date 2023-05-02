<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\CommissionHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\DiscountHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use App\Models\Client;
use App\Models\Commission;
use App\Models\Commissioner;
use App\Models\CurrentAcount;
use App\Models\Discount;
use App\Models\Sale;
use App\Models\SaleType;
use App\Models\SellerCommission;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

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
            if ($this->items_en_venta == $this->totalItems()) {
                $this->proccessCurrentAcount();
            }
        }
        foreach ($this->sale->combos as $combo) {
            $this->items_en_venta++;
            $this->items_en_pagina++;
            $this->debe += SaleHelper::getTotalItem($combo);
            $this->total_combos += SaleHelper::getTotalItem($combo);
            if ($this->items_en_venta == $this->totalItems()) {
                $this->proccessCurrentAcount();
            }
        }
        foreach ($this->sale->services as $service) {
            $this->items_en_venta++;
            $this->items_en_pagina++;
            $this->debe += SaleHelper::getTotalItem($service);
            $this->total_services += SaleHelper::getTotalItem($service);
            if ($this->items_en_venta == $this->totalItems()) {
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

        
        $this->items_en_pagina = 0;
        $this->debe = 0;
    }

    function hasSaleDiscounts() {
        return !is_null($this->sale->discounts);
    }

    function createCurrentAcount() {
        Log::info('por poner debe de '.$this->debe);
        $current_acount = CurrentAcount::create([
            'detalle'     => 'Rto '.$this->sale->num,
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

    function getDetalle() {
        $detalle = 'Comision '.$this->client->name.' remito '.$this->sale->num;
        $detalle .= ' pag '.$this->pagina;
        $detalle .= ' ($'.Numbers::price($this->debe).')';
        return $detalle;
    }

    function getCreatedAt() {
        $created_at = $this->sale->created_at;
        if ($this->index) {
            $created_at->subDays($this->index);
        }
        return $created_at;
    }
}

