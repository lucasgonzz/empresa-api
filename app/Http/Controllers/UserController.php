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
use App\Models\Article;
use App\Models\PriceType;
use App\Notifications\GlobalNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
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
        /** @var User|null $model Usuario autenticado (puede ser owner o empleado). */
        $model = Auth()->user();
        
        /** @var User|null $owner_user Usuario dueño (owner) al que se le aplica el flag listas_de_precio. */
        // El flag listas_de_precio vive en el usuario dueño (owner). El auth_user puede ser empleado.
        $owner_user = $model->owner_id ? User::find($model->owner_id) : $model;
        $current_lists_de_precio = (int) ($owner_user ? (bool) $owner_user->listas_de_precio : false);

        /**
         * Array de notificaciones para devolver al frontend en respuestas exitosas.
         *
         * - Se usa para feedback inmediato cuando se encola un recálculo masivo de precios.
         * - Formato esperado por el interceptor del frontend: array de objetos con `message` y `type`.
         *
         * @var array<int, array{message:string,type:string}>
         */
        $notifications = [];

        $current_dolar                          = $model->dollar;
        $current_iva_included                   = $model->iva_included;
        $current_percentage_gain                = $model->percentage_gain;
        $current_cotizar_precios_en_dolares     = $model->cotizar_precios_en_dolares;
        $current_redondear_precios_en_decenas   = (int) $model->redondear_precios_en_decenas;
        $current_redondear_de_a_50              = (int) $model->redondear_de_a_50;
        $current_redondear_precios_en_centavos  = (int) $model->redondear_precios_en_centavos;
        $current_aplicar_iva_al_costo           = (int) $model->aplicar_iva_al_costo;

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
        $model->redondear_precios_en_decenas          = $request->redondear_precios_en_decenas;
        $model->redondear_de_a_50                     = $request->redondear_de_a_50;
        $model->redondear_precios_en_centavos         = $request->redondear_precios_en_centavos;
        
        $model->header_articulos_pdf            = $request->header_articulos_pdf;
        $model->default_version                 = $request->default_version;
        $model->estable_version                 = $request->estable_version;

        if ($request->default_version) {
            $api_url = str_replace('https://', 'https://api-', $request->default_version);
            if (!config('app.VPS') && config('app.APP_ENV') == 'production') {
                $api_url .= '/public';
            }
            $model->api_url  = $api_url;
        }

        $model->text_omitir_cc                  = $request->text_omitir_cc;
        $model->percentage_gain                  = $request->percentage_gain;
        $model->scroll_en_tablas                  = $request->scroll_en_tablas;
        $model->cotizar_precios_en_dolares        = $request->cotizar_precios_en_dolares;
        $model->cc_ultimas_arriba        = $request->cc_ultimas_arriba;

        
        $model->show_stock_min_al_iniciar       = $request->show_stock_min_al_iniciar;
        $model->show_afip_errors_al_iniciar     = $request->show_afip_errors_al_iniciar;
        $model->usar_articles_cache             = $request->usar_articles_cache;
        /**
         * Flag para habilitar/deshabilitar trabajo offline en frontend.
         */
        $model->sync_offline_articles           = $request->sync_offline_articles;
        $model->clave_eliminar_article          = $request->clave_eliminar_article;
        $model->img_auto_timeout                = $request->img_auto_timeout;

        $model->address_company                 = $request->address_company;
        $model->all_addresses_in_sale_pdf       = $request->all_addresses_in_sale_pdf;
        $model->mostrar_vendedor_en_venta_pdf   = $request->mostrar_vendedor_en_venta_pdf;
        $model->pdf_image_size                  = $request->pdf_image_size;
        $model->inputs_size_id                  = $request->inputs_size_id;
        $model->aplicar_iva_al_costo                  = $request->aplicar_iva_al_costo;

        /**
         * Permite `provider_code` repetido en artículos.
         * Esta configuración se usa desde el front solo por el owner, por eso se persiste en el auth_user.
         */
        if ($request->has('usa_provider_codes_repetidos')) {
            $model->usa_provider_codes_repetidos = (bool) $request->usa_provider_codes_repetidos;
        }



        $model->save();

        if ($owner_user && $request->has('listas_de_precio')) {
            $owner_user->listas_de_precio = (int) $request->listas_de_precio;
            $owner_user->save();
        }
       

        UserHelper::set_sessions($model);

        $this->check_update_articles_price_types_relations_on_lists_de_precio($owner_user, $current_lists_de_precio);

        // Si se encola recálculo masivo de precios, devolvemos feedback inmediato al usuario.
        if (
            $this->check_actualizar_articulos(
                $model,
                $current_dolar,
                $current_iva_included,
                $current_percentage_gain,
                $current_cotizar_precios_en_dolares,
                $current_redondear_precios_en_decenas,
                $current_redondear_de_a_50,
                $current_redondear_precios_en_centavos,
                $current_aplicar_iva_al_costo
            )
        ) {
            $notifications[] = [
                'message' => 'Se inició la actualización de precios en segundo plano. Te avisaremos cuando termine.',
                'type'    => 'info',
            ];
        }

        $model = UserHelper::getFullModel();

        // $this->actualizar_empleados($model);

        return response()->json([
            'model' => $model,
            'notifications' => $notifications,
        ], 200);
    }

    /**
     * Si el usuario dueño cambia el flag `listas_de_precio`, sincroniza las relaciones en `article_price_type`
     * y dispara recálculo para que `final_price` quede consistente.
     *
     * - 0 -> 1: crea (o completa) relaciones para todos los artículos actuales contra todos los `price_types`
     *   creados por el dueño, setea el porcentaje por defecto del `price_type` cuando exista.
     * - 1 -> 0: elimina relaciones de artículos contra los `price_types` del dueño.
     *
     * @param User|null $owner_user Usuario dueño (owner) al que se le aplica el flag.
     * @param int $current_lists_de_precio Valor previo antes de guardar.
     * @return void
     */
    function check_update_articles_price_types_relations_on_lists_de_precio($owner_user, $current_lists_de_precio) {
        if (!$owner_user) {
            return;
        }

        $new_lists_de_precio = (int) ($owner_user->listas_de_precio ? 1 : 0);
        $current_lists_de_precio = (int) ($current_lists_de_precio ? 1 : 0);

        if ($new_lists_de_precio === $current_lists_de_precio) {
            return;
        }

        $price_types = PriceType::where('user_id', $owner_user->id)
            ->orderBy('position', 'ASC')
            ->get(['id', 'percentage', 'setear_precio_final', 'incluir_en_lista_de_precios_de_excel', 'position']);

        $last_position = PriceType::where('user_id', $owner_user->id)
                                ->orderBy('position', 'DESC')
                                ->first()->position;

        Log::info('Last position: '.$last_position);

        $price_type_ids = $price_types->pluck('id')->values()->all();
        $articles_chunk_size = 200;

        // 0 -> 1
        if ($current_lists_de_precio === 0 && $new_lists_de_precio === 1) {

            Log::info('Agregando listas de precio');
            $pivot_table = 'article_price_type';
            $now = now();

            // Inserta relaciones por chunk para evitar queries y payloads gigantes.
            Article::where('user_id', $owner_user->id)
                ->select('id', 'percentage_gain', 'cost', 'final_price')
                ->chunk($articles_chunk_size, function ($articles_chunk) use ($price_types, $pivot_table, $now, $articles_chunk_size, $last_position) {

                    if ($price_types->isEmpty()) {
                        return;
                    }

                    $rows = [];

                    foreach ($articles_chunk as $article) {


                        foreach ($price_types as $price_type) {

                            $percentage = $price_type->percentage;
                            $final_price = null;

                            $setear_precio_final = $price_type->setear_precio_final;

                            if ((int)$price_type->position == (int)$last_position) {

                                if (!is_null($article->cost) && !is_null($article->percentage_gain)) {
                                    $percentage = $article->percentage_gain;
                                } else if (!is_null($article->final_price)) {
                                    $percentage = null;
                                    $final_price = (float)$article->final_price;
                                    $setear_precio_final = 1;
                                    // Log::info('usando precio manual para la lista '.$price_type->name.' de '.$final_price);
                                }
                                // Log::info('last_position de article: '.$article->name);
                            }
                            
                            $rows[] = [
                                'article_id' => (int) $article->id,
                                'price_type_id' => (int) $price_type->id,
                                // Por defecto, seteamos el porcentaje del price_type. Si el usuario no completó
                                // percentage en el price_type, quedará null y el cálculo lo normaliza en 0.
                                'percentage' => $percentage,
                                'final_price' => $final_price,
                                'previus_final_price' => null,
                                'incluir_en_excel_para_clientes' => (int) ($price_type->incluir_en_lista_de_precios_de_excel ? 1 : 0),
                                'setear_precio_final' => (int) $setear_precio_final,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    }

                    if (count($rows)) {
                        DB::table($pivot_table)->insertOrIgnore($rows);
                    }
                });

            // Recalcula precios para que `final_price` refleje listas activadas.
            ProcessSetFinalPrices::dispatch($owner_user->id);
            return;
        }

        // 1 -> 0
        if ($current_lists_de_precio === 1 && $new_lists_de_precio === 0) {
            Log::info('Quitando listas de precio');
            if (!empty($price_type_ids)) {
                DB::table('article_price_type')
                    ->join('articles', 'articles.id', '=', 'article_price_type.article_id')
                    ->where('articles.user_id', $owner_user->id)
                    ->whereIn('article_price_type.price_type_id', $price_type_ids)
                    ->delete();
            }

            // Recalcula precios para que `final_price` deje de depender de los price_types.
            ProcessSetFinalPrices::dispatch($owner_user->id);
        }
    }

    function set_img_auto_timeout($value) {
        $model = User::find($this->userId());
        $model->img_auto_timeout = $value;
        $model->save();
        
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

    /**
     * Detecta cambios de configuración que requieren recálculo masivo de precios y encola el proceso.
     *
     * Nota: el recálculo se ejecuta en segundo plano vía queue, por eso este método devuelve un boolean
     * para que el controller pueda retornar feedback inmediato al frontend (campo `notifications`).
     *
     * @param User $model Usuario autenticado.
     * @param mixed $current_dolar Valor previo de dólar.
     * @param mixed $current_iva_included Valor previo de iva_included.
     * @param mixed $current_percentage_gain Valor previo de percentage_gain.
     * @param mixed $current_cotizar_precios_en_dolares Valor previo de cotizar_precios_en_dolares.
     * @param int $current_redondear_precios_en_decenas Valor previo del flag redondear_precios_en_decenas.
     * @param int $current_redondear_de_a_50 Valor previo del flag redondear_de_a_50.
     * @param int $current_redondear_precios_en_centavos Valor previo del flag redondear_precios_en_centavos.
     * @return bool true si se encoló un recálculo; false si no hubo cambios relevantes.
     */
    function check_actualizar_articulos(
        $model,
        $current_dolar,
        $current_iva_included,
        $current_percentage_gain,
        $current_cotizar_precios_en_dolares,
        $current_redondear_precios_en_decenas,
        $current_redondear_de_a_50,
        $current_redondear_precios_en_centavos,
        $current_aplicar_iva_al_costo
    ) {

        if (
            $model->dollar != $current_dolar
            || $model->iva_included != $current_iva_included
            || $model->percentage_gain != $current_percentage_gain
            || $model->cotizar_precios_en_dolares != $current_cotizar_precios_en_dolares
            || (int) $model->redondear_precios_en_decenas !== (int) $current_redondear_precios_en_decenas
            || (int) $model->redondear_de_a_50 !== (int) $current_redondear_de_a_50
            || (int) $model->redondear_precios_en_centavos !== (int) $current_redondear_precios_en_centavos
            || (int) $model->aplicar_iva_al_costo !== (int) $current_aplicar_iva_al_costo

        ) {
            Log::info($model->dollar.' | '.$current_dolar);
            Log::info($model->iva_included.' | '.$current_iva_included);
            Log::info($model->percentage_gain.' | '.$current_percentage_gain);
            Log::info($model->cotizar_precios_en_dolares.' | '.$current_cotizar_precios_en_dolares);
            Log::info((int) $model->redondear_precios_en_decenas.' | '.(int) $current_redondear_precios_en_decenas);
            Log::info((int) $model->redondear_de_a_50.' | '.(int) $current_redondear_de_a_50);
            Log::info((int) $model->redondear_precios_en_centavos.' | '.(int) $current_redondear_precios_en_centavos);
            Log::info((int) $model->aplicar_iva_al_costo.' | '.(int) $current_aplicar_iva_al_costo);
            Log::info('Hubo cambios en propiedades de user');

            /** @var bool $from_dolar Indica si el recálculo se disparó por cambio de dólar (optimiza query en job). */
            $from_dolar = false;

            if ($model->dollar != $current_dolar) {
                $from_dolar = true;
            }

            ProcessSetFinalPrices::dispatch(UserHelper::userId(), null, null, $from_dolar);
            return true;
        }
        return false;
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

    function set_eliminar_articulos_offline($id, $value) {
        $user = User::find($id);
        $user->eliminar_articulos_offline = (int)$value;
        $user->save();
        return response(null, 200);
    }
}
