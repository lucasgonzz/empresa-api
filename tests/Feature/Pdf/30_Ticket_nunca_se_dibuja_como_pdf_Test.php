<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\Helpers\PdfColumnProfileWhatsappDefaultHelper;
use App\Http\Controllers\Helpers\PdfColumnRemitoSetupHelper;
use App\Http\Controllers\Pdf\SaleLayoutPdf;
use App\Models\PdfColumnProfile;
use App\Models\Sale;
use App\Models\User;
use App\Services\PdfColumnService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Pdf\Concerns\PerfilesDeTicketDeComandera;
use Tests\TestCase;

/**
 * Decisión D4 de la misión diseno-ticket-comandera (9/10/2026): un perfil de ticket de comandera
 * se imprime directo en la impresora, NUNCA se dibuja como PDF. Toda resolución de perfil para un
 * PDF (el por defecto, uno pedido por id, el de la tienda, el de WhatsApp) excluye los tickets, y
 * el ajuste de columnas de los remitos A4 no los toca.
 *
 * @group pdf-ticket-comandera
 */
class Ticket_nunca_se_dibuja_como_pdf_Test extends TestCase
{
    use DatabaseTransactions;
    use PerfilesDeTicketDeComandera;

    /** @var \App\Models\User */
    protected $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::create([
            'name' => 'Dueno D4',
            'company_name' => 'Dueno D4',
            'email' => 'd4-'.uniqid().'@test.local',
            'password' => 'x',
        ]);
        $this->actingAs($this->dueno, 'web');
    }

    /**
     * @test
     */
    public function el_por_defecto_de_un_pdf_nunca_es_un_ticket_ni_pedido_por_id()
    {
        /** El ticket es el más viejo y el "por defecto": igual no puede salir como PDF. */
        $ticket = $this->perfil_de_ticket($this->dueno->id, false, ['is_default' => true, 'is_default_tienda' => true]);
        $hoja = $this->perfil_de_hoja($this->dueno->id, false);

        $this->assertSame($hoja->id, PdfColumnService::get_profile_for_print($this->dueno->id, 'sale')->id);
        $this->assertSame($hoja->id, PdfColumnService::get_profile_for_print($this->dueno->id, 'sale', null, false)->id);

        /** Pedido por id: se ignora y cae al por defecto de hoja. */
        $this->assertSame($hoja->id, PdfColumnService::get_profile_for_print($this->dueno->id, 'sale', $ticket->id, false)->id);

        /** La tienda (origin=tienda) tampoco: aunque un dato viejo tenga el ticket como "Predeterminado Tienda". */
        $this->assertSame($hoja->id, PdfColumnService::get_profile_for_print($this->dueno->id, 'sale', null, false, 'is_default_tienda')->id);

        /** Un diseño sin tipo de hoja es hoja (D12). */
        $sin_tipo = $this->perfil_de_hoja($this->dueno->id, false, ['sheet_type_id' => null, 'is_default' => true]);
        $this->assertSame($sin_tipo->id, PdfColumnService::get_profile_for_print($this->dueno->id, 'sale')->id);
    }

    /**
     * @test
     */
    public function la_factura_pdf_tampoco_toma_un_ticket_de_factura()
    {
        $ticket = $this->perfil_de_ticket($this->dueno->id, true, ['is_default' => true]);

        /** Solo el ticket de factura: no hay perfil para el PDF (sale el de siempre sin perfil). */
        $this->assertNull(PdfColumnService::get_profile_for_print($this->dueno->id, 'sale', $ticket->id, true));

        $hoja = $this->perfil_de_hoja($this->dueno->id, true);
        $this->assertSame($hoja->id, PdfColumnService::get_profile_for_print($this->dueno->id, 'sale', $ticket->id, true)->id);
    }

    /**
     * @test
     */
    public function un_ticket_disenado_no_sale_como_venta_con_cajas()
    {
        $diseno = [
            'version' => 1,
            'superior' => [['tipo' => 'caja', 'id' => 'a', 'cols' => 12, 'titulo' => '', 'estilo' => 'borde', 'campos' => [['key' => 'venta_numero']]]],
            'pie' => [],
        ];
        $ticket = $this->perfil_de_ticket($this->dueno->id, false, ['is_default' => true, 'page_layout' => $diseno]);
        $hoja = $this->perfil_de_hoja($this->dueno->id, false, ['page_layout' => $diseno]);

        $venta = Sale::create(['num' => 1, 'user_id' => $this->dueno->id, 'total' => 100]);

        $perfil = SaleLayoutPdf::perfil_con_diseno($venta, $ticket->id, null, null);
        $this->assertNotNull($perfil);
        $this->assertSame($hoja->id, $perfil->id, 'El link de la venta con el id de un ticket dibuja el PDF de hoja.');

        $this->assertSame($hoja->id, SaleLayoutPdf::perfil_con_diseno($venta, $ticket->id, null, 'tienda')->id);
    }

    /**
     * @test
     */
    public function whatsapp_nunca_elige_un_ticket()
    {
        /** "Ticket factura" ganaba por nombre ("factura") si el dueño no tenía "Factura comun". */
        $this->perfil_de_ticket($this->dueno->id, true, ['name' => 'Ticket factura']);
        $this->perfil_de_ticket($this->dueno->id, false, ['name' => 'Remito']);

        $this->assertNull(PdfColumnProfileWhatsappDefaultHelper::resolve_factura_whatsapp_profile($this->dueno->id));
        $this->assertNull(PdfColumnProfileWhatsappDefaultHelper::resolve_remito_whatsapp_profile($this->dueno->id));

        $factura_a4 = $this->perfil_de_hoja($this->dueno->id, true, ['name' => 'Factura A4 especial']);
        $this->assertSame($factura_a4->id, PdfColumnProfileWhatsappDefaultHelper::resolve_factura_whatsapp_profile($this->dueno->id)->id);

        PdfColumnProfileWhatsappDefaultHelper::apply_whatsapp_defaults_for_owner($this->dueno->id);
        $this->assertSame(0, PdfColumnProfile::where('user_id', $this->dueno->id)->deTicket()
            ->where(function ($q) {
                $q->where('is_default_whatsapp', true)->orWhere('is_default_whatsapp_afip', true);
            })->count());
    }

    /**
     * @test
     */
    public function el_ajuste_de_remitos_a4_no_toca_un_ticket_llamado_remito()
    {
        $ticket = $this->perfil_de_ticket($this->dueno->id, false, ['name' => 'Remito']);
        $anchos_antes = $ticket->pdf_column_options()->pluck('pdf_column_option_profile.width', 'pdf_column_options.id')->all();

        PdfColumnRemitoSetupHelper::apply_for_owner($this->dueno->id);

        $this->assertSame($anchos_antes, $ticket->fresh()->pdf_column_options()->pluck('pdf_column_option_profile.width', 'pdf_column_options.id')->all(), 'Las columnas del rollo quedan como estaban.');

        /** El remito A4 que falta se crea aparte, como hoja. */
        $remito_a4 = PdfColumnProfile::where('user_id', $this->dueno->id)->where('name', 'Remito')->deHoja()->first();
        $this->assertNotNull($remito_a4);
        $this->assertNotSame($ticket->id, $remito_a4->id);
    }
}
