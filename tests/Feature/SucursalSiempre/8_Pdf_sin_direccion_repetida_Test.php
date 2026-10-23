<?php

namespace Tests\Feature\SucursalSiempre;

use App\Models\Address;

/**
 * §9 del plan de la misión sucursal-siempre: con la principal precargada con el domicilio de la
 * empresa (D8), el PDF de la venta imprimía la misma dirección dos veces. La línea de la sucursal no
 * se imprime cuando su domicilio es textualmente el de la empresa (sin espacios de los bordes ni
 * mayúsculas); cualquier otra diferencia se imprime como siempre.
 */
class Pdf_sin_direccion_repetida_Test extends SucursalSiempreTestCase
{
    /**
     * @test
     */
    public function la_linea_de_la_sucursal_no_repite_la_direccion_de_la_empresa()
    {
        $sucursal = new Address(['street' => 'Principal', 'street_number' => '  av. siempreviva 742 ']);

        $this->assertTrue(Address::repite_la_direccion_de_la_empresa($sucursal, 'Av. Siempreviva 742'));
        $this->assertFalse(Address::repite_la_direccion_de_la_empresa($sucursal, 'Av. Siempreviva 743'));
        $this->assertFalse(Address::repite_la_direccion_de_la_empresa($sucursal, null), 'Sin dirección de empresa no hay nada repetido.');
        $this->assertFalse(Address::repite_la_direccion_de_la_empresa($sucursal, '   '));

        $sin_domicilio = new Address(['street' => 'Centro', 'street_number' => null]);

        $this->assertFalse(Address::repite_la_direccion_de_la_empresa($sin_domicilio, 'Av. Siempreviva 742'));
    }
}
