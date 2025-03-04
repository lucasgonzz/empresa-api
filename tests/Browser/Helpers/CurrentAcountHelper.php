<?php

namespace Tests\Browser\Helpers;

use App\Models\Client;

class CurrentAcountHelper {

    function __construct($browser, $data) {

        $this->browser = $browser;

        $this->client_name          = $data['client_name'];
        $this->fila                 = $data['fila'];

        if (isset($data['detalle'])) {
            $this->detalle                 = $data['detalle'];
        } else {
            $this->detalle = null;
        }

        if (isset($data['debe'])) {
            $this->debe                 = $data['debe'];
        } else {
            $this->debe = null;
        }

        if (isset($data['haber'])) {
            $this->haber                 = $data['haber'];
        } else {
            $this->haber = null;
        }

        if (isset($data['saldo'])) {
            $this->saldo                 = $data['saldo'];
        } else {
            $this->saldo = null;
        }

        $this->browser->visit('/clientes/clientes');

        $this->browser->pause(2000);

        $this->abrir_cuenta_corriente();

        $this->check_movimiento();
    }

    function check_movimiento() {
        $tr = "#table-current-acounts tbody tr:nth-child({$this->fila})";

        $this->browser->waitFor($tr);

        $this->browser->pause(500);

        if ($this->detalle) {
            $td_detalle = $tr.' td:nth-child(2)';
            $this->browser->assertSeeIn($td_detalle, $this->detalle);
        }

        if ($this->debe) {
            $td_debe = $tr.' td:nth-child(5)';
            $this->browser->assertSeeIn($td_debe, $this->debe);
        }

        if ($this->saldo) {
            $td_saldo = $tr.' td:nth-child(7)';
            $this->browser->assertSeeIn($td_saldo, $this->saldo);
        }

        dump('Saldo cliente OK');
    }

    function abrir_cuenta_corriente() {

        $client_model = Client::where('name', $this->client_name)->first();

        $btn_cc = "#table-client tbody #btn-current-acount-{$client_model->id}";
        
        $this->browser->waitFor($btn_cc, 30);
        $this->browser->click($btn_cc);

        $this->browser->pause(1000);
        
    }

    
    
}
