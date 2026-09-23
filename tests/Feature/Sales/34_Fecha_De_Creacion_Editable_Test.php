<?php

namespace Tests\Feature\Sales;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Article;
use App\Models\PriceType;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Misión fecha-creacion-editable (22/9/2026): el usuario elige la fecha de creación de la venta y
 * esa fecha se guarda en `created_at`. Por defecto, el día de hoy.
 *
 * Lo que fijan estos tests son las tres decisiones que no son obvias y que, si alguien las
 * "simplifica" más adelante, rompen cosas que hoy funcionan:
 *
 *  1. LA FECHA SE GUARDA CON LA HORA ACTUAL, no a medianoche. Un `<input type="date">` manda solo
 *     `YYYY-MM-DD`; guardarlo tal cual dejaría todas las ventas del día a las `00:00:00` y eso
 *     rompe el cierre de caja por turno, la guarda anti-duplicados y el orden de los movimientos
 *     de cuenta corriente.
 *  2. LA GUARDA ANTI-DUPLICADOS SE ANCLA EN EL `created_at` QUE SE VA A GUARDAR, no en `now()`, y
 *     se cierra de los dos lados. Con la condición vieja, una fecha pasada devolvía el doble clic
 *     a crear dos ventas y una fecha futura hacía que se rechazaran ventas legítimas.
 *  3. SOLO SE ACEPTA `YYYY-MM-DD`. Un ISO con `Z` reasignado corre la fecha +3 horas (medido el
 *     21/9/2026), así que el resolvedor es una lista blanca y no un parseo libre.
 *
 * `DatabaseTransactions` y no `RefreshDatabase`: la base de testing del slot está sembrada de
 * antes. El artículo y la lista de precios se crean adentro de la transacción, con prefijo `zz`,
 * y cada test usa un total propio para no cruzarse con la guarda anti-duplicados de otro.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class Fecha_De_Creacion_Editable_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var int Usuario del fixture de testing. */
    const USER_ID = 500;

    /** @var int Tolerancia en segundos al comparar un instante guardado contra `now()`. */
    const TOLERANCIA = 120;

    /** @var \App\Models\User */
    protected $user;

    /** @var \App\Models\Article */
    protected $article;

    /** @var \App\Models\PriceType */
    protected $lista;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::find(self::USER_ID);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($this->user, 'web');

        $this->lista = PriceType::create([
            'name'     => 'zz Lista (fecha de creacion editable)',
            'user_id'  => self::USER_ID,
            'position' => 5,
        ]);

        /*
         * Sin stock a propósito: `SaleHelper::usa_stock()` da false con stock null y el alta no
         * descuenta nada. Acá se mide la fecha, no el stock.
         */
        $this->article = Article::create([
            'name'        => 'zz Articulo fecha de creacion editable',
            'user_id'     => self::USER_ID,
            'final_price' => 100,
            'status'      => 'active',
        ]);
    }

    /**
     * Payload de `POST api/sale` para una venta de mostrador, calcado de
     * `tests/Feature/Sales/33`: sin cliente, un renglón ya preciado y la lista explícita.
     *
     * El total viaja como parámetro porque es la clave que usa la guarda anti-duplicados: dos
     * tests con el mismo total podrían verse entre sí.
     *
     * @param  float  $total
     * @param  array  $overrides
     * @return array
     */
    protected function payload_venta($total, $overrides = [])
    {
        return array_merge([
            'client_id'                        => null,
            'address_id'                       => null,
            'save_current_acount'              => 0,
            'omitir_en_cuenta_corriente'       => 0,
            'to_check'                         => 0,
            'current_acount_payment_method_id' => null,
            'price_type_id'                    => $this->lista->id,
            'discounts_in_services'            => 1,
            'surchages_in_services'            => 1,
            'employee_id'                      => null,
            'sub_total'                        => $total,
            'total'                            => $total,
            'terminada'                        => 1,
            'seller_id'                        => null,
            'cantidad_cuotas'                  => null,
            'cuota_descuento'                  => 0,
            'cuota_recargo'                    => 0,
            'caja_id'                          => null,
            'afip_tipo_comprobante_id'         => null,
            'descuento'                        => null,
            'moneda_id'                        => 1,
            'discount_stock'                   => 0,
            'discounts'                        => [],
            'surchages'                        => [],
            'items'                            => [
                [
                    'is_article'   => true,
                    'id'           => $this->article->id,
                    'name'         => $this->article->name,
                    'price_vender' => $total,
                    'amount'       => 1,
                ],
            ],
        ], $overrides);
    }

    /**
     * Payload mínimo y válido de `PUT api/sale/{id}`, SIN la clave `created_at`: cada test decide
     * si viaja y con qué.
     *
     * @param  \App\Models\Sale  $sale
     * @param  array  $overrides
     * @return array
     */
    protected function payload_update($sale, $overrides = [])
    {
        return array_merge([
            'client_id'                  => null,
            'save_current_acount'        => 0,
            'omitir_en_cuenta_corriente' => 0,
            'to_check'                   => 0,
            'checked'                    => 0,
            'confirmed'                  => 0,
            'discounts_in_services'      => 1,
            'surchages_in_services'      => 1,
            'discount_stock'             => 0,
            'sub_total'                  => $sale->sub_total,
            'total'                      => $sale->total,
            'moneda_id'                  => 1,
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $this->article->id,
                    'name'         => $this->article->name,
                    'price_vender' => $sale->total,
                    'amount'       => 1,
                ],
            ],
            'discounts'                  => [],
            'surchages'                  => [],
            'returned_items'             => [],
        ], $overrides);
    }

    /**
     * Crea la venta por la API y devuelve el modelo recién guardado.
     *
     * El `assertStatus(201)` no es decorativo: la guarda anti-duplicados descarta en silencio con
     * un 200 y el cuerpo vacío, así que sin esto un test podría quedar en verde midiendo una venta
     * que no es la que acaba de crear.
     *
     * @param  float  $total
     * @param  array  $overrides
     * @return \App\Models\Sale
     */
    protected function crear_venta_por_api($total, $overrides = [])
    {
        $respuesta = $this->post('api/sale', $this->payload_venta($total, $overrides));

        $respuesta->assertStatus(201);

        return Sale::find($respuesta->json('model.id'));
    }

    /**
     * Una venta de mostrador ya guardada, editable (sin caja, sin métodos, sin comprobante), con
     * el `created_at` que pida el test.
     *
     * @param  float  $total
     * @param  string  $created_at
     * @return \App\Models\Sale
     */
    protected function venta_guardada($total, $created_at)
    {
        return Sale::create([
            'user_id'                    => self::USER_ID,
            'client_id'                  => null,
            'price_type_id'              => $this->lista->id,
            'omitir_en_cuenta_corriente' => 0,
            'save_current_acount'        => 0,
            'terminada'                  => 1,
            'is_cerrada'                 => 0,
            'discount_stock'             => 0,
            'moneda_id'                  => 1,
            'sub_total'                  => $total,
            'total'                      => $total,
            'created_at'                 => $created_at,
        ]);
    }

    /**
     * Segundos de diferencia (en valor absoluto) entre dos instantes.
     *
     * @param  \Carbon\Carbon  $uno
     * @param  \Carbon\Carbon  $otro
     * @return int
     */
    protected function distancia_en_segundos($uno, $otro)
    {
        return abs($uno->getTimestamp() - $otro->getTimestamp());
    }

    /**
     * Caso 1: sin la clave `created_at` —una SPA vieja, el asistente, cualquier POST que no hable
     * de la fecha— la venta se guarda con la fecha y la hora de hoy, exactamente como siempre.
     *
     * @group sales
     * @test
     * @return void
     */
    public function una_venta_sin_created_at_se_guarda_con_la_fecha_y_hora_de_hoy()
    {
        $ahora = Carbon::now();

        $venta = $this->crear_venta_por_api(1101);

        $this->assertSame(
            $ahora->format('Y-m-d'),
            $venta->created_at->format('Y-m-d'),
            'Una venta sin created_at tiene que quedar con la fecha de hoy.'
        );

        $this->assertLessThanOrEqual(
            self::TOLERANCIA,
            $this->distancia_en_segundos($ahora, $venta->created_at),
            'Una venta sin created_at tiene que quedar con la hora de ahora, no corrida.'
        );
    }

    /**
     * 🔴 Caso 2: con la fecha de HOY —que es lo que manda la SPA por defecto— el instante guardado
     * conserva la hora. Si alguien guardara el `YYYY-MM-DD` tal cual, esto quedaría en `00:00:00`
     * y el cierre de caja por turno dejaría de encontrar la venta.
     *
     * @group sales
     * @test
     * @return void
     */
    public function una_venta_con_la_fecha_de_hoy_conserva_la_hora_y_no_queda_a_medianoche()
    {
        $ahora = Carbon::now();

        $venta = $this->crear_venta_por_api(1102, ['created_at' => $ahora->format('Y-m-d')]);

        $this->assertSame(
            $ahora->format('Y-m-d'),
            $venta->created_at->format('Y-m-d'),
            'La venta tiene que quedar en el día elegido.'
        );

        $this->assertLessThanOrEqual(
            self::TOLERANCIA,
            $this->distancia_en_segundos($ahora, $venta->created_at),
            'La venta con la fecha de hoy quedó a medianoche (o con la hora corrida): el instante no es el de ahora.'
        );
    }

    /**
     * Caso 3: con una fecha PASADA, la venta se guarda ese día y con la hora actual.
     *
     * @group sales
     * @test
     * @return void
     */
    public function una_venta_con_fecha_pasada_se_guarda_ese_dia_con_la_hora_actual()
    {
        $ahora  = Carbon::now();
        $pasado = $ahora->copy()->subDays(20);

        $venta = $this->crear_venta_por_api(1103, ['created_at' => $pasado->format('Y-m-d')]);

        $this->assertSame(
            $pasado->format('Y-m-d'),
            $venta->created_at->format('Y-m-d'),
            'La venta no quedó en el día elegido.'
        );

        /*
         * La hora del día tiene que ser la de ahora. Se compara moviendo el instante guardado al
         * día de hoy: comparar los dos `H:i:s` como strings fallaría por un segundo de diferencia
         * entre que el test lee `now()` y el controlador lo lee de nuevo.
         */
        $misma_hora_hoy = $venta->created_at->copy()->setDate($ahora->year, $ahora->month, $ahora->day);

        $this->assertLessThanOrEqual(
            self::TOLERANCIA,
            $this->distancia_en_segundos($ahora, $misma_hora_hoy),
            'La venta con fecha pasada no conservó la hora actual: quedó a medianoche o con la hora corrida.'
        );
    }

    /**
     * 🔴 Caso 4, el más delicado de la misión: el doble clic con una fecha PASADA sigue creando
     * UNA SOLA venta.
     *
     * Con la condición vieja (`created_at >= now()-5s`), la venta recién creada quedaba fechada 20
     * días atrás, o sea muy fuera de esa ventana: la guarda no encontraba nada y el segundo clic
     * creaba una segunda venta, con el stock descontado dos veces, dos movimientos de caja y dos
     * comisiones. Es exactamente lo que avisa en mayúsculas la migración 2026_09_01_180000.
     *
     * @group sales
     * @test
     * @return void
     */
    public function el_doble_clic_con_fecha_pasada_crea_una_sola_venta()
    {
        $pasado = Carbon::now()->subDays(20)->format('Y-m-d');

        $payload = $this->payload_venta(1104, ['created_at' => $pasado]);

        $primera = $this->post('api/sale', $payload);
        $primera->assertStatus(201);

        $segunda = $this->post('api/sale', $payload);

        /*
         * El descarte de la guarda es un 200 con el cuerpo vacío (no un 4xx): así lo devuelve
         * `SaleController::store()` desde siempre y así lo lee la SPA.
         */
        $segunda->assertStatus(200);
        $this->assertSame('', $segunda->getContent(), 'El segundo clic creó una venta en vez de ser descartado.');

        $this->assertSame(
            1,
            Sale::where('user_id', self::USER_ID)->where('price_type_id', $this->lista->id)->count(),
            'El doble clic con fecha pasada creó DOS ventas: la guarda anti-duplicados dejó de funcionar.'
        );
    }

    /**
     * 🔴 Caso 5: una venta FUTURA guardada no puede hacer que se rechace una venta legítima
     * idéntica.
     *
     * Este es el otro extremo del defecto: `created_at >= now()-5s` está abierto por arriba, así
     * que cualquier venta con fecha futura del mismo cliente/empleado/total lo satisface para
     * siempre. Con esa condición, la venta de abajo se descartaba con un 200 vacío —el vendedor
     * cree que guardó y no guardó— aunque no tuviera nada que ver con un doble clic.
     *
     * La venta sembrada se fecha mañana a una hora BIEN lejos de la actual (12 horas), para que
     * caiga fuera de la ventana de ±5 segundos alrededor del instante que se va a guardar. Un
     * doble clic de verdad, en cambio, lleva la misma fecha y la misma hora: ese sigue cayendo
     * adentro y se sigue descartando (lo mide el caso 4).
     *
     * @group sales
     * @test
     * @return void
     */
    public function una_venta_con_fecha_futura_no_hace_que_se_rechace_una_venta_legitima()
    {
        $ahora = Carbon::now();

        $manana_a_otra_hora = $ahora->copy()->addDay()->setTime(($ahora->hour + 12) % 24, 0, 0);

        $this->venta_guardada(1105, $manana_a_otra_hora->format('Y-m-d H:i:s'));

        $respuesta = $this->post('api/sale', $this->payload_venta(1105, [
            'created_at' => $manana_a_otra_hora->format('Y-m-d'),
        ]));

        $respuesta->assertStatus(201);

        $this->assertSame(
            2,
            Sale::where('user_id', self::USER_ID)->where('price_type_id', $this->lista->id)->count(),
            'La venta legítima fue descartada por la guarda anti-duplicados por culpa de una venta futura.'
        );
    }

    /**
     * 🔴 Caso 6: un `created_at` que no es exactamente `YYYY-MM-DD` cae a `now()`, y no se corre
     * tres horas.
     *
     * El ISO con `Z` es el caso medido el 21/9/2026: `toArray()` serializa la fecha en UTC y
     * reasignarla la reinterpreta en la zona local, corriéndola +3 horas. Por eso el resolvedor es
     * una lista blanca: nada de esto entra.
     *
     * @group sales
     * @test
     * @return void
     */
    public function un_created_at_que_no_es_y_m_d_cae_a_ahora_sin_correrse_tres_horas()
    {
        $valores = [
            1106 => Carbon::now()->format('Y-m-d').'T00:00:00.000Z',
            1107 => 'no soy una fecha',
            1108 => '2026-13-45',
            1109 => '',
            1110 => '2026-9-1',
        ];

        foreach ($valores as $total => $valor) {

            $ahora = Carbon::now();

            $venta = $this->crear_venta_por_api($total, ['created_at' => $valor]);

            $this->assertLessThanOrEqual(
                self::TOLERANCIA,
                $this->distancia_en_segundos($ahora, $venta->created_at),
                'El valor "'.$valor.'" no cayó a now(): la venta quedó en '.$venta->created_at->format('Y-m-d H:i:s').'.'
            );
        }
    }

    /**
     * Caso 7: el update que cambia el DÍA conserva la hora original de la venta.
     *
     * En el update "la hora actual" no significa nada: el registro ya tiene la suya, que es cuando
     * se cargó de verdad. Lo único que se le cambia es el día.
     *
     * @group sales
     * @test
     * @return void
     */
    public function el_update_que_cambia_el_dia_conserva_la_hora_original()
    {
        $original = Carbon::now()->subDays(10)->setTime(11, 22, 33);

        $venta = $this->venta_guardada(1111, $original->format('Y-m-d H:i:s'));

        $dia_nuevo = $original->copy()->subDays(5);

        $respuesta = $this->put('api/sale/'.$venta->id, $this->payload_update($venta, [
            'created_at' => $dia_nuevo->format('Y-m-d'),
        ]));

        $respuesta->assertStatus(200);

        $guardado = Sale::find($venta->id)->created_at;

        $this->assertSame(
            $dia_nuevo->format('Y-m-d').' 11:22:33',
            $guardado->format('Y-m-d H:i:s'),
            'El update cambió el día pero no conservó la hora original de la venta.'
        );
    }

    /**
     * Caso 7 bis: si el día pedido es el MISMO que el guardado, `created_at` no se toca — ni
     * siquiera para reescribirlo con la hora de ahora. Es lo que hace que abrir una venta vieja y
     * guardarla sin tocar la fecha no le corra la hora.
     *
     * @group sales
     * @test
     * @return void
     */
    public function el_update_con_el_mismo_dia_no_le_corre_la_hora_a_la_venta()
    {
        $original = Carbon::now()->subDays(10)->setTime(11, 22, 33);

        $venta = $this->venta_guardada(1112, $original->format('Y-m-d H:i:s'));

        $respuesta = $this->put('api/sale/'.$venta->id, $this->payload_update($venta, [
            'created_at' => $original->format('Y-m-d'),
        ]));

        $respuesta->assertStatus(200);

        $this->assertSame(
            $original->format('Y-m-d H:i:s'),
            Sale::find($venta->id)->created_at->format('Y-m-d H:i:s'),
            'El update con el mismo día le corrió la hora a la venta.'
        );
    }

    /**
     * 🔴 Caso 8: un PUT que no manda la clave —la SPA vieja— no pisa el `created_at` guardado.
     * Misma guarda que `omitir_en_cuenta_corriente`, y por el mismo motivo.
     *
     * @group sales
     * @test
     * @return void
     */
    public function el_update_sin_la_clave_no_pisa_el_created_at_guardado()
    {
        $original = Carbon::now()->subDays(10)->setTime(11, 22, 33);

        $venta = $this->venta_guardada(1113, $original->format('Y-m-d H:i:s'));

        $respuesta = $this->put('api/sale/'.$venta->id, $this->payload_update($venta));

        $respuesta->assertStatus(200);

        $this->assertSame(
            $original->format('Y-m-d H:i:s'),
            Sale::find($venta->id)->created_at->format('Y-m-d H:i:s'),
            'Un PUT sin la clave created_at le cambió la fecha de creación a la venta.'
        );
    }

    /**
     * Caso 8 bis: y un PUT que manda basura en la clave tampoco pisa nada. La lista blanca vale
     * también para el update: lo que no es `YYYY-MM-DD` no es una fecha pedida.
     *
     * @group sales
     * @test
     * @return void
     */
    public function el_update_con_un_created_at_invalido_no_pisa_el_guardado()
    {
        $original = Carbon::now()->subDays(10)->setTime(11, 22, 33);

        $venta = $this->venta_guardada(1114, $original->format('Y-m-d H:i:s'));

        $respuesta = $this->put('api/sale/'.$venta->id, $this->payload_update($venta, [
            'created_at' => $original->toIso8601ZuluString(),
        ]));

        $respuesta->assertStatus(200);

        $this->assertSame(
            $original->format('Y-m-d H:i:s'),
            Sale::find($venta->id)->created_at->format('Y-m-d H:i:s'),
            'Un PUT con un ISO con Z le corrió la fecha de creación a la venta.'
        );
    }

    /**
     * El resolvedor, medido directo: es el único lugar donde se interpreta el campo, así que las
     * reglas se fijan también acá y no solo a través de los dos controladores.
     *
     * @group sales
     * @test
     * @return void
     */
    public function el_resolvedor_es_una_lista_blanca_de_y_m_d()
    {
        $ahora = Carbon::now();

        // Lo que entra: un Y-m-d válido, con la hora de ahora.
        $resuelto = SaleHelper::resolver_created_at('2026-02-28');

        $this->assertSame('2026-02-28', $resuelto->format('Y-m-d'));

        $this->assertLessThanOrEqual(
            self::TOLERANCIA,
            $this->distancia_en_segundos(
                $ahora,
                $resuelto->copy()->setDate($ahora->year, $ahora->month, $ahora->day)
            ),
            'El resolvedor no le puso la hora actual al día elegido.'
        );

        // Lo que no entra: todo lo demás cae a now().
        $invalidos = [null, '', 'basura', '2026-13-45', '2026-02-30', '2026-2-8', '2026-02-28T00:00:00.000Z', 20260228, ['2026-02-28']];

        foreach ($invalidos as $invalido) {

            $caido = SaleHelper::resolver_created_at($invalido);

            $this->assertLessThanOrEqual(
                self::TOLERANCIA,
                $this->distancia_en_segundos($ahora, $caido),
                'Un valor inválido no cayó a now(): devolvió '.$caido->format('Y-m-d H:i:s').'.'
            );
        }

        // El resolvedor del update: día igual → null; día distinto → día nuevo con la hora vieja.
        $guardado = Carbon::parse('2026-03-10 07:08:09');

        $this->assertNull(
            SaleHelper::resolver_created_at_de_update($guardado, '2026-03-10'),
            'Con el mismo día el resolvedor del update tiene que devolver null: no hay nada que tocar.'
        );

        $this->assertNull(
            SaleHelper::resolver_created_at_de_update($guardado, 'basura'),
            'Con un valor inválido el resolvedor del update tiene que devolver null.'
        );

        $this->assertSame(
            '2026-03-04 07:08:09',
            SaleHelper::resolver_created_at_de_update($guardado, '2026-03-04')->format('Y-m-d H:i:s'),
            'El resolvedor del update no conservó la hora original.'
        );

        /*
         * Y no le toca el valor al Carbon que recibe: `$guardado` sigue siendo el mismo instante
         * después de la llamada. Sin el `copy()` de adentro, `setDate()` lo mutaría en el lugar y
         * el llamador se quedaría sin el valor original para comparar.
         */
        $this->assertSame('2026-03-10 07:08:09', $guardado->format('Y-m-d H:i:s'));
    }
}
