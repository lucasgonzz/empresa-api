<?php

namespace Tests\Browser;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class LoginTest extends DuskTestCase
{
    /**
     * A Dusk test example.
     *
     * @return void
     */
    public function test_login()
    {
        $this->browse(function (Browser $browser) {
            // $browser->visit('/inicio')
                    // ->assertSee('Organizamos');
            $browser->visit('/inicio')
                    ->waitFor('@login-btn') 
                    ->press('@login-btn')
                    ->type('dni', '1234')
                    ->type('password', '123')
                    ->press('login')
                    ->waitForLocation('/reportes/generales')
                    ->assertPathIs('/reportes/generales');
        });
    }
}
