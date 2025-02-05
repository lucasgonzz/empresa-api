<?php

namespace Tests\Browser\Listado;

use App\Models\Article;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\Browser\Helpers\AuthHelper;
use Tests\DuskTestCase;

class check_codigo_repetido_Test extends DuskTestCase
{

    public $article_name = 'Stock depositos';
    public $article = null;
    public $new_cost = 10;

    /**
     * @group listado
     */
    public function test_b_check_codigo_repetido()
    {
        $this->browse(function (Browser $browser) {

            $browser = AuthHelper::login($browser);
            
            $browser->visit('/listado-de-articulos');
            $browser->pause(4000);

            $this->set_article();

            $this->ingresar_codigo_repetido($browser, $this->article->bar_code);

            $browser->pause(1000);

            $this->actualizar_costo($browser, $this->new_cost);

            $this->check_bbdd();

            dump('Se actualizo articulo');
        });
    }

    function set_article() {
        $this->article = Article::where('name', $this->article_name)->first();
    }

    function check_bbdd() {

        $this->assertDatabaseHas('articles', [
            'article_id'        => $this->article->id,
            'cost'              => $this->new_cost,
        ]);
    }

    function ingresar_codigo_repetido($browser, $bar_code) {

        $browser->click('@btn_create_article');
        $browser->pause(1000);

        $browser->type('#article-bar_code', $bar_code);
        $browser->keys('#article-bar_code', ['{ENTER}']);
        $browser->pause(2000);
    }

    function actualizar_costo($browser, $costo) {

        $browser->type('#article-cost', $costo);
        $browser->pause(2000);

        $browser->click('@btn_guardar_article');
        $browser->pause(1000);
    }
}
