<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Http\Controllers\Helpers\PdfTicketComanderaSetupHelper;
use App\Http\Controllers\Helpers\UserSetupHelper;
use App\Models\PdfColumnProfile;
use App\Models\User;
use Database\Seeders\PdfTicketComanderaSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Pdf\Concerns\PerfilesDeTicketDeComandera;
use Tests\TestCase;

/**
 * Los dos tickets de comandera por defecto, "Ticket remito" y "Ticket factura" (misión
 * diseno-ticket-comandera, 9/10/2026, pedido 7 de Lucas y §6 del plan): lo que crea el helper, que
 * es idempotente y respeta lo que el dueño ya tiene, que el seeder suelto recorre solo dueños, y que
 * está en las listas de los dos setups.
 *
 * @group pdf-ticket-comandera
 */
class Tickets_por_defecto_Test extends TestCase
{
    use DatabaseTransactions;
    use PerfilesDeTicketDeComandera;

    /**
     * Un dueño nuevo.
     *
     * @param array $atributos
     * @return \App\Models\User
     */
    private function dueno(array $atributos = [])
    {
        return User::create(array_merge([
            'name' => 'Dueno tickets por defecto',
            'company_name' => 'Dueno tickets por defecto',
            'email' => 'tickets-defecto-'.uniqid().'@test.local',
            'password' => 'x',
        ], $atributos));
    }

    /**
     * Los tickets de un dueño, por id.
     *
     * @param int $owner_id
     * @return \Illuminate\Support\Collection
     */
    private function tickets($owner_id)
    {
        return PdfColumnProfile::where('user_id', $owner_id)->deTicket()->orderBy('id')->get();
    }

    /**
     * Las columnas visibles de un perfil, en orden: [value_resolver => [ancho, wrap]].
     *
     * @param PdfColumnProfile $perfil
     * @return array
     */
    private function columnas_visibles(PdfColumnProfile $perfil)
    {
        $columnas = [];
        foreach ($perfil->pdf_column_options()->orderBy('pdf_column_option_profile.order')->get() as $opcion) {
            if ($opcion->pivot->visible) {
                $columnas[$opcion->value_resolver] = [(int) $opcion->pivot->width, (bool) $opcion->pivot->wrap_content];
            }
        }

        return $columnas;
    }

    /**
     * Una lista de seeders privada de un setup.
     *
     * @param string $clase
     * @return array<int, string>
     */
    private function seeders_de($clase)
    {
        $metodo = new \ReflectionMethod($clase, 'base_seeders');
        $metodo->setAccessible(true);

        $lista = $metodo->getNumberOfParameters() > 0 ? $metodo->invoke(null, ['business_type' => 'ferreteria']) : $metodo->invoke(null);

        return array_values(array_filter($lista, 'is_string'));
    }

    /**
     * @test
     */
    public function crea_el_ticket_remito_y_el_ticket_factura_sin_diseno_en_80_mm()
    {
        $dueno = $this->dueno();

        $resultado = PdfTicketComanderaSetupHelper::apply_for_owner($dueno->id);
        $this->assertSame(['Ticket remito', 'Ticket factura'], $resultado['creados']);

        $tickets = $this->tickets($dueno->id);
        $this->assertCount(2, $tickets);

        foreach ($tickets as $i => $ticket) {
            $this->assertSame($i === 0 ? 'Ticket remito' : 'Ticket factura', $ticket->name);
            $this->assertSame($i === 1, $ticket->is_afip_ticket);
            /**
             * Cambio de especificación (revisor de merge, 9/10/2026): nace SIN is_default para que el
             * código viejo, que elige el PDF por defecto con where('is_default') sin mirar el tipo de
             * hoja, no lo tome por un remito A4. El código nuevo lo elige igual (el de menor id).
             */
            $this->assertFalse($ticket->is_default, 'No nace por defecto: el código viejo lo tomaría por la hoja por defecto.');
            $this->assertNull($ticket->page_layout, 'Nace sin diseño: imprime el Ticket 2.0 de siempre (D1).');
            $this->assertSame('Ticket 80 mm', $ticket->sheet_type->name);
            $this->assertNull($ticket->sheet_type->user_id);
            $this->assertSame([80, 80, 0], [(int) $ticket->paper_width_mm, (int) $ticket->printable_width_mm, (int) $ticket->margin_mm]);
            $this->assertFalse($ticket->is_default_whatsapp);
            $this->assertFalse($ticket->is_default_tienda);

            /**
             * Nombre (con salto), Cant, Precio y Sub total en 9/3/6/6 medias sobre 80 mm (cambio de
             * especificación del 9/10/2026: con 10/2/6/6 "Cant" quedaba en 3 caracteres).
             */
            $this->assertSame([
                'item_name' => [30, true],
                'item_amount' => [10, false],
                'item_price' => [20, false],
                'item_subtotal' => [20, false],
            ], $this->columnas_visibles($ticket));
        }

        /** El resto del catálogo de venta queda, no visible. */
        $this->assertGreaterThan(4, $tickets[0]->pdf_column_options()->count());
    }

    /**
     * @test
     */
    public function un_dueno_con_un_rollo_chico_configurado_recibe_los_de_55_mm()
    {
        $dueno = $this->dueno(['sale_ticket_width' => 58]);

        PdfTicketComanderaSetupHelper::apply_for_owner($dueno->id);

        foreach ($this->tickets($dueno->id) as $ticket) {
            $this->assertSame('Ticket 55 mm', $ticket->sheet_type->name);
            $this->assertSame(55, (int) $ticket->paper_width_mm);

            $anchos = array_column($this->columnas_visibles($ticket), 0);
            /** round(9/3/6/6 × 55 / 24) = 21/7/14/14 = 56: el mm que sobra se le saca a Nombre (la que más subió). */
            $this->assertSame([20, 7, 14, 14], $anchos);
            $this->assertLessThanOrEqual(55, array_sum($anchos), 'La suma no pasa del rollo: no depende de la tolerancia.');

            /** El diseñador los vuelve a leer como 9/3/6/6 medias columnas (D9). */
            $medias = [];
            foreach ($anchos as $ancho) {
                $medias[] = max(1, (int) round($ancho * 24 / 55));
            }
            $this->assertSame([9, 3, 6, 6], $medias);
        }
    }

    /**
     * @test
     */
    public function es_idempotente_y_respeta_lo_que_el_dueno_ya_tiene()
    {
        $dueno = $this->dueno();
        $hoja = $this->perfil_de_hoja($dueno->id, false, ['name' => 'Remito', 'is_default' => true]);

        PdfTicketComanderaSetupHelper::apply_for_owner($dueno->id);
        $segunda = PdfTicketComanderaSetupHelper::apply_for_owner($dueno->id);

        $this->assertSame([], $segunda['creados']);
        $this->assertCount(2, $this->tickets($dueno->id));
        $this->assertTrue($hoja->fresh()->is_default, 'El por defecto de hoja no se toca (D5).');

        /** Un dueño que ya armó su ticket de remito solo recibe el de factura. */
        $otro = $this->dueno();
        $propio = $this->perfil_de_ticket($otro->id, false, ['name' => 'Mi comanda']);

        $this->assertSame(['Ticket factura'], PdfTicketComanderaSetupHelper::apply_for_owner($otro->id)['creados']);
        $this->assertSame([$propio->id], $this->tickets($otro->id)->where('is_afip_ticket', false)->pluck('id')->values()->all());
    }

    /**
     * @test
     */
    public function el_seeder_suelto_recorre_solo_duenos_y_corrido_dos_veces_no_duplica()
    {
        $dueno = $this->dueno();
        $empleado = $this->dueno(['owner_id' => $dueno->id]);

        $this->assertSame('no_es_owner', PdfTicketComanderaSetupHelper::apply_for_owner($empleado->id)['skipped_reason']);

        (new PdfTicketComanderaSeeder())->run();
        (new PdfTicketComanderaSeeder())->run();

        $this->assertCount(2, $this->tickets($dueno->id));
        $this->assertCount(0, $this->tickets($empleado->id), 'Un empleado no tiene diseños propios.');
    }

    /**
     * @test
     */
    public function esta_en_los_dos_setups_despues_de_los_perfiles_de_venta_y_en_database_seeder()
    {
        foreach ([UserSetupHelper::class, DemoSetupHelper::class] as $clase) {
            $lista = $this->seeders_de($clase);

            $this->assertContains('PdfTicketComanderaSeeder', $lista, $clase);
            $this->assertGreaterThan(array_search('PdfColumnProfileSeeder', $lista), array_search('PdfTicketComanderaSeeder', $lista), $clase);
            $this->assertGreaterThan(array_search('SheetTypeSeeder', $lista), array_search('PdfTicketComanderaSeeder', $lista), $clase);
        }

        $this->assertStringContainsString(
            '$this->call(PdfTicketComanderaSeeder::class);',
            file_get_contents(base_path('database/seeders/DatabaseSeeder.php'))
        );
    }

    /**
     * El código VIEJO (el frente que sigue sirviendo durante el despliegue, las pestañas abiertas y los
     * comercios de una base compartida que siguen en una versión anterior) elige el PDF de una venta
     * con `where('is_default', true)` sin mirar el tipo de hoja. Después de sembrar, esa consulta no
     * puede devolver un ticket; y el ticket por defecto del código nuevo tiene que seguir siendo el
     * sembrado (revisor de merge, 9/10/2026).
     *
     * @test
     */
    public function despues_de_sembrar_el_codigo_viejo_no_toma_un_ticket_por_el_pdf_por_defecto()
    {
        $dueno = $this->dueno();
        $this->perfil_de_hoja($dueno->id, false, ['name' => 'Remito']);
        $this->perfil_de_hoja($dueno->id, true, ['name' => 'Factura comun']);

        PdfTicketComanderaSetupHelper::apply_for_owner($dueno->id);

        foreach ([false, true] as $es_factura) {
            /** La consulta de PdfColumnService::get_profile_for_print de antes de la misión, tal cual. */
            $viejo = PdfColumnProfile::where('user_id', $dueno->id)
                ->where('model_name', 'sale')
                ->where('is_afip_ticket', $es_factura)
                ->where('is_default', true)
                ->first();

            $this->assertNull($viejo, 'Ningún perfil de venta queda por defecto: el código viejo sigue cayendo en la hoja de siempre.');

            $por_defecto = \App\Http\Controllers\Helpers\sale\SaleTicketComanderaHelper::ticket_por_defecto($dueno->id, $es_factura);
            $this->assertNotNull($por_defecto);
            $this->assertSame($es_factura ? 'Ticket factura' : 'Ticket remito', $por_defecto->name, 'El código nuevo elige el ticket sembrado.');
        }
    }
}
