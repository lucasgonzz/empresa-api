<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\Helpers\UserHelper;
use App\Models\OnlineConfiguration;
use App\Models\User;
use App\Models\UserConfiguration;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    function user() {
        return response()->json(['user' => Auth()->user()], 200);
    }

    function store(Request $request) {
        if (!$this->docNumberRegistered($request->doc_number)) {
            $model = User::create([
                'name'          => $request->name,
                'doc_number'    => $request->doc_number,
                'phone'         => $request->phone,
                'company_name'  => $request->company_name,
                'email'         => $request->email,
                'expired_at'    => Carbon::now()->addMonth(),
                'password'      => bcrypt($request->password),
            ]);
            $model->extencions()->attach([6, 8, 9]);
            // UserConfiguration::create([
            //     'user_id'       => $model->id,
            //     'iva_included'  => 1,
            //     'current_acount_pagado_details' => 'Se saldo',
            //     'current_acount_pagandose_details'  => 'Pagandose',
            // ]);
            OnlineConfiguration::create([
                'user_id'       => $model->id,
            ]);
            Auth::login($model);
            return response()->json(['model' => $model], 201);
        } else {
            return response()->json(['repeated' => true], 200);
        }
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
        $model->download_articles               = $request->download_articles;
        $model->iva_included                    = $request->iva_included;
        // $model->online_price_type_id            = $request->online_price_type_id;
        // $model->instagram                       = $request->instagram;
        // $model->facebook                        = $request->facebook;
        // $model->quienes_somos                   = $request->quienes_somos;
        // $model->mensaje_contacto                = $request->mensaje_contacto;
        // $model->show_articles_without_images    = $request->show_articles_without_images;
        // $model->show_articles_without_stock     = $request->show_articles_without_stock;
        // $model->order_description               = $request->order_description;
        // $model->online_price_surchage           = $request->online_price_surchage;
        // $model->has_delivery                    = $request->has_delivery;
        // $model->pausar_tienda_online            = $request->pausar_tienda_online;
        $model->save();
        GeneralHelper::checkNewValuesForArticlesPrices($this, $current_dolar, $request->dollar);
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

    function docNumberRegistered($doc_number) {
        $repeated_user = User::where('doc_number', $doc_number)->first();
        return !is_null($repeated_user);
    }
}
