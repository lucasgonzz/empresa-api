<?php

namespace App\Http\Controllers\Helpers\client;

use App\Http\Controllers\Helpers\article\SincronizacionOfflineArticulosHelper;
use App\Models\Client;

/**
 * Reglas de la sincronización del caché de CLIENTES de VENDER sin conexión (misión
 * clientes-sin-internet, 26/10/2026).
 *
 * Hasta esta misión VENDER sin red podía vender con los artículos descargados a IndexedDB, pero no
 * podía elegir cliente: la lista de clientes salía siempre de la API. Ahora la SPA baja también los
 * clientes del comercio y los pone al día con dos endpoints, con el MISMO contrato que los de
 * artículos (ver `SincronizacionOfflineArticulosHelper`), en este orden:
 *
 *   1. `GET client/index/offline-eliminados` (`ClientController::index_offline_eliminados()`): todo
 *      lo que viene ahí se BORRA del caché (solo usa el `id`). Ver `consulta_de_eliminados()`.
 *   2. `GET client/index/offline` (`ClientController::index_offline()`): todo lo que viene ahí se
 *      GUARDA en el caché (`bulkPut`), con `withAll()` para que Vender tenga lo mismo que cuando
 *      elige el cliente con red (condición de IVA, lista de precios, cuentas corrientes con su
 *      moneda, descuentos y recargos). Con `updated_after`, solo lo tocado después de esa marca.
 *      Devuelve `sincronizado_hasta`, la marca de la próxima vuelta. Ver `consulta_de_vivos()`.
 *
 * A diferencia de los artículos, un cliente no tiene un estado "no vendible": vivo es vendible y
 * borrado (SoftDeletes) no. Por eso `offline-eliminados` trae solo los borrados, con o sin marca, y
 * no hace falta el complemento que arma `aplicar_criterio_de_no_vendibles()` para artículos.
 *
 * 🔴 ORDEN POR `id` ASC en los dos: es único y estable entre páginas. Ordenar por `created_at` o
 * `deleted_at` (con valores repetidos) deja que la misma fila caiga en dos páginas y otra en
 * ninguna, y la SPA recorre todas las páginas confiando en que el conjunto queda completo.
 *
 * Lo que NO cubre la marca (y por qué no es un hueco de esta misión): `updated_at` del cliente no se
 * mueve cuando cambia el SALDO de su cuenta corriente (vive en `credit_accounts`) ni cuando se le
 * vinculan descuentos/recargos por el pivot. Vender sin red no decide nada con el saldo (el límite
 * de crédito de una venta offline no se rechaza al subir: ver
 * `LimiteCreditoHelper::validar_venta_nueva()`), y la SPA puede pedir sin `updated_after` (baja
 * completa) cuando quiera rehacer el caché.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class SincronizacionOfflineClientesHelper {

    /** Página por defecto: la misma que artículos. */
    const PER_PAGE_DEFAULT = 500;

    /**
     * Techo de la página. 1000 y no 2000 (como `ClientController::index()`): acá cada fila trae
     * `withAll()`, que con las cuentas corrientes por moneda y los ajustes de venta es bastante más
     * que la fila sola. Mismo criterio que `ArticleController::index()`.
     */
    const PER_PAGE_TECHO = 1000;

    /**
     * El `per_page` del request, acotado: ausente, cero o negativo cae al default; arriba del techo
     * queda en el techo.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return int
     */
    static function per_page($request) {

        $per_page = (int) $request->input('per_page', Self::PER_PAGE_DEFAULT);

        if ($per_page <= 0) {
            $per_page = Self::PER_PAGE_DEFAULT;
        }

        if ($per_page > Self::PER_PAGE_TECHO) {
            $per_page = Self::PER_PAGE_TECHO;
        }

        return $per_page;
    }

    /**
     * La marca `sincronizado_hasta` que devuelve `index_offline()`.
     *
     * Es exactamente la de artículos (hora del servidor tomada ANTES de la consulta, en la zona de
     * la app, un segundo para atrás, `Y-m-d H:i:s`): se delega en
     * `SincronizacionOfflineArticulosHelper::marca_de_sincronizacion()` en lugar de copiarla, para
     * que las dos marcas no se separen nunca. El porqué de cada detalle está en su docblock.
     *
     * 🔴 Se llama ANTES de armar la consulta: un cliente modificado mientras corre la consulta queda
     * con `updated_at` posterior a la marca y entra en la próxima vuelta.
     *
     * @return string  `Y-m-d H:i:s`.
     */
    static function marca_de_sincronizacion() {

        return SincronizacionOfflineArticulosHelper::marca_de_sincronizacion();
    }

    /**
     * Consulta de los clientes VIVOS del comercio para el caché (`client/index/offline`).
     *
     * Sin `updated_after` (primera sincronización, caché vacío) trae todos. Con `updated_after`,
     * solo los de `updated_at > updated_after`: el mismo comparador estricto que artículos, que con
     * el segundo de margen de la marca no pierde nada (ver `marca_de_sincronizacion()`).
     *
     * `Client` usa SoftDeletes, así que los borrados quedan afuera solos: esos vienen por
     * `consulta_de_eliminados()`.
     *
     * @param  int          $user_id        El comercio (`Controller::userId()`).
     * @param  string|null  $updated_after  La marca de la vuelta anterior, o null.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    static function consulta_de_vivos($user_id, $updated_after) {

        $query = Client::where('user_id', $user_id);

        if ($updated_after) {
            $query->where('updated_at', '>', $updated_after);
        }

        return $query->orderBy('id', 'ASC')
                     ->withAll();
    }

    /**
     * Consulta de los clientes BORRADOS del comercio, para que la SPA los saque del caché
     * (`client/index/offline-eliminados`).
     *
     * Sin `updated_after`, todos los borrados (la SPA con caché vacío no tiene nada que sacar, pero
     * el contrato es el mismo que el de artículos). Con `updated_after`, los borrados desde la marca
     * (`deleted_at >= updated_after`, el mismo comparador inclusivo que `index_deleted()` de
     * artículos: borrar de más del caché algo que ya no está es inocuo).
     *
     * Solo `id` y `deleted_at`: la SPA borra por id y no necesita el resto de la fila.
     *
     * @param  int          $user_id        El comercio (`Controller::userId()`).
     * @param  string|null  $updated_after  La marca de la vuelta anterior, o null.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    static function consulta_de_eliminados($user_id, $updated_after) {

        $query = Client::withTrashed()
                        ->where('user_id', $user_id)
                        ->whereNotNull('deleted_at');

        if ($updated_after) {
            $query->where('deleted_at', '>=', $updated_after);
        }

        return $query->select('id', 'deleted_at')
                     ->orderBy('id', 'ASC');
    }
}
