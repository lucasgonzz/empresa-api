<?php

namespace Tests\Feature\Vender;

use App\Models\Client;
use App\Models\IvaCondition;
use App\Models\Location;
use App\Models\Provincia;
use Tests\Concerns\PedidosDePrueba;
use Tests\EmpresaTestCase;

/**
 * Misión cliente-desde-arca-en-vender (4/10/2026): en Vender, un CUIT que no está en el sistema se
 * consulta a ARCA y el botón "Crear cliente y usar para esta venta" abre el formulario de cliente
 * PRECARGADO con lo que devolvió ARCA (`buscar-por-cuit/ModalResult.vue::setCreateClient()`).
 *
 * El defecto era del lado de la SPA: la razón social no se pasaba al formulario y el cliente se
 * guardaba con `razon_social` en null. La SPA ahora la manda, y la manda con la clave que arma
 * `AfipConstanciaInscripcionController::get_constancia_inscripcion()` (`razon_social`, snake_case,
 * solo para personas jurídicas). Este test fija la otra punta de ese contrato: que `POST api/client`
 * con el payload del formulario precargado la persista y la devuelva en `model`. Si alguien la saca
 * del `Client::create()` de `ClientController::store`, la SPA la sigue mandando y se pierde sin ruido.
 *
 * Y el caso de la persona física: ARCA no devuelve razón social, el formulario la manda vacía (el
 * default de la prop en `empresa-spa/src/models/client.js` es '') o directamente no la manda, y el
 * alta tiene que salir igual, con la columna en null.
 *
 * `EmpresaTestCase` (DatabaseTransactions + fixture de la ferretería). Todo lo que se crea lleva
 * prefijo `zz` y vive adentro de la transacción.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class Cliente_desde_arca_guarda_razon_social_Test extends EmpresaTestCase
{
    use PedidosDePrueba;

    /** @var \App\Models\Provincia */
    protected $provincia;

    /** @var \App\Models\Location */
    protected $localidad;

    protected function setUp(): void
    {
        parent::setUp();

        /*
            La SPA busca provincia y localidad por nombre en el store y, si no están, las crea con
            `POST provincia` / `POST location` antes de abrir el formulario. Acá se crean directo:
            lo que se prueba es el alta del cliente, no esas dos.
        */
        $this->provincia = Provincia::create([
            'name'    => 'zz Provincia de ARCA',
            'user_id' => $this->user_id(),
        ]);

        $this->localidad = Location::create([
            'name'         => 'zz Localidad de ARCA',
            'provincia_id' => $this->provincia->id,
            'user_id'      => $this->user_id(),
        ]);
    }

    /**
     * El payload del form genérico de clientes (mismo armado que en
     * `AjustesDelCliente/1_Ficha_con_descuentos_y_recargos_Test.php`).
     *
     * @param  array  $overrides
     * @return array
     */
    protected function payload_cliente($overrides = [])
    {
        return array_merge([
            'name'                     => 'zz Cliente '.uniqid(),
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
     * Id de la condición de IVA por nombre (nunca por id hardcodeado).
     *
     * @param  string  $nombre
     * @return int
     */
    protected function iva_condition_id($nombre)
    {
        return (int) IvaCondition::where('name', $nombre)->value('id');
    }

    /**
     * Persona jurídica: el payload que arma el formulario precargado desde ARCA lleva la razón
     * social, y el alta la guarda y la devuelve.
     *
     * @group cliente_desde_arca
     * @test
     */
    public function el_alta_desde_arca_guarda_la_razon_social()
    {
        $iva_ri = $this->iva_condition_id('Responsable inscripto');

        $this->assertGreaterThan(0, $iva_ri, 'Falta la condición de IVA "Responsable inscripto" en el fixture.');

        /*
            Lo que manda `setCreateClient()` para una persona jurídica: el backend pone la razón
            social también en `nombre` (con `apellido` vacío), así que el nombre y la razón social
            coinciden.
        */
        $razon_social = 'ZZ TRANSPORTES DEL SUR S.A.';

        $response = $this->postJson('api/client', $this->payload_cliente([
            'name'             => $razon_social,
            'cuit'             => '30703088534',
            'razon_social'     => $razon_social,
            'iva_condition_id' => $iva_ri,
            'provincia_id'     => $this->provincia->id,
            'location_id'      => $this->localidad->id,
            'address'          => 'zz Av. Siempreviva 742',
        ]));

        $response->assertStatus(201);

        $this->assertEquals($razon_social, $response->json('model.razon_social'), 'La respuesta del alta no trae la razón social que mandó el formulario precargado desde ARCA.');

        $client_id = $response->json('model.id');

        $client = Client::find($client_id);

        $this->assertNotNull($client, 'El alta respondió 201 pero no dejó la fila en clients.');
        $this->assertEquals($razon_social, $client->razon_social, 'La fila de clients no guardó la razón social.');

        /* El resto de lo precargado desde ARCA también queda. */
        $this->assertEquals($razon_social, $client->name);
        $this->assertEquals('30703088534', $client->cuit);
        $this->assertEquals($iva_ri, (int) $client->iva_condition_id);
        $this->assertEquals($this->provincia->id, (int) $client->provincia_id);
        $this->assertEquals($this->localidad->id, (int) $client->location_id);
        $this->assertEquals('zz Av. Siempreviva 742', $client->address);
    }

    /**
     * Persona física: ARCA no devuelve razón social. El alta sale igual y la columna queda en null,
     * tanto si el formulario la manda vacía como si no la manda.
     *
     * @group cliente_desde_arca
     * @test
     */
    public function el_alta_de_una_persona_fisica_sin_razon_social_queda_en_null()
    {
        $iva_mono = $this->iva_condition_id('Monotributista');

        $this->assertGreaterThan(0, $iva_mono, 'Falta la condición de IVA "Monotributista" en el fixture.');

        $datos_de_arca = [
            'name'             => 'ZZ PEREZ JUAN',
            'cuit'             => '20123456786',
            'dni'              => '12345678',
            'iva_condition_id' => $iva_mono,
            'provincia_id'     => $this->provincia->id,
            'location_id'      => $this->localidad->id,
            'address'          => 'zz Calle 7 1234',
        ];

        /*
            Con la clave en '': es lo que manda el formulario, porque la prop `razon_social` de
            `models/client.js` nace en ''. El middleware ConvertEmptyStringsToNull la deja en null.
        */
        $vacia = $this->postJson('api/client', $this->payload_cliente(array_merge($datos_de_arca, [
            'razon_social' => '',
        ])));

        $vacia->assertStatus(201);

        $this->assertNull($vacia->json('model.razon_social'));
        $this->assertNull(Client::find($vacia->json('model.id'))->razon_social, 'Una razón social vacía no puede quedar guardada como texto.');

        /* Sin la clave: un emisor que no la conoce (una SPA anterior a esta misión). */
        $payload_sin_la_clave = $this->payload_cliente($datos_de_arca);
        unset($payload_sin_la_clave['razon_social']);

        $sin_la_clave = $this->postJson('api/client', $payload_sin_la_clave);

        $sin_la_clave->assertStatus(201);

        $this->assertNull($sin_la_clave->json('model.razon_social'));

        $client = Client::find($sin_la_clave->json('model.id'));

        $this->assertNull($client->razon_social);
        $this->assertEquals('ZZ PEREZ JUAN', $client->name, 'El alta de la persona física no guardó el nombre que mandó ARCA.');
        $this->assertEquals('12345678', $client->dni);
    }
}
