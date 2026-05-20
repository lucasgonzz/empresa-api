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
    /** @var SyncFromMeliArticle|null Registro de la corrida actual */
    protected $syncRecord;

    /** @var int Publicaciones listadas en ML en esta corrida */
    protected $meli_items_total = 0;

    /** @var int Artículos locales creados en esta corrida */
    protected $articles_created_count = 0;

    /** @var int Publicaciones ML que ya tenían artículo local vinculado (no se modifican) */
    protected $articles_skipped_count = 0;

    /** @var int Ítems que no pudieron importarse */
    protected $articles_error_count = 0;

    /**
     * Inicializa token ML, catálogos auxiliares y servicios de stock/categoría.
     *
     * @param int|null $user_id Tenant dueño de la sincronización.
     */
    public function __construct($user_id = null)
    {
        if (!$user_id) {
            $user_id = config('app.USER_ID');
        }

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
        $this->stock_ct = new StockMovementController();

        Log::info('ProductoDownloaderService user_id: '.$this->user_id);
    }

    /**
     * Importa publicaciones desde Mercado Libre hacia artículos locales.
     *
     * Reglas (modo create_only):
     * - Si ya existe un artículo con el mismo me_li_id → no se modifica.
     * - Si no existe → se crea vinculado con datos básicos de ML.
     *
     * @param string $modo Modo de importación (`create_only` recomendado).
     * @param int|null $sync_from_meli_article_id Id del registro de sincronización.
     * @return array<string, mixed>
     */
    public function importar_productos(string $modo = 'create_only', $sync_from_meli_article_id = null)
    {
        $limit = 50;
        $offset = 0;
        $total = 0;
        $this->syncRecord = SyncFromMeliArticle::find($sync_from_meli_article_id);

        if (!$this->syncRecord) {
            throw new \RuntimeException('No se encontró el registro de sincronización.');
        }

        if (empty($this->platform_connector->platform_user_id)) {
            throw new \RuntimeException('No hay cuenta de Mercado Libre conectada para este usuario.');
        }

        if (!$this->price_type_ml) {
            throw new \RuntimeException('Configurá una lista de precios con "se usa en Mercado Libre" antes de importar.');
        }

        $this->reset_summary_counters();
        $this->mark_sync_in_progress();

        DB::beginTransaction();

        try {
            do {
                $url = 'users/'.$this->meli_seller_id().'/items/search';
                $items_response = $this->make_request('GET', $url, [
                    'offset' => $offset,
                    'limit'  => $limit,
                ]);

                if (empty($items_response['results'])) {
                    break;
                }

                foreach ($items_response['results'] as $item_id) {
                    $this->procesarItem($item_id, $modo);
                }

                $total = (int) ($items_response['paging']['total'] ?? 0);
                $offset += $limit;
            } while ($offset < $total);

            DB::commit();

            $this->persist_sync_success();

            $this->notificacion();

            return [
                'message' => 'Productos importados correctamente',
                'sync'    => $this->syncRecord->fresh(),
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error en sincronización desde Mercado Libre: '.$e->getMessage());

            $this->persist_sync_error($e->getMessage());
            $this->notificacion_error();

            throw $e;
        }
    }

    /**
     * Reinicia contadores en memoria al iniciar una corrida.
     *
     * @return void
     */
    protected function reset_summary_counters(): void
    {
        $this->meli_items_total = 0;
        $this->articles_created_count = 0;
        $this->articles_skipped_count = 0;
        $this->articles_error_count = 0;
    }

    /**
     * Marca el registro de sync como en progreso.
     *
     * @return void
     */
    protected function mark_sync_in_progress(): void
    {
        $this->syncRecord->status = SyncFromMeliArticle::STATUS_EN_PROGRESO;
        $this->syncRecord->attempted_at = now();
        $this->syncRecord->synced_at = null;
        $this->syncRecord->error_message = null;
        $this->syncRecord->error_message_crudo = null;
        $this->syncRecord->summary_message = null;
        $this->syncRecord->save();
    }

    /**
     * Persiste contadores y mensaje de resumen al finalizar con éxito.
     *
     * @return void
     */
    protected function persist_sync_success(): void
    {
        $linked_total = $this->articles_created_count + $this->articles_skipped_count;

        $this->syncRecord->meli_items_total = $this->meli_items_total;
        $this->syncRecord->articles_created_count = $this->articles_created_count;
        $this->syncRecord->articles_skipped_count = $this->articles_skipped_count;
        $this->syncRecord->articles_error_count = $this->articles_error_count;
        $this->syncRecord->articles_linked_total_count = $linked_total;
        $this->syncRecord->status = SyncFromMeliArticle::STATUS_EXITOSA;
        $this->syncRecord->synced_at = now();
        $this->syncRecord->summary_message = $this->build_summary_message();
        $this->syncRecord->save();
    }

    /**
     * Persiste error fatal de la corrida completa.
     *
     * @param string $message Mensaje de error.
     * @return void
     */
    protected function persist_sync_error(string $message): void
    {
        $this->syncRecord->meli_items_total = $this->meli_items_total;
        $this->syncRecord->articles_created_count = $this->articles_created_count;
        $this->syncRecord->articles_skipped_count = $this->articles_skipped_count;
        $this->syncRecord->articles_error_count = $this->articles_error_count;
        $this->syncRecord->articles_linked_total_count = $this->articles_created_count + $this->articles_skipped_count;
        $this->syncRecord->status = SyncFromMeliArticle::STATUS_ERROR;
        $this->syncRecord->error_message_crudo = $message;
        $this->syncRecord->error_message = $message;
        $this->syncRecord->save();
    }

    /**
     * Arma texto de resumen legible para la UI.
     *
     * @return string
     */
    protected function build_summary_message(): string
    {
        $linked_total = $this->articles_created_count + $this->articles_skipped_count;

        return 'Se obtuvieron '.$this->meli_items_total.' publicaciones desde Mercado Libre. '
            .'Se crearon '.$this->articles_created_count.' artículos nuevos en el sistema. '
            .'Se omitieron '.$this->articles_skipped_count.' que ya estaban vinculados. '
            .($this->articles_error_count > 0
                ? 'Hubo '.$this->articles_error_count.' con error. '
                : '')
            .'Total vinculados en el sistema: '.$linked_total.'.';
    }

    /**
     * Procesa un ítem individual de Mercado Libre según el modo.
     *
     * @param string $item_id Id de publicación ML (ej. MLA123).
     * @param string $modo Modo de importación.
     * @return void
     */
    protected function procesarItem(string $item_id, string $modo)
    {
        $this->meli_items_total++;

        try {
            $existing = Article::where('me_li_id', $item_id)
                ->where('user_id', $this->user_id)
                ->first();

            if ($existing && $modo === 'create_only') {
                $this->articles_skipped_count++;
                $this->attachSyncPivot($existing->id, 'skipped');
                Log::info('Se omitió artículo ML ya vinculado: '.$item_id);

                return;
            }

            $item_data = $this->make_request('GET', "items/{$item_id}");

            if (!$item_data) {
                $this->articles_error_count++;

                return;
            }

            $was_new = !$existing;
            $article = $existing ?? $this->crearArticuloDesdeMeli($item_data);

            if ($article) {
                if ($was_new) {
                    $this->articles_created_count++;
                    $this->attachSyncPivot($article->id, 'created');
                } else {
                    $this->attachSyncPivot($article->id, 'updated');
                }
            } else {
                $this->articles_error_count++;
                $this->attachSyncPivot(null, 'error', 'CREATION_FAILED');
            }
        } catch (\Exception $e) {
            $this->articles_error_count++;
            Log::error('Error procesando item '.$item_id.': '.$e->getMessage());
            $this->attachSyncPivot(null, 'error', $e->getMessage() ?: 'EXCEPTION');
        }
    }

    /**
     * Crea un artículo en el sistema a partir de datos de Mercado Libre.
     *
     * @param array<string, mixed> $item_data Payload GET /items/{id}.
     * @return Article|null
     */
    protected function crearArticuloDesdeMeli(array $item_data)
    {
        $meli_descripcion = null;
        $description_data = $this->make_request('GET', "items/{$item_data['id']}/description");
        if (!empty($description_data['plain_text'])) {
            $meli_descripcion = $description_data['plain_text'];
        }

        $article = Article::create([
            'me_li_id'               => $item_data['id'],
            'name'                   => $item_data['title'],
            'user_id'                => $this->user_id,
            'mercado_libre'          => 1,
            'meli_listing_type_id'   => $this->get_listing_type_id($item_data),
            'meli_buying_mode_id'    => $this->get_buying_mode_id($item_data),
            'meli_item_condition_id' => $this->get_item_condition_id($item_data),
            'meli_descripcion'       => $meli_descripcion,
        ]);

        // Stock
        $this->stock_movement($article, $item_data['available_quantity']);

        // Precio
        $this->asignar_precio($article, $item_data['price']);

        // Categoría (prioriza category_id del ítem ML; crea MeliCategory si no existe)
        $this->asignar_meli_category_desde_item($article, $item_data);

        // Atributos (auto-crea MeliAttribute / MeliAttributeValue si faltan en BD)
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

        if (!empty($meli_descripcion)) {
            Description::create([
                'content'    => $meli_descripcion,
                'article_id' => $article->id,
            ]);
        }

        return $article;
    }

    /**
     * Adjunta estado al pivote de la sincronización (detalle por artículo).
     *
     * @param int|null $article_id Id local; null si falló antes de crear.
     * @param string $status created|skipped|updated|error.
     * @param string|null $error_code Detalle del error si aplica.
     * @return void
     */
    protected function attachSyncPivot(?int $article_id, string $status, ?string $error_code = null)
    {
        if (!$this->syncRecord || !$article_id) {
            return;
        }

        $this->syncRecord->articles()->attach($article_id, [
            'status'     => $status,
            'error_code' => $error_code,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Registra movimiento de stock inicial importado desde ML.
     *
     * @param Article $article Artículo recién creado.
     * @param int|float $stock Cantidad disponible en ML.
     * @return void
     */
    protected function stock_movement($article, $stock)
    {

        $data = [];

        $data['model_id'] = $article->id;

        $data['amount'] = $stock;
        
        $data['concepto_stock_movement_name'] = 'Mercado Libre';

        $this->stock_ct->crear($data);
    }

    /**
     * Asigna precio ML al artículo usando la lista marcada con se_usa_en_ml.
     *
     * @param Article $article Artículo local.
     * @param float|int $price Precio en ML.
     * @return void
     */
    protected function asignar_precio($article, $price)
    {
        $article->price_types()->attach($this->price_type_ml->id, [
            'setear_precio_final'   => 1,
            'final_price'           => $price,
        ]); 

        ArticleHelper::setFinalPrice($article, $this->user_id, $this->user);
    }

    /**
     * Resuelve id local de tipo de publicación ML; si no existe en BD, lo crea y lo cachea en memoria.
     *
     * @param array<string, mixed> $item_data Datos del ítem ML.
     * @return int|null
     */
    protected function get_listing_type_id($item_data)
    {
        $meli_id = $item_data['listing_type_id'] ?? null;
        if ($meli_id === null || $meli_id === '') {
            return null;
        }

        $known_labels = [
            'gold_pro'      => 'Premium',
            'gold_special'  => 'Clásica',
            'gold_premium'  => 'Oro Premium',
            'silver'        => 'Plata',
            'gold'          => 'Oro',
            'free'          => 'Gratuita',
        ];

        return $this->resolve_or_create_meli_catalog_id(
            $meli_id,
            MeliListingType::class,
            'listing_types',
            $known_labels
        );
    }

    /**
     * Resuelve id local de modo de compra ML; si no existe en BD, lo crea y lo cachea en memoria.
     *
     * @param array<string, mixed> $item_data Datos del ítem ML.
     * @return int|null
     */
    protected function get_buying_mode_id($item_data)
    {
        $meli_id = $item_data['buying_mode'] ?? null;
        if ($meli_id === null || $meli_id === '') {
            return null;
        }

        $known_labels = [
            'buy_it_now' => 'Compre Ya (buy_it_now)',
            'auction'    => 'Subasta',
            'classified' => 'Clasificado',
        ];

        return $this->resolve_or_create_meli_catalog_id(
            $meli_id,
            MeliBuyingMode::class,
            'buying_modes',
            $known_labels
        );
    }

    /**
     * Resuelve id local de condición del ítem ML; si no existe en BD, lo crea y lo cachea en memoria.
     *
     * @param array<string, mixed> $item_data Datos del ítem ML.
     * @return int|null
     */
    protected function get_item_condition_id($item_data)
    {
        $meli_id = $item_data['condition'] ?? null;
        if ($meli_id === null || $meli_id === '') {
            return null;
        }

        $known_labels = [
            'new'            => 'Nuevo',
            'used'           => 'Usado',
            'reconditioned'  => 'Reacondicionado',
            'not_specified'  => 'No especificado',
        ];

        return $this->resolve_or_create_meli_catalog_id(
            $meli_id,
            MeliItemCondition::class,
            'item_conditions',
            $known_labels
        );
    }

    /**
     * Busca un catálogo ML por meli_id en la colección en memoria o lo persiste con firstOrCreate.
     *
     * @param string $meli_id Código ML (listing_type_id, buying_mode, condition, etc.).
     * @param class-string<\Illuminate\Database\Eloquent\Model> $model_class Modelo Eloquent del catálogo.
     * @param string $collection_property Propiedad de esta clase donde se cachea la colección.
     * @param array<string, string> $known_labels Nombres legibles conocidos para el meli_id.
     * @return int|null PK local del registro.
     */
    protected function resolve_or_create_meli_catalog_id($meli_id, $model_class, $collection_property, array $known_labels = [])
    {
        $collection = $this->{$collection_property};
        $existing = $collection->where('meli_id', $meli_id)->first();
        if ($existing) {
            return $existing->id;
        }

        $display_name = $this->meli_catalog_display_name($meli_id, $known_labels);

        $record = $model_class::firstOrCreate(
            ['meli_id' => $meli_id],
            ['name' => $display_name]
        );

        $collection->push($record);

        Log::info("Auto-creado catálogo ML {$model_class} meli_id={$meli_id} id_local={$record->id}");

        return $record->id;
    }

    /**
     * Nombre legible para un registro de catálogo ML a partir de su meli_id.
     *
     * @param string $meli_id
     * @param array<string, string> $known_labels
     * @return string
     */
    protected function meli_catalog_display_name($meli_id, array $known_labels = [])
    {
        if (isset($known_labels[$meli_id])) {
            return $known_labels[$meli_id];
        }

        return ucfirst(str_replace('_', ' ', $meli_id));
    }

    /**
     * Asigna categoría ML al artículo usando category_id del ítem importado o el predictor como respaldo.
     *
     * @param Article $article Artículo local recién creado.
     * @param array<string, mixed> $item_data Payload GET /items/{id}.
     * @return void
     */
    protected function asignar_meli_category_desde_item(Article $article, array $item_data)
    {
        $meli_category_id_ml = $item_data['category_id'] ?? null;

        if ($meli_category_id_ml) {
            Log::info("asignar_meli_category_desde_item article_id={$article->id} category_id={$meli_category_id_ml}");
            $this->category_service->assign_to_article($article, $meli_category_id_ml);

            return;
        }

        Log::info('asignar_meli_category_desde_item: sin category_id en ML, se usa predictor para article_id='.$article->id);
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

    /**
     * Notifica al usuario el resumen de la importación exitosa.
     *
     * @return void
     */
    protected function notificacion()
    {
        $functions_to_execute = [
            [
                'btn_text'      => 'Ver mas detalles',
                'function_name' => 'go_to_sync_from_meli',
                'btn_variant'   => 'primary',
            ],
            [
                'btn_text'    => 'Entendido',
                'btn_variant' => 'outline-primary',
            ],
        ];

        $info_to_show = [
            [
                'title'    => 'Importación desde Mercado Libre',
                'parrafos' => [
                    $this->syncRecord->summary_message,
                ],
            ],
        ];

        $user = User::find($this->syncRecord->user_id);
        if (!$user) {
            return;
        }

        $user->notify(new GlobalNotification([
            'message_text'          => 'Importación desde Mercado Libre finalizada',
            'color_variant'         => 'success',
            'functions_to_execute'  => $functions_to_execute,
            'info_to_show'          => $info_to_show,
            'owner_id'              => $user->id,
            'is_only_for_auth_user' => false,
        ]));
    }

    /**
     * Notifica error fatal de la corrida.
     *
     * @return void
     */
    protected function notificacion_error()
    {
        $functions_to_execute = [
            [
                'btn_text'    => 'Entendido',
                'btn_variant' => 'outline-primary',
            ],
        ];

        $info_to_show = [
            [
                'title'    => 'Error al importar desde Mercado Libre',
                'parrafos' => [
                    $this->syncRecord->error_message_crudo,
                ],
            ],
        ];

        $user = User::find($this->syncRecord->user_id);
        if (!$user) {
            return;
        }

        $user->notify(new GlobalNotification([
            'message_text'          => 'Error al importar artículos desde Mercado Libre',
            'color_variant'         => 'danger',
            'functions_to_execute'  => $functions_to_execute,
            'info_to_show'          => $info_to_show,
            'owner_id'              => $user->id,
            'is_only_for_auth_user' => false,
        ]));
    }
}
