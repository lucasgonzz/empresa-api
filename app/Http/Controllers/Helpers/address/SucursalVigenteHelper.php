<?php

namespace App\Http\Controllers\Helpers\address;

use App\Models\Address;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ¿Esta sucursal sigue existiendo? Y si no, ¿cuál la reemplaza? (misión
 * eliminar-sucursal-con-stock, 5/10/2026).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  POR QUÉ EXISTE
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Una sucursal borrada deja su id vivo en muchos lugares que nadie limpia al instante: la cookie
 *  `address_id` de la SPA (3 años), `users.address_id` de un empleado, una venta vieja que se anula,
 *  una nota de crédito, una compra, un traslado pendiente. Cuando ese id muerto llega al motor de
 *  stock, `CheckFromAddress` / `CheckToAddress` / `CheckVariants` hacen `attach(id_muerto, cantidad)`
 *  porque el artículo "no tiene fila" para esa sucursal (la relación `addresses` es un INNER JOIN con
 *  `addresses` y no ve la fila vieja). Esa fila fantasma la suma `ArticleHelper::setArticleStockFromAddresses`
 *  (`SUM(address_article.amount)` crudo) pero no la muestra ninguna pantalla: el stock global deja de
 *  ser la suma de las sucursales que el usuario ve. Es exactamente lo que pasó en 3DTisk (frente
 *  tresdtisk2): 65 filas con −69 unidades en el "depósito 3", borrado hacía días.
 *
 *  Este helper es la ÚNICA definición de "sucursal viva" que usan las guardas (D12 del plan):
 *  el motor (`StockMovementController::crear()`), el alta manual de movimientos, la entrada de los
 *  comprobantes (ventas y presupuestos), los traslados de depósito y la edición de stock por sucursal.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  QUÉ ES "VIVA"
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Una fila de `addresses` que (a) existe, (b) es del dueño del comercio (`user_id` = dueño) y
 *  (c) NO es un domicilio de comprador de la tienda (`buyer_id` nulo: la tabla es compartida con
 *  tienda-api, que guarda ahí los domicilios de envío). Un id de otro comercio de la misma base cuenta
 *  como muerto a propósito: mover stock contra la sucursal de otro comercio es el mismo fantasma.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 EL MEMO Y POR QUÉ VENCE RÁPIDO
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `existe()` se pregunta en CADA movimiento de stock (una venta de 50 renglones son 50 preguntas, y
 *  la importación de Excel miles). Sin memo sería una consulta por renglón. Con memo eterno, un
 *  `queue:work` (proceso que vive horas) seguiría viendo viva una sucursal que otro proceso acaba de
 *  borrar, y volvería a abrir la fila fantasma. Por eso el memo dura SEGUNDOS_DE_MEMO y nada más: es
 *  el balance entre "una consulta por renglón" y "la ventana en la que un worker viejo todavía no se
 *  enteró". El que borra (`EliminarSucursalHelper`) llama a `olvidar()` en su propio proceso, y los
 *  tests también.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class SucursalVigenteHelper {

    /**
     * Cuántos segundos vale una respuesta memoizada de `existe()`. Corto a propósito: ver el
     * docblock de la clase ("el memo y por qué vence rápido").
     */
    const SEGUNDOS_DE_MEMO = 10;

    /**
     * Memo por proceso: `[owner_id][address_id] => ['viva' => bool, 'hasta' => timestamp]`.
     *
     * @var array
     */
    protected static $memo = [];

    /**
     * ¿El id es "vacío" (sin sucursal elegida)? null, '', 0 y '0' significan "no eligió sucursal", que
     * es un estado válido (stock global) y NO un id muerto: nunca se reemplaza.
     *
     * @param  mixed  $address_id
     * @return bool
     */
    static function es_vacio($address_id) {

        if (is_null($address_id) || $address_id === '' || $address_id === false) {
            return true;
        }

        if (is_numeric($address_id) && (int) $address_id === 0) {
            return true;
        }

        return false;
    }

    /**
     * ¿La sucursal existe, es del dueño y no es un domicilio de comprador?
     *
     * Un id vacío (ver `es_vacio()`) devuelve false: no es una sucursal. Los llamadores preguntan
     * `es_vacio()` antes cuando "sin sucursal" es válido para ellos.
     *
     * @param  mixed  $address_id
     * @param  int    $owner_id   Dueño del comercio (nunca el empleado).
     * @return bool
     */
    static function existe($address_id, $owner_id) {

        if (Self::es_vacio($address_id) || !is_numeric($address_id) || is_null($owner_id)) {
            return false;
        }

        $address_id = (int) $address_id;
        $owner_id   = (int) $owner_id;

        $ahora = time();

        if (isset(Self::$memo[$owner_id][$address_id])
            && Self::$memo[$owner_id][$address_id]['hasta'] >= $ahora) {

            return Self::$memo[$owner_id][$address_id]['viva'];
        }

        $viva = DB::table('addresses')
                    ->where('id', $address_id)
                    ->where('user_id', $owner_id)
                    ->whereNull('buyer_id')
                    ->exists();

        Self::$memo[$owner_id][$address_id] = [
            'viva'  => $viva,
            'hasta' => $ahora + Self::SEGUNDOS_DE_MEMO,
        ];

        return $viva;
    }

    /**
     * Borra el memo (todo, o el de una sucursal). Lo llama `EliminarSucursalHelper` después de borrar
     * una sucursal, para que el mismo proceso no la siga viendo viva durante los segundos del memo,
     * y los tests entre caso y caso.
     *
     * @param  int|null  $address_id  null = todo el memo.
     * @return void
     */
    static function olvidar($address_id = null) {

        if (is_null($address_id)) {
            Self::$memo = [];
            return;
        }

        foreach (Self::$memo as $owner_id => $por_sucursal) {
            unset(Self::$memo[$owner_id][(int) $address_id]);
        }
    }

    /**
     * La sucursal viva que reemplaza a una muerta, para el comercio y el usuario que está operando.
     *
     * Orden de preferencia, y por qué:
     *  1. La sucursal del EMPLEADO que opera (`users.address_id`), si está viva: es la que el usuario
     *     eligió para trabajar, así que el comprobante y el stock quedan donde él cree que está.
     *  2. La sucursal POR DEFECTO del comercio (`default_address`).
     *  3. El DEPÓSITO MADRE (`Address::deposito_madre_de()`, con su propia guarda de esquema).
     *  4. La de MENOR id: determinístico, la más vieja del comercio.
     *  5. Ninguna (null): el comercio ya no tiene sucursales y el movimiento va al stock global.
     *
     * El empleado tiene que ser del comercio (el dueño mismo o `owner_id` = dueño): un id ajeno no
     * puede elegir sucursal por nadie.
     *
     * @param  int       $owner_id
     * @param  int|null  $employee_id  Usuario autenticado (empleado o el dueño).
     * @return int|null
     */
    static function reemplazo_para($owner_id, $employee_id = null) {

        if (is_null($owner_id)) {
            return null;
        }

        if (!is_null($employee_id)) {

            $del_empleado = DB::table('users')
                                ->where('id', (int) $employee_id)
                                ->where(function ($q) use ($owner_id) {
                                    $q->where('id', (int) $owner_id)
                                      ->orWhere('owner_id', (int) $owner_id);
                                })
                                ->value('address_id');

            if (!Self::es_vacio($del_empleado) && Self::existe($del_empleado, $owner_id)) {
                return (int) $del_empleado;
            }
        }

        $por_defecto = Address::where('user_id', (int) $owner_id)
                                ->whereNull('buyer_id')
                                ->where('default_address', 1)
                                ->orderBy('id')
                                ->value('id');

        if (!is_null($por_defecto)) {
            return (int) $por_defecto;
        }

        $madre = Address::deposito_madre_de((int) $owner_id);

        if (!is_null($madre)) {
            return (int) $madre->id;
        }

        $primera = Address::where('user_id', (int) $owner_id)
                            ->whereNull('buyer_id')
                            ->orderBy('id')
                            ->value('id');

        return is_null($primera) ? null : (int) $primera;
    }

    /**
     * El id que hay que usar en lugar del que llegó: el mismo si está vivo, el reemplazo si está
     * muerto, y el vacío tal cual si no se eligió sucursal.
     *
     * Deja un `Log::warning` cada vez que reemplaza: es la única pista de que todavía hay algo (una
     * cookie, un empleado, un comprobante viejo) apuntando a una sucursal borrada.
     *
     * @param  mixed     $address_id
     * @param  int       $owner_id
     * @param  int|null  $employee_id
     * @param  string    $donde        Para el log: quién preguntó.
     * @return mixed  El id original (vacío o vivo), el reemplazo (int) o null si no queda ninguna.
     */
    static function resolver($address_id, $owner_id, $employee_id = null, $donde = '') {

        if (Self::es_vacio($address_id)) {
            return $address_id;
        }

        if (Self::existe($address_id, $owner_id)) {
            return (int) $address_id;
        }

        $reemplazo = Self::reemplazo_para($owner_id, $employee_id);

        Log::warning('SucursalVigenteHelper: la sucursal '.$address_id.' ya no existe para el comercio '.$owner_id.($donde !== '' ? ' ('.$donde.')' : '').'; se usa '.(is_null($reemplazo) ? 'ninguna (stock global)' : 'la '.$reemplazo).' en su lugar.');

        return $reemplazo;
    }
}
