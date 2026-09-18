<?php

namespace App\Models;

use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Http\Controllers\Helpers\UserHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

class Article extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    /**
     * `embedding` nunca sale en JSON (misión optimizacion-vps-fase1, 4.0.24).
     *
     * Es el vector de 1536 floats del agente de WhatsApp: ~29 KB por fila. En ferretotal 427 MB
     * de los 488 MB de la tabla son embeddings, y viajaban en cada página del listado (500
     * artículos = ~15 MB de números que ningún front lee: empresa-spa no lo usa, grep del
     * 10/9/2026, sólo un comentario). $hidden afecta toArray()/toJson()/jsonSerialize(), NO el
     * acceso al atributo: ArticleEmbeddingService, los observers, DuplicarRecetaHelper y el
     * comando articles:generate-embeddings leen $article->embedding y lo siguen viendo. Y $hidden
     * no alcanza solo: el vector igual se lee de la base y se hidrata en memoria; para eso está
     * scopeSinEmbedding().
     *
     * @var array
     */
    protected $hidden = ['embedding'];

    /**
     * Cache por proceso de las columnas de `articles` menos `embedding`, ya prefijadas con la
     * tabla. Schema::getColumnListing() es un SELECT a information_schema por llamada: un request
     * de listado lo paga una vez, un worker de cola una vez por vida del proceso.
     *
     * @var array|null null = todavía no se consultó.
     */
    protected static $columnas_sin_embedding = null;

    protected $dates = ['stock_updated_at', 'final_price_updated_at'];

    /**
     * Casts: medida es la magnitud del contenido (ej. 2.5) para la unidad_medida elegida.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'medida' => 'decimal:4',
    ];

    // protected $appends = ['costo_real'];

    // Hotfix Prompt 313: `precios_por_metodo_pago` no es una columna real (es un dato calculado por
    // request segun las reglas de recargo del usuario). Se expone via $appends + accessor para que
    // siga viniendo en el JSON del articulo como antes, sin que Eloquent la trate como atributo a
    // persistir en ningun save() posterior.
    protected $appends = ['precios_por_metodo_pago'];

    /**
     * Scope: todas las columnas de `articles` menos `embedding`, para no leer ni hidratar 29 KB
     * por fila en consultas que no vectorizan nada. Va ANTES de withAll() en
     * ArticleController::index() (el listado y la sincronización offline), que es la respuesta
     * más pesada del sistema.
     *
     * Las columnas van prefijadas (`articles`.`id`, ...) para que el mismo scope sirva en consultas
     * con join a otra tabla que también tenga `id` (sin prefijo MySQL tira "Column 'id' ambiguous");
     * Eloquent hidrata por el nombre de columna que devuelve MySQL, sin prefijo, así que el modelo
     * queda igual. paginate() reemplaza el select por COUNT(*) para contar, y las relaciones de
     * withAll() matchean por `articles`.`id`, que está en la lista.
     *
     * 🔴 Es un select explícito: si otro código encadena ->select() después, lo pisa (y
     * ->addSelect() le suma columnas); y si después de este scope alguien lee $article->embedding
     * recibe null, no un error. Para leer el vector se consulta sin el scope.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    function scopeSinEmbedding($query) {
        $query->select(self::columnas_sin_embedding());
    }

    /**
     * Columnas de `articles` menos `embedding`, prefijadas con la tabla y cacheadas en la
     * estática (ver $columnas_sin_embedding).
     *
     * @return array
     */
    static function columnas_sin_embedding() {
        if (is_null(static::$columnas_sin_embedding)) {
            $tabla = (new static)->getTable();

            $columnas = [];
            foreach (Schema::getColumnListing($tabla) as $columna) {
                if ($columna === 'embedding') {
                    continue;
                }
                $columnas[] = $tabla . '.' . $columna;
            }

            static::$columnas_sin_embedding = $columnas;
        }

        return static::$columnas_sin_embedding;
    }

    function scopeWithAll($query) {
        $query->with('images', 'iva', 'sizes', 'colors', 'condition', 'descriptions', 'category', 'sub_category', 'tags', 'brand', 'article_discounts', 'provider_price_list', 'deposits', 'article_properties.article_property_values', 'article_variants.article_property_values', 'article_variants.addresses', 'addresses', 'price_types', 'article_discounts_blanco', 'article_surchages', 'article_surchages_blanco', 'price_type_monedas', 'meli_category', 'article_ubications', 'article_price_ranges', 'providers', 'sales_with_deliveries_in_acopio', 'provider');
    }

    /**
     * Las mismas relaciones de withAll() MENOS `sales_with_deliveries_in_acopio` (27 en vez de 28).
     *
     * Esa relacion (linea ~306) es un belongsToMany a Sale que joinea article_sale y sales filtrando
     * por en_acopio y por delivered_amount NOT NULL. Es la mas cara del paquete y en el flujo de
     * VENDER no la usa nadie: medido el 1/9/2026, el UNICO consumidor en todo empresa-spa es
     * src/components/listado/components/Buttons.vue (pantalla Listado de articulos), que se alimenta
     * de ArticleController::index() -- y ese sigue usando withAll(), sin cambios.
     *
     * Las DOS pantallas que consumen el endpoint que usa este scope
     * (GET vender/buscar-articulo-por-codido/{code}, routes/api.php:321) no la leen:
     *   - vender/components/remito/header-form/ArticleBarCode.vue (arma el item de venta con
     *     set_item_vender: usa precios, descuentos, imagenes, direcciones/deposito, no acopio).
     *   - consultora-de-precios/components/buscador/BuscadorInput.vue (InfoArticle.vue solo lee
     *     article.name, el accessor de precio y variant.variant_description).
     *
     * NO CONVERTIR ESTO EN "withAll() menos una relacion" tocando withAll(): withAll() lo usan ~12
     * controllers mas (ArticleController::index(), etc.) y sacarle acopio le rompe el badge de
     * Buttons.vue, que hace .length sobre la propiedad sin guarda. La lista va duplicada a proposito,
     * y el test 15_Indices_De_Venta_Y_Vender_Test::las_dos_listas_difieren_en_una_sola_relacion falla
     * si las dos listas se separan en algo mas que sales_with_deliveries_in_acopio.
     */
    function scopeWithAllSinAcopio($query) {
        $query->with('images', 'iva', 'sizes', 'colors', 'condition', 'descriptions', 'category', 'sub_category', 'tags', 'brand', 'article_discounts', 'provider_price_list', 'deposits', 'article_properties.article_property_values', 'article_variants.article_property_values', 'article_variants.addresses', 'addresses', 'price_types', 'article_discounts_blanco', 'article_surchages', 'article_surchages_blanco', 'price_type_monedas', 'meli_category', 'article_ubications', 'article_price_ranges', 'providers', 'provider');
    }

    public function article_price_ranges()
    {
        return $this->hasMany(ArticlePriceRange::class);
    }

    public function article_ubications()
    {
        return $this->belongsToMany(ArticleUbication::class)->withPivot('ubication', 'notes');
    }

    public function meli_listing_type()
    {
        return $this->belongsTo(MeliListingType::class);
    }

    public function meli_buying_mode()
    {
        return $this->belongsTo(MeliBuyingMode::class);
    }

    public function meli_item_condition()
    {
        return $this->belongsTo(MeliItemCondition::class);
    }

    public function meli_attributes()
    {
        return $this->belongsToMany(MeliAttribute::class)->withPivot('value_id', 'value_name', 'meli_attribute_id');
    }

    public function meli_category()
    {
        return $this->belongsTo(MeliCategory::class);
    }

    public function price_type_monedas()
    {
        return $this->hasMany(ArticlePriceTypeMoneda::class);
    }

    public function price_type_tienda_nube()
    {
        return $this->price_types()
                ->where('se_usa_en_tienda_nube', 1)
                ->first();
    }

    public function lastStockMovement() {
        return $this->hasOne(StockMovement::class)->latestOfMany();
    }

    function price_changes() {
        return $this->hasMany(PriceChange::class);
    }

    function tipo_envase() {
        return $this->belongsTo(TipoEnvase::class);
    }

    // public function getCostoRealAttribute() {
    //     return $this->cost;
    //     if (!is_null(Auth()->user())) {
    //         $owner = UserHelper::user();
    //         $cost = $this->cost;
    //         if (!is_null($this->cost) && !is_null($owner)) {
    //             if ($this->cost_in_dollars) {
    //                 if (!is_null($this->provider) && !is_null($this->provider->dolar)) {
    //                     $cost = $cost * (float)$this->provider->dolar;
    //                 } else {
    //                     $cost = $cost * $owner->dollar;
    //                 }
    //             }
    //             foreach ($this->article_discounts as $discount) {
    //                 $cost -= $cost * (float)$discount->percentage / 100;
    //             }
    //             if (!is_null($this->iva) 
    //                 && !$owner->iva_included
    //                 && $this->iva->percentage != 0 
    //                 && $this->iva->percentage != 'Extento'
    //                 && $this->iva->percentage != 'No Gravado') {
    //                 $cost += $cost * (float)$this->iva->percentage / 100;
    //             }
    //         }
    //         return $cost;
    //     }
    // }

    /**
     * Accessor de `precios_por_metodo_pago` (Hotfix Prompt 313, hereda de Capa 3 Prompt 263).
     * Devuelve el desglose de precio equivalente por metodo de pago (con tarjeta incluida) para
     * que el SPA de Vender (Prompt 266) lo muestre sin recalcular. Se calcula al vuelo en cada
     * lectura, nunca se persiste: no existe como columna en `articles`.
     *
     * @return array|null null si no hay usuario autenticado, o si el flag
     *                     `precio_base_incluye_tarjeta` esta apagado / no hay recargos configurados.
     */
    public function getPreciosPorMetodoPagoAttribute() {
        if (is_null(Auth()->user())) {
            return null;
        }
        return ArticlePricesHelper::calcular_precios_por_metodo_pago_con_tarjeta_incluida($this->final_price, UserHelper::userId());
    }

    function unidad_medida() {
        return $this->belongsTo(UnidadMedida::class);
    }

    function stock_movements() {
        return $this->hasMany(StockMovement::class);
    }

    function price_types() {
        return $this->belongsToMany(PriceType::class)->withPivot('percentage', 'price', 'final_price', 'previus_final_price', 'incluir_en_excel_para_clientes', 'setear_precio_final', 'precio_luego_de_recargos', 'monto_ganancia');
    }

    function cart() {
        return $this->belongsToMany(Cart::class)->using(ArticleCart::class);
    }

    function addresses() {
        return $this->belongsToMany(Address::class)->withPivot('amount', 'stock_min', 'stock_max');
    }

    function article_properties() {
        return $this->hasMany(ArticleProperty::class);
    }

    function article_variants() {
        return $this->hasMany(ArticleVariant::class);
    }

    function views() {
        return $this->morphMany('App\View', 'viewable');
    }

    function deposits() {
        return $this->belongsToMany('App\Models\Deposit')->withPivot('value');
    }

    function prices_lists() {
        return $this->belongsToMany('App\Models\PricesList');
    }

    function provider_price_list() {
        return $this->belongsTo('App\Models\ProviderPriceList');
    }

    function recipe() {
        return $this->hasOne('App\Models\Recipe');
    }

    function article_discounts() {
        return $this->hasMany('App\Models\ArticleDiscount')->orderBy('id', 'ASC');;
    }

    function article_discounts_blanco() {
        return $this->hasMany('App\Models\ArticleDiscountBlanco');
    }

    function article_surchages() {
        return $this->hasMany('App\Models\ArticleSurchage')->orderBy('id', 'ASC');;
    }

    function article_surchages_blanco() {
        return $this->hasMany('App\Models\ArticleSurchageBlanco');
    }

    // Impuestos sobre ventas (Capa 2 — Prompt 260) que aplican a este artículo cuando el
    // sale_tax tiene apply_to_all = false.
    function sale_taxes() {
        return $this->belongsToMany('App\Models\SaleTax', 'article_sale_tax');
    }

    function combos() {
        return $this->belongsToMany('App\Models\Article')->withTrashed();
    }

    function brand() {
        return $this->belongsTo('App\Models\Brand');
    }

    function iva() {
        return $this->belongsTo('App\Models\Iva');
    }

    function descriptions() {
        return $this->hasMany('App\Models\Description');
    }

    function tags() {
        return $this->belongsToMany('App\Models\Tag');
    }

    function sizes() {
        return $this->belongsToMany('App\Models\Size');
    }

    function colors() {
        return $this->belongsToMany('App\Models\Color');
        // return $this->belongsToMany('App\Models\Color')->withPivot('amount');
    }

    function condition() {
        return $this->belongsTo('App\Models\Condition');
    }

    function user() {
        return $this->belongsTo('App\Models\User');
    }

    function category() {
        return $this->belongsTo('App\Models\Category');
    }

    function sub_category() {
        return $this->belongsTo('App\Models\SubCategory');
    }

    function marker() {
        return $this->hasOne('App\Models\Marker');
    }

    function images() {
        return $this->morphMany('App\Models\Image', 'imageable');
    }

    function sub_user() {
        return $this->belongsTo('App\Models\User', 'sub_user_id');
    }
    
    function updated_by() {
        return $this->belongsTo('App\Models\User', 'updated_by', 'id');
    }

    function sales() {
        return $this->belongsToMany('App\Models\Sale')->latest()->withPivot('amount', 'returned_amount');
    }

    function budgets() {
        return $this->belongsToMany('App\Models\Budget')->latest();
    }

    function order_productions() {
        return $this->belongsToMany('App\Models\OrderProduction')->latest();
    }

    function provider_orders() {
        return $this->belongsToMany('App\Models\ProviderOrder')->latest();
    }

    function recipes() {
        return $this->belongsToMany('App\Models\Recipe')->latest();
    }
    
    function providers(){
        return $this->belongsToMany('App\Models\Provider')->withPivot('amount', 'cost', 'price', 'provider_code')
                                                    ->withTimestamps();
    }
    
    function provider(){
        return $this->belongsTo('App\Models\Provider');
    }

    function questions() {
        return $this->hasMany('App\Models\Question');
    }

    public function sales_with_deliveries_in_acopio()
    {
        return $this->belongsToMany(Sale::class)
            ->withPivot('delivered_amount')
            ->wherePivotNotNull('delivered_amount')
            ->where('en_acopio', true);
    }
}
