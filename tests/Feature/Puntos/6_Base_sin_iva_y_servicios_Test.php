<?php

namespace Tests\Feature\Puntos;

use App\Models\Combo;
use App\Models\Service;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Archivo 6 — de qué está hecho el MONTO BASE.
 *
 * La spec dice "sobre el subtotal, sin impuestos". El subtotal del sistema es la suma de los
 * renglones con su descuento de línea, y "sin impuestos" es el NETO SIN IVA, que
 * `SaleHelper::attachArticle()` congela en `article_sale.price_sin_iva`. Se prefiere siempre esa
 * columna y no se recalcula: es el valor del momento de la venta, y el IVA de un artículo cambia
 * con el tiempo (una venta de hace dos años no se recalcula con el IVA de hoy).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 `article_sale.iva_percentage` ES `varchar(20)` Y GUARDA ETIQUETAS FISCALES
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Guarda 'Exento' y 'No Gravado' además de números. Tratarla como número la rompe: es literalmente
 *  la columna que originó la clase de error del bloqueante de esta misión. Un artículo Exento tiene
 *  que dar base = precio, no precio / (1 + 'Exento'/100).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 SOLO ACUMULAN LOS RENGLONES DE `article_sale`
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Servicios y combos NO suman, y no es una omisión: sus pivots no tienen `iva_percentage` ni
 *  `price_sin_iva` ni `price_type_personalizado_id`, así que ni se les puede sacar el IVA con datos
 *  congelados ni se sabe con qué lista se vendieron. Si algún día tienen que sumar, lo primero es
 *  agregarles esas columnas al pivot, no parchear el helper para que adivine.
 */
class Base_sin_iva_y_servicios_Test extends PuntosTestCase
{
    /**
     * La base sale del NETO, no del precio con IVA. Con el artículo al 21% y un total de $121.000,
     * la base es $100.000 (100 puntos) y no $121.000 (121 puntos).
     *
     * @group puntos
     * @test
     */
    public function la_base_sale_del_neto_sin_iva_y_no_del_precio_con_iva()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, 121000, [
            'omitir_en_cuenta_corriente' => 1,
        ]));

        $pivot = DB::table('article_sale')->where('sale_id', $venta->id)->first();

        $this->assertNotNull($pivot, 'La venta tiene que tener su renglón.');
        $this->assertEqualsWithDelta(
            100000,
            (float) $pivot->price_sin_iva,
            self::DELTA,
            'El pivot no guardó el neto esperado: el resto del test no mediría lo que dice medir.'
        );

        $movimiento = $this->movimientos_de_la_venta($venta, 'ganados')->first();

        $this->assertNotNull($movimiento);
        $this->assertEqualsWithDelta(100000, (float) $movimiento->monto_base, self::DELTA);
        $this->assertEqualsWithDelta(
            100,
            (float) $movimiento->puntos,
            self::DELTA,
            'Los puntos salieron del total con IVA en vez del neto.'
        );
    }

    /**
     * 🔴 Con `sales.iva_aplicado = 0` los precios de la venta YA son netos, y volver a dividir sería
     * restar el IVA dos veces. La base tiene que ser el `price` tal cual.
     *
     * @group puntos
     * @test
     */
    public function con_iva_aplicado_en_cero_la_base_es_el_precio_tal_cual()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, 100000, [
            'omitir_en_cuenta_corriente' => 1,
            'iva_aplicado'               => 0,
        ]));

        $this->assertEquals(0, (int) $venta->iva_aplicado, 'La venta tiene que haber quedado con iva_aplicado = 0.');

        $movimiento = $this->movimientos_de_la_venta($venta, 'ganados')->first();

        $this->assertNotNull($movimiento);
        $this->assertEqualsWithDelta(
            100000,
            (float) $movimiento->monto_base,
            self::DELTA,
            'Con iva_aplicado = 0 el precio ya es neto: dividirlo otra vez resta el IVA dos veces.'
        );
        $this->assertEqualsWithDelta(100, (float) $movimiento->puntos, self::DELTA);
    }

    /**
     * 🔴 Un artículo EXENTO no se divide. `article_sale.iva_percentage` guarda la etiqueta fiscal
     * 'Exento' como texto, y `(float) 'Exento'` es 0: tratarla como número daría una división por
     * 1 (0/100 + 1), que por casualidad da bien, pero la etiqueta 'No Gravado' y cualquier otra
     * futura romperían igual. La regla explícita es que esas etiquetas devuelven el precio tal cual.
     *
     * El artículo 'Cuchara' del fixture tiene `iva_id = 7`, que es la fila 'Exento' de `ivas`.
     *
     * @group puntos
     * @test
     */
    public function un_articulo_exento_no_se_divide_por_su_etiqueta_fiscal()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $exento = $this->articulo_de_puntos('Cuchara');

        $venta = $this->crear_venta($this->payload_venta($cliente->id, 100000, [
            'omitir_en_cuenta_corriente' => 1,
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $exento->id,
                    'price_vender' => 100000,
                    'amount'       => 1,
                ],
            ],
        ]));

        $pivot = DB::table('article_sale')->where('sale_id', $venta->id)->first();

        $this->assertEquals(
            'Exento',
            $pivot->iva_percentage,
            'El pivot tiene que haber congelado la etiqueta fiscal "Exento" (si no, este test mide otra cosa).'
        );

        $movimiento = $this->movimientos_de_la_venta($venta, 'ganados')->first();

        $this->assertNotNull($movimiento);
        $this->assertEqualsWithDelta(
            100000,
            (float) $movimiento->monto_base,
            self::DELTA,
            'Un artículo exento no lleva IVA: la base es el precio tal cual.'
        );
        $this->assertEqualsWithDelta(100, (float) $movimiento->puntos, self::DELTA);
    }

    /**
     * Un artículo con IVA al 10,5% ('Cuchilla', `iva_id = 3`) se netea con SU alícuota, no con el
     * 21 por default.
     *
     * @group puntos
     * @test
     */
    public function un_articulo_al_diez_y_medio_se_netea_con_su_propia_alicuota()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $articulo = $this->articulo_de_puntos('Cuchilla');

        // 110.500 / 1,105 = 100.000 exactos.
        $venta = $this->crear_venta($this->payload_venta($cliente->id, 110500, [
            'omitir_en_cuenta_corriente' => 1,
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $articulo->id,
                    'price_vender' => 110500,
                    'amount'       => 1,
                ],
            ],
        ]));

        $movimiento = $this->movimientos_de_la_venta($venta, 'ganados')->first();

        $this->assertNotNull($movimiento);
        $this->assertEqualsWithDelta(100000, (float) $movimiento->monto_base, self::DELTA);
        $this->assertEqualsWithDelta(100, (float) $movimiento->puntos, self::DELTA);
    }

    /**
     * El `discount` del renglón SÍ baja la base: es un porcentaje sobre esa línea y forma parte del
     * subtotal del sistema (`SaleHelper::getTotalItem()` hace exactamente lo mismo).
     *
     * @group puntos
     * @test
     */
    public function el_descuento_del_renglon_baja_la_base()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, 121000, [
            'omitir_en_cuenta_corriente' => 1,
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $this->articulo_de_puntos()->id,
                    'price_vender' => 121000,
                    'amount'       => 1,
                    'discount'     => 10,
                ],
            ],
        ]));

        $movimiento = $this->movimientos_de_la_venta($venta, 'ganados')->first();

        $this->assertNotNull($movimiento);
        $this->assertEqualsWithDelta(
            90000,
            (float) $movimiento->monto_base,
            self::DELTA,
            'El 10% de descuento del renglón tiene que bajar la base de $100.000 a $90.000.'
        );
        $this->assertEqualsWithDelta(90, (float) $movimiento->puntos, self::DELTA);
    }

    /**
     * Las unidades cuentan: dos unidades del mismo artículo dan el doble de base.
     *
     * @group puntos
     * @test
     */
    public function las_unidades_multiplican_la_base()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, 121000, [
            'omitir_en_cuenta_corriente' => 1,
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $this->articulo_de_puntos()->id,
                    'price_vender' => 60500,
                    'amount'       => 2,
                ],
            ],
        ]));

        $movimiento = $this->movimientos_de_la_venta($venta, 'ganados')->first();

        $this->assertNotNull($movimiento);
        $this->assertEqualsWithDelta(100000, (float) $movimiento->monto_base, self::DELTA);
        $this->assertEqualsWithDelta(100, (float) $movimiento->puntos, self::DELTA);
    }

    /**
     * 🔴 El redondeo es PARA ABAJO. Una compra de neto $999 con la tasa "1 punto cada $1.000" no
     * otorga nada, y una de $1.999 otorga uno solo. Redondear para arriba haría que el cliente que
     * compre un peso menos la próxima vez pregunte por qué.
     *
     * @group puntos
     * @test
     */
    public function los_puntos_se_redondean_para_abajo()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        // Neto $1.999,17 -> un solo tramo de $1.000 -> 1 punto (no 2).
        $venta = $this->crear_venta($this->payload_venta($cliente->id, 2419, [
            'omitir_en_cuenta_corriente' => 1,
        ]));

        $movimiento = $this->movimientos_de_la_venta($venta, 'ganados')->first();

        $this->assertNotNull($movimiento, 'Con neto $1.999 tiene que otorgarse 1 punto.');
        $this->assertEqualsWithDelta(
            1,
            (float) $movimiento->puntos,
            self::DELTA,
            'El redondeo tiene que ser para abajo: $1.999 de base es UN tramo de $1.000.'
        );
    }

    /**
     * Y por debajo del primer tramo no se emite NADA: ni un movimiento de 0 puntos, que sería ruido
     * en la ficha del cliente y ocuparía al pedo un lugar del unique.
     *
     * @group puntos
     * @test
     */
    public function por_debajo_del_primer_tramo_no_se_emite_ningun_movimiento()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        // Neto $826,45: no llega al primer tramo de $1.000.
        $venta = $this->crear_venta($this->payload_venta($cliente->id, 1000, [
            'omitir_en_cuenta_corriente' => 1,
        ]));

        $this->assertCount(
            0,
            $this->movimientos_de_la_venta($venta),
            'Una venta que no llega al primer tramo no puede dejar un movimiento de 0 puntos.'
        );
    }

    /**
     * 🔴 Un SERVICIO en la misma venta no aporta un peso a la base.
     *
     * El pivot `sale_service` no tiene `iva_percentage`, `price_sin_iva` ni
     * `price_type_personalizado_id`: no se le puede sacar el IVA con datos congelados ni se sabe
     * con qué lista se vendió. Que no sume es la única decisión posible con el schema que hay, y
     * este test la deja clavada para que nadie la "arregle" adivinando.
     *
     * @group puntos
     * @test
     */
    public function un_servicio_en_la_venta_no_aporta_a_la_base()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $servicio = Service::create([
            'name'    => 'Servicio de prueba (puntos)',
            'price'   => 121000,
            'user_id' => $this->comercio()->id,
        ]);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, 242000, [
            'omitir_en_cuenta_corriente' => 1,
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $this->articulo_de_puntos()->id,
                    'price_vender' => 121000,
                    'amount'       => 1,
                ],
                [
                    'is_service'   => true,
                    'id'           => $servicio->id,
                    'price_vender' => 121000,
                    'amount'       => 1,
                ],
            ],
        ]));

        $this->assertEquals(1, $venta->services()->count(), 'El servicio tiene que haber quedado enganchado a la venta.');

        $ganados = $this->movimientos_de_la_venta($venta, 'ganados');

        $this->assertCount(1, $ganados);
        $this->assertEqualsWithDelta(
            100000,
            (float) $ganados->first()->monto_base,
            self::DELTA,
            'El servicio no puede aportar a la base: solo suman los renglones de article_sale.'
        );
        $this->assertEqualsWithDelta(100, (float) $ganados->first()->puntos, self::DELTA);
    }

    /**
     * Un COMBO tampoco. Mismo motivo que el servicio: `combo_sale` no tiene con qué netear ni con
     * qué lista se vendió.
     *
     * @group puntos
     * @test
     */
    public function un_combo_en_la_venta_no_aporta_a_la_base()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $combo = Combo::create([
            'num'     => 9001,
            'name'    => 'Combo de prueba (puntos)',
            'price'   => 121000,
            'cost'    => 50000,
            'user_id' => $this->comercio()->id,
        ]);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, 242000, [
            'omitir_en_cuenta_corriente' => 1,
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $this->articulo_de_puntos()->id,
                    'price_vender' => 121000,
                    'amount'       => 1,
                ],
                [
                    'is_combo'     => true,
                    'id'           => $combo->id,
                    'name'         => $combo->name,
                    'price_vender' => 121000,
                    'amount'       => 1,
                    /*
                     * La clave `articles` va sí o sí: `ComboHelper::discount_articles_stock()` la
                     * recorre sin `isset` y sin ella el POST de la venta revienta con
                     * "Undefined index: articles". Va con un artículo de verdad para que el camino
                     * que corre sea el mismo que corre en producción.
                     */
                    'articles'     => [
                        [
                            'id'    => $this->articulo_de_puntos('Pinza')->id,
                            'pivot' => ['amount' => 1],
                        ],
                    ],
                ],
            ],
        ]));

        $this->assertEquals(1, $venta->combos()->count(), 'El combo tiene que haber quedado enganchado a la venta.');

        $ganados = $this->movimientos_de_la_venta($venta, 'ganados');

        $this->assertCount(1, $ganados);
        $this->assertEqualsWithDelta(
            100000,
            (float) $ganados->first()->monto_base,
            self::DELTA,
            'El combo no puede aportar a la base: solo suman los renglones de article_sale.'
        );
    }
}
