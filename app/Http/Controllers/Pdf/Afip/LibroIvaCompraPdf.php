<?php

namespace App\Http\Controllers\Pdf\Afip;

use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper2;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use Carbon\Carbon;
use fpdf;
require(__DIR__.'/../../CommonLaravel/fpdf/fpdf.php');

class LibroIvaCompraPdf extends fpdf {

	function __construct($comprobantes, $inicio, $fin) {
		
		parent::__construct();
		$this->SetAutoPageBreak(true, 1);
		$this->AddPage('Landscape');

		$this->comprobantes = $comprobantes;

		$this->user = UserHelper::user();
		
        $this->pdf = new PdfHelper2($this);

        $this->_header($inicio, $fin);
        $this->table();

        $this->Output();
        exit;
	}

	function _header($inicio, $fin) {
		$periodo = 'Periodo desde '.$inicio->format('d/m/y').' hasta '.$fin->format('d/m/y');
        $header_data = [
        	'header_left'	=> [
        		'titulos_principales'	=> [
        			'Libro de Iva COMPRAS',
        		],
        		'titulos_secundarios'	=> [
        			$this->user->company_name,
        			date('d/m/y'),
        			$periodo,
        		],	
        	],	
        	'header_right'	=> [
        		'logo'	=> [
        			'url'	=> $this->user->image_url,
        			'size'	=> 20,
        		],
        	],
        ];
        $this->pdf->header($header_data);
	}

	function table() {

        $table_data = [
        	'header_font_size'	=> 9,
        	'body_font_size'	=> 8,
        	'header'	=> [
        		[
        			'title'	=> 'Emitida',
        			'size'	=> 15,
        		],
        		[
        			'title'	=> 'Registrada',
        			'size'	=> 18,
        		],
        		[
        			'title'	=> 'Comprobante',
        			'size'	=> 28,
        		],
        		[
        			'title'	=> 'Proveedor',
        			'size'	=> 38,
        		],
        		[
        			'title'	=> 'Iva',
        			'size'	=> 8,
        		],
        		[
        			'title'	=> 'Cuit',
        			'size'	=> 25,
        		],
        		[
        			'title'	=> 'Neto',
        			'size'	=> 23,
        		],
        		[
        			'title'	=> 'Iva 21',
        			'size'	=> 20,
        		],
        		[
        			'title'	=> 'Iva 10.5',
        			'size'	=> 17,
        		],
        		[
        			'title'	=> 'Iva 27',
        			'size'	=> 17,
        		],
        		[
        			'title'	=> 'Per.IIBB',
        			'size'	=> 17,
        		],
        		[
        			'title'	=> 'No Grav.',
        			'size'	=> 17,
        		],
        		[
        			'title'	=> 'Per.IVA',
        			'size'	=> 17,
        		],
        		[
        			'title'	=> 'Total',
        			'size'	=> 27,
        		],
        	],
        	'rows'	=> [],
        ];

        $totales = [
        	'neto' => 0,
        	'iva_21' => 0,
        	'iva_10' => 0,
        	'iva_27' => 0,
        	'per_iibb' => 0,
        	'no_gravado' => 0,
        	'per_iva' => 0,
        	'total' => 0,
        ];

        foreach ($this->comprobantes as $comprobante) {
        	$row = [

        		'Emitida'			=> Carbon::parse($comprobante['issued_at'])->format('d/m/y'),
        		'Registrada'		=> Carbon::parse($comprobante['created_at'])->format('d/m/y'),
        		'Comprobante'		=> $comprobante['num_comprobante'] ,
        		'Proveedor'			=> $comprobante['proveedor'],
        		'Iva'				=> $comprobante['iva'],
        		'Cuit'				=> $comprobante['cuit'],
        		'Neto'				=> Numbers::price((float)$comprobante['neto'], true),
        		'Iva 21'			=> Numbers::price((float)$comprobante['iva_21'], true),
        		'Iva 10.5'			=> Numbers::price((float)$comprobante['iva_10'], true),
        		'Iva 27'			=> Numbers::price((float)$comprobante['iva_27'], true),
        		
        		'Per.IIBB'			=> Numbers::price((float)$comprobante['per_iibb'], true),
        		'No Grav.'			=> Numbers::price((float)$comprobante['no_gravado'], true),
        		'Per.IVA'			=> Numbers::price((float)$comprobante['per_iva'], true),
        		'Total'				=> Numbers::price((float)$comprobante['total'], true),
        	];

        	$totales['neto'] 			+= $comprobante['neto'] != '' ? $comprobante['neto'] : 0;
        	$totales['iva_21'] 			+= $comprobante['iva_21'] != '' ? $comprobante['iva_21'] : 0;
        	$totales['iva_10'] 			+= $comprobante['iva_10'] != '' ? $comprobante['iva_10'] : 0;
        	$totales['iva_27'] 			+= $comprobante['iva_27'] != '' ? $comprobante['iva_27'] : 0;
        	$totales['per_iibb'] 		+= $comprobante['per_iibb'] != '' ? $comprobante['per_iibb'] : 0;
        	$totales['no_gravado'] 		+= $comprobante['no_gravado'] != '' ? $comprobante['no_gravado'] : 0;
        	$totales['per_iva'] 		+= $comprobante['per_iva'] != '' ? $comprobante['per_iva'] : 0;
        	$totales['total'] 			+= $comprobante['total'] != '' ? $comprobante['total'] : 0;

        	$table_data['rows'][] = $row;
        }

        $table_data = $this->add_row_totales($table_data, $totales);

        $this->pdf->table($table_data);
	}

	function add_row_totales($table_data, $totales) {
		$row_totales = [

    		'Emitida'			=> '',
    		'Registrada'		=> '',
    		'Comprobante'		=> '',
    		'Proveedor'			=> '',
    		'Iva'				=> '',
    		'Cuit'				=> 'Totales',
    		'Neto'				=> Numbers::price($totales['neto'], true),
    		'Iva 21'			=> Numbers::price($totales['iva_21'], true),
    		'Iva 10.5'			=> Numbers::price($totales['iva_10'], true),
    		'Iva 27'			=> Numbers::price($totales['iva_27'], true),
    		
    		'Per.IIBB'			=> Numbers::price($totales['per_iibb'], true),
    		'No Grav.'			=> Numbers::price($totales['no_gravado'], true),
    		'Per.IVA'			=> Numbers::price($totales['per_iva'], true),
    		'Total'				=> Numbers::price($totales['total'], true),

    		'font'	=> [
    			'size'	=> 8,
    			'type'	=> 'B',
    		],
		];

        $table_data['rows'][] = $row_totales;

        return $table_data;

	}

}