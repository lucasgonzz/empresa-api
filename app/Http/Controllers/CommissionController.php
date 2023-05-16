<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\Commission;
use Illuminate\Http\Request;

class CommissionController extends Controller
{

    public function index() {
        $models = Commission::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Commission::create([
            'num'                   => $this->num('commissions'),
            'from'                  => $request->from,
            'until'                 => $request->until,
            'sale_type_id'          => $request->sale_type_id,
            'percentage'            => $request->percentage,
            'user_id'               => $this->userId(),
        ]);
        GeneralHelper::attachModels($model, 'for_all_sellers', $request->for_all_sellers, ['percentage']);
        GeneralHelper::attachModels($model, 'for_only_sellers', $request->for_only_sellers, []);
        GeneralHelper::attachModels($model, 'except_sellers', $request->except_sellers, []);
        $this->sendAddModelNotification('Commission', $model->id);
        return response()->json(['model' => $this->fullModel('Commission', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Commission', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Commission::find($id);
        $model->from                  = $request->from;
        $model->until                 = $request->until;
        $model->sale_type_id          = $request->sale_type_id;
        $model->percentage            = $request->percentage;
        $model->save();
        GeneralHelper::attachModels($model, 'for_all_sellers', $request->for_all_sellers, ['percentage']);
        GeneralHelper::attachModels($model, 'for_only_sellers', $request->for_only_sellers, []);
        GeneralHelper::attachModels($model, 'except_sellers', $request->except_sellers, []);
        $this->sendAddModelNotification('Commission', $model->id);
        return response()->json(['model' => $this->fullModel('Commission', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Commission::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('Commission', $model->id);
        return response(null);
    }
}
