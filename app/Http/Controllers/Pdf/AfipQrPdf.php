<?php

namespace App\Http\Controllers\Pdf;


class AfipQrPdf {

	function __construct($instance, $afip_ticket, $from_ticket = false) {
		$this->instance = $instance;
		$this->afip_ticket = $afip_ticket;
		$this->from_ticket = $from_ticket;
		$this->img_width = 50;

    	if ($this->from_ticket) {

        	$this->afip_logo_width = 20;
        	$this->afip_logo_height = 10;
			$this->img_start_x = $this->img_centrada($this->instance, $this->img_width);
			$this->afip_img_start_x = $this->img_centrada($this->instance, $this->img_width);

    	} else {

        	$this->afip_logo_width = 40;
        	$this->afip_logo_height = 20;
			$this->img_start_x = 5;
			$this->afip_img_start_x = 60;
    	}

	}


	function printQR() {

		$this->qr();

		$this->logo_afip();

		$this->instance->y += 5;
	}

	function qr() {

		$start_y = $this->instance->y;

		$this->instance->y += 7;

        self::imprimir_codigo(
            $this->instance,
            $this->afip_ticket,
            $this->img_start_x,
            $this->instance->y,
            $this->img_width
        );

        if ($this->from_ticket) {
	        $x_position = 10;
	        $this->instance->y += 52;
        	$width = 50;

        	$x_afip_logo = 10;
        	$y_afip_logo = $start_y + 50;
        } else {
        	$width = 150;
	        $x_position = 45;
	        $this->instance->y += 20;
        	$x_afip_logo = 45;
        	$y_afip_logo = $start_y + 15;
        }

        $this->instance->x = $x_position;
	}

    public static function enlace_fiscal($ticket): string
    {
        $fecha = $ticket->afip_fecha_emision ?: $ticket->created_at->format('Y-m-d');
        $cotizacion = $ticket->moneda_cotizacion ?: self::campo_soap($ticket->request ?? null, 'MonCotiz');
        $data = [
            'ver' => 1,
            'fecha' => substr((string) $fecha, 0, 10),
            'cuit' => (int) $ticket->cuit_negocio,
            'ptoVta' => (int) $ticket->punto_venta,
            'tipoCmp' => (int) $ticket->cbte_tipo,
            'nroCmp' => (int) $ticket->cbte_numero,
            'importe' => (float) $ticket->importe_total,
            'moneda' => $ticket->moneda ?: $ticket->moneda_id,
            'ctz' => (float) ($cotizacion ?: 1),
            'tipoCodAut' => 'E',
            'codAut' => (int) $ticket->cae,
        ];

        if (!empty($ticket->cuit_cliente)) {
            $tipo_documento = self::tipo_documento_receptor($ticket);
            if ($tipo_documento !== null) {
                $data['tipoDocRec'] = $tipo_documento;
                $data['nroDocRec'] = (int) $ticket->cuit_cliente;
            }
        }

        return 'https://www.arca.gob.ar/fe/qr/?p='.rawurlencode(base64_encode(json_encode($data)));
    }

    private static function tipo_documento_receptor($ticket): ?int
    {
        $tipo = self::campo_soap($ticket->response ?? null, 'DocTipo');
        if ($tipo !== null) {
            return (int) $tipo;
        }

        // Los comprobantes históricos pueden tener CUIT pero no la respuesta de ARCA.
        return strlen((string) $ticket->cuit_cliente) === 11 ? 80 : null;
    }

    private static function campo_soap(?string $xml, string $campo): ?string
    {
        if (!$xml) {
            return null;
        }

        $respuesta = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $valores = $respuesta !== false ? $respuesta->xpath('//*[local-name()="'.$campo.'"]') : [];
        return !empty($valores) ? (string) $valores[0] : null;
    }

    public static function imprimir_codigo($pdf, $ticket, $x, $y, $size): void
    {
        if (empty($ticket->cae)) {
            return;
        }

        $qr = new \TCPDF2DBarcode(self::enlace_fiscal($ticket), 'QRCODE,M');
        $matriz = $qr->getBarcodeArray();
        $escala = 4;
        $margen = 4 * $escala;
        $imagen = imagecreate(
            $matriz['num_cols'] * $escala + 2 * $margen,
            $matriz['num_rows'] * $escala + 2 * $margen
        );
        imagecolorallocate($imagen, 255, 255, 255);
        $negro = imagecolorallocate($imagen, 0, 0, 0);

        foreach ($matriz['bcode'] as $fila => $modulos) {
            foreach ($modulos as $columna => $activo) {
                if ($activo) {
                    $izquierda = $margen + $columna * $escala;
                    $arriba = $margen + $fila * $escala;
                    imagefilledrectangle($imagen, $izquierda, $arriba, $izquierda + $escala - 1, $arriba + $escala - 1, $negro);
                }
            }
        }

        $archivo = tempnam(sys_get_temp_dir(), 'qr-fiscal-');
        if ($archivo === false) {
            imagedestroy($imagen);
            throw new \RuntimeException('No se pudo preparar la imagen del QR fiscal.');
        }

        try {
            if (!imagepng($imagen, $archivo)) {
                throw new \RuntimeException('No se pudo generar la imagen del QR fiscal.');
            }
            $pdf->Image($archivo, $x, $y, $size, $size, 'PNG');
        } finally {
            imagedestroy($imagen);
            unlink($archivo);
        }
    }

	function logo_afip() {

    	$img_url = public_path().'/afip/logo.jpg';
        
        if (!$this->from_ticket) {
	    	$this->instance->y -= 10;
        }

        $this->instance->Image($img_url, $this->afip_img_start_x, $this->instance->y, $this->afip_logo_width);

        $this->instance->y += $this->afip_logo_height;

        $this->instance->x = $this->afip_img_start_x;

        $this->instance->SetFont('Arial', 'BI', 10);
		$this->instance->Cell(100, 5, 'Comprobante Autorizado', 0, 0, 'L');
        $this->instance->SetFont('Arial', '', 7);

	}

	function img_centrada($instance) {
		return ($this->instance->ancho - $this->img_width) / 2;
	}

}