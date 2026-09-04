<?php

namespace Tests\Feature\Puntos;

use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Archivo 15 — 🔴 EL SALDO DISPONIBLE Y LA VENTANA ENTRE QUE UN LOTE VENCE Y EL CRON LO BARRE.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  EL AGUJERO QUE ESTE ARCHIVO CIERRA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `puntos:vencer` corre una vez por día. Entre que un lote pasa su `vence_at` y el barrido
 *  del día siguiente hay una ventana de hasta 24 horas en la que el lote está VENCIDO pero la
 *  fila negativa que lo mata TODAVÍA NO ESTÁ ESCRITA.
 *
 *  Adentro de esa ventana, el módulo contestaba dos cosas distintas a la misma pregunta:
 *
 *    - la VALIDACIÓN del canje medía contra `SUM(movimiento_puntos.puntos)`, que incluye el
 *      lote vencido;
 *    - el CONSUMO del canje usa `lotes_para_canje()`, que lo excluye.
 *
 *  Resultado medido: el canje pasaba la validación, escribía el movimiento negativo, NO
 *  consumía un solo lote, y después `puntos:vencer` vencía el lote entero. El mismo punto se
 *  descontaba dos veces y el saldo del cliente quedaba NEGATIVO. Y de paso, la ficha del
 *  cliente y VENDER le mostraban como disponibles puntos que ya estaban muertos.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 LA PROPIEDAD QUE DEFINE QUE ESTÉ BIEN: EL SALDO DISPONIBLE NO SE MUEVE CUANDO PASA EL CRON
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `puntos:vencer` no es un hecho económico: es un proceso que escribe lo que ya era cierto.
 *  Si el número que ve el cliente cambia según si el cron ya corrió o no, el número está mal.
 *  Eso es lo que mide el segundo test, y es la aserción que ninguna otra suite hace.
 *
 *  ⚠️ Son DOS preguntas distintas y por eso hay dos frases:
 *    - `PuntosSaldoHelper::saldo()`        -> saldo DISPONIBLE (VENDER, la ficha, el canje);
 *    - `PuntoController::saldo_vivo()`     -> el PASIVO contable del reporte, que sigue siendo
 *                                             `SUM(puntos)` de todo el libro hasta una fecha.
 *  El archivo 10 mide la segunda. Este mide la primera.
 */
class Saldo_disponible_y_vencidos_Test extends PuntosTestCase
{
    /** Total con IVA del renglón de la venta con canje. Neto al 21%: $100.000. */
    const TOTAL_BRUTO = 121000;

    /**
     * Payload de una venta de MOSTRADOR que intenta canjear puntos.
     *
     * El total viaja ya neteado por el canje, que es como lo manda VENDER.
     *
     * @param  int    $client_id
     * @param  float  $puntos
     * @param  float  $descuento
     * @return array
     */
    protected function payload_con_canje($client_id, $puntos, $descuento)
    {
        return $this->payload_venta($client_id, self::TOTAL_BRUTO - $descuento, [
            'omitir_en_cuenta_corriente' => 1,
            'puntos_canjeados'           => $puntos,
            'descuento_puntos'           => $descuento,
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $this->articulo_de_puntos()->id,
                    'price_vender' => self::TOTAL_BRUTO,
                    'amount'       => 1,
                ],
            ],
        ]);
    }

    /**
     * 🔴 Un lote VENCIDO con el barrido todavía sin correr no se puede canjear.
     *
     * Sin el arreglo: 422 nunca, canje escrito, cero consumos, y el saldo en -418 después del
     * cron. Con el arreglo: 422 en la puerta y el saldo queda donde tiene que quedar.
     *
     * @group puntos
     * @test
     */
    public function no_se_pueden_canjear_puntos_ya_vencidos_aunque_el_barrido_no_haya_pasado()
    {
        $this->dar_extencion();

        $sistema = $this->crear_programa(['vencimiento_meses' => 12]);

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        // Un único lote, ya vencido ayer, y el cron todavía no pasó.
        $this->sembrar_lote($cliente, $sistema, 1000, Carbon::now()->subDay(), Carbon::now()->subMonths(13));

        $this->assertEqualsWithDelta(
            1000,
            $this->saldo_de_puntos($cliente),
            self::DELTA,
            'El libro todavía tiene los 1000 puntos: la fila negativa del vencimiento no se escribió.'
        );

        $response = $this->postear_venta($this->payload_con_canje($cliente->id, 500, 5000));

        $response->assertStatus(
            422,
            'Un canje contra un lote vencido tiene que rechazarse: el FIFO no tiene de dónde sacar los puntos.'
        );

        $cuerpo = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('error_canje_puntos', $cuerpo, 'El 422 tiene que traer la bandera propia del canje.');

        $this->assertCount(
            0,
            $this->movimientos_del_cliente($cliente, 'canjeados'),
            'Un canje rechazado no puede haber dejado escrito el movimiento negativo.'
        );

        /*
         * 🔴 La mitad que hacía el daño: sin el arreglo, acá el cron venía y vencía el lote
         * entero encima del canje que ya lo había descontado, y el saldo terminaba en negativo.
         */
        $this->artisan('puntos:vencer')->assertExitCode(0);

        $this->assertEqualsWithDelta(
            0,
            $this->saldo_de_puntos($cliente),
            self::DELTA,
            'Después del barrido el saldo tiene que quedar en cero, nunca en negativo: el punto se descuenta UNA vez.'
        );
    }

    /**
     * 🔴 El saldo que se le muestra al cliente NO cambia porque pase el cron.
     *
     * Con un lote vencido de 1000 y uno vigente de 300, VENDER y la ficha tienen que decir 300
     * antes y después del barrido. El único número que se mueve es el del libro (el pasivo),
     * que baja de 1300 a 300 cuando se escribe la fila negativa.
     *
     * @group puntos
     * @test
     */
    public function el_saldo_disponible_no_cuenta_los_vencidos_y_no_se_mueve_cuando_corre_el_cron()
    {
        $this->dar_extencion();

        $sistema = $this->crear_programa(['vencimiento_meses' => 12]);

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $this->sembrar_lote($cliente, $sistema, 1000, Carbon::now()->subDay(), Carbon::now()->subMonths(13));
        $this->sembrar_lote($cliente, $sistema, 300, Carbon::now()->addMonths(6), Carbon::now()->subMonths(6));

        $this->assertEqualsWithDelta(
            1300,
            $this->saldo_de_puntos($cliente),
            self::DELTA,
            'El libro arranca con los 1300: el vencido todavía no tiene su fila negativa.'
        );

        $disponible_antes = json_decode($this->getJson('api/puntos/disponible/'.$cliente->id)->getContent(), true);
        $ficha_antes      = json_decode($this->getJson('api/puntos/cliente/'.$cliente->id)->getContent(), true);

        $this->assertEqualsWithDelta(
            300,
            $disponible_antes['saldo'],
            self::DELTA,
            'VENDER no puede ofrecerle al vendedor puntos que ya vencieron.'
        );
        $this->assertEqualsWithDelta(
            300,
            $ficha_antes['saldo'],
            self::DELTA,
            'La ficha del cliente no puede mostrar como disponibles puntos que ya vencieron.'
        );

        $this->artisan('puntos:vencer')->assertExitCode(0);

        $disponible_despues = json_decode($this->getJson('api/puntos/disponible/'.$cliente->id)->getContent(), true);
        $ficha_despues      = json_decode($this->getJson('api/puntos/cliente/'.$cliente->id)->getContent(), true);

        $this->assertEqualsWithDelta(
            300,
            $disponible_despues['saldo'],
            self::DELTA,
            'El barrido escribe lo que ya era cierto: el saldo disponible no puede moverse por su culpa.'
        );
        $this->assertEqualsWithDelta(
            300,
            $ficha_despues['saldo'],
            self::DELTA,
            'Idem para la ficha del cliente.'
        );

        $this->assertEqualsWithDelta(
            300,
            $this->saldo_de_puntos($cliente),
            self::DELTA,
            'El libro sí se mueve: ahora tiene la fila negativa del vencimiento y coincide con el disponible.'
        );
    }
}
