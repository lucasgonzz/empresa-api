<?php

namespace Tests\Feature\SucursalSiempre;

use App\Http\Controllers\Helpers\UserSetupHelper;
use App\Models\Address;
use App\Models\User;
use ReflectionMethod;

/**
 * D10, D11 y D8 de la misión sucursal-siempre: el user setup deja SIEMPRE al menos una sucursal.
 *
 * `UserSetupHelper::run()` arranca con un `migrate:fresh` (no se puede correr en la suite), así que se
 * prueba el paso que arma las sucursales (`crear_sucursales()`, privado) por reflexión, con un dueño
 * propio del test, igual que LogoDelNegocioEnUserSetupTest prueba `create_user()`. D11 (la extensión
 * de movimientos entre depósitos solo con `use_deposits`) se prueba sobre `apply_flag_rules()`.
 */
class User_setup_con_sucursal_Test extends SucursalSiempreTestCase
{
    /**
     * @param  string  $metodo
     * @param  array   $argumentos
     * @return mixed
     */
    protected function invocar_privado($metodo, array $argumentos)
    {
        $reflection = new ReflectionMethod(UserSetupHelper::class, $metodo);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(null, $argumentos);
    }

    /**
     * @param  array  $data
     * @return \App\Models\User  El dueño, releído.
     */
    protected function correr_setup_de_sucursales(array $data)
    {
        $dueno = $this->dueno_nuevo('setup');

        // crear_depositos() usa config('app.USER_ID'), que run() alinea con el dueño nuevo.
        config(['app.USER_ID' => $dueno->id]);

        $this->invocar_privado('crear_sucursales', [$data, $dueno]);

        return User::find($dueno->id);
    }

    /**
     * @test
     */
    public function sin_use_deposits_nace_una_principal_con_el_domicilio()
    {
        $dueno = $this->correr_setup_de_sucursales(['address_company' => '  Av. Siempreviva 742  ']);

        $sucursales = Address::sucursales_de($dueno->id)->get();

        $this->assertCount(1, $sucursales);
        $this->assertSame('Principal', $sucursales[0]->street);
        $this->assertSame('Av. Siempreviva 742', $sucursales[0]->street_number, 'D8: el domicilio se precarga desde address_company.');
        $this->assertSame(1, (int) $sucursales[0]->default_address);

        $this->assertSame('Av. Siempreviva 742', $dueno->address_company, 'address_company se guarda (antes se descartaba).');
        $this->assertSame($sucursales[0]->id, (int) $dueno->address_id, 'El dueño queda con la principal elegida.');
    }

    /**
     * @test
     */
    public function con_use_deposits_las_del_formulario_y_la_primera_es_principal()
    {
        $dueno = $this->correr_setup_de_sucursales([
            'use_deposits'    => 1,
            'address_1'       => 'Centro',
            'address_2'       => 'Norte',
            'address_company' => 'San Martín 100',
        ]);

        $sucursales = Address::sucursales_de($dueno->id)->get();

        $this->assertCount(2, $sucursales);
        $this->assertSame('Centro', $sucursales[0]->street);
        $this->assertSame(1, (int) $sucursales[0]->default_address);
        $this->assertSame('San Martín 100', $sucursales[0]->street_number);
        $this->assertSame(0, (int) $sucursales[1]->default_address);
        $this->assertNull($sucursales[1]->street_number, 'El domicilio va solo a la principal.');
        $this->assertSame($sucursales[0]->id, (int) $dueno->address_id);
    }

    /**
     * El camino de leads del admin no manda `address_company`: no falla y la principal nace sin domicilio.
     *
     * @test
     */
    public function sin_address_company_no_falla()
    {
        $dueno = $this->correr_setup_de_sucursales([]);

        $sucursales = Address::sucursales_de($dueno->id)->get();

        $this->assertCount(1, $sucursales);
        $this->assertNull($sucursales[0]->street_number);
        $this->assertNull($dueno->address_company);
        $this->assertSame($sucursales[0]->id, (int) $dueno->address_id);
    }

    /**
     * use_deposits sin ningún nombre cargado: igual nace la "Principal".
     *
     * @test
     */
    public function use_deposits_sin_nombres_tambien_deja_una()
    {
        $dueno = $this->correr_setup_de_sucursales(['use_deposits' => 1, 'address_1' => '']);

        $this->assertSame(['Principal'], Address::sucursales_de($dueno->id)->pluck('street')->all());
    }

    /**
     * D11: `deposit_movements` sigue saliendo solo con use_deposits.
     *
     * @test
     */
    public function deposit_movements_solo_con_use_deposits()
    {
        $extencions = [];
        $seeders = [];

        $reflection = new ReflectionMethod(UserSetupHelper::class, 'apply_flag_rules');
        $reflection->setAccessible(true);

        $argumentos = [[], &$extencions, &$seeders];
        $reflection->invokeArgs(null, $argumentos);

        $this->assertNotContains('deposit_movements', $extencions);

        $extencions = [];
        $seeders = [];
        $argumentos = [['use_deposits' => 1], &$extencions, &$seeders];
        $reflection->invokeArgs(null, $argumentos);

        $this->assertContains('deposit_movements', $extencions);
    }
}
