<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\Buyer;
use Illuminate\Http\Request;

class BuyerController extends Controller
{

    public function index() {
        $models = Buyer::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {

        $password = '1234';

        if (isset($request->num)) {
            $password .= $request->num;
        }

        if (
            str_contains(env('APP_URL'), 'truvari')
            && isset($request->phone)
        ) {
            $password = 'truvari'.substr($request->phone, -3);
        }

        if ($request->visible_password) {
            $password = $request->visible_password;
        }

        $model = Buyer::create([
            'num'                       => $this->num('buyers'),
            'name'                      => $request->name,
            'email'                     => $request->email,
            'phone'                     => $request->phone,
            'seller_id'                 => $request->seller_id,
            'visible_password'          => $password,
            'password'                  => bcrypt($password),
            'comercio_city_client_id'   => $request->id,
            'user_id'                   => $this->userId(),
        ]);
        $this->sendAddModelNotification('Buyer', $model->id);
        return response()->json(['model' => $this->fullModel('Buyer', $model->id)], 201);
    }  

    public function update(Request $request, $id) {
        $model = Buyer::find($id);
        $model->name                    = $request->name;
        $model->email                   = $request->email;
        $model->phone                   = $request->phone;
        $model->seller_id               = $request->seller_id;
        $model->visible_password        = $request->visible_password;

        if ($request->visible_password && $request->visible_password != '') {
            $model->password     = bcrypt($request->visible_password);
        }

        $model->save();
        // $this->sendAddModelNotification('Buyer', $model->id);
        return response()->json(['model' => $this->fullModel('Buyer', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Buyer', $id)], 200);
    }

    public function destroy($id) {
        $model = Buyer::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Buyer', $model->id);
        return response(null);
    }
}
