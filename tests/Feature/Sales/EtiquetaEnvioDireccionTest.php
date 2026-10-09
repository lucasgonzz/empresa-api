<?php

namespace Tests\Feature\Sales;

use App\Http\Controllers\Helpers\PdfLayout\CamposDeVentaPdf;
use App\Http\Controllers\Helpers\SaleDeliveryInfoHelper;
use App\Http\Controllers\Pdf\EtiquetaEnvioPdf;
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
 *    no la conoce) no borra la que ya estaba guardada. Una dirección de 300 caracteres se guarda
 *    entera (la columna es TEXT, como `clients.address`).
 * 4. `EtiquetaEnvioPdf::renglones_de_direccion()` parte la dirección por palabras en hasta 3
 *    renglones que entran en la celda, sin romper los acentos, y no deja el primero con el rótulo
 *    solo.
 * 5. El campo "Datos de envío" del PDF de la venta (`CamposDeVentaPdf`) pone la dirección cargada
 *    en los datos de envío adelante del lugar.
 *
 * El GET del PDF (`sale/etiqueta-envio/pdf/{id}`) no se puede medir acá: `EtiquetaEnvioPdf` hace
 * `Output(); exit;` y cortaría el proceso de phpunit. Se verifica bajándolo. El partido de la
 * dirección sí se mide, instanciando la clase sin su constructor (ver etiqueta_para_medir()).
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

    /**
     * 🔴 Un domicilio largo no rompe el modal. El modal precarga el domicilio del cliente
     * (`clients.address`, TEXT) y lo manda siempre: con la columna en varchar(255), uno de más de
     * 255 caracteres daba "Data too long" (500, MySQL estricto) al guardar, aunque el usuario solo
     * hubiera cambiado el teléfono. La columna es TEXT y la dirección se guarda entera.
     *
     * @group etiqueta-envio
     * @test
     */
    public function el_put_guarda_entera_una_direccion_de_300_caracteres()
    {
        $larga = substr(str_repeat('Av Presidente Domingo Faustino Sarmiento 1500 ', 7), 0, 299).'X';
        $this->assertSame(300, mb_strlen($larga, 'UTF-8'));

        $venta = $this->crear_venta($this->crear_cliente(['address' => $larga]));

        $respuesta = $this->putJson('api/sale/'.$venta->id.'/delivery-info', [
            'phone' => '3415550000',
            'address' => $larga,
        ]);

        $respuesta->assertStatus(200);
        $this->assertSame($larga, SaleDeliveryInfo::where('sale_id', $venta->id)->value('address'));
        $this->assertSame($larga, $respuesta->json('model.sale_delivery_info.address'));
    }

    // ── El campo "Datos de envío" del PDF de la venta ───────────────────────────────────────

    /**
     * Lo que imprime el campo "Datos de envío" del diseño de página para la venta.
     *
     * @param Sale $venta
     * @return array<int, string>|null
     */
    private function datos_de_envio_del_pdf($venta)
    {
        $fuente = new CamposDeVentaPdf(Sale::find($venta->id), $this->dueno, false, 'descriptivo');

        return $fuente->valor('venta_datos_de_envio', [
            'key' => 'venta_datos_de_envio',
            'etiqueta' => null,
            'tamano' => null,
            'negrita' => null,
            'cursiva' => null,
            'alineacion' => null,
        ]);
    }

    /**
     * Con una dirección CARGADA en los datos de envío, el segundo renglón del campo es esa
     * dirección (no el domicilio del cliente) adelante del lugar. Y si no hay lugar, la dirección
     * sola.
     *
     * @group etiqueta-envio
     * @test
     */
    public function los_datos_de_envio_del_pdf_de_la_venta_usan_la_direccion_cargada()
    {
        $cliente = $this->crear_cliente(['address' => 'Av Pellegrini 1500', 'dni' => '30111222']);
        $venta = $this->crear_venta($cliente, [
            'first_name' => 'Juana',
            'last_name' => 'Gomez',
            'phone' => '3415550000',
            'address' => 'Mitre 742 Piso 3',
            'locality' => 'Funes',
            'province' => 'Santa Fe',
            'postal_code' => '2132',
            'email' => 'juana@correo.local',
        ]);

        $this->assertSame([
            'Juana Gomez · 3415550000',
            'Mitre 742 Piso 3, Funes, Santa Fe (2132)',
            'juana@correo.local / DNI 30111222',
        ], $this->datos_de_envio_del_pdf($venta));

        $solo_direccion = $this->crear_venta($this->crear_cliente(['address' => 'Av Pellegrini 1500', 'cuit' => '20301112223']), ['address' => 'Mitre 742']);

        $this->assertSame([
            'Destinatario Etiqueta Test',
            'Mitre 742',
            'CUIT 20301112223',
        ], $this->datos_de_envio_del_pdf($solo_direccion));
    }

    // ── El partido de la dirección en la etiqueta ───────────────────────────────────────────

    /**
     * Una etiqueta lista para medir, sin dibujar nada. El constructor de EtiquetaEnvioPdf arma el
     * PDF entero y hace `Output(); exit;`, así que se instancia sin constructor, se inicializa FPDF
     * a mano y se pone la fuente con la que se imprime la dirección (Arial negrita 12):
     * renglones_de_direccion() necesita el margen de la celda (lo pone el constructor de FPDF) y la
     * fuente actual (para GetStringWidth).
     *
     * @return EtiquetaEnvioPdf
     */
    private function etiqueta_para_medir()
    {
        $pdf = (new \ReflectionClass(EtiquetaEnvioPdf::class))->newInstanceWithoutConstructor();
        (new \ReflectionMethod(\FPDF::class, '__construct'))->invoke($pdf);
        $pdf->SetFont('Arial', 'B', 12);

        return $pdf;
    }

    /**
     * Ancho útil de un renglón de la dirección: los 200 mm de la celda menos su margen interno a
     * cada lado (`cMargin`, protegido en FPDF).
     *
     * @param EtiquetaEnvioPdf $pdf
     * @return float
     */
    private function ancho_util($pdf)
    {
        $margen = new \ReflectionProperty(\FPDF::class, 'cMargin');
        $margen->setAccessible(true);

        return 200 - 2 * $margen->getValue($pdf);
    }

    /**
     * Cada renglón es UTF-8 válido (un corte por bytes partiría un acento al medio) y entra en la
     * celda, medido como lo imprime Cell: GetStringWidth(utf8_decode(...)).
     *
     * @param EtiquetaEnvioPdf $pdf
     * @param array<int, string> $renglones
     * @return void
     */
    private function assert_renglones_validos($pdf, $renglones)
    {
        $ancho = $this->ancho_util($pdf);

        foreach ($renglones as $renglon) {
            $this->assertTrue(mb_check_encoding($renglon, 'UTF-8'), 'Renglón con UTF-8 roto: '.bin2hex($renglon));
            $this->assertLessThanOrEqual($ancho, $pdf->GetStringWidth(utf8_decode($renglon)), 'El renglón no entra en la celda: '.$renglon);
        }
    }

    /**
     * Corta, un renglón; larga, dos, partidos entre palabras; larguísima, tres y el último termina
     * en "..."; sin dirección, el rótulo solo. Ningún renglón pasa del ancho y los acentos y la
     * "Ñ" salen enteros.
     *
     * @group etiqueta-envio
     * @test
     */
    public function la_direccion_de_la_etiqueta_se_parte_en_renglones_que_entran()
    {
        $pdf = $this->etiqueta_para_medir();

        $this->assertSame(['Dirección: Av Pellegrini 1500'], $pdf->renglones_de_direccion('Av Pellegrini 1500'));

        $larga = 'Avenida Presidente Domingo Faustino Sarmiento 12345, Barrio Parque Las Acacias, Manzana 14 Lote 22, entre calles Güemes y Belgrano, portón verde con timbre';
        $renglones = $pdf->renglones_de_direccion($larga);
        $this->assertCount(2, $renglones);
        $this->assertSame('Dirección: '.$larga, implode(' ', $renglones), 'Partida entre palabras: unida con espacios vuelve a ser la misma.');
        $this->assert_renglones_validos($pdf, $renglones);

        $larguisima = trim(str_repeat('Calle Larguísima Número 1234 Bis Departamento Ñandú ', 8));
        $renglones = $pdf->renglones_de_direccion($larguisima);
        $this->assertCount(3, $renglones, 'Tope de 3 renglones.');
        $this->assertStringStartsWith('Dirección: Calle Larguísima', $renglones[0]);
        $this->assertSame('...', mb_substr($renglones[2], -3, null, 'UTF-8'), 'Lo que sobra se corta con "...".');
        $this->assertStringContainsString('Ñandú', implode(' ', $renglones));
        $this->assert_renglones_validos($pdf, $renglones);

        $this->assertSame(['Dirección: '], $pdf->renglones_de_direccion(''));
        $this->assertSame(['Dirección: '], $pdf->renglones_de_direccion('   '));
    }

    /**
     * Una palabra más ancha que el renglón se corta por caracteres, no por bytes: la "Ñ" y los
     * acentos salen enteros. Y si esa palabra es la PRIMERA, se corta al ancho que queda al lado del
     * rótulo: el primer renglón no queda con "Dirección:" solo, gastando uno de los tres.
     *
     * @group etiqueta-envio
     * @test
     */
    public function una_palabra_mas_ancha_que_el_renglon_se_corta_sin_romper_los_acentos()
    {
        $pdf = $this->etiqueta_para_medir();

        $palabra = str_repeat('Ñ', 120);
        $renglones = $pdf->renglones_de_direccion($palabra);
        $this->assertCount(2, $renglones);
        $this->assertStringStartsWith('Dirección: Ñ', $renglones[0], 'El primer renglón no puede quedar con el rótulo solo.');
        $this->assertSame('Dirección: '.$palabra, $renglones[0].$renglones[1], 'Cortada por caracteres, sin perder ninguno.');
        $this->assert_renglones_validos($pdf, $renglones);

        $acentos = str_repeat('áéíóúü', 40);
        $renglones = $pdf->renglones_de_direccion($acentos);
        $this->assertStringStartsWith('Dirección: á', $renglones[0]);
        $this->assert_renglones_validos($pdf, $renglones);

        /** Una palabra normal adelante y la larga después: esa sí pasa entera al renglón siguiente. */
        $renglones = $pdf->renglones_de_direccion('Ruta '.$palabra);
        $this->assertSame('Dirección: Ruta', $renglones[0]);
        $this->assert_renglones_validos($pdf, $renglones);
    }
}
