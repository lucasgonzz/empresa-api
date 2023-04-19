<?php

namespace App\Imports;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\IvaHelper;
use App\Http\Controllers\Helpers\LocalImportHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\getIva;
use App\Http\Controllers\update;
use App\Models\Article;
use App\Models\ImportHistory;
use App\Models\Provider;
use Carbon\Carbon; 
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ArticleImport implements ToCollection
{
    
    public function __construct($columns, $create_and_edit, $start_row, $finish_row, $provider_id) {
        $this->columns = $columns;
        $this->create_and_edit = $create_and_edit;
        $this->start_row = $start_row;
        $this->finish_row = $finish_row;
        $this->ct = new Controller();
        $this->provider_id = $provider_id;
        $this->provider = null;
        $this->created_models = 0;
        $this->updated_models = 0;
    }

    function checkRow($row) {
        return !is_null(ImportHelper::getColumnValue($row, 'nombre', $this->columns));
    }

    public function collection(Collection $rows) {
        $this->num_row = 1;
        if (is_null($this->finish_row) || $this->finish_row == '') {
            $this->finish_row = count($rows);
        } 
        foreach ($rows as $row) {
            if ($this->num_row >= $this->start_row && $this->num_row <= $this->finish_row) {
                if ($this->checkRow($row)) {
                    if (!is_null(ImportHelper::getColumnValue($row, 'numero', $this->columns))) {
                        $article = Article::where('user_id', UserHelper::userId())
                                            ->where('num', ImportHelper::getColumnValue($row, 'numero', $this->columns))
                                            ->where('status', 'active')
                                            ->first();
                        $this->saveArticle($row, $article);
                    } else if (!is_null(ImportHelper::getColumnValue($row, 'codigo_de_barras', $this->columns))) {
                        $article = Article::where('user_id', UserHelper::userId())
                                            ->where('bar_code', ImportHelper::getColumnValue($row, 'codigo_de_barras', $this->columns))
                                            ->where('status', 'active')
                                            ->first();
                        $this->saveArticle($row, $article);
                    } else if (!is_null(ImportHelper::getColumnValue($row, 'codigo_de_proveedor', $this->columns))) {
                        $article = Article::where('user_id', UserHelper::userId())
                                            ->where('provider_code', ImportHelper::getColumnValue($row, 'codigo_de_proveedor', $this->columns))
                                            ->where('status', 'active')
                                            ->first();
                        $this->saveArticle($row, $article);
                    } else {
                        $article = Article::where('user_id', UserHelper::userId())
                                            ->whereNull('bar_code')
                                            ->whereNull('provider_code')
                                            ->where('name', ImportHelper::getColumnValue($row, 'nombre', $this->columns))
                                            ->where('status', 'active')
                                            ->first();
                        $this->saveArticle($row, $article);
                    }
                } 
            } else if ($this->num_row > $this->finish_row) {
                break;
            }
            $this->num_row++;
        }
        $this->saveImportHistory();
    }

    function saveImportHistory() {
        ImportHistory::create([
            'user_id'           => UserHelper::userId(),
            'employee_id'       => UserHelper::userId(false),
            'model_name'        => 'article',
            'created_models'    => $this->created_models,
            'updated_models'    => $this->updated_models,
        ]);
    }

    function saveArticle($row, $article) {
        $iva_id = LocalImportHelper::getIvaId(ImportHelper::getColumnValue($row, 'iva', $this->columns));
        LocalImportHelper::saveProvider(ImportHelper::getColumnValue($row, 'proveedor', $this->columns), $this->ct);
        $data = [
            'name'              => ImportHelper::getColumnValue($row, 'nombre', $this->columns),
            'bar_code'          => ImportHelper::getColumnValue($row, 'codigo_de_barras', $this->columns),
            'provider_code'     => ImportHelper::getColumnValue($row, 'codigo_de_proveedor', $this->columns),
            // 'stock'             => ImportHelper::getColumnValue($row, 'stock_actual', $this->columns),
            'stock_min'         => ImportHelper::getColumnValue($row, 'stock_minimo', $this->columns),
            'iva_id'            => $iva_id,
            'cost'              => ImportHelper::getColumnValue($row, 'costo', $this->columns),
            'cost_in_dollars'   => $this->getCostInDollars($row),
            'percentage_gain'   => ImportHelper::getColumnValue($row, 'margen_de_ganancia', $this->columns),
            'price'             => ImportHelper::getColumnValue($row, 'precio', $this->columns),
            'category_id'       => LocalImportHelper::getCategoryId(ImportHelper::getColumnValue($row, 'categoria', $this->columns), $this->ct),
            'sub_category_id'   => LocalImportHelper::getSubcategoryId(ImportHelper::getColumnValue($row, 'categoria', $this->columns), ImportHelper::getColumnValue($row, 'sub_categoria', $this->columns), $this->ct),
            // 'provider_id'       => $this->provider_id != 0 ? $this->provider_id : ImportHelper::getColumnValue($row, 'proveedor', $this->columns),
        ];
        if (!is_null(ImportHelper::getColumnValue($row, 'stock_actual', $this->columns))) {
            $data['stock'] = ImportHelper::getColumnValue($row, 'stock_actual', $this->columns);
        }
        if (!is_null($article) && $this->isDataUpdated($article, $data)) {
            $data['slug'] = ArticleHelper::slug(ImportHelper::getColumnValue($row, 'nombre', $this->columns), $article->id);
            $article->update($data);
            $this->updated_models++;
        } else if (is_null($article) && $this->create_and_edit) {
            if (!is_null(ImportHelper::getColumnValue($row, 'codigo', $this->columns))) {
                $data['num'] = ImportHelper::getColumnValue($row, 'codigo', $this->columns);
            } else {
                $data['num'] = $this->ct->num('articles');
            }
            $data['slug'] = ArticleHelper::slug(ImportHelper::getColumnValue($row, 'nombre', $this->columns));
            $data['user_id'] = UserHelper::userId();
            $data['created_at'] = Carbon::now()->subSeconds($this->finish_row - $this->num_row);
            $article = Article::create($data);
            $this->created_models++;
        } 
        $this->setDiscounts($row, $article);
        $this->setProvider($row, $article);
        ArticleHelper::setFinalPrice($article);
    }

    function isDataUpdated($article, $data) {
        return  $article->name                              != $data['name'] ||
                $article->bar_code                          != $data['bar_code'] ||
                $article->provider_code                     != $data['provider_code'] ||
                (isset($data['stock']) && $article->stock   != $data['stock']) ||
                $article->stock_min                         != $data['stock_min'] ||
                $article->iva_id                            != $data['iva_id'] ||
                $article->cost                              != $data['cost'] ||
                $article->cost_in_dollars                   != $data['cost_in_dollars'] ||
                $article->percentage_gain                   != $data['percentage_gain'] ||
                $article->price                             != $data['price'] ||
                $article->category_id                       != $data['category_id'] ||
                $article->sub_category_id                   != $data['sub_category_id'];
    }

    function isFirstRow($row) {
        return ImportHelper::getColumnValue($row, 'nombre', $this->columns) == 'Nombre';
    }

    function getCostInDollars($row) {
        if (ImportHelper::getColumnValue($row, 'moneda', $this->columns) == 'USD') {
            return 1;
        }
        return 0;
    }

    function setDiscounts($row, $article) {
        if (!is_null(ImportHelper::getColumnValue($row, 'descuentos', $this->columns))) {
            $_discounts = explode('_', ImportHelper::getColumnValue($row, 'descuentos', $this->columns));
            $discounts = [];
            foreach ($_discounts as $_discount) {
                $discount = new \stdClass;
                $discount->percentage = $_discount;
                $discounts[] = $discount;
            } 
            ArticleHelper::setDiscounts($article, $discounts);
        }
    }

    function setProvider($row, $article) {
        if ($this->provider_id != 0 || !is_null(ImportHelper::getColumnValue($row, 'proveedor', $this->columns))) {
            if ($this->provider_id != 0) {
                $provider_id = $this->provider_id;
            } else {
                $provider_id = $this->ct->getModelBy('providers', 'name', ImportHelper::getColumnValue($row, 'proveedor', $this->columns), true, 'id');
            }
            if ($article->provider_id != $provider_id) {
                $article->provider_id = $provider_id;
                $article->save();
                $article->providers()->attach($provider_id, [
                                                'amount' => ImportHelper::getColumnValue($row, 'stock_actual', $this->columns),
                                                'cost'   => ImportHelper::getColumnValue($row, 'costo', $this->columns),
                                                'price'  => ImportHelper::getColumnValue($row, 'precio', $this->columns),
                                            ]);
            }
        }
    }
}
