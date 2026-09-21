<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Http\Controllers\Helpers\GeneralHelper;
use Illuminate\Support\Facades\Log;

/**
 * Arma el logo del negocio como bitmap monocromo ESC/POS (`GS v 0`) para el header del
 * Ticket 2.0 (el ticket termico que se manda por QZ Tray o por el agente propio de
 * impresion), con el mismo criterio visual que ya usa `SaleTicketPdf::logo()`: el logo
 * ocupa el 60% del ancho util del ticket, centrado horizontalmente.
 *
 * La conversion se hace ACA, en el backend, y no en el navegador: leer los pixeles de
 * una imagen de otro origen desde un <canvas> tira SecurityError sin CORS explicito, y
 * el logo vive en una URL de otro origen. GD ya resuelve este mismo problema del lado
 * del servidor para el logo del PDF (mision del 18/8/2026).
 */
class SaleTicketRasterHelper {

	/**
	 * Descarga (o lee del cache de `GeneralHelper::pdf_image_path()`) el logo, lo
	 * redimensiona al 60% del ancho total del ticket manteniendo su proporcion real, lo
	 * centra sobre un lienzo del ancho completo del ticket y lo empaqueta en bytes
	 * `GS v 0` listos para agregar tal cual a `content` del Ticket 2.0.
	 *
	 * Nunca tira: cualquier falla (imagen invalida, sin logo cargado, error inesperado)
	 * devuelve null, y el ticket sale igual, sin logo — como se banco siempre hasta ahora.
	 *
	 * @param  string|null  $image_url  URL del logo ya resuelta (ver `AfipPdfHelper::resolve_logo_url()`).
	 * @param  int  $ancho_mm  Ancho de papel del ticket, en milimetros (80, 58, etc.).
	 * @return string|null  Bytes ESC/POS crudos del logo, o null si no hay logo o algo fallo.
	 */
	static function build_ticket_logo_raster($image_url, $ancho_mm) {
		$source = null;
		$logo_redimensionado = null;
		$canvas = null;

		try {
			if (empty($image_url)) {
				return null;
			}

			$local_path = GeneralHelper::pdf_image_path($image_url);

			if (is_null($local_path)) {
				return null;
			}

			$source = @imagecreatefromstring(file_get_contents($local_path));

			if ($source === false) {
				return null;
			}

			/*
			 * Misma cuenta que TICKET_WIDTH en el frontend
			 * (empresa-spa/src/mixins/sale/print_ticket/index.js): 48 caracteres cada 80mm de
			 * papel. Se pasa de caracteres a puntos con 12 puntos por caracter (Font A) y se
			 * alinea a byte, porque GS v 0 empaqueta de a 8 columnas por byte.
			 *
			 * ⚠️ Si esta formula alguna vez se desalinea de TICKET_WIDTH en el frontend, el logo
			 * queda mas angosto o mas ancho que las lineas de texto del resto del ticket.
			 */
			$chars = (int) floor($ancho_mm * 48 / 80);
			$ancho_total_dots = $chars * 12;
			$ancho_total_dots -= $ancho_total_dots % 8; // alineado a byte

			// Mismo 60% del ancho util que SaleTicketPdf::logo(), alineado a byte.
			$logo_w = (int) floor($ancho_total_dots * 0.6);
			$logo_w -= $logo_w % 8;

			if ($logo_w < 8) {
				imagedestroy($source);
				$source = null;
				return null;
			}

			$src_w = imagesx($source);
			$src_h = imagesy($source);

			if ($src_w === 0) {
				imagedestroy($source);
				$source = null;
				return null;
			}

			// Alto manteniendo la proporcion real, igual que SaleTicketPdf::getLogoHeight().
			$logo_h = (int) round($logo_w * $src_h / $src_w);

			if ($logo_h < 1) {
				$logo_h = 1;
			}

			// Redimensiona el logo solo, sobre fondo blanco (por si tiene canal alfa).
			$logo_redimensionado = imagecreatetruecolor($logo_w, $logo_h);
			$blanco_logo = imagecolorallocate($logo_redimensionado, 255, 255, 255);
			imagefilledrectangle($logo_redimensionado, 0, 0, $logo_w, $logo_h, $blanco_logo);
			imagecopyresampled($logo_redimensionado, $source, 0, 0, 0, 0, $logo_w, $logo_h, $src_w, $src_h);

			imagedestroy($source);
			$source = null;

			// Lienzo del ANCHO TOTAL del ticket, con el logo centrado horizontalmente.
			$canvas = imagecreatetruecolor($ancho_total_dots, $logo_h);
			$blanco_canvas = imagecolorallocate($canvas, 255, 255, 255);
			imagefilledrectangle($canvas, 0, 0, $ancho_total_dots, $logo_h, $blanco_canvas);

			$offset_x = (int) floor(($ancho_total_dots - $logo_w) / 2);
			imagecopy($canvas, $logo_redimensionado, $offset_x, 0, 0, 0, $logo_w, $logo_h);

			imagedestroy($logo_redimensionado);
			$logo_redimensionado = null;

			$raster = self::pack_raster_bit_image($canvas, $ancho_total_dots, $logo_h);

			imagedestroy($canvas);
			$canvas = null;

			return $raster;
		} catch (\Exception $e) {
			Log::info('build_ticket_logo_raster: no se pudo armar el logo del Ticket 2.0 para '.$image_url.' ('.$e->getMessage().')');

			if (!is_null($source)) {
				imagedestroy($source);
			}
			if (!is_null($logo_redimensionado)) {
				imagedestroy($logo_redimensionado);
			}
			if (!is_null($canvas)) {
				imagedestroy($canvas);
			}

			return null;
		}
	}

	/**
	 * Empaqueta un GD image ya armado (fondo blanco + logo, en blanco/negro logico) al
	 * comando ESC/POS `GS v 0` (`0x1D 0x76 0x30`, modo normal).
	 *
	 * Bandea cada 255 lineas de alto: el campo de alto de `GS v 0` son 2 bytes, pero
	 * firmwares de comanderas economicas truncan o ignoran el segundo byte. Bandeando, cada
	 * banda es un comando `GS v 0` completo con su propio header de alto (maximo 255), y
	 * eso evita que un logo alto salga cortado a la mitad silenciosamente.
	 *
	 * @param  resource  $image  Imagen GD ya armada (fondo blanco + logo centrado).
	 * @param  int  $width  Ancho en puntos, multiplo de 8.
	 * @param  int  $height  Alto en puntos.
	 * @return string  Bytes ESC/POS crudos, uno o mas comandos `GS v 0` concatenados.
	 */
	private static function pack_raster_bit_image($image, $width, $height) {
		$width_bytes = intdiv($width, 8);
		$max_band_h = 255;
		$out = '';

		for ($band_y = 0; $band_y < $height; $band_y += $max_band_h) {
			$band_h = min($max_band_h, $height - $band_y);
			$data = '';

			for ($y = 0; $y < $band_h; $y++) {
				for ($xb = 0; $xb < $width_bytes; $xb++) {
					$byte = 0;
					for ($bit = 0; $bit < 8; $bit++) {
						$x = $xb * 8 + $bit;
						$rgb = imagecolorat($image, $x, $band_y + $y);
						$gray = (($rgb >> 16) & 0xFF) * 0.299 + (($rgb >> 8) & 0xFF) * 0.587 + ($rgb & 0xFF) * 0.114;
						if ($gray < 128) {
							$byte |= (1 << (7 - $bit));
						}
					}
					$data .= chr($byte);
				}
			}

			$xL = $width_bytes % 256;
			$xH = intdiv($width_bytes, 256);
			$yL = $band_h % 256;
			$yH = intdiv($band_h, 256);

			$out .= chr(0x1D) . chr(0x76) . chr(0x30) . chr(0x00) . chr($xL) . chr($xH) . chr($yL) . chr($yH) . $data;
		}

		return $out;
	}

}
