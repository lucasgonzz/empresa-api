<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\Helpers\PdfDocument\BudgetPdfDocument;
use App\Http\Controllers\Helpers\PdfDocumentSetupHelper;
use App\Http\Controllers\Pdf\Afip\AfipPdfHelper;
use App\Http\Controllers\Pdf\ProfileDocumentPdf;
use App\Models\PdfColumnProfile;
use App\Services\PdfColumnService;
use Tests\Concerns\DocumentosParaPdf;
use Tests\EmpresaTestCase;

/**
 * Cómo se elige el diseño al imprimir un presupuesto o un pedido online (misión
 * pdf-presupuestos-y-pedidos-personalizables, 29/9/2026).
 *
 * Las rutas de PDF son PÚBLICAS por id (`budget/pdf/{id}/{wp}/{wi}?pdf_column_profile_id=`), así que
 * el diseño se busca siempre con el dueño DEL COMPROBANTE y nunca con el usuario logueado:
 *
 *  - un id propio -> ese diseño;
 *  - un id de OTRO dueño o inexistente -> el default del dueño del comprobante (jamás el diseño
 *    ajeno: sería filtrar el diseño de un cliente a otro);
 *  - un dueño sin ningún diseño (todavía no corrió el seeder) -> null, y el controlador cae al PDF
 *    de siempre.
 *
 * Y la regresión de "un perfil de otro modelo se cuela": el default de `budget` o de `order` no
 * puede salir jamás como el diseño de una venta (remito o factura).
 *
 * @group pdf-documentos
 */
class Elegir_diseno_al_imprimir_Test extends EmpresaTestCase
{
    use DocumentosParaPdf;

    /**
     * Otro dueño, con sus propios diseños.
     *
     * @var \App\Models\User
     */
    protected $otro_dueno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_dueno_para_pdf();

        $this->otro_dueno = $this->crear_dueno('Otro dueno PDF '.uniqid());
        PdfDocumentSetupHelper::apply_for_owner($this->otro_dueno->id);
    }

    /**
     * @param int    $user_id
     * @param string $model_name
     * @param string $nombre
     * @return \App\Models\PdfColumnProfile
     */
    protected function diseno_de($user_id, $model_name, $nombre)
    {
        return PdfColumnProfile::where('user_id', $user_id)->where('model_name', $model_name)->where('name', $nombre)->firstOrFail();
    }

    /**
     * @test
     */
    public function un_id_propio_devuelve_ese_diseno()
    {
        $sin_precios = $this->diseno_de($this->dueno->id, 'budget', 'Presupuesto sin precios');

        $elegido = PdfColumnService::get_profile_for_print($this->dueno->id, 'budget', $sin_precios->id, null);

        $this->assertNotNull($elegido);
        $this->assertSame($sin_precios->id, $elegido->id, 'Pidieron el diseno sin precios y salio otro.');

        /** Sin id, el default del dueño. */
        $sin_id = PdfColumnService::get_profile_for_print($this->dueno->id, 'budget', null, null);
        $this->assertSame('Presupuesto', $sin_id->name);

        $pedido = PdfColumnService::get_profile_for_print($this->dueno->id, 'order', null, null);
        $this->assertSame('Pedido online', $pedido->name);
    }

    /**
     * 🔴 El id de un diseño de OTRO dueño no se respeta: cae al default del dueño del comprobante.
     *
     * @test
     */
    public function un_id_de_otro_dueno_cae_al_default_del_dueno_del_comprobante()
    {
        foreach ([['budget', 'Presupuesto sin precios', 'Presupuesto'], ['order', 'Pedido online', 'Pedido online']] as $caso) {
            list($model_name, $nombre_ajeno, $nombre_default) = $caso;

            $ajeno = $this->diseno_de($this->otro_dueno->id, $model_name, $nombre_ajeno);

            $elegido = PdfColumnService::get_profile_for_print($this->dueno->id, $model_name, $ajeno->id, null);

            $this->assertNotNull($elegido);
            $this->assertNotSame($ajeno->id, $elegido->id, 'Se devolvio el diseno de OTRO dueno.');
            $this->assertSame($this->dueno->id, (int) $elegido->user_id, 'El diseno elegido no es del dueno del comprobante.');
            $this->assertSame($nombre_default, $elegido->name);
        }
    }

    /**
     * @test
     */
    public function un_id_inexistente_cae_al_default()
    {
        $elegido = PdfColumnService::get_profile_for_print($this->dueno->id, 'budget', 987654321, null);

        $this->assertNotNull($elegido);
        $this->assertSame('Presupuesto', $elegido->name);
        $this->assertTrue((bool) $elegido->is_default);
    }

    /**
     * Un dueño que todavía no corrió el seeder (la ventana entre subir el código y sembrar) no
     * tiene ningún diseño: `null`, y el controlador imprime el PDF de siempre.
     *
     * @test
     */
    public function un_dueno_sin_disenos_devuelve_null_y_el_controlador_cae_al_pdf_de_siempre()
    {
        $sin_disenos = $this->crear_dueno('Dueno sin disenos '.uniqid());

        foreach (['budget', 'order'] as $model_name) {
            $this->assertNull(
                PdfColumnService::get_profile_for_print($sin_disenos->id, $model_name, 1, null),
                'Sin disenos de "'.$model_name.'" tiene que dar null (PDF de siempre).'
            );
        }

        /**
         * El "cae al PDF de siempre" es una decisión de los controladores (`if ($profile)`), y no se
         * puede ejecutar acá porque los dos PDF terminan con `exit`. Se fija que la rama exista y
         * que lo de siempre siga ahí, sin parámetro y sin diseño.
         */
        foreach ([['BudgetController', 'new BudgetPdf($budget, $with_prices, $with_images)'], ['OrderController', 'new OrderPdf($model)']] as $caso) {
            $codigo = file_get_contents(app_path('Http/Controllers/'.$caso[0].'.php'));

            $this->assertStringContainsString("filled('pdf_column_profile_id')", $codigo, $caso[0].' perdio la rama del diseno.');
            $this->assertStringContainsString('if ($profile)', $codigo, $caso[0].': sin diseno tiene que caer al PDF de siempre.');
            $this->assertStringContainsString($caso[1], $codigo, $caso[0].' perdio el PDF de siempre.');
        }
    }

    /**
     * 🔴 La regresión de "un perfil de otro modelo se cuela": pidiendo el id de un diseño de
     * presupuesto como si fuera de una venta o de un pedido, NUNCA sale el de presupuesto.
     *
     * @test
     */
    public function el_diseno_de_un_modelo_no_sale_como_diseno_de_otro()
    {
        $presupuesto = $this->diseno_de($this->dueno->id, 'budget', 'Presupuesto');

        $como_venta = PdfColumnService::get_profile_for_print($this->dueno->id, 'sale', $presupuesto->id, false);
        $this->assertTrue(
            is_null($como_venta) || ($como_venta->model_name === 'sale' && $como_venta->id !== $presupuesto->id),
            'Un diseno de presupuesto salio como diseno de venta.'
        );

        $como_pedido = PdfColumnService::get_profile_for_print($this->dueno->id, 'order', $presupuesto->id, null);
        $this->assertSame('order', $como_pedido->model_name, 'Un diseno de presupuesto salio como diseno de pedido.');

        /** Aun con la marca de default de tienda / WhatsApp: esos flags no existen para estos modelos. */
        $para_tienda = PdfColumnService::get_profile_for_print($this->dueno->id, 'sale', null, false, 'is_default_tienda');
        $this->assertTrue(is_null($para_tienda) || $para_tienda->model_name === 'sale');
    }

    /**
     * Las rutas siguen respondiendo 404 con el parámetro nuevo si el comprobante no existe: el
     * documento se busca ANTES de mirar el diseño. También prueba que la firma nueva de los dos
     * métodos (`Request $request` primero) sigue enrutando bien.
     *
     * @test
     */
    public function las_rutas_dan_404_si_el_comprobante_no_existe_aun_con_el_parametro_de_diseno()
    {
        $diseno_id = $this->diseno_de($this->dueno->id, 'budget', 'Presupuesto')->id;

        $this->get('budget/pdf/987654321/1/0?pdf_column_profile_id='.$diseno_id)->assertStatus(404);
        $this->get('budget/pdf/987654321/1/0')->assertStatus(404);

        $diseno_pedido_id = $this->diseno_de($this->dueno->id, 'order', 'Pedido online')->id;

        $this->get('order/pdf/987654321?pdf_column_profile_id='.$diseno_pedido_id)->assertStatus(404);
        $this->get('order/pdf/987654321')->assertStatus(404);
    }

    /**
     * El encabezado comercial sin la opción nueva sigue diciendo "Comprobante", que es lo que
     * imprime el remito: `header_comercial()` es un archivo compartido con las ventas y el único
     * cambio que se le hizo es aditivo.
     *
     * @test
     */
    public function el_encabezado_sin_titulo_propio_sigue_diciendo_comprobante()
    {
        $sin_opciones = $this->encabezado_comercial(null);
        $this->assertPdfContiene('(Comprobante)', $sin_opciones, 'Sin opciones, el encabezado del remito tiene que decir Comprobante.');

        $con_titulo = $this->encabezado_comercial(['right_title' => 'Presupuesto']);
        $this->assertPdfContiene('(Presupuesto)', $con_titulo);
        $this->assertPdfNoContiene('(Comprobante)', $con_titulo, 'Con titulo propio no puede quedar el de siempre.');

        /** Un titulo vacio vuelve al de siempre en vez de dejar el encabezado sin titulo. */
        $vacio = $this->encabezado_comercial(['right_title' => '']);
        $this->assertPdfContiene('(Comprobante)', $vacio);
    }

    /**
     * Un diseño que anda: `try_render()` devuelve el PDF ya dibujado, listo para `emit()`.
     *
     * @test
     */
    public function try_render_devuelve_el_pdf_ya_dibujado_si_el_diseno_anda()
    {
        $articulo = $this->crear_articulo('Taladro percutor try_render');
        $budget = $this->crear_presupuesto([['article' => $articulo, 'amount' => 1, 'price' => 100]]);
        $diseno = $this->diseno_de($this->dueno->id, 'budget', 'Presupuesto');

        $pdf = ProfileDocumentPdf::try_render(new BudgetPdfDocument($budget), $diseno);

        $this->assertInstanceOf(ProfileDocumentPdf::class, $pdf);
        $pdf->SetCompression(false);
        $binario = $pdf->Output('S');
        $this->assertSame(0, strpos($binario, '%PDF'));
        $this->assertPdfContiene('(Taladro percutor try_render)', $binario);
    }

    /**
     * 🔴 Un diseño que se rompe con un dato raro NO puede ser un 500 para el cliente final (el link
     * de WhatsApp del presupuesto lo abre él): devuelve null para que el controlador caiga al PDF
     * de siempre. Y el error NO se traga: se reporta (log / registro de errores).
     *
     * @test
     */
    public function try_render_devuelve_null_y_reporta_si_el_diseno_falla()
    {
        $articulo = $this->crear_articulo('Taladro percutor try_render roto');
        $budget = $this->crear_presupuesto([['article' => $articulo, 'amount' => 1, 'price' => 100]]);
        $diseno = $this->diseno_de($this->dueno->id, 'budget', 'Presupuesto');

        $roto = new class($budget) extends BudgetPdfDocument {
            public function items()
            {
                throw new \RuntimeException('dato raro que rompe el diseño');
            }
        };

        $handler = \Mockery::mock(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with(\Mockery::type(\RuntimeException::class));
        $this->app->instance(\Illuminate\Contracts\Debug\ExceptionHandler::class, $handler);

        $this->assertNull(
            ProfileDocumentPdf::try_render($roto, $diseno),
            'Un diseño que falla tiene que devolver null, no propagar la excepción.'
        );
    }

    /**
     * Los dos controladores usan `try_render()` y ya no instancian el diseño a pelo (el PDF no se
     * puede ejercitar por HTTP: termina en `exit`, así que el cableado se fija leyendo el código).
     *
     * @test
     */
    public function los_controladores_caen_al_pdf_de_siempre_si_el_diseno_falla()
    {
        foreach (['BudgetController', 'OrderController'] as $controlador) {
            $codigo = file_get_contents(app_path('Http/Controllers/'.$controlador.'.php'));

            $this->assertStringContainsString('ProfileDocumentPdf::try_render(', $codigo, $controlador.' no usa try_render().');
            $this->assertStringNotContainsString('new ProfileDocumentPdf(', $codigo, $controlador.' instancia el diseño sin red de seguridad.');
            $this->assertStringContainsString('emit()', $codigo);
        }
    }

    /**
     * 🔴 La caída al `OrderPdf` de siempre ocurre en el MISMO pedido en que `ProfileDocumentPdf` ya
     * cargó fpdf.php. Con el `require` pelado que tenía `OrderPdf`, PHP moría con 'Cannot declare
     * class FPDF', un fatal que ningún try/catch atrapa: el fallback era una trampa. Se prueba en un
     * PROCESO APARTE porque, si fallara, mataría al propio PHPUnit.
     *
     * @test
     */
    public function cargar_el_diseno_y_despues_el_pdf_de_siempre_del_pedido_no_da_fatal()
    {
        /**
         * Nowdoc: las barras invertidas de los nombres de clase quedan literales, sin escapes.
         */
        $codigo = <<<'PHP'
<?php
require __AUTOLOAD__;
class_exists('App\Http\Controllers\Pdf\ProfileDocumentPdf');
class_exists('App\Http\Controllers\Pdf\OrderPdf');
class_exists('App\Http\Controllers\Pdf\BudgetPdf');
echo 'CARGA_OK';
PHP;
        $codigo = str_replace('__AUTOLOAD__', var_export(base_path('vendor/autoload.php'), true), $codigo);

        $archivo = tempnam(sys_get_temp_dir(), 'pdfcarga');
        file_put_contents($archivo, $codigo);

        $salida = (string) shell_exec('"'.PHP_BINARY.'" "'.$archivo.'" 2>&1');
        @unlink($archivo);

        $this->assertStringNotContainsString('Cannot declare class', $salida, 'FPDF se cargo dos veces: '.$salida);
        $this->assertStringContainsString('CARGA_OK', $salida, 'El proceso no llego al final: '.$salida);
    }

    /**
     * Dibuja SOLO el encabezado comercial sobre un FPDF pelado, como lo hace `NewSalePdf` para el
     * remito.
     *
     * @param array|null $options Cuarto argumento nuevo (null = no se pasa, como el remito).
     * @return string
     */
    protected function encabezado_comercial($options)
    {
        require_once app_path('Http/Controllers/CommonLaravel/fpdf/fpdf.php');

        $pdf = new class extends \fpdf {
            public $use_current_date = false;
            public $logo_size_mm = 35;
        };
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetCompression(false);

        $documento = new \stdClass();
        $documento->num = 77;
        $documento->created_at = now();
        $documento->address = null;
        $documento->client = null;
        $documento->seller = null;
        $documento->employee = null;

        $layout = PdfColumnProfile::default_header_layout(false);

        if (is_null($options)) {
            AfipPdfHelper::header_comercial($pdf, $documento, $this->dueno, $layout, null);
        } else {
            AfipPdfHelper::header_comercial($pdf, $documento, $this->dueno, $layout, null, $options);
        }

        return $pdf->Output('S');
    }
}
