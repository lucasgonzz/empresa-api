<?php

namespace Tests\Feature\Compras;

use App\Models\Address;
use App\Models\ProviderOrder;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Misión stock-en-excel-de-compras (7/10/2026): el Excel que se baja de una compra
 * (`GET /provider-orders/export/{id}`) trae, además de las cuatro columnas de siempre, el stock
 * actual de cada artículo: el global y, si el comercio tiene sucursales, uno por cada sucursal.
 *
 * Lo que estos tests fijan:
 *
 *  - las cuatro columnas originales siguen en el mismo orden y lugar (el importador de compras
 *    mapea por columna elegida, y quien ya tenga una plantilla armada no se entera del cambio);
 *  - "Stock actual" es el stock global del artículo y va SIEMPRE, tenga o no sucursales (a
 *    diferencia del Excel de artículos, donde las sucursales reemplazan a la global);
 *  - una columna "Stock <sucursal>" por sucursal del DUEÑO DE LA COMPRA: el dueño sale de
 *    `provider_orders.user_id`, no del usuario logueado. (Hasta el 10/10/2026 este comentario
 *    decía que la ruta se abre con window.open sin sesión; no es así: verificado ese día en vivo
 *    con un navegador real, la SPA logueada abre `sale/pdf/1` con window.open y llega con su
 *    sesión — 200 application/pdf —, y sin sesión da 404. Desde la misión pdf-de-venta-publico la
 *    ruta exige la sesión del dueño de la compra, por eso `filas_del_excel()` pide con esa sesión);
 *  - un artículo que no tiene fila en una sucursal muestra 0 ahí, no una celda vacía;
 *  - un comercio sin sucursales no recibe ninguna columna de más;
 *  - los domicilios de compradores de la tienda (addresses con buyer_id, que llevan el user_id del
 *    dueño) no cuentan como sucursales.
 *
 * Hereda de `ComprasTestCase` (guards de entorno + fixture de la ferretería). La compra se arma
 * directo en la base, sin pasar por `POST api/provider-order`: ese camino mueve el stock y acá el
 * stock es justamente lo que se quiere controlar. PHP 7.4: sin match, str_contains, ?->, argumentos
 * nombrados ni union types.
 */
class Stock_En_Excel_De_Compras_Test extends ComprasTestCase
{
    /**
     * @var int|null Id del comercio sin ninguna sucursal cargada, creado por
     *               `dueno_sin_sucursales()` la primera vez que un test lo pide.
     */
    protected $dueno_sin_sucursales_id = null;

    /**
     * Un comercio sin ninguna sucursal cargada: un dueño NORMAL (id automático, `owner_id` null),
     * creado dentro de la transacción del test, que lo revierte.
     *
     * Hasta el 10/10/2026 era la constante 2000000000, un id que no existía como usuario. Desde la
     * misión pdf-de-venta-publico el Excel se pide con la sesión del dueño de la compra (cambio
     * autorizado por Lucas), así que ese dueño tiene que existir. No se crea con el id fijo: un
     * INSERT con id explícito sube el AUTO_INCREMENT de `users` para siempre (InnoDB no lo devuelve
     * con el rollback) y el próximo usuario de esa base saldría con id 2000000001.
     *
     * @return int
     */
    protected function dueno_sin_sucursales()
    {
        if (is_null($this->dueno_sin_sucursales_id)) {

            $this->dueno_sin_sucursales_id = DB::table('users')->insertGetId([
                'name'         => 'Comercio sin sucursales',
                'company_name' => 'Comercio sin sucursales (stock en Excel)',
                'email'        => 'stock-excel-sin-sucursales-'.uniqid().'@test.local',
                'password'     => Hash::make('secret'),
                'status'       => 'commerce',
                'owner_id'     => null,
                'created_at'   => Carbon::now(),
                'updated_at'   => Carbon::now(),
            ]);
        }

        return $this->dueno_sin_sucursales_id;
    }

    /**
     * Arma una compra con los artículos dados (nombre => cantidad) y devuelve el modelo.
     *
     * @param  array<string,int>  $articulos
     * @param  int|null           $user_id   Dueño de la compra; por defecto el del fixture.
     * @return \App\Models\ProviderOrder
     */
    protected function compra_con($articulos, $user_id = null)
    {
        $deposito = Address::where('street', TestingFerreteriaSeeder::DEPOSITO)->first();

        $compra = ProviderOrder::create([
            'provider_id' => $this->proveedor(TestingFerreteriaSeeder::PROVIDER_BSAS)->id,
            'address_id'  => $deposito->id,
            'user_id'     => is_null($user_id) ? $deposito->user_id : $user_id,
        ]);

        foreach ($articulos as $nombre => $cantidad) {
            $compra->articles()->attach($this->articulo($nombre)->id, ['amount' => $cantidad]);
        }

        return $compra;
    }

    /**
     * Baja el Excel de la compra por la ruta real y devuelve sus filas (la primera es la de
     * encabezados), con las celdas vacías como null.
     *
     * @param  \App\Models\ProviderOrder  $compra
     * @return array<int,array<int,mixed>>
     */
    protected function filas_del_excel($compra)
    {
        $this->con_la_sesion_del_dueno_de($compra);

        $respuesta = $this->get('/provider-orders/export/'.$compra->id);

        $respuesta->assertStatus(200);

        $ruta = $respuesta->baseResponse->getFile()->getPathname();

        return IOFactory::load($ruta)->getActiveSheet()->toArray(null, true, false, false);
    }

    /**
     * Preparación del pedido (misión pdf-de-venta-publico, 10/10/2026, cambio autorizado por
     * Lucas): `provider-orders/export/{id}` ya no es pública, se sirve con la sesión del comercio
     * dueño de la compra (o durante su ventana de transición, que en la base de testing está
     * cerrada). Así que el Excel se pide con la sesión de ESE dueño, como lo pide la SPA.
     *
     * El dueño es el del fixture o el de `dueno_sin_sucursales()`: en los dos casos existe.
     *
     * @param  \App\Models\ProviderOrder  $compra
     * @return void
     */
    protected function con_la_sesion_del_dueno_de($compra)
    {
        // findOrFail y no find: si el dueño no existiera, que corte acá con un error claro.
        $dueno = User::findOrFail($compra->user_id);

        // Se olvida la sesión que dejó el setUp antes de cambiar de usuario.
        $this->app['auth']->forgetGuards();

        $this->actingAs($dueno, 'web');
    }

    /**
     * Fija el stock de un artículo: el global y el de una sucursal (pivot `address_article`).
     *
     * @param  \App\Models\Article  $articulo
     * @param  float                $global
     * @param  \App\Models\Address  $sucursal
     * @param  float|null           $en_sucursal  null = el artículo no tiene fila en esa sucursal.
     * @return void
     */
    protected function fijar_stock($articulo, $global, $sucursal, $en_sucursal)
    {
        $articulo->stock = $global;
        $articulo->timestamps = false;
        $articulo->save();

        $articulo->addresses()->detach($sucursal->id);

        if (!is_null($en_sucursal)) {
            $articulo->addresses()->attach($sucursal->id, ['amount' => $en_sucursal]);
        }
    }

    /**
     * Caso 1: con sucursales, el Excel trae "Stock actual" más una columna por sucursal, detrás de
     * las cuatro de siempre y en el orden de alta de las sucursales.
     *
     * @group compras
     * @return void
     */
    public function test_con_sucursales_agrega_el_global_y_una_columna_por_sucursal()
    {
        $principal = Address::where('street', TestingFerreteriaSeeder::DEPOSITO)->first();

        $nueva = Address::create([
            'street'  => 'Sucursal Centro',
            'user_id' => $principal->user_id,
        ]);

        $pinza = $this->articulo('Pinza');

        $this->fijar_stock($pinza, 17, $principal, 12);
        $pinza->addresses()->attach($nueva->id, ['amount' => 5]);

        $filas = $this->filas_del_excel($this->compra_con(['Pinza' => 3]));

        $sucursales = Address::where('user_id', $principal->user_id)->orderBy('id', 'ASC')->get();

        $esperados = ['Nombre', 'Código de Barras', 'Código Proveedor', 'Cantidad', 'Stock actual'];
        foreach ($sucursales as $sucursal) {
            $esperados[] = 'Stock '.$sucursal->street;
        }

        $this->assertSame($esperados, $filas[0]);

        $this->assertCount(2, $filas, 'Encabezado + la única línea de la compra.');

        $fila = $filas[1];

        $this->assertSame('Pinza', $fila[0]);
        $this->assertEquals(3, $fila[3], 'La cantidad de la compra sigue en su columna de siempre.');
        $this->assertEquals(17, $fila[4], 'Stock actual = stock global del artículo.');

        $columna_principal = array_search('Stock '.TestingFerreteriaSeeder::DEPOSITO, $filas[0]);
        $columna_centro    = array_search('Stock Sucursal Centro', $filas[0]);

        $this->assertEquals(12, $fila[$columna_principal]);
        $this->assertEquals(5, $fila[$columna_centro]);
    }

    /**
     * Caso 2: un artículo sin fila en una sucursal muestra 0 en esa columna (no queda vacía), y el
     * global sigue saliendo igual.
     *
     * @group compras
     * @return void
     */
    public function test_articulo_sin_fila_en_una_sucursal_muestra_cero()
    {
        $principal = Address::where('street', TestingFerreteriaSeeder::DEPOSITO)->first();

        Address::create([
            'street'  => 'Sucursal Norte',
            'user_id' => $principal->user_id,
        ]);

        $this->fijar_stock($this->articulo('Pinza'), 9, $principal, 9);

        $filas = $this->filas_del_excel($this->compra_con(['Pinza' => 1]));

        $columna_norte = array_search('Stock Sucursal Norte', $filas[0]);

        $this->assertNotFalse($columna_norte);
        $this->assertSame(0, $filas[1][$columna_norte], 'Sin fila en la sucursal = 0, no celda vacía.');
        $this->assertEquals(9, $filas[1][4]);
    }

    /**
     * Caso 3: cada línea de la compra trae su propio stock, no el de la primera.
     *
     * @group compras
     * @return void
     */
    public function test_cada_linea_trae_el_stock_de_su_articulo()
    {
        $principal = Address::where('street', TestingFerreteriaSeeder::DEPOSITO)->first();

        $this->fijar_stock($this->articulo('Pinza'), 40, $principal, 40);
        $this->fijar_stock($this->articulo('Alicate'), 6, $principal, 6);

        $filas = $this->filas_del_excel($this->compra_con(['Pinza' => 1, 'Alicate' => 1]));

        $columna_principal = array_search('Stock '.TestingFerreteriaSeeder::DEPOSITO, $filas[0]);

        $por_nombre = [];
        foreach (array_slice($filas, 1) as $fila) {
            $por_nombre[$fila[0]] = $fila;
        }

        $this->assertEquals(40, $por_nombre['Pinza'][4]);
        $this->assertEquals(40, $por_nombre['Pinza'][$columna_principal]);
        $this->assertEquals(6, $por_nombre['Alicate'][4]);
        $this->assertEquals(6, $por_nombre['Alicate'][$columna_principal]);
    }

    /**
     * Caso 4: un comercio sin sucursales recibe "Stock actual" y nada más: ninguna columna por
     * sucursal.
     *
     * @group compras
     * @return void
     */
    public function test_sin_sucursales_solo_agrega_el_stock_global()
    {
        $pinza = $this->articulo('Pinza');
        $pinza->stock = 23;
        $pinza->timestamps = false;
        $pinza->save();

        $filas = $this->filas_del_excel($this->compra_con(['Pinza' => 2], $this->dueno_sin_sucursales()));

        $this->assertSame(
            ['Nombre', 'Código de Barras', 'Código Proveedor', 'Cantidad', 'Stock actual'],
            $filas[0]
        );
        $this->assertCount(5, $filas[1]);
        $this->assertEquals(23, $filas[1][4]);
    }

    /**
     * Caso 5: las sucursales que salen son las del dueño de la compra, no las de otro comercio.
     *
     * @group compras
     * @return void
     */
    public function test_las_sucursales_de_otro_dueno_no_se_cuelan()
    {
        $principal = Address::where('street', TestingFerreteriaSeeder::DEPOSITO)->first();

        Address::create([
            'street'  => 'Sucursal Ajena',
            'user_id' => $this->dueno_sin_sucursales(),
        ]);

        $filas = $this->filas_del_excel($this->compra_con(['Pinza' => 1], $principal->user_id));

        $this->assertNotContains('Stock Sucursal Ajena', $filas[0]);
        $this->assertContains('Stock '.TestingFerreteriaSeeder::DEPOSITO, $filas[0]);
    }

    /**
     * Caso 6: un domicilio de comprador de la tienda (`buyer_id` no nulo, con el `user_id` del
     * dueño) no es una sucursal y no genera columna.
     *
     * @group compras
     * @return void
     */
    public function test_los_domicilios_de_compradores_no_son_sucursales()
    {
        $principal = Address::where('street', TestingFerreteriaSeeder::DEPOSITO)->first();

        Address::create([
            'street'   => 'Calle de un comprador',
            'user_id'  => $principal->user_id,
            'buyer_id' => 987654,
        ]);

        $filas = $this->filas_del_excel($this->compra_con(['Pinza' => 1]));

        $this->assertNotContains('Stock Calle de un comprador', $filas[0]);
        $this->assertContains('Stock '.TestingFerreteriaSeeder::DEPOSITO, $filas[0]);
    }

    /**
     * Caso 7: un artículo sin stock sale con 0 en "Stock actual" (no en blanco), tanto si el stock
     * global es 0 como si nunca se cargó (null).
     *
     * @group compras
     * @return void
     */
    public function test_stock_global_en_cero_o_sin_cargar_sale_como_cero()
    {
        $pinza = $this->articulo('Pinza');
        $pinza->stock = 0;
        $pinza->timestamps = false;
        $pinza->save();

        $alicate = $this->articulo('Alicate');
        $alicate->stock = null;
        $alicate->timestamps = false;
        $alicate->save();

        $filas = $this->filas_del_excel($this->compra_con(['Pinza' => 1, 'Alicate' => 1], $this->dueno_sin_sucursales()));

        $por_nombre = [];
        foreach (array_slice($filas, 1) as $fila) {
            $por_nombre[$fila[0]] = $fila;
        }

        // assertEquals(0, null) pasaría: el assertNotNull es el que distingue "0" de "celda vacía".
        $this->assertNotNull($por_nombre['Pinza'][4], 'Stock 0 = 0, no celda vacía.');
        $this->assertEquals(0, $por_nombre['Pinza'][4]);
        $this->assertNotNull($por_nombre['Alicate'][4], 'Stock sin cargar = 0, no celda vacía.');
        $this->assertEquals(0, $por_nombre['Alicate'][4]);
    }
}
