<?php

namespace App\Http\Controllers\Helpers\address;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Http\Controllers\Stock\StockMovementController;
use App\Jobs\EliminarSucursalJob;
use App\Models\Address;
use App\Models\Article;
use App\Models\ConceptoStockMovement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Eliminar una sucursal sin dejar basura (misión eliminar-sucursal-con-stock, 5/10/2026).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  QUÉ PASABA ANTES
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `AddressController::destroy()` creaba un movimiento "Eliminacion de sucursal" por artículo, hacía
 *  `articles()->detach()` y borraba la fila. Quedaban colgados: el stock de las VARIANTES
 *  (`address_article_variant` ni se miraba, y para un artículo con variantes el recálculo reconstruía
 *  el pivot desde las variantes y DESHACÍA el descuento: el libro decía −N y el stock no se movía),
 *  los empleados con esa sucursal elegida (`users.address_id`), las cajas y puntos de venta (que
 *  quedaban INVISIBLES en Vender), las marcas de sucursal por defecto / madre / origen, y los
 *  artículos en la papelera. Además no había forma de elegir qué hacer con el stock, ni transacción,
 *  ni scope por dueño (un id ajeno o inexistente era un 500).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  QUÉ HACE AHORA (las decisiones D1–D15 del plan, en el código)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  - D1 Borrado FÍSICO de la fila de `addresses` (no hay `deleted_at`, y la tabla la comparte
 *    tienda-api: una sucursal "borrada lógicamente" seguiría apareciendo en la tienda).
 *  - D3 Sin decisión y con algo que decidir → 422 `requiere_decision`. Sin nada que decidir, borra.
 *  - D4 `transferir`: un "Mov entre depositos" por artículo (y por variante) hacia el destino, por
 *    el camino normal de `StockMovementController::crear()`. El stock global no cambia.
 *  - D5 `descartar`: un "Eliminacion de sucursal" (monto = −stock) por artículo y variante. El
 *    global lo recalcula el motor: suma de las sucursales que quedan (o de las variantes).
 *  - D6 Última sucursal: los artículos conservan su `articles.stock` (vuelven a "sin depósitos"),
 *    sin movimientos, y se borran solo las filas del pivot.
 *  - D7 Artículos en la papelera: por SQL (el motor usa `Article::find`, que no los ve), sin
 *    renglón en el libro.
 *  - D8 Empleados: `reasignar` o `dejar_sin_sucursal`, solo del comercio.
 *  - D9/D10 Marcas, cajas, puntos de venta y clientes pasan al reemplazo (o quedan en NULL = "todas
 *    las sucursales"); los métodos de pago por defecto de la sucursal se borran.
 *  - D11 Traslados de stock pendientes BLOQUEAN el borrado.
 *  - D13 Candado por sucursal; el stock se mueve artículo por artículo, cada uno en su transacción
 *    e idempotente; la parte final va en UNA transacción.
 *  - D14 Más de `FILAS_EN_LINEA` filas con stock → job en segundo plano (202).
 *  - D15 Scope por dueño (404 si no es suya); un domicilio de comprador se borra como siempre.
 *
 * Contrato con la SPA: `GET address/{id}/eliminar-resumen` (`resumen()`) y `DELETE address/{id}`
 * con `stock_accion`, `stock_destino_id`, `usuarios_accion`, `usuarios_destino_id`, `reemplazo_id`,
 * todos opcionales. Ver el plan de la misión (§4) y `AddressController`.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class EliminarSucursalHelper {

    /**
     * D14: hasta esta cantidad de filas de pivot con stock distinto de cero (artículos + variantes)
     * el borrado se hace en el mismo request; por encima se encola. 150 artículos por el motor de
     * stock son unos segundos; miles no entran en el timeout de PHP-FPM del shared hosting.
     */
    const FILAS_EN_LINEA = 150;

    /**
     * Umbral efectivo. null = `FILAS_EN_LINEA`. Existe SOLO para que los tests puedan probar el
     * camino en segundo plano sin armar 151 artículos; nadie del código de producción lo toca.
     *
     * @var int|null
     */
    public static $filas_en_linea = null;

    /**
     * Cuántas veces se repite "mover el stock → intentar la fase final" antes de rendirse. Cada
     * repetición existe porque entró stock nuevo a la sucursal mientras se vaciaba (una venta desde
     * un navegador que todavía la tiene elegida); más de cinco seguidas no es una carrera, es algo que
     * el motor no está pudiendo mover, y seguir girando no lo arregla.
     */
    const MAXIMO_DE_PASADAS = 5;

    /** Valores de `stock_accion`. */
    const STOCK_TRANSFERIR = 'transferir';
    const STOCK_DESCARTAR  = 'descartar';

    /** Valores de `usuarios_accion`. */
    const USUARIOS_REASIGNAR     = 'reasignar';
    const USUARIOS_SIN_SUCURSAL  = 'dejar_sin_sucursal';

    /** Conceptos de stock que usa el borrado (se buscan por NOMBRE: los ids varían entre bases). */
    const CONCEPTO_TRANSFERIR = 'Mov entre depositos';
    const CONCEPTO_DESCARTAR  = 'Eliminacion de sucursal';

    /**
     * Lo que lee el usuario si la eliminación se corta por un error (500 en línea, registro en fallo
     * en segundo plano). FIJO a propósito: el mensaje de la excepción puede traer SQL, rutas de archivos
     * o nombres de tablas, y eso va solo al log (Log::error), nunca a la pantalla.
     */
    const MENSAJE_SI_SE_CORTA = 'No se pudo terminar de eliminar la sucursal. Volvé a intentarlo: continúa donde quedó.';

    /** Tipo del registro visible del proceso en segundo plano (`background_processes.tipo`). */
    const TIPO_DE_PROCESO = 'eliminacion_sucursal';

    /** Marcas de `addresses` que hereda la sucursal de reemplazo (D9). */
    const MARCAS = ['default_address', 'es_deposito_madre', 'es_deposito_origen'];

    // ------------------------------------------------------------------------------------------
    // Entrada: de quién es la sucursal y qué decidió el usuario
    // ------------------------------------------------------------------------------------------

    /**
     * La fila de `addresses` si es del comercio, o null (D15: el controller responde 404).
     *
     * Una sucursal es del comercio si `user_id` es el dueño. Un domicilio de comprador (`buyer_id`
     * no nulo) lo escribe tienda-api SIN `user_id`: es del comercio si el comprador es suyo
     * (`buyers.user_id`). Se lo acepta para que siga pudiendo borrarse "como siempre" (D15) sin
     * abrirle la puerta a los domicilios de compradores de otro comercio.
     *
     * @param  mixed  $address_id
     * @param  int    $owner_id
     * @return \App\Models\Address|null
     */
    static function direccion_del_dueno($address_id, $owner_id) {

        if (!is_numeric($address_id) || (int) $address_id <= 0 || is_null($owner_id)) {
            return null;
        }

        $address = Address::find((int) $address_id);

        if (is_null($address)) {
            return null;
        }

        if ((int) $address->user_id === (int) $owner_id) {
            return $address;
        }

        if (!is_null($address->buyer_id)) {

            $dueno_del_comprador = DB::table('buyers')->where('id', $address->buyer_id)->value('user_id');

            if (!is_null($dueno_del_comprador) && (int) $dueno_del_comprador === (int) $owner_id) {
                return $address;
            }
        }

        return null;
    }

    /**
     * La decisión del usuario, normalizada, leída de un request (query string o cuerpo JSON).
     *
     * 🔴 Se lee de `request()` y no de un parámetro de `destroy()` A PROPÓSITO: `destroy($id)` lo
     * llaman con UN solo argumento el borrado masivo (`DeleteModelsHelper::process_delete`, también
     * desde su job) y la baja del asistente IA. Cambiarle la firma los rompía; leyendo el request
     * global, esos caminos simplemente llegan "sin decisión" (D3) y reciben un 422 legible si hay
     * algo que decidir.
     *
     * @param  \Illuminate\Http\Request|null  $request
     * @return array  Claves: stock_accion, stock_destino_id, usuarios_accion, usuarios_destino_id,
     *                reemplazo_id. Las ausentes van en null.
     */
    static function decision_del_request($request) {

        $leer = function ($clave) use ($request) {
            if (!($request instanceof Request)) {
                return null;
            }
            return $request->input($clave);
        };

        return Self::normalizar_decision([
            'stock_accion'        => $leer('stock_accion'),
            'stock_destino_id'    => $leer('stock_destino_id'),
            'usuarios_accion'     => $leer('usuarios_accion'),
            'usuarios_destino_id' => $leer('usuarios_destino_id'),
            'reemplazo_id'        => $leer('reemplazo_id'),
        ]);
    }

    /**
     * Normaliza una decisión: acciones en minúscula y sin espacios (vacío = null), ids enteros
     * positivos (0, '' y basura = null). La usan el request y el job, que la recibe ya armada.
     *
     * @param  array  $decision
     * @return array
     */
    static function normalizar_decision($decision) {

        $texto = function ($valor) {
            if (is_null($valor) || !is_scalar($valor)) {
                return null;
            }
            $valor = strtolower(trim((string) $valor));
            return $valor === '' ? null : $valor;
        };

        $id = function ($valor) {
            if (is_null($valor) || !is_numeric($valor) || (int) $valor <= 0) {
                return null;
            }
            return (int) $valor;
        };

        $decision = is_array($decision) ? $decision : [];

        return [
            'stock_accion'        => $texto(isset($decision['stock_accion']) ? $decision['stock_accion'] : null),
            'stock_destino_id'    => $id(isset($decision['stock_destino_id']) ? $decision['stock_destino_id'] : null),
            'usuarios_accion'     => $texto(isset($decision['usuarios_accion']) ? $decision['usuarios_accion'] : null),
            'usuarios_destino_id' => $id(isset($decision['usuarios_destino_id']) ? $decision['usuarios_destino_id'] : null),
            'reemplazo_id'        => $id(isset($decision['reemplazo_id']) ? $decision['reemplazo_id'] : null),
        ];
    }

    /**
     * La sucursal que hereda marcas, cajas, puntos de venta y clientes (D9/D10): la elegida en
     * `reemplazo_id`; si no vino, el destino del stock (si se transfiere); si no, el destino de los
     * empleados (si se reasignan); si no, ninguna.
     *
     * Los destinos cuentan solo si su acción los usa: un `stock_destino_id` que viaja junto con
     * `descartar` (un select que quedó cargado en la SPA) no es una elección del usuario.
     *
     * @param  array  $decision  Normalizada.
     * @return int|null
     */
    static function reemplazo_de_la_decision($decision) {

        if (!is_null($decision['reemplazo_id'])) {
            return $decision['reemplazo_id'];
        }

        if ($decision['stock_accion'] === Self::STOCK_TRANSFERIR && !is_null($decision['stock_destino_id'])) {
            return $decision['stock_destino_id'];
        }

        if ($decision['usuarios_accion'] === Self::USUARIOS_REASIGNAR && !is_null($decision['usuarios_destino_id'])) {
            return $decision['usuarios_destino_id'];
        }

        return null;
    }

    /**
     * Umbral efectivo de D14.
     *
     * @return int
     */
    static function umbral() {
        return is_null(Self::$filas_en_linea) ? Self::FILAS_EN_LINEA : (int) Self::$filas_en_linea;
    }

    // ------------------------------------------------------------------------------------------
    // El resumen (GET address/{id}/eliminar-resumen)
    // ------------------------------------------------------------------------------------------

    /**
     * Todo lo que el modal de la SPA necesita para preguntar, y lo mismo que usa `validar()` para
     * decidir si hay algo que decidir. La forma es el contrato del plan (§4).
     *
     * @param  \App\Models\Address  $address
     * @param  int                  $owner_id
     * @return array
     */
    static function resumen($address, $owner_id) {

        $es_de_comprador = !is_null($address->buyer_id);

        $datos_de_la_sucursal = [
            'id'                 => (int) $address->id,
            'street'             => $address->street,
            'default_address'    => (int) $address->default_address,
            'es_deposito_origen' => (int) $address->es_deposito_origen,
            // Sin la columna todavía (deploy a medio migrar) vale 0: ver Address::columna_madre_existe().
            'es_deposito_madre'  => Address::columna_madre_existe() ? (int) $address->es_deposito_madre : 0,
        ];

        /*
         * Un domicilio de comprador no es una sucursal: no tiene stock, empleados ni marcas que
         * decidir (D15). Se devuelve la forma completa en cero para que la SPA no tenga casos aparte.
         */
        if ($es_de_comprador) {
            return [
                'address'                   => $datos_de_la_sucursal,
                'es_domicilio_de_comprador' => true,
                'otras_sucursales'          => [],
                'es_la_ultima'              => false,
                'stock'                     => Self::stock_vacio(),
                'usuarios'                  => [],
                'tiene_ventas'              => false,
                'cajas'                     => 0,
                'puntos_de_venta'           => 0,
                'clientes'                  => 0,
                'marcas'                    => [],
                'requiere_reemplazo'        => false,
                'bloqueos'                  => [],
                'en_segundo_plano'          => false,
                'ya_en_proceso'             => Self::esta_en_proceso($owner_id, $address),
            ];
        }

        $otras = Self::otras_sucursales($address, $owner_id);

        $es_la_ultima = count($otras) === 0;

        $stock = Self::conteo_de_stock($address->id, $owner_id);

        $marcas = Self::marcas_de($address);

        return [
            'address'                   => $datos_de_la_sucursal,
            'es_domicilio_de_comprador' => false,
            'otras_sucursales'          => $otras,
            'es_la_ultima'              => $es_la_ultima,
            'stock'                     => $stock,
            'usuarios'                  => Self::usuarios_asignados($address->id, $owner_id),
            // Incluye las ventas en la papelera: también guardan el id, y la SPA solo avisa que se conservan.
            'tiene_ventas'              => DB::table('sales')->where('user_id', $owner_id)->where('address_id', $address->id)->exists(),
            'cajas'                     => DB::table('cajas')->where('user_id', $owner_id)->where('address_id', $address->id)->count(),
            'puntos_de_venta'           => DB::table('afip_information')->where('user_id', $owner_id)->where('address_id', $address->id)->count(),
            'clientes'                  => DB::table('clients')->where('user_id', $owner_id)->where('address_id', $address->id)->whereNull('deleted_at')->count(),
            'marcas'                    => $marcas,
            // D9: con marcas y con otras sucursales a donde pasarlas, el reemplazo es obligatorio.
            'requiere_reemplazo'        => count($marcas) > 0 && !$es_la_ultima,
            'bloqueos'                  => Self::bloqueos($address->id, $owner_id),
            // D14, y solo si hay movimientos que hacer: en la última sucursal (D6) no se mueve nada.
            'en_segundo_plano'          => !$es_la_ultima && $stock['filas'] > Self::umbral(),
            'ya_en_proceso'             => Self::esta_en_proceso($owner_id, $address),
        ];
    }

    /**
     * Las otras sucursales vivas del comercio (las que pueden recibir stock, empleados o marcas).
     *
     * @param  \App\Models\Address  $address
     * @param  int                  $owner_id
     * @return array  [{id, street}]
     */
    static function otras_sucursales($address, $owner_id) {

        $otras = [];

        $filas = Address::where('user_id', $owner_id)
                        ->whereNull('buyer_id')
                        ->where('id', '!=', $address->id)
                        ->orderBy('id')
                        ->get(['id', 'street']);

        foreach ($filas as $fila) {
            $otras[] = ['id' => (int) $fila->id, 'street' => $fila->street];
        }

        return $otras;
    }

    /**
     * La forma de `stock` con todo en cero.
     *
     * @return array
     */
    static function stock_vacio() {
        return [
            'articulos'             => 0,
            'variantes'             => 0,
            'filas'                 => 0,
            'unidades'              => 0.0,
            'filas_negativas'       => 0,
            'unidades_negativas'    => 0.0,
            'articulos_en_papelera' => 0,
        ];
    }

    /**
     * Las filas de `address_article` de la sucursal con stock distinto de cero, con el estado del
     * artículo. Incluye artículos en la papelera (D7). Se hace INNER JOIN con `articles` (que ve la
     * papelera porque es SQL crudo) para dejar afuera las filas de artículos que ya no existen:
     * esas no tienen stock que mover y la fase final las borra igual.
     *
     * @param  int  $address_id
     * @param  int  $owner_id    Solo artículos de este dueño (ver query_filas_de_articulos_con_stock()).
     * @return \Illuminate\Support\Collection  {id, article_id, amount, deleted_at}
     */
    static function filas_de_articulos_con_stock($address_id, $owner_id) {

        return Self::query_filas_de_articulos_con_stock($address_id, $owner_id)
                    ->get(['aa.id', 'aa.article_id', 'aa.amount', 'a.deleted_at']);
    }

    /**
     * Las filas de `address_article_variant` de la sucursal con stock distinto de cero, con su
     * artículo. Mismo criterio de joins que `filas_de_articulos_con_stock()`.
     *
     * @param  int  $address_id
     * @param  int  $owner_id    Solo artículos de este dueño (ver query_filas_de_articulos_con_stock()).
     * @return \Illuminate\Support\Collection  {id, article_variant_id, article_id, amount, deleted_at}
     */
    static function filas_de_variantes_con_stock($address_id, $owner_id) {

        return Self::query_filas_de_variantes_con_stock($address_id, $owner_id)
                    ->get(['aav.id', 'aav.article_variant_id', 'av.article_id', 'aav.amount', 'a.deleted_at']);
    }

    /*
     * 🔴 LA ÚNICA DEFINICIÓN DE "FILA CON STOCK QUE HAY QUE MOVER" (segunda ronda de revisión,
     * 5/10/2026). La usan el resumen, la pasada de stock Y la última mirada de la fase final. Antes la
     * fase final tenía su propia consulta con otros joins: una fila de variante cuyo artículo ya no
     * existe (borrado físico) la contaba la fase final pero no la pasada, que nunca la iba a mover, y
     * la sucursal quedaba IMBORRABLE ("sigue recibiendo stock" y 500 en cada intento). Si hace falta
     * cambiar el criterio, se cambia ACÁ y vale para los tres.
     *
     * 🔴 Y solo artículos DEL DUEÑO (`articles.user_id`). En una base compartida por varios comercios
     * (u767360347_empresa), un artículo de OTRO comercio puede tener una fila en esta sucursal (una
     * cookie vieja, un dato cruzado): moverlo usaría la identidad de este dueño en el libro de otro
     * comercio y le abriría una fila en NUESTRA sucursal de destino. Esas filas ajenas no se mueven:
     * la fase final las borra sin movimiento (ver fase_final()).
     */

    /**
     * Query de las filas de artículos del dueño con stock ≠ 0 en la sucursal (artículo existente,
     * incluida la papelera).
     *
     * @param  int  $address_id
     * @param  int  $owner_id
     * @return \Illuminate\Database\Query\Builder
     */
    static function query_filas_de_articulos_con_stock($address_id, $owner_id) {

        return DB::table('address_article as aa')
                    ->join('articles as a', 'a.id', '=', 'aa.article_id')
                    ->where('aa.address_id', $address_id)
                    ->where('a.user_id', $owner_id)
                    ->whereNotNull('aa.amount')
                    ->where('aa.amount', '!=', 0);
    }

    /**
     * Query de las filas de variantes de artículos del dueño con stock ≠ 0 en la sucursal (variante y
     * artículo existentes, incluida la papelera).
     *
     * @param  int  $address_id
     * @param  int  $owner_id
     * @return \Illuminate\Database\Query\Builder
     */
    static function query_filas_de_variantes_con_stock($address_id, $owner_id) {

        return DB::table('address_article_variant as aav')
                    ->join('article_variants as av', 'av.id', '=', 'aav.article_variant_id')
                    ->join('articles as a', 'a.id', '=', 'av.article_id')
                    ->where('aav.address_id', $address_id)
                    ->where('a.user_id', $owner_id)
                    ->whereNotNull('aav.amount')
                    ->where('aav.amount', '!=', 0);
    }

    /**
     * Los conteos de stock del resumen.
     *
     * - `filas`: filas de pivot con stock ≠ 0, de las DOS tablas. Es la medida de volumen de D14.
     * - `articulos`: artículos distintos con stock ≠ 0 acá, por su fila o por la de alguna variante.
     * - `variantes`: variantes distintas con stock ≠ 0 acá.
     * - `unidades` / `unidades_negativas`: 🔴 sin contar dos veces. En un artículo cuyas variantes
     *   reparten por depósitos, la fila del ARTÍCULO es la suma de las de sus variantes (la reconstruye
     *   `ArticleHelper::setArticleStockFromAddresses()`): sumar las dos tablas duplicaría ese stock.
     *   Por eso, para un artículo con filas de variante acá cuentan solo las variantes, y para el
     *   resto, la fila del artículo. Es el mismo conjunto que mueve `procesar_articulo()`.
     * - `filas_negativas`: filas con stock negativo, de las dos tablas.
     * - `articulos_en_papelera`: artículos en la papelera con stock acá (D7: se mueven sin libro).
     *
     * @param  int  $address_id
     * @param  int  $owner_id    Solo artículos de este dueño (ver query_filas_de_articulos_con_stock()).
     * @return array
     */
    static function conteo_de_stock($address_id, $owner_id) {

        $filas_articulo = Self::filas_de_articulos_con_stock($address_id, $owner_id);
        $filas_variante = Self::filas_de_variantes_con_stock($address_id, $owner_id);

        $articulos            = [];
        $articulos_papelera   = [];
        $variantes            = [];
        $con_filas_de_variante = [];

        $unidades           = 0.0;
        $unidades_negativas = 0.0;
        $filas_negativas    = 0;

        foreach ($filas_variante as $fila) {

            $cantidad = (float) $fila->amount;

            $articulos[(int) $fila->article_id] = true;
            $con_filas_de_variante[(int) $fila->article_id] = true;
            $variantes[(int) $fila->article_variant_id] = true;

            if (!is_null($fila->deleted_at)) {
                $articulos_papelera[(int) $fila->article_id] = true;
            }

            $unidades += $cantidad;

            if ($cantidad < 0) {
                $filas_negativas++;
                $unidades_negativas += $cantidad;
            }
        }

        foreach ($filas_articulo as $fila) {

            $cantidad = (float) $fila->amount;

            $articulos[(int) $fila->article_id] = true;

            if (!is_null($fila->deleted_at)) {
                $articulos_papelera[(int) $fila->article_id] = true;
            }

            if ($cantidad < 0) {
                $filas_negativas++;
            }

            // La fila del artículo de uno con variantes acá ya está contada por sus variantes.
            if (isset($con_filas_de_variante[(int) $fila->article_id])) {
                continue;
            }

            $unidades += $cantidad;

            if ($cantidad < 0) {
                $unidades_negativas += $cantidad;
            }
        }

        return [
            'articulos'             => count($articulos),
            'variantes'             => count($variantes),
            'filas'                 => count($filas_articulo) + count($filas_variante),
            'unidades'              => round($unidades, 2),
            'filas_negativas'       => $filas_negativas,
            'unidades_negativas'    => round($unidades_negativas, 2),
            'articulos_en_papelera' => count($articulos_papelera),
        ];
    }

    /**
     * Los usuarios del comercio (el dueño y sus empleados) que tienen elegida esta sucursal.
     * Nunca un usuario de otro comercio (D8), aunque por un dato viejo tuviera el mismo id.
     *
     * @param  int  $address_id
     * @param  int  $owner_id
     * @return array  [{id, name, es_dueno}]
     */
    static function usuarios_asignados($address_id, $owner_id) {

        $usuarios = [];

        $filas = Self::query_de_usuarios($address_id, $owner_id)
                        ->whereNull('deleted_at')
                        ->orderBy('id')
                        ->get(['id', 'name']);

        foreach ($filas as $fila) {
            $usuarios[] = [
                'id'       => (int) $fila->id,
                'name'     => $fila->name,
                'es_dueno' => (int) $fila->id === (int) $owner_id,
            ];
        }

        return $usuarios;
    }

    /**
     * Query de los usuarios del comercio con esta sucursal elegida (dueño + empleados).
     *
     * @param  int  $address_id
     * @param  int  $owner_id
     * @return \Illuminate\Database\Query\Builder
     */
    static function query_de_usuarios($address_id, $owner_id) {

        return DB::table('users')
                    ->where('address_id', $address_id)
                    ->where(function ($q) use ($owner_id) {
                        $q->where('id', $owner_id)
                          ->orWhere('owner_id', $owner_id);
                    });
    }

    /**
     * Las marcas prendidas de la sucursal (D9).
     *
     * @param  \App\Models\Address  $address
     * @return array  Nombres de columna.
     */
    static function marcas_de($address) {

        $marcas = [];

        foreach (Self::MARCAS as $marca) {

            // La madre tiene guarda de esquema propia (deploy que sube el código antes de migrar).
            if ($marca === Address::COLUMNA_MADRE && !Address::columna_madre_existe()) {
                continue;
            }

            if ((int) $address->{$marca} === 1) {
                $marcas[] = $marca;
            }
        }

        return $marcas;
    }

    /**
     * Lo que impide borrar sin importar qué elija el usuario (D11).
     *
     * Hoy uno solo: traslados de stock (`deposit_movements`) que usan esta sucursal de origen o de
     * destino y que todavía NO movieron el stock (`stock_moved_at` y `recibido_at` nulos: las dos
     * marcas, ver DepositMovementHelper::stock_movido()). Reasignarlos en silencio movería stock entre
     * depósitos que el usuario no eligió; dejarlos, el "Mover stock" de mañana abriría una fila
     * fantasma. Se le pide que los resuelva antes.
     *
     * @param  int  $address_id
     * @param  int  $owner_id
     * @return array  [{codigo, cantidad, mensaje}]
     */
    static function bloqueos($address_id, $owner_id) {

        $bloqueos = [];

        $query = DB::table('deposit_movements')
                        ->where('user_id', $owner_id)
                        ->where(function ($q) use ($address_id) {
                            $q->where('from_address_id', $address_id)
                              ->orWhere('to_address_id', $address_id);
                        })
                        ->whereNull('recibido_at');

        // Sin la columna (deploy a medio migrar) la única marca de "movido" es recibido_at: ver
        // hay_columna_stock_moved_at().
        if (Self::hay_columna_stock_moved_at()) {
            $query->whereNull('stock_moved_at');
        }

        $traslados = $query->count();

        if ($traslados > 0) {
            $bloqueos[] = [
                'codigo'   => 'traslados_pendientes',
                'cantidad' => $traslados,
                'mensaje'  => $traslados == 1
                    ? 'Tiene 1 traslado de stock sin mover que usa esta sucursal. Movelo o eliminalo antes.'
                    : 'Tiene '.$traslados.' traslados de stock sin mover que usan esta sucursal. Movelos o eliminalos antes.',
            ];
        }

        return $bloqueos;
    }

    /**
     * Memo de la guarda de esquema de `deposit_movements.stock_moved_at`. Solo se memoiza el SÍ.
     *
     * @var bool|null
     */
    protected static $columna_stock_moved_at = null;

    /**
     * ¿`deposit_movements` ya tiene `stock_moved_at` (misión movimientos-deposito-auditoria)?
     *
     * 🔴 Guarda de esquema (segunda ronda de revisión, 5/10/2026): un deploy de empresa sube los
     * archivos ANTES de migrar. Sin la guarda, en esa ventana el resumen y la eliminación de
     * CUALQUIER sucursal daban 500 (`Unknown column`), tengan o no traslados. Mismo patrón que
     * `Address::columna_madre_existe()`: se memoiza solo el SÍ, así un `queue:work` booteado dentro de
     * la ventana se entera en cuanto la columna aparece.
     *
     * @return bool
     */
    static function hay_columna_stock_moved_at() {

        if (Self::$columna_stock_moved_at !== true) {
            Self::$columna_stock_moved_at = Schema::hasColumn('deposit_movements', 'stock_moved_at');
        }

        return Self::$columna_stock_moved_at;
    }

    /**
     * Borra el memo de la guarda de esquema (los tests que esconden la columna).
     *
     * @return void
     */
    static function olvidar_esquema() {
        Self::$columna_stock_moved_at = null;
    }

    // ------------------------------------------------------------------------------------------
    // La validación (todo ANTES de escribir nada)
    // ------------------------------------------------------------------------------------------

    /**
     * Por qué NO se puede borrar con esta decisión, o null si se puede.
     *
     * Orden: bloqueos (no dependen de la decisión) → valores inválidos → faltantes. Los faltantes se
     * juntan en UN mensaje, para que la SPA vieja (que manda el DELETE sin nada) le diga al usuario
     * todo lo que hay que elegir de una sola vez.
     *
     * 🔴 En la ÚLTIMA sucursal ni el stock ni los empleados piden decisión: no hay a dónde pasarlos,
     * así que el único resultado posible es D6 (los artículos conservan su stock total) y empleados
     * sin sucursal. Exigir una elección imposible dejaba a la SPA vieja sin forma de borrarla.
     *
     * @param  \App\Models\Address  $address
     * @param  int                  $owner_id
     * @param  array                $decision  Normalizada.
     * @param  array|null           $resumen   El de `resumen()`, si ya se calculó.
     * @return array|null  ['status' => 422, 'body' => {message, requiere_decision, bloqueos, faltan}]
     */
    static function validar($address, $owner_id, $decision, $resumen = null) {

        // Un domicilio de comprador no pasa por este flujo (D15).
        if (!is_null($address->buyer_id)) {
            return null;
        }

        if (is_null($resumen)) {
            $resumen = Self::resumen($address, $owner_id);
        }

        $es_la_ultima = $resumen['es_la_ultima'];

        if (count($resumen['bloqueos']) > 0) {
            return Self::error($resumen['bloqueos'][0]['mensaje'], false, $resumen['bloqueos']);
        }

        /*
         * Los destinos elegidos, si vinieron, tienen que ser OTRAS sucursales vivas del comercio. Se
         * rechaza y no se ignora: un destino inválido ignorado terminaría descartando el stock que
         * el usuario quiso pasar.
         */
        $destinos = [
            'stock_destino_id'    => 'La sucursal elegida para pasar el stock no existe o es la misma que se está eliminando.',
            'usuarios_destino_id' => 'La sucursal elegida para los empleados no existe o es la misma que se está eliminando.',
            'reemplazo_id'        => 'La sucursal de reemplazo no existe o es la misma que se está eliminando.',
        ];

        foreach ($destinos as $clave => $mensaje) {

            $destino = $decision[$clave];

            if (is_null($destino)) {
                continue;
            }

            if ((int) $destino === (int) $address->id || !SucursalVigenteHelper::existe($destino, $owner_id)) {
                return Self::error($mensaje, true);
            }
        }

        if (!is_null($decision['stock_accion'])
            && !in_array($decision['stock_accion'], [Self::STOCK_TRANSFERIR, Self::STOCK_DESCARTAR])) {
            return Self::error('Elegí qué hacer con el stock: pasarlo a otra sucursal o descartarlo.', true);
        }

        if ($decision['stock_accion'] === Self::STOCK_TRANSFERIR && is_null($decision['stock_destino_id'])) {
            return Self::error('Elegí a qué sucursal pasar el stock.', true);
        }

        if (!is_null($decision['usuarios_accion'])
            && !in_array($decision['usuarios_accion'], [Self::USUARIOS_REASIGNAR, Self::USUARIOS_SIN_SUCURSAL])) {
            return Self::error('Elegí qué hacer con los empleados: pasarlos a otra sucursal o dejarlos sin sucursal.', true);
        }

        if ($decision['usuarios_accion'] === Self::USUARIOS_REASIGNAR && is_null($decision['usuarios_destino_id'])) {
            return Self::error('Elegí a qué sucursal pasar los empleados.', true);
        }

        /*
         * Transferir necesita el concepto "Mov entre depositos" (es el que hace que el motor invierta
         * el signo del origen: sin él, el stock se SUMARÍA en las dos sucursales). Si una base no lo
         * tiene, se frena acá con un mensaje claro en vez de mover mal.
         */
        if ($decision['stock_accion'] === Self::STOCK_TRANSFERIR
            && $resumen['stock']['filas'] > 0
            && !ConceptoStockMovement::where('name', Self::CONCEPTO_TRANSFERIR)->exists()) {
            return Self::error('Falta el concepto de stock "'.Self::CONCEPTO_TRANSFERIR.'" en esta instalación: no se puede pasar el stock a otra sucursal. Avisale a soporte.', false);
        }

        // Lo que falta decidir (D3).
        $faltan = [];
        $partes = [];

        if (!$es_la_ultima && $resumen['stock']['filas'] > 0 && is_null($decision['stock_accion'])) {
            $faltan[] = 'stock';
            $partes[] = 'su stock ('.$resumen['stock']['articulos'].' '.($resumen['stock']['articulos'] == 1 ? 'artículo' : 'artículos').')';
        }

        if (!$es_la_ultima && count($resumen['usuarios']) > 0 && is_null($decision['usuarios_accion'])) {
            $nombres = [];
            foreach ($resumen['usuarios'] as $usuario) {
                $nombres[] = $usuario['name'];
            }
            $faltan[] = 'usuarios';
            $partes[] = 'los usuarios que la tienen elegida ('.implode(', ', $nombres).')';
        }

        if ($resumen['requiere_reemplazo'] && is_null(Self::reemplazo_de_la_decision($decision))) {
            $faltan[] = 'reemplazo';
            $partes[] = 'la sucursal que pasa a ser '.Self::texto_de_marcas($resumen['marcas']);
        }

        if (count($faltan) > 0) {

            $mensaje = 'Para eliminar la sucursal "'.$address->street.'" hay que elegir qué hacer con '.Self::enumerar($partes).'. '
                     .'Hacelo desde ABM > Sucursales (si no te aparecen las opciones, recargá la página).';

            return Self::error($mensaje, true, [], $faltan);
        }

        return null;
    }

    /**
     * El cuerpo de un 422 de este flujo.
     *
     * @param  string  $mensaje
     * @param  bool    $requiere_decision  true = eligiendo otra cosa se puede; false = hay un
     *                                     bloqueo que no se resuelve eligiendo.
     * @param  array   $bloqueos
     * @param  array   $faltan             Qué decisiones faltan (stock, usuarios, reemplazo).
     * @return array
     */
    static function error($mensaje, $requiere_decision, $bloqueos = [], $faltan = []) {
        return [
            'status' => 422,
            'body'   => [
                'message'           => $mensaje,
                'requiere_decision' => (bool) $requiere_decision,
                'bloqueos'          => $bloqueos,
                'faltan'            => $faltan,
            ],
        ];
    }

    /**
     * "por defecto, madre y de origen" a partir de las columnas.
     *
     * @param  array  $marcas
     * @return string
     */
    static function texto_de_marcas($marcas) {

        $textos = [
            'default_address'    => 'la sucursal por defecto',
            'es_deposito_madre'  => 'el depósito madre',
            'es_deposito_origen' => 'el depósito de origen',
        ];

        $partes = [];

        foreach ($marcas as $marca) {
            if (isset($textos[$marca])) {
                $partes[] = $textos[$marca];
            }
        }

        return Self::enumerar($partes);
    }

    /**
     * "a", "a y b", "a, b y c".
     *
     * @param  array  $partes
     * @return string
     */
    static function enumerar($partes) {

        if (count($partes) <= 1) {
            return implode('', $partes);
        }

        $ultima = array_pop($partes);

        return implode(', ', $partes).' y '.$ultima;
    }

    // ------------------------------------------------------------------------------------------
    // El candado (D13)
    // ------------------------------------------------------------------------------------------

    /*
     * 🔴 POR QUÉ UN CANDADO DE MYSQL Y NO DE CACHE (segunda ronda de revisión, 5/10/2026).
     *
     * Antes era `Cache::add()` con 2 horas de vida. Dos problemas: con el driver `array` (cada
     * proceso tiene su propia memoria) no excluía NADA entre dos requests; y con `file`, un proceso que
     * moría por `max_execution_time` no llegaba al `finally`, y la sucursal quedaba "ya se está
     * eliminando" dos horas. `GET_LOCK` de MySQL se libera SOLO cuando la conexión se cierra (el
     * proceso termina, bien o mal), funciona con cualquier driver de cache, y el motor ya depende de
     * MySQL (lo usa también la creación de ventas, `crear_venta_{dueño}`).
     *
     * El nombre lleva dueño e id (máximo 64 caracteres en MySQL: sobra). `GET_LOCK` con espera 0: el
     * segundo "Eliminar" no espera, recibe un 422 al instante.
     *
     * ⚠️ El candado es por CONEXIÓN y re-entrante: la misma conexión lo vuelve a tomar sin esperar.
     * Por eso "ya en proceso" pregunta si lo tiene OTRA conexión (`IS_USED_LOCK` ≠ `CONNECTION_ID`), y
     * los tests lo prueban tomándolo desde una segunda conexión PDO.
     *
     * Mientras el job está ENCOLADO nadie tiene el candado (lo toma el job al arrancar): en esa
     * ventana "ya en proceso" lo dice el registro visible activo del proceso
     * (`background_processes`, tipo `eliminacion_sucursal`, referenciando la sucursal).
     */

    /**
     * @param  int  $owner_id
     * @param  int  $address_id
     * @return string
     */
    static function nombre_del_candado($owner_id, $address_id) {
        return 'eliminar_sucursal_'.(int) $owner_id.'_'.(int) $address_id;
    }

    /**
     * Toma el candado sin esperar.
     *
     * @param  int  $owner_id
     * @param  int  $address_id
     * @return bool  true si quedó tomado por ESTA conexión.
     */
    static function tomar_candado($owner_id, $address_id) {

        $fila = DB::selectOne('SELECT GET_LOCK(?, 0) AS tomado', [Self::nombre_del_candado($owner_id, $address_id)]);

        return !is_null($fila) && (int) $fila->tomado === 1;
    }

    /**
     * Libera el candado (si esta conexión no lo tiene, MySQL no hace nada).
     *
     * @param  int  $owner_id
     * @param  int  $address_id
     * @return void
     */
    static function liberar_candado($owner_id, $address_id) {
        DB::select('SELECT RELEASE_LOCK(?) AS liberado', [Self::nombre_del_candado($owner_id, $address_id)]);
    }

    /**
     * ¿Otra conexión tiene tomado el candado de esta sucursal?
     *
     * @param  int  $owner_id
     * @param  int  $address_id
     * @return bool
     */
    static function candado_tomado_por_otro($owner_id, $address_id) {

        $fila = DB::selectOne('SELECT IS_USED_LOCK(?) AS conexion, CONNECTION_ID() AS mia', [Self::nombre_del_candado($owner_id, $address_id)]);

        return !is_null($fila) && !is_null($fila->conexion) && (int) $fila->conexion !== (int) $fila->mia;
    }

    /**
     * ¿Hay una eliminación en segundo plano de esta sucursal todavía abierta (encolada o corriendo)?
     *
     * @param  \App\Models\Address  $address
     * @return bool
     */
    static function proceso_activo($address) {

        $proceso = BackgroundProcessHelper::por_referencia($address);

        return !is_null($proceso) && $proceso->tipo === Self::TIPO_DE_PROCESO;
    }

    /**
     * ¿Ya se está eliminando? Candado tomado por otra conexión, o un job de eliminación abierto.
     *
     * @param  int                  $owner_id
     * @param  \App\Models\Address  $address
     * @return bool
     */
    static function esta_en_proceso($owner_id, $address) {
        return Self::candado_tomado_por_otro($owner_id, $address->id) || Self::proceso_activo($address);
    }

    // ------------------------------------------------------------------------------------------
    // La orquestación (lo que llama AddressController::destroy)
    // ------------------------------------------------------------------------------------------

    /**
     * Elimina la sucursal con la decisión dada: valida, toma el candado, y lo hace en línea o lo
     * encola (D14).
     *
     * @param  \App\Models\Address  $address
     * @param  int                  $owner_id
     * @param  int|null             $auth_user_id  Usuario que firma los movimientos.
     * @param  array                $decision      Normalizada.
     * @return array  ['status' => 200|202|422|500, 'body' => array]
     */
    static function eliminar($address, $owner_id, $auth_user_id, $decision) {

        $decision = Self::normalizar_decision($decision);

        /*
         * D15: un domicilio de comprador se borra como siempre, sin stock ni preguntas. Lo único que
         * hacía el borrado viejo además de borrar la fila era el `detach()` del pivot de artículos
         * (un domicilio de envío no debería tener, pero si tuviera, no se deja huérfano).
         */
        if (!is_null($address->buyer_id)) {

            /*
             * Segunda ronda de revisión (F9): un domicilio de comprador SÍ puede tener filas, y con
             * stock: los pedidos de la tienda con envío le abrían una fila negativa (ver el docblock de
             * SucursalVigenteHelper). Se borran las de las DOS tablas y a esos artículos se les
             * recalcula el stock global desde lo que queda, para que no siga contando una fila que ya
             * no existe. Sin renglón en el libro: no hay a dónde mover ese stock y no es una sucursal.
             */
            $afectados = Self::articulos_con_filas($address->id);

            DB::transaction(function () use ($address) {
                DB::table('address_article')->where('address_id', $address->id)->delete();
                DB::table('address_article_variant')->where('address_id', $address->id)->delete();
                $address->delete();
            });

            ImageController::deleteModelImages($address);
            SucursalVigenteHelper::olvidar($address->id);

            Self::recalcular_articulos($afectados);

            return [
                'status' => 200,
                'body'   => ['eliminada' => true, 'resumen' => ['es_domicilio_de_comprador' => true]],
            ];
        }

        // D13: un segundo "Eliminar" mientras otro corre (o está encolado) no toca nada.
        if (Self::esta_en_proceso($owner_id, $address)) {
            return Self::error('La sucursal "'.$address->street.'" ya se está eliminando. Esperá a que termine.', false);
        }

        $resumen = Self::resumen($address, $owner_id);

        $error = Self::validar($address, $owner_id, $decision, $resumen);

        if (!is_null($error)) {
            return $error;
        }

        /*
         * D14: muchas filas con stock → segundo plano. El registro visible nace ACÁ, en `pendiente`
         * (mismo criterio que DeleteController): en el shared hosting el worker pasa una vez por
         * minuto y sin esto el usuario no vería nada hasta entonces. Referencia a la sucursal: es lo
         * que hace que un segundo "Eliminar" vea que ya está encolada (esta_en_proceso()). El
         * candado de MySQL NO se toma acá: es por conexión y moriría con este request; lo toma el job
         * al arrancar.
         */
        if ($resumen['en_segundo_plano']) {

            $proceso = BackgroundProcessHelper::iniciar(
                $owner_id,
                Self::TIPO_DE_PROCESO,
                'Eliminación de la sucursal '.$address->street,
                [
                    'auth_user_id' => $auth_user_id,
                    'total'        => $resumen['stock']['articulos'],
                    'unidad'       => 'artículos',
                    'detalle'      => $resumen['stock']['articulos'].' artículos con stock',
                    'status'       => 'pendiente',
                    'etapa'        => 'En espera del procesador',
                    'referencia'   => $address,
                ]
            );

            /*
             * Si la cola no acepta el trabajo (tabla `jobs` caída, Redis sin conexión...), el registro que
             * acaba de nacer en `pendiente` quedaría como "ya en proceso": todo nuevo "Eliminar" daría 422
             * hasta que `cerrar_colgados()` lo limpie (3 horas, y solo cuando alguien abre la SPA). Se
             * cierra en fallo ahora y se responde con el mismo mensaje fijo de los demás cortes.
             */
            try {

                EliminarSucursalJob::dispatch(
                    $address->id,
                    $owner_id,
                    $auth_user_id,
                    $decision,
                    is_null($proceso) ? null : $proceso->id
                );

            } catch (\Throwable $e) {

                Log::error('EliminarSucursalHelper: no se pudo encolar la eliminación de la sucursal '.$address->id.': '.$e->getMessage(), [
                    'archivo' => $e->getFile().':'.$e->getLine(),
                ]);

                BackgroundProcessHelper::fallar($proceso, Self::MENSAJE_SI_SE_CORTA);

                return [
                    'status' => 500,
                    'body'   => ['message' => Self::MENSAJE_SI_SE_CORTA],
                ];
            }

            return [
                'status' => 202,
                'body'   => [
                    'queued'                => true,
                    'message'               => 'La sucursal tiene muchos artículos con stock: la eliminación se está procesando en segundo plano y desaparece de la lista cuando termina.',
                    'background_process_id' => is_null($proceso) ? null : $proceso->id,
                ],
            ];
        }

        /*
         * El camino en línea termina lo que empezó aunque el usuario cierre la pestaña o se corte la
         * conexión (`ignore_user_abort`), y sin el límite de tiempo de PHP (`set_time_limit(0)`): un
         * corte por `max_execution_time` a mitad de la pasada no rompe nada (cada artículo es su
         * transacción y volver a eliminar continúa), pero dejaría al usuario con una sucursal a medio
         * vaciar sin motivo. Hasta FILAS_EN_LINEA filas, el trabajo entra cómodo.
         */
        set_time_limit(0);
        ignore_user_abort(true);

        if (!Self::tomar_candado($owner_id, $address->id)) {
            return Self::error('La sucursal "'.$address->street.'" ya se está eliminando. Esperá a que termine.', false);
        }

        try {

            $resultado = Self::ejecutar($address->id, $owner_id, $auth_user_id, $decision);

        } catch (\Throwable $e) {

            Log::error('EliminarSucursalHelper: falló la eliminación de la sucursal '.$address->id.': '.$e->getMessage(), [
                'archivo' => $e->getFile().':'.$e->getLine(),
            ]);

            return [
                'status' => 500,
                'body'   => [
                    // Mensaje FIJO: el de la excepción (SQL, rutas, nombres de tabla) va solo al log de arriba.
                    'message' => Self::MENSAJE_SI_SE_CORTA,
                ],
            ];

        } finally {
            Self::liberar_candado($owner_id, $address->id);
        }

        if (!$resultado['ok']) {
            return Self::error($resultado['mensaje'], true);
        }

        /*
         * Un resultado "cortado" (el gancho de tests de ejecutar()) NO es una eliminación: la sucursal
         * sigue existiendo. Nunca debería llegar acá (eliminar() no pasa $maximo_articulos), pero si
         * llegara, responder 200 "eliminada" le haría sacar a la SPA una sucursal que sigue viva.
         */
        if (!empty($resultado['resumen']['cortada'])) {
            return [
                'status' => 500,
                'body'   => ['message' => Self::MENSAJE_SI_SE_CORTA],
            ];
        }

        return [
            'status' => 200,
            'body'   => ['eliminada' => true, 'resumen' => $resultado['resumen']],
        ];
    }

    /**
     * El trabajo: vacía el stock de la sucursal (pasadas) y hace la fase final. Lo usan el camino en
     * línea y el job. NO toma ni libera el candado (eso es del llamador) y NO valida (el llamador ya
     * validó; el job vuelve a validar antes de llamar).
     *
     * Por qué hay "pasadas" (D14): mientras se vacía el stock, una venta desde un navegador que
     * todavía tiene la sucursal elegida puede volver a dejarle stock. La fase final mira de nuevo,
     * DENTRO de su transacción, y si encuentra stock no borra nada: se hace otra pasada.
     *
     * @param  int       $address_id
     * @param  int       $owner_id
     * @param  int|null  $auth_user_id
     * @param  array     $decision          Normalizada.
     * @param  \App\Models\BackgroundProcess|int|null  $proceso  Registro visible (solo el job).
     * @param  int|null  $maximo_articulos  @internal SOLO PARA TESTS. Corta la pasada después de N
     *                                      artículos y NO hace la fase final ("se cortó a la
     *                                      mitad"): devuelve ok con `resumen.cortada` y la sucursal
     *                                      SIGUE existiendo. Ningún llamador de producción lo pasa;
     *                                      si alguno lo hiciera, eliminar() lo trata como un corte
     *                                      (500), nunca como "eliminada".
     * @return array  ['ok' => bool, 'mensaje' => string|null, 'resumen' => array|null]
     */
    static function ejecutar($address_id, $owner_id, $auth_user_id, $decision, $proceso = null, $maximo_articulos = null) {

        $decision = Self::normalizar_decision($decision);

        $address = Address::find($address_id);

        // Ya no existe: otra corrida la terminó. No es un error (el pedido quedó cumplido).
        if (is_null($address)) {
            return [
                'ok'      => true,
                'mensaje' => null,
                'resumen' => ['ya_estaba_eliminada' => true],
            ];
        }

        $owner = User::find($owner_id);

        $es_la_ultima = count(Self::otras_sucursales($address, $owner_id)) === 0;

        $accion = $decision['stock_accion'];

        $destino = null;

        if (!$es_la_ultima && $accion === Self::STOCK_TRANSFERIR) {

            $destino = is_null($decision['stock_destino_id']) ? null : Address::find($decision['stock_destino_id']);

            // El destino desapareció entre la validación y ahora (el job corre más tarde): no se
            // descarta el stock que el usuario quiso pasar; se corta y se le pide que elija otro.
            if (is_null($destino)) {
                return [
                    'ok'      => false,
                    'mensaje' => 'La sucursal elegida para pasar el stock ya no existe. Volvé a intentarlo eligiendo otra.',
                    'resumen' => null,
                ];
            }
        }

        $totales = [
            'articulos'             => 0,
            'movimientos'           => 0,
            'articulos_en_papelera' => 0,
        ];

        for ($pasada = 1; $pasada <= Self::MAXIMO_DE_PASADAS; $pasada++) {

            if (!$es_la_ultima) {

                $hay_stock = count(Self::articulos_con_stock($address->id, $owner_id)) > 0;

                /*
                 * Stock que apareció después de validar sin que haya decisión para él (la sucursal no
                 * tenía stock cuando el usuario apretó Eliminar y una venta le dejó). No se descarta
                 * en silencio: se corta y se le pide que elija.
                 */
                if ($hay_stock && !in_array($accion, [Self::STOCK_TRANSFERIR, Self::STOCK_DESCARTAR])) {
                    return [
                        'ok'      => false,
                        'mensaje' => 'La sucursal "'.$address->street.'" recibió stock mientras se eliminaba. Volvé a intentarlo eligiendo qué hacer con el stock.',
                        'resumen' => null,
                    ];
                }

                if ($hay_stock) {

                    if ($accion === Self::STOCK_DESCARTAR) {
                        Self::asegurar_concepto_de_descarte();
                    }

                    $parcial = Self::pasada_de_stock($address, $owner, $auth_user_id, $accion, $destino, $proceso, $maximo_articulos, $totales['articulos']);

                    $totales['articulos']             += $parcial['articulos'];
                    $totales['movimientos']           += $parcial['movimientos'];
                    $totales['articulos_en_papelera'] += $parcial['articulos_en_papelera'];
                }

                // Corte a propósito (tests): la sucursal queda a medio vaciar, como si se hubiera caído.
                if (!is_null($maximo_articulos)) {
                    return [
                        'ok'      => true,
                        'mensaje' => null,
                        'resumen' => array_merge($totales, ['cortada' => true]),
                    ];
                }
            }

            $final = Self::fase_final($address, $owner_id, $decision, $es_la_ultima);

            // La sucursal que hereda (o la de los empleados) se eliminó mientras corría: no se escribió nada.
            if (!is_null($final) && isset($final['error'])) {
                return [
                    'ok'      => false,
                    'mensaje' => $final['error'],
                    'resumen' => null,
                ];
            }

            if (!is_null($final)) {

                Log::info('EliminarSucursalHelper: se eliminó la sucursal '.$address->id.' ('.$address->street.') del comercio '.$owner_id.' en '.$pasada.' pasada(s).');

                return [
                    'ok'      => true,
                    'mensaje' => null,
                    'resumen' => array_merge($totales, $final, [
                        'stock_accion'     => $es_la_ultima ? null : $accion,
                        'stock_destino_id' => is_null($destino) ? null : (int) $destino->id,
                        'es_la_ultima'     => $es_la_ultima,
                        'pasadas'          => $pasada,
                    ]),
                ];
            }

            Log::info('EliminarSucursalHelper: la sucursal '.$address->id.' volvió a tener stock antes de la fase final (pasada '.$pasada.'); se repite.');
        }

        throw new \RuntimeException('La sucursal "'.$address->street.'" sigue recibiendo stock después de '.Self::MAXIMO_DE_PASADAS.' pasadas.');
    }

    /**
     * El concepto "Eliminacion de sucursal" puede no existir en la base de un cliente viejo (lo
     * agregó la tanda correctivos 2408 con su seeder standalone). Sin él, `SetConcepto` deja el
     * movimiento SIN concepto y el motor lo trata como "no invertir signo / saltear carritos"; el
     * resultado de stock sería el mismo, pero el libro quedaría con un renglón sin nombre. Se crea con
     * las mismas columnas que `ConceptoStockMovementEliminacionDeSucursalSeeder`.
     *
     * @return void
     */
    static function asegurar_concepto_de_descarte() {

        if (ConceptoStockMovement::where('name', Self::CONCEPTO_DESCARTAR)->exists()) {
            return;
        }

        $ahora = Carbon::now();

        DB::table('concepto_stock_movements')->insert([
            'name'       => Self::CONCEPTO_DESCARTAR,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        Log::info('EliminarSucursalHelper: se creó el concepto de stock "'.Self::CONCEPTO_DESCARTAR.'", que faltaba en esta base.');
    }

    // ------------------------------------------------------------------------------------------
    // El stock: artículo por artículo
    // ------------------------------------------------------------------------------------------

    /**
     * Los artículos con stock ≠ 0 en la sucursal, por su fila o por la de alguna variante (incluye
     * la papelera).
     *
     * @param  int  $address_id
     * @param  int  $owner_id    Solo artículos de este dueño (ver query_filas_de_articulos_con_stock()).
     * @return array  ids de artículo, ordenados.
     */
    static function articulos_con_stock($address_id, $owner_id) {

        // DISTINCT en SQL sobre las mismas consultas de siempre: se pregunta en cada pasada, y traer
        // todas las filas a PHP solo para quedarse con los ids era cargar miles de filas dos veces.
        $de_articulos = Self::query_filas_de_articulos_con_stock($address_id, $owner_id)
                            ->distinct()
                            ->pluck('aa.article_id')
                            ->all();

        $de_variantes = Self::query_filas_de_variantes_con_stock($address_id, $owner_id)
                            ->distinct()
                            ->pluck('av.article_id')
                            ->all();

        $ids = array_values(array_unique(array_map('intval', array_merge($de_articulos, $de_variantes))));

        sort($ids);

        return $ids;
    }

    /**
     * Una pasada: procesa cada artículo con stock en la sucursal, cada uno en su transacción.
     *
     * @param  \App\Models\Address       $address
     * @param  \App\Models\User|null     $owner
     * @param  int|null                  $auth_user_id
     * @param  string                    $accion            transferir | descartar
     * @param  \App\Models\Address|null  $destino
     * @param  mixed                     $proceso           Registro visible (job) o null.
     * @param  int|null                  $maximo_articulos  Ver ejecutar().
     * @param  int                       $ya_procesados     Artículos de pasadas anteriores (para el avance).
     * @return array  ['articulos' => n, 'movimientos' => n, 'articulos_en_papelera' => n]
     */
    static function pasada_de_stock($address, $owner, $auth_user_id, $accion, $destino, $proceso = null, $maximo_articulos = null, $ya_procesados = 0) {

        $articulos   = 0;
        $movimientos = 0;
        $papelera    = 0;

        foreach (Self::articulos_con_stock($address->id, $address->user_id) as $article_id) {

            if (!is_null($maximo_articulos) && $articulos >= $maximo_articulos) {
                break;
            }

            $resultado = Self::procesar_articulo($address, $article_id, $owner, $auth_user_id, $accion, $destino);

            $articulos++;
            $movimientos += $resultado['movimientos'];

            if ($resultado['en_papelera']) {
                $papelera++;
            }

            if (!is_null($proceso) && $articulos % BackgroundProcessHelper::CADA_CUANTAS_UNIDADES === 0) {
                BackgroundProcessHelper::avanzar($proceso, $ya_procesados + $articulos, ['etapa' => 'Moviendo el stock']);
            }
        }

        return [
            'articulos'             => $articulos,
            'movimientos'           => $movimientos,
            'articulos_en_papelera' => $papelera,
        ];
    }

    /**
     * Deja en 0 el stock de UN artículo (y sus variantes) en la sucursal, en su propia transacción.
     *
     * Idempotente: al terminar, las filas del artículo en la sucursal quedan en 0, así que una
     * segunda corrida (porque la primera se cortó) ya no lo encuentra en `articulos_con_stock()`.
     *
     * Orden, y por qué:
     *  1. Candado de filas (`FOR UPDATE`) del artículo en la sucursal y en el destino: una venta
     *     simultánea del mismo artículo espera a que esto termine en vez de sumar sobre un valor viejo.
     *  2. Filas repetidas → una sola (ver `consolidar_filas()`).
     *  3. Primero las VARIANTES: en un artículo cuyas variantes reparten por depósitos, la fila del
     *     artículo se reconstruye desde las variantes. Mover la del artículo primero era exactamente el
     *     defecto viejo (el recálculo la deshacía).
     *  4. Después, lo que haya quedado en la fila del ARTÍCULO (artículos sin variantes, o con
     *     variantes que no reparten por depósitos).
     *
     * Si MySQL elige a esta transacción como víctima de un deadlock (1213), `DB::transaction` la
     * reintenta sola hasta 3 veces (segundo argumento) en vez de cortar la eliminación con un 500. Es
     * seguro porque el cuerpo es idempotente: un intento que no llegó a confirmar no dejó nada, y uno
     * que sí confirmó dejó las filas en 0, que `articulos_con_stock()` ya no vuelve a pedir.
     *
     * @param  \App\Models\Address       $address
     * @param  int                       $article_id
     * @param  \App\Models\User|null     $owner
     * @param  int|null                  $auth_user_id
     * @param  string                    $accion
     * @param  \App\Models\Address|null  $destino
     * @return array  ['movimientos' => n, 'en_papelera' => bool]
     */
    static function procesar_articulo($address, $article_id, $owner, $auth_user_id, $accion, $destino) {

        return DB::transaction(function () use ($address, $article_id, $owner, $auth_user_id, $accion, $destino) {

            $destino_id = is_null($destino) ? null : (int) $destino->id;

            $sucursales = array_values(array_filter([(int) $address->id, $destino_id]));

            /*
             * 🔴 ORDEN DE LOS CANDADOS: primero las filas de las VARIANTES y DESPUÉS las del artículo
             * (tercera ronda de revisión, 5/10/2026). Es el mismo orden en el que escribe la venta de una
             * variante: `CheckVariants` hace el UPDATE de `address_article_variant` y recién después
             * `setArticleStockFromAddresses` reconstruye `address_article` con un `sync([])`. Con el orden
             * inverso (artículo primero, variantes después) una venta de esa variante y esta pasada se
             * esperan mutuamente: deadlock (1213), y la víctima puede ser el cajero. Con el mismo orden, la
             * que llega segunda espera a que la primera termine. No tocar el orden sin mirar CheckVariants.
             */
            $variantes = DB::table('article_variants')->where('article_id', $article_id)->pluck('id')->all();

            if (count($variantes) > 0) {

                DB::table('address_article_variant')
                    ->whereIn('article_variant_id', $variantes)
                    ->whereIn('address_id', $sucursales)
                    ->lockForUpdate()
                    ->get(['id']);
            }

            DB::table('address_article')
                ->where('article_id', $article_id)
                ->whereIn('address_id', $sucursales)
                ->lockForUpdate()
                ->get(['id']);

            Self::consolidar_filas('address_article', 'article_id', $article_id, $address->id);

            if (!is_null($destino_id)) {
                Self::consolidar_filas('address_article', 'article_id', $article_id, $destino_id);
            }

            // withTrashed: el artículo en la papelera también tiene stock que resolver (D7).
            $article = Article::withTrashed()->find($article_id);

            if (is_null($article)) {
                return ['movimientos' => 0, 'en_papelera' => false];
            }

            $en_papelera = !is_null($article->deleted_at);

            $movimientos = 0;

            // 3. Variantes (sus filas ya quedaron bloqueadas arriba, antes que las del artículo).
            $filas_variante = collect();

            if (count($variantes) > 0) {

                foreach ($variantes as $variant_id) {

                    Self::consolidar_filas('address_article_variant', 'article_variant_id', $variant_id, $address->id);

                    if (!is_null($destino_id)) {
                        Self::consolidar_filas('address_article_variant', 'article_variant_id', $variant_id, $destino_id);
                    }
                }

                $filas_variante = DB::table('address_article_variant')
                                    ->whereIn('article_variant_id', $variantes)
                                    ->where('address_id', $address->id)
                                    ->whereNotNull('amount')
                                    ->where('amount', '!=', 0)
                                    ->orderBy('article_variant_id')
                                    ->get(['article_variant_id', 'amount']);
            }

            foreach ($filas_variante as $fila) {

                if ($en_papelera) {
                    Self::mover_por_sql('address_article_variant', 'article_variant_id', $fila->article_variant_id, $address->id, $destino_id, (float) $fila->amount, $accion);
                } else {
                    Self::crear_movimiento($article, (int) $fila->article_variant_id, (float) $fila->amount, $address, $accion, $destino, $owner, $auth_user_id);
                    $movimientos++;
                }
            }

            if ($en_papelera && count($filas_variante) > 0) {
                ArticleHelper::setArticleStockFromAddresses($article, false, is_null($owner) ? null : $owner->id);
            }

            // 4. Lo que quedó en la fila del artículo (ya consolidada: una sola fila).
            $resto = round((float) DB::table('address_article')
                                        ->where('article_id', $article_id)
                                        ->where('address_id', $address->id)
                                        ->sum('amount'), 2);

            if ($resto != 0) {

                if ($en_papelera) {
                    Self::mover_por_sql('address_article', 'article_id', $article_id, $address->id, $destino_id, $resto, $accion);
                    ArticleHelper::setArticleStockFromAddresses($article, false, is_null($owner) ? null : $owner->id);
                } else {
                    Self::crear_movimiento($article, null, $resto, $address, $accion, $destino, $owner, $auth_user_id);
                    $movimientos++;
                }
            }

            return ['movimientos' => $movimientos, 'en_papelera' => $en_papelera];
        }, 3);
    }

    /**
     * Junta en UNA las filas repetidas del mismo par (artículo o variante, sucursal).
     *
     * 🔴 `address_article` y `address_article_variant` NO tienen índice único: puede haber dos filas del
     * mismo par (datos viejos, importaciones). El motor suma con `UPDATE ... WHERE article_id AND
     * address_id`, o sea que un movimiento de −5 le resta 5 a CADA fila repetida: con dos filas de 3 y
     * 2, "vaciar" la sucursal las deja en −2 y −3. Y en el destino, una transferencia de +5 sumaría
     * 5 en cada una (aparecería stock de la nada). Juntándolas antes, la suma de la sucursal no cambia
     * (la fila que queda lleva la suma) y el movimiento la deja en 0 exacto.
     *
     * Se queda la de menor id (con su stock_min/stock_max).
     *
     * @param  string  $tabla    address_article | address_article_variant
     * @param  string  $columna  article_id | article_variant_id
     * @param  int     $id
     * @param  int     $address_id
     * @return void
     */
    static function consolidar_filas($tabla, $columna, $id, $address_id) {

        $filas = DB::table($tabla)
                    ->where($columna, $id)
                    ->where('address_id', $address_id)
                    ->orderBy('id')
                    ->get(['id', 'amount']);

        if (count($filas) <= 1) {
            return;
        }

        $suma = 0.0;
        $ids_a_borrar = [];

        foreach ($filas as $indice => $fila) {

            $suma += (float) $fila->amount;

            if ($indice > 0) {
                $ids_a_borrar[] = $fila->id;
            }
        }

        DB::table($tabla)->where('id', $filas[0]->id)->update([
            'amount'     => round($suma, 2),
            'updated_at' => Carbon::now(),
        ]);

        DB::table($tabla)->whereIn('id', $ids_a_borrar)->delete();

        Log::info('EliminarSucursalHelper: se juntaron '.count($filas).' filas repetidas de '.$tabla.' ('.$columna.' '.$id.', sucursal '.$address_id.') en una con '.round($suma, 2).'.');
    }

    /**
     * Un movimiento de stock por el camino normal (D4/D5), firmado por el usuario que eliminó la
     * sucursal y con el dueño explícito (en la cola no hay `Auth`).
     *
     * - Transferir: "Mov entre depositos", monto = +stock, origen la eliminada y destino el elegido. El
     *   motor INVIERTE el signo en el origen (`CheckFromAddress::get_amount_for_from_address`): el
     *   origen baja `stock` y el destino sube `stock`. Con stock negativo pasa lo mismo al revés: el
     *   destino baja (D4: no aparece stock de la nada y el total no cambia).
     * - Descartar: "Eliminacion de sucursal", monto = −stock, solo origen. Ese concepto NO se invierte:
     *   el origen queda en 0 y el global baja (o sube, si era negativo) en lo que tenía (D5).
     *
     * @param  \App\Models\Article       $article
     * @param  int|null                  $variant_id
     * @param  float                     $cantidad    Stock de la fila en la sucursal (con signo).
     * @param  \App\Models\Address       $address
     * @param  string                    $accion
     * @param  \App\Models\Address|null  $destino
     * @param  \App\Models\User|null     $owner
     * @param  int|null                  $auth_user_id
     * @return \App\Models\StockMovement|null
     */
    static function crear_movimiento($article, $variant_id, $cantidad, $address, $accion, $destino, $owner, $auth_user_id) {

        if ($accion === Self::STOCK_TRANSFERIR) {

            $data = [
                'model_id'                     => $article->id,
                'amount'                       => $cantidad,
                'from_address_id'              => $address->id,
                'to_address_id'                => $destino->id,
                'concepto_stock_movement_name' => Self::CONCEPTO_TRANSFERIR,
                'observations'                 => 'Eliminacion de sucursal '.$address->street.': el stock paso a '.$destino->street,
            ];

        } else {

            $data = [
                'model_id'                     => $article->id,
                'amount'                       => -$cantidad,
                'from_address_id'              => $address->id,
                'concepto_stock_movement_name' => Self::CONCEPTO_DESCARTAR,
                'observations'                 => 'Eliminacion de sucursal '.$address->street,
            ];
        }

        if (!is_null($variant_id)) {
            $data['article_variant_id'] = $variant_id;
        }

        $ct = new StockMovementController();

        return $ct->crear($data, false, $owner, $auth_user_id);
    }

    /**
     * D7: el mismo efecto que el movimiento, por SQL, para un artículo en la papelera (el motor no lo
     * ve: `crear()` hace `Article::find`). Transferir suma al pivot del destino (lo abre si no
     * existe); las dos acciones dejan la fila de la sucursal en 0. El llamador recalcula después
     * `articles.stock` con `setArticleStockFromAddresses()`.
     *
     * Sin renglón en el libro de movimientos: el libro cuelga de un artículo que el motor no ve, y
     * escribirlo a mano sería inventar un camino paralelo. Queda declarado como limitación.
     *
     * @param  string    $tabla
     * @param  string    $columna
     * @param  int       $id
     * @param  int       $address_id
     * @param  int|null  $destino_id
     * @param  float     $cantidad
     * @param  string    $accion
     * @return void
     */
    static function mover_por_sql($tabla, $columna, $id, $address_id, $destino_id, $cantidad, $accion) {

        $ahora = Carbon::now();

        if ($accion === Self::STOCK_TRANSFERIR && !is_null($destino_id)) {

            $hay_fila = DB::table($tabla)->where($columna, $id)->where('address_id', $destino_id)->exists();

            if ($hay_fila) {

                DB::table($tabla)
                    ->where($columna, $id)
                    ->where('address_id', $destino_id)
                    ->update([
                        'amount'     => DB::raw('COALESCE(amount, 0) + ('.sprintf('%.4F', $cantidad).')'),
                        'updated_at' => $ahora,
                    ]);

            } else {

                DB::table($tabla)->insert([
                    $columna     => $id,
                    'address_id' => $destino_id,
                    'amount'     => $cantidad,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            }
        }

        DB::table($tabla)
            ->where($columna, $id)
            ->where('address_id', $address_id)
            ->update(['amount' => 0, 'updated_at' => $ahora]);
    }

    // ------------------------------------------------------------------------------------------
    // La fase final (una transacción)
    // ------------------------------------------------------------------------------------------

    /**
     * Reasigna todo lo que apunta a la sucursal y la borra, en UNA transacción (D13). Devuelve null
     * SIN escribir nada si la sucursal volvió a tener stock (el llamador hace otra pasada).
     *
     * @param  \App\Models\Address  $address
     * @param  int                  $owner_id
     * @param  array                $decision
     * @param  bool                 $es_la_ultima
     * @return array|null  Conteos de lo hecho; null si la sucursal volvió a tener stock (el llamador
     *                     repite la pasada); o ['error' => mensaje] si la sucursal que hereda o la que
     *                     recibe a los empleados ya no existe (no se escribió nada).
     */
    static function fase_final($address, $owner_id, $decision, $es_la_ultima) {

        $reemplazo_id = $es_la_ultima ? null : Self::reemplazo_de_la_decision($decision);

        $usuarios_destino_id = (!$es_la_ultima && $decision['usuarios_accion'] === Self::USUARIOS_REASIGNAR)
            ? $decision['usuarios_destino_id']
            : null;

        $resultado = DB::transaction(function () use ($address, $owner_id, $es_la_ultima, $reemplazo_id, $usuarios_destino_id) {

            /*
             * 🔴 PRIMERO se bloquean TODAS las filas de la sucursal, también las que están en 0 (tercera
             * ronda de revisión, 5/10/2026). El conteo de más abajo filtra por `amount != 0` y, con el
             * aislamiento READ COMMITTED que usa este sistema (config/database.php), InnoDB SUELTA el
             * candado de las filas que no cumplen el WHERE: las filas en 0 quedaban libres. Una venta de un
             * navegador con la sucursal todavía elegida podía escribir en una de esas filas justo después
             * del conteo y antes del DELETE: el DELETE esperaba su commit y borraba la fila ya modificada,
             * y el barrido de después no la veía porque ya no existía (stock global ≠ suma de sucursales,
             * para siempre). Con todas las filas bloqueadas, esa venta espera a que esta transacción
             * termine y su UPDATE cae sobre cero filas, con el global recalculado sobre lo que queda.
             *
             * El orden es el de la pasada: variantes y después artículo (ver procesar_articulo()). No
             * frena el camino de venta en lo más mínimo: solo la espera una venta que justo escribe en
             * esta sucursal mientras se borra.
             */
            DB::table('address_article_variant')->where('address_id', $address->id)->lockForUpdate()->pluck('id');
            DB::table('address_article')->where('address_id', $address->id)->lockForUpdate()->pluck('id');

            /*
             * La sucursal que hereda y la que recibe a los empleados se validaron al pedir la eliminación
             * (y el job las vuelve a validar al arrancar), pero esto puede correr minutos después. Si
             * alguien las eliminó mientras tanto, seguir dejaba las marcas (por defecto, madre, origen)
             * sin sucursal, y las cajas, los puntos de venta, los clientes y los empleados apuntando a un
             * id muerto, en silencio. Se releen ACÁ, bloqueadas: de paso, nadie las puede eliminar hasta
             * que esta transacción termine. Si faltan no se escribe nada y se pide elegir otra: el stock
             * ya movido queda donde está y volver a apretar Eliminar continúa desde ahí.
             */
            foreach (array_values(array_unique(array_filter([$reemplazo_id, $usuarios_destino_id]))) as $id_heredero) {

                $sigue_viva = DB::table('addresses')
                                ->where('id', $id_heredero)
                                ->where('user_id', $owner_id)
                                ->whereNull('buyer_id')
                                ->lockForUpdate()
                                ->value('id');

                if (is_null($sigue_viva)) {
                    return ['error' => 'La sucursal elegida para heredar la configuración o recibir a los empleados ya no existe. Volvé a intentarlo eligiendo otra.'];
                }
            }

            /*
             * Última mirada al stock, con las filas bloqueadas: si entró una venta después de la
             * pasada, no se borra nada y se repite. En la última sucursal (D6) el stock de las filas
             * se descarta a propósito (los artículos conservan su stock total), así que no se mira.
             *
             * 🔴 Con las MISMAS consultas que la pasada (query_filas_de_*_con_stock): si contara algo
             * que la pasada no mueve (una variante de un artículo borrado físicamente), la sucursal
             * quedaría imborrable.
             */
            if (!$es_la_ultima) {

                $con_stock = Self::query_filas_de_articulos_con_stock($address->id, $owner_id)->lockForUpdate()->count()
                           + Self::query_filas_de_variantes_con_stock($address->id, $owner_id)->lockForUpdate()->count();

                if ($con_stock > 0) {
                    return null;
                }
            }

            // D8: el dueño y sus empleados (nunca un usuario de otro comercio).
            $usuarios = Self::query_de_usuarios($address->id, $owner_id)
                            ->update(['address_id' => $usuarios_destino_id]);

            // D9: las marcas pasan al reemplazo. Por modelo: el hook `saved` de Address mantiene la
            // unicidad de la madre y la auditoría de cambios ve el cambio.
            $marcas = Self::marcas_de($address);

            if (!is_null($reemplazo_id) && count($marcas) > 0) {

                $reemplazo = Address::find($reemplazo_id);

                if (!is_null($reemplazo)) {

                    foreach ($marcas as $marca) {
                        $reemplazo->{$marca} = 1;
                    }

                    $reemplazo->save();
                }
            }

            /*
             * D10: configuración viva que apunta a la sucursal. Quedarse con el id muerto dejaba cajas
             * con saldo y puntos de venta INVISIBLES en Vender (la SPA filtra por la sucursal
             * elegida). Pasan al reemplazo, o a NULL = "de todas las sucursales".
             */
            $cajas = DB::table('cajas')
                        ->where('user_id', $owner_id)
                        ->where('address_id', $address->id)
                        ->update(['address_id' => $reemplazo_id]);

            $puntos_de_venta = DB::table('afip_information')
                                ->where('user_id', $owner_id)
                                ->where('address_id', $address->id)
                                ->update(['address_id' => $reemplazo_id]);

            $clientes = DB::table('clients')
                            ->where('user_id', $owner_id)
                            ->where('address_id', $address->id)
                            ->update(['address_id' => $reemplazo_id]);

            /*
             * Los métodos de pago por defecto DE ESTA sucursal se borran, no se reasignan: son los
             * defaults de una sucursal que deja de existir, y pasarlos al reemplazo chocaría con los
             * defaults que el reemplazo ya tiene para los mismos métodos.
             */
            $metodos_por_defecto = DB::table('default_payment_method_cajas')
                                    ->where('user_id', $owner_id)
                                    ->where('address_id', $address->id)
                                    ->delete();

            /*
             * Artículos de OTRO comercio con fila en esta sucursal (base compartida, dato cruzado): la
             * pasada no los movió a propósito (ver query_filas_de_articulos_con_stock()). Sus filas
             * se borran acá abajo SIN movimiento, y después del commit se les recalcula el stock global
             * con su propio dueño, para que no quede contando una fila que ya no existe.
             */
            $articulos_ajenos = Self::articulos_ajenos_con_filas($address->id, $owner_id);

            // Las filas de pivot que quedan (en 0, o con stock en el caso D6) y la sucursal.
            $filas_articulo = DB::table('address_article')->where('address_id', $address->id)->delete();
            $filas_variante = DB::table('address_article_variant')->where('address_id', $address->id)->delete();

            $address->delete();

            return [
                'usuarios'               => (int) $usuarios,
                'reemplazo_id'           => $reemplazo_id,
                'marcas'                 => $marcas,
                'cajas'                  => (int) $cajas,
                'puntos_de_venta'        => (int) $puntos_de_venta,
                'clientes'               => (int) $clientes,
                'metodos_por_defecto'    => (int) $metodos_por_defecto,
                'filas_de_pivot_borradas' => (int) $filas_articulo + (int) $filas_variante,
                'articulos_ajenos'       => $articulos_ajenos,
            ];
        }, 3);

        if (is_null($resultado)) {
            return null;
        }

        // La sucursal heredera desapareció (ver arriba): no se escribió nada y el llamador lo informa.
        if (isset($resultado['error'])) {
            return $resultado;
        }

        // Fuera de la transacción: nada de esto se puede deshacer con un rollback.
        ImageController::deleteModelImages($address);
        SucursalVigenteHelper::olvidar($address->id);

        // Gancho SOLO para tests (simula una venta que escribió entre el commit y el barrido).
        if (!is_null(Self::$antes_del_barrido)) {
            call_user_func(Self::$antes_del_barrido, $address);
        }

        $resultado['filas_tardias'] = Self::barrer_filas_tardias($address);

        if (count($resultado['articulos_ajenos']) > 0) {

            Log::warning('EliminarSucursalHelper: la sucursal '.$address->id.' del comercio '.$owner_id.' tenía filas de '.count($resultado['articulos_ajenos']).' artículo(s) de OTRO comercio; se borraron sin movimiento y se les recalculó el stock.', [
                'articulos' => $resultado['articulos_ajenos'],
            ]);

            Self::recalcular_articulos($resultado['articulos_ajenos']);
        }

        $resultado['articulos_ajenos'] = count($resultado['articulos_ajenos']);

        return $resultado;
    }

    /**
     * Ids de los artículos de OTRO comercio que tienen alguna fila (propia o de una variante) en la
     * sucursal.
     *
     * @param  int  $address_id
     * @param  int  $owner_id
     * @return array
     */
    static function articulos_ajenos_con_filas($address_id, $owner_id) {

        $ids = DB::table('address_article as aa')
                    ->join('articles as a', 'a.id', '=', 'aa.article_id')
                    ->where('aa.address_id', $address_id)
                    ->where('a.user_id', '!=', $owner_id)
                    ->distinct()
                    ->pluck('a.id')
                    ->all();

        $de_variantes = DB::table('address_article_variant as aav')
                            ->join('article_variants as av', 'av.id', '=', 'aav.article_variant_id')
                            ->join('articles as a', 'a.id', '=', 'av.article_id')
                            ->where('aav.address_id', $address_id)
                            ->where('a.user_id', '!=', $owner_id)
                            ->distinct()
                            ->pluck('a.id')
                            ->all();

        $todos = array_values(array_unique(array_map('intval', array_merge($ids, $de_variantes))));

        sort($todos);

        return $todos;
    }

    /**
     * @internal SOLO PARA TESTS: se llama con la sucursal entre el commit de la fase final y el
     * barrido de filas tardías, para simular una venta que escribió justo en ese momento. En
     * producción siempre null.
     *
     * @var callable|null
     */
    public static $antes_del_barrido = null;

    /**
     * El barrido de filas tardías, justo DESPUÉS del commit de la fase final (segunda ronda de
     * revisión, G).
     *
     * 🔴 POR QUÉ HACE FALTA Y QUÉ NO CUBRE. La fase final relee el stock con las filas bloqueadas y
     * repite la pasada si encuentra algo, pero el camino de las ventas NO toma candados sobre
     * `addresses` (y no se le agregan: es el camino más caliente del sistema y el riesgo de deadlock no
     * se justifica). Una venta que validó la sucursal como viva ANTES del commit (con el memo de
     * SucursalVigenteHelper, hasta SEGUNDOS_DE_MEMO) y escribe DESPUÉS puede:
     *  - abrir una fila nueva para la sucursal ya borrada (`attach()` en CheckFromAddress, porque la
     *    relación ya no la ve) → este barrido la borra y recalcula el global de ese artículo;
     *  - sumar sobre una fila que ya no existe (el UPDATE no toca nada) → ese descuento se pierde, y
     *    ningún barrido lo puede recuperar.
     * El barrido corre milisegundos después del commit: lo que se escriba DESPUÉS de él (dentro de
     * los segundos del memo o de una transacción de venta ya en vuelo) queda sin barrer. Es la
     * ventana residual declarada en el informe de la misión.
     *
     * @param  \App\Models\Address  $address  Ya borrada.
     * @return int  Filas barridas.
     */
    static function barrer_filas_tardias($address) {

        $afectados = Self::articulos_con_filas($address->id);

        $filas = DB::table('address_article')->where('address_id', $address->id)->delete()
               + DB::table('address_article_variant')->where('address_id', $address->id)->delete();

        if ($filas == 0) {
            return 0;
        }

        Log::warning('EliminarSucursalHelper: después de eliminar la sucursal '.$address->id.' aparecieron '.$filas.' fila(s) de stock tardías (una venta que la seguía usando); se borraron y se recalculó el stock de '.count($afectados).' artículo(s).', [
            'articulos' => $afectados,
        ]);

        Self::recalcular_articulos($afectados);

        return $filas;
    }

    /**
     * Ids de TODOS los artículos que tienen alguna fila (propia o de una variante) en la dirección,
     * sin importar su stock ni su dueño (incluye la papelera).
     *
     * @param  int  $address_id
     * @return array
     */
    static function articulos_con_filas($address_id) {

        $ids = DB::table('address_article')
                    ->where('address_id', $address_id)
                    ->distinct()
                    ->pluck('article_id')
                    ->all();

        $de_variantes = DB::table('address_article_variant as aav')
                            ->join('article_variants as av', 'av.id', '=', 'aav.article_variant_id')
                            ->where('aav.address_id', $address_id)
                            ->distinct()
                            ->pluck('av.article_id')
                            ->all();

        $todos = array_values(array_unique(array_map('intval', array_merge($ids, $de_variantes))));

        sort($todos);

        return $todos;
    }

    /**
     * Recalcula `articles.stock` (y el de sus variantes) desde las filas que quedan, con el dueño de
     * CADA artículo (no el de la sucursal): `setArticleStockFromAddresses()` arma la lista de
     * sucursales con ese dueño. Sin renglón en el libro: no hubo un movimiento, se borró una fila que
     * ya no correspondía. Incluye la papelera.
     *
     * @param  array  $article_ids
     * @return void
     */
    static function recalcular_articulos($article_ids) {

        foreach ($article_ids as $article_id) {

            $article = Article::withTrashed()->find($article_id);

            if (is_null($article)) {
                continue;
            }

            /*
             * Corre DESPUÉS del commit de la eliminación: si el recálculo de un artículo falla (un
             * dato cruzado de otro comercio puede tener filas en sucursales que su dueño no tiene, y
             * setArticleStockFromAddresses() no lo tolera), la sucursal ya está eliminada y eso no
             * puede volverse un 500. Se deja en el log y se sigue con el resto.
             */
            try {
                ArticleHelper::setArticleStockFromAddresses($article, false, $article->user_id);
            } catch (\Throwable $e) {
                Log::warning('EliminarSucursalHelper: no se pudo recalcular el stock del artículo '.$article_id.': '.$e->getMessage());
            }
        }
    }
}
