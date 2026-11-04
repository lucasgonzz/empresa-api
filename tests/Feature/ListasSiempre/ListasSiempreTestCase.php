<?php

namespace Tests\Feature\ListasSiempre;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\PriceType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Base de los tests de la misión prueba-listas-siempre (3/11/2026, M1 del programa «Prueba gratuita
 * de 7 días»): todo negocio trabaja con listas de precios, y el que no carga listas propias tiene UNA,
 * «Precio final», que es el precio del artículo de siempre espejado en una lista.
 *
 * Cada test usa dueños PROPIOS (el 500 del fixture tiene cuatro listas con el flag apagado, un escenario
 * que no es el de estos tests): un dueño nace sin listas y con el flag apagado, como un cliente de hoy, y el
 * test lo pasa a «siempre con listas» con con_precio_final(). Las fotos se leen de la base, nunca del
 * modelo en memoria.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
abstract class ListasSiempreTestCase extends TestCase
{
    use DatabaseTransactions;

    /** Tolerancia de centavos: la columna guarda 2 decimales. */
    const DELTA = 0.005;

    protected function setUp(): void
    {
        parent::setUp();

        // El memo de la lista «Precio final» es por proceso: entre test y test, limpio.
        PriceTypeHelper::olvidar_precio_final();
    }

    protected function tearDown(): void
    {
        PriceTypeHelper::olvidar_precio_final();

        parent::tearDown();
    }

    /**
     * Un dueño propio, sin listas y con el flag apagado (un cliente de hoy). Dólar 1000 y cotiza los
     * costos en dólares a pesos, salvo que el test diga otra cosa.
     *
     * @param  string  $etiqueta
     * @param  array   $atributos
     * @return \App\Models\User
     */
    protected function dueno_nuevo($etiqueta = 'dueno', array $atributos = array())
    {
        $dueno = User::create(array_merge(array(
            'name'                       => 'zz Dueño listas-siempre '.$etiqueta,
            'company_name'               => 'zz Comercio listas-siempre '.$etiqueta,
            'email'                      => 'zz-listas-siempre-'.$etiqueta.'-'.uniqid().'@test.local',
            'password'                   => Hash::make(uniqid('', true)),
            'status'                     => 'commerce',
            'listas_de_precio'           => 0,
            'dollar'                     => 1000,
            'cotizar_precios_en_dolares' => 1,
        ), $atributos));

        return User::find($dueno->id);
    }

    /**
     * Pasa al dueño a «siempre con listas»: la lista «Precio final» y el flag prendido.
     *
     * @param  \App\Models\User  $dueno
     * @return array  [User releído, PriceType]
     */
    protected function con_precio_final($dueno)
    {
        $lista = PriceTypeHelper::crear_lista_precio_final($dueno->id);

        DB::table('users')->where('id', $dueno->id)->update(array('listas_de_precio' => 1));

        PriceTypeHelper::olvidar_precio_final($dueno->id);

        return array(User::find($dueno->id), $lista);
    }

    /**
     * Una lista común más del dueño.
     *
     * @param  \App\Models\User  $dueno
     * @param  string            $nombre
     * @param  float|null        $percentage
     * @param  int               $position
     * @return \App\Models\PriceType
     */
    protected function otra_lista($dueno, $nombre = 'zz Mayorista', $percentage = 100, $position = 1)
    {
        return PriceType::create(array(
            'name'       => $nombre,
            'percentage' => $percentage,
            'position'   => $position,
            'user_id'    => $dueno->id,
        ));
    }

    /**
     * Le da al dueño una extensión (la crea en el padrón si la base de testing no la tiene).
     *
     * @param  \App\Models\User  $dueno
     * @param  string            $slug
     * @return \App\Models\User  Releído, con las extensiones frescas.
     */
    protected function con_extension($dueno, $slug)
    {
        $extension = ExtencionEmpresa::where('slug', $slug)->first();

        if (is_null($extension)) {
            $extension = ExtencionEmpresa::forceCreate(array('name' => $slug, 'slug' => $slug));
        }

        $dueno->extencions()->syncWithoutDetaching(array($extension->id));

        return User::find($dueno->id);
    }

    /**
     * Un artículo del dueño, releído (así trae los defaults de la base: iva_id, aplicar_iva).
     *
     * @param  \App\Models\User  $dueno
     * @param  string            $nombre
     * @param  array             $atributos
     * @return \App\Models\Article
     */
    protected function articulo($dueno, $nombre, array $atributos = array())
    {
        $articulo = Article::create(array_merge(array(
            'name'    => 'zz '.$nombre.' '.uniqid(),
            'user_id' => $dueno->id,
            'status'  => 'active',
        ), $atributos));

        return Article::find($articulo->id);
    }

    /**
     * Recalcula el precio del artículo con el motor real, con el dueño releído de la base, y lo
     * devuelve fresco.
     *
     * @param  \App\Models\Article  $articulo
     * @param  \App\Models\User     $dueno
     * @return \App\Models\Article
     */
    protected function recalcular($articulo, $dueno)
    {
        ArticleHelper::setFinalPrice(Article::find($articulo->id), null, User::find($dueno->id));

        return Article::find($articulo->id);
    }

    /**
     * El precio final del artículo, leído de la base.
     *
     * @param  \App\Models\Article|int  $articulo
     * @return float|null
     */
    protected function final_price_de($articulo)
    {
        $id = is_object($articulo) ? $articulo->id : $articulo;

        $valor = DB::table('articles')->where('id', $id)->value('final_price');

        return is_null($valor) ? null : (float) $valor;
    }

    /**
     * La fila del pivote article_price_type de un artículo en una lista, o null.
     *
     * @param  \App\Models\Article|int    $articulo
     * @param  \App\Models\PriceType|int  $lista
     * @return object|null
     */
    protected function pivote($articulo, $lista)
    {
        $article_id    = is_object($articulo) ? $articulo->id : $articulo;
        $price_type_id = is_object($lista) ? $lista->id : $lista;

        return DB::table('article_price_type')
            ->where('article_id', $article_id)
            ->where('price_type_id', $price_type_id)
            ->orderBy('id')
            ->first();
    }

    /**
     * La fila de article_price_type_monedas de un artículo en una lista y moneda, o null.
     *
     * @param  \App\Models\Article|int    $articulo
     * @param  \App\Models\PriceType|int  $lista
     * @param  int                        $moneda_id
     * @return object|null
     */
    protected function fila_moneda($articulo, $lista, $moneda_id)
    {
        $article_id    = is_object($articulo) ? $articulo->id : $articulo;
        $price_type_id = is_object($lista) ? $lista->id : $lista;

        return DB::table('article_price_type_monedas')
            ->where('article_id', $article_id)
            ->where('price_type_id', $price_type_id)
            ->where('moneda_id', $moneda_id)
            ->orderBy('id')
            ->first();
    }

    /**
     * Dos precios iguales a 2 decimales (null = null).
     *
     * @param  float|null  $esperado
     * @param  float|null  $real
     * @param  string      $mensaje
     * @return void
     */
    protected function assertMismoPrecio($esperado, $real, $mensaje = '')
    {
        if (is_null($esperado) || is_null($real)) {
            $this->assertTrue(is_null($esperado) && is_null($real), $mensaje.' (esperado '.var_export($esperado, true).', real '.var_export($real, true).')');

            return;
        }

        $this->assertEqualsWithDelta((float) $esperado, (float) $real, self::DELTA, $mensaje);
    }
}
