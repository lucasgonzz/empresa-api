<?php

namespace App\Services\MercadoLibre;

use App\Models\Article;
use App\Models\MeliAttribute;
use App\Models\SyncToMeliArticle;
use App\Services\MercadoLibre\CategoryService;
use App\Services\MercadoLibre\MercadoLibreService;
use Illuminate\Support\Facades\Log;

class ProductService extends MercadoLibreService
{

    // Si el articulo tiene la info necesaria, se marca para sync a meli
    static function add_article_to_sync($article) {


        if (
            env('USA_MERCADO_LIBRE', false)
        ) {

            Log::info('add_article_to_sync');

            if (!$article->mercado_libre) {
                \Log::error("Artículo Mercado Libre: ID {$article->id}");
                return;
            }

            if (!$article->meli_category_id) {
                \Log::error("Artículo sin categoria de Mercado Libre: ID {$article->id}");
                return;
            }

            if (is_null($article->stock)) {
                \Log::error("Artículo sin stock para Mercado Libre: ID {$article->id}");
                return;
            }

            if (count($article->images) == 0) {
                \Log::error("Artículo sin imagenes para Mercado Libre: ID {$article->id}");
                return;
            }


            $already_exists = SyncToMeliArticle::where('article_id', $article->id)
                                    ->where('user_id', $article->user_id)
                                    ->where('status', 'pendiente')
                                    ->exists();

            if (!$already_exists) {
                SyncToMeliArticle::create([
                    'article_id' => $article->id,
                    'user_id' => $article->user_id,
                    'status'    => 'pendiente',
                ]);
            }
            
        }
        
    }

    public function sync_article(SyncToMeliArticle $sync)
    {
        $sync->status = 'en_progreso';
        $sync->attempted_at = now();
        $sync->save();

        try {
            $article = $sync->article;
            $article->load('meli_category');

            $meli_payload = [
                // 'title'                 => $article->name,
                'price'                 => (float)$this->get_price($article),
                // 'currency_id'           => 'ARS',
                // 'listing_type_id'       => $article->listing_type_id,
                'available_quantity'    => (int)$article->stock,
                // 'category_id'           => $article->meli_category->meli_category_id,
                // 'buying_mode'           => $article->meli_buying_mode->meli_id,
                // 'condition'             => $article->meli_item_condition->meli_id,
                // 'listing_type_id'       => $article->meli_listing_type->meli_id,
                // 'pictures'              => array_map(function ($image) {
                //     return ['source' => $image['hosting_url']];
                // }, $article->images->toArray()),
            ];

            if ($meli_payload['available_quantity'] <= 0) {
                unset($meli_payload['available_quantity']);

                $meli_payload['status'] = 'paused';
            }

            // $meli_payload = $this->add_attributes($meli_payload, $article);

            $me_li_id = $article->me_li_id;

            if ($me_li_id) {
                unset($meli_payload['listing_type_id']);
                $this->make_request('put', "items/{$me_li_id}", $meli_payload);
            } else {
                $response = $this->make_request('post', 'items', $meli_payload);
                $article->me_li_id = $response['id'];
                $article->save();
            }

            $this->set_description($article);

            $sync->status = 'exitosa';
            $sync->synced_at = now();
            $sync->error_message = null;
            $sync->save();

        } catch (\Exception $e) {


            \Log::error('Error al sincronizar artículo con MercadoLibre: ' . $e->getMessage());
            
            $error_message = $e->getMessage();

            // Si el mensaje tiene un JSON de respuesta de ML, intentamos extraerlo
            if (str_contains($error_message, 'Mercado Libre API error:')) {
                $json_part = trim(str_replace('Mercado Libre API error:', '', $error_message));

                $parsed_error = json_decode($json_part, true);

                if (json_last_error() === JSON_ERROR_NONE && isset($parsed_error['cause'])) {

                    if (is_array($parsed_error['cause'])) {
                        $causes = array_map(function ($c) {
                            return "- {$c['message']} (Código: {$c['code']})";
                        }, $parsed_error['cause']);
                    }

                    $error_message = "Errores al sincronizar con MercadoLibre:\n" . implode("\n", $causes);
                }
            }

            $sync->status = 'error';
            $sync->error_message = $error_message;
            $sync->error_message_crudo = $e->getMessage();
            Log::info('Se marco como fallido');
            $sync->save();

        }
    }

    function set_description($article)
    {
        if (!$article->meli_descripcion || !$article->me_li_id) {
            return;
        }

        $meli_payload = [
            'plain_text' => $article->meli_descripcion,
        ];

        try {
            // Intentamos obtener la descripción actual
            $this->make_request('get', "items/{$article->me_li_id}/description");

            // Si no lanza excepción, existe -> actualizamos (PUT)
            $this->make_request('put', "items/{$article->me_li_id}/description", $meli_payload);

        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), '404')) {
                // Si no existe, la creamos (POST)
                $this->make_request('post', "items/{$article->me_li_id}/description", $meli_payload);
            } else {
                // Otro error → lo registramos
                \Log::error("Error al setear descripción para artículo ID {$article->id}: " . $e->getMessage());
            }
        }
    }

    function get_price($article) {
        $price_type = $article->price_types()->where('se_usa_en_ml', 1)->first();

        return $price_type->pivot->final_price;
    }

    function add_attributes($meli_payload, $article) {
        // Relación: Article -> article_melee_atribute (pivote)
        // Asumo que la relación se llama "meli_attributes" en tu modelo Article
        $attributes = $article->meli_attributes()->get();

        $meli_payload['attributes'] = [];

        foreach ($article->meli_attributes as $article_meli_attributes) {

            Log::info('meli_attribute_id: '.$article_meli_attributes->pivot->meli_attribute_id);

            $meli_attribute = MeliAttribute::find($article_meli_attributes->pivot->meli_attribute_id);

            $attribute = [
                'id' => $meli_attribute->meli_id, // ej. BRAND, RAM, COLOR
            ];


            // Si tiene un value_id en Meli, lo mandamos
            if (!empty($article_meli_attributes->pivot->value_id)) {
                $attribute['value_id'] = $article_meli_attributes->pivot->value_id;
            }

            // Si no hay value_id, o si además queremos enviar el value_name (texto libre)
            if (!empty($article_meli_attributes->pivot->value_name)) {
                $attribute['value_name'] = $article_meli_attributes->pivot->value_name;
            }

            $meli_payload['attributes'][] = $attribute;
        }



        return $meli_payload;
    }

    function add_seller_address() {

          // array (
          //   'comment' => 'Duplex',
          //   'address_line' => 'Antonio Berni 237',
          //   'zip_code' => '5152',
          //   'city' => 
          //   array (
          //     'name' => 'Villa Carlos Paz',
          //   ),
          //   'state' => 
          //   array (
          //     'id' => 'AR-X',
          //     'name' => 'Córdoba',
          //   ),
          //   'country' => 
          //   array (
          //     'id' => 'AR',
          //     'name' => 'Argentina',
          //   ),
          //   'search_location' => 
          //   array (
          //     'city' => 
          //     array (
          //       'id' => 'TVhYVmlsbGEgQ2FybG9zIFBhelRVeEJVRU5QV',
          //       'name' => 'Villa Carlos Paz',
          //     ),
          //     'state' => 
          //     array (
          //       'id' => 'TUxBUENPUmFkZGIw',
          //       'name' => 'Córdoba',
          //     ),
          //   ),
          //   'latitude' => -31.4608248,
          //   'longitude' => -64.5089282,
          //   'id' => 100880110,
    }
}
