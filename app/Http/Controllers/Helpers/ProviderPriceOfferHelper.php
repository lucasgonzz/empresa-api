<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Article;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lógica de lectura del historial de precios por proveedor (misión historial-precios-proveedor).
 *
 * Alimenta dos pantallas:
 * - "Historial de precios" del modal Proveedores del artículo
 *   (GET provider-price-offer/article/{article_id}): todo lo que cada proveedor ofreció, qué pasó con
 *   cada oferta (si actualizó el costo, si cambió el titular) y a qué cambio de precio quedó vinculada.
 * - "Cambios de precios" (GET price-change/{article_id}): por cada cambio, la oferta que lo originó.
 *
 * Regla de consultas: una por relación (proveedores, compras, cambios de precio), nunca una por fila.
 * Es de sólo lectura: la única escritura de la tabla es App\Services\Compras\OfertasDeProveedorService.
 */
class ProviderPriceOfferHelper
{
    /** Máximo de filas que devuelve el historial de un artículo. */
    const MAXIMO_DE_FILAS = 300;

    /**
     * Indica si el usuario ve los costos (dueño, acceso de administrador o permiso `article.cost`).
     * Misma regla que usa PriceChangeController::index() desde siempre.
     *
     * @param \App\Models\User|null $usuario Usuario autenticado
     * @return bool
     */
    public static function can_see_cost($usuario)
    {
        return (bool) ($usuario && (is_null($usuario->owner_id) || $usuario->admin_access
            || $usuario->permissions()->where('slug', 'article.cost')->exists()));
    }

    /**
     * Historial de ofertas de proveedores de un artículo del dueño, de la más nueva a la más vieja.
     *
     * @param int $owner_id Dueño (users.id sin owner)
     * @param int $article_id Artículo
     * @param bool $can_see_cost Si es false se omiten `cost` y `previous_article_cost`
     * @return array Filas con las claves del contrato api-spa (máx. 300)
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException si el artículo no es del dueño (404)
     */
    public static function history_for_article($owner_id, $article_id, $can_see_cost)
    {
        // Artículo del dueño o 404 (nunca se filtra la existencia de artículos ajenos).
        $article = Article::where('id', $article_id)->where('user_id', $owner_id)->firstOrFail();

        $rows = DB::table('provider_price_offers')
            ->where('user_id', $owner_id)
            ->where('article_id', $article->id)
            ->orderBy('fecha', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit(self::MAXIMO_DE_FILAS)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        // Nombres de proveedores (el de la oferta y el titular anterior): UNA consulta.
        $provider_names = self::provider_names($owner_id, $rows->pluck('provider_id')->merge($rows->pluck('previous_article_provider_id')));

        // Números de las compras citadas: UNA consulta.
        $purchase_ids = $rows->where('origen', 'compra')->pluck('referencia_id')->filter()->unique()->values()->all();
        $purchase_numbers = empty($purchase_ids)
            ? []
            : DB::table('provider_orders')->whereIn('id', $purchase_ids)->where('user_id', $owner_id)->pluck('num', 'id')->all();

        // Fecha de las importaciones citadas ("Importación del dd/mm/aaaa"): UNA consulta.
        $import_ids = $rows->where('origen', 'importacion')->pluck('referencia_id')->filter()->unique()->values()->all();
        $import_dates = empty($import_ids)
            ? []
            : DB::table('import_histories')->whereIn('id', $import_ids)->where('user_id', $owner_id)->pluck('created_at', 'id')->all();

        // Fecha de los cambios de precio vinculados: UNA consulta.
        $price_change_ids = $rows->pluck('price_change_id')->filter()->unique()->values()->all();
        $price_change_dates = empty($price_change_ids)
            ? []
            : DB::table('price_changes')->whereIn('id', $price_change_ids)->where('article_id', $article->id)->pluck('created_at', 'id')->all();

        $result = [];

        foreach ($rows as $row) {
            $item = [
                'id'                             => (int) $row->id,
                'provider_id'                    => (int) $row->provider_id,
                'provider_name'                  => isset($provider_names[$row->provider_id]) ? $provider_names[$row->provider_id] : null,
                'provider_code'                  => $row->provider_code,
                'moneda_id'                      => (int) $row->moneda_id,
                'origen'                         => $row->origen,
                'fecha'                          => $row->fecha,
                'referencia_id'                  => is_null($row->referencia_id) ? null : (int) $row->referencia_id,
                'reference_label'                => self::reference_label($row, $purchase_numbers, $import_dates),
                'last_seen_at'                   => $row->last_seen_at,
                'last_seen_reference_id'         => is_null($row->last_seen_reference_id) ? null : (int) $row->last_seen_reference_id,
                // null = fila anterior a la misión ("sin dato"); no se convierte en 0.
                'updated_article_cost'           => is_null($row->updated_article_cost) ? null : (int) $row->updated_article_cost,
                'price_change_id'                => is_null($row->price_change_id) ? null : (int) $row->price_change_id,
                'price_change_created_at'        => (!is_null($row->price_change_id) && isset($price_change_dates[$row->price_change_id]))
                                                        ? $price_change_dates[$row->price_change_id]
                                                        : null,
                'changed_article_provider'       => is_null($row->changed_article_provider) ? null : (int) $row->changed_article_provider,
                'previous_article_provider_id'   => is_null($row->previous_article_provider_id) ? null : (int) $row->previous_article_provider_id,
                'previous_article_provider_name' => (!is_null($row->previous_article_provider_id) && isset($provider_names[$row->previous_article_provider_id]))
                                                        ? $provider_names[$row->previous_article_provider_id]
                                                        : null,
            ];

            if ($can_see_cost) {
                $item['cost'] = (float) $row->cost;
                $item['previous_article_cost'] = is_null($row->previous_article_cost) ? null : (float) $row->previous_article_cost;
            }

            $result[] = $item;
        }

        return $result;
    }

    /**
     * Ofertas de proveedor vinculadas a cada cambio de precio (UNA consulta para todos los cambios).
     *
     * @param int $owner_id Dueño
     * @param array $price_change_ids Ids de price_changes de la respuesta
     * @param bool $can_see_cost Si es false se omite `cost`
     * @return array [price_change_id => [oferta, ...]] (los cambios sin oferta no aparecen)
     */
    public static function offers_by_price_change($owner_id, array $price_change_ids, $can_see_cost)
    {
        if (empty($price_change_ids)) {
            return [];
        }

        $rows = DB::table('provider_price_offers')
            ->where('user_id', $owner_id)
            ->whereIn('price_change_id', $price_change_ids)
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $provider_names = self::provider_names($owner_id, $rows->pluck('provider_id')->merge($rows->pluck('previous_article_provider_id')));

        $grouped = [];

        foreach ($rows as $row) {
            $item = [
                'id'                             => (int) $row->id,
                'provider_id'                    => (int) $row->provider_id,
                'provider_name'                  => isset($provider_names[$row->provider_id]) ? $provider_names[$row->provider_id] : null,
                'provider_code'                  => $row->provider_code,
                'moneda_id'                      => (int) $row->moneda_id,
                'fecha'                          => $row->fecha,
                'origen'                         => $row->origen,
                'referencia_id'                  => is_null($row->referencia_id) ? null : (int) $row->referencia_id,
                'changed_article_provider'       => is_null($row->changed_article_provider) ? null : (int) $row->changed_article_provider,
                'previous_article_provider_id'   => is_null($row->previous_article_provider_id) ? null : (int) $row->previous_article_provider_id,
                'previous_article_provider_name' => (!is_null($row->previous_article_provider_id) && isset($provider_names[$row->previous_article_provider_id]))
                                                        ? $provider_names[$row->previous_article_provider_id]
                                                        : null,
            ];

            if ($can_see_cost) {
                $item['cost'] = (float) $row->cost;
            }

            $grouped[(int) $row->price_change_id][] = $item;
        }

        return $grouped;
    }

    /**
     * Nombres de proveedores del dueño, en UNA consulta.
     *
     * @param int $owner_id Dueño
     * @param \Illuminate\Support\Collection $provider_ids Ids (pueden repetirse o venir null)
     * @return array [provider_id => name]
     */
    protected static function provider_names($owner_id, $provider_ids)
    {
        $ids = $provider_ids->filter()->unique()->values()->all();

        if (empty($ids)) {
            return [];
        }

        return DB::table('providers')->where('user_id', $owner_id)->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * Texto corto que identifica de dónde salió la oferta ("Compra N° 12" / "Importación del 07/03/2026").
     * Plan §2.9: la importación se rotula con la fecha de la importación (import_histories.created_at); si
     * ya no existe, con la fecha de la oferta, que es la del mismo día.
     *
     * @param \stdClass $row Fila de provider_price_offers
     * @param array $purchase_numbers [provider_order_id => num]
     * @param array $import_dates [import_history_id => created_at] (una consulta para todas las filas)
     * @return string|null
     */
    protected static function reference_label($row, array $purchase_numbers, array $import_dates = [])
    {
        if (is_null($row->referencia_id)) {
            return null;
        }

        if ($row->origen === 'compra') {
            return isset($purchase_numbers[$row->referencia_id])
                ? 'Compra N° ' . $purchase_numbers[$row->referencia_id]
                : 'Compra #' . $row->referencia_id;
        }

        if ($row->origen === 'importacion') {
            $fecha = isset($import_dates[$row->referencia_id]) ? $import_dates[$row->referencia_id] : $row->fecha;

            return empty($fecha)
                ? 'Importación #' . $row->referencia_id
                : 'Importación del ' . Carbon::parse($fecha)->format('d/m/Y');
        }

        return null;
    }
}
