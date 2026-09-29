<?php

namespace Tests\Feature\EtiquetasDeGondola;

use App\Models\ArticleTicketDesign;
use App\Models\PriceType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Andamio común de los tests de "Diseños de etiquetas de góndola" (misión
 * disenos-etiquetas-gondola, 29/9/2026).
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing del slot está sembrada de antes y
 * un refresh la vaciaría. Todo lo que se crea acá (dueños, empleados, listas, artículos, diseños)
 * se revierte. Los dueños se crean de cero en vez de usar el 500, así cada test controla si trabaja
 * con listas de precios y cuántas tiene.
 */
abstract class EtiquetasDeGondolaTestCase extends TestCase
{
    use DatabaseTransactions;

    /**
     * Archivos creados por el test (se borran al terminar).
     *
     * @var array
     */
    protected $archivos_temporales = array();

    protected function setUp(): void
    {
        parent::setUp();

        /* El alta de una lista de precios encola el recálculo masivo: acá no tiene que correr. */
        Queue::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->archivos_temporales as $archivo) {
            if (is_file($archivo)) {
                @unlink($archivo);
            }
        }

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
            'name'             => 'zz Dueño etiquetas',
            'company_name'     => 'zz Comercio etiquetas',
            'email'            => 'etiquetas-'.uniqid('', true).'@test.local',
            'password'         => bcrypt('zz-password-testing'),
            'status'           => 'commerce',
            'listas_de_precio' => $con_listas ? 1 : 0,
        ));
    }

    /**
     * Un empleado del dueño.
     *
     * @param  \App\Models\User  $dueno
     * @return \App\Models\User
     */
    protected function crear_empleado($dueno)
    {
        return User::create(array(
            'owner_id' => $dueno->id,
            'name'     => 'zz Empleado etiquetas',
            'email'    => 'etiquetas-empleado-'.uniqid('', true).'@test.local',
            'password' => bcrypt('zz-password-testing'),
            'status'   => 'commerce',
        ));
    }

    /**
     * @param  \App\Models\User  $dueno
     * @param  string            $nombre
     * @param  int               $posicion
     * @return \App\Models\PriceType
     */
    protected function crear_lista($dueno, $nombre, $posicion)
    {
        return PriceType::create(array(
            'num'        => $posicion,
            'name'       => $nombre,
            'percentage' => 10,
            'position'   => $posicion,
            'user_id'    => $dueno->id,
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
            'name'        => 'zz Yerba Mate Playadito 1 kg',
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
     * Un diseño directo en la base.
     *
     * @param  \App\Models\User  $dueno
     * @param  string            $nombre
     * @param  array|null        $diseno
     * @param  int               $posicion
     * @return \App\Models\ArticleTicketDesign
     */
    protected function crear_diseno($dueno, $nombre, $diseno = null, $posicion = 0)
    {
        return ArticleTicketDesign::create(array(
            'user_id'       => $dueno->id,
            'name'          => $nombre,
            'price_type_id' => null,
            'position'      => $posicion,
            'diseno'        => $diseno,
        ));
    }

    /**
     * Un campo de diseño con lo mínimo.
     *
     * @param  string  $tipo
     * @param  array   $extra
     * @return array
     */
    protected function elemento($tipo, array $extra = array())
    {
        return array_merge(array(
            'id'              => 'c_'.$tipo,
            'tipo'            => $tipo,
            'x'               => 0,
            'y'               => 0,
            'w'               => 20,
            'h'               => 5,
            'tamano'          => 9,
            'negrita'         => false,
            'saltos_de_linea' => false,
            'alineacion'      => 'L',
        ), $extra);
    }
}
