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

        $this->current_acount = $model;
        $this->credit_account = $this->current_acount->credit_account;

        $this->model_name = $model_name;

        if ($model_name == 'client') {
            $this->model = $this->current_acount->client;
        } else {
            $this->model = $this->current_acount->provider;
        }

        $this->user = UserHelper::user();

        $localidad_cliente = null;

        if ($this->model->location) {
            $localidad_cliente = $this->model->location->name;
        }
        if ($this->model->provincia) {
            $localidad_cliente .= ' - '.$this->model->provincia->name;
        }
        if ($this->model->iva_condition) {
            $categoria_fiscal = $this->model->iva_condition->name;
        } else {
            $categoria_fiscal = 'Consumidor Final';
        }

        $datos = [
            'titulo' => 'Recibo',
            'condicion_iva' => $this->user->afip_information->iva_condition->name,
            'empresa_nombre' => $this->user->afip_information->razon_social,
            'direccion_line' => $this->user->afip_information->domicilio_comercial,
            // 'localidad' => 'CERRITO',
            'telefono' => $this->user->phone,
            'email' => $this->user->email,
            'nota_factura' => 'DOCUMENTO NO VALIDO COMO FACTURA',
            'tipo' => 'RECIBO',
            'numero' => 'Nº '.$model->num_receipt,
            'fecha' => $model->created_at->format('d/m/Y'),
            'cuit' => $this->user->afip_information->cuit,
            'señor' => $this->model->name,
            'domicilio_cliente' => $this->model->address,
            'localidad_cliente' => $localidad_cliente,
            'categoria_fiscal' => $categoria_fiscal,
            'cuit_cliente' => $this->model->cuit,
            'observaciones' => $this->current_acount->description,
            // 'generado_por' => 'Generado por www.duxsoftware.com.ar'
        ];

        $datos = $this->set_concepto($datos);
        $datos = $this->set_saldo_cta($datos);
        $datos = $this->set_monto($datos);

        $this->pdf($datos);

        $this->Output();
        exit;
    }

    function set_concepto($datos) {
        if ($this->current_acount->to_pay) {

            $concepto = 'COBRO EN CONCEPTO DE ';

            if ($this->current_acount->to_pay->sale && $this->current_acount->to_pay->sale->afip_ticket) {
                
                $concepto .= 'Fac N° '.$this->current_acount->to_pay->sale->afip_ticket->cbte_numero;
            
            } else if ($this->current_acount->to_pay->sale) {

                $concepto .= 'Venta N° '.$this->current_acount->to_pay->sale->num;
            }
        } else {
            $concepto = 'VARIOS';
        }
        $datos['concepto'] = $concepto;

        return $datos;
    }

    function set_saldo_cta($datos) {

        $saldo = $this->credit_account->saldo;

        $datos['saldo_cta'] = Numbers::price($saldo, true, $this->credit_account->moneda_id);

        return $datos;
    }

    function set_monto($datos) {

        $haber = $this->current_acount->haber;

        $datos['monto'] = Numbers::price($haber, true, $this->credit_account->moneda_id);

        return $datos;
    }

    function pdf($datos) {

        // HEADER: título centrado grande
        $this->AddPage();

        // $this->SetFont('Arial','B',18);
        // $this->SetXY(20, 10);
        // $this->Cell(170, 8, $datos['titulo'], 0, 1, 'C');

        $logo_url = 'https://api.freelogodesign.org/assets/thumb/logo/ad95beb06c4e4958a08bf8ca8a278bad_400.png';

        if (env('APP_ENV') == 'production') {
            $logo_url = $this->user->image_url;
        }
        // Logo (si existe) - colocarlo arriba a la derecha
        // Posición aproximada: x=150 mm, y=8 mm, ancho=40 mm (mantiene proporción)
        if ($logo_url) {
            // FPDF necesita un archivo local o URL habilitada. Intentamos usar la URL directa.
            // Si el servidor bloquea allow_url_fopen, podés descargar la imagen al storage y pasar la ruta local.
            try {
                $this->Image($logo_url, 40, 15, 35);
            } catch (\Exception $e) {
                // si falla la carga por URL, se ignora y sigue generando el PDF sin logo
            }
        }

        // Empresa (izquierda, debajo título)
        $this->SetFont('Arial','B',12);
        $this->x = 10;
        $this->y += 42;
        $this->Cell(95, 6, $datos['empresa_nombre'], 0, 1, 'C');

        $this->SetFont('Arial','',10);
        $this->x = 10;
        $this->Cell(95,5, $datos['direccion_line'], 0, 1, 'C');
        $this->x = 10;
        $this->Cell(95,5, 'TELEFONO: '.$datos['telefono'], 0, 1, 'C');
        $this->x = 10;
        $this->Cell(95,5, $datos['email'], 0, 1, 'C');





        // Nota pequeña (DOCUMENTO NO VALIDO COMO FACTURA)
        $this->SetFont('Arial','I',9);
        $this->x = 120;
        $this->y = 20;
        // $this->Cell(0,5, $datos['nota_factura'], 0, 1);

        // Right side: tipo, numero, fecha, cuit
        $this->SetFont('Arial','B',20);
        $this->x = 120;
        $this->Cell(75,10, $datos['tipo'], 0, 1, 'R');

        $this->SetFont('Arial','',20);
        $this->x = 120;
        $this->Cell(75,10, $datos['numero'], 0, 1, 'R');

        $this->x = 120;
        $this->Cell(75,10, 'FECHA: '.$datos['fecha'], 0, 1, 'R');

        $this->SetFont('Arial','',10);
        $this->x = 120;
        $this->Cell(75,10, $datos['condicion_iva'], 0, 1, 'R');
        $this->x = 120;
        $this->Cell(75,10, 'C.U.I.T.: '.$datos['cuit'], 0, 1, 'R');


        $this->Line(10, 10, 200, 10);
        $this->Line(200, 10, 200, 75);
        $this->Line(200, 75, 10, 75);
        $this->Line(10, 75, 10, 10);

        $this->Line(105, 10, 105, 75);




        // Datos del cliente 
        $this->datos_cliente($datos);


        // Concepto / Observaciones
        $this->observaciones($datos);


        // DETALLE: cuadro en la parte inferior - "DETALLE DE PAGOS RECIBIDOS"
        $y_detalle = 180;
        $this->SetDrawColor(0,0,0);
        $this->SetLineWidth(0.3);
        // $this->Rect(20, $y_detalle, 170, 35); // contenedor


        $this->pagos_recibidos($datos);


        $this->comprobantes_imputados($datos);

        $this->saldo_actual($datos);

    }

    function datos_cliente($datos) {
        $this->SetFont('Arial','B',10);
        $this->x = 10;
        $this->y = 75;
        $this->Cell(30,6, 'SEÑOR/ES:', 'LBT', 0);
        $this->SetFont('Arial','',10);
        $this->Cell(160,6, $datos['señor'], 'RBT', 1);

        $this->SetFont('Arial','B',10);
        $this->x = 10;
        $this->Cell(30,6, 'DOMICILIO:', 'LBT', 0);
        $this->SetFont('Arial','',10);
        $this->Cell(160,6, $datos['domicilio_cliente'], 'RBT', 1);

        $this->SetFont('Arial','B',10);
        $this->x = 10;
        $this->Cell(30,6, 'LOCALIDAD:', 'LBT', 0);
        $this->SetFont('Arial','',10);
        $this->Cell(160,6, $datos['localidad_cliente'], 'RBT', 1);

        $this->SetFont('Arial','B',10);
        $this->x = 10;
        $this->Cell(45,6, 'CATEGORIA FISCAL:', 'LBT', 0);
        $this->SetFont('Arial','',10);
        $this->Cell(145,6, $datos['categoria_fiscal'].'  C.U.I.T: '.$datos['cuit_cliente'], 'RBT', 1);
    }

    function observaciones($datos) {

        $this->x = 10;
        $this->SetFont('Arial','B',10);
        $this->Cell(30,6, 'CONCEPTO:', 'LBT', 0);
        $this->SetFont('Arial','',10);
        $this->MultiCell(160,6, $datos['concepto'], 'RBT', 1);
        

        // Observaciones
        $this->x = 10;
        $this->SetFont('Arial','B',10);
        $this->Cell(30,6, 'Observaciones:', 'LBT', 0);
        $this->SetFont('Arial','',10);
        $this->MultiCell(160,6, $datos['observaciones'], 'RBT', 1);
    }

    function saldo_actual($datos) {

        // Saldo y texto de monto
        $this->y += 10;
        $this->x = 10;
        $this->SetFont('Arial','B',12);
        $this->Cell(0,6, 'SALDO DE SU CTA CTE A LA FECHA: '.$datos['saldo_cta'], 0, 1);

        $this->SetFont('Arial','',11);
        $this->x = 10;
        $this->MultiCell(170,6, 'Recibimos la suma de '.$datos['monto'].' en concepto de los items detallados anteriormente', 0, 1);


        $this->SetFillColor(200,200,200);
        $this->x = 10;
        $this->y += 10;
        $this->SetFont('Arial','B',15);
        $this->Cell(190,15, 'SON: '.$datos['monto'], 0, 1, 'R', 1);


        // Firma (línea)
        $this->x = 10;
        $this->Cell(0,15, 'Firma:  .........................................................', 0, 1);
    }

    function pagos_recibidos($datos) {

        $this->x = 10;
        $this->y += 10;
        $this->SetFont('Arial','B',10);
        $this->SetFillColor(200,200,200);
        $this->Cell(190,6, 'DETALLE DE PAGOS RECIBIDOS', 1, 1, 'C', 1);

        $this->SetFont('Arial','',10);

        foreach ($this->current_acount->current_acount_payment_methods as $payment_method) {
            $this->x = 10;

            $payment_method_text = $payment_method->name;

            $this->Cell(150,6, $payment_method_text, 1, 0);

            $this->Cell(40,6, Numbers::price($payment_method->pivot->amount, true, $this->credit_account->moneda_id), 1, 1);
        }

        $this->SetFont('Arial','B',10);
        $this->x = 120;
        $this->SetFont('Arial','B',10);
        $this->Cell(40,6, 'TOTAL', 'LTB', 0, 'R');
        $this->Cell(40,6, $datos['monto'], 'RTB', 1, 'R');



        // Cheques
        if (count($this->current_acount->cheques) >= 1) {
            
            $this->x = 10;
            $this->y += 5;
            $this->Cell(190,6, 'Cheques asociados', 1, 1, 1);

            $this->SetFont('Arial','',10);

            foreach ($this->current_acount->cheques as $cheque) {
                $cheque_text = "Cheque N° {$cheque->numero} | Monto: ".Numbers::price($cheque->amount, true)." | Banco: {$cheque->banco}";

                if ($cheque->fecha_pago) {
                    $cheque_text .= " | Fecha Pago: ".$cheque->fecha_pago->format('d/m/y');
                }

                $this->x = 10;
                $this->Cell(190,6, $cheque_text, 1, 1);
            }
        }
    }

    function comprobantes_imputados($datos) {

            
        $this->x = 10;
        $this->y += 10;
        $this->SetFont('Arial','B',10);
        $this->SetFillColor(200,200,200);
        $this->Cell(190,6, 'COMPROBANTES IMPUTADOS', 1, 1, 'C', 1);

        $this->SetFont('Arial','',10);
        $this->Cell(110,6, 'Detalle', 1, 0);
        $this->Cell(40,6, 'Total Comprobante', 1, 0, 'R');
        $this->Cell(40,6, 'Imputado', 1, 1, 'R');

        foreach ($this->current_acount->pagando_a as $pagando_a) {
            
            if ($pagando_a->sale && $pagando_a->sale->afip_ticket) {
                $cbte_imputado = 'Factura N° '.$pagando_a->sale->afip_ticket->cbte_numero;
            } else if ($pagando_a->sale) {
                $cbte_imputado = 'Venta N° '.$pagando_a->sale->num;
            } else {
                $cbte_imputado = $pagando_a->detalle;
            }

            $this->Cell(110,6, $cbte_imputado, 1, 0);
            $this->Cell(40,6, Numbers::price($pagando_a->debe, true), 1, 0, 'R');
            $this->Cell(40,6, Numbers::price($pagando_a->pivot->pagado, true), 1, 1, 'R');
        }

        // TOTAL abajo a la derecha
        $this->SetFont('Arial','B',10);
        $this->x = 120;
        $this->Cell(40,6, 'TOTAL', 'LTB', 0, 'R');
        $this->Cell(40,6, $datos['monto'], 'RTB', 1, 'R');
    }

}