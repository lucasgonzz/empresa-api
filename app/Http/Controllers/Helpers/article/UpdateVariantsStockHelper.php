<?php

namespace App\Http\Controllers\Helpers\article;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\address\SucursalVigenteHelper;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Article;
use App\Models\ArticleVariant;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class UpdateVariantsStockHelper {

    function __construct($article_id, $variants_to_update) {

        $this->article = Article::find($article_id);

        $this->variants_to_update = $variants_to_update;

        // Deposito que se esta procesando (null cuando se actualiza el stock GLOBAL de la variante).
        $this->address = null;
    }

    function update_variants() {

        $segundos = 0;

        foreach ($this->variants_to_update as $variant) {

            $this->variant = $variant;

            // Negocio sin sucursales: la variante manda `stock` (global) y no manda depositos.
            // Es un campo opcional: un SPA viejo nunca lo manda y sigue por el camino de depositos.
            if ($this->es_stock_global($variant)) {

                $this->actualizar_stock_global($segundos);

                $segundos += 5;

                continue;
            }

            $addresses = isset($variant['addresses']) ? $variant['addresses'] : [];

            foreach ($addresses as $address) {

                /*
                 * Una sucursal que ya no existe se saltea sin error (misión eliminar-sucursal-con-stock,
                 * 5/10/2026): una pestaña abierta desde antes del borrado la sigue mandando. Sin esto,
                 * attach_address() le abría a la variante una fila para la sucursal borrada y después
                 * get_variant_address() no la encontraba (la relación es un INNER JOIN con addresses):
                 * `$variant_address->pivot` sobre null tumbaba el guardado con un 500.
                 */
                $address_id = isset($address['id']) ? $address['id'] : null;

                if (!SucursalVigenteHelper::existe_para_stock($address_id, $this->article->user_id)) {

                    Log::warning('UpdateVariantsStockHelper: se saltea la sucursal '.$address_id.' de la variante '.$variant['id'].': ya no existe.');

                    continue;
                }

                $this->address = $address;

                $variant_address = $this->get_variant_address();

                if (is_null($variant_address)) {

                    $this->attach_address();
                    
                    $variant_address = $this->get_variant_address();
                
                } else {
                    $this->update_address();
                }

                // Log::info('diferencia de '.$variant_address->street);
                // Log::info('Antes habia '.$variant_address->pivot->amount);
                // Log::info('Y ahora llego '.$address['amount']);

                $diferencia = (float)$address['amount'] - $variant_address->pivot->amount;
                // Log::info('diferencia: '.$diferencia);

                $this->guardar_stock_movement($diferencia, $segundos);
                
                $segundos += 5;

                // sleep(1);
            }
            
        }
    }

    /**
     * Indica si el item de la lista trae el stock GLOBAL de la variante (negocio sin sucursales).
     *
     * Es global cuando viene `stock` con un valor y no viene ningun deposito. Si trae depositos
     * manda el camino de siempre (stock por deposito), aunque ademas traiga `stock`.
     *
     * @param array $variant Item de variants_to_update: {id, addresses?, stock?}.
     * @return bool
     */
    function es_stock_global($variant) {

        if (!array_key_exists('stock', $variant) || is_null($variant['stock']) || $variant['stock'] === '') {
            return false;
        }

        return empty($variant['addresses']);
    }

    /**
     * Lleva el stock global de la variante al valor pedido, generando un movimiento por la
     * diferencia (nunca se escribe `article_variants.stock` a mano: lo mueve CheckVariants).
     *
     * Si la variante ya reparte por depositos no se toca: el stock de una variante con depositos
     * es la suma de sus depositos y un movimiento global quedaria pisado por esa suma.
     *
     * @param int $segundos Segundos a sumar al created_at para que los movimientos del lote queden ordenados.
     * @return void
     */
    function actualizar_stock_global($segundos) {

        $article_variant = ArticleVariant::where('id', $this->variant['id'])
                                            ->where('article_id', $this->article->id)
                                            ->with('addresses')
                                            ->first();

        if (is_null($article_variant)) {

            Log::warning('UpdateVariantsStockHelper: la variante '.$this->variant['id'].' no pertenece al articulo '.$this->article->id.'. No se toca el stock.');

            return;
        }

        if (count($article_variant->addresses) >= 1) {

            Log::info('UpdateVariantsStockHelper: la variante '.$article_variant->id.' reparte por depositos, se ignora el stock global.');

            return;
        }

        $this->address = null;

        $diferencia = (float)$this->variant['stock'] - (float)$article_variant->stock;

        $this->guardar_stock_movement($diferencia, $segundos);
    }

    function guardar_stock_movement($amount, $segundos) {

        if ($amount != 0) {

            $ct_stock_movement = new StockMovementController();

            $data = [];

            $data['model_id'] = $this->article->id;

            $data['article_variant_id'] = $this->variant['id'];

            $data['amount'] = $amount;

            // Sin deposito (stock global de la variante) el movimiento no lleva to_address_id.
            if (!is_null($this->address)) {
                $data['to_address_id'] = $this->address['id'];
            }

            // $data['employee_id'] = UserHelper::user(false)->id;

            $data['concepto_stock_movement_name'] = 'Actualizacion de deposito';

            Log::info('*************');
            if (!is_null($this->address)) {
                Log::info('Act de depositos para address '.$this->address['id'].' con '.$amount);
            } else {
                Log::info('Act de stock global de la variante '.$this->variant['id'].' con '.$amount);
            }

            $ct_stock_movement->crear($data, false, null, null, $segundos);
            Log::info('*************');
        }

    }

    function get_variant_address() {

        $result = null;

        foreach ($this->article->article_variants as $article_variant) {

            if ($article_variant->id == $this->variant['id']) {

                $this->article_variant = $article_variant;

                foreach ($article_variant->addresses as $variant_address) {

                    if ($variant_address->id == $this->address['id']) {

                        $result = $variant_address;
                    }
                }

            }
        }

        return $result;
    }

    function attach_address() {

        Log::info('No tenia stock en la direccion, se va a crear');

        $this->article_variant->addresses()->attach($this->address['id'], [
            'amount'        => 0,
            'on_display'    => $this->address['on_display']
        ]);

        $this->article->load('article_variants');
    }

    function update_address() {

        $this->article_variant->addresses()->updateExistingPivot($this->address['id'], [
            'on_display'    => $this->address['on_display']
        ]);

        $this->article->load('article_variants');
    }
}