<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\DepositMovementHelper;
use App\Models\DepositMovement;
use Illuminate\Http\Request;

class DepositMovementController extends Controller
{

    public function index($from_date = null, $until_date = null) {
        $models = DepositMovement::where('user_id', $this->userId())
                        ->orderBy('created_at', 'DESC')
                        ->withAll();
        if (!is_null($from_date)) {
            if (!is_null($until_date)) {
                $models = $models->whereDate('created_at', '>=', $from_date)
                                ->whereDate('created_at', '<=', $until_date);
            } else {
                $models = $models->whereDate('created_at', $from_date);
            }
        }

        $models = $models->get();
        return response()->json(['models' => $models], 200);
    }

    function en_curso() {
        $models = DepositMovement::where('deposit_movement_status_id', 1)
                                    ->where('employee_id', $this->userId(false))
                                    ->orderBy('created_at', 'ASC')
                                    ->withAll()
                                    ->get();
                                    
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = DepositMovement::create([
            'num'                   		=> $this->num('deposit_movements'),
            'from_address_id'               => $request->from_address_id,
            'to_address_id'                 => $request->to_address_id,
            'employee_id'                 	=> $request->employee_id,
            'deposit_movement_status_id'    => $request->deposit_movement_status_id,
            'recibido_at'                 	=> $request->recibido_at,
            'notes'                 		=> $request->notes,
            'user_id'               		=> $this->userId(),
        ]);

        $helper = new DepositMovementHelper($model);
        $helper->attach_articles($request->articles);
        $helper->check_status();

        $this->sendAddModelNotification('DepositMovement', $model->id);
        return response()->json(['model' => $this->fullModel('DepositMovement', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('DepositMovement', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = DepositMovement::find($id);
        $model->from_address_id             = $request->from_address_id;
        $model->to_address_id               = $request->to_address_id;
        $model->employee_id                 = $request->employee_id;
        $model->deposit_movement_status_id  = $request->deposit_movement_status_id;
        $model->recibido_at                 = $request->recibido_at;
        $model->notes                 		= $request->notes;
        $model->save();

        $helper = new DepositMovementHelper($model);
        $helper->attach_articles($request->articles);
        $helper->check_status();
        
        $this->sendAddModelNotification('DepositMovement', $model->id);
        return response()->json(['model' => $this->fullModel('DepositMovement', $model->id)], 200);
    }

    public function destroy($id) {
        $model = DepositMovement::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('DepositMovement', $model->id);
        return response(null);
    }
}
