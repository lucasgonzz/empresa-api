<?php

namespace Tests\Feature\EtiquetasIndividuales;

use App\Http\Controllers\Pdf\ArticleTicket\ArticleBarCodeEtiquetasPdf;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Andamio común de los tests de "Etiquetas individuales" (misión etiquetas-individuales-sin-partir,
 * 4/10/2026): el PDF de `GET article/bar-codes-etiquetas-pdf/{ids}` (`ArticleBarCodeEtiquetasPdf`) y
 * la disposición que dibuja (`DisposicionDeEtiquetaIndividual`).
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing del slot está sembrada de antes y
 * un refresh la vaciaría. El dueño y los artículos se crean de cero y se revierten.
 */
abstract class EtiquetasIndividualesTestCase extends TestCase
{
    use DatabaseTransactions;

    /**
     * Los cuatro artículos del pedido de Lucas, con los nombres reales de demo2
     * (`FerreteriaArticlesSeeder`): `nombre => código de barras`.
     *
     * @var array<string, string>
     */
    const ESPATULAS = array(
        'ESPATULA 80mm REMACHADO M/MADERA BIASSONI'          => '7798312201729',
        'ESPATULA 70mm REMACHADO M/MADERA BIASSONI'          => '7798312201712',
        'ESPATULA PARA JUNTAS 150MM CONST. EN SECO BIASSONI' => '7798312201927',
        'ESPATULA ENDUIR 140MM BIASSONI'                     => '7798172005758',
    );

    /**
     * Un precio distinto para cada espátula (en el mismo orden), y cómo sale impreso.
     *
     * @var array<int, array{0: float, 1: string}>
     */
    const PRECIOS = array(
        array(12345.67, '$12.345,67'),
        array(9876.50,  '$9.876,50'),
        array(23456.00, '$23.456'),
        array(15432.10, '$15.432,10'),
    );

    protected function setUp(): void
    {
        parent::setUp();

        /* Nada de lo que se crea acá tiene que encolar trabajos. */
        Queue::fake();
    }

    protected function tearDown(): void
    {
        /*
         * El PDF escribe el PNG del código de barras en el directorio actual
         * (`temp_barcode<código>_<sufijo>.png`) y lo borra apenas lo dibuja. Si un test se corta a
         * la mitad, que no quede basura.
         */
        foreach (self::ESPATULAS as $codigo) {
            foreach ((array) glob(getcwd().DIRECTORY_SEPARATOR.'temp_barcode'.$codigo.'_*.png') as $archivo) {
                if (is_file($archivo)) {
                    @unlink($archivo);
                }
            }
        }

        parent::tearDown();
    }

    /**
     * Un dueño nuevo.
     *
     * @return \App\Models\User
     */
    protected function crear_dueno()
    {
        return User::create(array(
            'name'         => 'zz Dueño etiquetas individuales',
            'company_name' => 'zz Ferretería etiquetas',
            'email'        => 'etiquetas-individuales-'.uniqid('', true).'@test.local',
            'password'     => bcrypt('zz-password-testing'),
            'status'       => 'commerce',
        ));
    }

    /**
     * Un artículo insertado directo (sin el observer, que encola embeddings y sincronizaciones).
     *
     * @param  \App\Models\User  $dueno
     * @param  array             $atributos
     * @return int  El id.
     */
    protected function crear_articulo($dueno, array $atributos = array())
    {
        return DB::table('articles')->insertGetId(array_merge(array(
            'name'        => 'zz Artículo etiqueta',
            'user_id'     => $dueno->id,
            'status'      => 'active',
            'bar_code'    => '7790387000144',
            'final_price' => 1234.00,
            'stock'       => 12,
            'created_at'  => Carbon::now(),
            'updated_at'  => Carbon::now(),
        ), $atributos));
    }

    /**
     * Las cuatro espátulas del pedido, cada una con su código de barras y su precio.
     *
     * @param  \App\Models\User  $dueno
     * @return int[]  Los ids, en el orden de ESPATULAS.
     */
    protected function crear_espatulas($dueno)
    {
        $ids = array();
        $indice = 0;

        foreach (self::ESPATULAS as $nombre => $codigo) {
            $ids[] = $this->crear_articulo($dueno, array(
                'name'        => $nombre,
                'bar_code'    => $codigo,
                'final_price' => self::PRECIOS[$indice][0],
            ));

            $indice++;
        }

        return $ids;
    }

    /**
     * Propiedades como las manda el modal: nombre, código de barras y precio (en ese orden, el
     * que queda al tildar el precio sobre la config por defecto).
     *
     * @param  int  $nombre_pt
     * @param  int  $precio_pt
     * @return array
     */
    protected function nombre_codigo_precio($nombre_pt, $precio_pt)
    {
        return array(
            array('key' => 'nombre', 'font_size' => $nombre_pt, 'negrita' => false),
            array('key' => 'codigo_barras', 'font_size' => 11, 'negrita' => false),
            array('key' => 'precio', 'font_size' => $precio_pt, 'negrita' => false),
        );
    }

    /**
     * El PDF sin comprimir, generado directo con la clase (sin `exit`), para buscar los textos en
     * el binario. Hay que estar logueado: la clase toma el dueño de `UserHelper::user()`.
     *
     * @param  int[]       $ids
     * @param  int         $ancho
     * @param  int         $alto
     * @param  array|null  $propiedades
     * @param  int|null    $codigo_alto
     * @param  int|null    $interlineado
     * @return string
     */
    protected function pdf_plano(array $ids, $ancho, $alto, $propiedades = null, $codigo_alto = null, $interlineado = null)
    {
        $pdf = new ArticleBarCodeEtiquetasPdf(implode('-', $ids), $ancho, $alto, $propiedades, $codigo_alto, $interlineado, false);
        $pdf->SetCompression(false);

        return $pdf->generar();
    }

    /**
     * Cantidad de páginas del PDF.
     *
     * @param  string  $pdf
     * @return int
     */
    protected function paginas($pdf)
    {
        return preg_match_all('#/Type /Page[^s]#', $pdf);
    }

    /**
     * El PDF partido por página: FPDF escribe cada objeto `/Type /Page` seguido de su contenido,
     * así que el tramo `i` (desde 1) es el contenido de la página `i`.
     *
     * @param  string  $pdf
     * @return string[]  Indexado desde 1.
     */
    protected function contenido_por_pagina($pdf)
    {
        $tramos = preg_split('#/Type /Page[^s]#', $pdf);

        /* El tramo 0 es lo de antes de la primera página. */
        unset($tramos[0]);

        return $tramos;
    }
}
