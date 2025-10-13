<?php

namespace App\Services\MercadoLibre;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Article;
use App\Models\ArticleMeliAttribute;
use App\Models\Description;
use App\Models\Image;
use App\Models\MeliAttribute;
use App\Models\MeliAttributeValue;
use App\Models\MeliBuyingMode;
use App\Models\MeliItemCondition;
use App\Models\MeliListingType;
use App\Models\MercadoLibreToken;
use App\Models\PriceType;
use App\Models\SyncFromMeliArticle;
use App\Models\User;
use App\Notifications\GlobalNotification;
use App\Services\MercadoLibre\CategoryService;
use App\Services\MercadoLibre\MercadoLibreService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductoDownloaderService extends MercadoLibreService
{
    protected $syncRecord;

    function __construct($user_id = null) {

        if (!$user_id) {
            $user_id = env('USER_ID');
        }

        parent::__construct($user_id);

        $this->user_id = $user_id;

        $this->user = User::find($this->user_id);

        $this->meli_token = MercadoLibreToken::where('user_id', $user_id)->first();

        $this->price_type_ml = PriceType::where('user_id', $this->user_id)
                                        ->where('se_usa_en_ml', 1)
                                        ->first();     

        $this->listing_types = MeliListingType::all();
        $this->buying_modes = MeliBuyingMode::all();
        $this->item_conditions = MeliItemCondition::all();

        $this->category_service = new CategoryService($this->user_id);
        $this->stock_ct = new StockMovementController();

        Log::info('User_id: '.$this->user_id);
    }

    /**
     * Importa productos desde Mercado Libre.
     * 
     * @param string $ml_user_id
     * @param string $modo 'create_only' o 'create_and_update'
     * @param SyncFromMeliArticle $syncRecord (registro de sync)
     */
    public function importar_productos(string $modo = 'create_only', $sync_from_meli_article_id = null)
    {
        $productos_importados = [];
        $limit = 50;
        $offset = 0;
        $total = null;
        $this->syncRecord = SyncFromMeliArticle::find($sync_from_meli_article_id);

        DB::beginTransaction();

        try {
            do {
                $url = "users/{$this->meli_token->meli_user_id}/items/search";
                $items_response = $this->make_request('GET', $url, [
                    'offset' => $offset, 
                    'limit' => $limit
                ]);

                if (empty($items_response['results'])) break;

                foreach ($items_response['results'] as $item_id) {
                    $this->procesarItem($item_id, $modo);
                }

                $total = $items_response['paging']['total'];
                $offset += $limit;

            } while ($offset < $total);

            DB::commit();

            $this->syncRecord->status = 'exitosa';
            $this->syncRecord->save();

            $this->notificacion();

            return [
                'message' => 'Productos importados correctamente',
                'data' => $productos_importados
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error en sincronización desde Mercado Libre: ".$e->getMessage());

            $this->syncRecord->status = 'error';
            $this->syncRecord->error_message_crudo = $e->getMessage();
            $this->syncRecord->save();

            $this->notificacion_error();

            throw $e;
        }
    }

    /**
     * Procesa un item individual de Mercado Libre según el modo.
     */
    protected function procesarItem(string $item_id, string $modo)
    {
        try {

            // Verificar si el artículo ya existe
            $existing = Article::where('me_li_id', $item_id)
                               ->where('user_id', $this->user_id)
                               ->first();


            if ($existing && $modo === 'create_only') {
                // Si ya existe y solo creamos → lo dejamos pasar
                // $this->attachSyncPivot($existing->id, 'skipped');
                Log::info('Se omitio articulo desde mercado libre');
                return;
            }

            $item_data = $this->make_request('GET', "items/{$item_id}");
            
            if (!$item_data) return;

            $article = $existing ?? $this->crearArticuloDesdeMeli($item_data);

            if ($article) {
                $this->attachSyncPivot($article->id, 'success');
            } else {
                $this->attachSyncPivot(null, 'error', 'CREATION_FAILED');
            }

        } catch (\Exception $e) {
            Log::error("Error procesando item {$item_id}: ");
            Log::info($e->getTraceAsString());

            $this->attachSyncPivot(null, 'error', $e->__toString() ?: 'EXCEPTION');
        }
    }

    /**
     * Crea un artículo en el sistema a partir de datos de Mercado Libre.
     */
    protected function crearArticuloDesdeMeli(array $item_data)
    {
        $article = Article::create([
            'me_li_id'                  => $item_data['id'],
            'name'                      => $item_data['title'],
            // 'price'                     => $item_data['price'],
            'user_id'                   => $this->user_id,
            'meli_listing_type_id'      => $this->get_listing_type_id($item_data),
            'meli_buying_mode_id'       => $this->get_buying_mode_id($item_data),
            'meli_item_condition_id'    => $this->get_item_condition_id($item_data),
        ]);

        // Stock
        $this->stock_movement($article, $item_data['available_quantity']);

        // Precio
        $this->asignar_precio($article, $item_data['price']);

        // Categoría
        $this->asignar_category_name($article);

        // Atributos
        $this->asignar_article_meli_attributes($article, $item_data);

        // Imágenes
        if (!empty($item_data['pictures'])) {
            foreach ($item_data['pictures'] as $picture) {
                Image::firstOrCreate([
                    'hosting_url'   => $picture['url'],
                    'imageable_type'=> 'article',
                    'imageable_id'  => $article->id,
                ]);
            }
        }

        // Descripción
        // try {
            $description_data = $this->make_request('GET', "items/{$item_data['id']}/description");

            Log::info('description_data:');
            Log::info($description_data);

            if (!empty($description_data['plain_text'])) {
                Description::create([
                    'content'    => $description_data['plain_text'],
                    'article_id' => $article->id,
                ]);
            }
        // } catch (\Exception $e) {
        //     Log::warning("No se encontró descripción para el item {$item_data['id']}: ".$e->getMessage());
        // }

        return $article;
    }

    /**
     * Adjunta estado al pivote de la sincronización
     */
    protected function attachSyncPivot(?int $article_id, string $status, ?string $error_code = null)
    {
        if (!$this->syncRecord || !$article_id) return;

        $this->syncRecord->articles()->attach($article_id, [
            'status'      => $status,
            'error_code'  => $error_code,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    function stock_movement($article, $stock) {

        $data = [];

        $data['model_id'] = $article->id;

        $data['amount'] = $stock;
        
        $data['concepto_stock_movement_name'] = 'Mercado Libre';

        $this->stock_ct->crear($data);
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

    function notificacion() {

        $functions_to_execute = [
            [
                'btn_text'      => 'Ver mas detalles',
                'function_name' => 'go_to_sync_from_meli',
                'btn_variant'   => 'primary',
            ],
            [
                'btn_text'      => 'Entendido',
                'btn_variant'   => 'outline-primary',
            ],
        ];

        $info_to_show = [
            [
                'title'     => 'Resultado de la operacion',
                'parrafos'  => [
                    count($this->syncRecord->articles). ' articulos creados',
                ],
            ],
        ];

        $user = User::find($this->syncRecord->user_id);

        $user->notify(new GlobalNotification([
            'message_text'              => 'Sincronizacion ENTRANTE con Mercado Libre finalizada correctamente',
            'color_variant'             => 'success',
            'functions_to_execute'      => $functions_to_execute,
            'info_to_show'              => $info_to_show,
            'owner_id'                  => $user->id,
            'is_only_for_auth_user'     => false,
            ])
        );
    }

    function notificacion_error() {

        $functions_to_execute = [
            [
                'btn_text'      => 'Entendido',
                'btn_variant'   => 'outline-primary',
            ],
        ];

        $info_to_show = [
            [
                'title'     => 'Error al sincronizar articulos con Mercado Libre',
                'parrafos'  => [
                    $this->syncRecord->error_message_crudo,
                ],
            ],
        ];

        $user = User::find($this->syncRecord->user_id);

        $user->notify(new GlobalNotification([
            'message_text'              => 'Error con Sincronizacion ENTRANTE con Mercado Libre',
            'color_variant'             => 'success',
            'functions_to_execute'      => $functions_to_execute,
            'info_to_show'              => $info_to_show,
            'owner_id'                  => $user->id,
            'is_only_for_auth_user'     => false,
            ])
        );
    }
}
