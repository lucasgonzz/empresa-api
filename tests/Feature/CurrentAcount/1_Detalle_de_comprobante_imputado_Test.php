<?php

namespace Tests\Feature\CurrentAcount;

use App\Http\Controllers\Helpers\currentAcount\ComprobanteImputadoHelper;
use App\Models\AfipTicket;
use App\Models\AfipTipoComprobante;
use App\Models\CurrentAcount;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Feature tests de ComprobanteImputadoHelper (grupo 339, prompt 01). Estrena la suite
 * `recibo-cc` en empresa-api/develop.
 *
 * Protege el bug de fondo: `Sale` no tiene relacion ni accessor `afip_ticket` (singular),
 * asi que la condicion vieja de NewPagoPdf.php (`$sale->afip_ticket`) siempre daba null sin
 * error y el recibo nunca mostraba la factura. El helper reemplaza esa condicion y define el
 * criterio de "comprobante autorizado" (con CAE) que usan tanto la tabla de COMPROBANTES
 * IMPUTADOS como el CONCEPTO del recibo.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing esta sembrada de antes y un
 * refresh la vaciaria, rompiendo el resto de las suites. Cada test arma sus propios datos.
 */
class Detalle_de_comprobante_imputado_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * @param int $codigo
     * @param string $name
     * @return \App\Models\AfipTipoComprobante
     */
    protected function tipo_comprobante($codigo, $name)
    {
        return AfipTipoComprobante::firstOrCreate(
            ['codigo' => $codigo],
            ['name' => $name]
        );
    }

    /**
     * @param int $user_id
     * @return \App\Models\Sale
     */
    protected function crear_venta($user_id)
    {
        return Sale::create([
            'user_id'   => $user_id,
            'moneda_id' => 1,
            'total'     => 1000,
            'terminada' => 1,
        ]);
    }

    /**
     * @group recibo-cc
     * @test
     */
    public function venta_con_ticket_autorizado_muestra_venta_y_factura_con_padding()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $venta = $this->crear_venta($user->id);
        $tipo = $this->tipo_comprobante(1, 'Factura A');

        AfipTicket::create([
            'sale_id'                    => $venta->id,
            'afip_tipo_comprobante_id'   => $tipo->id,
            'punto_venta'                => 3,
            'cbte_numero'                => 123,
            'cbte_letra'                 => 'A',
            'cae'                        => '71234567890123',
        ]);

        $current_acount = CurrentAcount::create([
            'status'  => 'sin_pagar',
            'sale_id' => $venta->id,
        ]);

        $this->assertEquals(
            'Venta N° '.$venta->num.' - Factura A 00003-00000123',
            ComprobanteImputadoHelper::get_detalle($current_acount)
        );
    }

    /**
     * @group recibo-cc
     * @test
     */
    public function venta_sin_ningun_ticket_muestra_solo_el_numero_de_venta()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $venta = $this->crear_venta($user->id);

        $current_acount = CurrentAcount::create([
            'status'  => 'sin_pagar',
            'sale_id' => $venta->id,
        ]);

        $this->assertEquals(
            'Venta N° '.$venta->num,
            ComprobanteImputadoHelper::get_detalle($current_acount)
        );
    }

    /**
     * @group recibo-cc
     * @test
     */
    public function venta_con_ticket_sin_cae_no_lo_muestra_como_factura()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        // Un ticket con cae null y otro con cae string vacio: ninguno de los dos cuenta
        // como comprobante autorizado.
        $venta_null = $this->crear_venta($user->id);
        AfipTicket::create([
            'sale_id'     => $venta_null->id,
            'punto_venta' => 1,
            'cbte_numero' => 1,
            'cae'         => null,
        ]);

        $venta_vacio = $this->crear_venta($user->id);
        AfipTicket::create([
            'sale_id'     => $venta_vacio->id,
            'punto_venta' => 1,
            'cbte_numero' => 2,
            'cae'         => '',
        ]);

        $current_acount_null = CurrentAcount::create([
            'status'  => 'sin_pagar',
            'sale_id' => $venta_null->id,
        ]);
        $current_acount_vacio = CurrentAcount::create([
            'status'  => 'sin_pagar',
            'sale_id' => $venta_vacio->id,
        ]);

        $this->assertEquals(
            'Venta N° '.$venta_null->num,
            ComprobanteImputadoHelper::get_detalle($current_acount_null)
        );
        $this->assertEquals(
            'Venta N° '.$venta_vacio->num,
            ComprobanteImputadoHelper::get_detalle($current_acount_vacio)
        );
    }

    /**
     * @group recibo-cc
     * @test
     */
    public function venta_con_dos_tickets_devuelve_el_que_tiene_cae()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $venta = $this->crear_venta($user->id);
        $tipo = $this->tipo_comprobante(1, 'Factura A');

        // El primer intento de facturacion fallo (sin CAE); el segundo, creado despues, se
        // autorizo. El helper tiene que devolver el autorizado, no "el ultimo por id" a secas.
        AfipTicket::create([
            'sale_id'     => $venta->id,
            'punto_venta' => 3,
            'cbte_numero' => 100,
            'cae'         => null,
        ]);
        AfipTicket::create([
            'sale_id'                  => $venta->id,
            'afip_tipo_comprobante_id' => $tipo->id,
            'punto_venta'              => 3,
            'cbte_numero'              => 101,
            'cbte_letra'               => 'A',
            'cae'                      => '71234567890124',
        ]);

        $current_acount = CurrentAcount::create([
            'status'  => 'sin_pagar',
            'sale_id' => $venta->id,
        ]);

        $this->assertEquals(
            'Venta N° '.$venta->num.' - Factura A 00003-00000101',
            ComprobanteImputadoHelper::get_detalle($current_acount)
        );
    }

    /**
     * @group recibo-cc
     * @test
     */
    public function ticket_sin_tipo_de_comprobante_usa_la_letra_como_fallback()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $venta = $this->crear_venta($user->id);

        AfipTicket::create([
            'sale_id'                  => $venta->id,
            'afip_tipo_comprobante_id' => null,
            'punto_venta'              => 2,
            'cbte_numero'              => 45,
            'cbte_letra'               => 'B',
            'cae'                      => '71234567890125',
        ]);

        $current_acount = CurrentAcount::create([
            'status'  => 'sin_pagar',
            'sale_id' => $venta->id,
        ]);

        $this->assertEquals(
            'Venta N° '.$venta->num.' - Factura B 00002-00000045',
            ComprobanteImputadoHelper::get_detalle($current_acount)
        );
    }

    /**
     * @group recibo-cc
     * @test
     */
    public function movimiento_sin_venta_devuelve_su_propio_detalle()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $current_acount = CurrentAcount::create([
            'status'  => 'sin_pagar',
            'detalle' => 'Saldo inicial',
        ]);

        $this->assertEquals(
            'Saldo inicial',
            ComprobanteImputadoHelper::get_detalle($current_acount)
        );
    }
}
