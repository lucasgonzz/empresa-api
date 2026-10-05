<?php

namespace Tests\Feature\Sales;

use App\Models\Article;
use App\Models\Sale;
use App\Models\SaleStatus;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Los dos Excel de la pantalla de Ventas ("Excel" y "Excel full") traen las MISMAS ventas que la
 * pantalla, y no una mas (mision excel-ventas-solo-terminadas, 5/10/2026).
 *
 * El defecto que se cierra: medido en la demo (4.3.6), el dia tenia 8 ventas en pantalla
 * ($167.890,04) y el Excel traia 9 ($175.890,04). La novena era una venta SIN TERMINAR, que vive en
 * Deposito / Por entregar y que el listado de Ventas nunca muestra. El Excel no filtraba `terminada`
 * ni `sale_status_id` (el listado si), ni sacaba las ventas en revision (`to_check` / `checked`)
 * que la pantalla oculta con `pasaFilaVenta` (mixins/sale.js).
 *
 * Lo que se protege aca:
 *
 *   1. Sin filtro de columnas, los dos Excel traen solo las ventas del modulo Ventas: terminadas y
 *      sin estado de venta (`Sale::scopeDelModuloVentas()`), sin las que estan en revision.
 *   2. Las ventas de los dos Excel son EXACTAMENTE las del listado paginado del mismo dia.
 *   3. Con filtro de columnas el Excel espeja la pantalla filtrada (decision de Lucas): no se
 *      filtra `terminada` ni `sale_status_id`, pero si se sacan `to_check` / `checked`.
 *   4. Las rutas GET viejas de routes/web.php tampoco traen las ventas sin terminar.
 *
 * Las ventas del fixture son combinaciones que existen en produccion: la sin terminar es
 * `terminada = 0` con `terminada_at` NULL (asi la deja `SaleHelper::get_terminada()` con fecha de
 * entrega), la de `checked = 1` esta terminada (Deposito la termino y `set_terminada` no limpia
 * `checked`), la de `to_check = 1` esta sin terminar (asi nace con la extension `check_sales`), y
 * la de estado lleva el id de un `sale_statuses` real del usuario.
 */
class Excel_De_Ventas_Solo_Terminadas_Test extends TestCase
{
    // DatabaseTransactions (no RefreshDatabase): la base de testing esta sembrada y compartida por
    // el slot. Mismo criterio que 16_Excel_Full_Unidad_De_Medida_Test.
    use DatabaseTransactions;

    public $user_id = 500;

    /** Sucursal `Principal` del user 500 en el fixture. */
    public $address_principal_id = 1;

    /**
     * Dia fijo y lejano, distinto del de los demas tests de ventas (16 y 20 usan 2037-06-15): aisla
     * el test de cualquier otra venta del usuario 500 en la base compartida del slot.
     */
    public $dia = '2037-07-20';

    /** Total de la venta terminada: es lo unico que tiene que sumar el Excel. */
    public $total_terminada = 167890.04;

    /** Total de la venta sin terminar: la misma diferencia de $8.000 que se midio en la demo. */
    public $total_sin_terminar = 8000;

    /** Articulo que se carga en cada venta, para que el Excel full tenga una linea por venta. */
    public $articulo = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actuar_como_el_usuario();
    }

    /**
     * "Excel" sin filtro de columnas: una venta terminada y una sin terminar en el rango -> el Excel
     * trae solo la terminada, y la fila de Total suma solo su plata.
     *
     * @group sales
     * @test
     */
    public function excel_sin_filtro_trae_solo_las_ventas_terminadas()
    {
        $terminada = $this->crear_venta(['total' => $this->total_terminada]);
        $this->crear_venta_sin_terminar();

        $export = $this->excel_de_la_pantalla('api/sales/excel/export', $this->body_sin_filtro());

        $this->assertSame([$terminada->id], $this->ids_del_excel($export),
            'El Excel sin filtro solo puede traer las ventas terminadas: la sin terminar no se ve en el listado de Ventas.');

        $this->assertEquals($this->total_terminada, $this->fila_de_total($export)[2],
            'La fila de Total del Excel tiene que sumar solo la plata de la venta terminada, no la de la sin terminar.');
    }

    /**
     * "Excel full" sin filtro de columnas: mismo caso, sobre el export desglosado por articulo.
     *
     * @group sales
     * @test
     */
    public function excel_full_sin_filtro_trae_solo_las_ventas_terminadas()
    {
        $terminada = $this->crear_venta(['total' => $this->total_terminada]);
        $this->crear_venta_sin_terminar();

        $export = $this->excel_de_la_pantalla('api/sales/excel/breakdown-export', $this->body_sin_filtro());

        $this->assertSame([$terminada->id], $this->ids_del_excel($export),
            'El Excel full sin filtro solo puede traer las lineas de las ventas terminadas.');

        $this->assertEquals($this->total_terminada, $this->fila_de_total($export)[9],
            'La fila de Total del Excel full tiene que sumar solo la plata de la venta terminada.');
    }

    /**
     * Las ventas de los dos Excel son EXACTAMENTE las del listado paginado del mismo dia, con las
     * cuatro clases de venta que el listado deja afuera conviviendo con la terminada: sin terminar,
     * con estado de venta, chequeada (terminada) y para chequear.
     *
     * @group sales
     * @test
     */
    public function los_dos_excel_traen_las_mismas_ventas_que_el_listado_del_dia()
    {
        $terminada = $this->crear_venta(['total' => $this->total_terminada]);
        $this->crear_venta_sin_terminar();
        $this->crear_venta(['sale_status_id' => $this->crear_estado_de_venta()->id]);
        $this->crear_venta(['checked' => 1]);
        $this->crear_venta_sin_terminar(['to_check' => 1]);

        $listado = $this->getJson('api/sale/from-date/ventas/' . $this->dia . '?per_page=25');
        $listado->assertStatus(200);

        $ids_del_listado = array_column($listado->json('models.data'), 'id');
        sort($ids_del_listado);

        $this->assertSame([$terminada->id], $ids_del_listado,
            'Precondicion: el listado del dia muestra solo la venta terminada, sin estado y sin revision.');

        $this->actuar_como_el_usuario();
        $excel = $this->excel_de_la_pantalla('api/sales/excel/export', $this->body_sin_filtro());

        $this->assertSame($ids_del_listado, $this->ids_del_excel($excel),
            'El Excel tiene que traer exactamente las ventas que muestra el listado del dia.');

        $this->actuar_como_el_usuario();
        $excel_full = $this->excel_de_la_pantalla('api/sales/excel/breakdown-export', $this->body_sin_filtro());

        $this->assertSame($ids_del_listado, $this->ids_del_excel($excel_full),
            'El Excel full tiene que traer exactamente las ventas que muestra el listado del dia.');
    }

    /**
     * Con filtro de columnas el Excel espeja la pantalla filtrada: la pantalla muestra el resultado
     * de /api/search/sale (que no filtra `terminada` ni `sale_status_id`) menos lo que saca
     * `pasaFilaVenta`. Por eso la sin terminar y la de estado SI vienen (decision de Lucas,
     * 5/10/2026) y las dos en revision NO.
     *
     * @group sales
     * @test
     */
    public function con_filtro_de_columnas_espeja_la_pantalla_filtrada()
    {
        $terminada = $this->crear_venta(['total' => $this->total_terminada]);
        $sin_terminar = $this->crear_venta_sin_terminar();
        $con_estado = $this->crear_venta(['sale_status_id' => $this->crear_estado_de_venta()->id]);
        $chequeada = $this->crear_venta(['checked' => 1]);
        $para_chequear = $this->crear_venta_sin_terminar(['to_check' => 1]);

        $ids_creados = [$terminada->id, $sin_terminar->id, $con_estado->id, $chequeada->id, $para_chequear->id];

        $esperados = [$terminada->id, $sin_terminar->id, $con_estado->id];
        sort($esperados);

        $body = array_merge($this->body_sin_filtro(), [
            'is_filtered' => true,
            'filters'     => [$this->filtro_por_id_entre(min($ids_creados), max($ids_creados))],
        ]);

        $excel = $this->excel_de_la_pantalla('api/sales/excel/export', $body);

        $this->assertSame($esperados, $this->ids_del_excel($excel),
            'Con filtro de columnas el Excel trae lo que muestra la pantalla filtrada: sin las ventas en revision (checked / to_check), y sin filtrar terminada ni estado.');

        $this->actuar_como_el_usuario();
        $excel_full = $this->excel_de_la_pantalla('api/sales/excel/breakdown-export', $body);

        $this->assertSame($esperados, $this->ids_del_excel($excel_full),
            'El Excel full filtrado tiene que traer las mismas ventas que el Excel filtrado.');
    }

    /**
     * Las rutas GET viejas de routes/web.php (sin consumidor en la SPA desde el prompt 287) tenian el
     * mismo defecto: tampoco pueden traer las ventas sin terminar.
     *
     * @group sales
     * @test
     */
    public function las_rutas_get_viejas_traen_solo_las_ventas_terminadas()
    {
        $terminada = $this->crear_venta(['total' => $this->total_terminada]);
        $this->crear_venta_sin_terminar();

        Excel::fake();

        $response = $this->get('sales/excel/export/' . $this->dia . '/' . $this->dia);
        $response->assertStatus(200);

        $ids = null;
        Excel::assertDownloaded($this->nombre_del_archivo('ventas'), function ($export) use (&$ids) {
            $ids = $this->ids_del_excel($export);
            return true;
        });

        $this->assertSame([$terminada->id], $ids,
            'La ruta GET vieja del Excel solo puede traer las ventas terminadas.');

        $this->actuar_como_el_usuario();

        $response = $this->get('sales/excel/breakdown-export/' . $this->dia . '/' . $this->dia);
        $response->assertStatus(200);

        $ids = null;
        Excel::assertDownloaded($this->nombre_del_archivo('ventas_desglosado'), function ($export) use (&$ids) {
            $ids = $this->ids_del_excel($export);
            return true;
        });

        $this->assertSame([$terminada->id], $ids,
            'La ruta GET vieja del Excel full solo puede traer las ventas terminadas.');
    }

    /**
     * Vuelve a autenticar al usuario 500 en el guard web. Despues de un request a la API el guard
     * puede quedar en Sanctum, y el request siguiente del mismo test ya no resuelve el usuario.
     *
     * @return void
     */
    function actuar_como_el_usuario()
    {
        $this->actingAs(User::find($this->user_id), 'web');
    }

    /**
     * POST al Excel de la pantalla (el que arma `build_export_body()` en Total.vue) y devuelve el
     * export que se descargo, para leerle las filas.
     *
     * @param  string $endpoint 'api/sales/excel/export' | 'api/sales/excel/breakdown-export'
     * @param  array  $body
     * @return \Maatwebsite\Excel\Concerns\FromCollection
     */
    function excel_de_la_pantalla($endpoint, $body)
    {
        Excel::fake();

        $response = $this->post($endpoint, $body);
        $response->assertStatus(200);

        $prefijo = $endpoint === 'api/sales/excel/export' ? 'ventas' : 'ventas_desglosado';

        $descargado = null;
        Excel::assertDownloaded($this->nombre_del_archivo($prefijo), function ($export) use (&$descargado) {
            $descargado = $export;
            return true;
        });

        return $descargado;
    }

    /**
     * Cuerpo del POST tal cual lo manda la pantalla sin filtro de columnas, con las show options y
     * las solapas en su valor por defecto.
     *
     * @return array
     */
    function body_sin_filtro()
    {
        return [
            'is_filtered'                 => false,
            'filters'                     => [],
            'from_date'                   => $this->dia,
            'until_date'                  => $this->dia,
            'ventas_cobradas_show_option' => 'cobradas-y-no-cobradas',
            'afip_ticket_show_option'     => 'con-y-sin-factura',
            'payment_method_show_option'  => 'todos',
            'address_id'                  => null,
            'employee_id'                 => null,
            'only_owner'                  => false,
        ];
    }

    /**
     * Filtro de columna numerico sobre `id` con el formato que lee `ColumnFiltersHelper::apply()`
     * (`type = number`, `mayor_que` / `menor_que`): matchea solo las ventas creadas por el test.
     *
     * @param  int $desde_id
     * @param  int $hasta_id
     * @return array
     */
    function filtro_por_id_entre($desde_id, $hasta_id)
    {
        return [
            'key'       => 'id',
            'type'      => 'number',
            'mayor_que' => $desde_id - 1,
            'menor_que' => $hasta_id + 1,
        ];
    }

    /**
     * Ids de venta del export, sin repetir y ordenados. Las filas de venta (y de articulo, en el
     * Excel full) son arrays asociativos con `numero_venta`; las de Total y de resumen por unidad
     * son arrays numericos y quedan afuera solas.
     *
     * @param  \Maatwebsite\Excel\Concerns\FromCollection $export
     * @return array
     */
    function ids_del_excel($export)
    {
        $ids = $export->collection()
            ->filter(function ($row) {
                return is_array($row) && array_key_exists('numero_venta', $row);
            })
            ->pluck('numero_venta')
            ->map(function ($id) {
                return (int) $id;
            })
            ->unique()
            ->values()
            ->all();

        sort($ids);

        return $ids;
    }

    /**
     * Fila de Total del export (la primera columna dice `Total`).
     *
     * @param  \Maatwebsite\Excel\Concerns\FromCollection $export
     * @return array
     */
    function fila_de_total($export)
    {
        $fila = $export->collection()->first(function ($row) {
            return is_array($row) && isset($row[0]) && $row[0] === 'Total';
        });

        $this->assertNotNull($fila, 'El export tiene que traer la fila de Total.');

        return $fila;
    }

    /**
     * Nombre del archivo que descarga el controller hoy.
     *
     * @param  string $prefijo
     * @return string
     */
    function nombre_del_archivo($prefijo)
    {
        return $prefijo . '_' . Carbon::now()->format('d-m-y') . '.xlsx';
    }

    /**
     * Estado de venta real del usuario (lo que lista Por estado).
     *
     * @return SaleStatus
     */
    function crear_estado_de_venta()
    {
        return SaleStatus::create([
            'name'        => 'Excel solo terminadas (test)',
            'description' => '',
            'position'    => 1,
            'user_id'     => $this->user_id,
        ]);
    }

    /**
     * Venta sin terminar como la deja `SaleHelper::get_terminada()` con fecha de entrega: sin
     * `terminada` y sin `terminada_at`. La fecha de entrega cae el mismo dia, asi la venta queda en
     * el rango tanto si el comercio fecha por carga como por fecha de pedido.
     *
     * @param  array $extra
     * @return Sale
     */
    function crear_venta_sin_terminar($extra = [])
    {
        return $this->crear_venta(array_merge([
            'total'         => $this->total_sin_terminar,
            'sub_total'     => $this->total_sin_terminar,
            'terminada'     => 0,
            'terminada_at'  => null,
            'fecha_entrega' => $this->dia . ' 18:00:00',
        ], $extra));
    }

    /**
     * Venta minima en pesos, sin cliente, terminada, del dia fijo del test, con una linea de
     * articulo por el total (para que el Excel full tenga una fila por venta). `$extra` pisa lo
     * que haga falta.
     *
     * @param  array $extra
     * @return Sale
     */
    function crear_venta($extra = [])
    {
        $sale = Sale::create(array_merge([
            'user_id'                      => $this->user_id,
            'address_id'                   => $this->address_principal_id,
            'moneda_id'                    => 1,
            'total'                        => 100,
            'sub_total'                    => 100,
            'terminada'                    => 1,
            'confirmed'                    => 1,
            'omitir_en_cuenta_corriente'   => 1,
            'is_consolidacion_facturacion' => 0,
            'created_at'                   => $this->dia . ' 12:00:00',
            'terminada_at'                 => $this->dia . ' 12:00:00',
        ], $extra));

        $sale->articles()->attach($this->articulo()->id, [
            'amount' => 1,
            'price'  => $sale->total,
            'cost'   => $sale->total,
        ]);

        return $sale;
    }

    /**
     * Articulo minimo del test, creado una sola vez por caso.
     *
     * @return Article
     */
    function articulo()
    {
        if (is_null($this->articulo)) {
            $this->articulo = Article::create([
                'name'    => 'Excel Solo Terminadas Test - Articulo',
                'user_id' => $this->user_id,
                'stock'   => 100,
                'status'  => 'active',
            ]);
        }

        return $this->articulo;
    }
}
