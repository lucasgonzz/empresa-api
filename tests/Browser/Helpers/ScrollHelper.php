<?php

namespace Tests\Browser\Helpers;


class ScrollHelper
{
    
    static function scroll($browser, $scroll = 800) {

        $browser->script("document.querySelector('.cont-table').scrollLeft += $scroll;");

    }

}
