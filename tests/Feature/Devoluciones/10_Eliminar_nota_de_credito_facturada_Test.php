<?php

namespace Tests\Feature\Devoluciones;

use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\IvaCondition;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Una nota de crédito ya facturada ante ARCA no se elimina (misión nc-aviso-existente-y-sin-cliente,
 * 8/10/2026; pedido de Lucas: "como pasa con las ventas"). La guarda vive en la API
 * (`DELETE current-acount/{model}/{id}`) aunque la pantalla esconda el botón: a ese delete también
 * llegan el asistente y cualquier llamada directa.
 *
 * @group devoluciones
 */
class Eliminar_nota_de_credito_facturada_Test extends EmpresaTestCase
{
    /** @var array */
    protected $sembrado = ['afip_information' => []];

    protected function tearDown(): void
    {
        if (count($this->sembrado['afip_information'])) {
            AfipTicket::withTrashed()->whereIn('afip_information_id', $this->sembrado['afip_information'])->forceDelete();
            AfipInformation::whereIn('id', $this->sembrado['afip_information'])->delete();
        }

        parent::tearDown();
    }

    /**
     * Una nota de crédito de un cliente con cuenta corriente (la que el modal de cuenta corriente
     * sabe eliminar) y, si se pide, su comprobante de ARCA.
     *
     * @param  array|null  $ticket  Campos del comprobante, o null para una nota sin comprobante.
     * @return array
     */
    protected function nota($ticket = null)
    {
        $usuario = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail();

        $iva = IvaCondition::where('name', 'Responsable inscripto')->first();

        $info = AfipInformation::create([
            'user_id'                => $usuario->id,
            'iva_condition_id'       => $iva->id,
            'razon_social'           => 'Comercio de test',
            'cuit'                   => '20000000000',
            'punto_venta'            => 1,
            'afip_ticket_production' => 0,
        ]);
        $this->sembrado['afip_information'][] = $info->id;

        $cliente = Client::create(['name' => 'Cliente borrar NC', 'user_id' => $usuario->id]);

        $cuenta = CreditAccount::create([
            'model_name' => 'client',
            'model_id'   => $cliente->id,
            'moneda_id'  => 1,
            'user_id'    => $usuario->id,
        ]);

        $venta = Sale::create([
            'user_id'   => $usuario->id,
            'client_id' => $cliente->id,
            'moneda_id' => 1,
            'total'     => 1000,
            'terminada' => 1,
        ]);

        $nota = CurrentAcount::create([
            'detalle'           => 'Nota Credito N°1',
            'haber'             => 1000,
            'status'            => 'nota_credito',
            'sale_id'           => $venta->id,
            'client_id'         => $cliente->id,
            'credit_account_id' => $cuenta->id,
            'user_id'           => $usuario->id,
            'moneda_id'         => 1,
        ]);

        $comprobante = null;

        if (!is_null($ticket)) {
            $comprobante = AfipTicket::create(array_merge([
                'nota_credito_id'      => $nota->id,
                'sale_nota_credito_id' => $venta->id,
                'afip_information_id'  => $info->id,
            ], $ticket));
        }

        return ['nota' => $nota, 'ticket' => $comprobante];
    }

    protected function borrar($nota)
    {
        return $this->delete('api/current-acount/client/'.$nota->id);
    }

    /**
     * @group devoluciones
     * @test
     */
    public function una_nota_con_cae_no_se_elimina()
    {
        $e = $this->nota(['cbte_tipo' => '8', 'cbte_numero' => '9', 'cae' => '71234567890123']);

        $response = $this->borrar($e['nota']);

        $response->assertStatus(422);
        $response->assertJson(['error_nota_credito_facturada' => true]);
        $this->assertStringContainsString('facturada ante ARCA', $response->json('message'));
        $this->assertNotNull(CurrentAcount::find($e['nota']->id), 'La nota sigue ahí.');
        $this->assertNotNull(AfipTicket::find($e['ticket']->id), 'Su comprobante sigue ahí.');
    }

    /**
     * @group devoluciones
     * @test
     */
    public function una_nota_enviada_a_arca_y_pendiente_de_confirmacion_no_se_elimina()
    {
        $e = $this->nota(['cbte_tipo' => '8', 'cbte_numero' => '9']);

        $response = $this->borrar($e['nota']);

        $response->assertStatus(422);
        $this->assertStringContainsString('pendiente de confirmación', $response->json('message'));
        $this->assertNotNull(CurrentAcount::find($e['nota']->id));
    }

    /**
     * Un intento que ARCA no autorizó (sin CAE ni número) no frena el borrado: la nota se elimina y
     * su comprobante fallido se da de baja con ella.
     *
     * @group devoluciones
     * @test
     */
    public function una_nota_con_un_intento_fallido_se_elimina_con_su_comprobante()
    {
        $e = $this->nota(['cbte_tipo' => '8']);

        $this->borrar($e['nota'])->assertStatus(200);

        $this->assertNull(CurrentAcount::find($e['nota']->id));
        $this->assertNull(AfipTicket::find($e['ticket']->id), 'El comprobante fallido no queda huérfano.');
        $this->assertNotNull(AfipTicket::withTrashed()->find($e['ticket']->id), 'Se da de baja (soft delete), no se borra de la base.');
    }

    /**
     * @group devoluciones
     * @test
     */
    public function una_nota_sin_comprobante_se_elimina_como_siempre()
    {
        $e = $this->nota();

        $this->borrar($e['nota'])->assertStatus(200);

        $this->assertNull(CurrentAcount::find($e['nota']->id));
    }

    /**
     * Un movimiento que no existe responde 404 (antes era un 500 por leer una propiedad de null).
     *
     * @group devoluciones
     * @test
     */
    public function un_movimiento_inexistente_responde_404()
    {
        $this->delete('api/current-acount/client/987654321')->assertStatus(404);
    }
}
