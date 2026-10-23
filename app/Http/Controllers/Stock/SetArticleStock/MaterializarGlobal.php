<?php

namespace App\Http\Controllers\Stock\SetArticleStock;

use App\Http\Controllers\Helpers\address\SucursalVigenteHelper;
use App\Models\Address;
use App\Models\ArticleVariant;
use App\Models\ConceptoStockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Materializar el stock global al abrir el primer depósito (misión sucursal-siempre, 23/10/2026, D4).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  EL PROBLEMA (2R, 23/10/2026)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Un artículo que lleva su stock en `articles.stock` (sin filas en `address_article`) y recibe un
 *  movimiento CON sucursal quedaba mal por cualquiera de los dos caminos:
 *
 *  - con un concepto que no reparte (venta, devolución, ingreso manual...), `CheckToAddress` no le abre
 *    el depósito (la guarda de la auditoría del 5/9) y el movimiento va al global: el artículo nunca
 *    empieza a repartir y el negocio queda en "estado mixto" para siempre (488 de 507 artículos en 2R);
 *  - si alguien le abría el depósito "a mano" con la cantidad del movimiento, se perdía el resto del
 *    global: el stock pasaba a ser la suma de esa única fila.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  LA REGLA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Antes de aplicar el movimiento, si:
 *   - trae sucursal (`from` o `to`) y esa sucursal es una sucursal DE VERDAD del comercio
 *     (`SucursalVigenteHelper::existe()`, estricto: un domicilio de comprador de un pedido de la tienda
 *     no dispara nada y conserva su comportamiento histórico),
 *   - el concepto NO es de los que reparten (`CheckToAddress::CONCEPTOS_QUE_REPARTEN`: ésos ya definen
 *     el reparto inicial —el modal de crear depósitos y la edición por sucursal mandan porciones que
 *     suman el total—, y materializar ahí duplicaría el stock),
 *   - y lo que mueve (el artículo, o la variante si el movimiento es de una variante) NO tiene filas
 *     vivas y tiene stock distinto de 0 (null cuenta como 0: no hay nada que materializar),
 *
 *  se crea primero un movimiento "Creacion de deposito" por el stock global ENTERO hacia la sucursal
 *  principal (`Address::principal_de()`), por el motor mismo (`StockMovementController::crear()`), y
 *  recién después se aplica el movimiento original. El global no cambia por la materialización: la
 *  fila nace con el mismo número que tenía `articles.stock`, y `setArticleStockFromAddresses()` lo
 *  vuelve a escribir igual. El libro queda con dos renglones legibles: "entró todo a la principal" y
 *  después el movimiento de verdad.
 *
 *  Ejemplo (el del plan): global 100 sin filas + "Ingreso manual" de 10 a la sucursal B →
 *  principal 100, B 10, global 110.
 *
 *  Sin recursión: el movimiento de materialización es "Creacion de deposito" (un concepto que
 *  reparte) y además viaja con `sin_materializar`, así que nunca vuelve a entrar acá.
 *
 *  Un artículo con variantes sin variante en el movimiento no se materializa: su stock es la suma de
 *  sus variantes y el movimiento del artículo entero no lo toca nadie (comportamiento de siempre).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class MaterializarGlobal {

    /** Observación del movimiento de materialización (para encontrarlo en el libro). */
    const OBSERVACION = 'Stock global pasado a la sucursal principal (primer movimiento por sucursal)';

    /** Concepto con el que se materializa. */
    const CONCEPTO = 'Creacion de deposito';

    /**
     * Materializa el stock global del artículo (o de la variante) si el movimiento lo pide.
     *
     * @param  array                    $data          Los datos del movimiento, ya con la guarda y la red (D3).
     * @param  \App\Models\Article      $article
     * @param  int|null                 $concepto_id
     * @param  int|null                 $owner_id
     * @param  int|null                 $employee_id
     * @param  bool                     $set_updated_at
     * @return bool  true si materializó (el llamador tiene que releer el artículo).
     */
    static function materializar_si_hace_falta($data, $article, $concepto_id, $owner_id, $employee_id, $set_updated_at = false) {

        if (!empty($data['sin_materializar']) || is_null($owner_id) || is_null($article)) {
            return false;
        }

        $address_id = Self::sucursal_del_movimiento($data);

        if (is_null($address_id)) {
            return false;
        }

        // Solo una sucursal de verdad del comercio dispara la materialización (ver el docblock).
        if (!SucursalVigenteHelper::existe($address_id, $owner_id)) {
            return false;
        }

        $concepto = is_null($concepto_id) ? null : ConceptoStockMovement::find($concepto_id);

        if (!is_null($concepto) && in_array($concepto->name, CheckToAddress::CONCEPTOS_QUE_REPARTEN)) {
            return false;
        }

        $variant_id = !empty($data['article_variant_id']) ? (int) $data['article_variant_id'] : null;

        $stock = Self::stock_a_materializar($article, $variant_id);

        if (is_null($stock)) {
            return false;
        }

        $principal = Address::principal_de((int) $owner_id);

        $destino_id = is_null($principal) ? (int) $address_id : (int) $principal->id;

        Log::info('MaterializarGlobal: el artículo '.$article->id.(is_null($variant_id) ? '' : ' (variante '.$variant_id.')').' no repartía y tenía '.$stock.' de stock global; antes del movimiento por sucursal se pasa entero a la sucursal '.$destino_id.'.');

        $owner = User::find((int) $owner_id);

        $ct = new \App\Http\Controllers\Stock\StockMovementController();

        $ct->crear([
            'model_id'                      => (int) $article->id,
            'amount'                        => $stock,
            'to_address_id'                 => $destino_id,
            'article_variant_id'            => $variant_id,
            'concepto_stock_movement_name'  => Self::CONCEPTO,
            'observations'                  => Self::OBSERVACION,
            'sin_unidades_individuales'     => true,
            'sin_materializar'              => true,
            'sin_sincronizar'               => true,
        ], $set_updated_at, $owner, $employee_id);

        return true;
    }

    /**
     * La sucursal que nombra el movimiento (`to` si viene, si no `from`), o null.
     *
     * @param  array  $data
     * @return int|null
     */
    static function sucursal_del_movimiento($data) {

        foreach (['to_address_id', 'from_address_id'] as $clave) {
            if (isset($data[$clave]) && !SucursalVigenteHelper::es_vacio($data[$clave])) {
                return (int) $data[$clave];
            }
        }

        return null;
    }

    /**
     * El stock global a materializar, o null si no hay nada que materializar: ya reparte, no tiene
     * stock (0 o null) o es un artículo con variantes y el movimiento no es de una variante.
     *
     * Se lee de la base (no del modelo en memoria): otro movimiento pudo haberlo cambiado.
     *
     * @param  \App\Models\Article  $article
     * @param  int|null             $variant_id
     * @return float|null
     */
    static function stock_a_materializar($article, $variant_id) {

        if (!is_null($variant_id)) {

            $variante = ArticleVariant::find($variant_id);

            if (is_null($variante) || $variante->addresses()->count() >= 1) {
                return null;
            }

            $stock = DB::table('article_variants')->where('id', $variant_id)->value('stock');

        } else {

            if ($article->article_variants()->count() >= 1 || $article->addresses()->count() >= 1) {
                return null;
            }

            $stock = DB::table('articles')->where('id', $article->id)->value('stock');
        }

        if (is_null($stock) || (float) $stock == 0.0) {
            return null;
        }

        return (float) $stock;
    }
}
