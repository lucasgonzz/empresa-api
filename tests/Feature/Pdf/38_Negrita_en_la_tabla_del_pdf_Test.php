<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\Pdf\ArticleTablePdf;
use App\Http\Controllers\Pdf\NewSalePdf;
use App\Models\PdfColumnOption;
use App\Models\PdfColumnProfile;
use App\Models\Sale;
use App\Services\PdfColumnService;
use App\Http\Controllers\Helpers\PdfDocument\BudgetPdfDocument;
use App\Http\Controllers\Helpers\PdfDocument\OrderPdfDocument;
use Tests\Concerns\DocumentosParaPdf;
use Tests\EmpresaTestCase;
use Tests\Feature\Pdf\Concerns\ComprobantesConDisenoDePagina;

/**
 * La negrita en el VALOR de las columnas de la tabla (misión pdf-negrita-perfil, 20/10/2026):
 * `pdf_column_profiles.bold_columns` lista los ids de las columnas cuyo valor sale en negrita en
 * los renglones de la venta (el remito de siempre y el de diseño con cajas), el presupuesto, el
 * pedido online y el catálogo de artículos. El encabezado de la tabla ya era negrita.
 *
 * Se lee el PDF real (sin comprimir): la fuente con la que se dibuja cada texto. Y se prueba lo
 * que tiene que seguir igual: sin `bold_columns` el PDF es el de siempre, y una columna en negrita
 * se MIDE en negrita (el salto de línea y el recorte con puntos suspensivos no se calculan con la
 * letra normal para después imprimir una más ancha).
 *
 * @group pdf-negrita-perfil
 */
class Negrita_en_la_tabla_del_pdf_Test extends EmpresaTestCase
{
    use DocumentosParaPdf;
    use ComprobantesConDisenoDePagina;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_dueno_para_pdf();

        /** El catálogo de artículos resuelve el dueño del usuario autenticado (UserHelper::getFullModel()). */
        $this->actingAs($this->dueno, 'web');

        require_once app_path('Http/Controllers/CommonLaravel/fpdf/fpdf.php');
    }

    protected function tearDown(): void
    {
        $this->borrar_archivos_de_prueba();

        parent::tearDown();
    }

    // ── Ayudas ──────────────────────────────────────────────────────────────────────────────

    /**
     * La opción del catálogo de columnas por modelo y resolver.
     *
     * @param string $model_name
     * @param string $resolver
     * @return \App\Models\PdfColumnOption
     */
    protected function opcion($model_name, $resolver)
    {
        /** El catálogo de artículo, presupuesto y pedido se siembra desde el código al pedirlo. */
        PdfColumnService::get_options($model_name);

        $opcion = PdfColumnOption::where('model_name', $model_name)->where('value_resolver', $resolver)->first();

        $this->assertNotNull($opcion, 'Falta la opción '.$resolver.' de '.$model_name);

        return $opcion;
    }

    /**
     * Guarda la negrita del perfil y lo devuelve recargado.
     *
     * @param \App\Models\PdfColumnProfile $perfil
     * @param array|null                   $ids
     * @return \App\Models\PdfColumnProfile
     */
    protected function con_negrita(PdfColumnProfile $perfil, $ids)
    {
        $perfil->bold_columns = $ids;
        $perfil->save();

        return PdfColumnProfile::find($perfil->id);
    }

    /**
     * El nombre de la fuente con la que se dibuja un texto entero.
     *
     * @param string $texto
     * @param string $pdf
     * @return string|null 'Helvetica', 'Helvetica-Bold'…
     */
    protected function fuente_de($texto, $pdf)
    {
        $estilo = $this->estilo_del_texto($texto, $pdf);

        $this->assertNotNull($estilo, 'El PDF no dibuja el texto: '.$texto);

        return $estilo['fuente'];
    }

    /**
     * El PDF sin lo único que cambia entre dos corridas del mismo comprobante (la fecha de creación
     * que FPDF escribe en el diccionario de información), para poder compararlos byte a byte.
     *
     * @param string $pdf
     * @return string
     */
    protected function sin_fecha_de_creacion($pdf)
    {
        return preg_replace('~/CreationDate \(D:\d+\)~', '/CreationDate (D:0)', $pdf);
    }

    /**
     * Un texto que entra en UN renglón de $ancho_mm con la letra normal pero NO con la negrita
     * (Helvetica 8 pt): sirve para probar que el salto de línea y el recorte se miden con la letra
     * con la que se imprime. Lo que entra en una celda es el ancho menos 2 mm (el margen de FPDF a
     * cada lado): el texto tiene que medir menos que eso en normal y más en negrita, con 0,3 mm de
     * aire de cada lado para que el redondeo no decida el resultado.
     *
     * @param float $ancho_mm
     * @return string
     */
    protected function texto_que_entra_normal_pero_no_en_negrita($ancho_mm)
    {
        $medidor = new \fpdf();
        $medidor->AddPage();

        foreach (['Destornillador', 'Martillo', 'Mecha', 'Tornillo', 'Cable', 'Pintura'] as $palabra) {
            for ($veces = 2; $veces <= 40; $veces++) {
                $texto = trim(str_repeat($palabra.' ', $veces));

                $medidor->SetFont('Arial', '', 8);
                $normal = $medidor->GetStringWidth($texto);
                $medidor->SetFont('Arial', 'B', 8);
                $negrita = $medidor->GetStringWidth($texto);

                if ($normal <= $ancho_mm - 2.3 && $negrita > $ancho_mm - 1.7) {
                    return $texto;
                }
            }
        }

        $this->fail('No se encontró un texto que entre en '.$ancho_mm.' mm en letra normal y no en negrita.');
    }

    /**
     * Las tres variantes de "sin negrita" que tienen que dar el mismo PDF que antes de existir la
     * función: la columna en null, una lista vacía y una lista con ids que no son de este perfil.
     *
     * @param \App\Models\PdfColumnProfile $perfil
     * @param \Closure                     $renderizar function (PdfColumnProfile): string
     * @param int                          $id_ajeno id de una opción que NO está en el perfil.
     * @return void
     */
    protected function assertSinNegritaEsElPdfDeSiempre(PdfColumnProfile $perfil, \Closure $renderizar, $id_ajeno)
    {
        $de_siempre = $this->sin_fecha_de_creacion($renderizar($this->con_negrita($perfil, null)));

        $this->assertSame(
            $de_siempre,
            $this->sin_fecha_de_creacion($renderizar($this->con_negrita($perfil, []))),
            'Una lista vacía tiene que imprimir igual que null.'
        );

        $this->assertSame(
            $de_siempre,
            $this->sin_fecha_de_creacion($renderizar($this->con_negrita($perfil, [$id_ajeno]))),
            'Un id que no es de una columna del perfil no puede cambiar nada.'
        );
    }

    // ── Presupuesto y pedido (ProfileDocumentPdf) ───────────────────────────────────────────

    /**
     * @test
     */
    public function en_un_presupuesto_la_columna_marcada_sale_en_negrita_y_las_demas_no()
    {
        $perfil = $this->diseno('budget', 'Presupuesto');
        $nombre = $this->opcion('budget', 'document_item_name');

        $presupuesto = $this->crear_presupuesto([
            ['article' => $this->crear_articulo('Taladro percutor 13mm', ['bar_code' => '7791111111111']), 'amount' => 2, 'price' => 1000, 'bonus' => null],
            ['article' => $this->crear_articulo('Mecha widia 8mm', ['bar_code' => '7792222222222']), 'amount' => 1, 'price' => 500, 'bonus' => null],
        ]);
        $fuente = new BudgetPdfDocument($presupuesto);

        $antes = $this->renderizar($fuente, $this->con_negrita($perfil, null));
        $this->assertSame('Helvetica', $this->fuente_de('Taladro percutor 13mm', $antes), 'Sin bold_columns el valor sale en letra normal.');

        $despues = $this->renderizar(new BudgetPdfDocument($presupuesto), $this->con_negrita($perfil, [$nombre->id]));

        $this->assertSame('Helvetica-Bold', $this->fuente_de('Taladro percutor 13mm', $despues));
        $this->assertSame('Helvetica-Bold', $this->fuente_de('Mecha widia 8mm', $despues), 'Todos los renglones de la columna.');
        $this->assertSame(8.0, $this->estilo_del_texto('Taladro percutor 13mm', $despues)['tamano'], 'La negrita no cambia el tamaño.');

        /** Las otras columnas siguen en normal, también la del renglón que viene DESPUÉS de uno en negrita. */
        $this->assertSame('Helvetica', $this->fuente_de('7791111111111', $despues));
        $this->assertSame('Helvetica', $this->fuente_de('7792222222222', $despues));

        /** El encabezado de la tabla ya era negrita y sigue igual. */
        $this->assertSame($this->fuente_de('Nombre', $antes), $this->fuente_de('Nombre', $despues));
        $this->assertSame('Helvetica-Bold', $this->fuente_de('Nombre', $despues));
    }

    /**
     * @test
     */
    public function en_un_presupuesto_sin_negrita_el_pdf_es_el_de_siempre()
    {
        $perfil = $this->diseno('budget', 'Presupuesto');
        $presupuesto = $this->crear_presupuesto_completo();

        /** Una columna del catálogo que el diseño NO muestra: marcarla en negrita no cambia nada. */
        $ajena = $this->opcion('budget', 'document_item_brand_name');
        $en_el_diseno = $perfil->pdf_column_options->firstWhere('id', $ajena->id);
        $this->assertTrue(is_null($en_el_diseno) || ! $en_el_diseno->pivot->visible, 'La columna ajena no debe estar visible en el diseño.');

        $this->assertSinNegritaEsElPdfDeSiempre($perfil, function ($p) use ($presupuesto) {
            return $this->renderizar(new BudgetPdfDocument($presupuesto), $p);
        }, $ajena->id);
    }

    /**
     * @test
     */
    public function en_un_pedido_online_la_columna_marcada_sale_en_negrita()
    {
        $perfil = $this->diseno('order', 'Pedido online');
        $nombre = $this->opcion('order', 'document_item_name');
        $pedido = $this->crear_pedido_completo();

        $antes = $this->renderizar(new OrderPdfDocument($pedido), $this->con_negrita($perfil, null));
        $this->assertSame('Helvetica', $this->fuente_de('Tornillos x100', $antes));

        $despues = $this->renderizar(new OrderPdfDocument($pedido), $this->con_negrita($perfil, [$nombre->id]));

        $this->assertSame('Helvetica-Bold', $this->fuente_de('Tornillos x100', $despues));
        $this->assertSame('Helvetica-Bold', $this->fuente_de('Camisa de trabajo', $despues));
        $this->assertSame('Helvetica', $this->fuente_de('7793333333333', $despues), 'El código de barras no se marcó.');
    }

    /**
     * @test
     */
    public function en_un_pedido_online_sin_negrita_el_pdf_es_el_de_siempre()
    {
        $perfil = $this->diseno('order', 'Pedido online');
        $pedido = $this->crear_pedido_completo();
        $ajena = $this->opcion('order', 'document_item_brand_name');
        $en_el_diseno = $perfil->pdf_column_options->firstWhere('id', $ajena->id);
        $this->assertTrue(is_null($en_el_diseno) || ! $en_el_diseno->pivot->visible, 'La columna ajena no debe estar visible en el diseño.');

        $this->assertSinNegritaEsElPdfDeSiempre($perfil, function ($p) use ($pedido) {
            return $this->renderizar(new OrderPdfDocument($pedido), $p);
        }, $ajena->id);
    }

    /**
     * Una celda con salto de línea (wrap_content) en negrita ocupa lo que ocupa en negrita: si la
     * medida se hiciera con la letra normal, el renglón de abajo se dibujaría encima del texto que
     * pasó a dos líneas.
     *
     * @test
     */
    public function la_celda_con_salto_de_linea_en_negrita_se_mide_en_negrita()
    {
        $perfil = $this->diseno('budget', 'Presupuesto');
        $nombre = $this->opcion('budget', 'document_item_name');

        /** El ancho de la columna del nombre (wrap_content) en el diseño por defecto. */
        $ancho = (int) $perfil->pdf_column_options->firstWhere('id', $nombre->id)->pivot->width;
        $largo = $this->texto_que_entra_normal_pero_no_en_negrita($ancho);

        $presupuesto = $this->crear_presupuesto([
            ['article' => $this->crear_articulo($largo, ['bar_code' => '7795550000001']), 'amount' => 1, 'price' => 100, 'bonus' => null],
            ['article' => $this->crear_articulo('Segundo renglon', ['bar_code' => '7795550000002']), 'amount' => 1, 'price' => 100, 'bonus' => null],
        ]);

        $y_del_segundo = function ($pdf) {
            foreach ($this->textos_con_posicion($pdf) as $texto) {
                if ($texto['texto'] === '7795550000002') {
                    return $texto['y'];
                }
            }
            $this->fail('No se dibujó el segundo renglón.');
        };

        $normal = $this->renderizar(new BudgetPdfDocument($presupuesto), $this->con_negrita($perfil, null));
        $negrita = $this->renderizar(new BudgetPdfDocument($presupuesto), $this->con_negrita($perfil, [$nombre->id]));

        $this->assertSame('Helvetica', $this->fuente_de($largo, $normal), 'En letra normal el nombre entra en un solo renglón.');
        $this->assertGreaterThan(
            $y_del_segundo($normal) + 3,
            $y_del_segundo($negrita),
            'En negrita el nombre ocupa dos renglones y el siguiente baja una línea.'
        );
    }

    /**
     * Una celda SIN salto de línea se recorta con puntos suspensivos según lo que mide en negrita.
     *
     * @test
     */
    public function la_celda_sin_salto_de_linea_se_recorta_midiendo_en_negrita()
    {
        $perfil = $this->diseno('budget', 'Presupuesto');
        $nombre = $this->opcion('budget', 'document_item_name');

        $ancho = (int) $perfil->pdf_column_options->firstWhere('id', $nombre->id)->pivot->width;
        $largo = $this->texto_que_entra_normal_pero_no_en_negrita($ancho);

        /** Se apaga el salto de línea de la columna: ahora el valor se recorta. */
        $perfil->pdf_column_options()->updateExistingPivot($nombre->id, ['wrap_content' => false]);

        $presupuesto = $this->crear_presupuesto([
            ['article' => $this->crear_articulo($largo), 'amount' => 1, 'price' => 100, 'bonus' => null],
        ]);

        $normal = $this->renderizar(new BudgetPdfDocument($presupuesto), $this->con_negrita(PdfColumnProfile::find($perfil->id), null));
        $negrita = $this->renderizar(new BudgetPdfDocument($presupuesto), $this->con_negrita(PdfColumnProfile::find($perfil->id), [$nombre->id]));

        $this->assertDibujaTexto($largo, $normal, 'En letra normal el nombre entra entero.');
        $this->assertNoDibujaTexto($largo, $negrita, 'En negrita no entra entero.');
        $this->assertSame('Helvetica-Bold', $this->fuente_de(
            collect($this->textos_legibles($negrita))->first(function ($t) use ($largo) {
                return substr($t, -3) === '...' && strpos($largo, substr($t, 0, 10)) === 0;
            }),
            $negrita
        ), 'El texto recortado también va en negrita.');
    }

    // ── Venta con diseño de página (SaleLayoutPdf) ──────────────────────────────────────────

    /**
     * @test
     */
    public function en_la_venta_con_diseno_de_pagina_la_columna_marcada_sale_en_negrita()
    {
        $venta = $this->crear_venta_completa();
        $perfil = $this->perfil_de_venta([], $this->diseno_de_remito_completo());
        $nombre = $this->opcion('sale', 'item_name');

        $antes = $this->pdf_de_venta($venta, $this->con_negrita($perfil, null));
        $this->assertSame('Helvetica', $this->fuente_de('Taladro percutor 13mm', $antes));

        $despues = $this->pdf_de_venta($venta, $this->con_negrita($perfil, [$nombre->id]));

        $this->assertSame('Helvetica-Bold', $this->fuente_de('Taladro percutor 13mm', $despues));
        $this->assertSame('Helvetica-Bold', $this->fuente_de('Mecha widia 8mm', $despues));
        $this->assertSame(8.0, $this->estilo_del_texto('Mecha widia 8mm', $despues)['tamano']);
    }

    /**
     * @test
     */
    public function en_la_venta_con_diseno_de_pagina_sin_negrita_el_pdf_es_el_de_siempre()
    {
        $venta = $this->crear_venta_completa();
        $perfil = $this->perfil_de_venta([], $this->diseno_de_remito_completo());
        $ajena = $this->opcion('sale', 'item_discount_percentage');

        $this->assertSinNegritaEsElPdfDeSiempre($perfil, function ($p) use ($venta) {
            return $this->pdf_de_venta($venta, $p);
        }, $ajena->id);
    }

    // ── Venta de siempre (NewSalePdf) ───────────────────────────────────────────────────────

    /**
     * El PDF de NewSalePdf armado SIN emitirlo (el constructor suma un parámetro opcional que
     * por defecto emite y termina el script, como siempre).
     *
     * @param \App\Models\Sale             $venta
     * @param \App\Models\PdfColumnProfile $perfil
     * @return string
     */
    protected function pdf_de_remito_de_siempre($venta, PdfColumnProfile $perfil)
    {
        $pdf = new NewSalePdf(Sale::find($venta->id), $perfil->id, null, null, false);
        $pdf->SetCompression(false);

        return $pdf->Output('S');
    }

    /**
     * @test
     */
    public function en_el_remito_de_siempre_la_columna_marcada_sale_en_negrita()
    {
        $venta = $this->crear_venta_completa();
        $perfil = $this->perfil_de_venta();
        $nombre = $this->opcion('sale', 'item_name');
        $cantidad = $this->opcion('sale', 'item_amount');

        $antes = $this->pdf_de_remito_de_siempre($venta, $this->con_negrita($perfil, null));
        $this->assertSame('Helvetica', $this->fuente_de('Taladro percutor 13mm', $antes));

        $despues = $this->pdf_de_remito_de_siempre($venta, $this->con_negrita($perfil, [$nombre->id]));

        $this->assertSame('Helvetica-Bold', $this->fuente_de('Taladro percutor 13mm', $despues));
        $this->assertSame('Helvetica-Bold', $this->fuente_de('Mecha widia 8mm', $despues));
        $this->assertSame($this->estilo_del_texto('Taladro percutor 13mm', $antes)['tamano'], $this->estilo_del_texto('Taladro percutor 13mm', $despues)['tamano']);

        /** Y la cantidad, que viene después en el mismo renglón, sigue en normal: la negrita no se arrastra. */
        $con_cantidad = $this->pdf_de_remito_de_siempre($venta, $this->con_negrita($perfil, [$cantidad->id]));
        $this->assertSame('Helvetica', $this->fuente_de('Taladro percutor 13mm', $con_cantidad));
        $this->assertNotSame($this->fuente_de('Taladro percutor 13mm', $despues), $this->fuente_de('Taladro percutor 13mm', $con_cantidad));
    }

    /**
     * @test
     */
    public function en_el_remito_de_siempre_sin_negrita_el_pdf_es_el_de_siempre()
    {
        $venta = $this->crear_venta_completa();
        $perfil = $this->perfil_de_venta();
        $ajena = $this->opcion('sale', 'item_discount_percentage');

        $this->assertSinNegritaEsElPdfDeSiempre($perfil, function ($p) use ($venta) {
            return $this->pdf_de_remito_de_siempre($venta, $p);
        }, $ajena->id);
    }

    /**
     * @test
     */
    public function en_el_remito_de_siempre_el_recorte_se_mide_en_negrita()
    {
        $perfil = $this->perfil_de_venta();
        $nombre = $this->opcion('sale', 'item_name');
        $largo = $this->texto_que_entra_normal_pero_no_en_negrita(60);

        $venta = $this->crear_venta_completa();
        /** El primer renglón del remito pasa a llamarse como el texto de prueba. */
        $venta->articles->first()->update(['name' => $largo]);

        $normal = $this->pdf_de_remito_de_siempre($venta, $this->con_negrita($perfil, null));
        $negrita = $this->pdf_de_remito_de_siempre($venta, $this->con_negrita($perfil, [$nombre->id]));

        $this->assertDibujaTexto($largo, $normal);
        $this->assertNoDibujaTexto($largo, $negrita, 'En negrita no entra entero en 60 mm: se recorta con "...".');
    }

    /**
     * Las dos ventas (la de siempre y la de diseño con cajas) con la columna del nombre CON salto de
     * línea: en negrita el nombre pasa a dos renglones y el siguiente baja. Si la fila se midiera
     * con la letra normal, los dos renglones se pisarían.
     *
     * @return array<string, \Closure>
     */
    public function ventas_con_salto_de_linea_provider()
    {
        return [
            'remito de siempre' => [false],
            'venta con diseño de página' => [true],
        ];
    }

    /**
     * @test
     * @dataProvider ventas_con_salto_de_linea_provider
     */
    public function en_la_venta_la_celda_con_salto_de_linea_en_negrita_se_mide_en_negrita($con_diseno)
    {
        $perfil = $this->perfil_de_venta([], $con_diseno ? $this->diseno_de_remito_completo() : null);
        $nombre = $this->opcion('sale', 'item_name');
        $perfil->pdf_column_options()->updateExistingPivot($nombre->id, ['wrap_content' => true]);

        $largo = $this->texto_que_entra_normal_pero_no_en_negrita(60);

        $venta = $this->crear_venta_completa();
        /** El primer renglón pasa a llamarse como el texto de prueba; el segundo es "Mecha widia 8mm". */
        $venta->articles->first()->update(['name' => $largo]);

        $renderizar = function ($ids) use ($venta, $perfil, $con_diseno) {
            $p = $this->con_negrita(PdfColumnProfile::find($perfil->id), $ids);

            return $con_diseno ? $this->pdf_de_venta($venta, $p) : $this->pdf_de_remito_de_siempre($venta, $p);
        };

        $y_del_segundo = function ($pdf) {
            foreach ($this->textos_con_posicion($pdf) as $texto) {
                if ($texto['texto'] === 'Mecha widia 8mm') {
                    return $texto['y'];
                }
            }
            $this->fail('No se dibujó el segundo renglón.');
        };

        $normal = $renderizar(null);
        $negrita = $renderizar([$nombre->id]);

        $this->assertSame('Helvetica', $this->fuente_de($largo, $normal), 'En letra normal el nombre entra en un renglón.');
        $this->assertGreaterThan(
            $y_del_segundo($normal) + 3,
            $y_del_segundo($negrita),
            'En negrita el nombre ocupa dos renglones y el siguiente baja una línea.'
        );
    }

    // ── Catálogo de artículos (ArticleTablePdf) ─────────────────────────────────────────────

    /**
     * Un perfil de catálogo de artículos con el nombre (con o sin salto de línea) y el código de barras.
     *
     * @param bool $wrap_en_el_nombre
     * @param int  $ancho_del_nombre
     * @return \App\Models\PdfColumnProfile
     */
    protected function perfil_de_catalogo($wrap_en_el_nombre = false, $ancho_del_nombre = 100)
    {
        $perfil = PdfColumnProfile::create([
            'user_id' => $this->dueno->id,
            'model_name' => 'article',
            'name' => 'zz Catálogo negrita '.uniqid(),
            'paper_width_mm' => 210,
            'printable_width_mm' => 200,
            'margin_mm' => 5,
            'columns' => [],
        ]);

        $perfil->pdf_column_options()->attach($this->opcion('article', 'article_name')->id, [
            'visible' => true, 'order' => 0, 'width' => $ancho_del_nombre, 'wrap_content' => $wrap_en_el_nombre,
        ]);
        $perfil->pdf_column_options()->attach($this->opcion('article', 'article_bar_code')->id, [
            /** Las dos columnas suman el ancho útil (200 mm): ArticleTablePdf reescala los anchos que no lo suman. */
            'visible' => true, 'order' => 1, 'width' => 200 - $ancho_del_nombre, 'wrap_content' => false,
        ]);

        return PdfColumnProfile::find($perfil->id);
    }

    /**
     * El PDF del catálogo armado SIN emitirlo.
     *
     * @param \App\Models\PdfColumnProfile $perfil
     * @param array                        $articulos
     * @return string
     */
    protected function pdf_de_catalogo(PdfColumnProfile $perfil, array $articulos)
    {
        $pdf = new ArticleTablePdf($perfil, $articulos, false);
        $pdf->SetCompression(false);

        return $pdf->Output('S');
    }

    /**
     * @test
     */
    public function en_el_catalogo_de_articulos_la_columna_marcada_sale_en_negrita()
    {
        $perfil = $this->perfil_de_catalogo();
        $nombre = $this->opcion('article', 'article_name');
        $articulos = [
            $this->crear_articulo('Taladro percutor 13mm', ['bar_code' => '7791111111111']),
            $this->crear_articulo('Mecha widia 8mm', ['bar_code' => '7792222222222']),
        ];

        $antes = $this->pdf_de_catalogo($this->con_negrita($perfil, null), $articulos);
        $this->assertSame('Helvetica', $this->fuente_de('Taladro percutor 13mm', $antes));

        $despues = $this->pdf_de_catalogo($this->con_negrita($perfil, [$nombre->id]), $articulos);

        $this->assertSame('Helvetica-Bold', $this->fuente_de('Taladro percutor 13mm', $despues));
        $this->assertSame('Helvetica-Bold', $this->fuente_de('Mecha widia 8mm', $despues));
        $this->assertSame('Helvetica', $this->fuente_de('7791111111111', $despues));
        $this->assertSame('Helvetica', $this->fuente_de('7792222222222', $despues), 'El renglón siguiente a uno en negrita vuelve a la letra normal.');
        $this->assertSame(8.0, $this->estilo_del_texto('Taladro percutor 13mm', $despues)['tamano']);
    }

    /**
     * @test
     */
    public function en_el_catalogo_de_articulos_sin_negrita_el_pdf_es_el_de_siempre()
    {
        $perfil = $this->perfil_de_catalogo();
        $articulos = [
            $this->crear_articulo('Taladro percutor 13mm', ['bar_code' => '7791111111111']),
            $this->crear_articulo('Mecha widia 8mm', ['bar_code' => '7792222222222']),
        ];
        $ajena = $this->opcion('article', 'article_stock');

        $this->assertSinNegritaEsElPdfDeSiempre($perfil, function ($p) use ($articulos) {
            return $this->pdf_de_catalogo($p, $articulos);
        }, $ajena->id);
    }

    /**
     * @test
     */
    public function en_el_catalogo_de_articulos_la_celda_con_salto_de_linea_se_mide_en_negrita()
    {
        $ancho = 45;
        $perfil = $this->perfil_de_catalogo(true, $ancho);
        $nombre = $this->opcion('article', 'article_name');
        $largo = $this->texto_que_entra_normal_pero_no_en_negrita($ancho);
        $articulos = [
            $this->crear_articulo($largo, ['bar_code' => '7796660000001']),
            $this->crear_articulo('Segundo articulo', ['bar_code' => '7796660000002']),
        ];

        $y_del_segundo = function ($pdf) {
            foreach ($this->textos_con_posicion($pdf) as $texto) {
                if ($texto['texto'] === '7796660000002') {
                    return $texto['y'];
                }
            }
            $this->fail('No se dibujó el segundo artículo.');
        };

        $normal = $this->pdf_de_catalogo($this->con_negrita($perfil, null), $articulos);
        $negrita = $this->pdf_de_catalogo($this->con_negrita($perfil, [$nombre->id]), $articulos);

        $this->assertGreaterThan(
            $y_del_segundo($normal) + 2,
            $y_del_segundo($negrita),
            'En negrita el nombre ocupa dos renglones: la fila es más alta y la siguiente baja.'
        );
    }
}
