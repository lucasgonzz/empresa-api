<?php

namespace Tests\Feature\Compras;

use App\Models\Address;
use App\Models\ProviderOrder;
use Database\Seeders\testing\TestingFerreteriaSeeder;
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
 *  - una columna "Stock <sucursal>" por sucursal del DUEÑO DE LA COMPRA. La ruta se abre con
 *    window.open y no lleva sesión, así que el dueño sale de `provider_orders.user_id`;
 *  - un artículo que no tiene fila en una sucursal muestra 0 ahí, no una celda vacía;
 *  - un comercio sin sucursales no recibe ninguna columna de más.
 *
 * Hereda de `ComprasTestCase` (guards de entorno + fixture de la ferretería). La compra se arma
 * directo en la base, sin pasar por `POST api/provider-order`: ese camino mueve el stock y acá el
 * stock es justamente lo que se quiere controlar. PHP 7.4: sin match, str_contains, ?->, argumentos
 * nombrados ni union types.
 */
class Stock_En_Excel_De_Compras_Test extends ComprasTestCase
{
    /** @var int Dueño que no existe: representa un comercio sin ninguna sucursal cargada. */
    const DUENO_SIN_SUCURSALES = 2000000000;

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
        $respuesta = $this->get('/provider-orders/export/'.$compra->id);

        $respuesta->assertStatus(200);

        $ruta = $respuesta->baseResponse->getFile()->getPathname();

        return IOFactory::load($ruta)->getActiveSheet()->toArray(null, true, false, false);
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

        $filas = $this->filas_del_excel($this->compra_con(['Pinza' => 2], self::DUENO_SIN_SUCURSALES));

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
            'user_id' => self::DUENO_SIN_SUCURSALES,
        ]);

        $filas = $this->filas_del_excel($this->compra_con(['Pinza' => 1], $principal->user_id));

        $this->assertNotContains('Stock Sucursal Ajena', $filas[0]);
        $this->assertContains('Stock '.TestingFerreteriaSeeder::DEPOSITO, $filas[0]);
    }
}
