<?php

namespace Tests\Feature\Pedidos;

use App\Http\Controllers\Helpers\Order\OrderStatusHelper;
use App\Models\OrderStatus;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\PedidosDePrueba;
use Tests\EmpresaTestCase;

/**
 * Los estados de pedido son de SOLO LECTURA (decisión de Lucas, 24/8/2026).
 *
 * 🔴 El motivo no es cosmético. Desde la misión del 22/8, `OrderStatusHelper` decide si un pedido
 * puede avanzar comparando los **nombres** de estas filas contra una lista literal. Entonces:
 *
 *  - Renombrar "Confirmado" deja `array_search()` en false en las dos puntas: el backend rechaza
 *    toda transición salvo quedarse en el mismo estado, y **todos los pedidos de ese comercio pasan
 *    a ser solo cancelables**.
 *  - Borrar un estado deja tapiados a los pedidos que estaban en él: su `order_status` queda null y
 *    la venta ya no se puede crear nunca más.
 *
 * Cuando se cerraron los endpoints no había ninguna pantalla que los usara ni ningún consumidor en
 * los seis repos del ecosistema: eran tres endpoints de escritura regalados contra una tabla de la
 * que depende el módulo entero.
 */
class Order_status_solo_lectura_Test extends EmpresaTestCase
{
    use PedidosDePrueba;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->sembrar_estados_de_pedido();
    }

    /**
     * Afirma que la respuesta es un rechazo POR RUTA (404/405) y no cualquier no-2xx.
     *
     * 🔴 No es quisquillosidad. Se midió: `OrderStatusController@store` ya devolvía **500** en
     * `develop`, antes de esta misión, o sea que estaba roto además de no usarse. Un
     * `assertNotEquals(201)` habría pasado igual con la ruta abierta, midiendo el bug viejo en vez
     * de la guarda nueva. Exigir 405 es lo que distingue "el verbo no existe" de "el verbo existe y
     * explota".
     *
     * @param  \Illuminate\Testing\TestResponse  $response
     * @param  string  $que_se_intento
     * @return void
     */
    protected function assertRechazadoPorLaRuta($response, $que_se_intento)
    {
        $this->assertContains(
            $response->getStatusCode(),
            [404, 405],
            $que_se_intento.': se esperaba que la ruta no existiera (404/405) y respondio '.$response->getStatusCode().'.'
        );
    }

    /**
     * 1. No se puede crear un estado nuevo.
     *
     * @group pedidos
     * @test
     */
    public function no_se_puede_crear_un_estado()
    {
        $cuantos_habia = OrderStatus::count();

        $response = $this->postJson('api/order-status', ['name' => 'Estado inventado']);

        $this->assertRechazadoPorLaRuta($response, 'Crear un estado');

        $this->assertEquals(
            $cuantos_habia,
            OrderStatus::count(),
            'Se creo un estado de pedido nuevo.'
        );

        $this->assertNull(
            OrderStatus::where('name', 'Estado inventado')->first(),
            'Se creo el estado inventado.'
        );
    }

    /**
     * 2. No se puede renombrar un estado. Es el caso que rompe la máquina entera.
     *
     * @group pedidos
     * @test
     */
    public function no_se_puede_renombrar_un_estado()
    {
        $confirmado = $this->estado('Confirmado');

        $this->assertNotNull($confirmado, 'No se pudo armar el escenario: falta el estado "Confirmado".');

        $response = $this->putJson('api/order-status/'.$confirmado->id, ['name' => 'Aceptado']);

        $this->assertRechazadoPorLaRuta($response, 'Renombrar un estado');

        $this->assertEquals(
            'Confirmado',
            $confirmado->fresh()->name,
            'Se pudo renombrar "Confirmado": eso deja todos los pedidos del comercio solo cancelables.'
        );
    }

    /**
     * 3. No se puede borrar un estado.
     *
     * @group pedidos
     * @test
     */
    public function no_se_puede_borrar_un_estado()
    {
        $terminado = $this->estado('Terminado');

        $this->assertNotNull($terminado, 'No se pudo armar el escenario: falta el estado "Terminado".');

        $response = $this->deleteJson('api/order-status/'.$terminado->id);

        $this->assertRechazadoPorLaRuta($response, 'Borrar un estado');

        $this->assertNotNull(
            OrderStatus::find($terminado->id),
            'Se borro un estado de pedido.'
        );
    }

    /**
     * 4. Leerlos sigue funcionando: es lo que alimenta el select del formulario del pedido.
     *
     * @group pedidos
     * @test
     */
    public function leer_los_estados_sigue_funcionando()
    {
        $response = $this->getJson('api/order-status');

        $response->assertStatus(200);

        /** Nombres devueltos por el endpoint. */
        $nombres = [];

        foreach ($response->json('models') as $modelo) {
            $nombres[] = $modelo['name'];
        }

        foreach (self::$ESTADOS_PEDIDO as $esperado) {
            $this->assertContains(
                $esperado,
                $nombres,
                'El endpoint de lectura dejo de devolver el estado "'.$esperado.'".'
            );
        }
    }

    /**
     * 5. Ninguna ruta declarada de `order-status` acepta un verbo de escritura.
     *
     * Se mira la tabla de rutas y no solo los códigos de respuesta: un no-2xx puede ser casualidad
     * de otra cosa, y lo que cierra el caso es que el verbo no esté declarado en ningún lado.
     *
     * @group pedidos
     * @test
     */
    public function ninguna_ruta_de_order_status_acepta_escritura()
    {
        /** Verbos que no tienen que existir para este recurso. */
        $verbos_de_escritura = ['POST', 'PUT', 'PATCH', 'DELETE'];

        $encontradas = [];

        foreach (Route::getRoutes() as $ruta) {

            /*
             * Se compara el primer segmento y no un `strpos`: `provider-order-status`,
             * `meli-order-status` y `tienda-nube-order-status` son recursos distintos y no entran
             * en esta regla.
             */
            $uri = $ruta->uri();

            if ($uri != 'api/order-status' && strpos($uri, 'api/order-status/') !== 0) {
                continue;
            }

            foreach ($ruta->methods() as $metodo) {
                if (in_array($metodo, $verbos_de_escritura)) {
                    $encontradas[] = $metodo.' '.$uri;
                }
            }
        }

        $this->assertEmpty(
            $encontradas,
            'Sigue habiendo rutas de escritura sobre order-status: '.implode(', ', $encontradas)
        );
    }

    /**
     * 6. Se listan en el orden de negocio (Sin confirmar, Confirmado, Terminado, Entregado,
     * Cancelado), no en el orden de `id`/`created_at`.
     *
     * 🔴 Regresión concreta: hasta el 24/8/2026 el endpoint ordenaba por `created_at DESC`. Como
     * los 5 estados se siembran casi en el mismo segundo, eso devolvía los cinco fuera de
     * secuencia (medido: Terminado, Entregado, Cancelado, Sin confirmar, Confirmado). Este test
     * sembrando en un orden distinto al de `self::$ESTADOS_PEDIDO` es lo que hace que el caso
     * pegue: si el endpoint volviera a depender de `id`/`created_at`, este test lo agarra.
     *
     * @group pedidos
     * @test
     */
    public function los_estados_se_listan_en_el_orden_de_negocio()
    {
        // Se pisa el fixture del setUp con uno sembrado en orden inverso, para que un eventual
        // regreso a "orderBy id/created_at" quede en evidencia y no pase de casualidad.
        OrderStatus::query()->delete();
        foreach (array_reverse(self::$ESTADOS_PEDIDO) as $nombre) {
            OrderStatus::create(['name' => $nombre]);
        }

        $response = $this->getJson('api/order-status');

        $response->assertStatus(200);

        $nombres = [];
        foreach ($response->json('models') as $modelo) {
            $nombres[] = $modelo['name'];
        }

        $this->assertEquals(
            self::$ESTADOS_PEDIDO,
            $nombres,
            'El endpoint de lectura no devolvio los estados en el orden de negocio.'
        );
    }

    /**
     * 7. `OrderStatusHelper::ORDEN_VISUAL` (el orden de display) no puede desincronizarse de
     * `OrderStatusHelper::AVANCE` (la maquina de estados que valida transiciones): los primeros
     * cuatro tienen que coincidir exactamente, y "Cancelado" tiene que ir al final.
     *
     * @group pedidos
     * @test
     */
    public function orden_visual_no_se_desincroniza_de_la_maquina_de_avance()
    {
        $this->assertEquals(
            OrderStatusHelper::AVANCE,
            array_slice(OrderStatusHelper::ORDEN_VISUAL, 0, 4),
            'ORDEN_VISUAL dejo de coincidir con AVANCE en sus primeros cuatro estados.'
        );

        $orden_visual = OrderStatusHelper::ORDEN_VISUAL;

        $this->assertEquals(
            OrderStatusHelper::CANCELADO,
            end($orden_visual),
            'ORDEN_VISUAL tiene que terminar en Cancelado.'
        );
    }
}
