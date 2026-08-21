<?php

namespace Tests\Feature\Puntos;

use App\Models\Sale;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Archivo 16 — 🔴 RESTAURAR UNA VENTA DESDE LA PAPELERA.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  EL AGUJERO QUE ESTE ARCHIVO CIERRA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `SaleController@destroy` deshace los DOS lados del módulo: le devuelve al cliente los
 *  puntos que canjeó en la venta y anula el lote que la venta le otorgó. Está bien.
 *
 *  `RestoreSaleFromPapeleraHelper::run()` no mencionaba ninguno de los dos. Una venta de
 *  CUENTA CORRIENTE zafaba de rebote —recrear el débito dispara `checkPagos()` y el
 *  reconciliador cuelga de ahí—, pero una venta de MOSTRADOR no pasa por ninguna cuenta
 *  corriente y volvía rota por los dos lados:
 *
 *      tras crear:     total=116000  puntos_canjeados=500  descuento_puntos=5000
 *      tras borrar:    (el cliente recupera sus puntos)
 *      tras restaurar: total=116000  puntos_canjeados=NULL descuento_puntos=NULL
 *
 *  O sea: la venta seguía valiendo $5.000 menos y ninguna columna del sistema explicaba por
 *  qué. El cliente se quedaba con los puntos Y con el descuento.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 LA DECISIÓN: RESTAURAR ES DEVOLVER LA VENTA COMO ESTABA, ASÍ QUE EL CANJE SE RE-APLICA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  De las dos salidas coherentes —re-cobrarle los puntos, o devolverle el descuento al total—
 *  se eligió la primera: es la única que deja la venta restaurada IDÉNTICA a la que el usuario
 *  vio en la papelera. Un botón que se llama "restaurar" y devuelve una venta con otro total
 *  está restaurando otra venta.
 *
 *  Y la segunda salida existe igual, para el caso en que re-aplicar no se puede: entre el
 *  borrado y la restauración el cliente pudo gastar esos puntos. Ahí no se le puede cobrar dos
 *  veces el mismo punto, así que el descuento vuelve al total y las columnas quedan en NULL.
 *  Lo único prohibido es el estado en el que el total y las columnas se contradicen, y eso es
 *  lo que miden los dos tests: cada uno cierra una de las dos salidas.
 */
class Restaurar_de_papelera_Test extends PuntosTestCase
{
    /** Total con IVA del renglón. Neto al 21%: $100.000, o sea 100 puntos ganados. */
    const TOTAL_BRUTO = 121000;

    /** Lo que se canjea en la venta: 500 puntos a $10 = $5.000 de descuento. */
    const PUNTOS_CANJE = 500;
    const DESCUENTO    = 5000;

    /** El saldo con el que arranca el cliente, sembrado a mano. */
    const SALDO_INICIAL = 1000;

    /**
     * Una venta de MOSTRADOR con canje, sobre un cliente que ya tenía saldo.
     *
     * Mostrador a propósito: es el caso que no se salva por rebote de la cuenta corriente, o
     * sea el que mide de verdad si el helper de la papelera hace su trabajo.
     *
     * @return array  [\App\Models\Client, \App\Models\Sale]
     */
    protected function escenario()
    {
        $this->dar_extencion();

        $sistema = $this->crear_programa(['vencimiento_meses' => 12]);

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $this->sembrar_lote($cliente, $sistema, self::SALDO_INICIAL, Carbon::now()->addMonths(6), Carbon::now()->subMonths(1));

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_BRUTO - self::DESCUENTO, [
            'omitir_en_cuenta_corriente' => 1,
            'puntos_canjeados'           => self::PUNTOS_CANJE,
            'descuento_puntos'           => self::DESCUENTO,
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $this->articulo_de_puntos()->id,
                    'price_vender' => self::TOTAL_BRUTO,
                    'amount'       => 1,
                ],
            ],
        ]));

        return [$cliente, $venta];
    }

    /**
     * @param  \App\Models\Sale  $venta
     * @return \Illuminate\Testing\TestResponse
     */
    protected function restaurar($venta)
    {
        return $this->putJson('api/papelera/restaurar/sale/'.$venta->id);
    }

    /**
     * @param  \App\Models\Sale  $venta
     * @return \App\Models\Sale
     */
    protected function releer($venta)
    {
        return Sale::withTrashed()->find($venta->id);
    }

    /**
     * 🔴 El camino normal: restaurar vuelve a cobrar el canje y a otorgar los puntos ganados.
     *
     * @group puntos
     * @test
     */
    public function restaurar_la_venta_vuelve_a_aplicar_el_canje_y_a_otorgar_los_puntos()
    {
        list($cliente, $venta) = $this->escenario();

        // 1000 iniciales - 500 canjeados + 100 ganados por la propia venta.
        $this->assertEqualsWithDelta(600, $this->saldo_de_puntos($cliente), self::DELTA, 'El escenario no arrancó como se esperaba.');
        $this->assertEqualsWithDelta(self::TOTAL_BRUTO - self::DESCUENTO, (float) $venta->total, self::DELTA);

        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $this->assertEqualsWithDelta(
            self::SALDO_INICIAL,
            $this->saldo_de_puntos($cliente),
            self::DELTA,
            'Al borrar, el cliente tiene que recuperar los puntos canjeados y perder los ganados.'
        );

        /*
         * 🔴 La venta se va a la papelera CONSERVANDO las dos columnas del canje. Es lo único
         * que queda del canje —el movimiento se borra de verdad— y sin eso no hay forma de
         * reconstruir por qué el total está $5.000 abajo.
         */
        $borrada = $this->releer($venta);

        $this->assertNotNull($borrada->deleted_at, 'La venta tiene que estar en la papelera.');
        $this->assertEqualsWithDelta(
            self::PUNTOS_CANJE,
            (float) $borrada->puntos_canjeados,
            self::DELTA,
            'La venta borrada tiene que conservar el registro de su canje: es lo que la hace restaurable.'
        );
        $this->assertEqualsWithDelta(self::DESCUENTO, (float) $borrada->descuento_puntos, self::DELTA);
        $this->assertEqualsWithDelta(
            self::TOTAL_BRUTO - self::DESCUENTO,
            (float) $borrada->total,
            self::DELTA,
            'El total de la venta borrada sigue neteado: el canje y el total tienen que contar la misma historia.'
        );

        $this->restaurar($venta)->assertStatus(200);

        $restaurada = $this->releer($venta);

        $this->assertNull($restaurada->deleted_at);

        $this->assertEqualsWithDelta(
            self::TOTAL_BRUTO - self::DESCUENTO,
            (float) $restaurada->total,
            self::DELTA,
            'Restaurar no puede cambiarle el total a la venta.'
        );
        $this->assertEqualsWithDelta(
            self::PUNTOS_CANJE,
            (float) $restaurada->puntos_canjeados,
            self::DELTA,
            'El total sigue neteado, así que las columnas que lo explican TIENEN que estar: si no, nada explica el descuento.'
        );
        $this->assertEqualsWithDelta(self::DESCUENTO, (float) $restaurada->descuento_puntos, self::DELTA);

        /*
         * Y los dos lados del libro de puntos, no solo las columnas.
         */
        $canjeados = $this->movimientos_de_la_venta($venta, 'canjeados');

        $this->assertCount(1, $canjeados, 'El canje tiene que volver a estar escrito, y una sola vez.');
        $this->assertEqualsWithDelta(-self::PUNTOS_CANJE, (float) $canjeados->first()->puntos, self::DELTA);

        $consumos = $this->consumos_de($canjeados->first());

        $this->assertGreaterThan(
            0,
            count($consumos),
            'El canje re-aplicado tiene que consumir lotes de verdad: un movimiento negativo sin consumos es el bug del doble descuento.'
        );

        $ganados = $this->movimientos_de_la_venta($venta, 'ganados');

        $this->assertCount(1, $ganados);
        $this->assertNull(
            $ganados->first()->anulado_at,
            'La venta volvió a existir: su lote tiene que revivir, no quedarse anulado.'
        );
        $this->assertCount(
            0,
            $this->movimientos_de_la_venta($venta, 'revertidos'),
            'Al revivir el lote, su reverso tiene que desaparecer: si no, restaría dos veces.'
        );

        $this->assertEqualsWithDelta(
            600,
            $this->saldo_de_puntos($cliente),
            self::DELTA,
            'El saldo tiene que volver a ser exactamente el de antes de borrar la venta.'
        );
    }

    /**
     * 🔴 La otra salida: si el cliente ya no tiene con qué pagar el canje, la venta vuelve al
     * total BRUTO y las columnas quedan en NULL.
     *
     * Lo que no puede pasar —y es lo que pasaba— es que la venta vuelva con el total neteado y
     * las columnas vacías: ahí el cliente se queda con los puntos y con el descuento.
     *
     * @group puntos
     * @test
     */
    public function si_el_cliente_ya_no_tiene_saldo_la_venta_restaurada_vuelve_al_total_bruto()
    {
        list($cliente, $venta) = $this->escenario();

        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $this->assertEqualsWithDelta(self::SALDO_INICIAL, $this->saldo_de_puntos($cliente), self::DELTA);

        /*
         * Entre el borrado y la restauración el cliente gasta todo su saldo. Por el endpoint
         * real, para que el ajuste consuma los lotes como lo haría en producción.
         */
        $this->postJson('api/puntos/ajuste', [
            'client_id' => $cliente->id,
            'puntos'    => -self::SALDO_INICIAL,
            'detalle'   => 'El cliente gastó los puntos en otro lado (test)',
        ])->assertStatus(201);

        $this->assertEqualsWithDelta(0, $this->saldo_de_puntos($cliente), self::DELTA);

        $this->restaurar($venta)->assertStatus(200);

        $restaurada = $this->releer($venta);

        $this->assertNull(
            $restaurada->puntos_canjeados,
            'Sin saldo no se puede re-aplicar el canje, así que la venta no puede seguir diciendo que canjeó.'
        );
        $this->assertNull($restaurada->descuento_puntos);

        $this->assertEqualsWithDelta(
            self::TOTAL_BRUTO,
            (float) $restaurada->total,
            self::DELTA,
            'Si el descuento por puntos ya no corre, el total tiene que volver al bruto: el total y las columnas no pueden contradecirse.'
        );

        $this->assertCount(
            0,
            $this->movimientos_de_la_venta($venta, 'canjeados'),
            'No se puede escribir un canje que ningún lote respalda.'
        );

        /*
         * Los puntos GANADOS sí vuelven: la venta existe otra vez y su base no cambió (sale del
         * neto de los renglones, no de `sales.total`).
         */
        $this->assertEqualsWithDelta(
            100,
            $this->saldo_de_puntos($cliente),
            self::DELTA,
            'La venta restaurada vuelve a otorgar sus 100 puntos ganados sobre un saldo que había quedado en cero.'
        );
    }
}
