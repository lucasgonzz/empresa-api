<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\OnlineConfiguration;
use Illuminate\Http\Request;

class OnlineConfigurationController extends Controller
{

    public function index() {
        $models = OnlineConfiguration::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function update(Request $request, $id) {
        $model = OnlineConfiguration::find($id);
        $model->pausar_tienda_online            = $request->pausar_tienda_online;    
        $model->register_to_buy                 = $request->register_to_buy;    
        $model->online_price_type_id            = $request->online_price_type_id;                     
        $model->online_price_surchage           = $request->online_price_surchage;                      
        $model->instagram                       = $request->instagram;                     
        $model->facebook                        = $request->facebook;                     
        $model->quienes_somos                   = $request->quienes_somos;                     
        $model->default_article_image_url       = $request->default_article_image_url;                     
        $model->mensaje_contacto                = $request->mensaje_contacto;                     
        $model->show_articles_without_images    = $request->show_articles_without_images;                     
        $model->show_articles_without_stock     = $request->show_articles_without_stock;
        $model->stock_null_equal_0              = $request->stock_null_equal_0;

        $model->online_description              = $request->online_description;                     
        $model->has_delivery                    = $request->has_delivery;                     
        $model->order_description               = $request->order_description;                     
        $model->save();
        $this->sendAddModelNotification('OnlineConfiguration', $model->id);
        return response()->json(['model' => $this->fullModel('OnlineConfiguration', $model->id)], 200);
    }
}
