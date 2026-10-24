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
 *   - SUMA a una sucursal (`to` con monto > 0: ingreso manual, compra, devolución, nota de crédito a
 *     cliente, producción del terminado) o es un TRASLADO entre depósitos (ver más abajo),
 *   - esa sucursal es una sucursal DE VERDAD del comercio
 *     (`SucursalVigenteHelper::existe()`, estricto: un domicilio de comprador de un pedido de la tienda
 *     no dispara nada y conserva su comportamiento histórico),
 *   - el concepto NO es de los que definen el reparto inicial (`CONCEPTOS_QUE_NO_MATERIALIZAN`:
 *     "Creacion de deposito", "Actualizacion de deposito" e "Importacion de excel" —el modal de crear
 *     depósitos, la edición por sucursal y el Excel por sucursal mandan porciones que ya suman el
 *     total, y materializar ahí duplicaría el stock—, más "Reseteo de Stock" y "Eliminacion de
 *     sucursal", que operan sobre filas que ya existen),
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
 *  🔴 LOS TRASLADOS SÍ MATERIALIZAN ("Mov entre depositos" y "Mov manual entre depositos";
 *  corrección del coordinador, 23/10/2026: el brief los había excluido por error). Sin materializar,
 *  el traslado de un artículo sin filas le abría el destino con la cantidad trasladada y el global
 *  pasaba a ser solo eso: se perdía el resto. Con la materialización primero:
 *   - global 100 sin filas, traslado principal → B de 5: principal 95, B 5, global 100;
 *   - global 100 sin filas, traslado B → C de 5: principal 100, B −5, C 5, global 100. El origen queda
 *     en negativo y la principal con todo, que es lo correcto: el sistema no sabe dónde estaba
 *     físicamente el stock global, y el negativo le muestra al usuario de dónde salió.
 *
 *  🔴 LO QUE RESTA DESDE UNA SUCURSAL NO MATERIALIZA (`from` con monto < 0: ventas, consumo de
 *  insumos, nota de crédito a proveedor; corrección del verificador del contrato, 23/10/2026). Una
 *  venta desde la sucursal B sobre un artículo con global 100 sin filas dejaba principal 100, B −1 y
 *  global 99: con `check_article_stock_en_vender` (empresa-spa, check_stock.js) las ventas siguientes
 *  desde B se frenaban con "No hay stock en B", cuando en develop esa venta descuenta del global y
 *  anda. Así que siguen como siempre: CheckFromAddress no actúa (el artículo no reparte) y se
 *  descuenta del global. El artículo empieza a repartir con el primer movimiento que SUMA.
 *
 *  🔴 CONCURRENCIA. La venta del mostrador y el pedido de Tienda Nube del mismo artículo pueden llegar
 *  a la vez: los dos leían "no tiene filas" y los dos materializaban (el global entraba dos veces a la
 *  principal). Por eso la decisión y la materialización van en UNA transacción con `lockForUpdate`
 *  sobre la fila de `article_variants` si es una variante y después la de `articles` (el orden del
 *  motor), y las filas y el stock
 *  se vuelven a leer DESPUÉS de tomar el candado: el segundo espera al primero, relee, ve la fila ya
 *  abierta y no materializa.
 *
 *  El movimiento de materialización no toca `articles.stock_updated_at` (`sin_stock_updated_at`): el
 *  stock no cambia, y esa fecha la usan los reportes de "stock actualizado".
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
     * Conceptos que NO materializan: los que definen el reparto inicial (sus porciones ya suman el
     * total) y los que operan sobre filas existentes. Se comparan sin distinguir mayúsculas (la tabla
     * guarda 'Reseteo de Stock' y el código lo pide como 'Reseteo de stock'). Los traslados NO están
     * acá a propósito: ver el docblock de la clase.
     */
    const CONCEPTOS_QUE_NO_MATERIALIZAN = [
        'Creacion de deposito',
        'Actualizacion de deposito',
        'Importacion de excel',
        'Reseteo de Stock',
        'Eliminacion de sucursal',
    ];

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

        $concepto = is_null($concepto_id) ? null : ConceptoStockMovement::find($concepto_id);

        $es_traslado = !is_null($concepto) && Self::es_traslado($concepto->name);

        /*
         * Solo lo que SUMA a una sucursal (o un traslado) materializa: ver "lo que resta desde una
         * sucursal no materializa" en el docblock de la clase.
         */
        if ($es_traslado) {
            $address_id = Self::sucursal_del_movimiento($data);
        } else {
            $address_id = (isset($data['to_address_id']) && !SucursalVigenteHelper::es_vacio($data['to_address_id'])
                            && isset($data['amount']) && (float) $data['amount'] > 0)
                ? (int) $data['to_address_id']
                : null;
        }

        if (is_null($address_id)) {
            return false;
        }

        // Solo una sucursal de verdad del comercio dispara la materialización (ver el docblock).
        if (!SucursalVigenteHelper::existe($address_id, $owner_id)) {
            return false;
        }

        /*
         * Sin concepto resuelto (la base del cliente no tiene ese nombre en `concepto_stock_movements`)
         * no se sabe si el movimiento reparte: no se materializa, y el motor sigue con su regla de
         * siempre (CheckToAddress no abre el depósito y el movimiento va al global). Materializar ahí
         * podría duplicar el reparto de un "Creacion de deposito" o una importación sin concepto.
         */
        if (is_null($concepto) || Self::no_materializa($concepto->name)) {
            return false;
        }

        $variant_id = !empty($data['article_variant_id']) ? (int) $data['article_variant_id'] : null;

        // Barato antes del candado: si ya reparte (lo normal), no se bloquea nada.
        if (is_null(Self::stock_a_materializar($article, $variant_id))) {
            return false;
        }

        $principal = Address::principal_de((int) $owner_id);

        $destino_id = is_null($principal) ? (int) $address_id : (int) $principal->id;

        return DB::transaction(function () use ($article, $variant_id, $destino_id, $owner_id, $employee_id, $set_updated_at) {

            /*
             * El candado, y DESPUÉS la relectura (ver "concurrencia" en el docblock de la clase). Primero
             * la variante y después el artículo: el mismo orden en que escribe el motor (CheckVariants
             * antes que `articles`), así una venta de esa misma variante en paralelo no se cruza en
             * deadlock con esta transacción.
             */
            if (!is_null($variant_id)) {
                DB::table('article_variants')->where('id', $variant_id)->lockForUpdate()->value('id');
            }

            DB::table('articles')->where('id', (int) $article->id)->lockForUpdate()->value('id');

            $stock = Self::stock_a_materializar($article, $variant_id);

            if (is_null($stock)) {
                return false;
            }

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
                'sin_stock_updated_at'          => true,
            ], $set_updated_at, $owner, $employee_id);

            return true;
        });
    }

    /**
     * ¿Es un traslado entre depósitos? Los traslados materializan aunque resten del origen.
     *
     * @param  string  $nombre
     * @return bool
     */
    static function es_traslado($nombre) {

        $nombre = mb_strtolower((string) $nombre);

        return $nombre === mb_strtolower('Mov entre depositos') || $nombre === mb_strtolower('Mov manual entre depositos');
    }

    /**
     * ¿El concepto está entre los que no materializan? (sin distinguir mayúsculas)
     *
     * @param  string  $nombre
     * @return bool
     */
    static function no_materializa($nombre) {

        foreach (Self::CONCEPTOS_QUE_NO_MATERIALIZAN as $concepto) {
            if (mb_strtolower($concepto) === mb_strtolower((string) $nombre)) {
                return true;
            }
        }

        return false;
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
