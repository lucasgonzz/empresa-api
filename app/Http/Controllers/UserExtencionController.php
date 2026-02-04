<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\ExtencionEmpresa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UserExtencionController extends Controller
{
    public function edit($user_id = null)
    {

        if (is_null($user_id)) {
            $user_id = config('app.USER_ID');
        }

        $user = User::find($user_id);

        // traemos todas las extensiones
        $extencions = ExtencionEmpresa::all();

        // también podemos traer los IDs de extensiones que el usuario ya tiene
        $user_extencion_ids = $user->extencions()->pluck('extencion_empresa_user.extencion_empresa_id')->toArray();

        return view('user.extencions.edit', compact('user', 'extencions', 'user_extencion_ids'));
    }

    public function update(Request $request, $user_id)
    {
        $user = User::find($user_id);

        // sincronizamos: las extensiones seleccionadas serán exactamente las que tenga después
        $selected = $request->extencions; // si no viene, ponemos vacío => remueve todas

        $user->extencions()->sync($selected);

        return redirect()->route('users.extencions.edit', $user)
                         ->with('success', 'Extensiones actualizadas correctamente.');
    }
}
