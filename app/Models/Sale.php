<?php

namespace App\Models;

use App\Http\Controllers\Helpers\UserHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Venta del sistema (remito / facturación asociada).
 *
 * @property int|null $dias_alerta_venta_no_cobrada_personalizado Días hasta mostrar alerta de cobro pendiente; null usa la jerarquía global de usuarios.
 */
class Sale extends Model
{
    use SoftDeletes;

    /**
     * Expresion SQL de la fecha por la que se fecha una venta cuando el comercio reporta por fecha
     * de pedido.
     *
     * 🔴 El COALESCE no es decorativo: una venta SIN `fecha_entrega` cargada tiene que seguir
     * cayendo en algun periodo (el de su carga) y no desaparecer del listado. Truvari tiene 1 asi
     * en agosto de 2026 sobre 299 ventas; sacar el COALESCE la borraria de la pantalla.
     *
     * Va calificada con `sales.` porque estos reportes viajan con joins y subqueries encima
     * (`whereHas('articles', ...)`, por ejemplo) y `created_at` a secas seria ambigua.
     */
    const EXPRESION_FECHA_DE_PEDIDO = 'COALESCE(sales.fecha_entrega, sales.created_at)';

    protected $guarded = [];

    protected $dates = ['fecha_entrega'];

    /**
     * Casts de atributos serializados para exponer tipos consistentes en API.
     */
    protected $casts = [
        // Bitácora detallada de auditoría enviada desde el módulo vender.
        'log' => 'array',
    ];

    public function current_acount_payment_methods(){
        return $this->belongsToMany(CurrentAcountPaymentMethod::class)->withPivot('amount', 'discount_percentage', 'discount_amount', 'caja_id', 'amount_cotizado', 'cotizacion', 'moneda_id', 'cuota_id');
    }

    function article_purchases() {
        return $this->hasMany(ArticlePurchase::class);
    }

    function moneda() {
        return $this->belongsTo(Moneda::class);
    }

    function stock_movements() {
        return $this->hasMany(StockMovement::class);
    }

    public function actualizandose_por() {
        return $this->belongsTo(User::class, 'actualizandose_por_id');
    }

    public function afip_tipo_comprobante() {
        return $this->belongsTo(AfipTipoComprobante::class);
    }

    public function impressions() {
        return $this->hasMany('App\Models\Impression');
    }

    function scopeWithAll($query) {
        $query->with('client.iva_condition', 'client.price_type', 'client.credit_accounts', 'client.location.provincia', 'buyer.comercio_city_client', 'articles.article_variants', 'articles.price_types', 'articles.addresses', 'impressions', 'discounts', 'surchages', 'afip_tickets.afip_errors', 'afip_tickets.afip_observations', 'nota_credito_afip_tickets', 'afip_tickets.nota_credito_afip.afip_errors', 'combos.articles', 'order.cupon', 'services', 'employee', 'budget.articles', 'budget.client', 'budget.discounts', 'budget.surchages', 'current_acount_payment_method', 'order_production.client', 'order_production.articles', 'afip_errors', 'afip_observations', 'current_acount', 'current_acount_payment_methods', 'price_type', 'sale_modifications', 'sale_delivery_info', 'sale_sender_info', 'seller_commissions', 'promocion_vinotecas.articles', 'afip_information.iva_condition', 'meli_order')
        ->withCount('sale_modifications');
    }

    /**
     * Imágenes de artículos de la venta (opt-in vía SaleArticlesEagerLoadHelper).
     */
    public function scopeWithSaleArticlesImages($query)
    {
        return $query->with(['articles.images']);
    }

    function meli_order() {
        return $this->belongsTo(MeliOrder::class);
    }

    function sale_modifications() {
        return $this->hasMany(SaleModification::class);
    }

    /**
     * Datos de envío personalizados para la etiqueta; opcional (1:1).
     */
    public function sale_delivery_info() {
        return $this->hasOne(SaleDeliveryInfo::class);
    }

    /**
     * Remitente preferido para la etiqueta (cabecera del negocio en el PDF).
     */
    public function sale_sender_info() {
        return $this->belongsTo(SaleSenderInfo::class);
    }

    public function price_type() {
        return $this->belongsTo(PriceType::class);
    }

    public function address() {
        return $this->belongsTo('App\Models\Address');
    }

    function afip_errors() {
        return $this->hasMany(AfipError::class);
    }

    function afip_observations() {
        return $this->hasMany(AfipObservation::class);
    }

    public function budget() {
        return $this->belongsTo('App\Models\Budget');
    }

    public function order_production() {
        return $this->belongsTo('App\Models\OrderProduction');
    }

    public function sale_type() {
        return $this->belongsTo('App\Models\SaleType');
    }

    public function afip_information() {
        return $this->belongsTo('App\Models\AfipInformation');
    }

    public function afip_tickets() {
        return $this->hasMany('App\Models\AfipTicket');
    }

    public function nota_credito_afip_tickets() {
        return $this->hasMany('App\Models\AfipTicket', 'sale_nota_credito_id');
    }

    public function user() {
        return $this->belongsTo('App\Models\User', 'user_id');
    }

    public function employee() {
        return $this->belongsTo('App\Models\User', 'employee_id');
    }

    public function current_acount() {
        return $this->hasOne('App\Models\CurrentAcount');
    }

    public function current_acounts() {
        return $this->hasMany('App\Models\CurrentAcount');
    }

    public function order() {
        return $this->belongsTo('App\Models\Order');
    }

    public function tienda_nube_order() {
        return $this->belongsTo(TiendaNubeOrder::class);
    }

    public function discounts() {
        return $this->belongsToMany('App\Models\Discount')->withTrashed()->withPivot('percentage');
    }

    public function surchages() {
        return $this->belongsToMany('App\Models\Surchage')->withTrashed()->withPivot('percentage');
    }

    public function articles() {
        return $this->belongsToMany('App\Models\Article')->withTrashed()->withPivot('amount', 'cost', 'price', 'returned_amount', 'delivered_amount', 'discount', 'with_dolar', 'checked_amount', 'variant_description', 'name', 'article_variant_id', 'price_type_personalizado_id', 'ganancia', 'fecha_agregado', 'iva_percentage', 'price_sin_iva')->withTrashed();
    }

    public function combos() {
        return $this->belongsToMany('App\Models\Combo')->withPivot('amount', 'price', 'cost')->withTrashed();
    }

    public function promocion_vinotecas() {
        return $this->belongsToMany(PromocionVinoteca::class)->withPivot('amount', 'price')->withTrashed();
    }

    public function services() {
        return $this->belongsToMany('App\Models\Service')->withPivot('discount', 'amount', 'price', 'returned_amount');
    }

    public function client() {
        return $this->belongsTo('App\Models\Client')->withTrashed();
    }

    public function buyer() {
        return $this->belongsTo('App\Models\Buyer');
    }

    public function current_acount_payment_method() {
        return $this->belongsTo('App\Models\CurrentAcountPaymentMethod');
    }

    public function seller_commissions() {
        return $this->hasMany('App\Models\SellerCommission');
    }

    public function seller() {
        return $this->belongsTo(Seller::class);
    }

    /**
     * Relación hacia la venta consolidada que agrupa esta venta original.
     * Nulo si la venta no fue incluida en ninguna consolidación de facturación.
     */
    public function consolidacion_facturacion() {
        return $this->belongsTo(Sale::class, 'consolidacion_facturacion_id');
    }

    /**
     * Ventas individuales (originales) que fueron agrupadas bajo esta venta consolidada.
     * Solo aplica cuando is_consolidacion_facturacion = 1.
     */
    public function ventas_consolidadas() {
        return $this->hasMany(Sale::class, 'consolidacion_facturacion_id');
    }

    /**
     * Scope: excluye las ventas contenedoras de facturación de los reportes de ventas reales.
     * Usar en todos los queries que calculen totales, rendimiento, caja o performance.
     */
    public function scopeSoloVentasReales($query) {
        return $query->where(function($q) {
            $q->whereNull('is_consolidacion_facturacion')
              ->orWhere('is_consolidacion_facturacion', 0);
        });
    }

    /**
     * Scope: incluye solo las ventas que son contenedoras de facturación AFIP.
     * Útil para listar consolidaciones emitidas.
     */
    public function scopeConsolidacionesFacturacion($query) {
        return $query->where('is_consolidacion_facturacion', 1);
    }

    /**
     * Indica si el comercio fecha sus ventas por FECHA DE PEDIDO en vez de por fecha de carga.
     *
     * La preferencia (`users.fechar_ventas_por_fecha_de_entrega`) es del comercio, no de cada
     * vendedor: siempre se resuelve al usuario dueño, igual que `UserHelper::uses_listas_de_precio()`.
     *
     * Devuelve `false` —el camino de siempre— cuando no hay usuario resoluble o cuando la columna
     * todavia no existe en esa base (Eloquent devuelve null para un atributo que no vino del SELECT).
     *
     * @param  \App\Models\User|int|null $user Usuario, id de usuario, o null para el de la sesion.
     * @return bool
     */
    public static function fechaDeReportePorPedido($user = null)
    {
        if (is_null($user)) {
            $user = UserHelper::user(true);
        } else if (is_numeric($user)) {
            $user = User::find($user);
        }

        if (is_null($user)) {
            return false;
        }

        if ($user->owner_id) {
            $user = User::find($user->owner_id);

            if (is_null($user)) {
                return false;
            }
        }

        return (bool) $user->fechar_ventas_por_fecha_de_entrega;
    }

    /**
     * Scope: acota un reporte de ventas a un rango de fechas, por el criterio del comercio.
     *
     * 🔴 UN SOLO LUGAR DECIDE EL CRITERIO Y TODOS LOS REPORTES DE VENTAS LO CONSUMEN. Si el listado,
     * los dos Excel, el grafico y Rendimiento no se mueven juntos, el comercio ve el mismo mes con
     * dos numeros distintos segun por donde entre. Con el scope eso deja de depender de la
     * disciplina de la proxima sesion: se mueven juntos por construccion, y el proximo reporte de
     * ventas que alguien escriba usando el scope ya viene bien.
     *
     * 🔴 CON LA PREFERENCIA APAGADA EL SQL QUE SALE ES IDENTICO AL DE SIEMPRE: misma forma, mismos
     * binds, misma columna. No es cosmetico: son ~40 comercios que no pidieron nada, y ademas el
     * indice `sales_user_id_created_at_idx` (migracion 2026_09_01_180000) esta hecho para esa forma.
     * El camino apagado no se toca; solo el prendido usa la expresion nueva.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @param  string|\DateTimeInterface|null $from_date  Inicio del rango; null o '' no filtra nada.
     * @param  string|\DateTimeInterface|null $until_date Fin del rango; null o '' = un solo dia.
     * @param  \App\Models\User|int|null      $user       Usuario del reporte; null usa el de la sesion.
     * @param  bool $comparar_solo_la_fecha  true (default) compara solo la parte fecha, que es lo
     *                                       que hacen el listado, los Excel y Rendimiento (whereDate);
     *                                       false compara el datetime completo, que es lo que hace
     *                                       el grafico de ventas. Se respeta el que ya usaba cada
     *                                       sitio para no cambiarle el SQL al camino apagado.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeEnRangoDeFechas($query, $from_date, $until_date = null, $user = null, $comparar_solo_la_fecha = true)
    {
        if (is_null($from_date) || $from_date === '') {
            return $query;
        }

        $hay_rango = !is_null($until_date) && $until_date !== '';

        if (!self::fechaDeReportePorPedido($user)) {

            /* Camino de siempre, tal cual estaba escrito en cada sitio antes de esta mision. */
            if ($hay_rango) {

                if ($comparar_solo_la_fecha) {
                    return $query->whereDate('created_at', '>=', $from_date)
                                 ->whereDate('created_at', '<=', $until_date);
                }

                return $query->where('created_at', '>=', $from_date)
                             ->where('created_at', '<=', $until_date);
            }

            if ($comparar_solo_la_fecha) {
                return $query->whereDate('created_at', $from_date);
            }

            return $query->where('created_at', $from_date);
        }

        $columna = self::EXPRESION_FECHA_DE_PEDIDO;

        if ($comparar_solo_la_fecha) {
            $columna = 'DATE('.$columna.')';
        }

        if ($hay_rango) {
            return $query->whereRaw($columna.' >= ?', [self::fecha_para_bind($from_date, $comparar_solo_la_fecha)])
                         ->whereRaw($columna.' <= ?', [self::fecha_para_bind($until_date, $comparar_solo_la_fecha)]);
        }

        return $query->whereRaw($columna.' = ?', [self::fecha_para_bind($from_date, $comparar_solo_la_fecha)]);
    }

    /**
     * Normaliza el valor que va al bind del whereRaw del scope.
     *
     * `whereDate()` sabe formatear un Carbon solo; un `whereRaw()` no, y un objeto en el bind
     * revienta en PDO. `PerformanceHelper` pasa Carbon (`mes_inicio` / `mes_fin`) y los controllers
     * pasan strings, asi que las dos formas tienen que entrar por aca.
     *
     * @param  string|\DateTimeInterface $fecha
     * @param  bool $comparar_solo_la_fecha
     * @return string
     */
    private static function fecha_para_bind($fecha, $comparar_solo_la_fecha)
    {
        if ($fecha instanceof \DateTimeInterface) {
            return $fecha->format($comparar_solo_la_fecha ? 'Y-m-d' : 'Y-m-d H:i:s');
        }

        return $fecha;
    }

}
