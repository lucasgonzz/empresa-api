<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\InventoryLinkageHelper;
use App\Models\InventoryLinkage;
use Illuminate\Http\Request;

class InventoryLinkageController extends Controller
{

    public function index() {
        $models = InventoryLinkage::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = InventoryLinkage::create([
            // 'num'                           => $this->num('inventory_linkages'),
            'client_id'                     => $request->client_id,
            'inventory_linkage_scope_id'    => $request->inventory_linkage_scope_id,
            'use_categories'                => $request->use_categories,
            'user_id'                       => $this->userId(),
        ]);

        GeneralHelper::attachModels($model, 'categories', $request->categories, ['percentage_discount']);

        $inventory_linkage_helper = new InventoryLinkageHelper($model);
        // $inventory_linkage_helper->setClientCategories();
        // $inventory_linkage_helper->setClientSubCategories();
        $inventory_linkage_helper->setClientArticles();
        $this->sendAddModelNotification('inventory_linkage', $model->id);
        return response()->json(['model' => $this->fullModel('InventoryLinkage', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('InventoryLinkage', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = InventoryLinkage::find($id);
        $model->client_id                     = $request->client_id;
        $model->inventory_linkage_scope_id    = $request->inventory_linkage_scope_id;
        $model->use_categories                = $request->use_categories;
        $model->save();

        GeneralHelper::attachModels($model, 'categories', $request->categories, ['percentage_discount']);
        
        $this->sendAddModelNotification('inventory_linkage', $model->id);
        return response()->json(['model' => $this->fullModel('InventoryLinkage', $model->id)], 200);
    }

    public function destroy($id) {
        $model = InventoryLinkage::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('InventoryLinkage', $model->id);
        return response(null);
    }
}
