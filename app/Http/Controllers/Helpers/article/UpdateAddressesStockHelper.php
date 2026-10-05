<?php

namespace App\Http\Controllers\Helpers\article;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\address\SucursalVigenteHelper;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Article;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class UpdateAddressesStockHelper {

    function __construct($article_id, $addresses) {

        $this->article = Article::find($article_id);

        $this->addresses = $addresses;
    }

    function update_addresses() {
        
        $segundos = 0;

        foreach ($this->addresses as $address) {

            // Una sucursal que ya no existe se saltea (ver sucursal_viva()).
            if (!$this->sucursal_viva($address)) {
                continue;
            }

            $this->address = $address;

            $article_address = $this->get_article_address();

            if (!is_null($article_address)) {

                $diferencia = (float)$address['pivot']['amount'] - $article_address->pivot->amount;
            } else {
                $diferencia = (float)$address['pivot']['amount'];
            }

            if (
                $diferencia != ''
                && $diferencia != 0
            ) {
                
                $this->guardar_stock_movement($diferencia, $segundos);

                $segundos += 5;
            }
        }
    }

    function set_stock_min_max() {
        foreach ($this->addresses as $address_data) {

            // Idem update_addresses(): el mínimo y el máximo tampoco le abren fila a una sucursal muerta.
            if (!$this->sucursal_viva($address_data)) {
                continue;
            }

            $address_id = $address_data['id'];
            $stock_min = isset($address_data['pivot']['stock_min']) ? $address_data['pivot']['stock_min'] : null;
            $stock_max = isset($address_data['pivot']['stock_max']) ? $address_data['pivot']['stock_max'] : null;

            if (
                is_null($stock_min)
                && is_null($stock_max)
            ) {
                continue; // si no se pasa valor, no hace nada
            }

            if ($this->article->addresses()->where('address_id', $address_id)->exists()) {
                $this->article->addresses()->updateExistingPivot($address_id, [
                    'stock_min' => $stock_min,
                    'stock_max' => $stock_max,
                ]);
            } else {
                $this->article->addresses()->attach($address_id, [
                    'stock_min' => $stock_min,
                    'stock_max' => $stock_max,
                    // 'amount' => 0, // por si es requerido por la DB
                ]);
            }
        }
    }

    function guardar_stock_movement($amount, $segundos) {

        $ct_stock_movement = new StockMovementController();

        $data = [];

        $data['model_id'] = $this->article->id;

        $data['amount'] = $amount;

        $data['to_address_id'] = $this->address['id'];

        // $data['employee_id'] = UserHelper::user(false)->id;

        $article_address = $this->get_article_address();
        
        if (is_null($article_address)) {

            $data['concepto_stock_movement_name'] = 'Creacion de deposito';
        }  else {

            $data['concepto_stock_movement_name'] = 'Actualizacion de deposito';
        }

        
        $ct_stock_movement->crear($data, false, null, null, $segundos);
    }

    /**
     * ¿La sucursal del renglón sigue existiendo para el comercio del artículo? (misión
     * eliminar-sucursal-con-stock, 5/10/2026).
     *
     * La SPA arma la lista de sucursales de su store, y una pestaña abierta desde antes de que
     * alguien borrara una sucursal la sigue mandando. Sin esta guarda, `attach()` le abría al
     * artículo una fila para la sucursal borrada (stock fantasma). Se SALTEA sin error a propósito:
     * el resto de las sucursales del mismo guardado son válidas y el usuario no puede hacer nada con
     * un 422 por una fila que ni ve.
     *
     * @param  array  $address  Renglón del request (`id`, `pivot`).
     * @return bool
     */
    function sucursal_viva($address) {

        $address_id = isset($address['id']) ? $address['id'] : null;

        if (SucursalVigenteHelper::existe($address_id, $this->article->user_id)) {
            return true;
        }

        Log::warning('UpdateAddressesStockHelper: se saltea la sucursal '.$address_id.' del artículo '.$this->article->id.': ya no existe.');

        return false;
    }

    function get_article_address() {

        $article_address = null;

        foreach ($this->article->addresses as $address) {
            
            if ($address->id == $this->address['id']) {

                $article_address = $address;
            }
        }

        return $article_address;
    }
}