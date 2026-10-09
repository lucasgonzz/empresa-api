<?php

namespace Tests\Feature\Sales;

use App\Http\Controllers\Helpers\SaleDeliveryInfoHelper;
use App\Models\Address;
use App\Models\Client;
use App\Models\Sale;
use App\Models\SaleDeliveryInfo;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Misión etiqueta-envio-direccion (9/10/2026): la etiqueta de envío en PDF no traía la dirección del
 * destinatario (calle y número), el modal "Datos de envío (etiqueta)" no tenía dónde cargarla, y el
 * renglón del documento decía "DNI:" aunque mostrara un CUIT.
 *
 * Lo que fija este archivo:
 *
 * 1. `SaleDeliveryInfoHelper::resolved_for_etiqueta_pdf()` suma `address`: la cargada en los datos
 *    de envío si tiene texto; si no, el domicilio del cliente, que es la COLUMNA DE TEXTO
 *    `clients.address` — no la relación `Client::address()`, que es la sucursal. Con los espacios y
 *    saltos de línea colapsados a uno.
 * 2. Suma `document_label`: 'DNI', 'CUIT' o 'DNI/CUIT' si no hay documento. Un DNI del cliente ''
 *    o de solo espacios cuenta como vacío y cae al CUIT (antes, `dni ?? cuit` dejaba pasar el '').
 * 3. `PUT api/sale/{id}/delivery-info` guarda `address`, y un PUT SIN la clave (el SPA viejo, que
 *    no la conoce) no borra la que ya estaba guardada.
 *
 * El GET del PDF (`sale/etiqueta-envio/pdf/{id}`) no se puede medir acá: `EtiquetaEnvioPdf` hace
 * `Output(); exit;` y cortaría el proceso de phpunit. Se verifica bajándolo.
 *
 * DatabaseTransactions (por EmpresaTestCase) sobre la base sembrada del slot.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class EtiquetaEnvioDireccionTest extends EmpresaTestCase
{
    /** @var \App\Models\User Dueño del fixture (el mismo que autentica EmpresaTestCase). */
    protected $dueno;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * Un cliente del dueño, con los atributos que pida el test.
     *
     * @param array $atributos
     * @return Client
     */
    private function crear_cliente($atributos = [])
    {
        return Client::create(array_merge([
            'name' => 'Destinatario Etiqueta Test',
            'user_id' => $this->dueno->id,
        ], $atributos));
    }

    /**
     * Una venta del dueño, con su cliente (o sin cliente) y, si se pasan, sus datos de envío.
     *
     * @param Client|null $cliente
     * @param array|null $envio Campos de SaleDeliveryInfo; null = sin datos de envío cargados.
     * @return Sale
     */
    private function crear_venta($cliente, $envio = null)
    {
        $venta = Sale::create([
            'user_id' => $this->dueno->id,
            'client_id' => is_null($cliente) ? null : $cliente->id,
            'moneda_id' => 1,
            'total' => 0,
        ]);

        if (!is_null($envio)) {
            SaleDeliveryInfo::create(array_merge(['sale_id' => $venta->id], $envio));
        }

        return $venta;
    }

    /**
     * Lo que imprime la etiqueta para la venta, cargada con las mismas relaciones que
     * `SaleController::etiqueta_envio()`.
     *
     * @param Sale $venta
     * @return array<string, string>
     */
    private function etiqueta($venta)
    {
        $sale = Sale::where('id', $venta->id)
            ->with(['client.location.provincia', 'sale_delivery_info'])
            ->first();

        return SaleDeliveryInfoHelper::resolved_for_etiqueta_pdf($sale);
    }

    // ── La dirección ────────────────────────────────────────────────────────────────────────

    /**
     * Sin datos de envío, o con datos de envío sin dirección, la etiqueta usa el domicilio del
     * cliente. Y las ocho claves de antes siguen estando.
     *
     * @group etiqueta-envio
     * @test
     */
    public function sin_direccion_cargada_va_el_domicilio_del_cliente()
    {
        $cliente = $this->crear_cliente(['address' => 'Av Pellegrini 1500']);

        $sin_envio = $this->etiqueta($this->crear_venta($cliente));
        $this->assertSame('Av Pellegrini 1500', $sin_envio['address']);

        foreach (['first_name', 'last_name', 'phone', 'document', 'locality', 'province', 'postal_code', 'email'] as $clave) {
            $this->assertArrayHasKey($clave, $sin_envio, 'La clave '.$clave.' de antes tiene que seguir.');
        }

        $envio_sin_direccion = $this->etiqueta($this->crear_venta($cliente, ['phone' => '3415550000']));
        $this->assertSame('Av Pellegrini 1500', $envio_sin_direccion['address']);
        $this->assertSame('3415550000', $envio_sin_direccion['phone']);
    }

    /**
     * La dirección cargada en los datos de envío pisa la del cliente.
     *
     * @group etiqueta-envio
     * @test
     */
    public function la_direccion_cargada_pisa_la_del_cliente()
    {
        $cliente = $this->crear_cliente(['address' => 'Av Pellegrini 1500']);

        $etiqueta = $this->etiqueta($this->crear_venta($cliente, ['address' => 'Mitre 742 Piso 3']));

        $this->assertSame('Mitre 742 Piso 3', $etiqueta['address']);
    }

    /**
     * Una dirección cargada vacía (o de solo espacios) es como no haberla cargado: cae a la del
     * cliente, igual que el resto de los campos de los datos de envío.
     *
     * @group etiqueta-envio
     * @test
     */
    public function una_direccion_cargada_vacia_cae_a_la_del_cliente()
    {
        $cliente = $this->crear_cliente(['address' => 'Av Pellegrini 1500']);

        $this->assertSame('Av Pellegrini 1500', $this->etiqueta($this->crear_venta($cliente, ['address' => '']))['address']);
        $this->assertSame('Av Pellegrini 1500', $this->etiqueta($this->crear_venta($cliente, ['address' => "   \n "]))['address']);
    }

    /**
     * Espacios, tabulaciones y saltos de línea seguidos se colapsan a uno, en el domicilio del
     * cliente y en la dirección cargada: la etiqueta la imprime en renglones de una sola línea.
     *
     * @group etiqueta-envio
     * @test
     */
    public function los_espacios_y_saltos_de_linea_se_colapsan()
    {
        $cliente = $this->crear_cliente(['address' => "  Av   Pellegrini\r\n 1500\t PB  "]);

        $this->assertSame('Av Pellegrini 1500 PB', $this->etiqueta($this->crear_venta($cliente))['address']);
        $this->assertSame('Mitre 742 Dto B', $this->etiqueta($this->crear_venta($cliente, ['address' => "Mitre\n742   Dto B "]))['address']);
    }

    /**
     * Un cliente sin domicilio, o una venta sin cliente, da dirección vacía (la etiqueta imprime el
     * rótulo solo, para completarla a mano).
     *
     * @group etiqueta-envio
     * @test
     */
    public function sin_domicilio_ni_direccion_cargada_queda_vacia()
    {
        $sin_domicilio = $this->crear_cliente(['address' => null]);
        $this->assertSame('', $this->etiqueta($this->crear_venta($sin_domicilio))['address']);

        $sin_cliente = $this->etiqueta($this->crear_venta(null));
        $this->assertSame('', $sin_cliente['address']);
        $this->assertSame('', $sin_cliente['document']);
        $this->assertSame('DNI/CUIT', $sin_cliente['document_label']);
    }

    /**
     * 🔴 La trampa del modelo: `Client::address()` es la RELACIÓN con la sucursal
     * (`address_id` -> `addresses`), no el domicilio. Con la relación cargada, la etiqueta sigue
     * diciendo el domicilio de texto; un cliente sin domicilio de texto no imprime la calle de la
     * sucursal; y si el cliente vino sin la columna de texto en el select (donde Eloquent
     * devolvería la relación en su lugar), tampoco.
     *
     * @group etiqueta-envio
     * @test
     */
    public function la_sucursal_del_cliente_no_se_confunde_con_su_domicilio()
    {
        $sucursal = Address::create(['street' => 'Belgrano', 'street_number' => '450', 'city' => 'Rosario', 'user_id' => $this->dueno->id]);

        /** Con domicilio de texto y la relación cargada: el domicilio. */
        $con_domicilio = $this->crear_cliente(['address' => 'Av Pellegrini 1500', 'address_id' => $sucursal->id]);
        $venta = $this->crear_venta($con_domicilio);
        $sale = Sale::with(['client.address', 'sale_delivery_info'])->find($venta->id);
        $this->assertTrue($sale->client->relationLoaded('address'));
        $this->assertSame('Av Pellegrini 1500', SaleDeliveryInfoHelper::resolved_for_etiqueta_pdf($sale)['address']);

        /** Sin domicilio de texto y con sucursal: vacía, no "Belgrano 450". */
        $sin_domicilio = $this->crear_cliente(['address' => null, 'address_id' => $sucursal->id]);
        $venta = $this->crear_venta($sin_domicilio);
        $sale = Sale::with(['client.address', 'sale_delivery_info'])->find($venta->id);
        $this->assertSame('', SaleDeliveryInfoHelper::resolved_for_etiqueta_pdf($sale)['address']);

        /** Cliente leído sin la columna de texto: `$client->address` sería la sucursal (un objeto). */
        $venta = $this->crear_venta($con_domicilio);
        $sale = Sale::with('sale_delivery_info')->find($venta->id);
        $sale->setRelation('client', Client::select(['id', 'name', 'address_id', 'dni', 'cuit', 'phone', 'email', 'location_id', 'user_id'])->find($con_domicilio->id));
        $this->assertInstanceOf(Address::class, $sale->client->address, 'Precondición: sin la columna, Eloquent devuelve la relación.');
        $this->assertSame('', SaleDeliveryInfoHelper::resolved_for_etiqueta_pdf($sale)['address']);
    }

    // ── El rótulo del documento ─────────────────────────────────────────────────────────────

    /**
     * El rótulo dice qué documento es: el DNI del cliente se rotula "DNI" y, sin DNI, el CUIT se
     * rotula "CUIT" (antes decía "DNI:" con un CUIT al lado).
     *
     * @group etiqueta-envio
     * @test
     */
    public function el_documento_del_cliente_va_con_su_rotulo()
    {
        $con_dni = $this->etiqueta($this->crear_venta($this->crear_cliente(['dni' => '30111222', 'cuit' => '20301112223'])));
        $this->assertSame('30111222', $con_dni['document']);
        $this->assertSame('DNI', $con_dni['document_label']);

        $solo_cuit = $this->etiqueta($this->crear_venta($this->crear_cliente(['dni' => null, 'cuit' => '20301112223'])));
        $this->assertSame('20301112223', $solo_cuit['document']);
        $this->assertSame('CUIT', $solo_cuit['document_label']);
    }

    /**
     * Los overrides de los datos de envío: el DNI cargado va como "DNI"; sin DNI cargado, el CUIT
     * cargado va como "CUIT" aunque el cliente tenga DNI.
     *
     * @group etiqueta-envio
     * @test
     */
    public function el_documento_cargado_en_los_datos_de_envio_va_con_su_rotulo()
    {
        $cliente = $this->crear_cliente(['dni' => '30111222', 'cuit' => '20301112223']);

        $dni_cargado = $this->etiqueta($this->crear_venta($cliente, ['dni' => '40999888', 'cuit' => '27111111114']));
        $this->assertSame('40999888', $dni_cargado['document']);
        $this->assertSame('DNI', $dni_cargado['document_label']);

        $cuit_cargado = $this->etiqueta($this->crear_venta($cliente, ['dni' => '', 'cuit' => '27-11111111-4']));
        $this->assertSame('27-11111111-4', $cuit_cargado['document']);
        $this->assertSame('CUIT', $cuit_cargado['document_label']);
    }

    /**
     * Un DNI del cliente '' (o de solo espacios) cuenta como vacío y cae al CUIT. Antes,
     * `dni ?? cuit` dejaba pasar el '' y la etiqueta salía sin documento teniendo CUIT.
     *
     * @group etiqueta-envio
     * @test
     */
    public function un_dni_vacio_del_cliente_cae_al_cuit()
    {
        $dni_vacio = $this->etiqueta($this->crear_venta($this->crear_cliente(['dni' => '', 'cuit' => '20301112223'])));
        $this->assertSame('20301112223', $dni_vacio['document']);
        $this->assertSame('CUIT', $dni_vacio['document_label']);

        $dni_espacios = $this->etiqueta($this->crear_venta($this->crear_cliente(['dni' => '   ', 'cuit' => '20301112223'])));
        $this->assertSame('20301112223', $dni_espacios['document']);
        $this->assertSame('CUIT', $dni_espacios['document_label']);
    }

    /**
     * Sin documento en ningún lado: documento vacío y rótulo "DNI/CUIT" (el renglón se imprime
     * igual, para completarlo a mano).
     *
     * @group etiqueta-envio
     * @test
     */
    public function sin_documento_el_rotulo_es_dni_cuit()
    {
        $cliente = $this->crear_cliente(['dni' => null, 'cuit' => '']);

        $sin_envio = $this->etiqueta($this->crear_venta($cliente));
        $this->assertSame('', $sin_envio['document']);
        $this->assertSame('DNI/CUIT', $sin_envio['document_label']);

        $envio_vacio = $this->etiqueta($this->crear_venta($cliente, ['dni' => ' ', 'cuit' => null]));
        $this->assertSame('', $envio_vacio['document']);
        $this->assertSame('DNI/CUIT', $envio_vacio['document_label']);
    }

    // ── PUT api/sale/{id}/delivery-info ─────────────────────────────────────────────────────

    /**
     * El PUT guarda la dirección y la venta que devuelve la trae en `sale_delivery_info.address`.
     *
     * @group etiqueta-envio
     * @test
     */
    public function el_put_guarda_la_direccion()
    {
        $venta = $this->crear_venta($this->crear_cliente(['address' => 'Av Pellegrini 1500']));

        $respuesta = $this->putJson('api/sale/'.$venta->id.'/delivery-info', [
            'first_name' => 'Juan',
            'last_name' => 'Perez',
            'address' => 'Mitre 742 Piso 3',
            'locality' => 'Rosario',
        ]);

        $respuesta->assertStatus(200);
        $this->assertSame('Mitre 742 Piso 3', $respuesta->json('model.sale_delivery_info.address'));
        $this->assertSame('Mitre 742 Piso 3', SaleDeliveryInfo::where('sale_id', $venta->id)->value('address'));
        $this->assertSame('Mitre 742 Piso 3', $this->etiqueta($venta)['address']);
    }

    /**
     * 🔴 Compatibilidad hacia atrás: un PUT SIN la clave `address` (el SPA viejo, una PWA con caché
     * o el otro frente) no borra la dirección guardada, y el resto de los campos se guarda igual.
     * Mandarla vacía sí la limpia, y la etiqueta vuelve al domicilio del cliente.
     *
     * @group etiqueta-envio
     * @test
     */
    public function un_put_sin_la_clave_no_borra_la_direccion_guardada()
    {
        $venta = $this->crear_venta($this->crear_cliente(['address' => 'Av Pellegrini 1500']));

        $this->putJson('api/sale/'.$venta->id.'/delivery-info', [
            'first_name' => 'Juan',
            'address' => 'Mitre 742 Piso 3',
        ])->assertStatus(200);

        /** El PUT del SPA viejo: los nueve campos de siempre, sin `address`. */
        $this->putJson('api/sale/'.$venta->id.'/delivery-info', [
            'first_name' => 'Juana',
            'last_name' => 'Gomez',
            'phone' => '3415550000',
            'dni' => '',
            'cuit' => '',
            'locality' => 'Funes',
            'province' => 'Santa Fe',
            'postal_code' => '2132',
            'email' => '',
        ])->assertStatus(200);

        $guardado = SaleDeliveryInfo::where('sale_id', $venta->id)->first();
        $this->assertSame('Mitre 742 Piso 3', $guardado->address, 'Un PUT sin la clave address borró la dirección guardada.');
        $this->assertSame('Juana', $guardado->first_name);
        $this->assertSame('Funes', $guardado->locality);

        /** Mandarla vacía la limpia: la etiqueta vuelve al domicilio del cliente. */
        $this->putJson('api/sale/'.$venta->id.'/delivery-info', [
            'first_name' => 'Juana',
            'address' => '',
        ])->assertStatus(200);

        $this->assertNull(SaleDeliveryInfo::where('sale_id', $venta->id)->value('address'));
        $this->assertSame('Av Pellegrini 1500', $this->etiqueta($venta)['address']);
    }
}
