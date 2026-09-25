<?php

namespace Tests\Feature\Facturacion;

use App\Http\Controllers\Helpers\Afip\CondicionIvaReceptorHelper;
use App\Http\Controllers\Helpers\Afip\LeyendaIsibCabaHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Pdf\Afip\AfipPdfHelper;
use App\Http\Controllers\Pdf\SaleTicketPdf;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\IvaCondition;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use ReflectionMethod;
use Tests\EmpresaTestCase;

/**
 * Espia minimo de FPDF para los bloques estaticos de AfipPdfHelper: guarda el texto de cada celda.
 */
class EspiaDeLeyendaIsib
{
    /** @var float */
    public $x = 0;

    /** @var float */
    public $y = 0;

    /** @var array<int, string> Texto de cada celda dibujada, en orden. */
    public $renglones = [];

    /**
     * @return void
     */
    public function Cell($w = 0, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false)
    {
        $this->renglones[] = (string) $txt;
    }

    /**
     * @return void
     */
    public function SetFont($family, $style = '', $size = 0)
    {
    }
}

/**
 * Archivo 9 — leyenda de Ingresos Brutos de CABA en los comprobantes a consumidor final.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  LO QUE PIDE LA NORMA (mision leyenda-isib-caba, 24/9/2026)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Res. 169/AGIP/2026 (plazo prorrogado por las Res. 312 y 339/AGIP/2026 hasta el 1/1/2027): los
 *  contribuyentes de Ingresos Brutos de CABA informan en cada factura o ticket a CONSUMIDOR FINAL
 *  la alicuota que les corresponde, con la leyenda "ALÍCUOTA ISIB CABA XX,XX%", y los de Convenio
 *  Multilateral agregan "APLICABLE SOBRE INGRESOS BRUTOS ATRIBUIDOS A CABA". Nunca en comprobantes
 *  a otros eslabones de la cadena.
 *
 *  La alicuota se carga por punto de venta (`afip_information`). "Consumidor final" es lo que se le
 *  DECLARO a ARCA (CondicionIVAReceptorId = 5), recalculado sobre la foto del ticket con la misma
 *  tabla que usa la emision.
 *
 *  Nunca se llama a ARCA: configuraciones, ventas y tickets son filas dentro de la transaccion
 *  del test, con un cuit y un punto de venta que no existen en el fixture.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 *
 * @group facturacion
 * @group leyenda-isib-caba
 */
class Leyenda_Isib_Caba_Test extends EmpresaTestCase
{
    /**
     * Fecha de lo que siembra esta clase (ventana reservada de las suites de facturacion).
     */
    const FECHA = '2014-03-11 10:00:00';

    /**
     * CUIT de las configuraciones de prueba. No es el del fixture.
     */
    const CUIT = '20111111112';

    /**
     * Punto de venta de las configuraciones de prueba. No es el del fixture ni el del archivo 8.
     */
    const PUNTO_VENTA = 96;

    /**
     * La leyenda de 3% tal como la pidio el contador de ferretotal.
     */
    const LEYENDA_3 = 'ALÍCUOTA ISIB CABA 3,00%';

    // -----------------------------------------------------------------------------------------
    // Montaje
    // -----------------------------------------------------------------------------------------

    /**
     * @return \App\Models\User
     */
    protected function usuario_de_testing()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail();
    }

    /**
     * Crea (EN LA BASE) un punto de venta del comercio del fixture.
     *
     * @param  array $overrides
     * @return \App\Models\AfipInformation
     */
    protected function crear_config($overrides = [])
    {
        $iva_condition = IvaCondition::where('name', 'Responsable inscripto')->first();

        $this->assertNotNull($iva_condition, 'Falta la iva_condition "Responsable inscripto" en la base de testing.');

        return AfipInformation::create(array_merge([
            'user_id'                => $this->usuario_de_testing()->id,
            'iva_condition_id'       => $iva_condition->id,
            'cuit'                   => self::CUIT,
            'punto_venta'            => self::PUNTO_VENTA,
            'razon_social'           => 'zz Razon social ISIB',
            'domicilio_comercial'    => 'zz Calle de test 123',
            'afip_ticket_production' => 0,
        ], $overrides));
    }

    /**
     * @param  float $total
     * @return \App\Models\Sale
     */
    protected function crear_venta($total)
    {
        return Sale::create([
            'user_id'                    => $this->usuario_de_testing()->id,
            'client_id'                  => null,
            'omitir_en_cuenta_corriente' => 0,
            'save_current_acount'        => 0,
            'terminada'                  => 1,
            'moneda_id'                  => 1,
            'sub_total'                  => $total,
            'total'                      => $total,
            'created_at'                 => self::FECHA,
        ]);
    }

    /**
     * @param  \App\Models\Sale $venta
     * @param  float $price Precio CON IVA incluido.
     * @return void
     */
    protected function agregar_articulo($venta, $price)
    {
        $articulo = $this->articulo('Cuchilla');

        if (is_null($articulo)) {
            $this->fail('No existe el articulo "Cuchilla" en el fixture de testing.');
        }

        $venta->articles()->attach($articulo->id, [
            'amount'         => 1,
            'price'          => $price,
            'iva_percentage' => '21',
        ]);
    }

    /**
     * Crea (EN LA BASE) un ticket AFIP vinculado al punto de venta y lo devuelve leido de nuevo,
     * sin relaciones cargadas, como llega una reimpresion.
     *
     * @param  \App\Models\AfipInformation $config
     * @param  \App\Models\Sale|null $venta
     * @param  array $overrides `cbte_letra`, `cbte_tipo`, `iva_cliente`...
     * @return \App\Models\AfipTicket
     */
    protected function crear_ticket($config, $venta = null, $overrides = [])
    {
        $ticket = AfipTicket::create(array_merge([
            'sale_id'             => is_null($venta) ? null : $venta->id,
            'cuit_negocio'        => self::CUIT,
            'iva_negocio'         => 'Responsable inscripto',
            'punto_venta'         => (string) self::PUNTO_VENTA,
            'cbte_letra'          => 'B',
            'cbte_tipo'           => '6',
            'cbte_numero'         => '12345',
            'cae'                 => '70123456789012',
            'cae_expired_at'      => '2014-03-21',
            'iva_cliente'         => '',
            'afip_information_id' => $config->id,
        ], $overrides));

        return AfipTicket::find($ticket->id);
    }

    /**
     * Arma el ticket PDF (SaleTicketPdf) sin el `exit` del constructor real, igual que el archivo
     * 8: mismos Header()/items()/Footer(), compresion apagada para leer el texto, sin QR ni logo
     * (son de red). Devuelve el espia, para poder leer el PDF y medir la leyenda.
     *
     * @param  \App\Models\Sale $venta
     * @param  \App\Models\AfipTicket $ticket
     * @param  int $ancho Ancho del rollo en mm (80 o 58).
     * @return \App\Http\Controllers\Pdf\SaleTicketPdf
     */
    protected function ticket_pdf($venta, $ticket, $ancho)
    {
        return new class(Sale::find($venta->id), $ticket, $ancho) extends SaleTicketPdf {

            /** El PDF armado, como texto. */
            public $pdf_generado = null;

            /** Alto (mm) que ocupo la leyenda al dibujarse de verdad. */
            public $alto_dibujado_leyenda = 0;

            public function __construct($sale, $afip_ticket = null, $ancho = 80)
            {
                $this->line_height = 5;
                $this->user = UserHelper::getFullModel();
                $this->user->image_url = null;
                $this->sale = $sale;
                $this->afip_ticket = $afip_ticket;
                $this->x_incial = 4;
                $this->ancho = $ancho;
                $this->cell_ancho = $this->ancho - 8;
                $this->name_font_size = 12;
                $this->price_font_size = 10;

                \FPDF::__construct('P', 'mm', [$this->ancho, $this->getPdfHeight()]);
                $this->SetCompression(false);
                $this->SetAutoPageBreak(false);
                $this->b = 0;

                $this->AddPage();
                $this->items();

                $this->pdf_generado = $this->Output('S');
            }

            /** Mide lo que la leyenda ocupa en el papel, sin cambiar lo que dibuja. */
            public function leyenda_isib_caba()
            {
                $y_antes = $this->y;
                parent::leyenda_isib_caba();
                $this->alto_dibujado_leyenda = $this->y - $y_antes;
            }

            /** El QR de ARCA le pega a un servicio externo: fuera de este test. */
            public function qr()
            {
            }
        };
    }

    // -----------------------------------------------------------------------------------------
    // Quien la lleva
    // -----------------------------------------------------------------------------------------

    /**
     * Caso 1 — el caso del contador: punto de venta con 3%, factura B sin cliente.
     *
     * @test
     */
    public function factura_b_a_consumidor_final_lleva_la_leyenda_con_la_alicuota()
    {
        $config = $this->crear_config(['isib_caba_alicuota' => 3]);
        $ticket = $this->crear_ticket($config);

        $this->assertSame([self::LEYENDA_3], LeyendaIsibCabaHelper::partes($ticket));
        $this->assertSame(self::LEYENDA_3, LeyendaIsibCabaHelper::texto($ticket));
    }

    /**
     * Caso 2 — Convenio Multilateral agrega la segunda parte; en un renglon van unidas con " - "
     * (sin guion largo: FPDF y ESC/POS imprimen Latin-1 y el "—" sale "?").
     *
     * @test
     */
    public function convenio_multilateral_agrega_la_atribucion_a_caba()
    {
        $config = $this->crear_config(['isib_caba_alicuota' => 3, 'isib_caba_convenio_multilateral' => 1]);
        $ticket = $this->crear_ticket($config);

        $this->assertSame(
            [self::LEYENDA_3, 'APLICABLE SOBRE INGRESOS BRUTOS ATRIBUIDOS A CABA'],
            LeyendaIsibCabaHelper::partes($ticket)
        );
        $this->assertSame(
            'ALÍCUOTA ISIB CABA 3,00% - APLICABLE SOBRE INGRESOS BRUTOS ATRIBUIDOS A CABA',
            LeyendaIsibCabaHelper::texto($ticket)
        );
        $this->assertStringNotContainsString('—', LeyendaIsibCabaHelper::texto($ticket));
    }

    /**
     * Caso 3 — sin alicuota cargada (el estado en que nacen TODOS los puntos de venta) no cambia
     * ningun comprobante.
     *
     * @test
     */
    public function sin_alicuota_cargada_no_hay_leyenda()
    {
        $config = $this->crear_config();
        $ticket = $this->crear_ticket($config);

        $this->assertNull(AfipInformation::find($config->id)->isib_caba_alicuota);
        $this->assertSame([], LeyendaIsibCabaHelper::partes($ticket));
        $this->assertNull(LeyendaIsibCabaHelper::texto($ticket));
    }

    /**
     * Caso 4 — la matriz de clase x ficha. Va la leyenda si y solo si el comprobante se le
     * declaro a ARCA como emitido a consumidor final (CondicionIVAReceptorId 5). Con factura B,
     * una ficha de Responsable Inscripto o de Monotributista se declara como 5 (ver la tabla de
     * CondicionIvaReceptorHelper::get_iva_receptor): esos tambien la llevan.
     *
     * @test
     */
    public function solo_la_llevan_los_comprobantes_declarados_a_consumidor_final()
    {
        $config = $this->crear_config(['isib_caba_alicuota' => 3]);

        /** [letra, cbte_tipo, iva_cliente, lleva_leyenda] */
        $casos = [
            ['B', '6', '', true],
            ['B', '6', 'Consumidor final', true],
            ['B', '6', 'Responsable inscripto', true],
            ['B', '6', 'Monotributista', true],
            ['B', '6', 'Exento', false],
            ['C', '11', '', true],
            ['C', '11', 'Consumidor final', true],
            ['C', '11', 'Responsable inscripto', false],
            ['C', '11', 'Monotributista', false],
            ['C', '11', 'Exento', false],
            ['A', '1', 'Responsable inscripto', false],
            ['A', '1', '', false],
            ['E', '19', '', false],
            // Ticket viejo sin cbte_tipo guardado: manda lo que dice la ficha, y la letra corta A.
            ['B', null, '', true],
            ['A', null, '', false],
        ];

        foreach ($casos as $caso) {
            $ticket = $this->crear_ticket($config, null, [
                'cbte_letra'  => $caso[0],
                'cbte_tipo'   => $caso[1],
                'iva_cliente' => $caso[2],
            ]);

            $this->assertSame(
                $caso[3],
                count(LeyendaIsibCabaHelper::partes($ticket)) > 0,
                'clase '.$caso[0].' ('.var_export($caso[1], true).') con ficha "'.$caso[2].'"'
            );
        }
    }

    /**
     * Caso 5 — la condicion del ticket sale de la MISMA tabla que la emision: para cada clase y
     * ficha, lo que se recalcula al imprimir es lo que get_iva_receptor() le mando a ARCA.
     *
     * @test
     */
    public function la_condicion_del_ticket_coincide_con_la_que_se_declaro_al_emitir()
    {
        $config = $this->crear_config();

        foreach (['', 'Responsable inscripto', 'Monotributista', 'Consumidor final', 'Exento'] as $ficha) {
            foreach (['1', '6', '11', '3', '8', '13'] as $cbte_tipo) {

                $ticket = $this->crear_ticket($config, null, ['cbte_tipo' => $cbte_tipo, 'iva_cliente' => $ficha]);

                $condicion = IvaCondition::where('name', $ficha)->first();
                $venta_con_ficha = new Sale();
                if (!is_null($condicion)) {
                    $cliente = new \App\Models\Client();
                    $cliente->setRelation('iva_condition', $condicion);
                    $venta_con_ficha->setRelation('client', $cliente);
                }

                $this->assertSame(
                    CondicionIvaReceptorHelper::get_iva_receptor($venta_con_ficha, $cbte_tipo),
                    CondicionIvaReceptorHelper::condicion_declarada_del_ticket($ticket),
                    'ficha "'.$ficha.'", cbte_tipo '.$cbte_tipo
                );
            }
        }
    }

    /**
     * Caso 6 — formato de la alicuota: dos decimales con coma, venga como numero o como el
     * string que devuelve MySQL para un DECIMAL.
     *
     * @test
     */
    public function la_alicuota_se_imprime_con_dos_decimales_y_coma()
    {
        $config = $this->crear_config(['isib_caba_alicuota' => 1.5]);
        $ticket = $this->crear_ticket($config);

        $this->assertSame(['ALÍCUOTA ISIB CABA 1,50%'], LeyendaIsibCabaHelper::partes($ticket));

        $this->assertSame(3.0, LeyendaIsibCabaHelper::alicuota_configurada('3.00'));
        $this->assertSame(3.5, LeyendaIsibCabaHelper::alicuota_configurada('3.5'));
        $this->assertSame(0.75, LeyendaIsibCabaHelper::alicuota_configurada(0.75));

        // Vacio, cero, negativo, basura o un tipeo (300 por 3,00): "no aplica".
        foreach ([null, '', 0, '0', -1, 'abc', 150] as $invalido) {
            $this->assertNull(
                LeyendaIsibCabaHelper::alicuota_configurada($invalido),
                'valor '.var_export($invalido, true)
            );
        }
    }

    // -----------------------------------------------------------------------------------------
    // Configuracion
    // -----------------------------------------------------------------------------------------

    /**
     * Caso 7 — el formulario del punto de venta guarda los dos campos, y vacio es null.
     *
     * @test
     */
    public function el_formulario_guarda_la_alicuota_y_el_convenio()
    {
        $config = $this->crear_config();

        $datos = [
            'iva_condition_id'       => $config->iva_condition_id,
            'razon_social'           => $config->razon_social,
            'cuit'                   => $config->cuit,
            'punto_venta'            => $config->punto_venta,
            'afip_ticket_production' => 0,
        ];

        $this->putJson('/api/afip-information/'.$config->id, array_merge($datos, [
            'isib_caba_alicuota'              => 3,
            'isib_caba_convenio_multilateral' => true,
        ]))->assertStatus(200);

        $guardado = AfipInformation::find($config->id);
        $this->assertEquals(3, (float) $guardado->isib_caba_alicuota);
        $this->assertEquals(1, (int) $guardado->isib_caba_convenio_multilateral);

        $this->putJson('/api/afip-information/'.$config->id, array_merge($datos, [
            'isib_caba_alicuota'              => '',
            'isib_caba_convenio_multilateral' => 0,
        ]))->assertStatus(200);

        $guardado = AfipInformation::find($config->id);
        $this->assertNull($guardado->isib_caba_alicuota, 'la alicuota vacia se guarda como null (no se imprime nada)');
        $this->assertEquals(0, (int) $guardado->isib_caba_convenio_multilateral);
    }

    /**
     * Caso 8 — compatibilidad hacia atras: un SPA viejo (cacheado o sin desplegar) no manda los
     * campos nuevos, y guardar el punto de venta NO le borra la configuracion al negocio.
     *
     * @test
     */
    public function un_spa_viejo_que_no_manda_los_campos_no_los_pisa()
    {
        $config = $this->crear_config(['isib_caba_alicuota' => 3, 'isib_caba_convenio_multilateral' => 1]);

        $this->putJson('/api/afip-information/'.$config->id, [
            'iva_condition_id'       => $config->iva_condition_id,
            'razon_social'           => 'zz Razon social editada',
            'cuit'                   => $config->cuit,
            'punto_venta'            => $config->punto_venta,
            'afip_ticket_production' => 0,
        ])->assertStatus(200);

        $guardado = AfipInformation::find($config->id);
        $this->assertSame('zz Razon social editada', $guardado->razon_social);
        $this->assertEquals(3, (float) $guardado->isib_caba_alicuota);
        $this->assertEquals(1, (int) $guardado->isib_caba_convenio_multilateral);
    }

    /**
     * Caso 9 — el alta de un punto de venta tambien los guarda.
     *
     * @test
     */
    public function el_alta_guarda_la_alicuota()
    {
        $iva_condition = IvaCondition::where('name', 'Responsable inscripto')->first();

        $respuesta = $this->postJson('/api/afip-information', [
            'iva_condition_id'                => $iva_condition->id,
            'razon_social'                    => 'zz Alta ISIB',
            'cuit'                            => self::CUIT,
            'punto_venta'                     => self::PUNTO_VENTA,
            'afip_ticket_production'          => 0,
            'isib_caba_alicuota'              => 3.5,
            'isib_caba_convenio_multilateral' => 1,
        ])->assertStatus(201);

        $guardado = AfipInformation::find($respuesta->json('model.id'));
        $this->assertEquals(3.5, (float) $guardado->isib_caba_alicuota);
        $this->assertEquals(1, (int) $guardado->isib_caba_convenio_multilateral);
    }

    /**
     * Caso 9 bis — una alicuota fuera de rango (300 tipeado por 3,00) se rechaza con 422 en vez de
     * guardarse en silencio como null. En el alta no crea nada; en la edicion no toca nada.
     *
     * @test
     */
    public function una_alicuota_fuera_de_rango_se_rechaza_sin_crear_ni_tocar_nada()
    {
        $iva_condition = IvaCondition::where('name', 'Responsable inscripto')->first();

        $antes = AfipInformation::where('razon_social', 'zz Alta rechazada')->count();

        $this->postJson('/api/afip-information', [
            'iva_condition_id'   => $iva_condition->id,
            'razon_social'       => 'zz Alta rechazada',
            'cuit'               => self::CUIT,
            'punto_venta'        => self::PUNTO_VENTA,
            'isib_caba_alicuota' => 300,
        ])->assertStatus(422)->assertJsonValidationErrors(['isib_caba_alicuota']);

        $this->assertSame($antes, AfipInformation::where('razon_social', 'zz Alta rechazada')->count(), 'el alta rechazada no puede dejar un punto de venta creado');

        $config = $this->crear_config(['isib_caba_alicuota' => 3]);

        foreach ([300, -1, 'abc'] as $invalido) {
            $this->putJson('/api/afip-information/'.$config->id, [
                'iva_condition_id'   => $config->iva_condition_id,
                'razon_social'       => 'zz No se tiene que guardar',
                'cuit'               => $config->cuit,
                'punto_venta'        => $config->punto_venta,
                'isib_caba_alicuota' => $invalido,
            ])->assertStatus(422);
        }

        $guardado = AfipInformation::find($config->id);
        $this->assertSame('zz Razon social ISIB', $guardado->razon_social);
        $this->assertEquals(3, (float) $guardado->isib_caba_alicuota);
    }

    // -----------------------------------------------------------------------------------------
    // Donde se imprime
    // -----------------------------------------------------------------------------------------

    /**
     * Caso 10 — Ticket 2.0: get-importes devuelve la leyenda ya resuelta, un renglon por parte,
     * y [] cuando el comprobante no la lleva (la clave esta siempre).
     *
     * @test
     */
    public function get_importes_devuelve_la_leyenda_para_el_ticket_2()
    {
        $config = $this->crear_config(['isib_caba_alicuota' => 3, 'isib_caba_convenio_multilateral' => 1]);

        $venta = $this->crear_venta(1210);
        $this->agregar_articulo($venta, 1210);
        $this->crear_ticket($config, $venta);

        $this->getJson('/api/afip/get-importes/'.$venta->id)
            ->assertStatus(200)
            ->assertJsonPath('leyenda_isib_caba', [self::LEYENDA_3, 'APLICABLE SOBRE INGRESOS BRUTOS ATRIBUIDOS A CABA']);

        $venta_a = $this->crear_venta(1210);
        $this->agregar_articulo($venta_a, 1210);
        $this->crear_ticket($config, $venta_a, ['cbte_letra' => 'A', 'cbte_tipo' => '1', 'iva_cliente' => 'Responsable inscripto']);

        $this->getJson('/api/afip/get-importes/'.$venta_a->id)
            ->assertStatus(200)
            ->assertJsonPath('leyenda_isib_caba', []);
    }

    /**
     * Caso 11 — ticket PDF de 80mm: la leyenda sale impresa debajo del CAE.
     *
     * El PDF guarda el texto en Latin-1 (el Cell() de este fpdf decodifica UTF-8), por eso se
     * compara contra utf8_decode().
     *
     * @test
     */
    public function el_ticket_pdf_imprime_la_leyenda()
    {
        $config = $this->crear_config(['isib_caba_alicuota' => 3, 'isib_caba_convenio_multilateral' => 1]);

        $venta = $this->crear_venta(1210);
        $this->agregar_articulo($venta, 1210);
        $ticket = $this->crear_ticket($config, $venta);

        $pdf = $this->ticket_pdf($venta, $ticket, 80)->pdf_generado;

        $this->assertSame('%PDF', substr($pdf, 0, 4));
        $this->assertStringContainsString(utf8_decode(self::LEYENDA_3), $pdf);
        $this->assertStringContainsString('APLICABLE SOBRE', $pdf);
        $this->assertStringContainsString('ATRIBUIDOS A CABA', $pdf);
    }

    /**
     * Caso 12 — el mismo ticket a un Exento (factura B, condicion 4) no la lleva.
     *
     * @test
     */
    public function el_ticket_pdf_a_un_exento_no_la_imprime()
    {
        $config = $this->crear_config(['isib_caba_alicuota' => 3]);

        $venta = $this->crear_venta(1210);
        $this->agregar_articulo($venta, 1210);
        $ticket = $this->crear_ticket($config, $venta, ['iva_cliente' => 'Exento']);

        $pdf = $this->ticket_pdf($venta, $ticket, 80)->pdf_generado;

        $this->assertSame('%PDF', substr($pdf, 0, 4));
        $this->assertStringNotContainsString('ISIB CABA', $pdf);
    }

    /**
     * Caso 13 — el rollo se mide ANTES de dibujar: lo que se reserva para la leyenda tiene que
     * alcanzar para los renglones que efectivamente se dibujan, en 80mm y en 58mm (donde cada
     * parte se parte en mas renglones). Si no alcanza, el ticket sale cortado.
     *
     * @test
     */
    public function el_alto_reservado_alcanza_para_la_leyenda_en_80_y_58mm()
    {
        $config = $this->crear_config(['isib_caba_alicuota' => 3, 'isib_caba_convenio_multilateral' => 1]);

        $venta = $this->crear_venta(1210);
        $this->agregar_articulo($venta, 1210);
        $ticket = $this->crear_ticket($config, $venta);

        foreach ([80, 58] as $ancho) {
            $espia = $this->ticket_pdf($venta, $ticket, $ancho);

            $this->assertGreaterThanOrEqual(
                8,
                $espia->alto_dibujado_leyenda,
                $ancho.'mm: son dos partes, al menos dos renglones de 4mm'
            );
            $this->assertGreaterThanOrEqual(
                $espia->alto_dibujado_leyenda,
                $espia->leyenda_isib_caba_alto(),
                $ancho.'mm: se reservaron '.$espia->leyenda_isib_caba_alto().'mm y se dibujaron '.$espia->alto_dibujado_leyenda.'mm'
            );
        }
    }

    /**
     * Caso 14 — PDF A4: la leyenda sale en un renglon debajo del bloque oficial, y la estimacion
     * del pie reserva exactamente ese renglon (y nada cuando no va).
     *
     * `print_footer_leyenda_isib_caba()` es protected static: se invoca por reflexion con un espia.
     * No se llama a footer(), que dibuja el QR de ARCA (red).
     *
     * @test
     */
    public function el_pdf_a4_imprime_la_leyenda_y_la_estimacion_la_cuenta()
    {
        $config = $this->crear_config(['isib_caba_alicuota' => 3, 'isib_caba_convenio_multilateral' => 1]);

        $venta = $this->crear_venta(1210);
        $con_leyenda = $this->crear_ticket($config, $venta);
        $sin_leyenda = $this->crear_ticket($config, $venta, ['iva_cliente' => 'Exento']);

        $metodo = new ReflectionMethod(AfipPdfHelper::class, 'print_footer_leyenda_isib_caba');
        $metodo->setAccessible(true);

        $espia = new EspiaDeLeyendaIsib();
        $metodo->invoke(null, $espia, $con_leyenda);
        $this->assertSame(
            ['ALÍCUOTA ISIB CABA 3,00% - APLICABLE SOBRE INGRESOS BRUTOS ATRIBUIDOS A CABA'],
            $espia->renglones
        );

        $espia = new EspiaDeLeyendaIsib();
        $metodo->invoke(null, $espia, $sin_leyenda);
        $this->assertSame([], $espia->renglones, 'a un Exento no se imprime nada');

        $venta = Sale::find($venta->id);
        $this->assertEqualsWithDelta(
            AfipPdfHelper::LEYENDA_ISIB_CABA_ALTO,
            AfipPdfHelper::estimate_footer_height($con_leyenda, $venta, false)
                - AfipPdfHelper::estimate_footer_height($sin_leyenda, $venta, false),
            0.001,
            'la estimacion del pie tiene que reservar el renglon de la leyenda'
        );
    }
}
