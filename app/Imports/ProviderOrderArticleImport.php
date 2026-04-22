<?php

namespace App\Imports;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Helpers\providerOrder\ModoFacturacionHelper;
use App\Http\Controllers\Helpers\providerOrder\NewProviderOrderHelper;
use App\Models\Article;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;

class ProviderOrderArticleImport implements ToCollection
{

    public $columns;
    public $start_row;
    public $finish_row;
    public $user;
    public $provider_order;
    public $import_type;
    public $overwrite_articles;
    public $articles;
    public $trabajo_terminado;
    public $current_pivot_by_article_id;

    public function __construct($columns, $start_row, $finish_row, $user, $provider_order, $import_type = 'pedido', $overwrite_articles = false) {

        $this->columns            = $columns;
        $this->start_row          = $start_row;
        $this->finish_row         = $finish_row;
        $this->user               = $user;
        $this->provider_order     = $provider_order;
        $this->import_type        = $import_type;
        $this->overwrite_articles = $overwrite_articles;

        $this->articles           = [];
        $this->trabajo_terminado  = false;
        $this->current_pivot_by_article_id = [];

        $this->load_current_pivots();
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

                if (!is_null($article)) {
                    $this->add_article($article, $row);
                }
            }

            $num_row++;
        }

        $ya_se_actualizo_stock = $this->provider_order->update_stock;

        $helper = new NewProviderOrderHelper($this->provider_order, $this->articles, $ya_se_actualizo_stock);

        $helper->attach_articles($this->overwrite_articles);

        ModoFacturacionHelper::check_modo_facturacion($this->provider_order, $helper);

        $helper->procesar_pedido();
    }

    function load_current_pivots() {
        $this->provider_order->load('articles');

        foreach ($this->provider_order->articles as $article) {
            $this->current_pivot_by_article_id[$article->id] = [
                'amount'          => $article->pivot->amount,
                'received'        => $article->pivot->received,
                'cost'            => $article->pivot->cost,
                'notes'           => $article->pivot->notes,
                'price'           => $article->pivot->price,
                'discount'        => $article->pivot->discount,
                'iva_id'          => $article->pivot->iva_id,
                'cost_in_dollars' => $article->pivot->cost_in_dollars,
                'amount_pedida'   => $article->pivot->amount_pedida,
                'update_provider' => $article->pivot->update_provider,
            ];
        }
    }

    function add_article($article, $row) {

        $amount   = ImportHelper::getColumnValue($row, 'cantidad', $this->columns);
        $received = ImportHelper::getColumnValue($row, 'cantidad_recibida', $this->columns);
        $cost     = ImportHelper::getColumnValue($row, 'costo', $this->columns);
        $notes    = ImportHelper::getColumnValue($row, 'notas', $this->columns);

        // $current = $this->get_current_pivot($article->id);
        // $model_defaults = $this->get_model_defaults($article, $current);

        // $final_amount = $this->import_type === 'recibido'
        //     ? $current['amount']
        //     : $amount;

        // $final_received = $this->import_type === 'recibido'
        //     ? $received
        //     : $current['received'];

        // // Solo se toma del excel: amount/received, cost y notes.
        // // Si cost o notes vienen vacios en excel, se guardan vacios en pivot.
        // $final_cost = $cost;
        // $final_notes = $notes;

        $this->articles[] = [
            'id'           => $article->id,
            'status'       => $article->status,
            'bar_code'     => $article->bar_code,
            'provider_code' => $article->provider_code,
            'pivot' => [
                'amount'          => $amount,
                'received'        => $received,
                'cost'            => $cost,
                'notes'           => $notes,
                'price'           => $article->price,
                'iva_id'          => $article->iva_id,
                'cost_in_dollars' => $article->cost_in_dollars,
                // 'amount_pedida'   => $current['amount_pedida'],
                'update_provider' => 0,
            ],
        ];
    }

    function get_model_defaults($article, $current) {
        return [
            'price' => $this->get_model_value_or_fallback($article, 'price', $current['price']),
            'discount' => $this->get_model_value_or_fallback($article, 'discount', $current['discount']),
            'iva_id' => $this->get_model_value_or_fallback($article, 'iva_id', $current['iva_id']),
            'cost_in_dollars' => $this->get_model_value_or_fallback($article, 'cost_in_dollars', $current['cost_in_dollars']),
        ];
    }

    function get_model_value_or_fallback($article, $attribute, $fallback) {
        $value = $article->getAttribute($attribute);
        return is_null($value) ? $fallback : $value;
    }

    function get_current_pivot($article_id) {
        return $this->current_pivot_by_article_id[$article_id] ?? [
            'amount'          => null,
            'received'        => null,
            'cost'            => null,
            'notes'           => null,
            'price'           => null,
            'discount'        => null,
            'iva_id'          => null,
            'cost_in_dollars' => null,
            'amount_pedida'   => null,
            'update_provider' => 1,
        ];
    }

    function get_article($row) {

        $bar_code     = ImportHelper::getColumnValue($row, 'codigo_de_barras', $this->columns);
        $provider_code = ImportHelper::getColumnValue($row, 'codigo_de_proveedor', $this->columns);
        $name         = ImportHelper::getColumnValue($row, 'nombre', $this->columns);

        $query = Article::where('user_id', $this->user->id);

        if (!is_null($bar_code)) {

            Log::info('Buscando por bar_code: '.$bar_code);
            $query->where('bar_code', $bar_code);

        } else if (!is_null($provider_code)) {

            Log::info('Buscando por provider_code: '.$provider_code);
            $query->where('provider_code', $provider_code);

        } else if (!is_null($name)) {

            Log::info('Buscando por name: '.$name);
            $query->where('name', $name);

        }

        $article = $query->first();

        if (is_null($article)) {

            if ($this->import_type === 'recibido') {
                Log::info('No se encontro article para recibido (no se crea)');
                return null;
            }

            Log::info('No se encontro article, se crea inactivo');

            $article = Article::create([
                'bar_code'     => $bar_code,
                'provider_code' => $provider_code,
                'name'         => $name,
                'provider_id'  => $this->provider_order->provider_id,
                'status'       => 'inactive',
                'user_id'      => $this->user->id,
            ]);

        } else {
            Log::info('Se encontro article '.$article->id);
        }

        return $article;
    }
}
