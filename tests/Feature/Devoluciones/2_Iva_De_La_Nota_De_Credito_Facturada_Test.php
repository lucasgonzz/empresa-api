<?php

namespace Tests\Feature\Devoluciones;

use App\Http\Controllers\Helpers\Afip\AfipNotaCreditoHelper;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\CurrentAcount;
use App\Models\IvaCondition;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * El IVA y la fecha de emisión que se le declaran a ARCA al facturar una nota de crédito tienen que
 * quedar GUARDADOS en el `afip_ticket`.
 *
 * Por qué existe este archivo: hasta el 1/9/2026 `AfipNotaCreditoHelper::update_afip_ticket()`
 * escribía diez columnas y no escribía estas dos. El `ImpIVA` que se le mandaba a ARCA en
 * `interno()` se perdía apenas volvía la respuesta, y sin ese número el renglón "IVA de notas de
 * crédito emitidas" de la Posición Fiscal no tenía de dónde salir. Nada avisaba: el comprobante se
 * emitía bien, el CAE quedaba guardado, y la única señal era un renglón fiscal en cero.
 *
 * Es una clase de error que se puede volver a introducir sin querer, porque `update_afip_ticket()`
 * recibe un array armado en dos lugares distintos (`interno()` y `exportacion()`) y recortar una
 * clave de más no rompe nada visible. Los tests de la Posición Fiscal miden el renglón sobre datos
 * sembrados a mano; este mide la punta que los produce.
 *
 * 🔴 No toca la red. `init()` y `notaCredito()` son los que hablan con el webservice de ARCA;
 * `create_afip_ticket()` y `update_afip_ticket()` son métodos planos que solo escriben en base, y
 * son exactamente los dos que definen con qué forma queda la fila.
 *
 * @group devoluciones
 */
class Iva_De_La_Nota_De_Credito_Facturada_Test extends EmpresaTestCase
{
    /**
     * Ids de las filas sembradas por este test, en orden inverso al de creación, para borrarlas en
     * el tearDown y no dejar configuración fiscal ni comprobantes dando vueltas en el fixture.
     *
     * @var array<string,array<int,int>>
     */
    protected $sembrado = [
        'afip_tickets'      => [],
        'current_acounts'   => [],
        'sales'             => [],
        'afip_information'  => [],
    ];

    /**
     * Borra lo que sembró este test antes del rollback de DatabaseTransactions, en orden inverso al
     * de creación (primero los comprobantes, al final la configuración fiscal de la que cuelgan).
     * Corre siempre, incluso si una aserción cortó el test a mitad de camino.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (count($this->sembrado['afip_tickets'])) {
            // forceDelete y no delete: AfipTicket usa SoftDeletes, y un borrado normal dejaría la
            // fila viva en la tabla con deleted_at puesto.
            AfipTicket::whereIn('id', $this->sembrado['afip_tickets'])->forceDelete();
        }

        if (count($this->sembrado['current_acounts'])) {
            CurrentAcount::whereIn('id', $this->sembrado['current_acounts'])->delete();
        }

        if (count($this->sembrado['sales'])) {
            Sale::whereIn('id', $this->sembrado['sales'])->forceDelete();
        }

        if (count($this->sembrado['afip_information'])) {
            AfipInformation::whereIn('id', $this->sembrado['afip_information'])->delete();
        }

        parent::tearDown();
    }

    /**
     * Usuario dueño del fixture de testing (el mismo que autentica EmpresaTestCase::setUp()).
     *
     * @return \App\Models\User
     */
    protected function usuario_de_testing()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail();
    }

    /**
     * Arma el mínimo indispensable para que `AfipNotaCreditoHelper` se pueda construir y crear su
     * comprobante: la configuración fiscal del comercio, una venta y su factura autorizada, y el
     * movimiento de cuenta corriente de la nota de crédito.
     *
     * Crea su propia `AfipInformation` en vez de usar la del fixture a propósito. Cuando se escribió
     * este archivo el `TestingFerreteriaSeeder` no sembraba ninguna; el 1/9/2026 empezó a sembrarla
     * (`seed_afip_information()`), pero seguir creando la propia mantiene el escenario explícito y
     * bajo control de este test: si mañana el seeder le cambia el punto de venta o la condición de
     * IVA al fixture, este test no se entera. El `tearDown` borra por id, así que las dos conviven.
     *
     * @return array{afip_ticket_venta: \App\Models\AfipTicket, nota_credito: \App\Models\CurrentAcount}
     */
    protected function escenario_de_una_venta_facturada()
    {
        $usuario = $this->usuario_de_testing();

        $iva_condition = IvaCondition::where('name', 'Responsable inscripto')->first();

        if (is_null($iva_condition)) {
            $this->fail(
                'No existe la condición de IVA "Responsable inscripto" en la base de testing. El constructor de '.
                'AfipNotaCreditoHelper lee `afip_information->iva_condition->name`, así que sin ella este test no '.
                'puede armar el escenario. Es un problema del fixture para reportar, no algo para saltear.'
            );
        }

        $afip_information = AfipInformation::create([
            'user_id'                => $usuario->id,
            'iva_condition_id'       => $iva_condition->id,
            'razon_social'           => 'Comercio de test',
            'cuit'                   => '20000000000',
            'punto_venta'            => 1,
            // En 0 a propósito: marca el entorno de homologación, que es el que corresponde a un
            // test. El constructor del helper lee este campo para decidir `$this->testing`.
            'afip_ticket_production' => 0,
        ]);
        $this->sembrado['afip_information'][] = $afip_information->id;

        $venta = Sale::create([
            'user_id'   => $usuario->id,
            'moneda_id' => 1,
            'total'     => 1210,
            'terminada' => 1,
        ]);
        $this->sembrado['sales'][] = $venta->id;

        $afip_ticket_venta = AfipTicket::create([
            'sale_id'             => $venta->id,
            'afip_information_id' => $afip_information->id,
            'cbte_tipo'           => '1',
            'cbte_letra'          => 'A',
            'cbte_numero'         => (string) $venta->id,
            'resultado'           => 'A',
            'importe_total'       => 1210,
            'importe_iva'         => 210,
            'afip_fecha_emision'  => '2020-03-05',
            'cuit_negocio'        => '20000000000',
            'cae'                 => '00000000000000',
        ]);
        $this->sembrado['afip_tickets'][] = $afip_ticket_venta->id;

        $nota_credito = CurrentAcount::create([
            'detalle'     => 'Nota Credito de test',
            'description' => 'Devolución de test',
            'haber'       => 1210,
            'status'      => 'nota_credito',
            'sale_id'     => $venta->id,
            'user_id'     => $usuario->id,
            'moneda_id'   => 1,
        ]);
        $this->sembrado['current_acounts'][] = $nota_credito->id;

        return [
            'afip_ticket_venta' => $afip_ticket_venta,
            'nota_credito'      => $nota_credito,
        ];
    }

    /**
     * El par `importe_iva` + `afip_fecha_emision` que le llega a `update_afip_ticket()` tiene que
     * terminar en la fila, no solamente en el array.
     *
     * Se lee de vuelta de la base (`fresh()`) y no del modelo en memoria: un campo que no está en la
     * lista del `update()` se ve igual de bien en el objeto que quedó en RAM y solo se delata al
     * volver a consultarlo. Ese fue exactamente el modo de fallo original.
     *
     * De paso se verifica la forma con la que nace el comprobante — `sale_id` en NULL y
     * `nota_credito_id` apuntando al movimiento de cuenta corriente —, que es lo que hace que
     * `ContabilidadRepository::iva_debito()` (que joinea por `sale_id`) no cuente esta fila como si
     * fuera una factura de venta. Si esa forma cambiara, el IVA de las notas de crédito se sumaría
     * al débito en vez de restarse: un error de signo doble en una DDJJ.
     *
     * @group devoluciones
     * @test
     */
    public function update_afip_ticket_persiste_el_iva_y_la_fecha_de_emision_de_la_nota_de_credito()
    {
        $escenario = $this->escenario_de_una_venta_facturada();

        $helper = new AfipNotaCreditoHelper($escenario['afip_ticket_venta'], $escenario['nota_credito']);

        $helper->create_afip_ticket();

        $this->sembrado['afip_tickets'][] = $helper->created_afip_ticket->id;

        $helper->update_afip_ticket([
            'cuit_negocio'       => '20000000000',
            'cbte_nro'           => 77,
            'cbte_letra'         => 'A',
            'cbte_tipo'          => 3,
            'importe_total'      => 1210,
            'moneda_id'          => 'PES',
            'resultado'          => 'A',
            'concepto'           => 1,
            'cuit_cliente'       => '20111111112',
            'cae'                => '71234567890123',
            'cae_expired_at'     => '2020-03-20',
            'importe_iva'        => 210,
            'afip_fecha_emision' => '2020-03-10',
        ]);

        $fila = AfipTicket::find($helper->created_afip_ticket->id);

        if (is_null($fila)) {
            $this->fail('create_afip_ticket() no dejó ninguna fila en afip_tickets: el escenario no llegó a armarse y las aserciones de abajo no medirían nada.');
        }

        $this->assertEqualsWithDelta(
            210,
            (float) $fila->importe_iva,
            0.01,
            'El importe_iva que se le declaró a ARCA tiene que quedar persistido: sin él, el renglón "IVA de notas de crédito emitidas" de la Posición Fiscal no tiene monto que sumar.'
        );

        $this->assertEquals(
            '2020-03-10',
            Carbon::parse($fila->afip_fecha_emision)->format('Y-m-d'),
            'La afip_fecha_emision tiene que quedar persistida: es el único campo que decide a qué período fiscal se imputa la nota de crédito (toda la Posición Fiscal fechea por emisión, no por created_at).'
        );

        $this->assertNull(
            $fila->sale_id,
            'El afip_ticket de una nota de crédito nace SIN sale_id: es lo que hace que iva_debito() (que joinea por sale_id) no lo cuente como una factura de venta.'
        );

        $this->assertEquals(
            $escenario['nota_credito']->id,
            (int) $fila->nota_credito_id,
            'nota_credito_id tiene que apuntar al movimiento de cuenta corriente de la nota de crédito: es el campo por el que el renglón de la Posición Fiscal scopea por usuario.'
        );
    }
}
