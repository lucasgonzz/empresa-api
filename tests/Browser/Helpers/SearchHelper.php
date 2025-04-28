<?php

namespace Tests\Browser\Helpers;


class SearchHelper
{
    
    static function search($browser, $data) {

        $browser->click($data['input']);
        $browser->pause(1000);

        $browser->type($data['input'].'-search-modal-input', $data['search']);
        $browser->pause(500);
        
        $browser->keys($data['input'].'-search-modal-input', ['{ENTER}']);
        $browser->pause(500);
        
        $browser->waitFor('@table-results-'.$data['model_name']);
        $browser->pause(500);
        
        $browser->keys($data['input'].'-search-modal-input', ['{ENTER}']);

        $browser->pause(500);
        
    }
}
