<?php

namespace Tests\Browser\Vender\Golonorte\Combos\Helpers;

use App\Models\Combo;

class Check_Stock_Movements
{


    function __construct($browser) {
        $this->browser = $browser;
    }

    function check_stock_movements($combo_name, $combo_amount) {

        $this->browser->visit('/listado-de-articulos');
        $this->browser->pause(4000);

        $combo = Combo::where('name', $combo_name)->first();

        foreach ($combo->articles as $article) {

            $this->check_movement($article, $combo_amount);
        }
    }

    function check_movement($article, $combo_amount) {

        $btn_dusk = 'btn-stock-movements-'.$article->id;
        
        $this->browser->waitFor("[dusk=\"$btn_dusk\"]");

        $this->browser->script("
            let btn = document.querySelector('[dusk=\"$btn_dusk\"]');
            let event = new MouseEvent('click', { bubbles: false });
            btn.dispatchEvent(event);
        ");

        $this->browser->pause(2000);

        // Concepto
        $this->browser->assertSeeIn('#stock-movement-table tbody tr:nth-child(1) td:nth-child(1)', 'Venta');

        // Cantidad
        $article_amount = (int)$article->pivot->amount * $combo_amount;
        $this->browser->assertSeeIn('#stock-movement-table tbody tr:nth-child(1) td:nth-child(2)', $article_amount);

        // Stock resultante
        $stock_resultante = $article->stock;
        $this->browser->assertSeeIn('#stock-movement-table tbody tr:nth-child(1) td:nth-child(4)', $stock_resultante);

        $this->browser->pause(2000);
        
        $this->browser->click('#stock-movement-modal-info .close');

        $this->browser->pause(1000);
    }
}
