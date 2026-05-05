<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

/**
 * Listado de artículos con imagen, código de proveedor, nombre y precio (layout histórico en FPDF).
 * Antes vivía en `ArticlePdf.php`; se renombró la clase para liberar el nombre de dominio del modelo `ArticlePdf`.
 */
class ArticleListImagePdf extends fpdf
{
    /**
     * @param string $ids Identificadores separados por guión.
     */
    public function __construct($ids)
    {
        set_time_limit(0);
        parent::__construct();
        $this->SetAutoPageBreak(true, 1);
        $this->b = 0;
        $this->line_height = 7;

        $this->setArticles($ids);

        $this->user = UserHelper::getFullModel();

        $this->image_width = 45;
        $this->provider_code_width = 30;
        $this->name_width = 100;
        $this->price_width = 30;

        $this->AddPage();
        $this->print();
        $this->Output();
        exit;
    }

    /**
     * @param string $ids
     * @return void
     */
    public function setArticles($ids)
    {
        $this->articles = [];
        foreach (explode('-', $ids) as $id) {
            $this->articles[] = Article::find($id);
        }
    }

    /**
     * @return void
     */
    public function Header()
    {
        if (!is_null($this->user->image_pdf_header_url)) {
            $this->image_header();
        } else {
            $this->header_normal();
        }

        $this->tableHeader();
    }

    /**
     * @return void
     */
    public function header_normal()
    {
        if (config('app.APP_ENV') == 'local') {
            $this->Image('https://api.freelogodesign.org/assets/thumb/logo/ad95beb06c4e4958a08bf8ca8a278bad_400.png', 2, 2, 45, 45);
        } else {
            $logo = $this->user->image_url;
            if (!is_null($logo) && GeneralHelper::file_exists_2($logo)) {
                $this->Image($logo, 2, 2, 45, 45);
            }
        }

        $this->SetFont('Arial', 'B', 18);

        $this->x = 50;
        $this->y = 20;
        $this->Cell(70, 8, $this->user->company_name, $this->b, 0, 'L');

        $this->SetFont('Arial', '', 14);
        $this->Cell(80, 8, date('d/m/Y'), $this->b, 0, 'R');
    }

    /**
     * @return void
     */
    public function image_header()
    {
        if (config('app.APP_ENV') == 'local') {
            $this->Image('https://api.freelogodesign.org/assets/thumb/logo/ad95beb06c4e4958a08bf8ca8a278bad_400.png', 2, 2, 206, 47);
        } else {
            $this->Image($this->user->image_pdf_header_url, 2, 2, 206, 47);
        }
    }

    /**
     * @return void
     */
    public function tableHeader()
    {
        $this->SetFont('Arial', '', 14);
        $this->y = 50;
        $this->x = 2;

        $this->SetFillColor(221, 211, 211);
        $this->Cell($this->image_width, 8, 'Imagen', 1, 0, 'L');
        $this->Cell($this->provider_code_width, 8, 'Cod Prov.', 1, 0, 'L');
        $this->Cell($this->name_width, 8, 'Nombre', 1, 0, 'L');
        $this->Cell($this->price_width, 8, 'Precio', 1, 0, 'L');
        $this->y += 9;
    }

    /**
     * @return void
     */
    public function print()
    {
        $this->SetFont('Arial', '', 12);
        foreach ($this->articles as $article) {
            $this->printArticle($article);
        }
    }

    /**
     * @param \App\Models\Article $article
     * @return void
     */
    public function printImage($article)
    {
        if (count($article->images) >= 1) {
            $img_url = $this->getJpgImage($article);
            $this->Image($img_url, 2, $this->y, $this->image_width, $this->image_width);
        }
    }

    /**
     * @param \App\Models\Article $article
     * @return string|null
     */
    public function getJpgImage($article)
    {
        if (config('app.APP_ENV') == 'local') {
            $img_url = 'https://api-colman-prueba.comerciocity.com/public/storage/171699179550596.webp';
        } else {
            $img_url = $article->images[0]->{env('IMAGE_URL_PROP_NAME', 'image_url')};
        }

        if (!is_null($img_url)) {
            $array = explode('/', $img_url);
            $array_name = end($array);
            $name = explode('.', $array_name)[0];
            $extension = strtolower(pathinfo($array_name, PATHINFO_EXTENSION));

            $jpg_file_url = storage_path('app/public/'.$name.'.jpg');

            if ($extension === 'webp') {
                if (!file_exists($jpg_file_url)) {
                    try {
                        $image = imagecreatefromwebp($img_url);
                        if ($image !== false) {
                            imagejpeg($image, $jpg_file_url, 100);
                            imagedestroy($image);
                        } else {
                            throw new \Exception('No se pudo crear la imagen desde WEBP.');
                        }
                    } catch (\Exception $e) {
                        return $img_url;
                    }
                }
                return $jpg_file_url;
            }

            if ($this->isJpg($img_url)) {
                return $img_url;
            }
        }
        return $img_url;
    }

    /**
     * @param string $file
     * @return bool
     */
    public function isJpg($file)
    {
        if (!file_exists($file)) {
            return false;
        }

        $imageInfo = getimagesize($file);

        if ($imageInfo && $imageInfo['mime'] == 'image/jpeg') {
            return true;
        }

        return false;
    }

    /**
     * @param \App\Models\Article|null $article
     * @return void
     */
    public function printArticle($article)
    {
        if ($this->y >= 270) {
            $this->AddPage();
        }

        if (!is_null($article)) {
            if (count($article->images) >= 1) {
                $this->printImage($article);
            }

            $this->x = 5 + $this->image_width;
            $this->Cell($this->provider_code_width, 8, $article->provider_code, $this->b, 0, 'L');

            $this->MultiCell($this->name_width, 8, $article->name, $this->b, 'L', false);

            $this->y -= 8;

            $this->x += $this->image_width + $this->provider_code_width + $this->name_width - 5;
            $this->Cell($this->price_width, 8, '$'.Numbers::price($article->final_price), $this->b, 0, 'L');

            $this->y += 48;
        }
    }
}
