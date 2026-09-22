<?php

namespace Tests\Feature\Compras;

use App\Http\Controllers\Helpers\providerOrder\ProviderOrderAltaHelper;
use App\Models\ProviderOrder;
use Carbon\Carbon;

/**
 * Misión fecha-creacion-editable (22/9/2026), lado compras: el usuario elige la fecha de creación
 * de la compra a proveedor en la solapa "Configuracion" y esa fecha se guarda en `created_at`. Por
 * defecto, el día de hoy.
 *
 * Es el mismo resolvedor que usa la venta (`SaleHelper::resolver_created_at()`): un solo lugar
 * donde se interpreta el campo, para los dos flujos. Lo que estos tests fijan es lo propio de
 * compras:
 *
 *  - la clave entra a la LISTA BLANCA de `ProviderOrderController::store()` (lo que no está en esa
 *    lista no llega al modelo aunque la SPA lo mande);
 *  - 🔴 y un `created_at` nulo NO se escribe. `ProviderOrder` tiene `$guarded = []` y Eloquent
 *    completa `created_at` solo si el atributo no está sucio: asignarlo en null lo ensucia igual y
 *    la compra quedaría con la columna en NULL en vez de la fecha de hoy. Por eso la clave se
 *    agrega al `create()` únicamente cuando tiene valor, y por eso ese caso tiene su propio test.
 *
 * Hereda de `ComprasTestCase` (guards de entorno, fixture de la ferretería, `payload_compra` e
 * `item`). PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class Fecha_De_Creacion_Editable_Test extends ComprasTestCase
{
    /** @var int Tolerancia en segundos al comparar un instante guardado contra `now()`. */
    const TOLERANCIA = 120;

    /**
     * Crea la compra por la API y devuelve el modelo recién guardado.
     *
     * @param  array  $overrides
     * @return \App\Models\ProviderOrder
     */
    protected function crear_compra_por_api($overrides = [])
    {
        $this->quitar_bonificaciones_de_buenos_aires();

        $payload = $this->payload_compra(array_merge([
            'articles' => [$this->item('Pinza', 100, 1)],
        ], $overrides));

        $respuesta = $this->post('api/provider-order', $payload);

        $respuesta->assertStatus(201);

        return ProviderOrder::find($respuesta->json('model.id'));
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
     * Caso 9: una compra con fecha PASADA se guarda ese día, y con la hora actual (no a
     * medianoche).
     *
     * @group compras
     * @test
     * @return void
     */
    public function una_compra_con_fecha_pasada_se_guarda_ese_dia_con_la_hora_actual()
    {
        $ahora  = Carbon::now();
        $pasado = $ahora->copy()->subDays(30);

        $compra = $this->crear_compra_por_api(['created_at' => $pasado->format('Y-m-d')]);

        $this->assertSame(
            $pasado->format('Y-m-d'),
            $compra->created_at->format('Y-m-d'),
            'La compra no quedó en el día elegido.'
        );

        $misma_hora_hoy = $compra->created_at->copy()->setDate($ahora->year, $ahora->month, $ahora->day);

        $this->assertLessThanOrEqual(
            self::TOLERANCIA,
            $this->distancia_en_segundos($ahora, $misma_hora_hoy),
            'La compra con fecha pasada no conservó la hora actual: quedó a medianoche o con la hora corrida.'
        );
    }

    /**
     * 🔴 Caso 10: una compra SIN la clave queda con la fecha de hoy, y la columna NO queda en
     * NULL.
     *
     * Por la API el valor nunca llega nulo (`store()` manda siempre el resuelto), así que lo que
     * este test fija es que el camino normal de la SPA sin tocar el campo sigue guardando hoy. El
     * caso del `created_at` nulo —el del asistente, que es donde la columna podía quedar en NULL—
     * lo mide el test de abajo, llamando al helper de alta directo.
     *
     * @group compras
     * @test
     * @return void
     */
    public function una_compra_sin_created_at_queda_con_hoy_y_nunca_en_null()
    {
        $ahora = Carbon::now();

        $compra = $this->crear_compra_por_api();

        $this->assertNotNull(
            $compra->created_at,
            'La compra quedó con created_at en NULL: la clave se escribió aunque no vino valor.'
        );

        $this->assertSame(
            $ahora->format('Y-m-d'),
            $compra->created_at->format('Y-m-d'),
            'Una compra sin created_at tiene que quedar con la fecha de hoy.'
        );

        $this->assertLessThanOrEqual(
            self::TOLERANCIA,
            $this->distancia_en_segundos($ahora, $compra->created_at),
            'Una compra sin created_at tiene que quedar con la hora de ahora, no corrida.'
        );
    }

    /**
     * 🔴 Y el caso que la API sola NO puede ejercitar: un llamador de
     * `ProviderOrderAltaHelper::crear()` que NO manda la clave `created_at`.
     *
     * Por la API esto nunca pasa —`ProviderOrderController::store()` siempre manda el valor ya
     * resuelto, que nunca es null—, pero el helper tiene un segundo llamador que no tiene request:
     * el asistente de WhatsApp creando la compra donde va a colgar la factura
     * (`ConfirmacionPorTextoIaHelper`). Ese pasa el array sin la clave.
     *
     * Con la clave agregada a secas al `create()`, ese llamador insertaba `created_at` en NULL:
     * `ProviderOrder` tiene `$guarded = []`, así que la clave entra al modelo, y `updateTimestamps()`
     * solo completa `created_at` cuando el atributo NO está sucio —asignarlo en null lo ensucia
     * igual—. La compra quedaba sin fecha de creación, invisible en el listado, en las alertas y
     * en el Libro IVA, sin un solo error en ningún lado.
     *
     * @group compras
     * @test
     * @return void
     */
    public function el_alta_sin_la_clave_created_at_no_deja_la_columna_en_null()
    {
        $ahora = Carbon::now();

        /*
         * Rosario y no Buenos Aires: este proveedor no tiene bonificaciones de catálogo, así que
         * `precargar_bonificaciones_proveedor()` no copia nada y no hace falta neutralizar nada.
         */
        $compra = ProviderOrderAltaHelper::crear([
            'user_id'                  => auth()->id(),
            'provider_id'              => $this->proveedor('Rosario')->id,
            'provider_order_status_id' => 1,
            'modo_facturacion'         => 'automatico',
            'update_stock'             => 0,
            'update_prices'            => 0,
            'precios_incluyen_iva'     => 0,
            'total_with_iva'           => 1,
            'moneda_id'                => 1,
            'generate_current_acount'  => 0,
            'articles'                 => [],
        ]);

        $recargada = ProviderOrder::find($compra->id);

        $this->assertNotNull(
            $recargada->created_at,
            'El alta sin la clave created_at dejó la columna en NULL en vez de la fecha de hoy.'
        );

        $this->assertLessThanOrEqual(
            self::TOLERANCIA,
            $this->distancia_en_segundos($ahora, $recargada->created_at),
            'El alta sin la clave created_at no cayó en el default de Eloquent.'
        );
    }

    /**
     * Y con un valor que no pasa la lista blanca —un ISO con `Z`, que es el que corre la fecha +3
     * horas— también cae a hoy, sin correrse.
     *
     * @group compras
     * @test
     * @return void
     */
    public function una_compra_con_un_created_at_invalido_cae_a_hoy()
    {
        $ahora = Carbon::now();

        $compra = $this->crear_compra_por_api([
            'created_at' => $ahora->copy()->toIso8601ZuluString(),
        ]);

        $this->assertNotNull($compra->created_at);

        $this->assertLessThanOrEqual(
            self::TOLERANCIA,
            $this->distancia_en_segundos($ahora, $compra->created_at),
            'El ISO con Z no cayó a now(): la compra quedó en '.$compra->created_at->format('Y-m-d H:i:s').'.'
        );
    }

    /**
     * El update que cambia el DÍA conserva la hora original de la compra.
     *
     * @group compras
     * @test
     * @return void
     */
    public function el_update_que_cambia_el_dia_conserva_la_hora_original()
    {
        $compra = $this->crear_compra_por_api();

        $original = Carbon::now()->subDays(10)->setTime(9, 15, 45);

        // Se fija la hora original a mano, sin pasar por el alta: lo que se mide acá es el update.
        ProviderOrder::where('id', $compra->id)->update(['created_at' => $original->format('Y-m-d H:i:s')]);

        $dia_nuevo = $original->copy()->subDays(4);

        $respuesta = $this->put('api/provider-order/'.$compra->id, $this->payload_compra([
            'articles'   => [$this->item('Pinza', 100, 1)],
            'created_at' => $dia_nuevo->format('Y-m-d'),
        ]));

        $respuesta->assertStatus(200);

        $this->assertSame(
            $dia_nuevo->format('Y-m-d').' 09:15:45',
            ProviderOrder::find($compra->id)->created_at->format('Y-m-d H:i:s'),
            'El update cambió el día pero no conservó la hora original de la compra.'
        );
    }

    /**
     * 🔴 Y un PUT que no manda la clave —la SPA vieja— no pisa el `created_at` guardado.
     *
     * @group compras
     * @test
     * @return void
     */
    public function el_update_sin_la_clave_no_pisa_el_created_at_guardado()
    {
        $compra = $this->crear_compra_por_api();

        $original = Carbon::now()->subDays(10)->setTime(9, 15, 45);

        ProviderOrder::where('id', $compra->id)->update(['created_at' => $original->format('Y-m-d H:i:s')]);

        $respuesta = $this->put('api/provider-order/'.$compra->id, $this->payload_compra([
            'articles' => [$this->item('Pinza', 100, 1)],
        ]));

        $respuesta->assertStatus(200);

        $this->assertSame(
            $original->format('Y-m-d H:i:s'),
            ProviderOrder::find($compra->id)->created_at->format('Y-m-d H:i:s'),
            'Un PUT sin la clave created_at le cambió la fecha de creación a la compra.'
        );
    }
}
