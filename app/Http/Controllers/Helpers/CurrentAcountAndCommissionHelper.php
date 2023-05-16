<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\SellerCommissionHelper;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CurrentAcountAndCommissionHelper extends Controller {

    public $sale;
    public $created_current_acount;

    function __construct($sale, $index = null) {
        $this->sale = $sale;
        $this->index = $index;
    }

    function attachCommissionsAndCurrentAcounts() {
        $this->items_en_pagina = 0;
        $this->items_en_venta = 0;
        $this->debe = 0;
        $this->total_articles = 0;
        $this->total_combos = 0;
        $this->total_services = 0;
        $this->debe = 0;
        foreach ($this->sale->articles as $article) {
            $this->items_en_venta++;
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
        $this->debe_sin_descuentos = $this->debe;
        $this->debe = SaleHelper::getTotalWithDiscountsAndSurchages($this->sale, $this->total_articles, $this->total_combos, $this->total_services);
        $this->createCurrentAcount();
        $this->updateClientSaldo();
        SellerCommissionHelper::commissionForSeller($this->created_current_acount);
    }

    function createCurrentAcount() {
        $this->created_current_acount = CurrentAcount::create([
            'detalle'     => 'Venta N°'.$this->sale->num,
            'debe'        => $this->debe,
            'status'      => 'sin_pagar',
            'client_id'   => $this->sale->client_id,
            'seller_id'   => $this->sale->client->seller_id,
            'sale_id'     => $this->sale->id,
            'description' => CurrentAcountHelper::getDescription($this->sale, $this->debe_sin_descuentos),
            'created_at' => $this->getCreatedAt(),
        ]);
        $this->created_current_acount->saldo = Numbers::redondear(CurrentAcountHelper::getSaldo('client', $this->sale->client_id, $this->created_current_acount) + $this->debe);
        $this->created_current_acount->save();
    }

    function updateClientSaldo() {
        $client = Client::find($this->sale->client_id);
        $client->saldo = $this->created_current_acount->saldo;
        $client->save();
    }

    function getCreatedAt() {
        $created_at = $this->sale->created_at;
        if ($this->index) {
            $created_at->subDays($this->index);
        }
        return $created_at;
    }
}

