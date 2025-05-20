<?php

namespace App\Http\Controllers\Helpers\article;

use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Article;
use Carbon\Carbon;

class BarCodeAutomaticoHelper {

    static function set_bar_code($model) {

        if (UserHelper::hasExtencion('codigos_de_barra_por_defecto')) {

            if (
                is_null($model->bar_code)
                || $model->bar_code == ''
            ) {
                $model->bar_code = $model->id;
                $model->save();
            }
        }
    }

}