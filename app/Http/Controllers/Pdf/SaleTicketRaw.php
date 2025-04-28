<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\AfipHelper;

class SaleTicketRaw {

	function __construct($sale) {
		$this->sale = $sale;

		$this->content = '';

		$this->header();

		$this->print();
	}

	function header() {

		// Iniciar el contenido del ticket
		$this->content = "\x1B\x21\x08"; // Negrita ON
		$this->content .= "Comerciocity\n";
		$this->content .= "\x1B\x21\x00"; // Negrita OFF
		$this->content .= "-----------------------------\n";
		$this->content .= "Producto        Precio  Total\n";
		$this->content .= "-----------------------------\n";
	}

	function print() {

		$printer_name = str_replace('_', ' ', env('NOMBRE_IMPRESORA'));
		$output_pdf = public_path()."\Microsoft Print to PDF"; // Ruta donde se guardará el PDF

		// Agregar artículos con salto de línea si es necesario
		foreach ($this->sale->articles as $article) {
		    $name = wordwrap($article->name, 15, "\n", true); // Divide en líneas de 15 caracteres
		    $lineas_nombre = explode("\n", $name); // Convierte en array las líneas del name
		    
		    foreach ($lineas_nombre as $index => $linea) {
		        if ($index == 0) { 
		            // Primera línea: se imprime junto con el precio y total
		            $precio = str_pad($article->pivot->price, 6, " ", STR_PAD_LEFT);
		            $total = str_pad($article->pivot->price * $article->pivot->amount, 6, " ", STR_PAD_LEFT);
		            $this->content .= str_pad($linea, 15) . " $precio $total\n";
		        } else { 
		            // Líneas siguientes: solo imprimimos el name sin precio
		            $this->content .= str_pad($linea, 15) . "\n";
		        }
		    }
		}

		$this->content .= "-----------------------------\n";
		$this->content .= "\x1B\x45\x01"; // Negrita ON
		$this->content .= "TOTAL A PAGAR:     950\n";
		$this->content .= "\x1B\x45\x00"; // Negrita OFF
		$this->content .= "\n¡Gracias por su compra!\n";
		$this->content .= "\x1D\x56\x41\x10"; // Comando de corte de papel


		// Guardar el ticket en un archivo temporal
		$temp_ticket = tempnam(sys_get_temp_dir(), 'ticket');
		file_put_contents($temp_ticket, $this->content);


		$command = "print /d:\"$printer_name\" \"$temp_ticket\"";
		exec($command, $output, $status);
		exec("start \"\" \"$output_pdf\"");


		unlink($temp_ticket);







		// Enviar a la impresora
		// $printer = printer_open($printer_name);
		// if ($printer) {
		//     printer_set_option($printer, PRINTER_MODE, "RAW");
		//     printer_write($printer, $this->content);
		//     printer_close($printer);
		//     echo "Ticket enviado correctamente.";
		// } else {
		//     echo "No se pudo conectar a la impresora.";
		// }
	}
}