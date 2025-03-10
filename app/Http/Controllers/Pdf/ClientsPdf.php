<?php

namespace App\Http\Controllers\Pdf; 

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\ImageHelper;
use App\Http\Controllers\Helpers\UserHelper;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

class ClientsPdf extends fpdf {

	function __construct($clients) {
		parent::__construct();
		$this->SetAutoPageBreak(true, 1);
		$this->b = 0;
		$this->line_height = 7;
		
		$this->user = UserHelper::getFullModel();
		$this->clients = $clients;

		$this->AddPage();
		$this->clients();
        $this->Output();
        exit;
	}

	function getFields() {
		return [
			'Nombre' 		=> 70,
			'Saldo' 		=> 40,
			'Telefono' 		=> 30,
			'Vendedor' 		=> 30,
			'Descripcion' 	=> 30,
		];
	}

	function Header() {
		$data = [
			'title' 			=> 'Clientes',
			'fields' 			=> $this->getFields(),
		];
		PdfHelper::header($this, $data);
	}

	function Footer() {
	}

	

	function clients() {
		$this->SetFont('Arial', '', 10);
		$this->x = 5;
		foreach ($this->clients as $client) {
			if ($this->y < 210) {
				$this->printClient($client);
			} else {
				$this->AddPage();
				$this->x = 5;
				$this->y = 90;
				$this->printClient($client);
			}
		}
	}

	function printClient($client) {

		$this->x = 5;
		$this->Cell($this->getFields()['Nombre'], $this->line_height, $client->name, $this->b, 0, 'L');
		$this->Cell($this->getFields()['Saldo'], $this->line_height, '$'.Numbers::price($client->saldo), $this->b, 0, 'L');
		$this->Cell($this->getFields()['Telefono'], $this->line_height, $client->phone, $this->b, 0, 'L');

		$seller = null;
		if (!is_null($client->seller)) {
			$seller = $client->seller->name;
		}
		$this->Cell($this->getFields()['Vendedor'], $this->line_height, $seller, $this->b, 0, 'L');

		// $y_1 = $this->y;
		$this->MultiCell($this->getFields()['Descripcion'], $this->line_height, $client->description, $this->b, 'L', false);
		
	    // $y_2 = $this->y;
		// $this->y = $y_1;
		
		// $this->y = $y_2;
		
		$this->Line(5, $this->y, 205, $this->y);
	}

}