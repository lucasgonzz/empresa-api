<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\AuthController;
use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Jobs\ProcessSetFinalPrices;
use App\Models\OnlineConfiguration;
use App\Models\User;
use App\Models\UserConfiguration;
use App\Notifications\GlobalNotification;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{

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
        $current_iva_included                   = $model->iva_included;
        $current_percentage_gain                = $model->percentage_gain;

        $model->name                            = $request->name;
        $model->doc_number                      = $request->doc_number;
        $model->dollar                          = $request->dollar;
        $model->company_name                    = $request->company_name;
        $model->phone                           = $request->phone;
        $model->email                           = $request->email;
        $model->download_articles               = $request->download_articles;
        $model->iva_included                    = $request->iva_included;
        $model->ask_amount_in_vender            = $request->ask_amount_in_vender;
        $model->sale_ticket_width               = $request->sale_ticket_width;
        $model->default_current_acount_payment_method_id               = $request->default_current_acount_payment_method_id;
        $model->discount_stock_from_recipe_after_advance_to_next_status               = $request->discount_stock_from_recipe_after_advance_to_next_status;
        $model->article_ticket_info_id          = $request->article_ticket_info_id;

        $model->dias_alertar_empleados_ventas_no_cobradas          = $request->dias_alertar_empleados_ventas_no_cobradas;

        $model->aplicar_descuentos_en_articulos_antes_del_margen_de_ganancia          = $request->aplicar_descuentos_en_articulos_antes_del_margen_de_ganancia;
        
       
        $model->dias_alertar_administradores_ventas_no_cobradas          = $request->dias_alertar_administradores_ventas_no_cobradas;

        $model->str_limint_en_vender          = $request->str_limint_en_vender;
        $model->sale_ticket_description          = $request->sale_ticket_description;
        $model->siempre_omitir_en_cuenta_corriente          = $request->siempre_omitir_en_cuenta_corriente;
        $model->redondear_centenas_en_vender          = $request->redondear_centenas_en_vender;
        
        $model->header_articulos_pdf            = $request->header_articulos_pdf;
        $model->default_version                 = $request->default_version;
        $model->estable_version                 = $request->estable_version;

        $model->text_omitir_cc                  = $request->text_omitir_cc;
        $model->percentage_gain                  = $request->percentage_gain;
        $model->scroll_en_tablas                  = $request->scroll_en_tablas;
        $model->cotizar_precios_en_dolares        = $request->cotizar_precios_en_dolares;
        $model->cc_ultimas_arriba        = $request->cc_ultimas_arriba;

        
        $model->show_stock_min_al_iniciar       = $request->show_stock_min_al_iniciar;
        $model->show_afip_errors_al_iniciar     = $request->show_afip_errors_al_iniciar;
        $model->usar_articles_cache             = $request->usar_articles_cache;
        $model->clave_eliminar_article          = $request->clave_eliminar_article;


        $model->save();

        UserHelper::set_sessions($model);

        $this->check_actualizar_articulos($model, $current_dolar, $current_iva_included, $current_percentage_gain);

        $model = UserHelper::getFullModel();

        // $this->actualizar_empleados($model);

        return response()->json(['model' => $model], 200);
    }

    function actualizar_empleados($user) {
        
        $functions_to_execute = [
            [
                'btn_text'      => 'Recargar pagina',
                'function_name' => 'recargar_pagina',
                'btn_variant'   => 'primary',
            ],
        ];

        $info_to_show = [];

        $user->notify(new GlobalNotification([
            'message_text'              => 'Informacion de la cuenta actualizada',
            'color_variant'             => 'success',
            'functions_to_execute'      => $functions_to_execute,
            'info_to_show'              => $info_to_show,
            'owner_id'                  => $user->id,
            'is_only_for_auth_user'     => false,
        ]));
    }

    function check_actualizar_articulos($model, $current_dolar, $current_iva_included, $current_percentage_gain) {

        if (
            $model->dollar != $current_dolar
            || $model->iva_included != $current_iva_included
            || $model->percentage_gain != $current_percentage_gain
        ) {
            Log::info($model->dollar.' | '.$current_dolar);
            Log::info($model->iva_included.' | '.$current_iva_included);
            Log::info($model->percentage_gain.' | '.$current_percentage_gain);
            Log::info('Hubo cambios en propiedades de user');

            $from_dolar = false;

            if ($model->dollar != $current_dolar) {
                $from_dolar = true;
            }

            ProcessSetFinalPrices::dispatch(UserHelper::userId(), null, null, $from_dolar);
        }
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
