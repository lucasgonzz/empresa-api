<?php

namespace Tests\Browser\Vender;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Browser\Helpers\AuthHelper;

class crear_venta_Test extends DuskTestCase
{
    /**
     * A Dusk test example.
     *
     * @return void
     */
    public function test_crear_venta()
    {


        $this->browse(function (Browser $browser) {

            $browser = AuthHelper::login($browser);
            
            $browser->visit('/vender/remito');
            

            $browser = $this->add_article($browser, '003', 2);
            
            $browser = $this->add_article($browser, '002', '');
            

            $browser->pause(4000);

            $browser->pause(2000)
                    ->press('@btn_vender')
                    ->pause(10000);

        });
    }


    function add_article($browser, $bar_code, $amount) {

                $browser
                ->pause(2000)
                
                ->waitFor('@article_bar_code')
                ->waitUntilEnabled('@article_bar_code')
                ->click('@article_bar_code')
                ->clear('@article_bar_code')
                ->type('@article_bar_code', $bar_code)
                ->pause(1000)
                ->keys('@article_bar_code', ['{ENTER}']);
                    
        
        $browser->pause(4000)

                ->waitFor('@article_amount')
                ->type('@article_amount', $amount)
                ->pause(1000);

        
        $browser
                ->keys('@article_amount', ['{ENTER}']);

        return $browser;


    }
}
