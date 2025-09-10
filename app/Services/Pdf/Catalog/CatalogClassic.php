<?php

namespace App\Services\Pdf\Catalog;

use App\Http\Controllers\Helpers\GeneralHelper;
use fpdf;
require(__DIR__.'/../../../Http/Controllers/CommonLaravel/fpdf/fpdf.php');

class CatalogClassic extends fpdf
{
    public function generate(string $logo_path, string $business_name, array $info_header, array $products)
    {
        // Configuración inicial
        $this->AddPage('P', 'A4');
        $this->SetMargins(15, 15, 15);
        $this->SetAutoPageBreak(true, 20);
        $this->SetFont('Arial', '', 11);

        // Cabecera estilizada con fondo suave
        $this->SetFillColor(240, 240, 245);
        $this->SetDrawColor(200, 200, 200);
        $this->Rect(15, 15, 180, 30, 'DF');

        if (!is_null($logo_path) && file_exists($logo_path)) {
            $this->Image($logo_path, 17, 17, 25);
        }

        $this->SetXY(50, 18);
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 8, utf8_decode($business_name), 0, 1);

        $this->SetFont('Arial', '', 9);
        $this->SetX(50);
        $this->Cell(0, 5, 'Fecha: ' . date('d/m/Y'), 0, 1);

        $y = $this->GetY();
        foreach ($info_header as $key => $value) {
            $this->SetX(50);
            $this->Cell(0, 5, utf8_decode("{$key}: {$value}"), 0, 1);
        }

        $this->Ln(6);

        // Línea separadora elegante
        $this->SetDrawColor(180, 180, 200);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->Ln(8);

        // Sección de productos con estilo por tarjetas
        foreach ($products as $product) {
            // Tarjeta con borde y fondo
            $this->SetFillColor(250, 250, 255);
            $this->SetDrawColor(200, 200, 210);
            $startX = $this->GetX();
            $startY = $this->GetY();
            $this->Rect($startX, $startY, 180, 50, 'DF');

            $this->SetXY($startX + 5, $startY + 5);
            $this->SetFont('Arial', 'B', 12);
            $this->Cell(100, 6, utf8_decode($product['name']), 0, 1);

            // Imagen redondeada (no nativa, emulación con Rect)
            if (!empty($product['images'])) {

                $url = $product['images'][0]['hosting_url'];

                if (env('APP_ENV') == 'local') {

                    $url = 'https://api-colman-prueba.comerciocity.com/public/storage/171699179550596.webp';
                }


                $img_url = GeneralHelper::getJpgImage($url);

                $this->Image($img_url, $this->GetX(), $this->GetY(), 30);
            }

            // Precios
            $this->SetFont('Arial', '', 10);
            $currentY = $this->GetY();
            foreach ($product['price_types'] as $price) {
                $this->SetXY($startX + 40, $currentY);
                $this->Cell(0, 5, utf8_decode("{$price['name']}: {$price['pivot']['final_price']}"), 0, 1);
                $currentY += 5;
            }

            // Avanzar al siguiente bloque (espacio constante entre tarjetas)
            $this->SetY($startY + 55);
        }

        $this->Output();
    }
}
