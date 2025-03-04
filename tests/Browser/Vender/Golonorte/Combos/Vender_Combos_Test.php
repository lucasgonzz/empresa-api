<?php

namespace Tests\Browser\Vender\Golonorte\Combos;

use App\Models\Combo;
use Laravel\Dusk\Browser;
use Tests\Browser\Helpers\AuthHelper;
use Tests\Browser\Helpers\SaleHelper;
use Tests\Browser\Helpers\Sale\ComboHelper;
use Tests\Browser\Vender\Golonorte\Combos\Helpers\Actualizar_Venta;
use Tests\Browser\Vender\Golonorte\Combos\Helpers\Check_Stock_Movements;
use Tests\Browser\Vender\Golonorte\Combos\Helpers\CrearVenta;
use Tests\DuskTestCase;

class Vender_Combos_Test extends DuskTestCase
{
    public $combo_name = 'Combo 1';
    public $combo_amount = 2;

    public $combo_new_amount = 5;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->artisan('migrate:fresh');
        // Ejecuta los seeders después de las migraciones
        $this->artisan('db:seed');
    }

    /**
     * A Dusk test example.
     *
     * @return void
     * @group vender_combos
     */
    public function test_combos_vender()
    {
        $this->browse(function (Browser $browser) {
            $this->browser = AuthHelper::login($browser);
            
            $this->browser->visit('/vender/remito');
            $this->browser->pause(3000);


            // Vendo 2 "combo 1"
            $this->crear_venta();


            /*
                Chequeo que yerba quede con 96
                Chequeo que mate torpedo quede con 92
            */
            $this->check_stock_movements();


            $this->actualizar_venta();


            $this->check_stock_movements_actualizados();
        });
    }

    function crear_venta() {

        /*
            * Agrego Combo 1
            * precio = $1.000
            * total = $2.000
        */
        
        $helper = new CrearVenta($this->browser);
        $helper->crear_venta($this->combo_name, $this->combo_amount);
    }

    function check_stock_movements() {

        $helper = new Check_Stock_Movements($this->browser);
        $helper->check_stock_movements($this->combo_name, $this->combo_amount);
    }

    function actualizar_venta() {


        $helper = new Actualizar_Venta($this->browser);
        $helper->actualizar_venta($this->combo_new_amount);
    }

    function check_stock_movements_actualizados() {

        $helper = new Check_Stock_Movements($this->browser);
        $helper->check_stock_movements($this->combo_name, $this->combo_new_amount - $this->combo_amount);
    }

    
}
