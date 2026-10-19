<?php

namespace Tests\Feature\Compras;

use App\Models\CurrentAcount;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Models\ProviderOrderAfipTicketIva;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Misión `compra-asistente-iva-total` (9/10/2026), ampliación después del chequeo — la bandera
 * `total_with_iva` cuando el request NO la manda.
 *
 * La SPA la manda siempre (1 en una compra nueva, lo guardado al editar). Los que no la mandan son el
 * asistente de WhatsApp y el MCP, que crean y editan compras por las acciones de pantalla
 * `POST api/provider-order` y `PUT api/provider-order/{provider_order}`
 * (CatalogoDeAccionesDePantallaIaHelper), donde la clave es opcional. Hasta esta misión:
 *
 *   - el alta sin la clave nacía con la bandera en NULL: NewProviderOrderHelper::suma_iva_al_total()
 *     no le sumaba el IVA y la deuda con el proveedor quedaba NETA;
 *   - la edición sin la clave le APAGABA la bandera a una compra del formulario.
 *
 * Criterio (decisión de Lucas, 9/10/2026), el mismo que `factura-compra-tres-defectos` aplicó al modo
 * de facturación en esos dos métodos:
 *
 *   1. Alta sin la clave o con null → 1, como una compra nueva del formulario. Un 0 o un 1
 *      explícito se respeta.
 *   2. Edición sin la clave o con null → conserva la bandera que la compra ya tenía. Un valor
 *      explícito se respeta.
 *   3. De punta a punta de la plata: la compra creada sin la clave, en RRII, deja el IVA de su
 *      factura sumado al total y a la deuda; y una edición sin la clave no se lo saca.
 *   4. La normalización del valor, SIN el middleware: el asistente y el MCP ejecutan la acción con
 *      `$ruta->run()` (EjecutorAccionDePantallaIaHelper) y un `""` o un `"false"` llegan crudos.
 *
 * Escenario: proveedor Rosario (sin bonificaciones de catálogo), sin tocar precios ni stock, un solo
 * artículo de 1000 al 21% — el mismo de Factura_De_Compra_Total_Y_Percepciones_Test.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group compras
 */
class Total_Con_Iva_Sin_La_Clave_Test extends ComprasTestCase
{
    /** Tolerancia de las comparaciones de plata (las columnas son decimal(x,2)). */
    const DELTA = 0.01;

    /**
     * Valor que significa "no mandar la clave" en los proveedores de datos (un null es un valor
     * distinto: la clave viaja con null).
     */
    const SIN_LA_CLAVE = '__sin_la_clave__';

    /** @var array<int,int> Compras creadas por el test, para la limpieza. */
    protected $compras_creadas = [];

    /**
     * Payload del escenario, con `total_with_iva` pisado. Con `SIN_LA_CLAVE` la clave no viaja.
     *
     * @param  mixed  $total_with_iva
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    protected function payload_con_total_with_iva($total_with_iva, $overrides = [])
    {
        $payload = $this->payload_compra(array_merge([
            'provider_id'             => $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO)->id,
            'modo_facturacion'        => 'manual',
            'update_prices'           => 0,
            'update_stock'            => 0,
            'generate_current_acount' => 0,
            'articles'                => [
                $this->item('Marco para cama', 1000, 1),
            ],
        ], $overrides));

        if ($total_with_iva === self::SIN_LA_CLAVE) {
            unset($payload['total_with_iva']);
        } else {
            $payload['total_with_iva'] = $total_with_iva;
        }

        return $payload;
    }

    /**
     * Crea una compra por el endpoint real y la registra para la limpieza.
     *
     * @param  mixed  $total_with_iva
     * @param  array<string,mixed>  $overrides
     * @return \App\Models\ProviderOrder
     */
    protected function crear_compra($total_with_iva, $overrides = [])
    {
        $response = $this->postJson('api/provider-order', $this->payload_con_total_with_iva($total_with_iva, $overrides));

        $response->assertStatus(201);

        $compra_id = $response->json('model.id');

        $this->compras_creadas[] = $compra_id;

        return ProviderOrder::find($compra_id);
    }

    /**
     * Vuelve a guardar la compra por el endpoint real, con el payload entero del escenario: el
     * controller no es un PATCH parcial, y así lo único que cambia entre el alta y la edición es
     * `total_with_iva`. La acción de pantalla del asistente NO hace esto: manda solo las claves que
     * elige el modelo, y por eso lo que se mide acá es la clave ausente (o null), que es su caso.
     *
     * @param  \App\Models\ProviderOrder  $compra
     * @param  mixed  $total_with_iva
     * @param  array<string,mixed>  $overrides
     * @return \App\Models\ProviderOrder
     */
    protected function editar_compra($compra, $total_with_iva, $overrides = [])
    {
        $this->putJson('api/provider-order/'.$compra->id, $this->payload_con_total_with_iva($total_with_iva, $overrides))
                ->assertStatus(200);

        return $compra->fresh();
    }

    /**
     * Borra todo lo que crearon los tests: facturas, alícuotas, movimientos de cuenta corriente y
     * compras. Los saldos de cuenta corriente son acumulativos (ver el docblock de ComprasTestCase).
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->compras_creadas as $compra_id) {

            $ids = ProviderOrderAfipTicket::where('provider_order_id', $compra_id)->pluck('id')->all();

            ProviderOrderAfipTicketIva::whereIn('provider_order_afip_ticket_id', $ids)->delete();
            ProviderOrderAfipTicket::whereIn('id', $ids)->delete();
            CurrentAcount::where('provider_order_id', $compra_id)->delete();
            ProviderOrder::where('id', $compra_id)->delete();
        }

        $this->compras_creadas = [];

        parent::tearDown();
    }

    /**
     * [lo que manda el request, la bandera con la que tiene que nacer la compra]
     *
     * @return array<string,array<int,mixed>>
     */
    public function altas()
    {
        return [
            'sin la clave (acción de pantalla del asistente)' => [self::SIN_LA_CLAVE, 1],
            'null'                                             => [null, 1],
            '0 explícito'                                      => [0, 0],
            '1 explícito (la SPA)'                             => [1, 1],
        ];
    }

    /**
     * Test 1 — El alta: sin la clave o con null nace en 1; un valor explícito se respeta.
     *
     * @dataProvider altas
     * @group compras
     * @test
     *
     * @param  mixed  $enviado
     * @param  int    $esperado
     */
    public function el_alta_sin_la_clave_nace_sumando_el_iva_y_un_valor_explicito_se_respeta($enviado, $esperado)
    {
        $compra = $this->crear_compra($enviado);

        $this->assertSame(
            $esperado,
            is_null($compra->total_with_iva) ? null : (int) $compra->total_with_iva,
            'Alta con total_with_iva '.var_export($enviado, true).': la compra tiene que nacer en '.$esperado.'.'
        );
    }

    /**
     * [con qué bandera nace la compra, lo que manda la edición, la bandera con la que tiene que quedar]
     *
     * @return array<string,array<int,mixed>>
     */
    public function ediciones()
    {
        return [
            'compra en 1, edición sin la clave' => [1, self::SIN_LA_CLAVE, 1],
            'compra en 1, edición con null'     => [1, null, 1],
            'compra en 1, edición con 0'        => [1, 0, 0],
            'compra en 0, edición sin la clave' => [0, self::SIN_LA_CLAVE, 0],
            'compra en 0, edición con 1'        => [0, 1, 1],
        ];
    }

    /**
     * Test 2 — La edición: sin la clave o con null conserva la bandera de la compra; un valor
     * explícito se respeta.
     *
     * @dataProvider ediciones
     * @group compras
     * @test
     *
     * @param  int    $inicial
     * @param  mixed  $enviado
     * @param  int    $esperado
     */
    public function la_edicion_sin_la_clave_conserva_la_bandera_y_un_valor_explicito_se_respeta($inicial, $enviado, $esperado)
    {
        $compra = $this->crear_compra($inicial);

        $this->assertSame($inicial, (int) $compra->total_with_iva, 'Punto de partida.');

        $compra = $this->editar_compra($compra, $enviado);

        $this->assertSame(
            $esperado,
            is_null($compra->total_with_iva) ? null : (int) $compra->total_with_iva,
            'Edición con total_with_iva '.var_export($enviado, true).' sobre una compra en '.$inicial.': tiene que quedar en '.$esperado.'.'
        );
    }

    /**
     * Test 3 — 🔴 De punta a punta de la plata: una compra creada SIN la clave, en RRII, con su
     * factura (la automática: un artículo de 1000 al 21%, IVA 210) deja el total y la deuda con el
     * proveedor CON el IVA sumado. Y una edición sin la clave —la acción de pantalla del asistente—
     * no se lo saca.
     *
     * @group compras
     * @test
     */
    public function una_compra_creada_sin_la_clave_deja_el_iva_en_el_total_y_en_la_deuda()
    {
        $this->set_condicion_iva('RRII');

        $overrides = [
            'modo_facturacion'                       => 'automatico',
            'total_from_provider_order_afip_tickets' => 0,
            'generate_current_acount'                => 1,
        ];

        $compra = $this->crear_compra(self::SIN_LA_CLAVE, $overrides);

        $this->assertEqualsWithDelta(210, (float) $compra->total_iva, self::DELTA, 'El IVA de la factura queda en la compra.');

        $this->assertEqualsWithDelta(
            1210,
            (float) $compra->total,
            self::DELTA,
            'Total = $1.000 del artículo + $210 de IVA de la factura.'
        );

        $current_acount = CurrentAcount::where('provider_order_id', $compra->id)->first();

        $this->assertNotNull($current_acount, 'Con generate_current_acount en 1 la compra deja su movimiento.');

        $this->assertEqualsWithDelta(
            1210,
            (float) $current_acount->debe,
            self::DELTA,
            'La deuda con el proveedor es el total CON el IVA.'
        );

        // La edición sin la clave (la acción de pantalla del asistente) no le saca el IVA.
        $compra = $this->editar_compra($compra, self::SIN_LA_CLAVE, $overrides);

        $this->assertEqualsWithDelta(
            1210,
            (float) $compra->total,
            self::DELTA,
            'Volver a guardar sin la clave no puede sacarle el IVA al total.'
        );

        $this->assertEqualsWithDelta(
            1210,
            (float) $current_acount->fresh()->debe,
            self::DELTA,
            'Ni a la deuda con el proveedor.'
        );
    }

    /**
     * Test 4 — 🔴 La normalización del valor, llamando al método directo. Por HTTP un `""` ya llega
     * en null (ConvertEmptyStringsToNull) y el caso no se ve; pero el asistente y el MCP ejecutan la
     * acción de pantalla con `$ruta->run()`, que NO pasa por el middleware global, y ahí llegan
     * crudos. Con un `$valor ? 1 : 0` a secas, `""` daba 0 (la compra nacía sin IVA o la edición le
     * apagaba la bandera) y `"false"` daba 1. Mismo criterio que ModoFacturacionHelper::normalizar():
     * un vacío es "no vino".
     *
     * @group compras
     * @test
     */
    public function la_bandera_se_normaliza_igual_sin_pasar_por_el_middleware()
    {
        $metodo = new \ReflectionMethod(\App\Http\Controllers\ProviderOrderController::class, 'total_with_iva_pedido');
        $metodo->setAccessible(true);

        // [lo que llega, lo que tiene que dar]
        $casos = [
            [null, null],
            ['', null],
            ['  ', null],
            ['null', null],
            ['0', 0],
            ['1', 1],
            ['false', 0],
            ['true', 1],
            [0, 0],
            [1, 1],
            [false, 0],
            [true, 1],
        ];

        foreach ($casos as $caso) {

            $this->assertSame(
                $caso[1],
                $metodo->invoke(null, $caso[0]),
                'total_with_iva = '.var_export($caso[0], true).' tiene que dar '.var_export($caso[1], true).'.'
            );
        }
    }
}
