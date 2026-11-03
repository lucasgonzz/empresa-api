<?php

namespace Tests\Unit\Pdf;

use App\Http\Controllers\Pdf\AfipQrPdf;
use PHPUnit\Framework\TestCase;

class QrFiscalPdfTest extends TestCase
{
    public function test_el_qr_de_un_comprobante_existente_conserva_los_datos_fiscales(): void
    {
        $ticket = $this->ticket();
        $ticket->afip_fecha_emision = '2026-10-30';
        $ticket->moneda = 'DOL';
        $ticket->moneda_cotizacion = '1450.50';

        $enlace = parse_url(AfipQrPdf::enlace_fiscal($ticket));
        parse_str($enlace['query'], $consulta);
        $datos = json_decode(base64_decode($consulta['p'], true), true);

        $this->assertSame('www.arca.gob.ar', $enlace['host']);
        $this->assertSame([
            'ver' => 1,
            'fecha' => '2026-10-30',
            'cuit' => 30123456789,
            'ptoVta' => 11,
            'tipoCmp' => 6,
            'nroCmp' => 78,
            'importe' => 527018.79,
            'moneda' => 'DOL',
            'ctz' => 1450.5,
            'tipoCodAut' => 'E',
            'codAut' => 76412345678901,
            'tipoDocRec' => 80,
            'nroDocRec' => 30987654321,
        ], $datos);
    }

    public function test_el_qr_recupera_el_tipo_de_documento_de_la_respuesta_original(): void
    {
        $ticket = $this->ticket();
        $ticket->cuit_cliente = '12345678';
        $ticket->response = '<soap:Envelope xmlns:soap="urn:soap"><soap:Body>'
            .'<FECAEDetResponse xmlns="urn:arca"><DocTipo>96</DocTipo></FECAEDetResponse>'
            .'</soap:Body></soap:Envelope>';

        parse_str(parse_url(AfipQrPdf::enlace_fiscal($ticket), PHP_URL_QUERY), $consulta);
        $datos = json_decode(base64_decode($consulta['p'], true), true);

        $this->assertSame(96, $datos['tipoDocRec']);
        $this->assertSame(12345678, $datos['nroDocRec']);
        $this->assertSame('2026-11-03', $datos['fecha']);
        $this->assertSame('PES', $datos['moneda']);
        $this->assertSame(1, $datos['ctz']);
    }

    public function test_el_qr_local_se_imprime_en_el_pie_de_cada_pagina(): void
    {
        require_once __DIR__.'/../../../app/Http/Controllers/CommonLaravel/fpdf/fpdf.php';

        $pdf = new class($this->ticket()) extends \FPDF {
            private $ticket;

            public function __construct($ticket)
            {
                parent::__construct();
                $this->ticket = $ticket;
            }

            public function Footer()
            {
                AfipQrPdf::imprimir_codigo($this, $this->ticket, 10, 257, 22);
            }
        };
        $pdf->SetCompression(false);
        $pdf->AddPage();
        $pdf->AddPage();
        $salida = $pdf->Output('S');

        $this->assertStringStartsWith('%PDF-', $salida);
        $this->assertSame(2, preg_match_all('~/I\d+ Do~', $salida));
        $this->assertStringContainsString('/Subtype /Image', $salida);
    }

    private function ticket(): object
    {
        return (object) [
            'created_at' => new \DateTimeImmutable('2026-11-03'),
            'afip_fecha_emision' => null,
            'cuit_negocio' => '30123456789',
            'punto_venta' => '11',
            'cbte_tipo' => '6',
            'cbte_numero' => '78',
            'importe_total' => '527018.79',
            'moneda_id' => 'PES',
            'moneda' => null,
            'moneda_cotizacion' => null,
            'cuit_cliente' => '30987654321',
            'cae' => '76412345678901',
        ];
    }
}
