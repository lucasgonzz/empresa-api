<?php

namespace Tests\Feature\ForzarTotal;

use App\Models\Client;
use App\Models\Combo;
use App\Models\Sale;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Base comun de la suite del total forzado por monto (mision forzar-total-por-monto, 17/9/2026).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  QUE ES EL TOTAL FORZADO, EN UNA LINEA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  La venta da $4.012 y el vendedor le dice al cliente "pagame 4.000 y listo". El sistema guarda
 *  esos -12 como un MONTO CON SIGNO en `sales.forzar_total_monto` (negativo = descuento, positivo
 *  = recargo, null = no se forzo nada) y el total final de la venta es 4.000 en todos lados:
 *  cuenta corriente, caja, comprobante, factura y reportes. El 4.012 queda solo como `sub_total`.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 LO QUE ESTA SUITE TIENE QUE PROBAR, Y QUE NO SE PUEDE PROBAR CON UN SOLO ARTICULO
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  La extension `forzar_total` vieja guardaba un PORCENTAJE en `sales.descuento` y lo restaba
 *  solo a `total_articles`; despues el total se rearmaba sumando articulos + servicios + combos +
 *  promociones. En una venta de puros articulos eso daba bien de casualidad. En una venta con un
 *  servicio o un combo adentro, el numero forzado NO APARECIA POR NINGUN LADO — y era el caso
 *  real, porque el forzado se usa justo en el mostrador, donde se mezcla todo.
 *
 *  Por eso el escenario central de esta suite (archivo 2) es una venta MEZCLADA. Un fixture de un
 *  solo articulo daria verde sin probar nada de lo que esta mision vino a arreglar.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  ⚠️ DOS COSAS DEL ENTORNO QUE CUESTAN UNA HORA SI NO SE SABEN
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  1. `DatabaseTransactions`, NUNCA `RefreshDatabase` (lo hereda de `EmpresaTestCase`). La base
 *     del slot esta sembrada de antes con `TestingFerreteriaSeeder` y un refresh la vaciaria,
 *     rompiendo todas las demas suites.
 *
 *  2. `SaleController::store()` tiene una guarda anti-duplicados: si hace 5 segundos o menos entro
 *     una venta del mismo comercio, mismo cliente y MISMO TOTAL, la segunda no se crea y el
 *     endpoint responde 200 con el cuerpo vacio en vez de 201. Entre tests no molesta (cada uno es
 *     su propia transaccion y la venta del anterior ya se revirtio), pero un test que cree DOS
 *     ventas tiene que darles totales distintos.
 *
 * Todo lo que se afirma se lee de la BASE despues de pegarle al endpoint real, nunca del valor de
 * retorno de un helper — salvo en los archivos que prueban explicitamente un calculo, donde el
 * esperado esta calculado A MANO en la asercion y nunca contra otro metodo del mismo helper.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
abstract class ForzarTotalTestCase extends EmpresaTestCase
{
    /** Tolerancia de plata: nunca igualdad exacta sobre floats. */
    const DELTA = 0.01;

    /** El total que suman los renglones por su cuenta, antes de forzar. */
    const BRUTO = 4012.00;

    /** El total que el vendedor escribe a mano. */
    const FORZADO = 4000.00;

    /** El monto que se guarda: FORZADO - BRUTO. Negativo porque es un descuento. */
    const MONTO = -12.00;

    /**
     * El comercio del fixture.
     *
     * @return \App\Models\User
     */
    protected function comercio()
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->assertNotNull($user, 'Falta el usuario del fixture.');

        return $user;
    }

    /**
     * Un cliente del fixture, por nombre.
     *
     * @param  string  $nombre
     * @return \App\Models\Client
     */
    protected function cliente($nombre)
    {
        $client = Client::where('name', $nombre)->first();

        $this->assertNotNull($client, 'Falta el cliente "'.$nombre.'" del fixture.');

        return $client;
    }

    /**
     * Crea una venta directo en la base, sin pasar por el endpoint.
     *
     * Se usa solo en los archivos que prueban un CALCULO (`getTotalSale()`, el prorrateo de AFIP),
     * donde lo que importa es el estado de la venta y no como se llego a el. Los archivos que
     * prueban el guardado pegan contra el endpoint real.
     *
     * @param  array  $overrides
     * @return \App\Models\Sale
     */
    protected function crear_venta_en_base($overrides = [])
    {
        return Sale::create(array_merge([
            'user_id'                    => $this->comercio()->id,
            'client_id'                  => null,
            'omitir_en_cuenta_corriente' => 0,
            'save_current_acount'        => 0,
            'terminada'                  => 1,
            'is_cerrada'                 => 0,
            'sub_total'                  => self::BRUTO,
            'total'                      => self::FORZADO,
            'moneda_id'                  => 1,
            'descuento'                  => 0,
            'forzar_total_monto'         => self::MONTO,
        ], $overrides));
    }

    /**
     * Engancha un articulo del fixture a la venta.
     *
     * @param  \App\Models\Sale  $sale
     * @param  string            $nombre_articulo
     * @param  float             $price
     * @param  float             $amount
     * @param  array             $pivot_extra
     * @return void
     */
    protected function enganchar_articulo($sale, $nombre_articulo, $price, $amount, $pivot_extra = [])
    {
        $articulo = $this->articulo($nombre_articulo);

        $this->assertNotNull($articulo, 'Falta el articulo "'.$nombre_articulo.'" del fixture.');

        $sale->articles()->attach($articulo->id, array_merge([
            'amount' => $amount,
            'price'  => $price,
        ], $pivot_extra));
    }

    /**
     * Crea un servicio del comercio y lo engancha a la venta.
     *
     * ⚠️ La tabla `services` viene VACIA en la base del slot (medida el 17/9/2026), asi que el
     * servicio hay que crearlo. Un servicio en una venta es un estado perfectamente real de
     * produccion — es literalmente el caso que rompia la extension vieja —, no un fixture armado
     * para que el test pase.
     *
     * @param  \App\Models\Sale  $sale
     * @param  float             $price
     * @param  float             $amount
     * @return \App\Models\Service
     */
    protected function enganchar_servicio($sale, $price, $amount = 1)
    {
        $service = Service::create([
            'name'    => 'Servicio de la suite de forzar total',
            'price'   => $price,
            'user_id' => $this->comercio()->id,
        ]);

        $sale->services()->attach($service->id, [
            'price'  => $price,
            'amount' => $amount,
        ]);

        return $service;
    }

    /**
     * Crea un combo del comercio y lo engancha a la venta.
     *
     * El combo se engancha por el pivote y NO por `SaleHelper::attachCombos()` a proposito: ese
     * camino ademas descuenta el stock de los articulos que componen el combo, que es maquinaria
     * de otro modulo y no tiene nada que ver con lo que mide esta suite.
     *
     * @param  \App\Models\Sale  $sale
     * @param  float             $price
     * @param  float             $amount
     * @return \App\Models\Combo
     */
    protected function enganchar_combo($sale, $price, $amount = 1)
    {
        $combo = Combo::create([
            'num'     => 9001,
            'name'    => 'Combo de la suite de forzar total',
            'price'   => $price,
            'cost'    => 0,
            'user_id' => $this->comercio()->id,
        ]);

        $sale->combos()->attach($combo->id, [
            'price'  => $price,
            'amount' => $amount,
        ]);

        return $combo;
    }

    /**
     * Payload de POST api/sale con un solo renglon del articulo centinela.
     *
     * @param  float  $total       El total final de la venta (el forzado, si lo hay).
     * @param  float  $sub_total   El total bruto, antes de forzar.
     * @param  array  $overrides
     * @return array
     */
    protected function payload_venta($total, $sub_total, $overrides = [])
    {
        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);

        $payload = [
            'client_id'                  => null,
            'address_id'                 => null,
            'save_current_acount'        => 0,
            'omitir_en_cuenta_corriente' => 1,
            'to_check'                   => 0,
            'discounts_in_services'      => 1,
            'surchages_in_services'      => 1,
            'employee_id'                => null,
            'sub_total'                  => $sub_total,
            'total'                      => $total,
            'terminada'                  => 1,
            'seller_id'                  => null,
            'cantidad_cuotas'            => null,
            'cuota_descuento'            => 0,
            'cuota_recargo'              => 0,
            'caja_id'                    => null,
            'afip_tipo_comprobante_id'   => null,
            'descuento'                  => null,
            'moneda_id'                  => 1,
            'discounts'                  => [],
            'surchages'                  => [],
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $articulo->id,
                    'price_vender' => $sub_total,
                    'amount'       => 1,
                ],
            ],
        ];

        return array_merge($payload, $overrides);
    }

    /**
     * Crea una venta por el endpoint real y devuelve el modelo leido de la base.
     *
     * @param  array  $payload
     * @return \App\Models\Sale
     */
    protected function crear_venta_por_endpoint($payload)
    {
        $response = $this->postJson('api/sale', $payload);

        $response->assertStatus(201);

        $cuerpo = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('model', $cuerpo, 'POST api/sale no devolvio la venta creada.');

        return Sale::find($cuerpo['model']['id']);
    }

    /**
     * Payload minimo de PUT api/sale/{id}.
     *
     * to_check / checked / confirmed / discounts_in_services / surchages_in_services son NOT NULL
     * en la tabla y `update()` los asigna a secas desde el request, asi que faltar cualquiera
     * revienta el update con una violacion de integridad. Y los `items` van SIEMPRE: `update()`
     * hace `detachItems()` y vuelve a enganchar lo que venga, asi que con la lista vacia la venta
     * queda sin renglones.
     *
     * @param  \App\Models\Sale  $sale
     * @param  float             $total
     * @param  float             $sub_total
     * @param  array             $overrides
     * @return array
     */
    protected function payload_actualizar($sale, $total, $sub_total, $overrides = [])
    {
        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);

        $payload = [
            'client_id'                  => $sale->client_id,
            'save_current_acount'        => $sale->save_current_acount,
            'omitir_en_cuenta_corriente' => $sale->omitir_en_cuenta_corriente,
            'to_check'                   => 0,
            'checked'                    => 0,
            'confirmed'                  => 0,
            'discounts_in_services'      => 1,
            'surchages_in_services'      => 1,
            'sub_total'                  => $sub_total,
            'total'                      => $total,
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $articulo->id,
                    'price_vender' => $sub_total,
                    'amount'       => 1,
                ],
            ],
            'discounts'                  => [],
            'surchages'                  => [],
            'returned_items'             => [],
        ];

        return array_merge($payload, $overrides);
    }

    /**
     * @param  \App\Models\Sale  $sale
     * @param  array             $payload
     * @return \Illuminate\Testing\TestResponse
     */
    protected function actualizar_venta($sale, $payload)
    {
        return $this->putJson('api/sale/'.$sale->id, $payload);
    }
}
