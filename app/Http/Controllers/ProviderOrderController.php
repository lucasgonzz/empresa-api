<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\ProviderOrderHelper;
use App\Models\ProviderOrder;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ProviderOrderController extends Controller
{

    public function index($from_date, $until_date = null) {
        $models = ProviderOrder::where('user_id', $this->userId())
                        ->orderBy('created_at', 'DESC')
                        ->withAll();
        if (!is_null($until_date)) {
            $models = $models->whereDate('created_at', '>=', $from_date)
                            ->whereDate('created_at', '<=', $until_date);
        } else {
            $models = $models->whereDate('created_at', $from_date);
        }

        $models = $models->get();
        return response()->json(['models' => $models], 200);
    }

    public function indexDaysToAdvise($from_date, $until_date = null) {
        $models = ProviderOrder::where('user_id', $this->userId())
                        ->orderBy('created_at', 'DESC')
                        ->withAll()
                        ->whereNotNull('days_to_advise')
                        ->where('provider_order_status_id', 1)
                        ->get();
        $results = [];
        foreach ($models as $model) {
            if ($model->created_at->addDays($model->days_to_advise)->gte(Carbon::today())) {
                $results[] = $model;
            }
        }

        return response()->json(['models' => $results], 200);
    }

    public function store(Request $request) {
        $model = ProviderOrder::create([
            'num'                                       => $this->num('provider_orders'),
            'total_with_iva'                            => $request->total_with_iva,
            'total_from_provider_order_afip_tickets'    => $request->total_from_provider_order_afip_tickets,
            'provider_id'                               => $request->provider_id,
            'provider_order_status_id'                  => $request->provider_order_status_id,
            'days_to_advise'                            => $request->days_to_advise,
            'user_id'                                   => $this->userId(),
        ]);
        ProviderOrderHelper::attachArticles($request->articles, $model);
        $this->updateRelationsCreated('provider_order', $model->id, $request->childrens);
        $this->sendAddModelNotification('provider_order', $model->id);
        return response()->json(['model' => $this->fullModel('ProviderOrder', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ProviderOrder', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ProviderOrder::find($id);
        $model->total_with_iva                              = $request->total_with_iva;
        $model->total_from_provider_order_afip_tickets      = $request->total_from_provider_order_afip_tickets;
        $model->provider_id                                 = $request->provider_id;
        $model->provider_order_status_id                    = $request->provider_order_status_id;
        $model->days_to_advise                              = $request->days_to_advise;
        $model->save();
        ProviderOrderHelper::attachArticles($request->articles, $model);
        $this->sendAddModelNotification('provider_order', $model->id);
        return response()->json(['model' => $this->fullModel('ProviderOrder', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ProviderOrder::find($id);
        ProviderOrderHelper::deleteCurrentAcount($model);
        $model->provider->pagos_checkeados = 0;
        $model->provider->save();
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('ProviderOrder', $model->id);
        return response(null);
    }
}
