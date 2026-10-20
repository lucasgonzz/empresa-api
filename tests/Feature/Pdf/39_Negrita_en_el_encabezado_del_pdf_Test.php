<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\Helpers\PdfDocument\BudgetPdfDocument;
use App\Http\Controllers\Helpers\PdfDocument\OrderPdfDocument;
use App\Http\Controllers\Helpers\PdfDocumentSetupHelper;
use App\Http\Controllers\Pdf\ArticleTablePdf;
use App\Http\Controllers\Pdf\NewSalePdf;
use App\Models\AfipInformation;
use App\Models\PdfColumnProfile;
use App\Models\Sale;
use Tests\Concerns\DocumentosParaPdf;
use Tests\EmpresaTestCase;
use Tests\Feature\Pdf\Concerns\ComprobantesConDisenoDePagina;

/**
 * La negrita en el VALOR de los datos del encabezado (misión pdf-negrita-perfil, 20/10/2026), en
 * los dos encabezados que tiene el sistema:
 *
 *  - Encabezado de venta / presupuesto / pedido: `header_layout.bold` lista las keys de los datos
 *    (emisor y cliente) cuyo valor va en negrita; el rótulo ya era negrita.
 *  - Encabezado del catálogo de artículos: cada renglón puede traer `bold`.
 *
 * Se lee el PDF real (sin comprimir): la fuente con la que se dibuja cada texto. Y se prueba lo
 * que tiene que seguir igual: sin la clave el PDF es el de siempre, y un dato en negrita se MIDE
 * en negrita (si no, el dato que pasa a ocupar dos renglones pisaría lo de abajo).
 *
 * @group pdf-negrita-perfil
 */
class Negrita_en_el_encabezado_del_pdf_Test extends EmpresaTestCase
{
    use DocumentosParaPdf;
    use ComprobantesConDisenoDePagina;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_dueno_para_pdf();

        /** Un IIBB distinto del CUIT, para poder distinguir cada valor en el PDF. */
        AfipInformation::where('user_id', $this->dueno->id)->update(['ingresos_brutos' => '901-234567-8']);

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
     * El header_layout por defecto de una venta con la negrita pedida (o sin la clave si es null).
     *
     * @param array|null $bold
     * @param bool       $fiscal
     * @return array
     */
    protected function header_con_negrita($bold, $fiscal = false)
    {
        $layout = PdfColumnProfile::default_header_layout($fiscal);

        if (! is_null($bold)) {
            $layout['bold'] = $bold;
        }

        return $layout;
    }

    /**
     * El nombre de la fuente con la que se dibuja un texto entero.
     *
     * @param string $texto
     * @param string $pdf
     * @return string|null
     */
    protected function fuente_de($texto, $pdf)
    {
        $estilo = $this->estilo_del_texto($texto, $pdf);

        $this->assertNotNull($estilo, 'El PDF no dibuja el texto: '.$texto);

        return $estilo['fuente'];
    }

    /**
     * El PDF sin lo único que cambia entre dos corridas del mismo comprobante.
     *
     * @param string $pdf
     * @return string
     */
    protected function sin_fecha_de_creacion($pdf)
    {
        return preg_replace('~/CreationDate \(D:\d+\)~', '/CreationDate (D:0)', $pdf);
    }

    /**
     * El remito de siempre (NewSalePdf) armado SIN emitirlo.
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
     * La posición (mm desde arriba) del primer texto igual a $texto en la primera hoja.
     *
     * @param string $texto
     * @param string $pdf
     * @return float
     */
    protected function y_de($texto, $pdf)
    {
        foreach ($this->textos_con_posicion($pdf) as $dibujado) {
            if ($dibujado['texto'] === $texto) {
                return $dibujado['y'];
            }
        }

        $this->fail('El PDF no dibuja el texto: '.$texto);
    }

    // ── Encabezado de la venta con diseño de página (SaleLayoutPdf) ─────────────────────────

    /**
     * @test
     */
    public function en_el_encabezado_de_la_venta_con_diseno_el_dato_marcado_sale_en_negrita()
    {
        $venta = $this->crear_venta_completa();

        $antes = $this->pdf_de_venta($venta, $this->perfil_de_venta(['header_layout' => $this->header_con_negrita(null)], $this->diseno_de_remito_completo()));
        $this->assertSame('Helvetica', $this->fuente_de('30111111118', $antes), 'Sin la clave bold el CUIT sale en letra normal.');
        $this->assertSame('Helvetica', $this->fuente_de('Razon Social PDF SA', $antes));

        $despues = $this->pdf_de_venta($venta, $this->perfil_de_venta(
            ['header_layout' => $this->header_con_negrita(['cuit', 'razon_social'])],
            $this->diseno_de_remito_completo()
        ));

        $this->assertSame('Helvetica-Bold', $this->fuente_de('30111111118', $despues), 'El CUIT pedido sale en negrita.');
        $this->assertSame('Helvetica-Bold', $this->fuente_de('Razon Social PDF SA', $despues), 'La razón social pedida sale en negrita.');

        /** Los que no se pidieron siguen en normal. */
        $this->assertSame('Helvetica', $this->fuente_de('901-234567-8', $despues));
        $this->assertSame('Helvetica', $this->fuente_de('Calle Falsa 123', $despues));

        /** El rótulo ya era negrita y sigue igual, también el de un dato que no se pidió. */
        $this->assertSame('Helvetica-Bold', $this->fuente_de('CUIT: ', $despues));
        $this->assertSame('Helvetica-Bold', $this->fuente_de('Ingresos Brutos: ', $despues));
    }

    /**
     * @test
     */
    public function en_el_encabezado_de_la_venta_con_diseno_sin_negrita_el_pdf_es_el_de_siempre()
    {
        $venta = $this->crear_venta_completa();
        $diseno = $this->diseno_de_remito_completo();

        $de_siempre = $this->sin_fecha_de_creacion($this->pdf_de_venta($venta, $this->perfil_de_venta(['header_layout' => $this->header_con_negrita(null)], $diseno)));

        foreach ([[], ['clave_que_no_es_de_ningun_dato'], ['cliente_nombre']] as $bold) {
            $con_clave = $this->sin_fecha_de_creacion($this->pdf_de_venta($venta, $this->perfil_de_venta(['header_layout' => $this->header_con_negrita($bold)], $diseno)));

            $this->assertSame(
                $de_siempre,
                $con_clave,
                'Una clave bold sin datos del emisor que marcar no puede cambiar nada: '.json_encode($bold)
            );
        }
    }

    /**
     * Busca un IIBB que en letra normal entra en UN renglón del panel derecho del encabezado y en
     * negrita no, y afirma que en ese caso el PDF con la negrita es más alto: el dato pasó a dos
     * renglones y empujó la tabla hacia abajo. Si se midiera en normal para dibujar después en
     * negrita, el valor entraría "según la medida" y se saldría del panel sin cortarse: nunca habría
     * un renglón de más.
     *
     * Se busca así: primero el texto más largo (una palabra de 'm', que mide 7 % más en negrita) que
     * en letra normal sigue ocupando un renglón; después se prueba esa longitud y las cuatro
     * anteriores en negrita (la diferencia de 7 % del panel son unas dos letras): alguna tiene que
     * pasar a dos renglones.
     *
     * @param \Closure $renderizar function (array $header_layout): string
     * @return void
     */
    protected function assertElDatoEnNegritaSeMideEnNegrita(\Closure $renderizar)
    {
        $afip = AfipInformation::where('user_id', $this->dueno->id)->first();

        $altura = function ($letras, $bold) use ($afip, $renderizar) {
            $afip->update(['ingresos_brutos' => str_repeat('m', $letras)]);

            return $this->y_de('Taladro percutor 13mm', $renderizar($this->header_con_negrita($bold ? ['ingresos_brutos'] : null)));
        };

        $base = $altura(1, false);
        $mas_largo_que_entra = null;

        for ($letras = 2; $letras <= 90; $letras++) {
            if ($altura($letras, false) > $base + 3) {
                $mas_largo_que_entra = $letras - 1;
                break;
            }
        }

        $this->assertNotNull($mas_largo_que_entra, 'No se encontró dónde el IIBB deja de entrar en un renglón en letra normal.');

        $paso_a_dos_renglones = false;

        for ($letras = $mas_largo_que_entra; $letras > $mas_largo_que_entra - 5 && $letras > 1; $letras--) {
            if ($altura($letras, true) > $base + 3) {
                $paso_a_dos_renglones = true;
                break;
            }
        }

        $this->assertTrue($paso_a_dos_renglones, 'Un IIBB que entra en un renglón en letra normal tiene que pasar a dos en negrita (se mide en negrita).');
    }

    /**
     * @test
     */
    public function en_el_encabezado_de_la_venta_con_diseno_el_dato_en_negrita_se_mide_en_negrita()
    {
        $venta = $this->crear_venta_completa();
        $diseno = $this->diseno_de_remito_completo();

        $this->assertElDatoEnNegritaSeMideEnNegrita(function ($header_layout) use ($venta, $diseno) {
            return $this->pdf_de_venta($venta, $this->perfil_de_venta(['header_layout' => $header_layout], $diseno));
        });
    }

    /**
     * @test
     */
    public function en_el_encabezado_del_remito_de_siempre_el_dato_en_negrita_se_mide_en_negrita()
    {
        $venta = $this->crear_venta_completa();

        $this->assertElDatoEnNegritaSeMideEnNegrita(function ($header_layout) use ($venta) {
            return $this->pdf_de_remito_de_siempre($venta, $this->perfil_de_venta(['header_layout' => $header_layout]));
        });
    }

    // ── Factura (SaleLayoutPdf fiscal) ──────────────────────────────────────────────────────

    /**
     * @test
     */
    public function en_la_factura_cada_valor_del_par_punto_de_venta_y_numero_lleva_su_propio_estilo()
    {
        $venta = $this->crear_venta_completa();
        $ticket = $this->crear_factura($venta, 'B');

        $pdf = function ($bold) use ($venta, $ticket) {
            /** El pie de la factura le pega a api.qrserver.com para el QR: con la red cortada se dibuja igual, sin la imagen. */
            return $this->sin_red_https(function () use ($venta, $ticket, $bold) {
                return $this->pdf_de_venta(
                    $venta,
                    $this->perfil_de_venta(
                        ['is_afip_ticket' => true, 'header_layout' => $this->header_con_negrita($bold, true)],
                        $this->diseno_de_remito_completo()
                    ),
                    $ticket->id
                );
            });
        };

        $sin = $pdf(null);
        $this->assertSame('Helvetica', $this->fuente_de('00000027', $sin));
        $this->assertSame('Helvetica', $this->fuente_de('00001', $sin));

        $solo_numero = $pdf(['numero_comprobante']);
        $this->assertSame('Helvetica-Bold', $this->fuente_de('00000027', $solo_numero));
        $this->assertSame('Helvetica', $this->fuente_de('00001', $solo_numero));

        $solo_punto = $pdf(['punto_venta']);
        $this->assertSame('Helvetica', $this->fuente_de('00000027', $solo_punto));
        $this->assertSame('Helvetica-Bold', $this->fuente_de('00001', $solo_punto));

        $this->assertSame('Helvetica-Bold', $this->fuente_de('Razon Social PDF SA', $pdf(['razon_social'])), 'Los datos fiscales obligatorios también se pueden poner en negrita.');
    }

    // ── Remito de siempre (NewSalePdf) ──────────────────────────────────────────────────────

    /**
     * @test
     */
    public function en_el_remito_de_siempre_los_datos_del_emisor_y_del_cliente_marcados_salen_en_negrita()
    {
        $venta = $this->crear_venta_completa();

        $antes = $this->pdf_de_remito_de_siempre($venta, $this->perfil_de_venta(['header_layout' => $this->header_con_negrita(null)]));
        $this->assertSame('Helvetica', $this->fuente_de('Juan Perez Test', $antes));
        $this->assertSame('Helvetica', $this->fuente_de('Razon Social PDF SA', $antes));

        $despues = $this->pdf_de_remito_de_siempre(
            $venta,
            $this->perfil_de_venta(['header_layout' => $this->header_con_negrita(['cliente_nombre', 'razon_social', 'cuit'])])
        );

        $this->assertSame('Helvetica-Bold', $this->fuente_de('Juan Perez Test', $despues), 'El nombre del cliente pedido sale en negrita.');
        $this->assertSame('Helvetica-Bold', $this->fuente_de('Razon Social PDF SA', $despues));
        $this->assertSame('Helvetica-Bold', $this->fuente_de('30111111118', $despues));

        $this->assertSame('Helvetica', $this->fuente_de('1155555555', $despues), 'El teléfono del cliente no se pidió.');
        $this->assertSame('Helvetica', $this->fuente_de('901-234567-8', $despues));
        $this->assertSame('Helvetica-Bold', $this->fuente_de('Cliente: ', $despues), 'El rótulo sigue en negrita.');
    }

    /**
     * @test
     */
    public function en_el_remito_de_siempre_sin_negrita_el_pdf_es_el_de_siempre()
    {
        $venta = $this->crear_venta_completa();

        $de_siempre = $this->sin_fecha_de_creacion($this->pdf_de_remito_de_siempre($venta, $this->perfil_de_venta(['header_layout' => $this->header_con_negrita(null)])));

        foreach ([[], ['clave_que_no_es_de_ningun_dato'], ['web']] as $bold) {
            $con_clave = $this->sin_fecha_de_creacion($this->pdf_de_remito_de_siempre($venta, $this->perfil_de_venta(['header_layout' => $this->header_con_negrita($bold)])));

            $this->assertSame($de_siempre, $con_clave, 'bold sin datos que marcar no puede cambiar nada: '.json_encode($bold));
        }
    }

    /**
     * Un perfil sin header_layout (el de siempre de los clientes): el default por código, sin
     * negrita.
     *
     * @test
     */
    public function un_perfil_sin_header_layout_imprime_el_encabezado_de_siempre()
    {
        $venta = $this->crear_venta_completa();

        $pdf = $this->pdf_de_remito_de_siempre($venta, $this->perfil_de_venta(['header_layout' => null]));

        $this->assertSame('Helvetica', $this->fuente_de('Juan Perez Test', $pdf));
        $this->assertSame('Helvetica', $this->fuente_de('30111111118', $pdf));
    }

    // ── Presupuesto y pedido (ProfileDocumentPdf) ───────────────────────────────────────────

    /**
     * @test
     */
    public function en_el_presupuesto_los_datos_marcados_salen_en_negrita()
    {
        $perfil = $this->diseno('budget', 'Presupuesto');
        $presupuesto = $this->crear_presupuesto_completo();

        $perfil->header_layout = array_merge(PdfDocumentSetupHelper::default_header_layout_for('budget'), ['bold' => ['cliente_nombre', 'razon_social']]);
        $perfil->save();

        $pdf = $this->renderizar(new BudgetPdfDocument($presupuesto), PdfColumnProfile::find($perfil->id));

        $this->assertSame('Helvetica-Bold', $this->fuente_de('Cliente Presupuesto Test', $pdf));
        $this->assertSame('Helvetica-Bold', $this->fuente_de('Razon Social PDF SA', $pdf));
        $this->assertSame('Helvetica', $this->fuente_de('2215550000', $pdf), 'El teléfono del cliente no se pidió.');
        $this->assertSame('Helvetica', $this->fuente_de('Calle Falsa 123', $pdf));
    }

    /**
     * @test
     */
    public function en_el_presupuesto_y_el_pedido_sin_negrita_el_pdf_es_el_de_siempre()
    {
        $presupuesto = $this->crear_presupuesto_completo();
        $pedido = $this->crear_pedido_completo();

        foreach ([['budget', 'Presupuesto', new BudgetPdfDocument($presupuesto), 'budget'], ['order', 'Pedido online', new OrderPdfDocument($pedido), 'order']] as $caso) {
            list($model_name, $nombre, $fuente, $tipo) = $caso;

            $perfil = $this->diseno($model_name, $nombre);
            $base = PdfDocumentSetupHelper::default_header_layout_for($tipo);

            $perfil->header_layout = $base;
            $perfil->save();
            $de_siempre = $this->sin_fecha_de_creacion($this->renderizar($fuente, PdfColumnProfile::find($perfil->id)));

            foreach ([[], ['clave_que_no_es_de_ningun_dato']] as $bold) {
                $perfil->header_layout = array_merge($base, ['bold' => $bold]);
                $perfil->save();

                $this->assertSame(
                    $de_siempre,
                    $this->sin_fecha_de_creacion($this->renderizar($fuente, PdfColumnProfile::find($perfil->id))),
                    $nombre.': bold sin datos que marcar no puede cambiar nada: '.json_encode($bold)
                );
            }
        }
    }

    /**
     * @test
     */
    public function en_el_pedido_online_el_dato_del_emisor_marcado_sale_en_negrita()
    {
        $perfil = $this->diseno('order', 'Pedido online');
        $pedido = $this->crear_pedido_completo();

        $perfil->header_layout = array_merge(PdfDocumentSetupHelper::default_header_layout_for('order'), ['bold' => ['razon_social']]);
        $perfil->save();

        $pdf = $this->renderizar(new OrderPdfDocument($pedido), PdfColumnProfile::find($perfil->id));

        $this->assertSame('Helvetica-Bold', $this->fuente_de('Razon Social PDF SA', $pdf));
        $this->assertSame('Helvetica', $this->fuente_de('Calle Falsa 123', $pdf));
    }

    // ── Encabezado del catálogo de artículos (ArticleTablePdf) ──────────────────────────────

    /**
     * Un perfil de catálogo con un encabezado diseñado: dos renglones libres a la izquierda y dos a la
     * derecha (la mitad con negrita según $bold).
     *
     * @param bool $bold
     * @return \App\Models\PdfColumnProfile
     */
    protected function perfil_de_catalogo_con_encabezado($bold)
    {
        $perfil = PdfColumnProfile::create([
            'user_id' => $this->dueno->id,
            'model_name' => 'article',
            'name' => 'zz Catálogo encabezado negrita '.uniqid(),
            'paper_width_mm' => 210,
            'printable_width_mm' => 200,
            'margin_mm' => 5,
            'columns' => [],
            'catalog_header_layout' => [
                'logo' => ['show' => false, 'pages' => 'all', 'size_mm' => 25],
                'company_name' => ['show' => false],
                'rows_pages' => 'all',
                'izquierda' => [
                    array_merge(['title' => 'Horario', 'value' => 'Lun a Vie 9 a 18', 'source' => null], $bold ? ['bold' => true] : []),
                    ['title' => 'Zona', 'value' => 'Centro y alrededores', 'source' => null],
                ],
                'derecha' => [
                    array_merge(['title' => 'Web', 'value' => 'www.ejemplo-catalogo.com', 'source' => null], $bold ? ['bold' => true] : []),
                    ['title' => 'Atiende', 'value' => 'Marta Gomez', 'source' => null],
                ],
            ],
        ]);

        $opcion = \App\Models\PdfColumnOption::where('model_name', 'article')->where('value_resolver', 'article_name')->first();
        if (is_null($opcion)) {
            \App\Services\PdfColumnService::get_options('article');
            $opcion = \App\Models\PdfColumnOption::where('model_name', 'article')->where('value_resolver', 'article_name')->first();
        }
        $perfil->pdf_column_options()->attach($opcion->id, ['visible' => true, 'order' => 0, 'width' => 200, 'wrap_content' => false]);

        return PdfColumnProfile::find($perfil->id);
    }

    /**
     * @param \App\Models\PdfColumnProfile $perfil
     * @return string
     */
    protected function pdf_de_catalogo(PdfColumnProfile $perfil)
    {
        $pdf = new ArticleTablePdf($perfil, [$this->crear_articulo('Taladro percutor 13mm')], false);
        $pdf->SetCompression(false);

        return $pdf->Output('S');
    }

    /**
     * @test
     */
    public function en_el_encabezado_del_catalogo_el_valor_del_renglon_marcado_sale_en_negrita_en_las_dos_columnas()
    {
        $pdf = $this->pdf_de_catalogo($this->perfil_de_catalogo_con_encabezado(true));

        /** Columna izquierda y derecha: el valor marcado en negrita, el no marcado en normal. */
        $this->assertSame('Helvetica-Bold', $this->fuente_de('Lun a Vie 9 a 18', $pdf));
        $this->assertSame('Helvetica', $this->fuente_de('Centro y alrededores', $pdf));
        $this->assertSame('Helvetica-Bold', $this->fuente_de('www.ejemplo-catalogo.com', $pdf));
        $this->assertSame('Helvetica', $this->fuente_de('Marta Gomez', $pdf));

        /** Los títulos eran negrita y siguen siéndolo, marcados o no. */
        $this->assertSame('Helvetica-Bold', $this->fuente_de('Horario: ', $pdf));
        $this->assertSame('Helvetica-Bold', $this->fuente_de('Zona: ', $pdf));
        $this->assertSame('Helvetica-Bold', $this->fuente_de('Web: ', $pdf));
        $this->assertSame('Helvetica-Bold', $this->fuente_de('Atiende: ', $pdf));
    }

    /**
     * @test
     */
    public function en_el_encabezado_del_catalogo_sin_negrita_todos_los_valores_van_en_normal()
    {
        $pdf = $this->pdf_de_catalogo($this->perfil_de_catalogo_con_encabezado(false));

        foreach (['Lun a Vie 9 a 18', 'Centro y alrededores', 'www.ejemplo-catalogo.com', 'Marta Gomez'] as $valor) {
            $this->assertSame('Helvetica', $this->fuente_de($valor, $pdf), $valor);
        }
    }

    /**
     * La negrita de un renglón de la columna derecha se mide en negrita: el título y el valor entran en
     * una línea con letra normal y en negrita ya no, así que pasan a dos renglones y el valor se
     * dibuja en su propia línea (no encima del título).
     *
     * @test
     */
    public function el_renglon_del_catalogo_en_negrita_se_mide_en_negrita()
    {
        $perfil = $this->perfil_de_catalogo_con_encabezado(false);

        $medidor = new \fpdf();
        $medidor->AddPage();
        /** La columna derecha del encabezado: del 60 % al borde de la tabla (200 mm útiles). */
        $ancho_columna = 200 - (200 * 0.60);
        $cmargin = 1;

        $valor = null;
        /** Minúsculas anchas: en Helvetica la 'm' mide 7 % más en negrita (las mayúsculas miden lo mismo en las dos). */
        foreach (range(10, 80) as $letras) {
            $candidato = str_repeat('m', $letras);

            $medidor->SetFont('Arial', 'B', 8);
            $titulo = $medidor->GetStringWidth('Web: ');
            $medidor->SetFont('Arial', '', 8);
            $normal = $medidor->GetStringWidth($candidato);
            $medidor->SetFont('Arial', 'B', 8);
            $en_negrita = $medidor->GetStringWidth($candidato);

            if ($titulo + $normal + 3 * $cmargin <= $ancho_columna && $titulo + $en_negrita + 3 * $cmargin > $ancho_columna) {
                $valor = $candidato;
                break;
            }
        }

        $this->assertNotNull($valor, 'No se encontró un valor que entre en una línea en normal y no en negrita.');

        $armar = function ($bold) use ($perfil, $valor) {
            $layout = $perfil->catalog_header_layout;
            $layout['derecha'] = [array_merge(['title' => 'Web', 'value' => $valor, 'source' => null], $bold ? ['bold' => true] : [])];
            $perfil->catalog_header_layout = $layout;
            $perfil->save();

            return $this->pdf_de_catalogo(PdfColumnProfile::find($perfil->id));
        };

        $normal = $armar(false);
        $negrita = $armar(true);

        /** En letra normal título y valor comparten la línea; en negrita el valor baja a la siguiente. */
        $this->assertEqualsWithDelta($this->y_de('Web: ', $normal), $this->y_de($valor, $normal), 0.01, 'En normal comparten el renglón.');
        $this->assertGreaterThan($this->y_de('Web: ', $negrita) + 1, $this->y_de($valor, $negrita), 'En negrita el valor baja a su propia línea.');
        $this->assertSame('Helvetica-Bold', $this->fuente_de($valor, $negrita));
    }
}
