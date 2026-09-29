<?php

namespace Tests\Concerns;

use App\Http\Controllers\Helpers\PdfDocumentSetupHelper;
use App\Http\Controllers\Pdf\ProfileDocumentPdf;
use App\Models\AfipInformation;
use App\Models\Article;
use App\Models\Budget;
use App\Models\Client;
use App\Models\Image;
use App\Models\IvaCondition;
use App\Models\PdfColumnProfile;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;

/**
 * Fixture compartido de las suites de PDF de presupuesto y pedido online (misión
 * pdf-presupuestos-y-pedidos-personalizables, 29/9/2026).
 *
 * Arma un dueño PROPIO con sus diseños por defecto (sembrados con el mismo helper de producción,
 * `PdfDocumentSetupHelper`), presupuestos con renglones y JPG reales generados con GD, y renderiza
 * un `ProfileDocumentPdf` SIN emitirlo: `render()` + `Output('S')` con la compresión apagada, para
 * poder buscar el texto dentro del binario del PDF.
 *
 * Todo corre dentro de la transacción de `EmpresaTestCase`. Lo único que queda en disco son los
 * JPG de prueba, y los borra `borrar_archivos_de_prueba()` (cada clase lo llama desde su
 * tearDown()).
 *
 * Requiere `Tests\EmpresaTestCase`.
 */
trait DocumentosParaPdf
{
    /**
     * Dueño del comercio de prueba.
     *
     * @var \App\Models\User|null
     */
    protected $dueno = null;

    /**
     * Archivos que creó el test, para borrarlos en tearDown().
     *
     * @var array<int, string>
     */
    protected $archivos_de_prueba = [];

    /**
     * Crea el dueño (con sus datos fiscales, para que el encabezado tenga qué dibujar) y, si se
     * pide, le siembra los diseños por defecto.
     *
     * `Queue::fake()` porque crear artículos dispara el observer de embeddings: en una base de
     * testing eso no puede llegar a salir a ninguna API paga.
     *
     * @param bool $con_disenos
     * @return \App\Models\User
     */
    protected function preparar_dueno_para_pdf($con_disenos = true)
    {
        Queue::fake();

        $this->dueno = $this->crear_dueno('Dueno PDF '.uniqid());

        $condicion = IvaCondition::where('name', 'Responsable inscripto')->first();

        AfipInformation::create([
            'user_id'             => $this->dueno->id,
            'punto_venta'         => 1,
            'iva_condition_id'    => $condicion ? $condicion->id : null,
            'razon_social'        => 'Razon Social PDF SA',
            'domicilio_comercial' => 'Calle Falsa 123',
            'cuit'                => '30111111118',
            'ingresos_brutos'     => '30111111118',
            'inicio_actividades'  => '2020-01-01',
        ]);

        if ($con_disenos) {
            PdfDocumentSetupHelper::apply_for_owner($this->dueno->id);
        }

        return $this->dueno;
    }

    /**
     * Crea un dueño (owner_id null).
     *
     * @param string $empresa Nombre comercial.
     * @return \App\Models\User
     */
    protected function crear_dueno($empresa)
    {
        return User::create([
            'name'         => $empresa,
            'company_name' => $empresa,
            'email'        => 'pdf-'.uniqid().'@test.local',
            'password'     => Hash::make('secret'),
        ]);
    }

    /**
     * Diseño del dueño por modelo y nombre.
     *
     * @param string $model_name budget|order
     * @param string $nombre
     * @return \App\Models\PdfColumnProfile
     */
    protected function diseno($model_name, $nombre)
    {
        return PdfColumnProfile::where('user_id', $this->dueno->id)
            ->where('model_name', $model_name)
            ->where('name', $nombre)
            ->firstOrFail();
    }

    /**
     * Artículo del dueño.
     *
     * @param string $nombre
     * @param array  $extra Atributos extra (bar_code, provider_code, etc).
     * @return \App\Models\Article
     */
    protected function crear_articulo($nombre, array $extra = [])
    {
        return Article::create(array_merge([
            'name'    => $nombre,
            'user_id' => $this->dueno->id,
        ], $extra));
    }

    /**
     * Cliente del dueño, con observaciones (para el bloque de "Observaciones" del encabezado).
     *
     * @return \App\Models\Client
     */
    protected function crear_cliente()
    {
        return Client::create([
            'name'        => 'Cliente Presupuesto Test',
            'phone'       => '2215550000',
            'address'     => 'Diagonal 74 N 1234',
            'cuit'        => '20222222229',
            'description' => 'Paga a 30 dias',
            'user_id'     => $this->dueno->id,
        ]);
    }

    /**
     * Presupuesto del dueño con sus renglones de artículos.
     *
     * @param array $renglones Cada uno: ['article' => Article, 'amount' => n, 'price' => n, 'bonus' => n|null].
     * @param array $atributos Atributos del presupuesto que pisan los de por defecto.
     * @return \App\Models\Budget
     */
    protected function crear_presupuesto(array $renglones, array $atributos = [])
    {
        $budget = Budget::create(array_merge([
            'num'                              => 990000 + rand(1, 9000),
            'user_id'                          => $this->dueno->id,
            'client_id'                        => $this->crear_cliente()->id,
            'budget_status_id'                 => 1,
            'total'                            => 0,
            'discount_stock'                   => 0,
            'discounts_in_services'            => 1,
            'surchages_in_services'            => 1,
            'aplicar_recargos_directo_a_items' => 0,
            'moneda_id'                        => 1,
        ], $atributos));

        foreach ($renglones as $renglon) {
            $budget->articles()->attach($renglon['article']->id, [
                'amount' => $renglon['amount'],
                'price'  => $renglon['price'],
                'bonus'  => isset($renglon['bonus']) ? $renglon['bonus'] : null,
            ]);
        }

        return $budget->fresh();
    }

    /**
     * Renderiza el PDF de un comprobante con un diseño, SIN emitirlo, y devuelve el binario con la
     * compresión apagada (el texto queda legible dentro del stream).
     *
     * @param \App\Http\Controllers\Helpers\PdfDocument\PdfDocumentSource $source
     * @param \App\Models\PdfColumnProfile                                $perfil
     * @return string
     */
    protected function renderizar($source, PdfColumnProfile $perfil)
    {
        $pdf = new ProfileDocumentPdf($source, $perfil);
        $pdf->SetCompression(false);
        $pdf->render();

        return $pdf->Output('S');
    }

    /**
     * Cantidad de hojas de un PDF de FPDF (cada hoja es un objeto `/Type /Page`; el `\b` deja
     * afuera al `/Type /Pages` raíz).
     *
     * @param string $pdf
     * @return int
     */
    protected function cantidad_de_hojas($pdf)
    {
        return preg_match_all('~/Type /Page\b~', $pdf);
    }

    /**
     * El contenido de cada hoja por separado, en orden. Sirve para afirmar QUÉ hoja tiene qué: en
     * FPDF el objeto de la hoja viene seguido de su stream de contenido.
     *
     * @param string $pdf
     * @return array<int, string>
     */
    protected function hojas($pdf)
    {
        $partes = preg_split('~/Type /Page\b~', $pdf);

        /** La primera parte es lo que hay antes de la primera hoja. */
        array_shift($partes);

        return $partes;
    }

    /**
     * Afirma que un texto aparece en el PDF SIN volcar el binario entero en el mensaje de fallo
     * (assertStringContainsString imprime el string completo: un PDF de varias hojas son cientos
     * de KB de hexadecimal que tapan la causa).
     *
     * @param string $needle
     * @param string $pdf
     * @param string $message
     * @return void
     */
    protected function assertPdfContiene($needle, $pdf, $message = '')
    {
        $this->assertTrue(strpos($pdf, $needle) !== false, trim($message.' No aparece en el PDF: '.$needle));
    }

    /**
     * Lo contrario de assertPdfContiene().
     *
     * @param string $needle
     * @param string $pdf
     * @param string $message
     * @return void
     */
    protected function assertPdfNoContiene($needle, $pdf, $message = '')
    {
        $this->assertTrue(strpos($pdf, $needle) === false, trim($message.' Aparece en el PDF: '.$needle));
    }

    /**
     * Los textos que el PDF dibuja: lo que va entre parentesis antes de cada `Tj` de los streams de
     * contenido. Buscar un patron en el binario ENTERO no sirve para "no aparece ningun importe": la
     * fuente embebida del encabezado son bytes al azar y tarde o temprano forman un `$1`.
     *
     * @param string $pdf
     * @return array<int, string>
     */
    protected function textos_del_pdf($pdf)
    {
        preg_match_all('~\(((?:[^()\\\\]|\\\\.)*)\) Tj~s', $pdf, $coincidencias);

        return $coincidencias[1];
    }

    /**
     * Cantidad de textos del PDF que traen un importe (un `$` seguido de un digito).
     *
     * @param string $pdf
     * @return array<int, string> Los textos con importe.
     */
    protected function textos_con_importe($pdf)
    {
        return array_values(array_filter($this->textos_del_pdf($pdf), function ($texto) {
            return preg_match('~\\$\\d~', $texto) === 1;
        }));
    }

    /**
     * Afirma que el PDF NO dibuja ningun importe. Si dibuja, el mensaje trae los primeros.
     *
     * @param string $pdf
     * @param string $message
     * @return void
     */
    protected function assertPdfSinImportes($pdf, $message = '')
    {
        $con_importe = $this->textos_con_importe($pdf);

        $this->assertSame([], $con_importe, trim($message.' Importes: '.implode(' | ', array_slice($con_importe, 0, 5))));
    }

    /**
     * Texto en el encoding con el que FPDF lo escribe en el stream (Cell() hace utf8_decode).
     *
     * @param string $texto UTF-8.
     * @return string
     */
    protected function en_el_pdf($texto)
    {
        return utf8_decode($texto);
    }

    /**
     * Crea un JPG real bajo storage/app/public/ y le cuelga una imagen al artículo, con la misma
     * forma de URL que producción (`.../storage/<archivo>`).
     *
     * @param \App\Models\Article $articulo
     * @param int                 $ancho
     * @param int                 $alto
     * @return string Ruta del archivo.
     */
    protected function colgar_imagen_jpg(Article $articulo, $ancho = 60, $alto = 40)
    {
        $nombre = 'test_pdfdoc_'.uniqid().'.jpg';
        $ruta = storage_path('app/public/'.$nombre);

        $imagen = imagecreatetruecolor($ancho, $alto);
        imagefill($imagen, 0, 0, imagecolorallocate($imagen, 200, 60, 60));
        imagejpeg($imagen, $ruta, 90);
        imagedestroy($imagen);

        $this->archivos_de_prueba[] = $ruta;

        Image::create([
            'hosting_url'    => 'https://api-cliente.comerciocity.com/storage/'.$nombre,
            'imageable_id'   => $articulo->id,
            'imageable_type' => 'article',
        ]);

        return $ruta;
    }

    /**
     * Cuelga una imagen ROTA: un archivo .jpg local con basura adentro. La ruta existe (así que
     * pasa el `is_file`) pero no es una imagen que se pueda leer.
     *
     * @param \App\Models\Article $articulo
     * @return string Ruta del archivo.
     */
    protected function colgar_imagen_rota(Article $articulo)
    {
        $nombre = 'test_pdfdoc_roto_'.uniqid().'.jpg';
        $ruta = storage_path('app/public/'.$nombre);

        file_put_contents($ruta, 'esto no es un jpg');
        $this->archivos_de_prueba[] = $ruta;

        Image::create([
            'hosting_url'    => 'https://api-cliente.comerciocity.com/storage/'.$nombre,
            'imageable_id'   => $articulo->id,
            'imageable_type' => 'article',
        ]);

        return $ruta;
    }

    /**
     * Borra los archivos que creó el test.
     *
     * @return void
     */
    protected function borrar_archivos_de_prueba()
    {
        foreach ($this->archivos_de_prueba as $archivo) {
            if (is_file($archivo)) {
                @unlink($archivo);
            }
        }

        $this->archivos_de_prueba = [];
    }
}
