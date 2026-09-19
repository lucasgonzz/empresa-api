<?php

namespace Tests\Unit\Pdf;

use PHPUnit\Framework\TestCase;

/**
 * Protege el bug que le rompía el PDF de ofertas a La Martina (19/9/2026): cuando una imagen de
 * artículo no se podía convertir de webp a jpg (URL caída, archivo corrupto),
 * ArticleOfferSheetPdf::resolve_article_image_path() devolvía la URL webp original. FPDF no sabe
 * parsear webp: Image() tiraba "Unsupported image type: webp", una excepción sin catch en toda
 * la cadena que abortaba la generación del PDF ENTERO -- no solo la imagen de ese artículo, sino
 * el pedido completo, aunque el resto de los artículos tuviera imágenes sanas. Confirmado en
 * producción: 500 reales en `article/article-offer-pdf/1/408-2173` y otros, con esa excepción
 * exacta en el log.
 *
 * ArticleOfferSheetPdf no se puede instanciar en un test: el constructor termina en
 * $this->Output(); exit;, que mataría el proceso de PHPUnit (mismo motivo por el que
 * Catalogo_encabezado_reglas_de_render_Test prueba el helper del catálogo por separado, no la
 * clase PDF). Este test lee el archivo fuente y confirma que la resolución de imagen delega en
 * GeneralHelper::pdf_image_path() -- que ya está probado (grupo 372, suite `pdf-imagenes`,
 * tests/Feature/Pdf/1_Resolucion_De_Imagen_Para_Pdf_Test.php) para nunca devolver una URL y
 * devolver null cuando la descarga o la conversión fallan -- y que no volvió la conversión
 * manual con imagecreatefromwebp() directo en este archivo.
 *
 * @group pdf-ofertas
 */
class ArticleOfferSheetPdfNoRevientaConImagenRotaTest extends TestCase
{
    private const ARCHIVO = __DIR__.'/../../../app/Http/Controllers/Pdf/ArticleOfferSheetPdf.php';

    /** @test */
    public function resolve_article_image_path_delega_en_pdf_image_path()
    {
        $codigo = file_get_contents(self::ARCHIVO);

        $this->assertStringContainsString(
            'GeneralHelper::pdf_image_path(',
            $codigo,
            'resolve_article_image_path() tiene que resolver la imagen con '
            .'GeneralHelper::pdf_image_path(), que nunca devuelve una URL sin verificar.'
        );
    }

    /** @test */
    public function no_vuelve_el_patron_que_devolvia_la_url_webp_sin_convertir()
    {
        $codigo = file_get_contents(self::ARCHIVO);

        $this->assertDoesNotMatchRegularExpression(
            '/imagecreatefromwebp\s*\(/',
            $codigo,
            'ArticleOfferSheetPdf no debería llamar a imagecreatefromwebp() directamente: esa '
            .'lógica duplicada (con su fallback roto, "return $img_url" cuando la conversión '
            .'fallaba) es la causa original del bug que le impedía imprimir a La Martina. La '
            .'conversión webp→jpg vive en GeneralHelper::pdf_image_path().'
        );
    }

    /** @test */
    public function no_hay_un_fallback_que_devuelva_la_url_original_sin_convertir()
    {
        $codigo = file_get_contents(self::ARCHIVO);

        // El patrón puntual que rompía producción: un "return $img_url" (la variable de imagen
        // sin resolver) en vez de null cuando la imagen no se puede leer.
        $this->assertDoesNotMatchRegularExpression(
            '/return\s+\$img_url\s*;/',
            $codigo
        );
    }
}
