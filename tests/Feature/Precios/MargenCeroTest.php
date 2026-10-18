<?php

namespace Tests\Feature\Precios;

use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Un margen de ganancia en 0 deja de trabar la ficha del articulo (mision 44).
 *
 * El estado que motivo la mision: `percentage_gain = 0` conviviendo con un `price`
 * cargado dejaba los DOS inputs de la ficha bloqueados y sin salida -- el back no
 * limpiaba el precio (0 no es > 0) y el front bloqueaba los dos campos ("0.00" != ''
 * es verdadero en JS). Este test cubre las dos mitades de la solucion del lado del back:
 *
 *   1. Guardar por el endpoint real ya no genera ese estado (se persiste null).
 *   2. El comando articles:normalizar-margen-cero limpia los que YA estan asi, que son
 *      el motivo de la mision: sin ese comando, la mision arregla la interfaz para los
 *      articulos futuros y deja igual a los que estan trabados hoy.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing del slot esta sembrada
 * de antes y un refresh la vaciaria.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promocion de constructor, readonly, enum ni #[...].
 */
class MargenCeroTest extends TestCase
{
    use DatabaseTransactions;

    /** @var \App\Models\User */
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::find(500);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($this->user, 'web');
    }

    /**
     * Articulo creado directo en la base (sin pasar por el endpoint), para poder sembrar
     * a proposito el estado sucio que el comando tiene que limpiar.
     *
     * @param  array $props
     * @return \App\Models\Article
     */
    protected function crear_articulo(array $props)
    {
        $article = new Article();

        $article->user_id = $this->user->id;
        $article->name    = 'ZZ Test margen cero';
        $article->status  = 'active';
        $article->iva_id  = 2;

        foreach ($props as $campo => $valor) {
            $article->$campo = $valor;
        }

        $article->save();

        return $article;
    }

    /**
     * Payload minimo que acepta ArticleController: varias columnas de `articles` son NOT
     * NULL sin default y el controlador las asigna tal cual vienen del request, asi que un
     * body incompleto revienta con un 23000 antes de llegar a lo que este test mide.
     *
     * @param  array $props
     * @return array
     */
    protected function payload(array $props = [])
    {
        return array_merge([
            'name'                           => 'ZZ Test margen cero',
            'cost'                           => 100,
            'iva_id'                         => 2,
            'aplicar_iva'                    => 1,
            'apply_provider_percentage_gain' => 0,
            'cost_in_dollars'                => 0,
            'provider_cost_in_dollars'       => 0,
            'online'                         => 0,
            'in_offer'                       => 0,
            'precio_pausado'                 => 0,
            'default_in_vender'              => 0,
            'personalizar_price_en_vender'   => 0,
            'omitir_en_lista_pdf'            => 0,
            'mercado_libre'                  => 0,
            'disponible_tienda_nube'         => 0,
            'requires_shipping'              => 0,
            'free_shipping'                  => 0,
            'es_insumo'                      => 0,
            'featured'                       => 0,
            /* Los helpers de relaciones hacen foreach directo sobre estos: no pueden faltar. */
            'price_types'                    => [],
            'price_type_monedas'             => [],
            'tags'                           => [],
            'addresses'                      => [],
            'childrens'                      => [],
        ], $props);
    }

    /**
     * Guardar un articulo nuevo con margen 0 lo persiste como null.
     *
     * @return void
     */
    public function test_al_crear_un_margen_en_cero_se_guarda_como_null()
    {
        $respuesta = $this->postJson('/api/article', $this->payload([
            'name'            => 'ZZ Test alta margen cero',
            'percentage_gain' => 0,
            'price'           => 250,
        ]));

        $respuesta->assertStatus(201);

        $id = $respuesta->json('model.id');

        $this->assertNotNull($id, 'El alta no devolvio el articulo creado.');

        $articulo = Article::find($id);

        $this->assertNull($articulo->percentage_gain, 'Un margen en 0 tiene que persistirse como null.');
    }

    /**
     * Y editar uno existente, tambien.
     *
     * @return void
     */
    public function test_al_editar_un_margen_en_cero_se_guarda_como_null()
    {
        $articulo = $this->crear_articulo([
            'cost'            => 100,
            'percentage_gain' => 30,
            'price'           => null,
        ]);

        /* ArticleController@update lee el id del BODY ($request->id), no del parametro de ruta. */
        $respuesta = $this->putJson('/api/article/' . $articulo->id, $this->payload([
            'id'              => $articulo->id,
            'name'            => $articulo->name,
            'percentage_gain' => 0,
            'price'           => 250,
        ]));

        $respuesta->assertStatus(200);

        $articulo->refresh();

        $this->assertNull($articulo->percentage_gain, 'Un margen en 0 tiene que persistirse como null.');
        $this->assertNotNull($articulo->price, 'Con el margen en null, el precio manual tiene que sobrevivir.');
    }

    /**
     * El comando limpia los que ya estan sucios y no toca a nadie mas.
     *
     * @return void
     */
    public function test_el_comando_normaliza_los_margenes_en_cero_existentes()
    {
        $sucio    = $this->crear_articulo(['cost' => 100, 'percentage_gain' => 0,    'price' => 250]);
        $con_gain = $this->crear_articulo(['cost' => 100, 'percentage_gain' => 25,   'price' => null]);
        $sin_gain = $this->crear_articulo(['cost' => 100, 'percentage_gain' => null, 'price' => 250]);
        $negativo = $this->crear_articulo(['cost' => 100, 'percentage_gain' => -5,   'price' => null]);

        Artisan::call('articles:normalizar-margen-cero', ['--aplicar' => true, '--user_id' => $this->user->id]);

        $sucio->refresh();
        $con_gain->refresh();
        $sin_gain->refresh();
        $negativo->refresh();

        $this->assertNull($sucio->percentage_gain, 'El margen en 0 tiene que quedar en null.');
        $this->assertEquals(25, (float) $con_gain->percentage_gain, 'Un margen real no se toca.');
        $this->assertNull($sin_gain->percentage_gain, 'Un margen que ya era null sigue igual.');
        $this->assertEquals(-5, (float) $negativo->percentage_gain, 'Un margen negativo no es el estado que traba la ficha: no se toca.');
    }

    /**
     * Es idempotente: la segunda corrida no encuentra nada y no cambia nada.
     *
     * @return void
     */
    public function test_el_comando_es_idempotente()
    {
        $sucio = $this->crear_articulo(['cost' => 100, 'percentage_gain' => 0, 'price' => 250]);

        Artisan::call('articles:normalizar-margen-cero', ['--aplicar' => true, '--user_id' => $this->user->id]);

        $sucio->refresh();
        $this->assertNull($sucio->percentage_gain);

        Artisan::call('articles:normalizar-margen-cero', ['--aplicar' => true, '--user_id' => $this->user->id]);

        $this->assertStringContainsString(
            'No hay articulos con margen de ganancia en 0',
            Artisan::output(),
            'La segunda corrida no tiene que encontrar nada para tocar.'
        );
    }

    /**
     * Sin --aplicar el comando no escribe nada: es de solo lectura por defecto, igual
     * que normalizar_api_url.
     *
     * @return void
     */
    public function test_sin_aplicar_el_comando_no_escribe()
    {
        $sucio = $this->crear_articulo(['cost' => 100, 'percentage_gain' => 0, 'price' => 250]);

        Artisan::call('articles:normalizar-margen-cero', ['--user_id' => $this->user->id]);

        $fresco = DB::table('articles')->where('id', $sucio->id)->first();

        $this->assertNotNull($fresco->percentage_gain, 'En modo reporte no se escribe nada.');
        $this->assertEquals(0, (float) $fresco->percentage_gain);
    }

    /**
     * Un --user_id pelado, vacio, no numerico o en cero corta con codigo 1 y sin tocar nada.
     *
     * Antes, pelado (null) y vacio ('') se tomaban como "sin filtro": `--aplicar --user_id $ID`
     * con `$ID` vacio en el shell normalizaba a TODOS los dueños. En un ArrayInput (Artisan::call)
     * un null es la opcion pelada, igual que en la consola real.
     *
     * @return void
     */
    public function test_un_user_id_pelado_o_vacio_o_no_numerico_o_cero_corta_sin_tocar_nada()
    {
        $sucio = $this->crear_articulo(['cost' => 100, 'percentage_gain' => 0, 'price' => 250]);

        $casos = [
            'pelado'      => null,
            'vacio'       => '',
            'no numerico' => 'abc',
            'con basura'  => '500x',
            'cero'        => '0',
            'negativo'    => '-3',
        ];

        foreach ($casos as $nombre => $valor) {
            $codigo = Artisan::call('articles:normalizar-margen-cero', ['--aplicar' => true, '--user_id' => $valor]);

            $this->assertSame(1, $codigo, "--user_id $nombre tiene que cortar con codigo 1.");
            $this->assertStringContainsString('No se hizo nada', Artisan::output(), "--user_id $nombre tiene que avisar que no se hizo nada.");

            $fresco = DB::table('articles')->where('id', $sucio->id)->first();

            $this->assertNotNull($fresco->percentage_gain, "--user_id $nombre no tiene que escribir nada.");
            $this->assertEquals(0, (float) $fresco->percentage_gain);
        }
    }

    /**
     * Sin la opcion el comando sigue barriendo a todos (comportamiento intacto), y con un id
     * valido solo a ese dueño.
     *
     * @return void
     */
    public function test_sin_user_id_sigue_andando_y_con_id_valido_acota()
    {
        $sucio = $this->crear_articulo(['cost' => 100, 'percentage_gain' => 0, 'price' => 250]);

        $codigo = Artisan::call('articles:normalizar-margen-cero', ['--aplicar' => true, '--user_id' => '999999999']);
        $this->assertSame(0, $codigo);
        $this->assertEquals(0, (float) DB::table('articles')->where('id', $sucio->id)->value('percentage_gain'), 'Otro dueño: no se toca.');

        $codigo = Artisan::call('articles:normalizar-margen-cero', ['--aplicar' => true]);
        $this->assertSame(0, $codigo);
        $this->assertNull(DB::table('articles')->where('id', $sucio->id)->value('percentage_gain'), 'Sin la opcion barre todos los dueños, como siempre.');
    }
}
