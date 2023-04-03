<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\Helpers\UserHelper;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    function user() {
        return response()->json(['user' => Auth()->user()], 200);
    }

    function update(Request $request, $id) {
        $model = Auth()->user();
        $current_dolar                          = $model->dollar;
        $model->name                            = $request->name;
        $model->doc_number                      = $request->doc_number;
        $model->dollar                          = $request->dollar;
        $model->company_name                    = $request->company_name;
        $model->phone                           = $request->phone;
        $model->email                           = $request->email;
        $model->instagram                       = $request->instagram;
        $model->facebook                        = $request->facebook;
        $model->quienes_somos                   = $request->quienes_somos;
        $model->mensaje_contacto                = $request->mensaje_contacto;
        $model->show_articles_without_images    = $request->show_articles_without_images;
        $model->show_articles_without_stock     = $request->show_articles_without_stock;
        $model->save();
        GeneralHelper::checkNewValuesForArticlesPrices($this, $current_dolar, $request->dolar);
        $model = UserHelper::getFullModel();
        return response()->json(['model' => $model], 200);
    }

    function updatePassword(Request $request) {

        if (Hash::check($request->current_password, Auth()->user()->password)) {
            $user = User::find(Auth()->user()->id);
            $user->update([
                'password' => bcrypt($request->new_password),
            ]);
            return response()->json(['updated' => true], 200);
        } else {
            return response()->json(['updated' => false], 200);
        }
    }
}
