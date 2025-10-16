<?php

namespace App\Http\Controllers\Pdf\CurrentAcount;

use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\Helpers\UserHelper;
use fpdf;
require(__DIR__.'/../../CommonLaravel/fpdf/fpdf.php');

class NewPagoPdf extends fpdf {

    function __construct($model, $model_name) {
        parent::__construct();
        $this->SetAutoPageBreak(true, 1);

        $datos = [
            'titulo' => 'Recibo',
            'condicion_iva' => 'Responsable Inscripto',
            'empresa_nombre' => 'SREBERNICH SERGIO JOSE',
            'direccion_line' => 'ANGELINI 426, CERRITO',
            'localidad' => 'CERRITO',
            'telefono' => '3436229646',
            'email' => 'VENTASCERRITO@MASQUITOREPARACIONES.COM.AR',
            'nota_factura' => 'DOCUMENTO NO VALIDO COMO FACTURA',
            'tipo' => 'RECIBO',
            'numero' => 'Nº 00009- 00003573',
            'fecha' => '10/10/2025',
            'cuit' => '20292700599',
            'señor' => 'SASIA, SAMUEL DOMINGO',
            'domicilio_cliente' => 'ZONA RURAL',
            'localidad_cliente' => 'COLONIA CERRITO - ENTRE RIOS',
            'categoria_fiscal' => 'RESPONSABLE INSCRIPTO',
            'cuit_cliente' => '20077059718',
            'saldo_cta' => '$ 124.753,16',
            'monto_texto' => 'ciento treinta mil con 0/100',
            'son' => '$ 130000.00',
            'efectivo' => '$ 130,000.00',
            'total_recibido' => '$ 130.000,00',
            'comprobantes_imputados' => [
                [
                    'tipo' => 'FACTURA A-00009-00000387',
                    'saldo' => '$ 764.333,16',
                    'imputado' => '$ 130.000,00',
                ],
            ],
            'generado_por' => 'Generado por www.duxsoftware.com.ar'
        ];

        $this->pdf($datos);

        $this->Output();
        exit;
    }

    
    function pdf($datos) {

        // HEADER: título centrado grande
        $this->AddPage();

        $this->SetFont('Arial','B',18);
        $this->SetXY(20, 10);
        $this->Cell(170, 8, $datos['titulo'], 0, 1, 'C');

        $logo_url = 'https://api.freelogodesign.org/assets/thumb/logo/ad95beb06c4e4958a08bf8ca8a278bad_400.png';

        // Logo (si existe) - colocarlo arriba a la derecha
        // Posición aproximada: x=150 mm, y=8 mm, ancho=40 mm (mantiene proporción)
        if ($logo_url) {
            // FPDF necesita un archivo local o URL habilitada. Intentamos usar la URL directa.
            // Si el servidor bloquea allow_url_fopen, podés descargar la imagen al storage y pasar la ruta local.
            try {
                $this->Image($logo_url, 30, 10, 30);
            } catch (\Exception $e) {
                // si falla la carga por URL, se ignora y sigue generando el PDF sin logo
            }
        }

        // Empresa (izquierda, debajo título)
        $this->SetFont('Arial','B',12);
        $this->x = 10;
        $this->y += 30;
        $this->Cell(0, 6, $datos['empresa_nombre'], 0, 1);

        $this->SetFont('Arial','',10);
        $this->x = 10;
        $this->Cell(0,5, $datos['direccion_line'], 0, 1);
        $this->x = 10;
        $this->Cell(0,5, 'TELEFONO: '.$datos['telefono'], 0, 1);
        $this->x = 10;
        $this->Cell(0,5, $datos['email'], 0, 1);





        // Nota pequeña (DOCUMENTO NO VALIDO COMO FACTURA)
        $this->SetFont('Arial','I',9);
        $this->x = 120;
        $this->y = 20;
        $this->Cell(0,5, $datos['nota_factura'], 0, 1);

        // Right side: tipo, numero, fecha, cuit
        $this->SetFont('Arial','B',20);
        $this->x = 120;
        $this->Cell(80,10, $datos['tipo'], 0, 1, 'L');

        $this->SetFont('Arial','',20);
        $this->x = 120;
        $this->Cell(80,10, $datos['numero'], 0, 1, 'L');

        $this->x = 120;
        $this->Cell(80,10, 'FECHA: '.$datos['fecha'], 0, 1, 'L');

        $this->SetFont('Arial','',10);
        $this->x = 120;
        $this->Cell(80,10, $datos['condicion_iva'], 0, 1, 'L');
        $this->x = 120;
        $this->Cell(80,10, 'C.U.I.T.: '.$datos['cuit'], 0, 1, 'L');






        // Línea separadora
        $this->Line(20, $this->y, 190, $this->y);

        // Datos del cliente y concepto
        $this->SetFont('Arial','B',10);
        $this->x = 10;
        $this->y = 100;
        $this->Cell(30,6, 'SEÑOR/ES:', 0, 0);
        $this->SetFont('Arial','',10);
        $this->Cell(120,6, $datos['señor'], 0, 1);

        $this->SetFont('Arial','B',10);
        $this->x = 10;
        $this->Cell(30,6, 'DOMICILIO:', 0, 0);
        $this->SetFont('Arial','',10);
        $this->Cell(120,6, $datos['domicilio_cliente'], 0, 1);

        $this->SetFont('Arial','B',10);
        $this->x = 10;
        $this->Cell(30,6, 'LOCALIDAD:', 0, 0);
        $this->SetFont('Arial','',10);
        $this->Cell(120,6, $datos['localidad_cliente'], 0, 1);

        $this->SetFont('Arial','B',10);
        $this->x = 10;
        $this->Cell(45,6, 'CATEGORIA FISCAL:', 0, 0);
        $this->SetFont('Arial','',10);
        $this->Cell(90,6, $datos['categoria_fiscal'].'  C.U.I.T: '.$datos['cuit_cliente'], 0, 1);

        // Concepto / Observaciones
        $this->SetXY(20, 94);
        $this->SetFont('Arial','B',10);
        $this->Cell(30,6, 'CONCEPTO:', 0, 0);
        $this->SetFont('Arial','',10);
        $this->MultiCell(150,6, 'COBRO EN CONCEPTO DE F-0000900000387', 0, 1);

        // Saldo y texto de monto
        $this->SetFont('Arial','',10);
        $this->SetXY(20, 115);
        $this->Cell(0,6, 'SALDO DE SU CTA CTE A LA FECHA: '.$datos['saldo_cta'], 0, 1);

        $this->SetXY(20, 122);
        $this->MultiCell(170,6, 'Recibimos la suma de '.$datos['monto_texto'].' en concepto de los items detallados anteriormente', 0, 1);

        $this->SetFont('Arial','B',11);
        $this->SetXY(20, 136);
        $this->Cell(0,6, 'SON: '.$datos['son'], 0, 1);

        // Firma (línea)
        $this->SetXY(20, 150);
        $this->Cell(0,6, 'Firma:  .........................................................', 0, 1);

        // Pie / generado por
        $this->SetFont('Arial','I',8);
        $this->SetXY(20, 165);
        $this->Cell(0,5, $datos['generado_por'], 0, 1);

        // DETALLE: cuadro en la parte inferior - "DETALLE DE PAGOS RECIBIDOS"
        $y_detalle = 180;
        $this->SetDrawColor(0,0,0);
        $this->SetLineWidth(0.3);
        $this->Rect(20, $y_detalle, 170, 35); // contenedor

        $this->SetFont('Arial','B',10);
        $this->SetXY(22, $y_detalle + 2);
        $this->Cell(0,6, 'DETALLE DE PAGOS RECIBIDOS', 0, 1);

        // Valores dentro de detalle
        $this->SetFont('Arial','',10);
        $this->SetXY(22, $y_detalle + 12);
        $this->Cell(60,6, 'EFECTIVO', 0, 0);
        $this->Cell(40,6, $datos['efectivo'], 0, 1);

        $this->SetXY(22, $y_detalle + 20);
        $this->SetFont('Arial','B',10);
        $this->Cell(60,6, 'TOTAL RECIBIDO:', 0, 0);
        $this->Cell(40,6, $datos['total_recibido'], 0, 1);

        // COMPROBANTES IMPUTADOS (debajo)
        $this->SetXY(20, $y_detalle + 38);
        $this->SetFont('Arial','B',10);
        $this->Cell(0,6, 'COMPROBANTES IMPUTADOS', 0, 1);

        $this->SetFont('Arial','',9);
        $x = 22;
        $y = $y_detalle + 44;
        foreach ($datos['comprobantes_imputados'] as $row) {
            $this->SetXY($x, $y);
            $this->Cell(95,6, $row['tipo'], 0, 0);
            $this->Cell(35,6, $row['saldo'], 0, 0, 'R');
            $this->Cell(35,6, $row['imputado'], 0, 1, 'R');
            $y += 6;
        }

        // TOTAL abajo a la derecha
        $this->SetFont('Arial','B',10);
        $this->SetXY(120, $y + 6);
        $this->Cell(40,6, 'TOTAL:', 0, 0, 'R');
        $this->Cell(40,6, $datos['total_recibido'], 0, 1, 'R');
    }

}