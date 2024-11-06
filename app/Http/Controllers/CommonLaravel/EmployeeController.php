<?php

namespace App\Http\Controllers\CommonLaravel;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class EmployeeController extends Controller
{
    
    function index() {
        $models = User::where('owner_id', $this->userId())
                    ->with('permissions')
                    ->orderBy('name', 'ASC')
                    ->get();
        return response()->json(['models' => $models], 200);
    }      

    function update(Request $request, $id) {
        $model = User::where('id', $request->id)
                        ->first();
        
        $model->permissions()->sync([]);
        foreach ($request->permissions as $permission) {
            $model->permissions()->attach($permission['id']);
        }
        
        $model->name                                            = $request->name;
        $model->address_id                                      = $request->address_id;
        $model->visible_password                                = $request->visible_password;
        $model->admin_access                                    = $request->admin_access;
        $model->dias_alertar_empleados_ventas_no_cobradas       = $request->dias_alertar_empleados_ventas_no_cobradas;
        $model->ver_alertas_de_todos_los_empleados              = $request->ver_alertas_de_todos_los_empleados;
        
        $model->puede_guardar_ventas_sin_cliente              = $request->puede_guardar_ventas_sin_cliente;

        $model->password                                        = bcrypt($request->visible_password);
        if ($model->doc_number == $request->doc_number || !$this->docNumerRegister($request->doc_number)) {
            $model->doc_number          = $request->doc_number;
        }
        $model->save();

        $model = User::where('id', $request->id)
                        ->with('permissions')
                        ->first();
        return response()->json(['model' => $model], 200);
    }

    function destroy($id) {
        $user = User::find($id);
        $user->delete();
    }

    function store(Request $request) {
        $user = auth()->user();


        if (!$this->docNumerRegister($request->doc_number)) {
            $model = User::create([
                'name'              => ucfirst($request->name),
                'doc_number'        => $request->doc_number,
                'admin_access'      => $request->admin_access,
                'visible_password'  => $request->visible_password,
                'address_id'        => $request->address_id,
                'password'          => Hash::make($request->visible_password),
                'owner_id'          => $this->userId(),
                'created_at'        => Carbon::now(),
            ]);

            $model->permissions()->attach($request->permissions_id);
            $model = User::where('id', $model->id)
                                ->with('permissions')
                                ->first();
            return response()->json(['model' => $model], 201);
        } else {
            return response()->json(['model' => false], 200);
        }
    }

    function docNumerRegister($doc_number) {
        $model = User::where('doc_number', $doc_number)
                        ->first();
        return !is_null($model);
    }
    
}
