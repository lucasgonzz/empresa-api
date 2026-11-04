<?php

namespace App\Services\Pdf\Catalog;

use App\Http\Controllers\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use TCPDF;

class TCPDCCatalog extends TCPDF
{
    function set_items($articles_ids) {
        $this->articles = [];

        foreach (explode('-', $articles_ids) as $id) {

            $article = Article::find($id);

            if (count($article->article_variants) >= 1) {

                foreach ($article->article_variants as $variant) {
                    

                    $_article = new \stdClass();

                    $_article->price_types = $article->price_types;
                    $_article->name = $article->name . ' ' . $variant->variant_description;
                    $_article->price_type_monedas = $article->price_type_monedas;
                    $_article->final_price = $article->final_price;
                    $_article->cost_in_dollars = $article->cost_in_dollars;

                    if ($variant->image_url) {
                        $_article->images = [
                            [
                                'hosting_url'   => $variant->image_url,
                            ]
                        ]; 
                    } else {

                        $_article->images = $article->images;
                    }


                    $this->articles[] = $_article;
                } 
            } else {
                $this->articles[] = $article;
            }

        }

        // dd($this->articles);

    }

    public function generate(string $logoPath, string $businessName, array $infoHeader, string $articles_ids, $moneda_id)
    {

        $this->set_items($articles_ids);

        // Una sola vez: el loop de abajo corre por artículo.
        $solo_precio_final = PriceTypeHelper::solo_lista_precio_final();
        $owner = UserHelper::getFullModel();

        $this->SetCreator(PDF_CREATOR);
        $this->SetAuthor($businessName);
        $this->SetTitle('Catalogo '.$businessName);
        $this->setMargins(15, 10, 15);
        $this->SetAutoPageBreak(false);
        $this->AddPage('P', 'A4');

        // CABECERA (solo en primera página)
        $this->SetFont('helvetica', 'B', 18);
        $this->SetXY(15, 10);

        if ($logoPath) {
            $this->Image($logoPath, 15, 10, 30);
        }

        $this->SetXY(52, 15);
        $this->Cell(0, 8, $businessName, 0, 1, 'L');

        $this->SetFont('helvetica', '', 10);
        $this->SetX(52);
        $this->Cell(0, 5, 'Fecha: ' . date('d/m/Y'), 0, 1, 'L');

        foreach ($infoHeader as $key => $value) {
            $this->SetX(52);
            $this->Cell(0, 5, "{$key}: {$value}", 0, 1, 'L');
        }

        $this->Ln(10);

        $product_index = 0;
        $products_per_page = 4;
        $product_height = 50;
        $start_y = $this->GetY();

        foreach ($this->articles as $article) {
            // Comprobar si hay que agregar nueva página
            if ($product_index > 0 && $product_index % $products_per_page === 0) {
                $this->AddPage('P', 'A4');
                $start_y = 20;
            }

            $x = 15;
            $y = $start_y + ($product_index % $products_per_page) * ($product_height + 10);

            // Tarjeta con fondo celeste y bordes redondeados
            $this->SetFillColor(235, 245, 255);
            $this->SetDrawColor(200, 200, 200);
            $this->RoundedRect($x, $y, 180, $product_height, 3, '1111', 'DF');

            // Imagen del producto
            if (count($article->images) >= 1) {

                $url = $article->images[0]['hosting_url'];

                if (config('app.APP_ENV') == 'local') {

                    $url = 'https://api-colman-prueba.comerciocity.com/public/storage/171699179550596.webp';
                }


                $img_url = GeneralHelper::getJpgImage($url);
                
                $this->Image($img_url, $x + 5, $y + 5, 40, 40, '', '', '', false, 300, '', false, false, 1, false, false, false);
            }

            // Nombre y precios
            $this->SetFont('helvetica', 'B', 14);
            $this->SetXY($x + 50, $y + 2);

            $this->MultiCell(0, 8, $article->name, 0, 'L', false);
            

            $this->SetFont('helvetica', '', 12);
            $py = $this->GetY();

            // Con una sola lista, la «Precio final» (misión prueba-listas-siempre, 3/11/2026), el catálogo
            // imprime el precio de siempre: ni el nombre de la lista ni sus filas por moneda.
            if ($solo_precio_final) {

                $this->SetX($x + 50);
                $this->Cell(0, 6, $this->precio_del_articulo($article, $owner, $moneda_id), 0, 1, 'L');

            } else if (count($article->price_type_monedas) >= 1) {


                foreach ($article->price_type_monedas as $price_type_moneda) {
                    
                    if (
                        $price_type_moneda->moneda_id == $moneda_id
                        && $price_type_moneda->final_price > 0
                    ) {
                        $this->SetX($x + 50);
                        // $price = $price_type_moneda->final_price;
                        
                        $price = Numbers::price($price_type_moneda->final_price, true, $moneda_id);
                    
                        $this->Cell(0, 6, $price_type_moneda->price_type->name.': '.$price, 0, 1, 'L');
                    }

                }

            } else if (count($article->price_types) >= 1) {

                foreach ($article->price_types as $price_type) {
                    

                    $this->SetX($x + 50);
                
                    // El pivote de una lista está en pesos.
                    $this->Cell(0, 6, $price_type->name.': '.$this->precio_en_la_moneda_pedida($price_type->pivot->final_price, 1, $owner, $moneda_id), 0, 1, 'L');
                }

            } else {

                $this->SetX($x + 50);
                $this->Cell(0, 6, $this->precio_del_articulo($article, $owner, $moneda_id), 0, 1, 'L');
            }

            $product_index++;
        }

        $this->Output('catalog_tcpdf.pdf', 'I');
    }

    /**
     * El precio final del artículo, que está en su moneda nativa, en la moneda que pidió el PDF.
     *
     * @param  mixed                 $article
     * @param  \App\Models\User|null $owner
     * @param  mixed                 $moneda_id
     * @return string
     */
    protected function precio_del_articulo($article, $owner, $moneda_id)
    {
        $nativa = is_null($owner) ? 1 : PriceTypeHelper::moneda_nativa_del_precio($article, $owner);

        return $this->precio_en_la_moneda_pedida($article->final_price, $nativa, $owner, $moneda_id);
    }

    /**
     * Un precio con su signo, en la moneda pedida (misión prueba-listas-siempre, 3/11/2026: la opción de
     * PDF por moneda se ofrece a todo negocio). Sin moneda pedida, en la suya. Sin dólar en la cuenta no
     * se inventa una cotización: se imprime en la moneda en la que está, con su signo.
     *
     * @param  float|string|null     $precio
     * @param  int                   $moneda_del_precio
     * @param  \App\Models\User|null $owner
     * @param  mixed                 $moneda_id
     * @return string
     */
    protected function precio_en_la_moneda_pedida($precio, $moneda_del_precio, $owner, $moneda_id)
    {
        $moneda = empty($moneda_id) ? (int) $moneda_del_precio : (int) $moneda_id;

        $valor = PriceTypeHelper::precio_en_moneda($precio, $moneda_del_precio, $moneda, is_null($owner) ? 0 : $owner->dollar);

        if (is_null($valor)) {
            $valor = $precio;
            $moneda = (int) $moneda_del_precio;
        }

        return Numbers::price(round((float) $valor, 2), true, $moneda);
    }
}
