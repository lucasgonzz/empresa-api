<?php

namespace Tests\Feature\AjustesDelCliente;

use App\Models\Client;
use App\Models\Discount;
use App\Models\Surchage;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PedidosDePrueba;
use Tests\EmpresaTestCase;

/**
 * Misión descuentos-recargos-por-cliente (23/9/2026): la ficha del cliente guarda los descuentos y
 * recargos de venta vinculados (`client_discount` / `client_surchage`).
 *
 * Esos vínculos son una CONDICIÓN COMERCIAL: Vender los prende solos al elegir el cliente y la
 * tienda ajusta con ellos los precios del comprador vinculado. Por eso lo que más importa acá no es
 * que se guarden, sino las dos formas en que se podían perder o contaminar sin que nadie lo viera:
 *
 *  - un update que no trae la clave (una SPA sin desplegar, un escritor parcial) NO puede borrarlos:
 *    `GeneralHelper::attachModels()` arranca con un `detach()`, y un `sync([])` haría lo mismo;
 *  - un id de descuento de OTRO comercio se ignora: la base puede ser compartida entre comercios, y
 *    un descuento ajeno colgado del cliente cambiaría precios con un porcentaje que el dueño no creó.
 *
 * Y que el GET del cliente traiga las dos relaciones: el listado genérico de la SPA hace
 * `model[prop.key].length` sin guarda para las props belongs_to_many.
 *
 * `EmpresaTestCase` (DatabaseTransactions + fixture de la ferretería). Todo lo que se crea lleva
 * prefijo `zz` y vive adentro de la transacción.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class Ficha_con_descuentos_y_recargos_Test extends EmpresaTestCase
{
    use PedidosDePrueba;

    /** Un comercio que no es el del fixture (base compartida). */
    const OTRO_COMERCIO = 987654;

    /** @var \App\Models\Discount */
    protected $descuento;

    /** @var \App\Models\Discount */
    protected $otro_descuento;

    /** @var \App\Models\Surchage */
    protected $recargo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->descuento = Discount::create([
            'name'       => 'zz Descuento mayorista',
            'percentage' => 10,
            'user_id'    => $this->user_id(),
        ]);

        $this->otro_descuento = Discount::create([
            'name'       => 'zz Descuento por volumen',
            'percentage' => 3,
            'user_id'    => $this->user_id(),
        ]);

        $this->recargo = Surchage::create([
            'name'       => 'zz Recargo por flete',
            'percentage' => 5,
            'user_id'    => $this->user_id(),
        ]);
    }

    /**
     * El payload del form genérico de clientes, con las claves de ajustes solo si se piden.
     *
     * @param  array  $overrides
     * @return array
     */
    protected function payload_cliente($overrides = [])
    {
        return array_merge([
            'name'                     => 'zz Cliente con condiciones '.uniqid(),
            'email'                    => null,
            'phone'                    => null,
            'address'                  => null,
            'cuil'                     => null,
            'cuit'                     => null,
            'dni'                      => null,
            'razon_social'             => null,
            'iva_condition_id'         => null,
            'price_type_id'            => null,
            'location_id'              => null,
            'provincia_id'             => null,
            'description'              => null,
            'saldo'                    => null,
            'moneda_id'                => 1,
            'pais_exportacion_id'      => null,
            'comercio_city_user_id'    => null,
            'seller_id'                => null,
            'link_google_maps'         => null,
            'client_reputation_id'     => null,
            'pasar_ventas_a_la_cuenta_corriente_sin_esperar_a_facturar' => 0,
            'address_id'               => null,
        ], $overrides);
    }

    /**
     * Ids vinculados al cliente, leídos del pivot y no de la relación.
     *
     * @param  string  $tabla
     * @param  string  $columna
     * @param  int  $client_id
     * @return array<int,int>
     */
    protected function vinculados($tabla, $columna, $client_id)
    {
        return DB::table($tabla)
                    ->where('client_id', $client_id)
                    ->orderBy($columna)
                    ->pluck($columna)
                    ->map(function ($id) {
                        return (int) $id;
                    })
                    ->all();
    }

    /**
     * El alta y la edición guardan los vínculos, tal como los manda la SPA (el modelo entero de
     * cada descuento, no el id pelado).
     *
     * @group ajustes_del_cliente
     * @test
     */
    public function la_ficha_guarda_los_descuentos_y_recargos()
    {
        $response = $this->postJson('api/client', $this->payload_cliente([
            'discounts' => [$this->descuento->toArray()],
            'surchages' => [$this->recargo->toArray()],
        ]));

        $response->assertStatus(201);

        $client_id = $response->json('model.id');

        $this->assertEquals([$this->descuento->id], $this->vinculados('client_discount', 'discount_id', $client_id), 'El alta no guardó el descuento del cliente.');
        $this->assertEquals([$this->recargo->id], $this->vinculados('client_surchage', 'surchage_id', $client_id), 'El alta no guardó el recargo del cliente.');

        /* Editar: se suma un descuento y se saca el recargo. */
        $this->putJson('api/client/'.$client_id, $this->payload_cliente([
            'discounts' => [$this->descuento->toArray(), $this->otro_descuento->toArray()],
            'surchages' => [],
        ]))->assertStatus(200);

        $esperados = [$this->descuento->id, $this->otro_descuento->id];
        sort($esperados);

        $this->assertEquals($esperados, $this->vinculados('client_discount', 'discount_id', $client_id), 'La edición no sincronizó los descuentos.');
        $this->assertEquals([], $this->vinculados('client_surchage', 'surchage_id', $client_id), 'Mandar la lista vacía tiene que sacar el recargo: la clave vino.');
    }

    /**
     * 🔴 Un update SIN las claves no borra nada.
     *
     * @group ajustes_del_cliente
     * @test
     */
    public function el_update_sin_las_claves_no_borra_los_vinculos()
    {
        $client_id = $this->postJson('api/client', $this->payload_cliente([
            'discounts' => [$this->descuento->toArray()],
            'surchages' => [$this->recargo->toArray()],
        ]))->assertStatus(201)->json('model.id');

        /* El payload de una SPA vieja: el cliente entero, sin `discounts` ni `surchages`. */
        $this->putJson('api/client/'.$client_id, $this->payload_cliente(['name' => 'zz Renombrado']))
             ->assertStatus(200);

        $this->assertEquals('zz Renombrado', Client::find($client_id)->name);

        $this->assertEquals([$this->descuento->id], $this->vinculados('client_discount', 'discount_id', $client_id), 'Un update sin la clave `discounts` borró los descuentos del cliente.');
        $this->assertEquals([$this->recargo->id], $this->vinculados('client_surchage', 'surchage_id', $client_id), 'Un update sin la clave `surchages` borró los recargos del cliente.');

        /* Y el cambio de teléfono, que es un escritor parcial de verdad, tampoco. */
        $this->patchJson('api/client/'.$client_id.'/phone', ['phone' => '2216111111'])->assertStatus(200);

        $this->assertEquals([$this->descuento->id], $this->vinculados('client_discount', 'discount_id', $client_id));
        $this->assertEquals([$this->recargo->id], $this->vinculados('client_surchage', 'surchage_id', $client_id));
    }

    /**
     * 🔴 Los ids de otro comercio, los inexistentes y los borrados se ignoran.
     *
     * @group ajustes_del_cliente
     * @test
     */
    public function los_ids_de_otro_comercio_se_ignoran()
    {
        $ajeno = Discount::create([
            'name'       => 'zz Descuento de otro comercio',
            'percentage' => 50,
            'user_id'    => self::OTRO_COMERCIO,
        ]);

        $recargo_ajeno = Surchage::create([
            'name'       => 'zz Recargo de otro comercio',
            'percentage' => 50,
            'user_id'    => self::OTRO_COMERCIO,
        ]);

        $borrado = Discount::create([
            'name'       => 'zz Descuento borrado',
            'percentage' => 20,
            'user_id'    => $this->user_id(),
        ]);
        $borrado->delete();

        $client_id = $this->postJson('api/client', $this->payload_cliente([
            /* Mezcla de formas: modelo entero, id pelado, id inexistente. */
            'discounts' => [$this->descuento->toArray(), $ajeno->id, ['id' => $borrado->id], ['id' => 99999999]],
            'surchages' => [['id' => $recargo_ajeno->id]],
        ]))->assertStatus(201)->json('model.id');

        $this->assertEquals([$this->descuento->id], $this->vinculados('client_discount', 'discount_id', $client_id), 'Se vinculó un descuento ajeno, borrado o inexistente.');
        $this->assertEquals([], $this->vinculados('client_surchage', 'surchage_id', $client_id), 'Se vinculó un recargo de otro comercio.');
    }

    /**
     * El GET del cliente (show e index) trae las dos relaciones, también vacías.
     *
     * @group ajustes_del_cliente
     * @test
     */
    public function el_get_del_cliente_trae_las_relaciones()
    {
        $client_id = $this->postJson('api/client', $this->payload_cliente([
            'discounts' => [$this->descuento->toArray()],
            'surchages' => [$this->recargo->toArray()],
        ]))->assertStatus(201)->json('model.id');

        $response = $this->getJson('api/client/'.$client_id)->assertStatus(200);

        $this->assertEquals([$this->descuento->id], array_column($response->json('model.discounts'), 'id'));
        $this->assertEquals(10, (float) $response->json('model.discounts.0.percentage'));
        $this->assertEquals([$this->recargo->id], array_column($response->json('model.surchages'), 'id'));

        /* Un cliente sin vínculos trae listas vacías, no null: el listado de la SPA hace `.length`. */
        $sin_nada = $this->postJson('api/client', $this->payload_cliente())->assertStatus(201);

        $this->assertSame([], $sin_nada->json('model.discounts'));
        $this->assertSame([], $sin_nada->json('model.surchages'));

        /* Y el listado paginado también las trae. */
        $listado = $this->getJson('api/client?per_page=5')->assertStatus(200);

        $primero = $listado->json('models.data.0');

        $this->assertArrayHasKey('discounts', $primero);
        $this->assertArrayHasKey('surchages', $primero);
    }
}
