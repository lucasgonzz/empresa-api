<?php

namespace Tests\Feature\Sales;

use App\Models\Article;
use App\Models\Sale;
use App\Models\UnidadMedida;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Excel full de ventas (boton "Excel full" -> SalesBreakdownExport): columna Unidad de medida,
 * suma de cantidad en la fila de Total, y resumen de cantidad vendida por unidad de medida.
 */
class Excel_Full_Unidad_De_Medida_Test extends TestCase
{
    // DatabaseTransactions (no RefreshDatabase): la base de testing esta sembrada y compartida
    // por el slot. Sin esto, las ventas creadas por este test quedan pisando la base entre
    // corridas y la fila de Total termina sumando cantidad de corridas anteriores.
    use DatabaseTransactions;

    public $user_id = 500;

    /**
     * Fecha fija y lejana para las ventas del test, y unico filtro from/until de la request.
     * Aisla el test de cualquier otra venta que ya exista hoy para el usuario 500 en la base
     * compartida del slot: sin esto, la fila de Total suma cantidad de ventas ajenas al test.
     */
    public $fecha_test = '2037-06-15';

    /**
     * @group sales
     * @test
     */
    public function excel_full_suma_cantidad_y_agrupa_por_unidad_de_medida()
    {
        $user = User::find($this->user_id);
        $this->actingAs($user, 'web');

        $unidad_kilo = UnidadMedida::firstOrCreate(['name' => 'Kilo (test excel full)']);
        $unidad_unidad = UnidadMedida::firstOrCreate(['name' => 'Unidad (test excel full)']);

        $articulo_kilo = $this->crear_articulo('Excel Full Test - Articulo Kilo', $unidad_kilo->id);
        $articulo_unidad = $this->crear_articulo('Excel Full Test - Articulo Unidad', $unidad_unidad->id);

        // Venta A: 3 kilos a 100 + 5 unidades a 50 => total 550.
        $venta_a = $this->crear_venta(550);
        $venta_a->articles()->attach($articulo_kilo->id, $this->pivot_articulo(3, 100));
        $venta_a->articles()->attach($articulo_unidad->id, $this->pivot_articulo(5, 50));

        // Venta B: 2 kilos mas a 100 => total 200. Prueba que la suma por unidad agrupa ENTRE ventas.
        $venta_b = $this->crear_venta(200);
        $venta_b->articles()->attach($articulo_kilo->id, $this->pivot_articulo(2, 100));

        Excel::fake();

        $response = $this->post('api/sales/excel/breakdown-export', [
            'from_date'  => $this->fecha_test,
            'until_date' => $this->fecha_test,
        ]);

        $response->assertStatus(200);

        $filename = 'ventas_desglosado_' . Carbon::now()->format('d-m-y') . '.xlsx';

        Excel::assertDownloaded($filename, function ($export) use ($venta_a, $venta_b, $unidad_kilo, $unidad_unidad) {

            $headings = $export->headings();
            $this->assertSame('Unidad de medida', $headings[6],
                'La columna Unidad de medida tiene que ir justo despues de Cantidad (indice 6).');

            // Las filas de articulo son arrays asociativos (claves por nombre de campo); las de
            // Total y de resumen son arrays numericos. Maatwebsite\Excel escribe las celdas en
            // orden posicional en los dos casos, asi que array_values() normaliza ambas formas
            // al mismo indice por el que se lee la columna en el Excel real.
            $rows = $export->collection()->map(function ($row) {
                return array_values($row);
            })->values();

            $filas_de_las_ventas = $rows->filter(function ($row) use ($venta_a, $venta_b) {
                return in_array($row[0], [$venta_a->id, $venta_b->id], true);
            });
            $this->assertCount(3, $filas_de_las_ventas,
                'Tienen que aparecer las 3 lineas de articulo (2 de la venta A + 1 de la venta B).');

            $fila_total = $rows->first(function ($row) {
                return $row[0] === 'Total';
            });
            $this->assertNotNull($fila_total, 'Tiene que existir la fila de Total.');
            $this->assertEquals(10, $fila_total[5],
                'La fila de Total tiene que sumar la cantidad de TODAS las lineas: 3 + 5 + 2 = 10.');
            $this->assertEquals(750, $fila_total[9],
                'La fila de Total sigue sumando el total en pesos de cada venta: 550 + 200 = 750.');

            $fila_resumen_kilo = $rows->first(function ($row) use ($unidad_kilo) {
                return $row[0] === 'Total ' . $unidad_kilo->name;
            });
            $this->assertNotNull($fila_resumen_kilo, 'Tiene que existir el resumen de la unidad Kilo.');
            $this->assertEquals(5, $fila_resumen_kilo[5],
                'Kilo se vendio en las dos ventas: 3 + 2 = 5, sumado entre ventas distintas.');
            $this->assertSame($unidad_kilo->name, $fila_resumen_kilo[6]);

            $fila_resumen_unidad = $rows->first(function ($row) use ($unidad_unidad) {
                return $row[0] === 'Total ' . $unidad_unidad->name;
            });
            $this->assertNotNull($fila_resumen_unidad, 'Tiene que existir el resumen de la unidad Unidad.');
            $this->assertEquals(5, $fila_resumen_unidad[5]);
            $this->assertSame($unidad_unidad->name, $fila_resumen_unidad[6]);

            return true;
        });
    }

    /**
     * Articulo minimo para el test, con la unidad de medida indicada.
     *
     * @param string $name
     * @param int $unidad_medida_id
     * @return Article
     */
    function crear_articulo($name, $unidad_medida_id)
    {
        return Article::create([
            'name'             => $name,
            'user_id'          => $this->user_id,
            'unidad_medida_id' => $unidad_medida_id,
            'stock'            => 100,
            'status'           => 'active',
        ]);
    }

    /**
     * Venta minima en pesos, sin cliente, ya terminada.
     *
     * @param float $total
     * @return Sale
     */
    function crear_venta($total)
    {
        return Sale::create([
            'user_id'                    => $this->user_id,
            'address_id'                 => 2,
            'moneda_id'                  => 1,
            'total'                      => $total,
            'sub_total'                  => $total,
            'terminada'                  => 1,
            'confirmed'                  => 1,
            'omitir_en_cuenta_corriente' => 1,
            'is_consolidacion_facturacion' => 0,
            'created_at'                 => $this->fecha_test . ' 12:00:00',
        ]);
    }

    /**
     * Datos de pivot article_sale minimos para que el export calcule linea y agrupe cantidad.
     *
     * @param float $amount
     * @param float $price
     * @return array
     */
    function pivot_articulo($amount, $price)
    {
        return [
            'amount' => $amount,
            'price'  => $price,
            'cost'   => $price,
        ];
    }
}
