<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\UserController;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Pdf\Concerns\PerfilesDeTicketDeComandera;
use Tests\TestCase;

/**
 * La opción de impresión `ticket:{id}` (misión diseno-ticket-comandera, 9/10/2026, plan §7.4 y
 * pedido de la sesión madre): el SPA ofrece, en el atajo Imprimir de Vender (listas de remito y de
 * facturado) y en "qué imprime el botón de la factura ARCA" (`users.sale_factura_print_option`),
 * un diseño de ticket de comandera puntual. Antes la API la perdía sin avisar: el atajo la pasaba a
 * 'ticket_2' y la preferencia de la factura la guardaba en null.
 *
 * Lo que se cuida:
 * - el atajo guarda y devuelve `ticket:{id}` con un ticket del dueño de la clase de la lista
 *   (remito → no fiscal, facturado → fiscal); uno de otro dueño, un perfil de hoja o la clase
 *   cruzada caen a 'ticket_2';
 * - la preferencia de la factura guarda `ticket:{id}` con un ticket FISCAL del dueño y null con
 *   cualquier otro;
 * - `factura_a4:{id}` ahora tiene que ser del dueño (preexistente: no se filtraba) y de hoja;
 * - las claves de siempre quedan igual.
 *
 * @group pdf-ticket-comandera
 */
class Opcion_de_impresion_ticket_de_comandera_Test extends TestCase
{
    use DatabaseTransactions;
    use PerfilesDeTicketDeComandera;

    const URL_ATAJO = 'api/vender-keyboard-shortcut';

    /**
     * El dueño del fixture, autenticado.
     *
     * @return \App\Models\User
     */
    protected function autenticar()
    {
        $owner = User::find(500);

        if (is_null($owner)) {
            $this->fail('La base de testing no tiene el usuario 500 (TestingFerreteriaSeeder): sin el fixture estos tests no prueban nada.');
        }

        $this->actingAs($owner, 'web');

        return $owner;
    }

    /**
     * Un dueño nuevo, sin nada.
     *
     * @return \App\Models\User
     */
    protected function otro_dueno()
    {
        return User::create([
            'name' => 'Otro dueno ticket',
            'company_name' => 'Otro dueno ticket',
            'email' => 'ticket-otro-'.uniqid().'@test.local',
            'password' => 'x',
        ]);
    }

    /**
     * Guarda el atajo con esas dos opciones (sin "Ticket 2.0 para ambos") y devuelve las
     * print_options que respondió el PUT.
     *
     * @param string $remito
     * @param string $facturado
     * @return array
     */
    protected function guardar_atajo($remito, $facturado)
    {
        $response = $this->putJson(self::URL_ATAJO, [
            'shortcuts' => ['print' => 'F6'],
            'print_options' => [
                'use_ticket_2_for_both' => false,
                'remito' => $remito,
                'facturado' => $facturado,
            ],
        ]);

        $response->assertStatus(200);

        return $response->json('model.print_options');
    }

    /**
     * Corre el normalizador privado de la preferencia de la factura como dueño autenticado (el PUT
     * de usuario reescribe el usuario entero: no sirve para probar una sola columna).
     *
     * @param mixed $valor
     * @return string|null
     */
    protected function preferencia_de_factura($valor)
    {
        $metodo = new \ReflectionMethod(UserController::class, 'resolve_sale_factura_print_option');
        $metodo->setAccessible(true);

        return $metodo->invoke(new UserController(), $valor);
    }

    /**
     * @test
     */
    public function el_atajo_guarda_y_devuelve_un_ticket_del_dueno_en_cada_lista()
    {
        $owner = $this->autenticar();
        $remito = $this->perfil_de_ticket($owner->id, false);
        $factura = $this->perfil_de_ticket($owner->id, true);

        $guardado = $this->guardar_atajo('ticket:'.$remito->id, 'ticket:'.$factura->id);

        $this->assertSame('ticket:'.$remito->id, $guardado['remito']);
        $this->assertSame('ticket:'.$factura->id, $guardado['facturado']);

        /** Releído por el GET, igual. */
        $releido = $this->getJson(self::URL_ATAJO)->assertStatus(200)->json('model.print_options');
        $this->assertSame('ticket:'.$remito->id, $releido['remito']);
        $this->assertSame('ticket:'.$factura->id, $releido['facturado']);
    }

    /**
     * @test
     */
    public function el_atajo_sanea_a_ticket_2_un_ticket_ajeno_una_hoja_o_la_clase_cruzada()
    {
        $owner = $this->autenticar();
        $remito = $this->perfil_de_ticket($owner->id, false);
        $factura = $this->perfil_de_ticket($owner->id, true);
        $hoja = $this->perfil_de_hoja($owner->id, false);
        $ajeno = $this->perfil_de_ticket($this->otro_dueno()->id, false);

        $guardado = $this->guardar_atajo('ticket:'.$ajeno->id, 'ticket:'.$hoja->id);
        $this->assertSame('ticket_2', $guardado['remito'], 'Un ticket de otro dueño no se guarda.');
        $this->assertSame('ticket_2', $guardado['facturado'], 'Un perfil de hoja no es un ticket.');

        $guardado = $this->guardar_atajo('ticket:'.$factura->id, 'ticket:'.$remito->id);
        $this->assertSame('ticket_2', $guardado['remito'], 'En la lista de remito va un ticket de remito.');
        $this->assertSame('ticket_2', $guardado['facturado'], 'En la lista de facturado va un ticket de factura.');

        $guardado = $this->guardar_atajo('ticket:999999999', 'ticket:abc');
        $this->assertSame('ticket_2', $guardado['remito']);
        $this->assertSame('ticket_2', $guardado['facturado']);
    }

    /**
     * @test
     */
    public function el_atajo_conserva_las_claves_de_siempre()
    {
        $owner = $this->autenticar();
        $hoja = $this->perfil_de_hoja($owner->id, false);
        $factura_a4 = $this->perfil_de_hoja($owner->id, true);

        $guardado = $this->guardar_atajo('ticket_pdf', 'factura_ticket_pdf');
        $this->assertSame('ticket_pdf', $guardado['remito']);
        $this->assertSame('factura_ticket_pdf', $guardado['facturado']);

        $guardado = $this->guardar_atajo('remito_a4:'.$hoja->id, 'factura_a4:'.$factura_a4->id);
        $this->assertSame('remito_a4:'.$hoja->id, $guardado['remito']);
        $this->assertSame('factura_a4:'.$factura_a4->id, $guardado['facturado']);

        /** "Ticket 2.0 para ambos" sigue pisando las dos. */
        $remito = $this->perfil_de_ticket($owner->id, false);
        $response = $this->putJson(self::URL_ATAJO, [
            'shortcuts' => ['print' => 'F6'],
            'print_options' => ['use_ticket_2_for_both' => true, 'remito' => 'ticket:'.$remito->id, 'facturado' => 'ticket_pdf'],
        ])->assertStatus(200);
        $this->assertSame('ticket_2', $response->json('model.print_options.remito'));
        $this->assertSame('ticket_2', $response->json('model.print_options.facturado'));
    }

    /**
     * @test
     */
    public function la_preferencia_de_la_factura_guarda_y_relee_un_ticket_fiscal_del_dueno()
    {
        $owner = $this->autenticar();
        $factura = $this->perfil_de_ticket($owner->id, true);

        $valor = $this->preferencia_de_factura('ticket:'.$factura->id);
        $this->assertSame('ticket:'.$factura->id, $valor);

        $owner->sale_factura_print_option = $valor;
        $owner->save();
        $this->assertSame('ticket:'.$factura->id, User::find($owner->id)->sale_factura_print_option);
    }

    /**
     * @test
     */
    public function la_preferencia_de_la_factura_rechaza_un_ticket_de_remito_ajeno_o_una_hoja()
    {
        $owner = $this->autenticar();
        $remito = $this->perfil_de_ticket($owner->id, false);
        $hoja_fiscal = $this->perfil_de_hoja($owner->id, true);
        $ajeno = $this->perfil_de_ticket($this->otro_dueno()->id, true);

        $this->assertNull($this->preferencia_de_factura('ticket:'.$remito->id), 'El botón de la factura imprime un ticket de factura.');
        $this->assertNull($this->preferencia_de_factura('ticket:'.$hoja_fiscal->id), 'Un perfil de hoja no es un ticket.');
        $this->assertNull($this->preferencia_de_factura('ticket:'.$ajeno->id), 'Un ticket de otro dueño no se guarda.');
        $this->assertNull($this->preferencia_de_factura('ticket:0'));
    }

    /**
     * @test
     */
    public function factura_a4_tiene_que_ser_una_hoja_fiscal_del_dueno_y_las_fijas_quedan_igual()
    {
        $owner = $this->autenticar();
        $propia = $this->perfil_de_hoja($owner->id, true);
        $ajena = $this->perfil_de_hoja($this->otro_dueno()->id, true);
        $ticket_fiscal = $this->perfil_de_ticket($owner->id, true);

        $this->assertSame('factura_a4:'.$propia->id, $this->preferencia_de_factura('factura_a4:'.$propia->id));
        $this->assertNull($this->preferencia_de_factura('factura_a4:'.$ajena->id), 'Un diseño de otro dueño no se guarda (antes no se filtraba).');
        $this->assertNull($this->preferencia_de_factura('factura_a4:'.$ticket_fiscal->id), 'Un ticket de comandera nunca se abre como PDF (D4).');

        $this->assertSame('ticket_2', $this->preferencia_de_factura('ticket_2'));
        $this->assertSame('factura_ticket_pdf', $this->preferencia_de_factura('factura_ticket_pdf'));
        $this->assertNull($this->preferencia_de_factura(''));
        $this->assertNull($this->preferencia_de_factura('cualquier_cosa'));
    }
}
