<?php

namespace Tests\Feature\Sucursales;

use App\Models\Address;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Base comun de la suite del AJUSTE DE PRECIOS DE LA SUCURSAL (mision sucursal-recargo-descuento,
 * 2/10/2026).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  QUE PRUEBA ESTA SUITE, EN UNA LINEA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Que la API PERSISTE y DEVUELVE `ajuste_precio_tipo` / `ajuste_precio_porcentaje` de `addresses`
 *  (alta y edicion de sucursales), que rechaza con 422 lo que viola la invariante "las dos columnas
 *  con valor valido o las dos en NULL", que `update` NO pisa el ajuste cuando la SPA no manda las
 *  claves, que la guarda de esquema deja crear y editar sucursales antes de migrar, y que la API
 *  NO re-deriva el precio de una venta desde el catalogo (guarda lo que manda la SPA).
 *
 *  La API no calcula ningun precio con el ajuste: lo calcula la SPA. Por eso los numeros de los
 *  payloads de venta estan escritos A MANO (100 con el 10 % adentro = 110).
 *
 *  Toda afirmacion sobre lo guardado se lee DIRECTO de la tabla con `DB::table()`, nunca por la
 *  respuesta del endpoint: si el endpoint devolviera algo que no se guardo, un test que lo leyera
 *  de ahi daria verde con la columna vacia.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
abstract class SucursalesTestCase extends EmpresaTestCase
{
    /** Tolerancia de plata. */
    const DELTA = 0.01;

    /**
     * @return \App\Models\User
     */
    protected function comercio()
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->assertNotNull($user, 'Falta el usuario del fixture.');

        return $user;
    }

    /**
     * Payload minimo de una sucursal, con los campos que manda el ABM de siempre. Las claves del
     * ajuste NO van: cada test las agrega (o no) a proposito, porque que NO viajen es justo uno de
     * los casos (la SPA vieja).
     *
     * @param  array  $extra  Claves que pisan o agregan.
     * @return array
     */
    protected function payload_sucursal($extra = [])
    {
        return array_merge([
            'street'          => 'zz Sucursal ajuste '.uniqid(),
            'street_number'   => null,
            'city'            => null,
            'province'        => null,
            'default_address' => 0,
        ], $extra);
    }

    /**
     * Crea una sucursal por el endpoint real y devuelve su id.
     *
     * @param  array  $extra  Claves que se suman al payload minimo (p. ej. las del ajuste).
     * @return int
     */
    protected function crear_sucursal($extra = [])
    {
        $response = $this->postJson('api/address', $this->payload_sucursal($extra));

        $response->assertStatus(201);

        $id = $response->json('model.id');

        $this->assertNotNull($id, 'POST api/address no devolvio la sucursal creada.');

        return (int) $id;
    }

    /**
     * El par del ajuste tal como esta guardado, leido DIRECTO de la tabla.
     *
     * Los valores vuelven crudos (el tipo como texto o null; el porcentaje como el string decimal
     * que entrega MySQL, p. ej. "10.00", o null).
     *
     * @param  int  $id
     * @return object  Con `ajuste_precio_tipo` y `ajuste_precio_porcentaje`.
     */
    protected function ajuste_guardado($id)
    {
        $fila = DB::table('addresses')
                    ->where('id', $id)
                    ->first(['ajuste_precio_tipo', 'ajuste_precio_porcentaje']);

        $this->assertNotNull($fila, 'La sucursal '.$id.' no esta en la base.');

        return $fila;
    }

    /**
     * Afirma el par guardado de una sucursal. `$porcentaje` en null = se esperan las dos en NULL.
     *
     * @param  int          $id
     * @param  string|null  $tipo
     * @param  float|null   $porcentaje
     * @param  string       $que        Para el mensaje.
     * @return void
     */
    protected function assert_ajuste($id, $tipo, $porcentaje, $que)
    {
        $fila = $this->ajuste_guardado($id);

        $this->assertSame($tipo, $fila->ajuste_precio_tipo, $que.': el tipo guardado no es el esperado.');

        if (is_null($porcentaje)) {
            $this->assertNull($fila->ajuste_precio_porcentaje, $que.': el porcentaje tiene que quedar en NULL.');
            return;
        }

        $this->assertNotNull($fila->ajuste_precio_porcentaje, $que.': el porcentaje no se guardo (quedo NULL).');

        $this->assertEqualsWithDelta(
            $porcentaje,
            (float) $fila->ajuste_precio_porcentaje,
            0.0001,
            $que.': el porcentaje guardado no es el esperado.'
        );
    }

    /**
     * Cantidad de sucursales del comercio de prueba. Para probar que un 422 en el alta no dejo una
     * sucursal a medio escribir.
     *
     * @return int
     */
    protected function cantidad_de_sucursales()
    {
        return Address::where('user_id', $this->comercio()->id)->count();
    }

    // ==========================================================================================
    //  ELIMINAR UNA SUCURSAL (misión eliminar-sucursal-con-stock, 5/10/2026)
    //
    //  Helpers de los archivos 7 a 12. Mismo criterio que el resto de la suite: todo lo guardado se
    //  lee DIRECTO de las tablas, y el stock se carga por el endpoint real de movimientos (el modal
    //  de crear depósitos) cuando se puede, para que lo medido sea el camino de siempre.
    // ==========================================================================================

    /**
     * Sucursal principal del fixture (la de menor id del comercio).
     *
     * @return \App\Models\Address
     */
    protected function sucursal_principal()
    {
        $address = Address::where('user_id', $this->comercio()->id)->whereNull('buyer_id')->orderBy('id')->first();

        $this->assertNotNull($address, 'El fixture no tiene ninguna sucursal.');

        return $address;
    }

    /**
     * Una sucursal propia del test, creada directo en la tabla (sin pasar por el alta, que no es lo
     * que se prueba acá).
     *
     * @param  string  $nombre
     * @param  array   $extra
     * @return \App\Models\Address
     */
    protected function nueva_sucursal($nombre, $extra = [])
    {
        return Address::create(array_merge([
            'street'          => $nombre,
            'user_id'         => $this->comercio()->id,
            'default_address' => 0,
        ], $extra));
    }

    /**
     * Un artículo propio del test (prefijo "zz").
     *
     * @param  string  $nombre
     * @param  array   $extra
     * @return \App\Models\Article
     */
    protected function nuevo_articulo($nombre, $extra = [])
    {
        return \App\Models\Article::create(array_merge([
            'name'    => $nombre,
            'user_id' => $this->comercio()->id,
        ], $extra));
    }

    /**
     * Le carga stock a un depósito por el endpoint real, como el modal de crear depósitos.
     *
     * @param  \App\Models\Article  $articulo
     * @param  \App\Models\Address  $address
     * @param  float                $cantidad  Puede ser negativa.
     * @return void
     */
    protected function cargar_deposito($articulo, $address, $cantidad)
    {
        $this->postJson('api/stock-movement', [
            'model_id'                     => $articulo->id,
            'amount'                       => $cantidad,
            'to_address_id'                => $address->id,
            'concepto_stock_movement_name' => 'Creacion de deposito',
        ])->assertStatus(201);
    }

    /**
     * Una variante del artículo, sin stock.
     *
     * @param  \App\Models\Article  $articulo
     * @param  string               $descripcion
     * @return \App\Models\ArticleVariant
     */
    protected function nueva_variante($articulo, $descripcion)
    {
        return \App\Models\ArticleVariant::create([
            'article_id'          => $articulo->id,
            'variant_description' => $descripcion,
            'stock'               => 0,
        ]);
    }

    /**
     * Le pone stock a una variante en un depósito y recalcula el artículo como lo hace el motor
     * (mismo armado que los tests 12 de la carpeta Stock: la fila de la variante y el recálculo, que
     * reconstruye las filas del artículo desde las variantes).
     *
     * @param  \App\Models\Article         $articulo
     * @param  \App\Models\ArticleVariant  $variante
     * @param  \App\Models\Address         $address
     * @param  int                         $cantidad
     * @return void
     */
    protected function cargar_variante($articulo, $variante, $address, $cantidad)
    {
        DB::table('address_article_variant')->insert([
            'article_variant_id' => $variante->id,
            'address_id'         => $address->id,
            'amount'             => $cantidad,
        ]);

        \App\Http\Controllers\Helpers\ArticleHelper::setArticleStockFromAddresses(
            \App\Models\Article::withTrashed()->find($articulo->id),
            false,
            $this->comercio()->id
        );
    }

    /**
     * `articles.stock`, leído de la tabla (incluye la papelera).
     *
     * @param  \App\Models\Article|int  $articulo
     * @return float|null
     */
    protected function stock_global($articulo)
    {
        $id = is_object($articulo) ? $articulo->id : $articulo;

        $stock = DB::table('articles')->where('id', $id)->value('stock');

        return is_null($stock) ? null : (float) $stock;
    }

    /**
     * Stock del artículo en un depósito (suma de sus filas), o null si no tiene fila.
     *
     * @param  \App\Models\Article|int  $articulo
     * @param  int                      $address_id
     * @return float|null
     */
    protected function stock_en($articulo, $address_id)
    {
        $id = is_object($articulo) ? $articulo->id : $articulo;

        $query = DB::table('address_article')->where('article_id', $id)->where('address_id', $address_id);

        if (!$query->exists()) {
            return null;
        }

        return (float) $query->sum('amount');
    }

    /**
     * Stock de la variante en un depósito, o null si no tiene fila.
     *
     * @param  \App\Models\ArticleVariant|int  $variante
     * @param  int                             $address_id
     * @return float|null
     */
    protected function stock_variante_en($variante, $address_id)
    {
        $id = is_object($variante) ? $variante->id : $variante;

        $query = DB::table('address_article_variant')->where('article_variant_id', $id)->where('address_id', $address_id);

        if (!$query->exists()) {
            return null;
        }

        return (float) $query->sum('amount');
    }

    /**
     * `article_variants.stock`, leído de la tabla.
     *
     * @param  \App\Models\ArticleVariant|int  $variante
     * @return float
     */
    protected function stock_de_variante($variante)
    {
        $id = is_object($variante) ? $variante->id : $variante;

        return (float) DB::table('article_variants')->where('id', $id)->value('stock');
    }

    /**
     * Cantidad de filas de pivot (artículos + variantes) que nombran la sucursal, sin importar su
     * stock. Después de eliminarla tiene que dar 0 SIEMPRE.
     *
     * @param  int  $address_id
     * @return int
     */
    protected function filas_de_pivot($address_id)
    {
        return DB::table('address_article')->where('address_id', $address_id)->count()
             + DB::table('address_article_variant')->where('address_id', $address_id)->count();
    }

    /**
     * Suma del stock del artículo en las sucursales que EXISTEN (INNER JOIN con addresses). El
     * invariante de la misión: tiene que ser igual a `articles.stock` en un artículo que reparte.
     *
     * @param  \App\Models\Article|int  $articulo
     * @return float
     */
    protected function suma_de_sucursales_vivas($articulo)
    {
        $id = is_object($articulo) ? $articulo->id : $articulo;

        return (float) DB::table('address_article')
                            ->join('addresses', 'addresses.id', '=', 'address_article.address_id')
                            ->where('address_article.article_id', $id)
                            ->sum('address_article.amount');
    }

    /**
     * Movimientos de stock del artículo, en orden, opcionalmente de un concepto.
     *
     * @param  \App\Models\Article|int  $articulo
     * @param  string|null              $concepto
     * @return \Illuminate\Support\Collection
     */
    protected function movimientos_de($articulo, $concepto = null)
    {
        $id = is_object($articulo) ? $articulo->id : $articulo;

        $query = \App\Models\StockMovement::where('article_id', $id)->orderBy('id');

        if (!is_null($concepto)) {
            $query->where('concepto_stock_movement_id', $this->concepto_id($concepto));
        }

        return $query->get();
    }

    /**
     * @param  string  $nombre
     * @return int
     */
    protected function concepto_id($nombre)
    {
        $id = DB::table('concepto_stock_movements')->where('name', $nombre)->value('id');

        $this->assertNotNull($id, 'El fixture no tiene el concepto de stock "'.$nombre.'".');

        return (int) $id;
    }

    /**
     * `DELETE api/address/{id}` con los parámetros de la decisión (o sin nada, como la SPA vieja).
     *
     * @param  int    $address_id
     * @param  array  $parametros
     * @return \Illuminate\Testing\TestResponse
     */
    protected function eliminar_sucursal($address_id, $parametros = [])
    {
        return $this->json('DELETE', 'api/address/'.$address_id, $parametros);
    }

    /**
     * Un empleado del comercio del fixture con una sucursal elegida.
     *
     * @param  string    $nombre
     * @param  int|null  $address_id
     * @param  int|null  $owner_id    null = el dueño del fixture.
     * @return \App\Models\User
     */
    protected function nuevo_empleado($nombre, $address_id, $owner_id = null)
    {
        return User::create([
            'name'         => $nombre,
            'company_name' => 'zz Ferreteria sucursales',
            'email'        => 'zz-sucursal-'.uniqid().'@test.local',
            'password'     => bcrypt('secret'),
            'owner_id'     => is_null($owner_id) ? $this->comercio()->id : $owner_id,
            'admin_access' => 0,
            'address_id'   => $address_id,
        ]);
    }

    /**
     * Otro comercio de la misma base (un dueño sin owner_id).
     *
     * @return \App\Models\User
     */
    protected function otro_comercio()
    {
        return User::create([
            'name'         => 'zz Otro comercio',
            'company_name' => 'zz Otro comercio',
            'email'        => 'zz-otro-'.uniqid().'@test.local',
            'password'     => bcrypt('secret'),
            'admin_access' => 1,
        ]);
    }

    /**
     * Una SEGUNDA conexión a la base de testing, por fuera de Laravel. Para el candado de la
     * eliminación (`GET_LOCK` de MySQL): es por conexión y re-entrante, así que tomarlo desde la
     * conexión del test no simula a "otro proceso" (la misma conexión lo vuelve a tomar).
     *
     * @return \PDO
     */
    protected function otra_conexion()
    {
        $config = config('database.connections.'.config('database.default'));

        $dsn = 'mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'];

        return new \PDO($dsn, $config['username'], $config['password']);
    }

    /**
     * Toma el candado de eliminación de una sucursal desde otra conexión ("otro proceso").
     *
     * @param  \PDO  $pdo
     * @param  int   $address_id
     * @return bool
     */
    protected function tomar_candado_desde($pdo, $address_id)
    {
        $nombre = \App\Http\Controllers\Helpers\address\EliminarSucursalHelper::nombre_del_candado($this->comercio()->id, $address_id);

        $sentencia = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $sentencia->execute([$nombre]);

        return (int) $sentencia->fetchColumn() === 1;
    }

    /**
     * ¿Alguna conexión tiene tomado el candado de eliminación de la sucursal?
     *
     * @param  int  $address_id
     * @return bool
     */
    protected function candado_tomado($address_id)
    {
        $nombre = \App\Http\Controllers\Helpers\address\EliminarSucursalHelper::nombre_del_candado($this->comercio()->id, $address_id);

        return !is_null(DB::selectOne('SELECT IS_USED_LOCK(?) AS conexion', [$nombre])->conexion);
    }

    /**
     * Deja al comercio con UNA sola sucursal viva (la dada), borrando las otras en la transacción
     * del test (se revierte al terminar). Para probar "la última sucursal" (D6).
     *
     * @param  \App\Models\Address  $la_que_queda
     * @return void
     */
    protected function dejar_solo($la_que_queda)
    {
        DB::table('addresses')
            ->where('user_id', $this->comercio()->id)
            ->whereNull('buyer_id')
            ->where('id', '!=', $la_que_queda->id)
            ->delete();

        \App\Http\Controllers\Helpers\address\SucursalVigenteHelper::olvidar();
    }
}
