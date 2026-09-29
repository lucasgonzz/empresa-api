<?php

namespace Tests\Feature\EtiquetasDeGondola;

use App\Http\Controllers\Helpers\ArticleTicketDesignHelper;
use App\Http\Controllers\Pdf\ArticleTicket\ArticleTicketDesignPdf;
use Illuminate\Support\Facades\DB;

/**
 * El PDF de etiquetas de góndola dibujado según un diseño (misión disenos-etiquetas-gondola,
 * 29/9/2026): `GET article/tickets-pdf/{ids}?article_ticket_design_id=`.
 *
 * 🔴 El camino viejo (`ArticleTicketPdf`) no se puede ejercitar acá: termina en `Output(); exit;`
 * y mataría el proceso de PHPUnit. Lo que sí se fija es la bifurcación: un diseño ajeno no lo
 * resuelve `diseno_del_dueno()`, que es lo único que decide el camino en `ArticleController`.
 *
 * Los tests que miran el contenido instancian la clase directo con la compresión apagada, para
 * poder buscar los textos en el binario.
 *
 * @group etiquetas_de_gondola
 */
class Pdf_de_etiquetas_Test extends EtiquetasDeGondolaTestCase
{
    /**
     * @param  string  $pdf
     * @return int
     */
    protected function paginas($pdf)
    {
        return preg_match_all('#/Type /Page[^s]#', $pdf);
    }

    /**
     * @param  string  $pdf
     * @return int
     */
    protected function imagenes($pdf)
    {
        return substr_count($pdf, '/Subtype /Image');
    }

    /**
     * El PDF sin comprimir, generado directo con la clase.
     *
     * @param  array   $diseno
     * @param  array   $ids
     * @param  int     $owner_id
     * @return string
     */
    protected function pdf_plano(array $diseno, array $ids, $owner_id)
    {
        $pdf = new ArticleTicketDesignPdf($diseno, implode('-', $ids), $owner_id);
        $pdf->SetCompression(false);

        return $pdf->generar();
    }

    /**
     * Un diseño con TODOS los tipos del catálogo, en una etiqueta de 1 columna.
     *
     * @param  int|null  $lista_id
     * @return array
     */
    protected function diseno_con_todo($lista_id = null)
    {
        $elementos = array();
        $y = 0;

        foreach (array_keys(ArticleTicketDesignHelper::CATALOGO) as $i => $tipo) {
            $extra = array('id' => 'c'.$i, 'y' => $y, 'w' => 100, 'h' => 5);

            if ($tipo === 'precio_lista') {
                if (is_null($lista_id)) {
                    continue;
                }
                $extra['price_type_id'] = $lista_id;
                $extra['rotulo'] = true;
            }

            if ($tipo === 'precio_final') {
                $extra['rotulo'] = true;
            }

            if ($tipo === 'texto_fijo') {
                $extra['texto'] = 'OFERTA';
            }

            $elementos[] = $this->elemento($tipo, $extra);
            $y += 5;
        }

        return array(
            'version' => 1, 'columnas' => 1, 'filas' => 3, 'alto_mm' => 95, 'marco' => true,
            'elementos' => $elementos,
        );
    }

    /** @test */
    public function con_un_diseno_propio_responde_un_pdf_inline()
    {
        $dueno = $this->crear_dueno();
        $articulo = $this->crear_articulo($dueno);

        $diseno = $this->crear_diseno($dueno, 'Etiqueta', ArticleTicketDesignHelper::diseno_actual());

        $this->actingAs($dueno, 'web');

        $respuesta = $this->get('article/tickets-pdf/'.$articulo.'?article_ticket_design_id='.$diseno->id);

        $respuesta->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $respuesta->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', $respuesta->headers->get('Content-Disposition'));
        $this->assertSame('%PDF', substr($respuesta->getContent(), 0, 4));
        $this->assertSame(1, $this->paginas($respuesta->getContent()));
    }

    /** @test */
    public function un_diseno_ajeno_no_se_resuelve_y_cae_al_camino_de_siempre()
    {
        $dueno = $this->crear_dueno();
        $otro = $this->crear_dueno();

        $ajeno = $this->crear_diseno($otro, 'Ajeno', ArticleTicketDesignHelper::diseno_actual());
        $propio = $this->crear_diseno($dueno, 'Propio', ArticleTicketDesignHelper::diseno_actual());

        $this->assertNull(ArticleTicketDesignHelper::diseno_del_dueno($ajeno->id, $dueno->id));
        $this->assertNull(ArticleTicketDesignHelper::diseno_del_dueno(null, $dueno->id));
        $this->assertNull(ArticleTicketDesignHelper::diseno_del_dueno('', $dueno->id));
        $this->assertNull(ArticleTicketDesignHelper::diseno_del_dueno($propio->id.'abc', $dueno->id));
        $this->assertSame($propio->id, ArticleTicketDesignHelper::diseno_del_dueno((string) $propio->id, $dueno->id)->id);
    }

    /** @test */
    public function solo_imprime_articulos_del_dueno_y_respeta_el_orden_de_los_ids()
    {
        $dueno = $this->crear_dueno();
        $otro = $this->crear_dueno();

        $primero = $this->crear_articulo($dueno, array('name' => 'PRIMEROZZ'));
        $segundo = $this->crear_articulo($dueno, array('name' => 'SEGUNDOZZ'));
        $ajeno = $this->crear_articulo($otro, array('name' => 'AJENOZZ'));

        $pdf = $this->pdf_plano(ArticleTicketDesignHelper::diseno_actual(), array($segundo, 999999999, $ajeno, $primero), $dueno->id);

        $this->assertStringNotContainsString('AJENOZZ', $pdf);
        $this->assertLessThan(strpos($pdf, 'PRIMEROZZ'), strpos($pdf, 'SEGUNDOZZ'));
    }

    /** @test */
    public function el_diseno_de_siempre_imprime_precio_nombre_codigo_y_fecha_en_latin1()
    {
        $dueno = $this->crear_dueno();
        $articulo = $this->crear_articulo($dueno, array('name' => 'Ñandú de peluche', 'final_price' => 1234.00));

        $pdf = $this->pdf_plano(ArticleTicketDesignHelper::diseno_actual(), array($articulo), $dueno->id);

        $this->assertStringContainsString('($1.234)', $pdf);
        $this->assertStringContainsString("(\xD1and\xFA de peluche)", $pdf);
        $this->assertStringContainsString('(7790387000144)', $pdf);
        $this->assertStringContainsString('('.date('d/m/y').')', $pdf);
        $this->assertSame(1, $this->imagenes($pdf), 'El código de barras va como imagen.');
    }

    /** @test */
    public function un_texto_largo_se_corta_con_elipsis_y_no_se_desborda()
    {
        $dueno = $this->crear_dueno();
        $articulo = $this->crear_articulo($dueno, array('name' => str_repeat('Palabra ', 60)));

        $diseno = ArticleTicketDesignHelper::diseno_actual();

        /* Sin saltos de línea: un renglón con "…" (0x85 en CP1252). */
        $diseno['elementos'][1]['saltos_de_linea'] = false;
        $pdf = $this->pdf_plano($diseno, array($articulo), $dueno->id);
        $this->assertSame(1, preg_match_all('/\(Palabra[^)]*\x85\)/', $pdf));

        /* Con saltos: 3 renglones de 12 pt entran en 15 mm, y el último termina en "…". */
        $diseno['elementos'][1]['saltos_de_linea'] = true;
        $pdf = $this->pdf_plano($diseno, array($articulo), $dueno->id);
        $this->assertSame(3, preg_match_all('/\(Palabra[^)]*\)/', $pdf));
        $this->assertSame(1, preg_match_all('/\(Palabra[^)]*\x85\)/', $pdf));

        /* Cada campo va recortado a su recuadro. */
        $this->assertSame(5, substr_count($pdf, ' re W n'));
    }

    /** @test */
    public function un_articulo_sin_codigo_de_barras_ni_imagen_no_rompe_el_pdf()
    {
        $dueno = $this->crear_dueno(true);
        $lista = $this->crear_lista($dueno, 'Mayorista', 1);

        $articulo = $this->crear_articulo($dueno, array('bar_code' => null, 'provider_code' => null, 'stock' => null));

        $diseno = $this->crear_diseno($dueno, 'Todo', $this->diseno_con_todo($lista->id));

        $this->actingAs($dueno, 'web');

        $respuesta = $this->get('article/tickets-pdf/'.$articulo.'?article_ticket_design_id='.$diseno->id);

        $respuesta->assertStatus(200);
        $this->assertSame('%PDF', substr($respuesta->getContent(), 0, 4));
        $this->assertSame(0, $this->imagenes($respuesta->getContent()));
    }

    /** @test */
    public function imprime_cada_tipo_de_campo()
    {
        $dueno = $this->crear_dueno(true);
        $lista = $this->crear_lista($dueno, 'Mayorista', 1);

        $categoria = DB::table('categories')->insertGetId(array('name' => 'Almacén ZZ', 'user_id' => $dueno->id));

        $articulo = $this->crear_articulo($dueno, array(
            'provider_code' => 'PROV-77',
            'sku'           => 'SKU-42',
            'category_id'   => $categoria,
            'descripcion'   => '<p>Rinde &amp; dura</p>',
            'stock'         => 12.5,
            'final_price'   => 1000,
        ));

        DB::table('article_price_type')->insert(array(
            'article_id' => $articulo, 'price_type_id' => $lista->id, 'final_price' => 1500.5,
        ));

        $pdf = $this->pdf_plano($this->diseno_con_todo($lista->id), array($articulo), $dueno->id);

        $this->assertStringContainsString('(Precio: $1.000)', $pdf);
        $this->assertStringContainsString('(Mayorista: $1.500,50)', $pdf);
        $this->assertStringContainsString('(PROV-77)', $pdf);
        $this->assertStringContainsString('(SKU-42)', $pdf);
        $this->assertStringContainsString("(Almac\xE9n ZZ)", $pdf);
        $this->assertStringContainsString('(Rinde & dura)', $pdf);
        $this->assertStringContainsString('(12,50)', $pdf);
        $this->assertStringContainsString('(OFERTA)', $pdf);
    }

    /** @test */
    public function precio_lista_sin_pivot_cae_al_precio_final_y_con_lista_ajena_no_imprime()
    {
        $dueno = $this->crear_dueno(true);
        $lista = $this->crear_lista($dueno, 'Mayorista', 1);

        $articulo = $this->crear_articulo($dueno, array('final_price' => 777));

        $pdf = $this->pdf_plano(ArticleTicketDesignHelper::diseno_actual($lista->id), array($articulo), $dueno->id);
        $this->assertStringContainsString('($777)', $pdf);

        /* Una lista que no es del dueño (o que se borró): el campo no imprime nada. */
        $otro = $this->crear_dueno(true);
        $ajena = $this->crear_lista($otro, 'Ajena', 1);

        $pdf = $this->pdf_plano(ArticleTicketDesignHelper::diseno_actual($ajena->id), array($articulo), $dueno->id);
        $this->assertStringNotContainsString('($777)', $pdf);
        $this->assertSame('%PDF', substr($pdf, 0, 4));
    }

    /** @test */
    public function la_imagen_del_articulo_se_lee_del_disco_incluso_webp_y_una_rota_no_rompe()
    {
        $dueno = $this->crear_dueno();

        $png = $this->crear_imagen_en_storage('png');
        $webp = $this->crear_imagen_en_storage('webp');
        $rota = $this->crear_archivo_en_storage('jpg', 'esto no es una imagen');

        $diseno = array(
            'version' => 1, 'columnas' => 4, 'filas' => 7, 'alto_mm' => 40, 'marco' => false,
            'elementos' => array($this->elemento('imagen', array('w' => 20, 'h' => 30))),
        );

        $ids = array();

        foreach (array($png, $webp, $rota) as $nombre) {
            $id = $this->crear_articulo($dueno);
            DB::table('images')->insert(array(
                'hosting_url'    => 'http://localhost/storage/'.$nombre,
                'imageable_id'   => $id,
                'imageable_type' => 'article',
            ));
            $ids[] = $id;
        }

        $pdf = $this->pdf_plano($diseno, $ids, $dueno->id);

        $this->assertSame('%PDF', substr($pdf, 0, 4));
        $this->assertSame(2, $this->imagenes($pdf), 'El png y el webp se dibujan; la rota queda en blanco.');
    }

    /** @test */
    public function cuatro_columnas_y_una_columna_y_varias_paginas()
    {
        $dueno = $this->crear_dueno();
        $articulo = $this->crear_articulo($dueno);

        $diseno = ArticleTicketDesignHelper::diseno_actual();

        /* 3 x 7 = 21 por hoja: 22 etiquetas son 2 hojas. */
        $pdf = $this->pdf_plano($diseno, array_fill(0, 22, $articulo), $dueno->id);
        $this->assertSame(2, $this->paginas($pdf));
        $this->assertSame(22, substr_count($pdf, '(7790387000144)'));

        /* 4 columnas con 20 mm de alto: 4 x 14 = 56 por hoja. */
        $diseno['columnas'] = 4;
        $diseno['alto_mm'] = 20;
        $pdf = $this->pdf_plano($diseno, array_fill(0, 57, $articulo), $dueno->id);
        $this->assertSame(2, $this->paginas($pdf));

        /* 1 columna con 95 mm: 3 por hoja. */
        $diseno['columnas'] = 1;
        $diseno['alto_mm'] = 95;
        $pdf = $this->pdf_plano($diseno, array_fill(0, 7, $articulo), $dueno->id);
        $this->assertSame(3, $this->paginas($pdf));

        /* Sin artículos igual sale un PDF válido (una hoja en blanco). */
        $pdf = $this->pdf_plano($diseno, array(), $dueno->id);
        $this->assertSame(1, $this->paginas($pdf));
    }

    /** @test */
    public function el_marco_se_dibuja_en_la_grilla_de_la_hoja()
    {
        $dueno = $this->crear_dueno();
        $articulo = $this->crear_articulo($dueno);

        $pdf = new ArticleTicketDesignPdf(ArticleTicketDesignHelper::diseno_actual(), $articulo.'-'.$articulo, $dueno->id);
        $pdf->SetCompression(false);
        $binario = $pdf->generar();

        /* Rect() de FPDF: "x y w -h re S", en puntos (k = 72 / 25,4). Etiqueta 2: x = 5 + 66,67. */
        $k = 72 / 25.4;
        $this->assertStringContainsString(sprintf('%.2F %.2F %.2F %.2F re S', 5 * $k, (297 - 5) * $k, (200 / 3) * $k, -40 * $k), $binario);
        $this->assertStringContainsString(sprintf('%.2F %.2F %.2F %.2F re S', (5 + 200 / 3) * $k, (297 - 5) * $k, (200 / 3) * $k, -40 * $k), $binario);
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  Andamio de imágenes
     * ---------------------------------------------------------------------------------------
     */

    /**
     * Una imagen real de 40x20 en storage/app/public. Devuelve el nombre del archivo.
     *
     * @param  string  $extension  png | webp
     * @return string
     */
    protected function crear_imagen_en_storage($extension)
    {
        $nombre = 'zz_etiquetas_test_'.uniqid().'.'.$extension;
        $ruta = storage_path('app/public/'.$nombre);

        $imagen = imagecreatetruecolor(40, 20);
        imagefilledrectangle($imagen, 0, 0, 39, 19, imagecolorallocate($imagen, 200, 30, 30));

        if ($extension === 'webp') {
            imagewebp($imagen, $ruta);
        } else {
            imagepng($imagen, $ruta);
        }

        imagedestroy($imagen);

        $this->registrar_derivados($nombre);

        return $nombre;
    }

    /**
     * @param  string  $extension
     * @param  string  $contenido
     * @return string
     */
    protected function crear_archivo_en_storage($extension, $contenido)
    {
        $nombre = 'zz_etiquetas_test_'.uniqid().'.'.$extension;

        file_put_contents(storage_path('app/public/'.$nombre), $contenido);

        $this->registrar_derivados($nombre);

        return $nombre;
    }

    /**
     * El archivo y lo que `GeneralHelper::pdf_image_path()` deriva de él (el .jpg del webp y el
     * cache de pdf_cache), para borrarlos al terminar.
     *
     * @param  string  $nombre
     * @return void
     */
    protected function registrar_derivados($nombre)
    {
        $this->archivos_temporales[] = storage_path('app/public/'.$nombre);
        $this->archivos_temporales[] = storage_path('app/public/'.pathinfo($nombre, PATHINFO_FILENAME).'.jpg');
        $this->archivos_temporales[] = storage_path('app/public/pdf_cache/'.md5('http://localhost/storage/'.$nombre).'.jpg');
    }
}
