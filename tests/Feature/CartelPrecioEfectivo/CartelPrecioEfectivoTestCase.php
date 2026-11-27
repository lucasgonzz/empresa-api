<?php

namespace Tests\Feature\CartelPrecioEfectivo;

use App\Http\Controllers\Pdf\ArticleOfferSheetPdf;
use App\Models\ArticlePdf;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\CurrentAcountPaymentMethodDiscount;
use App\Models\Iva;
use App\Models\PriceType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Andamio común de los tests del "cartel de ofertas con precio en efectivo o transferencia"
 * (misión soporte-t173-m44, ticket 173 de La Martina).
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing está sembrada de antes. Todo lo
 * que se crea acá (dueños, artículos, plantillas, métodos de pago y reglas) se revierte solo.
 */
abstract class CartelPrecioEfectivoTestCase extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        /* El alta de una lista de precios encola el recálculo masivo: acá no tiene que correr. */
        Queue::fake();
    }

    protected function tearDown(): void
    {
        /* El PDF de listas lee `price_type_id` del request: no se lo deja pegado al próximo test. */
        request()->query->remove('price_type_id');

        parent::tearDown();
    }

    /**
     * Un dueño nuevo.
     *
     * @param  bool  $con_listas  `listas_de_precio` prendido.
     * @return \App\Models\User
     */
    protected function crear_dueno($con_listas = false)
    {
        return User::create(array(
            'name'             => 'zz Dueño cartel efectivo',
            'company_name'     => 'zz Comercio cartel efectivo',
            'email'            => 'cartel-efectivo-'.uniqid('', true).'@test.local',
            'password'         => bcrypt('zz-password-testing'),
            'status'           => 'commerce',
            'listas_de_precio' => $con_listas ? 1 : 0,
        ));
    }

    /**
     * Un artículo insertado directo (sin el observer, que encola embeddings y sincronizaciones).
     * Por defecto: $2.499 final, IVA 21 %, precio anterior $2.399 y código de barras.
     *
     * @param  \App\Models\User  $dueno
     * @param  array             $atributos
     * @return int  El id.
     */
    protected function crear_articulo($dueno, array $atributos = array())
    {
        $iva = Iva::create(array('percentage' => 21));

        return DB::table('articles')->insertGetId(array_merge(array(
            'name'                => 'zz Yerba Mate Playadito 1 kg',
            'user_id'             => $dueno->id,
            'status'              => 'active',
            'bar_code'            => '7790086438158',
            'final_price'         => 2499.00,
            'previus_final_price' => 2399.00,
            'iva_id'              => $iva->id,
            'stock'               => 12,
            'created_at'          => Carbon::now(),
            'updated_at'          => Carbon::now(),
        ), $atributos));
    }

    /**
     * Una plantilla de PDF de ofertas. Por defecto: la del ticket (título, texto personalizado,
     * fecha y precio anterior prendidos) con el flag nuevo APAGADO.
     *
     * @param  \App\Models\User  $dueno
     * @param  array             $atributos
     * @return \App\Models\ArticlePdf
     */
    protected function crear_plantilla($dueno, array $atributos = array())
    {
        return ArticlePdf::create(array_merge(array(
            'user_id'                 => $dueno->id,
            'nombre'                  => 'zz Cartel',
            'titulo'                  => 'SUPER OFERTA',
            'texto_personalizado'     => 'Oferta hasta agotar stock',
            'motrar_fecha_impresion'  => 1,
            'mostrar_precio_anterior' => 1,
        ), $atributos));
    }

    /**
     * Un método de pago del catálogo (global, sin dueño).
     *
     * @param  string  $nombre
     * @return \App\Models\CurrentAcountPaymentMethod
     */
    protected function crear_metodo($nombre)
    {
        return CurrentAcountPaymentMethod::create(array('name' => $nombre));
    }

    /**
     * Una regla de descuento (positivo) o recargo (negativo) de un método para un dueño.
     *
     * @param  \App\Models\User                         $dueno
     * @param  \App\Models\CurrentAcountPaymentMethod   $metodo
     * @param  float                                    $porcentaje
     * @param  int|null                                 $cuotas
     * @param  string|null                              $created_at
     * @return \App\Models\CurrentAcountPaymentMethodDiscount
     */
    protected function crear_regla($dueno, $metodo, $porcentaje, $cuotas = null, $created_at = null)
    {
        $regla = CurrentAcountPaymentMethodDiscount::create(array(
            'current_acount_payment_method_id' => $metodo->id,
            'discount_percentage'              => $porcentaje,
            'cuotas'                           => $cuotas,
            'user_id'                          => $dueno->id,
        ));

        if (!is_null($created_at)) {
            DB::table('current_acount_payment_method_discounts')
                ->where('id', $regla->id)
                ->update(array('created_at' => $created_at));
        }

        return $regla;
    }

    /**
     * Una lista de precios del dueño.
     *
     * @param  \App\Models\User  $dueno
     * @return \App\Models\PriceType
     */
    protected function crear_lista($dueno)
    {
        return PriceType::create(array(
            'num'        => 1,
            'name'       => 'zz Mayorista',
            'percentage' => 10,
            'position'   => 1,
            'user_id'    => $dueno->id,
        ));
    }

    /**
     * El PDF de ofertas sin comprimir, generado directo con la clase (con `exit` apagado).
     *
     * @param  \App\Models\ArticlePdf  $plantilla
     * @param  array                   $ids
     * @param  \App\Models\User        $dueno
     * @return string
     */
    protected function pdf_plano($plantilla, array $ids, $dueno)
    {
        $this->actingAs($dueno, 'web');

        $pdf = new ArticleOfferSheetPdf($plantilla, implode('-', $ids), false);
        $pdf->SetCompression(false);

        return $pdf->Output('S');
    }

    /**
     * @param  string  $pdf
     * @return int
     */
    protected function paginas($pdf)
    {
        return preg_match_all('#/Type /Page[^s]#', $pdf);
    }
}
