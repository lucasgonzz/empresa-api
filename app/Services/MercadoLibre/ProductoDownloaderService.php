<?php

namespace App\Services\MercadoLibre;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Article;
use App\Models\ArticleMeliAttribute;
use App\Models\Description;
use App\Models\Image;
use App\Models\MeliAttribute;
use App\Models\MeliAttributeValue;
use App\Models\MeliBuyingMode;
use App\Models\MeliItemCondition;
use App\Models\MeliListingType;
use App\Models\PriceType;
use App\Models\User;
use App\Services\MercadoLibre\CategoryService;
use App\Services\MercadoLibre\MercadoLibreService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductoDownloaderService extends MercadoLibreService
{

    function __construct($user_id = null) {
        parent::__construct($user_id);

        $this->user_id = $user_id;

        $this->user = User::find($this->user_id);

        $this->price_type_ml = PriceType::where('user_id', $this->user_id)
                                        ->where('se_usa_en_ml', 1)
                                        ->first();     

        $this->listing_types = MeliListingType::all();

        $this->buying_modes = MeliBuyingMode::all();

        $this->item_conditions = MeliItemCondition::all();

        $this->category_service = new CategoryService($this->user_id);

        Log::info('User_id: '.$this->user_id);
        
        Log::info('User: '.$this->user->id);

    }

    public function importar_productos(string $ml_user_id)
    {
        $productos_importados = [];
        $limit = 1;
        $offset = 0;
        $total = null;

        DB::beginTransaction();

        try {
            do {

                $url = "users/{$ml_user_id}/items/search";

                $items_response = $this->make_request('GET', $url, [
                    'offset' => $offset, 
                    'limit' => $limit
                ]);

                if (!isset($items_response['results']) || empty($items_response['results'])) {
                    break;
                }

                $item_ids = $items_response['results'];


                foreach ($item_ids as $item_id) {
                    $item_data = $this->make_request('GET', "items/{$item_id}");
                    if (!$item_data) {
                        continue;
                    }

                    Log::info('item: ');
                    Log::info($item_data);

                    // foreach ($item_date['attributes'] as $attribute) {
                    //     Log::info()
                    // }

                    $article = Article::updateOrCreate(
                        ['me_li_id' => $item_data['id']],
                        [
                            'name'                      => $item_data['title'],
                            'price'                     => $item_data['price'],
                            'stock'                     => $item_data['available_quantity'],
                            'meli_category_id'          => $item_data['category_id'] ?? null,
                            'user_id'                   => $this->user_id,
                            'meli_listing_type_id'      => $this->get_listing_type_id($item_data),
                            'meli_buying_mode_id'       => $this->get_buying_mode_id($item_data),
                            'meli_item_condition_id'    => $this->get_item_condition_id($item_data),
                        ]
                    );

                    $this->asignar_precio($article, $item_data['price']);

                    $this->asignar_category_name($article);

                    $this->asignar_article_meli_attributes($article, $item_data);

                    if (isset($item_data['pictures']) && is_array($item_data['pictures'])) {
                        foreach ($item_data['pictures'] as $picture) {

                            $image = Image::where('hosting_url', $picture['url'])->first();

                            if (!$image) {

                                Image::create([
                                    'hosting_url'   => $picture['url'],
                                    'imageable_type'=> 'article',
                                    'imageable_id'  => $article->id,
                                ]);
                            }
                        }
                    }

                    try {
                        $description_data = $this->make_request('GET', "items/{$item_id}/description");

                        Log::info('description_data: ');
                        Log::info($description_data);

                        if (!empty($description_data['plain_text'])) {
                            Description::create([
                                'content'    => $description_data['plain_text'],
                                'article_id' => $article->id,
                            ]);
                        }
                    } catch (\Exception $e) {
                        // Si no existe descripción, logueamos y seguimos
                        Log::warning("No se encontró descripción para el item {$item_id}: " . $e->getMessage());
                    }

                    $productos_importados[] = $article;
                }

                // Actualizamos paginación
                $total = $items_response['paging']['total'];
                $offset += $limit;

                Log::info('total: '.$total);
                Log::info('offset: '.$offset);

            // } while ($offset < $total);
            } while (false);

            Log::info('Termino');

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return [
            'message' => 'Productos importados correctamente',
            'data' => $productos_importados
        ];
    }


    function asignar_precio($article, $price) {
        $article->price_types()->attach($this->price_type_ml->id, [
            'setear_precio_final'   => 1,
            'final_price'           => $price,
        ]); 

        ArticleHelper::setFinalPrice($article, $this->user_id, $this->user);
    }



    function get_listing_type_id($item_data) {
        $listing_type = $this->listing_types->where('meli_id', $item_data['listing_type_id'])->first();

        return $listing_type->id;
    }

    function get_buying_mode_id($item_data) {
        $buying_mode = $this->buying_modes->where('meli_id', $item_data['buying_mode'])->first();

        return $buying_mode->id;
    }

    function get_item_condition_id($item_data) {
        $item_condition = $this->item_conditions->where('meli_id', $item_data['condition'])->first();

        return $item_condition->id;
    }




    function asignar_category_name($article) {

        Log::info('asignar_category_name');

        $this->category_service->resolve_meli_category_for_article($article);

    }

    /**
     * Toma los attributes que vienen en $item_data y:
     * - Garantiza que exista el MeliAttribute (auto-crea si no existe)
     * - Garantiza que exista el MeliAttributeValue cuando haya value_id (auto-crea si no existe)
     * - Crea/actualiza el pivote ArticleMeliAttribute de forma idempotente
     *
     * Requisitos de modelos/columnas asumidos:
     * - meli_attributes: id (PK), meli_id (string), name, value_type, meli_category_id (FK a meli_categories)
     * - meli_attribute_values: id (PK), meli_id (string), meli_name (string), meli_attribute_id (FK)
     * - article_meli_attribute: id, article_id, meli_attribute_id (FK local), meli_attribute_value_id (FK local nullable), value_id (string nullable), value_name (string nullable)
     */
    protected function asignar_article_meli_attributes(\App\Models\Article $article, array $item_data): void
    {
        if (empty($item_data['attributes']) || !is_array($item_data['attributes'])) {
            \Log::info("ML asignación de atributos: el item {$item_data['id']} no trae 'attributes'.");
            return;
        }

        foreach ($item_data['attributes'] as $attribute) {
            // Seguridad: requerimos al menos id del atributo
            if (empty($attribute['id'])) {
                \Log::warning("ML atributo sin 'id' en item {$item_data['id']}", ['attribute' => $attribute]);
                continue;
            }

            $meli_attr_id_ml  = $attribute['id'];                          // ej: "BRAND"
            $meli_value_id_ml = $attribute['value_id'] ?? null;            // ej: "52055" (puede venir null para string)
            $meli_value_name  = $attribute['value_name'] ?? null;          // ej: "Samsung" / "Negro" / "128 GB"
            $value_type       = $attribute['value_type'] ?? null;          // puede venir en item_data o lo tenemos en la definición de categoría

            // 1) Resolver / crear el MeliAttribute (por meli_id + categoría)
            $meli_attribute = \App\Models\MeliAttribute::where('meli_id', $meli_attr_id_ml)
                ->when(!empty($article->meli_category_id), function ($q) use ($article) {
                    $q->where('meli_category_id', $article->meli_category_id);
                })
                ->first();

            if (!$meli_attribute) {
                // 🔧 Auto-sanar: crear definición mínima del atributo si por algún motivo
                // no se sincronizó aún desde /categories/{id}/attributes
                $meli_attribute = \App\Models\MeliAttribute::firstOrCreate(
                    [
                        'meli_id'          => $meli_attr_id_ml,
                        'meli_category_id' => $article->meli_category_id, // puede ser null si aún no asignaste categoría
                    ],
                    [
                        'name'       => $attribute['name'] ?? $meli_attr_id_ml,
                        'value_type' => $value_type ?? 'string',
                    ]
                );

                \Log::warning("Auto-creado MeliAttribute faltante", [
                    'meli_id' => $meli_attr_id_ml,
                    'meli_category_id' => $article->meli_category_id,
                    'item_id' => $item_data['id'],
                ]);
            }

            // 2) Resolver / crear el MeliAttributeValue si hay value_id
            $meli_attribute_value_id = null;

            if (!empty($meli_value_id_ml)) {
                $meli_attribute_value = \App\Models\MeliAttributeValue::where('meli_id', $meli_value_id_ml)
                    ->where('meli_attribute_id', $meli_attribute->id)
                    ->first();

                if (!$meli_attribute_value) {
                    // 🔧 Auto-sanar: crear el value si vino en el item y no estaba en el catálogo
                    $meli_attribute_value = \App\Models\MeliAttributeValue::firstOrCreate(
                        [
                            'meli_id'           => $meli_value_id_ml,
                            'meli_attribute_id' => $meli_attribute->id,
                        ],
                        [
                            'meli_name' => $meli_value_name ?? $meli_value_id_ml,
                        ]
                    );

                    \Log::warning("Auto-creado MeliAttributeValue faltante", [
                        'meli_attribute_id' => $meli_attribute->id,
                        'value_meli_id'     => $meli_value_id_ml,
                        'value_meli_name'   => $meli_value_name,
                        'item_id'           => $item_data['id'],
                    ]);
                }

                $meli_attribute_value_id = $meli_attribute_value->id;
            }

            // 3) Guardar/actualizar pivote ArticleMeliAttribute
            \App\Models\ArticleMeliAttribute::updateOrCreate(
                [
                    'article_id'        => $article->id,
                    'meli_attribute_id' => $meli_attribute->id,
                ],
                [
                    'meli_attribute_value_id' => $meli_attribute_value_id,
                    'value_id'                => $meli_value_id_ml,
                    'value_name'              => $meli_value_name,
                ]
            );
        }
    }
}
