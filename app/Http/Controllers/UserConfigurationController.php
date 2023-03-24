<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\UserConfiguration;
use Illuminate\Http\Request;

class UserConfigurationController extends Controller
{

    public function index() {
        $models = UserConfiguration::where('user_id', $this->userId())
                            ->withAll()
                            ->orderBy('created_at', 'DESC')
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function show($id) {
        return response()->json(['model' => $this->fullModel('UserConfiguration', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = UserConfiguration::find($id);
        $current_value = $model->iva_included;
        $model->show_articles_without_stock     = $request->show_articles_without_stock;
        $model->iva_included                    = $request->iva_included;
        $model->apply_price_type_in_services    = $request->apply_price_type_in_services;
        $model->save();
        GeneralHelper::checkNewValuesForArticlesPrices($this, $current_value, $request->iva_included);
        $this->sendAddModelNotification('configuration', $model->id);
        return response()->json(['model' => $this->fullModel('UserConfiguration', $model->id)], 200);
    }

    public function destroy($id) {
        $model = UserConfiguration::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('configuration', $model->id);
        return response(null);
    }
}
