<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\ProviderPriceList;
use Illuminate\Http\Request;

class ProviderPriceListController extends Controller
{

    public function index() {
        $models = ProviderPriceList::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = ProviderPriceList::create([
            'num'                   => $this->num('provider_price_lists', null, 'provider_id', $request->model_id),
            'name'                  => $request->name,
            'percentage'            => $request->percentage,
            'provider_id'           => $request->model_id,
            'temporal_id'           => $this->getTemporalId($request),
            // 'user_id'               => $this->userId(),
        ]);
        if (!is_null($request->model_id)) {
            $this->sendAddModelNotification('provider', $request->model_id);
        }
        return response()->json(['model' => $this->fullModel('ProviderPriceList', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ProviderPriceList', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ProviderPriceList::find($id);
        $last_percentage            = $model->percentage;
        $model->name                = $request->name;
        $model->percentage          = $request->percentage;
        $model->provider_id         = $this->get_model_id($request, 'provider_id');
        $model->save();
        GeneralHelper::checkNewValuesForArticlesPrices($this, $last_percentage, $model->percentage, 'provider_id', $model->provider_id);
        $this->sendAddModelNotification('provider_price_list', $model->id);
        return response()->json(['model' => $this->fullModel('ProviderPriceList', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ProviderPriceList::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('provider_price_list', $model->id);
        return response(null);
    }
}
