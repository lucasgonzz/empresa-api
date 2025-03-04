<?php

namespace Tests\Browser\Listado\Clases;

use Tests\Browser\Listado\Helpers\FiltrarArticleHelper;

class filtrar {

    function __construct($browser) {

        $this->browser = $browser;

        $this->filtrar();

    }

    function filtrar() {

        FiltrarArticleHelper::filtrar($this->browser);
    }
}
