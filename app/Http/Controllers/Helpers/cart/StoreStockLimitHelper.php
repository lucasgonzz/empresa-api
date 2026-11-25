<?php

namespace App\Http\Controllers\Helpers\cart;

use App\Http\Controllers\Helpers\Order\ReservaStockWebHelper;
use App\Models\Address;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Criterio ÚNICO, en empresa-api, de la cantidad máxima que la tienda online deja tener en el carrito para un
 * renglón (artículo + variante). Es el espejo de lo que hace tienda-api (`ConsultaStockHelper::clasificar` y
 * `ReservaStockWebHelper::expresion_articulo`): si los dos lados divergen, el ERP recorta carritos que la tienda
 * dejaría comprar (ticket 169, Únicas) o deja pasar los que la tienda frenaría. Los tests de las dos puntas
 * llevan la misma matriz de casos (`TopeDeLaTiendaTest` / `TopeDeLineaTest`).
 *
 * Absorbe el guard "la tienda no pone tope" que trajo la misión unicas-carritos-sin-control-stock en
 * `CartArticleAmountInsificienteHelper`: ya no hay otro lugar donde se decida si hay tope.
 *
 * Reglas, en orden (plan de carritos-ajuste-stock, §2.1):
 *  1. Sin fila de online_configurations del dueño: sin tope (no hay tienda).
 *  2. `ignorar_stock` prendido: sin tope.
 *  3. Renglón sin variante de un artículo que tiene variantes: sin tope (renglón inválido para la tienda).
 *  4. Disponible = con reserva web activa, físico del depósito principal menos lo reservado por otros pedidos;
 *     sin reserva, el stock del artículo o de la variante.
 *  5. Disponible null: tope 0 sólo si el renglón no tiene variante y `stock_null_equal_0` está prendido; si no, sin tope.
 *  6. Consultas activas (`permitir_pedidos_sin_stock`) y disponible exactamente 0: sin tope (se pide como "Consultar").
 *  7. Si no, tope = max(0, disponible).
 */
class StoreStockLimitHelper
{
    /**
     * Cantidad máxima que el comprador puede tener en el carrito para el renglón.
     *
     * @param \App\Models\OnlineConfiguration|null $configuration Configuración online del dueño (null si no tiene fila).
     * @param int $article_id Artículo del renglón.
     * @param int|null $variant_id Variante del renglón (null si no lleva).
     * @return float|null null = la tienda no pone tope; un número = el tope (puede ser 0 o fraccionario).
     */
    public static function limit_for_line($configuration, $article_id, $variant_id)
    {
        // Regla 1: sin configuración online no hay tienda que ponga tope.
        if (is_null($configuration)) {
            return null;
        }

        // Regla 2: con ignorar_stock la tienda no mira el stock.
        if (self::flag($configuration, 'ignorar_stock')) {
            return null;
        }

        // Regla 3: artículo con variantes consultado sin variante.
        if (is_null($variant_id) && DB::table('article_variants')->where('article_id', $article_id)->exists()) {
            return null;
        }

        // Regla 4: lo que hay para comprar, leído de la base fresca (el modelo en memoria puede estar viejo).
        $available = self::available($configuration, $article_id, $variant_id);

        // Regla 5: stock sin cargar. Sólo un artículo sin variante con "stock vacío cuenta como cero" tiene tope 0.
        if (is_null($available)) {
            if (is_null($variant_id) && self::flag($configuration, 'stock_null_equal_0')) {
                return 0.0;
            }
            return null;
        }

        // Regla 6: modo Consultar con stock exactamente 0, el renglón se va a pedir como consulta.
        if (self::consultas_active($configuration) && $available == 0) {
            return null;
        }

        // Regla 7: tope normal (el stock negativo no da tope negativo).
        return max(0.0, $available);
    }

    /**
     * Indica si la reserva de stock de los pedidos web está activa para el comercio. Mismo criterio que
     * `ReservaStockWebHelper::activa` de tienda-api.
     *
     * @param \App\Models\OnlineConfiguration|null $configuration
     * @return bool
     */
    public static function reservation_active($configuration)
    {
        return !is_null($configuration)
            && self::flag($configuration, 'reservar_stock_pedidos_web')
            && !self::flag($configuration, 'ignorar_stock')
            && (int) self::value($configuration, 'reserva_stock_erp_protocolo') === 1
            && (int) self::value($configuration, 'reserva_stock_tienda_protocolo') === 1
            && ReservaStockWebHelper::hay_esquema();
    }

    /**
     * Indica si la tienda trabaja en modo "Consultar" (pedidos de artículos sin stock). Mismo criterio que
     * `ConsultaStockHelper::activa` de tienda-api, incluida la guarda de esquema.
     *
     * @param \App\Models\OnlineConfiguration|null $configuration
     * @return bool
     */
    public static function consultas_active($configuration)
    {
        return !is_null($configuration)
            && self::flag($configuration, 'permitir_pedidos_sin_stock')
            && !self::flag($configuration, 'ignorar_stock')
            && Schema::hasColumn('online_configurations', 'permitir_pedidos_sin_stock')
            && Schema::hasColumn('article_cart', 'stock_a_consultar')
            && Schema::hasColumn('article_order', 'stock_a_consultar')
            && Schema::hasColumn('carts', 'preferencia_online_emitida');
    }

    /**
     * Unidades que hoy se pueden comprar de un renglón.
     *
     * Con reserva activa es el físico del depósito principal (o el stock del recurso si no reparte por
     * depósitos) menos lo reservado por otros pedidos, con piso en 0. Sin reserva es el stock crudo.
     * Devuelve null si el stock no está cargado.
     *
     * @param \App\Models\OnlineConfiguration $configuration Configuración online del dueño.
     * @param int $article_id Artículo del renglón.
     * @param int|null $variant_id Variante del renglón (null si no lleva).
     * @return float|null
     */
    public static function available($configuration, $article_id, $variant_id)
    {
        // Tabla y clave del recurso que lleva el stock: la variante si el renglón la tiene, si no el artículo.
        $is_variant = !is_null($variant_id);
        $resource_id = $is_variant ? $variant_id : $article_id;
        $stock = $is_variant
            ? DB::table('article_variants')->where('id', $variant_id)->value('stock')
            : DB::table('articles')->where('id', $article_id)->value('stock');

        if (!self::reservation_active($configuration)) {
            return is_null($stock) ? null : (float) $stock;
        }

        // Tabla puente por depósito y su columna de recurso.
        $pivot = $is_variant ? 'address_article_variant' : 'address_article';
        $resource_column = $is_variant ? 'article_variant_id' : 'article_id';

        // Depósito principal del comercio (0 si no tiene, igual que tienda-api).
        $principal = Address::principal_de($configuration->user_id);
        $deposit_id = is_null($principal) ? 0 : (int) $principal->id;

        // ¿El recurso reparte su stock por depósitos? Si no, vale el stock del recurso.
        $splits_by_deposit = DB::table($pivot)->where($resource_column, $resource_id)->exists();

        $physical = $splits_by_deposit
            ? (float) DB::table($pivot)->where($resource_column, $resource_id)->where('address_id', $deposit_id)->sum('amount')
            : (is_null($stock) ? null : (float) $stock);

        if (is_null($physical)) {
            return null;
        }

        // Reservado por pedidos pendientes: sólo el del depósito principal si el recurso reparte por depósitos.
        $reserved_query = DB::table('order_stock_reservations');
        if ($is_variant) {
            $reserved_query->where('article_variant_id', $variant_id);
        } else {
            $reserved_query->where('article_id', $article_id)->whereNull('article_variant_id');
        }
        if ($splits_by_deposit) {
            $reserved_query->whereRaw('COALESCE(address_id, 0) = ?', [$deposit_id]);
        }

        return max(0.0, $physical - (float) $reserved_query->sum('amount'));
    }

    /**
     * Lee una opción booleana de la configuración, tolerando que la columna no exista en bases viejas.
     *
     * @param \App\Models\OnlineConfiguration $configuration
     * @param string $column
     * @return bool
     */
    private static function flag($configuration, $column)
    {
        return (int) self::value($configuration, $column) === 1;
    }

    /**
     * Lee un atributo de la configuración sin fallar si la columna no existe (devuelve null).
     *
     * @param \App\Models\OnlineConfiguration $configuration
     * @param string $column
     * @return mixed
     */
    private static function value($configuration, $column)
    {
        return $configuration->getAttribute($column);
    }
}
