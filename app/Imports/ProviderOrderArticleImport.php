<?php

namespace App\Imports;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Helpers\providerOrder\NewProviderOrderHelper;
use App\Models\Article;
use App\Models\Iva;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

class ProviderOrderArticleImport implements ToCollection
{

    public function __construct($columns, $start_row, $finish_row, $user, $provider_order) {

        $this->columns = $columns;
        $this->start_row = $start_row;
        $this->finish_row = $finish_row;
        $this->user = $user;
        $this->provider_order = $provider_order;

        $this->articles = [];

        // $this->ct = new Controller();

        $this->trabajo_terminado = false;
    }

    public function collection(Collection $rows)
    {

        $num_row = 1;

        foreach ($rows as $row) {
            
            if (
                $num_row >= $this->start_row
                && $num_row <= $this->finish_row
            ) {

                $article = $this->get_article($row);

                $this->add_article($article, $row);
            }    

            $num_row++;

        }

        $ya_se_actualizo_stock = $this->provider_order->update_stock;

        $helper = new NewProviderOrderHelper($this->provider_order, $this->articles, $ya_se_actualizo_stock);
        $helper->procesar_pedido();

    }

    function add_article($article, $row) {

        $amount = ImportHelper::getColumnValue($row, 'cantidad', $this->columns);
        $cost = ImportHelper::getColumnValue($row, 'costo', $this->columns);
        $notes = ImportHelper::getColumnValue($row, 'notas', $this->columns);
        $price = ImportHelper::getColumnValue($row, 'precio', $this->columns);
        $discount = ImportHelper::getColumnValue($row, 'descuento', $this->columns);
        $iva_id = $this->get_iva_id($row);
        $cost_in_dollars = ImportHelper::getColumnValue($row, 'costo_en_dolares', $this->columns);

        $this->articles[] = [
            'id'    => $article->id,
            'status'    => $article->status,
            'bar_code'    => $article->bar_code,
            'provider_code'    => $article->provider_code,
            'pivot' => [
                'amount'                => $amount,
                'cost'                  => $cost,
                'notes'                 => $notes,
                'price'                 => $price,
                'discount'              => $discount,
                'iva_id'                => $iva_id,
                'cost_in_dollars'       => $cost_in_dollars,
                'update_provider'       => 1,
            ],
        ];

    }

    function get_iva_id($row) {
        $iva_id = null;
        
        $iva = ImportHelper::getColumnValue($row, 'iva', $this->columns);
        
        if ($iva) {
            $iva_model = Iva::where('percentage', $iva)
                                ->first();
            
            if ($iva_model) {

                $iva_id = $iva_model->id;
            }
        }

        return $iva_id;
    }

    function get_article($row) {

        $bar_code = ImportHelper::getColumnValue($row, 'codigo_de_barras', $this->columns);
        $provider_code = ImportHelper::getColumnValue($row, 'codigo_de_proveedor', $this->columns);
        $name = ImportHelper::getColumnValue($row, 'nombre', $this->columns);

        $article = Article::where('user_id', $this->user->id);
        
        if (!is_null($bar_code)) {
        
            $article->where('bar_code', $bar_code);
        
        } else if (!is_null($provider_code)) {

            $article->where('provider_code', $provider_code);
        
        } else if (!is_null($name)) {

            $article->where('name', $name);
        
        }
        
        $article = $article->first();

        if (is_null($article)) {

            $article = Article::create([
                'bar_code'          => $bar_code,
                'provider_code'     => $provider_code,
                'name'              => $name,
                'provider_id'       => $this->provider_order->provider_id,
                'status'            => 'inactive',
                'user_id'           => $this->user->id,
            ]);
        
        }

        return $article;
    }
}
