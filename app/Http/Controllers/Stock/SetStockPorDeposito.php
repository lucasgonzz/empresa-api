<?php

namespace App\Http\Controllers\Stock;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stock por depósito de cada movimiento de stock: cuánto había en CADA depósito del artículo
 * antes del movimiento y cuánto quedó después (misión stock-por-deposito-en-movimientos,
 * 29/9/2026).
 *
 * Hasta acá el movimiento guardaba solo el `stock_resultante` global. En un negocio con
 * sucursales eso no alcanza para auditar: una venta en el Centro y un traslado del Norte al
 * Centro dejan el mismo global, y después no hay forma de saber qué depósito tenía qué. Ahora
 * cada movimiento guarda la foto completa de todos los depósitos del artículo (no solo los que
 * tocó): así se puede reconstruir el estado de cualquier sucursal en cualquier momento.
 *
 * Se llama desde `StockMovementController::crear()`, que es por donde pasan todos los
 * movimientos: una foto justo antes de `SetArticleStock` y otra justo antes de
 * `SetStockResultante`, que es la que hace el `save()` del movimiento. Esta clase NO guarda nada
 * por su cuenta: solo asigna los atributos al modelo y viajan en ese `save()`.
 *
 * `StockEnLote` (importación de Excel en lote) no pasa por `crear()`: arma las mismas dos fotos
 * con el estado que simula en PHP y usa `armar()` y `a_json()` de acá, para que la fila quede
 * idéntica byte a byte a la que deja este camino.
 *
 * Formato de `stock_por_deposito` (JSON en una columna `text`, `$casts` 'array' en el modelo):
 *
 *   {"articulo": [{"address_id": 3, "deposito": "Centro", "anterior": 10, "resultante": 8}, …],
 *    "variante": [ … mismo formato, solo en movimientos de una variante que reparte … ]}
 *
 *  - "Todos los depósitos" = todos los `address_id` con fila en `address_article` antes o
 *    después del movimiento. Un depósito sin fila tiene 0: `anterior` 0 si el movimiento lo
 *    abrió, `resultante` 0 si la fila desapareció.
 *  - Las filas repetidas del mismo par (la tabla no tiene índice único) se SUMAN, y se leen con
 *    LEFT JOIN a `addresses` sin filtrar direcciones vivas: es exactamente lo que suma
 *    `ArticleHelper::setArticleStockFromAddresses()` para escribir `articles.stock`, así la suma
 *    de los `resultante` coincide con el `stock_resultante` del movimiento.
 *  - Artículo que no reparte por depósitos (ni antes ni después) → la columna queda NULL.
 *    "Repartir" es tener al menos un depósito VIVO (fila cuya dirección existe): un artículo con
 *    solo filas de sucursales borradas lleva el stock global y también queda NULL. Con al menos
 *    un depósito vivo, las filas huérfanas entran en la foto (ver armar()).
 *  - Qué depósito "tocó" el movimiento no se guarda aparte: sale de `from_address_id` /
 *    `to_address_id` y de `anterior != resultante`.
 *
 * 🔴 Sin candado sobre el artículo: dos movimientos simultáneos del mismo artículo pueden
 * intercalar sus fotos (la de "antes" de uno puede ver lo que el otro ya aplicó). Es el mismo
 * límite que tiene hoy `stock_resultante`; no se agrega candado porque el camino no está en
 * transacción en todos los llamadores.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class SetStockPorDeposito {

    /**
     * Foto del stock del artículo (y de su variante, si el movimiento es de una) leída de la
     * base en este instante. Se lee de la base y no de los modelos en memoria por el mismo
     * motivo que SetStockResultante: SetArticleStock escribe con SQL y el modelo queda atrasado.
     *
     * Una consulta a `articles`, una a `address_article` y, solo si hay variante, una a
     * `address_article_variant`; las dos últimas van por los índices de (article_id, address_id)
     * y (article_variant_id, address_id).
     *
     * La foto de "después" no necesita el stock (guardar() usa solo el de antes, y el de después
     * lo lee SetStockResultante): con `$leer_stock` en false se ahorra esa consulta y `stock`
     * queda null.
     *
     * @param  int       $article_id
     * @param  int|null  $article_variant_id
     * @param  bool      $leer_stock
     * @return array ['stock' => float|null, 'articulo' => [address_id => ['deposito' => string|null, 'amount' => float, 'vivo' => bool]], 'variante' => [...]]
     */
    static function foto($article_id, $article_variant_id = null, $leer_stock = true) {

        $stock = null;

        if ($leer_stock) {
            $stock = DB::table('articles')
                        ->where('id', $article_id)
                        ->value('stock');
        }

        $variante = [];

        if (!is_null($article_variant_id) && $article_variant_id != 0) {
            $variante = Self::depositos('address_article_variant', 'article_variant_id', $article_variant_id);
        }

        return [
            'stock'     => is_null($stock) ? null : (float) $stock,
            'articulo'  => Self::depositos('address_article', 'article_id', $article_id),
            'variante'  => $variante,
        ];
    }

    /**
     * Depósitos de un artículo (o de una variante), sumando las filas repetidas del mismo par.
     *
     * @param  string  $tabla    address_article | address_article_variant
     * @param  string  $columna  article_id | article_variant_id
     * @param  int     $id
     * `vivo` dice si la dirección existe: una fila de una sucursal borrada entra en la suma (y en
     * la foto), pero la relación `addresses` del artículo no la ve, así que sola no alcanza para
     * que el artículo "reparta por depósitos" (ver armar()).
     *
     * @return array address_id => ['deposito' => string|null, 'amount' => float, 'vivo' => bool]
     */
    static function depositos($tabla, $columna, $id) {

        $filas = DB::table($tabla)
                    ->leftJoin('addresses', 'addresses.id', '=', $tabla.'.address_id')
                    ->where($tabla.'.'.$columna, $id)
                    ->get([$tabla.'.address_id', $tabla.'.amount', 'addresses.street', 'addresses.id as address_existente']);

        $mapa = [];

        foreach ($filas as $fila) {

            $address_id = (int) $fila->address_id;

            if (!isset($mapa[$address_id])) {
                $mapa[$address_id] = [
                    'deposito'  => $fila->street,
                    'amount'    => 0.0,
                    'vivo'      => !is_null($fila->address_existente),
                ];
            }

            $mapa[$address_id]['amount'] += is_null($fila->amount) ? 0.0 : (float) $fila->amount;
        }

        return $mapa;
    }

    /**
     * Asigna `stock_anterior` y `stock_por_deposito` al movimiento, SIN save(): los guarda el
     * save() que ya hace SetStockResultante a continuación.
     *
     * @param  \App\Models\StockMovement  $stock_movement
     * @param  array                      $antes    foto() antes de aplicar el movimiento
     * @param  array                      $despues  foto() después de aplicarlo
     * @return void
     */
    static function guardar($stock_movement, $antes, $despues) {

        $stock_movement->stock_anterior = $antes['stock'];

        $foto = Self::armar($antes, $despues);

        /*
            El cast 'array' del modelo hace json_encode() al asignar y, si falla (un nombre de
            depósito con bytes que no son UTF-8), tira una excepción que se llevaría puesta la
            venta entera. Un dato de auditoría no puede frenar un movimiento de stock: en ese caso
            la foto queda en NULL y se deja constancia en el log.
        */
        if (!is_null($foto) && is_null(Self::a_json($foto))) {

            Log::warning('SetStockPorDeposito: no se pudo pasar a JSON la foto de depositos del movimiento '.$stock_movement->id.' (article_id '.$stock_movement->article_id.'), queda en NULL. Error: '.json_last_error_msg());

            $foto = null;
        }

        $stock_movement->stock_por_deposito = $foto;
    }

    /**
     * Arma el contenido de `stock_por_deposito` uniendo los depósitos de las dos fotos.
     *
     * "Reparte por depósitos" = tiene al menos un depósito VIVO (fila cuya dirección existe),
     * antes o después. Un artículo con solo filas de sucursales borradas lleva el stock global
     * (CheckGlobalStock lo trata así, porque la relación `addresses` no ve esas filas) y su foto
     * no cuadraría con el stock_resultante: queda NULL. Con al menos un depósito vivo, las filas
     * huérfanas SÍ entran, porque la suma de `articles.stock` las incluye. Mismo criterio para el
     * bloque de la variante.
     *
     * @param  array  $antes    ['articulo' => mapa, 'variante' => mapa] (ver foto())
     * @param  array  $despues  idem
     * @return array|null  null si ni el artículo ni la variante reparten por depósitos
     */
    static function armar($antes, $despues) {

        $articulo_reparte = Self::tiene_deposito_vivo($antes['articulo']) || Self::tiene_deposito_vivo($despues['articulo']);
        $variante_reparte = Self::tiene_deposito_vivo($antes['variante']) || Self::tiene_deposito_vivo($despues['variante']);

        if (!$articulo_reparte && !$variante_reparte) {
            return null;
        }

        $foto = ['articulo' => Self::unir($antes['articulo'], $despues['articulo'])];

        if ($variante_reparte) {
            $foto['variante'] = Self::unir($antes['variante'], $despues['variante']);
        }

        return $foto;
    }

    /**
     * @param  array  $depositos  address_id => ['deposito', 'amount', 'vivo']
     * @return bool   si alguno es de una dirección que existe
     */
    static function tiene_deposito_vivo($depositos) {

        foreach ($depositos as $deposito) {
            if (!empty($deposito['vivo'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Une los depósitos de antes y de después en una lista ordenada por `address_id`. El nombre
     * del depósito sale de la foto de después (si la fila desapareció, de la de antes).
     *
     * @param  array  $antes    address_id => ['deposito' => string|null, 'amount' => float, 'vivo' => bool]
     * @param  array  $despues  idem
     * @return array  [['address_id', 'deposito', 'anterior', 'resultante'], …]
     */
    static function unir($antes, $despues) {

        $address_ids = array_unique(array_merge(array_keys($antes), array_keys($despues)));

        sort($address_ids);

        $lista = [];

        foreach ($address_ids as $address_id) {

            if (isset($despues[$address_id])) {
                $deposito = $despues[$address_id]['deposito'];
            } else {
                $deposito = $antes[$address_id]['deposito'];
            }

            $lista[] = [
                'address_id'    => (int) $address_id,
                'deposito'      => $deposito,
                'anterior'      => Self::numero(isset($antes[$address_id]) ? $antes[$address_id]['amount'] : 0),
                'resultante'    => Self::numero(isset($despues[$address_id]) ? $despues[$address_id]['amount'] : 0),
            ];
        }

        return $lista;
    }

    /**
     * Número de la foto: float redondeado a los 2 decimales de las columnas de stock. El -0.0
     * se normaliza a 0.0, porque json_encode lo escribiría "-0.0".
     *
     * @param  mixed  $valor
     * @return float
     */
    static function numero($valor) {

        $numero = round((float) $valor, 2);

        if ($numero == 0.0) {
            return 0.0;
        }

        return $numero;
    }

    /**
     * La foto como la guarda el cast 'array' de Eloquent (`json_encode()` sin flags), para el
     * camino que inserta con el query builder (StockEnLote) y no pasa por el modelo.
     *
     * @param  array|null  $foto
     * @return string|null  null si la foto es null o no se puede pasar a JSON
     */
    static function a_json($foto) {

        if (is_null($foto)) {
            return null;
        }

        $json = json_encode($foto);

        if ($json === false) {
            return null;
        }

        return $json;
    }
}
