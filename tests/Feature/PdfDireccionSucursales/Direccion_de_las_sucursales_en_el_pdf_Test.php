<?php

namespace Tests\Feature\PdfDireccionSucursales;

use App\Console\Commands\SumarSucursalAlEncabezadoPdf;
use App\Http\Controllers\Helpers\PdfDocument\BudgetPdfDocument;
use App\Http\Controllers\Helpers\PdfDocument\OrderPdfDocument;
use App\Http\Controllers\Helpers\PdfDocumentSetupHelper;
use App\Http\Controllers\Pdf\Afip\AfipPdfHelper;
use App\Http\Controllers\Pdf\NewSalePdf;
use App\Models\Address;
use App\Models\PdfColumnProfile;
use App\Models\AfipInformation;
use App\Models\Sale;
use App\Models\SheetType;
use App\Models\User;
use Tests\Concerns\DocumentosParaPdf;
use Tests\EmpresaTestCase;
use Tests\Feature\Pdf\Concerns\ComprobantesConDisenoDePagina;

/**
 * La dirección del PDF de venta, presupuesto y pedido sale de las sucursales (misión
 * pdf-direccion-sucursales, 23/10/2026).
 *
 * Tres chips nuevos del emisor en `header_layout`: `sucursal_comprobante`, `todas_las_sucursales` y
 * `sucursal_<id>`. Se lee el PDF real (sin comprimir) y se prueba:
 *
 *  - el renglón ("Dirección: " con una sola sucursal, "Sucursal <Nombre>: " con dos o más) en el
 *    remito y la factura de siempre (NewSalePdf), con cajas (SaleLayoutPdf), el presupuesto y el
 *    pedido online;
 *  - lo que NO se imprime: sucursal sin domicilio ni ciudad, ajena, de un comprador o borrada, y la
 *    misma sucursal dos veces;
 *  - lo que tiene que seguir igual: un perfil con header_layout guardado y sin chips de sucursal;
 *  - el default (D6), la factura (enforce_fiscal_required_fields), el guardado por la API y el
 *    comando `pdf:sumar-sucursal-al-encabezado` (D7).
 *
 * @group pdf-direccion-sucursales
 */
class Direccion_de_las_sucursales_en_el_pdf_Test extends EmpresaTestCase
{
    use DocumentosParaPdf;
    use ComprobantesConDisenoDePagina;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_dueno_para_pdf();

        $this->actingAs($this->dueno, 'web');

        require_once app_path('Http/Controllers/CommonLaravel/fpdf/fpdf.php');
    }

    protected function tearDown(): void
    {
        $this->borrar_archivos_de_prueba();

        parent::tearDown();
    }

    // ── Ayudas ──────────────────────────────────────────────────────────────────────────────

    /**
     * El header_layout que guardaba un perfil ANTES de esta misión (el default de entonces): sin
     * chips de sucursal.
     *
     * @param bool $fiscal
     * @return array
     */
    protected function layout_de_antes($fiscal = false)
    {
        $derecha = ['numero_comprobante', 'fecha_emision', 'cuit', 'ingresos_brutos', 'inicio_actividades'];
        if ($fiscal) {
            $derecha[] = 'punto_venta';
        }

        return [
            'emisor' => [
                'izquierda' => ['razon_social', 'domicilio_comercial', 'condicion_iva'],
                'derecha' => $derecha,
            ],
            'receptor' => [
                'izquierda' => ['cliente_nombre', 'cliente_telefono', 'cliente_localidad', 'cliente_direccion', 'cliente_cuit'],
            ],
        ];
    }

    /**
     * El layout de antes con chips en la izquierda después del Domicilio Comercial y, si se pide,
     * otros al final de la derecha.
     *
     * @param array $izquierda
     * @param array $derecha
     * @param bool  $fiscal
     * @return array
     */
    protected function layout_con(array $izquierda, array $derecha = [], $fiscal = false)
    {
        $layout = $this->layout_de_antes($fiscal);

        array_splice($layout['emisor']['izquierda'], 2, 0, $izquierda);
        $layout['emisor']['derecha'] = array_merge($layout['emisor']['derecha'], $derecha);

        return $layout;
    }

    /**
     * El remito o la factura de siempre (NewSalePdf) armado SIN emitirlo.
     *
     * @param \App\Models\Sale             $venta
     * @param \App\Models\PdfColumnProfile $perfil
     * @param mixed                        $afip_ticket_id
     * @return string
     */
    protected function pdf_de_siempre($venta, PdfColumnProfile $perfil, $afip_ticket_id = null)
    {
        $pdf = new NewSalePdf(Sale::find($venta->id), $perfil->id, $afip_ticket_id, null, false);
        $pdf->SetCompression(false);

        return $pdf->Output('S');
    }

    /**
     * @param string $pdf
     * @return string
     */
    protected function sin_fecha_de_creacion($pdf)
    {
        return preg_replace('~/CreationDate \(D:\d+\)~', '/CreationDate (D:0)', $pdf);
    }

    /**
     * La venta completa (su sucursal es "Belgrano", Domicilio "450", Rosario) y su sucursal.
     *
     * @return array{0: \App\Models\Sale, 1: \App\Models\Address}
     */
    protected function venta_con_su_sucursal()
    {
        $venta = $this->crear_venta_completa();

        return [$venta, Address::find($venta->address_id)];
    }

    /**
     * La base (mm desde arriba) del primer texto igual a $texto en la primera hoja.
     *
     * @param string $texto
     * @param string $pdf
     * @return float
     */
    protected function y_de($texto, $pdf)
    {
        foreach ($this->textos_con_posicion($pdf) as $dibujado) {
            if ($dibujado['texto'] === $texto) {
                return $dibujado['y'];
            }
        }

        $this->fail('El PDF no dibuja el texto: '.$texto);
    }

    // ── El default (D6) ─────────────────────────────────────────────────────────────────────

    /**
     * @test
     */
    public function el_default_suma_la_sucursal_del_comprobante_despues_del_domicilio_comercial()
    {
        foreach ([false, true] as $fiscal) {
            $this->assertSame(
                ['razon_social', 'domicilio_comercial', 'sucursal_comprobante', 'condicion_iva'],
                PdfColumnProfile::default_header_layout($fiscal)['emisor']['izquierda']
            );
        }

        foreach (['budget', 'order'] as $modelo) {
            $this->assertSame(
                ['razon_social', 'domicilio_comercial', 'sucursal_comprobante', 'condicion_iva'],
                PdfDocumentSetupHelper::default_header_layout_for($modelo)['emisor']['izquierda']
            );
        }
    }

    // ── Venta: remito de siempre ─────────────────────────────────────────────────────────────

    /**
     * @test
     */
    public function el_remito_sin_diseno_guardado_imprime_la_sucursal_de_la_venta_como_direccion()
    {
        list($venta) = $this->venta_con_su_sucursal();

        $pdf = $this->pdf_de_siempre($venta, $this->perfil_de_venta(['header_layout' => null]));

        $this->assertRenglon('Domicilio Comercial: ', 'Calle Falsa 123', $pdf);
        $this->assertRenglon('Dirección: ', '450, Rosario', $pdf);

        /** Pegada al Domicilio Comercial: el renglón siguiente. */
        $this->assertGreaterThan($this->y_de('Domicilio Comercial: ', $pdf), $this->y_de('Dirección: ', $pdf));
        $this->assertLessThan($this->y_de('Condición IVA: ', $pdf), $this->y_de('Dirección: ', $pdf));
    }

    /**
     * @test
     */
    public function un_diseno_guardado_sin_chips_de_sucursal_sale_igual_que_siempre()
    {
        list($venta, $sucursal) = $this->venta_con_su_sucursal();
        $perfil = $this->perfil_de_venta(['header_layout' => $this->layout_de_antes()]);

        $pdf = $this->sin_fecha_de_creacion($this->pdf_de_siempre($venta, $perfil));

        /** Ojo: "Dirección: " también es el rótulo del domicilio del cliente; se mira el valor. */
        $this->assertNoDibujaTexto('450, Rosario', $pdf);

        /** Ni las sucursales del dueño lo cambian: el PDF no las lee. */
        $sucursal->update(['street_number' => 'Otra calle 999']);
        Address::create(['street' => 'Centro', 'street_number' => 'Mitre 1', 'city' => 'Rosario', 'user_id' => $this->dueno->id]);

        $this->assertSame($pdf, $this->sin_fecha_de_creacion($this->pdf_de_siempre($venta, $perfil)));
    }

    /**
     * @test
     */
    public function con_dos_sucursales_el_rotulo_lleva_el_nombre_y_todas_salen_con_la_principal_primero()
    {
        list($venta) = $this->venta_con_su_sucursal();
        Address::create([
            'street' => 'Centro',
            'street_number' => 'San Martin 100',
            'city' => 'Rosario',
            'province' => 'Santa Fe',
            'default_address' => 1,
            'user_id' => $this->dueno->id,
        ]);

        $solo_la_de_la_venta = $this->pdf_de_siempre($venta, $this->perfil_de_venta(['header_layout' => $this->layout_con(['sucursal_comprobante'])]));
        $this->assertRenglon('Sucursal Belgrano: ', '450, Rosario', $solo_la_de_la_venta);
        $this->assertNoDibujaTexto('San Martin 100, Rosario, Santa Fe', $solo_la_de_la_venta);

        $todas = $this->pdf_de_siempre($venta, $this->perfil_de_venta(['header_layout' => $this->layout_con(['todas_las_sucursales'])]));
        $this->assertRenglon('Sucursal Belgrano: ', '450, Rosario', $todas);
        $this->assertRenglon('Sucursal Centro: ', 'San Martin 100, Rosario, Santa Fe', $todas);
        $this->assertLessThan($this->y_de('Sucursal Belgrano: ', $todas), $this->y_de('Sucursal Centro: ', $todas), 'La principal va primero.');
    }

    /**
     * @test
     */
    public function una_sucursal_puntual_se_imprime_y_la_misma_sucursal_no_se_repite()
    {
        list($venta, $sucursal) = $this->venta_con_su_sucursal();
        $centro = Address::create(['street' => 'Centro', 'street_number' => 'San Martin 100', 'city' => 'Rosario', 'user_id' => $this->dueno->id]);

        $puntual = $this->pdf_de_siempre($venta, $this->perfil_de_venta(['header_layout' => $this->layout_con(['sucursal_'.$centro->id])]));
        $this->assertRenglon('Sucursal Centro: ', 'San Martin 100, Rosario', $puntual);
        $this->assertNoDibujaTexto('450, Rosario', $puntual);

        /** La del comprobante a la izquierda y la misma, puntual, a la derecha: una sola vez. */
        $repetida = $this->pdf_de_siempre($venta, $this->perfil_de_venta([
            'header_layout' => $this->layout_con(['sucursal_comprobante'], ['sucursal_'.$sucursal->id, 'todas_las_sucursales']),
        ]));
        $this->assertSame(1, $this->veces_que_se_dibuja('450, Rosario', $repetida));
        $this->assertSame(1, $this->veces_que_se_dibuja('San Martin 100, Rosario', $repetida), 'Todas las sucursales suma solo la que faltaba.');
    }

    /**
     * @test
     */
    public function no_se_imprime_una_sucursal_sin_domicilio_ni_ciudad_ajena_de_comprador_o_borrada()
    {
        list($venta, $sucursal) = $this->venta_con_su_sucursal();

        $otro_dueno = $this->crear_dueno('Otro dueno '.uniqid());
        $ajena = Address::create(['street' => 'Ajena', 'street_number' => 'Lejos 1', 'city' => 'Cordoba', 'user_id' => $otro_dueno->id]);
        $de_comprador = Address::create(['street' => 'Casa', 'street_number' => 'Comprador 5', 'city' => 'Rosario', 'user_id' => $this->dueno->id, 'buyer_id' => 999999]);
        $borrada = Address::create(['street' => 'Borrada', 'street_number' => 'Vieja 7', 'city' => 'Rosario', 'user_id' => $this->dueno->id]);
        $id_borrada = $borrada->id;
        $borrada->delete();

        $pdf = $this->pdf_de_siempre($venta, $this->perfil_de_venta([
            'header_layout' => $this->layout_con(['sucursal_'.$ajena->id, 'sucursal_'.$de_comprador->id, 'sucursal_'.$id_borrada, 'sucursal_999999999']),
        ]));
        $this->assertNoDibujaTexto('Lejos 1, Cordoba', $pdf);
        $this->assertNoDibujaTexto('Comprador 5, Rosario', $pdf);
        $this->assertNoDibujaTexto('Vieja 7, Rosario', $pdf);

        /** El comprador no cuenta como sucursal: el dueño tiene una sola y el rótulo es "Dirección: ". */
        $con_la_suya = $this->pdf_de_siempre($venta, $this->perfil_de_venta(['header_layout' => $this->layout_con(['todas_las_sucursales'])]));
        $this->assertRenglon('Dirección: ', '450, Rosario', $con_la_suya);
        $this->assertNoDibujaTexto('Comprador 5, Rosario', $con_la_suya);

        /** Sin Domicilio ni ciudad (solo la provincia) el renglón no sale, ni su rótulo. */
        $sucursal->update(['street_number' => null, 'city' => null, 'province' => 'Santa Fe']);
        $vacia = $this->pdf_de_siempre($venta, $this->perfil_de_venta(['header_layout' => $this->layout_con(['sucursal_comprobante'])]));
        $this->assertNoDibujaTexto('Santa Fe', $vacia);
        $this->assertSame(
            $this->veces_que_se_dibuja('Dirección: ', $this->pdf_de_siempre($venta, $this->perfil_de_venta(['header_layout' => $this->layout_de_antes()]))),
            $this->veces_que_se_dibuja('Dirección: ', $vacia),
            'Ni el rótulo: solo queda el "Dirección: " del cliente.'
        );

        /** Con dos sucursales y una sin nombre: "Sucursal: ". */
        /** La provincia quedó de la prueba anterior. */
        $sucursal->update(['street' => '', 'street_number' => 'Sin nombre 3', 'city' => null]);
        Address::create(['street' => 'Centro', 'street_number' => 'San Martin 100', 'user_id' => $this->dueno->id]);
        $sin_nombre = $this->pdf_de_siempre($venta, $this->perfil_de_venta(['header_layout' => $this->layout_con(['sucursal_comprobante'])]));
        $this->assertRenglon('Sucursal: ', 'Sin nombre 3, Santa Fe', $sin_nombre);
    }

    /**
     * @test
     */
    public function una_venta_sin_sucursal_no_imprime_el_renglon()
    {
        $venta = $this->crear_venta_completa(['address_id' => null]);

        $pdf = $this->pdf_de_siempre($venta, $this->perfil_de_venta(['header_layout' => null]));

        $this->assertNoDibujaTexto('450, Rosario', $pdf);
        $this->assertRenglon('Domicilio Comercial: ', 'Calle Falsa 123', $pdf);
    }

    /**
     * No repetir el domicilio: una sucursal con el mismo domicilio que el Domicilio Comercial (la
     * Principal que la misión sucursal-siempre precarga con el de ARCA) no sale otra vez.
     *
     * @test
     */
    public function una_sucursal_con_el_mismo_domicilio_que_el_comercial_no_se_repite()
    {
        list($venta, $sucursal) = $this->venta_con_su_sucursal();
        AfipInformation::where('user_id', $this->dueno->id)->update(['domicilio_comercial' => 'Belgrano 450']);

        /** El Domicilio igual con otras mayúsculas, tildes, espacios y un punto o coma al final: no sale. */
        foreach (['BELGRANO 450', 'Belgráno  450.', 'belgrano 450,'] as $domicilio) {
            $sucursal->update(['street_number' => $domicilio, 'city' => 'Rosario']);
            $pdf = $this->pdf_de_siempre($venta, $this->perfil_de_venta(['header_layout' => null]));

            $this->assertRenglon('Domicilio Comercial: ', 'Belgrano 450', $pdf);
            $this->assertNoDibujaTexto($domicilio.', Rosario', $pdf, 'Mismo domicilio: '.$domicilio);
        }

        /** El valor completo (Domicilio + ciudad) igual al comercial también cuenta. */
        AfipInformation::where('user_id', $this->dueno->id)->update(['domicilio_comercial' => 'Belgrano 450, Rosario']);
        $sucursal->update(['street_number' => 'Belgrano 450', 'city' => 'Rosario']);
        $this->assertSame(1, $this->veces_que_se_dibuja('Belgrano 450, Rosario', $this->pdf_de_siempre($venta, $this->perfil_de_venta(['header_layout' => null]))), 'Solo el Domicilio Comercial.');

        /** Con otro domicilio sí sale. */
        $sucursal->update(['street_number' => 'Belgrano 451']);
        $this->assertRenglon('Dirección: ', 'Belgrano 451, Rosario', $this->pdf_de_siempre($venta, $this->perfil_de_venta(['header_layout' => null])));

        /** Con todas las sucursales se saltea solo la que coincide. */
        $sucursal->update(['street_number' => 'Belgrano 450']);
        Address::create(['street' => 'Centro', 'street_number' => 'San Martin 100', 'city' => 'Rosario', 'user_id' => $this->dueno->id]);
        $todas = $this->pdf_de_siempre($venta, $this->perfil_de_venta(['header_layout' => $this->layout_con(['todas_las_sucursales'])]));
        $this->assertSame(1, $this->veces_que_se_dibuja('Belgrano 450, Rosario', $todas), 'Solo el Domicilio Comercial.');
        $this->assertRenglon('Sucursal Centro: ', 'San Martin 100, Rosario', $todas);

        /** Si el encabezado NO imprime el Domicilio Comercial, la sucursal sí sale. */
        $sin_domicilio = $this->layout_con(['sucursal_comprobante']);
        $sin_domicilio['emisor']['izquierda'] = array_values(array_diff($sin_domicilio['emisor']['izquierda'], ['domicilio_comercial']));
        $pdf = $this->pdf_de_siempre($venta, $this->perfil_de_venta(['header_layout' => $sin_domicilio]));
        $this->assertRenglon('Sucursal Belgrano: ', 'Belgrano 450, Rosario', $pdf);
    }

    // ── Venta: con cajas y factura ───────────────────────────────────────────────────────────

    /**
     * @test
     */
    public function el_remito_con_cajas_imprime_la_sucursal_en_el_encabezado()
    {
        list($venta) = $this->venta_con_su_sucursal();

        $pdf = $this->pdf_de_venta($venta, $this->perfil_de_venta(['header_layout' => null], $this->diseno_de_remito_completo()));

        $this->assertRenglon('Dirección: ', '450, Rosario', $pdf);
    }

    /**
     * @test
     */
    public function la_factura_conserva_el_domicilio_comercial_y_suma_la_sucursal()
    {
        list($venta) = $this->venta_con_su_sucursal();
        $ticket = $this->crear_factura($venta, 'B');

        /** El pie de la factura le pega a api.qrserver.com para el QR: con la red cortada se dibuja igual. */
        list($de_siempre, $con_cajas, $todas) = $this->sin_red_https(function () use ($venta, $ticket) {
            return [
                $this->pdf_de_siempre($venta, $this->perfil_de_venta(['is_afip_ticket' => true, 'header_layout' => null]), $ticket->id),
                $this->pdf_de_venta($venta, $this->perfil_de_venta(['is_afip_ticket' => true, 'header_layout' => null], $this->diseno_de_remito_completo()), $ticket->id),
                $this->pdf_de_siempre($venta, $this->perfil_de_venta(['is_afip_ticket' => true, 'header_layout' => $this->layout_con(['todas_las_sucursales'], [], true)]), $ticket->id),
            ];
        });

        foreach ([$de_siempre, $con_cajas, $todas] as $pdf) {
            $this->assertRenglon('Domicilio Comercial: ', 'Calle Falsa 123', $pdf);
            $this->assertRenglon('Dirección: ', '450, Rosario', $pdf);
        }

        /** Pegada al Domicilio Comercial también en la factura (el enforce no la manda al final). */
        $this->assertLessThan($this->y_de('Condición IVA: ', $de_siempre), $this->y_de('Dirección: ', $de_siempre));
    }

    /**
     * @test
     */
    public function el_enforce_fiscal_deja_los_chips_de_sucursal_en_su_lugar_y_no_cambia_lo_demas()
    {
        /** Sin chips de sucursal: lo de siempre (obligatorios en orden canónico, extras al final). */
        $sin_chips = [
            'emisor' => [
                'izquierda' => ['telefono', 'condicion_iva', 'razon_social'],
                'derecha' => ['email', 'cuit'],
            ],
        ];
        $resultado = AfipPdfHelper::enforce_fiscal_required_fields($sin_chips);
        $this->assertSame(['razon_social', 'domicilio_comercial', 'condicion_iva', 'telefono'], $resultado['emisor']['izquierda']);
        $this->assertSame(['numero_comprobante', 'fecha_emision', 'cuit', 'ingresos_brutos', 'inicio_actividades', 'punto_venta', 'email'], $resultado['emisor']['derecha']);

        /** El default fiscal pasa idéntico (idempotente). */
        $default = PdfColumnProfile::default_header_layout(true);
        $this->assertSame($default['emisor'], AfipPdfHelper::enforce_fiscal_required_fields($default)['emisor']);

        /** Cada chip pegado al obligatorio que lo precede; sin obligatorio antes, al final. */
        $con_chips = [
            'emisor' => [
                'izquierda' => ['todas_las_sucursales', 'razon_social', 'domicilio_comercial', 'sucursal_comprobante', 'web', 'condicion_iva'],
                'derecha' => ['cuit', 'sucursal_7'],
            ],
        ];
        $resultado = AfipPdfHelper::enforce_fiscal_required_fields($con_chips);
        $this->assertSame(
            ['razon_social', 'domicilio_comercial', 'sucursal_comprobante', 'condicion_iva', 'todas_las_sucursales', 'web'],
            $resultado['emisor']['izquierda']
        );
        $this->assertSame(
            ['numero_comprobante', 'fecha_emision', 'cuit', 'sucursal_7', 'ingresos_brutos', 'inicio_actividades', 'punto_venta'],
            $resultado['emisor']['derecha']
        );
    }

    // ── Presupuesto y pedido ─────────────────────────────────────────────────────────────────

    /**
     * @test
     */
    public function el_presupuesto_imprime_su_sucursal()
    {
        $budget = $this->crear_presupuesto_completo();

        /** El diseño por defecto sembrado (header_layout guardado con el default de ahora). */
        $perfil = $this->diseno('budget', 'Presupuesto');
        $pdf = $this->pdf_de_documento(new BudgetPdfDocument($budget), $perfil);
        $this->assertRenglon('Dirección: ', '980, La Plata', $pdf);

        /** Con cajas, igual. */
        $perfil->page_layout = $this->diseno_de_pagina([
            $this->caja_de_diseno('caja_cliente', 12, [$this->campo_de_caja('cliente_nombre')], 'Cliente'),
        ], []);
        $con_cajas = $this->pdf_de_documento(new BudgetPdfDocument($budget), $perfil);
        $this->assertRenglon('Dirección: ', '980, La Plata', $con_cajas);
    }

    /**
     * @test
     */
    public function el_pedido_imprime_su_deposito_y_todas_las_sucursales()
    {
        $pedido = $this->crear_pedido_completo();
        $perfil = $this->diseno('order', 'Pedido online');

        $pdf = $this->pdf_de_documento(new OrderPdfDocument($pedido), $perfil);
        $this->assertRenglon('Dirección: ', '450, Rosario', $pdf);

        Address::create(['street' => 'Centro', 'street_number' => 'San Martin 100', 'city' => 'Rosario', 'user_id' => $this->dueno->id]);
        $perfil->header_layout = $this->layout_con(['todas_las_sucursales']);
        $todas = $this->pdf_de_documento(new OrderPdfDocument($pedido), $perfil);
        $this->assertRenglon('Sucursal Belgrano: ', '450, Rosario', $todas);
        $this->assertRenglon('Sucursal Centro: ', 'San Martin 100, Rosario', $todas);
    }

    // ── API ──────────────────────────────────────────────────────────────────────────────────

    /**
     * @test
     */
    public function la_api_guarda_los_chips_de_sucursal_tambien_uno_ajeno_y_su_negrita()
    {
        $otro_dueno = $this->crear_dueno('Otro dueno '.uniqid());
        $ajena = Address::create(['street' => 'Ajena', 'street_number' => 'Lejos 1', 'user_id' => $otro_dueno->id]);
        $propia = Address::create(['street' => 'Centro', 'street_number' => 'San Martin 100', 'user_id' => $this->dueno->id]);

        $perfil = $this->perfil_de_venta(['header_layout' => $this->layout_de_antes()]);
        $layout = $this->layout_con(['sucursal_comprobante', 'sucursal_'.$propia->id], ['todas_las_sucursales', 'sucursal_'.$ajena->id]);
        $layout['bold'] = ['sucursal_comprobante', 'sucursal_'.$propia->id, 'todas_las_sucursales', 'inventada'];

        $this->putJson('api/pdf-column-profiles/'.$perfil->id, ['header_layout' => $layout])->assertStatus(200);

        $guardado = $perfil->fresh()->header_layout;
        /** assertEquals: la columna JSON de MySQL reordena las claves del objeto (no las listas). */
        $this->assertEquals($layout['emisor'], $guardado['emisor'], 'Las claves nuevas se guardan tal cual, también la de una sucursal ajena.');
        $this->assertSame(['sucursal_comprobante', 'sucursal_'.$propia->id, 'todas_las_sucursales'], $guardado['bold']);
    }

    // ── El comando (D7) ──────────────────────────────────────────────────────────────────────

    /**
     * @test
     */
    public function el_comando_ver_no_escribe_y_aplicar_suma_la_sucursal_una_sola_vez()
    {
        $remito = $this->perfil_de_venta(['header_layout' => $this->layout_de_antes()]);
        $sin_domicilio = $this->perfil_de_venta(['header_layout' => [
            'emisor' => ['izquierda' => ['razon_social'], 'derecha' => ['domicilio_comercial', 'cuit']],
        ]]);
        $sin_nada = $this->perfil_de_venta(['header_layout' => [
            'emisor' => ['izquierda' => ['razon_social'], 'derecha' => ['cuit']],
        ]]);
        $ya_tiene = $this->perfil_de_venta(['header_layout' => $this->layout_con([], ['sucursal_12'])]);
        $sin_layout = $this->perfil_de_venta(['header_layout' => null]);
        $presupuesto = $this->diseno('budget', 'Presupuesto');
        $presupuesto->header_layout = $this->layout_de_antes();
        $presupuesto->save();

        $this->artisan('pdf:sumar-sucursal-al-encabezado')->assertExitCode(0);
        $this->artisan('pdf:sumar-sucursal-al-encabezado', ['--ver' => true, '--aplicar' => true])->assertExitCode(0);
        /** assertEquals: la columna JSON de MySQL reordena las claves del objeto (no las listas). */
        $this->assertEquals($this->layout_de_antes(), $remito->fresh()->header_layout, '--ver (y --ver con --aplicar) no escribe.');

        $this->artisan('pdf:sumar-sucursal-al-encabezado', ['--aplicar' => true])->assertExitCode(0);

        $esperado = ['razon_social', 'domicilio_comercial', 'sucursal_comprobante', 'condicion_iva'];
        $this->assertSame($esperado, $remito->fresh()->header_layout['emisor']['izquierda']);
        $this->assertSame($this->layout_de_antes()['emisor']['derecha'], $remito->fresh()->header_layout['emisor']['derecha']);
        $this->assertEquals($this->layout_de_antes()['receptor'], $remito->fresh()->header_layout['receptor'], 'El resto del layout no se toca.');
        $this->assertSame($esperado, $presupuesto->fresh()->header_layout['emisor']['izquierda']);
        $this->assertSame(['domicilio_comercial', 'sucursal_comprobante', 'cuit'], $sin_domicilio->fresh()->header_layout['emisor']['derecha']);
        $this->assertSame(['razon_social'], $sin_domicilio->fresh()->header_layout['emisor']['izquierda']);
        $this->assertSame(['razon_social', 'sucursal_comprobante'], $sin_nada->fresh()->header_layout['emisor']['izquierda']);
        $this->assertEquals($this->layout_con([], ['sucursal_12']), $ya_tiene->fresh()->header_layout);
        $this->assertNull($sin_layout->fresh()->header_layout, 'Un perfil sin layout usa el default: no se toca.');

        /** Idempotente. */
        $this->artisan('pdf:sumar-sucursal-al-encabezado', ['--aplicar' => true])->assertExitCode(0);
        $this->assertSame($esperado, $remito->fresh()->header_layout['emisor']['izquierda']);
    }

    /**
     * @test
     */
    public function el_comando_respeta_la_opcion_vieja_de_todas_las_sucursales()
    {
        User::where('id', $this->dueno->id)->update(['all_addresses_in_sale_pdf' => 1]);
        $perfil = $this->perfil_de_venta(['header_layout' => $this->layout_de_antes(true), 'is_afip_ticket' => true]);

        $this->artisan('pdf:sumar-sucursal-al-encabezado', ['--aplicar' => true])->assertExitCode(0);

        $this->assertSame(
            ['razon_social', 'domicilio_comercial', 'todas_las_sucursales', 'condicion_iva'],
            $perfil->fresh()->header_layout['emisor']['izquierda']
        );
    }

    /**
     * D9: el ticket de comandera no se toca. Mismo criterio que PdfColumnProfile::es_ticket():
     * tipo de hoja sin alto.
     *
     * @test
     */
    public function el_comando_no_toca_los_perfiles_de_ticket()
    {
        $rollo = SheetType::create(['name' => 'zz Rollo '.uniqid(), 'width' => 80, 'height' => null, 'user_id' => $this->dueno->id]);
        $ticket = $this->perfil_de_venta(['header_layout' => $this->layout_de_antes(), 'sheet_type_id' => $rollo->id]);
        $this->assertTrue($ticket->es_ticket());

        $hoja = $this->perfil_de_venta(['header_layout' => $this->layout_de_antes()]);

        $this->artisan('pdf:sumar-sucursal-al-encabezado', ['--aplicar' => true])->assertExitCode(0);

        $this->assertEquals($this->layout_de_antes(), $ticket->fresh()->header_layout, 'El ticket queda como estaba.');
        $this->assertContains('sucursal_comprobante', $hoja->fresh()->header_layout['emisor']['izquierda']);
    }

    /**
     * @test
     */
    public function el_comando_inserta_justo_despues_del_domicilio_comercial()
    {
        $this->assertSame(['a', 'domicilio_comercial', 'x', 'b'], SumarSucursalAlEncabezadoPdf::con_chip(['a', 'domicilio_comercial', 'b'], 'x'));
        $this->assertSame(['a', 'b', 'x'], SumarSucursalAlEncabezadoPdf::con_chip(['a', 'b'], 'x'));
        $this->assertSame(['x'], SumarSucursalAlEncabezadoPdf::con_chip([], 'x'));
    }
}
