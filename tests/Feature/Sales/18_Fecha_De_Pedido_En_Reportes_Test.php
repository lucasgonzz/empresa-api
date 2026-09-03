<?php

namespace Tests\Feature\Sales;

use App\Http\Controllers\Helpers\PerformanceHelper;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Fechar las ventas por fecha de pedido (`users.fechar_ventas_por_fecha_de_entrega`).
 *
 * El defecto que cierra esta suite: el listado y los reportes fechaban cada venta por
 * `sales.created_at` —cuando se CARGO la fila—, no por cuando ocurrio el pedido. Para el comercio
 * que carga en el momento las dos fechas coinciden; para la distribuidora que anota los pedidos en
 * papel y los carga en tandas, el reporte mezcla meses sin tirar ningun error y con un numero
 * plausible.
 *
 * Las cuatro cosas que se protegen aca, en este orden de importancia:
 *
 *   1. Con la preferencia APAGADA nada cambia. Son ~40 comercios que no pidieron nada.
 *   2. Con la preferencia prendida, la venta cae en el mes del PEDIDO y NO en el de carga.
 *   3. Con la preferencia prendida, una venta SIN fecha de entrega sigue apareciendo (el COALESCE):
 *      no puede desaparecer del listado.
 *   4. El grafico y Rendimiento devuelven EL MISMO conjunto que el listado. Es el acoplamiento que
 *      marco Lucas: si los tres no se mueven juntos, el comercio ve el mismo mes con dos numeros.
 */
class Fecha_De_Pedido_En_Reportes_Test extends TestCase
{
    // DatabaseTransactions (no RefreshDatabase): la base de testing esta sembrada y compartida por
    // el slot. Mismo criterio que 16_Excel_Full_Unidad_De_Medida_Test.
    use DatabaseTransactions;

    public $user_id = 500;

    /**
     * Fechas fijas y lejanas, en dos meses DISTINTOS. Aislan el test de cualquier venta que ya
     * exista en la base compartida del slot, y son justamente el caso que el defecto rompia: una
     * venta cargada en marzo cuyo pedido fue de enero.
     */
    public $fecha_de_carga = '2037-03-10';
    public $fecha_de_pedido = '2037-01-20';

    /** Ventana del mes de CARGA (marzo 2037). */
    public $mes_de_carga_desde = '2037-03-01';
    public $mes_de_carga_hasta = '2037-03-31';

    /** Ventana del mes de PEDIDO (enero 2037). */
    public $mes_de_pedido_desde = '2037-01-01';
    public $mes_de_pedido_hasta = '2037-01-31';

    /**
     * Con la preferencia apagada, la venta se sigue fechando por la fecha de CARGA. Es el test que
     * protege a los comercios que no pidieron nada: si este se pone en rojo, se les movio el
     * reporte a todos.
     *
     * @group sales
     * @test
     */
    public function con_la_preferencia_apagada_la_venta_se_fecha_por_la_fecha_de_carga()
    {
        $this->preferencia(0);

        $con_pedido = $this->crear_venta(1000, $this->fecha_de_pedido);
        $sin_pedido = $this->crear_venta(500, null);

        $en_marzo = $this->listado($this->mes_de_carga_desde, $this->mes_de_carga_hasta);
        $this->assertContains($con_pedido->id, $en_marzo,
            'Con la preferencia apagada la venta tiene que seguir cayendo en el mes de carga.');
        $this->assertContains($sin_pedido->id, $en_marzo);

        $en_enero = $this->listado($this->mes_de_pedido_desde, $this->mes_de_pedido_hasta);
        $this->assertNotContains($con_pedido->id, $en_enero,
            'Con la preferencia apagada la fecha de entrega no puede mover la venta de mes.');

        // Rendimiento conserva su OR historico (created_at O terminada_at): las dos ventas, que
        // tienen terminada_at en marzo, siguen entrando en marzo igual que siempre.
        $rendimiento_marzo = $this->rendimiento($this->mes_de_carga_desde, $this->mes_de_carga_hasta);
        $this->assertContains($con_pedido->id, $rendimiento_marzo);
        $this->assertContains($sin_pedido->id, $rendimiento_marzo);
    }

    /**
     * Con la preferencia prendida, la misma venta cae en el mes del PEDIDO y sale del de carga.
     *
     * @group sales
     * @test
     */
    public function con_la_preferencia_prendida_la_venta_se_fecha_por_la_fecha_de_pedido()
    {
        $this->preferencia(1);

        $con_pedido = $this->crear_venta(1000, $this->fecha_de_pedido);

        $en_enero = $this->listado($this->mes_de_pedido_desde, $this->mes_de_pedido_hasta);
        $this->assertContains($con_pedido->id, $en_enero,
            'Con la preferencia prendida la venta tiene que caer en el mes del pedido.');

        $en_marzo = $this->listado($this->mes_de_carga_desde, $this->mes_de_carga_hasta);
        $this->assertNotContains($con_pedido->id, $en_marzo,
            'Y tiene que SALIR del mes de carga: si aparece en los dos, los totales se duplican.');
    }

    /**
     * Una venta sin fecha de entrega no puede desaparecer del listado: cae por su fecha de carga.
     * Es el COALESCE, y no es un detalle — Truvari tiene una asi en agosto de 2026.
     *
     * @group sales
     * @test
     */
    public function la_venta_sin_fecha_de_entrega_sigue_apareciendo_por_su_fecha_de_carga()
    {
        $this->preferencia(1);

        $sin_pedido = $this->crear_venta(500, null);

        $en_marzo = $this->listado($this->mes_de_carga_desde, $this->mes_de_carga_hasta);
        $this->assertContains($sin_pedido->id, $en_marzo,
            'Sin COALESCE esta venta desaparece del listado, que es peor que fecharla mal.');

        $en_enero = $this->listado($this->mes_de_pedido_desde, $this->mes_de_pedido_hasta);
        $this->assertNotContains($sin_pedido->id, $en_enero);
    }

    /**
     * El grafico y Rendimiento devuelven el MISMO conjunto que el listado. Es el test del
     * acoplamiento: si alguien mueve uno de los tres y se olvida de los otros, esto se pone rojo.
     *
     * La segunda mitad (Rendimiento en marzo) es la que cubre el caso especial de
     * PerformanceHelper::set_sales(): su filtro historico es un OR con `terminada_at`, que sigue la
     * fecha de CARGA, asi que sin el `if` de la mision volveria a meter en marzo justo la venta que
     * el cambio saca.
     *
     * @group sales
     * @test
     */
    public function el_grafico_y_rendimiento_devuelven_el_mismo_conjunto_que_el_listado()
    {
        $this->preferencia(1);

        $con_pedido = $this->crear_venta(1000, $this->fecha_de_pedido);
        $sin_pedido = $this->crear_venta(500, null);

        $listado_enero = $this->listado($this->mes_de_pedido_desde, $this->mes_de_pedido_hasta);
        $this->assertContains($con_pedido->id, $listado_enero);
        $this->assertNotContains($sin_pedido->id, $listado_enero);

        $grafico_enero = $this->grafico($this->mes_de_pedido_desde, $this->mes_de_pedido_hasta);
        $this->assertEquals(count($listado_enero), $grafico_enero['cantidad_ventas'],
            'El grafico tiene que contar exactamente las ventas que muestra el listado.');
        $this->assertEquals(1000, $grafico_enero['total_ventas'],
            'Y sumar exactamente su total: solo la venta de 1000 pertenece a enero.');

        $rendimiento_enero = $this->rendimiento($this->mes_de_pedido_desde, $this->mes_de_pedido_hasta);
        $this->assertContains($con_pedido->id, $rendimiento_enero,
            'Rendimiento tiene que traer la misma venta que el listado en el mes del pedido.');
        $this->assertNotContains($sin_pedido->id, $rendimiento_enero);

        $rendimiento_marzo = $this->rendimiento($this->mes_de_carga_desde, $this->mes_de_carga_hasta);
        $this->assertNotContains($con_pedido->id, $rendimiento_marzo,
            'La rama de terminada_at no puede volver a meter en marzo la venta que se movio a enero.');
        $this->assertContains($sin_pedido->id, $rendimiento_marzo,
            'La venta sin fecha de entrega si tiene que seguir en marzo, por su fecha de carga.');
    }

    /**
     * El tilde del formulario de configuracion tiene que LLEGAR a la columna del dueño.
     *
     * `UserController::update()` asigna campo por campo (no hay fill ni mass assignment), asi que
     * una preferencia nueva que no este nombrada ahi se guarda en ningun lado: el PUT devuelve 200,
     * el tilde queda prendido en pantalla hasta que se recarga, y no cambia un solo numero. Medido
     * el 3/9/2026: sin la asignacion, este mismo PUT dejaba la columna en 0.
     *
     * @group sales
     * @test
     */
    public function el_formulario_de_configuracion_guarda_la_preferencia_en_el_dueno()
    {
        $user = $this->preferencia(0);

        $payload = $user->toArray();
        $payload['fechar_ventas_por_fecha_de_entrega'] = 1;

        $response = $this->putJson('api/user/' . $this->user_id, $payload);
        $response->assertStatus(200);

        $this->assertEquals(1, User::find($this->user_id)->fechar_ventas_por_fecha_de_entrega,
            'El PUT de configuracion tiene que persistir la preferencia en el usuario dueño.');
    }

    /**
     * Prende o apaga la preferencia en el usuario dueño y lo deja como usuario autenticado.
     *
     * @param  int $valor
     * @return User
     */
    function preferencia($valor)
    {
        $user = User::find($this->user_id);
        $user->fechar_ventas_por_fecha_de_entrega = $valor;
        $user->save();

        $this->actingAs($user, 'web');

        return $user;
    }

    /**
     * Venta minima, terminada, con `terminada_at` en la fecha de CARGA (que es lo que pasa en la
     * realidad: la venta se termina el dia que se carga, no el dia del pedido).
     *
     * @param  float       $total
     * @param  string|null $fecha_de_pedido Fecha de entrega, o null para la venta que no la tiene.
     * @return Sale
     */
    function crear_venta($total, $fecha_de_pedido)
    {
        return Sale::create([
            'user_id'                      => $this->user_id,
            'address_id'                   => 2,
            'moneda_id'                    => 1,
            'total'                        => $total,
            'sub_total'                    => $total,
            'terminada'                    => 1,
            'confirmed'                    => 1,
            'omitir_en_cuenta_corriente'   => 1,
            'is_consolidacion_facturacion' => 0,
            'created_at'                   => $this->fecha_de_carga . ' 12:00:00',
            'terminada_at'                 => $this->fecha_de_carga . ' 12:00:00',
            'fecha_entrega'                => is_null($fecha_de_pedido) ? null : $fecha_de_pedido . ' 12:00:00',
        ]);
    }

    /**
     * Ids que devuelve el listado de ventas para ese rango (SaleController::index).
     *
     * @param  string $desde
     * @param  string $hasta
     * @return array
     */
    function listado($desde, $hasta)
    {
        $response = $this->get('api/sale/from-date/ventas/' . $desde . '/' . $hasta);
        $response->assertStatus(200);

        $ids = [];

        foreach ($response->json('models') as $model) {
            $ids[] = $model['id'];
        }

        return $ids;
    }

    /**
     * Totales del grafico de ventas para ese rango (SaleChartHelper::getCharts).
     *
     * @param  string $desde
     * @param  string $hasta
     * @return array
     */
    function grafico($desde, $hasta)
    {
        $response = $this->get('api/sale/charts/' . $desde . '/' . $hasta);
        $response->assertStatus(200);

        return $response->json('charts');
    }

    /**
     * Ids de las ventas que Rendimiento toma para ese rango (PerformanceHelper::set_sales).
     *
     * Se instancia el helper derecho y no el endpoint de company-performance a proposito: el
     * endpoint escribe filas de performance y de article_performances, y lo que este test mide es
     * el conjunto de ventas, no el reporte armado.
     *
     * @param  string $desde
     * @param  string $hasta
     * @return array
     */
    function rendimiento($desde, $hasta)
    {
        $helper = new PerformanceHelper(null, null, $this->user_id, $desde, false, $hasta);
        $helper->set_sales();

        $ids = [];

        foreach ($helper->sales as $sale) {
            $ids[] = $sale->id;
        }

        return $ids;
    }
}
