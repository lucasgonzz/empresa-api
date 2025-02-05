<?php

namespace Tests\Browser\Helpers;


class AuthHelper
{
    
    static function login($browser)
    {
        
        return $browser->move(0, 0)
                ->visit('/inicio')
                ->waitFor('@login-btn') 
                ->press('@login-btn')
                ->waitFor('@dni') 
                ->type('@dni', '1234')
                ->type('@password', '123')
                ->press('login')
                ->waitForLocation('/reportes/generales')
                ->assertPathIs('/reportes/generales');
    }
}
