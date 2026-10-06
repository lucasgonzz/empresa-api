<?php

namespace App\Http\Controllers\Helpers\address;

use App\Models\Address;
use App\Models\ConceptoStockMovement;
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
 *  Hay DOS definiciones, a propósito (segunda ronda de revisión, 5/10/2026):
 *
 *  - `existe()` (ESTRICTA): la fila existe, es del dueño (`user_id` = dueño) y NO es un domicilio de
 *    comprador de la tienda (`buyer_id` nulo: la tabla es compartida con tienda-api, que guarda ahí los
 *    domicilios de envío). Es "una sucursal de verdad del comercio". La usan la validación de los
 *    destinos y reemplazos de la eliminación, y la elección de un reemplazo (`reemplazo_para()`).
 *
 *  - `existe_para_stock()` (la del MOTOR y de la entrada de comprobantes): la fila existe y NO es de
 *    OTRO comercio (`user_id` nulo o = dueño). Deja pasar los domicilios de comprador. 🔴 Por qué no se
 *    usa la estricta acá: un pedido de la tienda con envío graba en la venta `sales.address_id` =
 *    domicilio del COMPRADOR (tienda-api `OrderHelper::getAddressId` → `CreateSaleOrderHelper`), y
 *    durante años `CheckFromAddress` le abrió una fila negativa a ese domicilio. Esas filas existen en
 *    producción, y "Poner stock en 0" (`ResetStockHelper`) las recorre con su id: si el motor tratara
 *    ese id como muerto, lo mandaría a la sucursal por defecto y la fila negativa quedaría intacta.
 *    Para el motor, lo que hay que frenar es un id que NO EXISTE (sucursal borrada) o que es de OTRO
 *    comercio de la misma base; el resto es el comportamiento histórico y no se toca.
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
     * Memo por proceso: `[criterio][owner_id][address_id] => ['viva' => bool, 'hasta' => timestamp]`,
     * con `criterio` = 'estricto' (existe()) o 'stock' (existe_para_stock()).
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
        return Self::consultar('estricto', $address_id, $owner_id);
    }

    /**
     * ¿El motor de stock puede usar este id tal cual? La fila existe en `addresses` y NO es de otro
     * comercio (`user_id` nulo o igual al dueño). Un domicilio de comprador pasa (comportamiento
     * histórico: ver el docblock de la clase, "qué es viva"); un id inexistente o ajeno, no.
     *
     * @param  mixed  $address_id
     * @param  int    $owner_id
     * @return bool
     */
    static function existe_para_stock($address_id, $owner_id) {
        return Self::consultar('stock', $address_id, $owner_id);
    }

    /**
     * La consulta memoizada de los dos criterios.
     *
     * @param  string  $criterio    'estricto' | 'stock'
     * @param  mixed   $address_id
     * @param  int     $owner_id
     * @return bool
     */
    protected static function consultar($criterio, $address_id, $owner_id) {

        if (Self::es_vacio($address_id) || !is_numeric($address_id) || is_null($owner_id)) {
            return false;
        }

        $address_id = (int) $address_id;
        $owner_id   = (int) $owner_id;

        $ahora = time();

        if (isset(Self::$memo[$criterio][$owner_id][$address_id])
            && Self::$memo[$criterio][$owner_id][$address_id]['hasta'] >= $ahora) {

            return Self::$memo[$criterio][$owner_id][$address_id]['viva'];
        }

        $query = DB::table('addresses')->where('id', $address_id);

        if ($criterio === 'estricto') {

            $query->where('user_id', $owner_id)->whereNull('buyer_id');

        } else {

            // Existe y no es de OTRO comercio: user_id nulo (domicilio de comprador) o el dueño.
            $query->where(function ($q) use ($owner_id) {
                $q->whereNull('user_id')->orWhere('user_id', $owner_id);
            });
        }

        $viva = $query->exists();

        Self::$memo[$criterio][$owner_id][$address_id] = [
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

        foreach (Self::$memo as $criterio => $por_dueno) {
            foreach ($por_dueno as $owner_id => $por_sucursal) {
                unset(Self::$memo[$criterio][$owner_id][(int) $address_id]);
            }
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
     * El id que hay que usar en lugar del que llegó: el mismo si sirve, el reemplazo si está muerto,
     * y el vacío tal cual si no se eligió sucursal.
     *
     * "Sirve" con el criterio del MOTOR (`existe_para_stock()`), no el estricto: lo usan el motor y la
     * entrada de los comprobantes, y un domicilio de comprador (pedido de la tienda con envío) tiene
     * que conservar su comportamiento histórico. Solo se reemplaza un id inexistente o de otro
     * comercio. El reemplazo, en cambio, siempre es una sucursal de verdad (`reemplazo_para()`).
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

        if (Self::existe_para_stock($address_id, $owner_id)) {
            return (int) $address_id;
        }

        $reemplazo = Self::reemplazo_para($owner_id, $employee_id);

        Log::warning('SucursalVigenteHelper: la sucursal '.$address_id.' ya no existe para el comercio '.$owner_id.($donde !== '' ? ' ('.$donde.')' : '').'; se usa '.(is_null($reemplazo) ? 'ninguna (stock global)' : 'la '.$reemplazo).' en su lugar.');

        return $reemplazo;
    }

    /**
     * Conceptos que NOMBRAN depósitos a propósito: el usuario (o el sistema) eligió ESA sucursal y
     * no otra. Con una sucursal muerta, cambiarla por otra movería stock entre depósitos que nadie
     * eligió; lo correcto es no mover nada (ver aplicar_guarda_del_motor()).
     */
    const CONCEPTOS_QUE_NOMBRAN_DEPOSITOS = [
        'Mov entre depositos',
        'Mov manual entre depositos',
        'Creacion de deposito',
        'Actualizacion de deposito',
        'Eliminacion de sucursal',
        /*
         * La importación de Excel con una columna de stock por sucursal escribe la cantidad de ESA
         * sucursal. Si la sucursal se borró a mitad de la importación, volcar esa cantidad en la
         * sucursal por defecto la pisaría con un número que no es suyo: mejor no mover nada (segunda
         * ronda de revisión, F6).
         */
        'Importacion de excel',
        /*
         * "Poner stock en 0": el monto sale del pivot de ESE depósito (ResetStockHelper itera las filas
         * del artículo y manda una por una). Si el depósito ya no existe (una carrera con la eliminación),
         * redirigir ese monto a OTRA sucursal le aplicaría a ella un número que no es suyo. Mejor no
         * mover nada: la fila ya no existe y la eliminación recalculó el global (tercera ronda, A.2). OJO:
         * el nombre se compara sin distinguir mayúsculas (ver nombra_depositos()), porque el helper lo
         * pide como 'Reseteo de stock' y la tabla lo guarda como 'Reseteo de Stock'.
         */
        'Reseteo de Stock',
    ];

    /**
     * ¿El concepto nombra depósitos a propósito? Se compara sin distinguir mayúsculas: los conceptos se
     * piden por nombre desde muchos lados y la tabla `concepto_stock_movements` no siempre los guarda
     * con la misma grafía que el código ('Reseteo de stock' vs 'Reseteo de Stock').
     *
     * @param  string|null  $nombre
     * @return bool
     */
    static function nombra_depositos($nombre) {

        if (is_null($nombre)) {
            return false;
        }

        foreach (Self::CONCEPTOS_QUE_NOMBRAN_DEPOSITOS as $concepto) {

            if (mb_strtolower($concepto) === mb_strtolower($nombre)) {
                return true;
            }
        }

        return false;
    }

    /**
     * La guarda contra sucursales muertas del motor de stock (misión eliminar-sucursal-con-stock,
     * 5/10/2026, decisión D12 del plan). La llama `StockMovementController::crear()`, que es la capa
     * que cubre a TODOS los caminos, porque todos terminan ahí: ventas, devoluciones, notas de
     * crédito, compras, producción, ingresos manuales, traslados, importaciones por movimiento. Vive
     * acá (y no en el controlador) por la regla del repo: la lógica va en Helpers.
     *
     * Para cada `from_address_id` / `to_address_id` que venga con valor y que el motor no pueda usar
     * (`existe_para_stock()`: la fila no existe, o es de OTRO comercio). Un domicilio de comprador SÍ
     * pasa: es el comportamiento histórico de los pedidos de la tienda con envío, y "Poner stock en 0"
     * tiene que poder llevar a 0 las filas que esos pedidos dejaron:
     *
     *  - si el concepto NOMBRA depósitos a propósito (CONCEPTOS_QUE_NOMBRAN_DEPOSITOS) → no se mueve
     *    nada: devuelve null y queda un Log::warning;
     *  - si no (venta, devolución, nota de crédito, compra, producción, ingreso manual...) → se
     *    reemplaza por una sucursal viva (la del empleado, la por defecto, la madre o la de menor id;
     *    si no queda ninguna, null = stock global), con Log::warning. El comprobante ya ocurrió: el
     *    stock tiene que moverse en ALGÚN lado real, nunca en una fila fantasma.
     *
     * 🔴 No sacar esta guarda "porque la SPA ya valida la sucursal": el id muerto no viene solo de la
     * SPA (una venta vieja que se anula trae el suyo, un traslado viejo, un empleado con la sucursal
     * borrada elegida, un navegador que recuerda la cookie por 3 años).
     *
     * Barato en el camino normal: dos consultas memoizadas por proceso y el concepto solo se busca si
     * hay un id muerto.
     *
     * @param  array     $data         Los datos del movimiento (los de crear()).
     * @param  int|null  $concepto_id  El concepto ya resuelto por SetConcepto::get_concepto().
     * @param  int       $owner_id     Dueño del comercio (el `user_id` del movimiento).
     * @param  int|null  $employee_id  Usuario que opera (para elegir su sucursal como reemplazo).
     * @return array|null  Los datos con las sucursales resueltas, o null si no hay que mover nada.
     */
    static function aplicar_guarda_del_motor($data, $concepto_id, $owner_id, $employee_id = null) {

        /*
         * Sin dueño no se puede decidir qué sucursales existen (`consultar()` devuelve false con un dueño
         * nulo y TODO id quedaría "muerto"): un movimiento de consola o de una cola sin dueño en el
         * entorno terminaría redirigido o descartado sin motivo. Se deja pasar como antes de la guarda
         * (tercera ronda de revisión, A.4).
         */
        if (is_null($owner_id)) {
            return $data;
        }

        $muertas = [];

        foreach (['from_address_id', 'to_address_id'] as $clave) {

            if (!isset($data[$clave]) || Self::es_vacio($data[$clave])) {
                continue;
            }

            if (!Self::existe_para_stock($data[$clave], $owner_id)) {
                $muertas[] = $clave;
            }
        }

        if (count($muertas) == 0) {
            return $data;
        }

        $concepto = is_null($concepto_id) ? null : ConceptoStockMovement::find($concepto_id);

        $nombre_del_concepto = is_null($concepto) ? null : $concepto->name;

        if (Self::nombra_depositos($nombre_del_concepto)) {

            Log::warning('SucursalVigenteHelper::aplicar_guarda_del_motor: "'.$nombre_del_concepto.'" del artículo '.$data['model_id'].' nombra una sucursal que ya no existe ('.implode(', ', $muertas).': '.(isset($data['from_address_id']) ? $data['from_address_id'] : '-').' / '.(isset($data['to_address_id']) ? $data['to_address_id'] : '-').'). No se mueve nada.');

            return null;
        }

        foreach ($muertas as $clave) {
            $data[$clave] = Self::resolver(
                $data[$clave],
                $owner_id,
                $employee_id,
                'StockMovementController::crear, '.$clave.', concepto '.(is_null($nombre_del_concepto) ? 'sin concepto' : $nombre_del_concepto).', artículo '.$data['model_id']
            );
        }

        return $data;
    }

    /**
     * Como `resolver()`, pero para la EDICIÓN de un comprobante que ya existe (venta o presupuesto).
     *
     * 🔴 Si el request trae la MISMA sucursal que el comprobante ya tiene guardada, se deja tal cual
     * aunque esa sucursal ya no exista (segunda ronda de revisión, 5/10/2026). D2 del plan: las ventas
     * y presupuestos viejos NO se reescriben (cambiaría la atribución por sucursal de los reportes). El
     * stock igual queda bien: el motor redirige el movimiento por D12 (`crear()`), así que la venta
     * conserva su historia y ninguna fila fantasma se abre. Solo se reemplaza si el usuario MANDA un id
     * distinto del guardado y ese id está muerto (la cookie vieja de Vender, por ejemplo).
     *
     * @param  mixed     $address_id  El que trae el request.
     * @param  mixed     $guardado    El que tiene hoy el comprobante.
     * @param  int       $owner_id
     * @param  int|null  $employee_id
     * @param  string    $donde
     * @return mixed
     */
    static function resolver_al_editar($address_id, $guardado, $owner_id, $employee_id = null, $donde = '') {

        if (!Self::es_vacio($address_id) && !Self::es_vacio($guardado) && (int) $address_id === (int) $guardado) {
            return (int) $address_id;
        }

        return Self::resolver($address_id, $owner_id, $employee_id, $donde);
    }
}
