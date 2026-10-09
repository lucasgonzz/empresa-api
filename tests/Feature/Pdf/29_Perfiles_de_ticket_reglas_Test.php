<?php

namespace Tests\Feature\Pdf;

use App\Models\PdfColumnOption;
use App\Models\PdfColumnProfile;
use App\Models\User;
use App\Services\PdfColumnService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Pdf\Concerns\PerfilesDeTicketDeComandera;
use Tests\TestCase;

/**
 * Las reglas de un diseño de PDF que es un TICKET DE COMANDERA en el ABM (misión
 * diseno-ticket-comandera, 9/10/2026, contrato §3.2): lo que la API fuerza, el 422 de un ticket que
 * no es de venta, el "por defecto" por clase (D5), el cambio de clase ticket ↔ hoja, el duplicado y
 * la tolerancia de la grilla de 24 medias columnas en la suma de anchos. Contra los endpoints reales
 * de pdf-column-profiles y el estado en la base.
 *
 * @group pdf-ticket-comandera
 */
class Perfiles_de_ticket_reglas_Test extends TestCase
{
    use DatabaseTransactions;
    use PerfilesDeTicketDeComandera;

    const URL = 'api/pdf-column-profiles';

    /** @var \App\Models\User */
    protected $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::create([
            'name' => 'Dueno reglas de ticket',
            'company_name' => 'Dueno reglas de ticket',
            'email' => 'reglas-ticket-'.uniqid().'@test.local',
            'password' => 'x',
        ]);

        $this->actingAs($this->dueno, 'web');
        PdfColumnService::sync_catalog_options('sale');
    }

    /**
     * Una columna de venta del catálogo, con su pivot.
     *
     * @param string $resolver
     * @param int    $ancho
     * @param int    $orden
     * @param bool   $wrap
     * @return array
     */
    private function columna($resolver, $ancho, $orden = 0, $wrap = false)
    {
        $opcion = PdfColumnOption::where('model_name', 'sale')->where('value_resolver', $resolver)->first();
        $this->assertNotNull($opcion);

        return ['id' => $opcion->id, 'pivot' => ['visible' => true, 'order' => $orden, 'width' => $ancho, 'wrap_content' => $wrap]];
    }

    /**
     * El cuerpo de un alta, como lo manda el formulario del ABM (la hoja vieja del formulario).
     *
     * @param array $extra
     * @return array
     */
    private function alta(array $extra = [])
    {
        return array_merge([
            'model_name' => 'sale',
            'name' => 'zz Diseño '.uniqid(),
            'paper_width_mm' => 210,
            'printable_width_mm' => 210,
            'margin_mm' => 5,
            'is_default' => false,
            'is_afip_ticket' => false,
            'pdf_column_options' => [$this->columna('item_name', 33, 0, true)],
        ], $extra);
    }

    /**
     * Lo que el formulario genérico reenvía en un PUT: el modelo entero tal como está guardado.
     *
     * @param PdfColumnProfile $perfil
     * @param array            $cambios
     * @return array
     */
    private function eco_del_formulario(PdfColumnProfile $perfil, array $cambios)
    {
        $perfil = $perfil->fresh();

        return array_merge([
            'model_name' => $perfil->model_name,
            'name' => $perfil->name,
            'paper_width_mm' => $perfil->paper_width_mm,
            'printable_width_mm' => $perfil->printable_width_mm,
            'margin_mm' => $perfil->margin_mm,
            'sheet_type_id' => $perfil->sheet_type_id,
            'is_afip_ticket' => $perfil->is_afip_ticket,
            'is_default' => $perfil->is_default,
            'page_layout' => $perfil->page_layout,
        ], $cambios);
    }

    /**
     * Un diseño de prueba con una caja.
     *
     * @param string $key
     * @return array
     */
    private function diseno($key = 'venta_numero')
    {
        return [
            'version' => 1,
            'superior' => [['tipo' => 'caja', 'id' => 'caja_a', 'cols' => 12, 'titulo' => '', 'estilo' => 'borde', 'campos' => [['key' => $key]]]],
            'pie' => [],
        ];
    }

    /**
     * @test
     */
    public function un_ticket_fuerza_el_papel_del_rollo_y_apaga_lo_que_es_de_un_pdf()
    {
        $rollo = $this->rollo_del_sistema(80);

        $id = $this->postJson(self::URL, $this->alta([
            'sheet_type_id' => $rollo->id,
            'paper_height_mm' => 297,
            'is_default_whatsapp' => true,
            'is_default_whatsapp_afip' => true,
            'is_default_tienda' => true,
            'show_totals_on_each_page' => true,
        ]))->assertStatus(201)->json('model.id');

        $perfil = PdfColumnProfile::find($id);
        $this->assertSame(80, (int) $perfil->paper_width_mm);
        $this->assertSame(80, (int) $perfil->printable_width_mm);
        $this->assertSame(0, (int) $perfil->margin_mm);
        $this->assertNull($perfil->paper_height_mm);
        $this->assertFalse($perfil->is_default_whatsapp);
        $this->assertFalse($perfil->is_default_whatsapp_afip);
        $this->assertFalse($perfil->is_default_tienda);
        $this->assertFalse($perfil->show_totals_on_each_page);
        $this->assertTrue($perfil->es_ticket());

        /** Un PUT que intenta prenderlos tampoco puede. */
        $this->putJson(self::URL.'/'.$id, ['is_default_tienda' => true, 'margin_mm' => 5, 'paper_width_mm' => 210])->assertStatus(200);
        $perfil = $perfil->fresh();
        $this->assertFalse($perfil->is_default_tienda);
        $this->assertSame(0, (int) $perfil->margin_mm);
        $this->assertSame(80, (int) $perfil->paper_width_mm);
    }

    /**
     * @test
     */
    public function solo_un_diseno_de_venta_puede_ser_ticket_y_el_rollo_tiene_que_ser_del_dueno()
    {
        $rollo = $this->rollo_del_sistema(80);
        $antes = PdfColumnProfile::where('user_id', $this->dueno->id)->count();

        PdfColumnService::sync_catalog_options('budget');
        $opcion_de_presupuesto = PdfColumnOption::where('model_name', 'budget')->where('value_resolver', 'document_item_name')->first();

        $this->postJson(self::URL, $this->alta([
            'model_name' => 'budget',
            'sheet_type_id' => $rollo->id,
            'pdf_column_options' => [['id' => $opcion_de_presupuesto->id, 'pivot' => ['visible' => true, 'order' => 0, 'width' => 30]]],
        ]))->assertStatus(422)->assertJsonStructure(['message']);

        $otro = User::create(['name' => 'Otro', 'company_name' => 'Otro', 'email' => 'reglas-otro-'.uniqid().'@test.local', 'password' => 'x']);
        $ajeno = $this->rollo_propio($otro->id, 72);

        $this->postJson(self::URL, $this->alta(['sheet_type_id' => $ajeno->id]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['sheet_type_id']]);

        $this->assertSame($antes, PdfColumnProfile::where('user_id', $this->dueno->id)->count(), 'Un 422 no crea nada.');

        /** Un rollo propio del dueño, sí. */
        $this->postJson(self::URL, $this->alta(['sheet_type_id' => $this->rollo_propio($this->dueno->id, 72)->id]))->assertStatus(201);
    }

    /**
     * @test
     */
    public function el_por_defecto_es_uno_por_clase()
    {
        $rollo = $this->rollo_del_sistema(80)->id;
        $a4 = $this->hoja_a4()->id;

        $hoja = $this->postJson(self::URL, $this->alta(['sheet_type_id' => $a4, 'is_default' => true]))->assertStatus(201)->json('model.id');
        $remito = $this->postJson(self::URL, $this->alta(['sheet_type_id' => $rollo, 'is_default' => true]))->assertStatus(201)->json('model.id');
        $factura = $this->postJson(self::URL, $this->alta(['sheet_type_id' => $rollo, 'is_default' => true, 'is_afip_ticket' => true]))->assertStatus(201)->json('model.id');

        /** Los tres conviven: hoja, ticket remito y ticket factura. */
        foreach ([$hoja, $remito, $factura] as $id) {
            $this->assertTrue(PdfColumnProfile::find($id)->is_default);
        }

        /** Otro ticket remito por defecto apaga SOLO al ticket remito anterior. */
        $remito_2 = $this->postJson(self::URL, $this->alta(['sheet_type_id' => $rollo, 'is_default' => true]))->assertStatus(201)->json('model.id');
        $this->assertFalse(PdfColumnProfile::find($remito)->is_default);
        $this->assertTrue(PdfColumnProfile::find($remito_2)->is_default);
        $this->assertTrue(PdfColumnProfile::find($factura)->is_default);
        $this->assertTrue(PdfColumnProfile::find($hoja)->is_default);

        /** Una hoja por defecto (por PUT) apaga solo a las hojas. */
        $hoja_2 = $this->postJson(self::URL, $this->alta(['sheet_type_id' => $a4]))->assertStatus(201)->json('model.id');
        $this->putJson(self::URL.'/'.$hoja_2, ['is_default' => true])->assertStatus(200);
        $this->assertFalse(PdfColumnProfile::find($hoja)->is_default);
        $this->assertTrue(PdfColumnProfile::find($remito_2)->is_default);
        $this->assertTrue(PdfColumnProfile::find($factura)->is_default);

        /** Un diseño sin tipo de hoja es hoja (D12): también lo apaga. */
        $sin_tipo = $this->postJson(self::URL, $this->alta(['is_default' => true]))->assertStatus(201)->json('model.id');
        $this->assertFalse(PdfColumnProfile::find($hoja_2)->is_default);
        $this->assertTrue(PdfColumnProfile::find($sin_tipo)->is_default);
        $this->assertTrue(PdfColumnProfile::find($remito_2)->is_default);
    }

    /**
     * @test
     */
    public function al_cambiar_de_clase_el_diseno_vuelve_al_de_siempre_y_el_papel_se_acomoda()
    {
        $rollo = $this->rollo_del_sistema(80);
        $hoja = $this->perfil_de_hoja($this->dueno->id, false, ['page_layout' => $this->diseno()]);

        /** Hoja → ticket con el diseño de hoja reenviado por el formulario: vuelve a null. */
        $this->putJson(self::URL.'/'.$hoja->id, $this->eco_del_formulario($hoja, ['sheet_type_id' => $rollo->id]))->assertStatus(200);
        $ticket = $hoja->fresh();
        $this->assertTrue($ticket->es_ticket());
        $this->assertNull($ticket->page_layout, 'El diseño de hoja no sirve en el rollo: sale el Ticket 2.0 de siempre.');
        $this->assertSame(80, (int) $ticket->paper_width_mm);

        /**
         * Las columnas de la A4 (100/20/40/40 sobre 200 mm útiles = 12/2/5/5 medias) pasan al rollo
         * conservando sus medias columnas (D9): 40/7/17/17 sobre 80.
         */
        $anchos = [];
        foreach ($ticket->pdf_column_options()->orderBy('pdf_column_option_profile.order')->get() as $opcion) {
            if ($opcion->pivot->visible) {
                $anchos[] = (int) $opcion->pivot->width;
            }
        }
        $this->assertSame([40, 7, 17, 17], $anchos);

        /** Ticket → hoja con el 80/80/0 que reenvía el formulario: A4 con margen 5. */
        $ticket->page_layout = $this->diseno();
        $ticket->save();
        $this->putJson(self::URL.'/'.$ticket->id, $this->eco_del_formulario($ticket, ['sheet_type_id' => $this->hoja_a4()->id]))->assertStatus(200);
        $de_vuelta = $ticket->fresh();
        $this->assertFalse($de_vuelta->es_ticket());
        $this->assertNull($de_vuelta->page_layout);
        $this->assertSame([210, 210, 5], [(int) $de_vuelta->paper_width_mm, (int) $de_vuelta->printable_width_mm, (int) $de_vuelta->margin_mm]);

        /** Ticket → hoja trayendo su hoja (Carta) y un diseño propio: se respetan. */
        $otro_ticket = $this->perfil_de_ticket($this->dueno->id, false, ['page_layout' => $this->diseno()]);
        $this->putJson(self::URL.'/'.$otro_ticket->id, [
            'sheet_type_id' => $this->hoja_a4()->id,
            'paper_width_mm' => 216,
            'printable_width_mm' => 216,
            'margin_mm' => 8,
            'page_layout' => $this->diseno('cliente_nombre'),
        ])->assertStatus(200);
        $carta = $otro_ticket->fresh();
        $this->assertSame(216, (int) $carta->paper_width_mm);
        $this->assertSame(8, (int) $carta->margin_mm);
        $this->assertSame('cliente_nombre', $carta->page_layout['superior'][0]['campos'][0]['key']);

        /** Un PUT que no cambia de clase no toca el diseño (un renombre, por ejemplo). */
        $this->putJson(self::URL.'/'.$otro_ticket->id, ['name' => 'zz renombrado'])->assertStatus(200);
        $this->assertNotNull($otro_ticket->fresh()->page_layout);
    }

    /**
     * @test
     */
    public function un_ticket_de_factura_guarda_los_tres_fijos_y_una_hoja_no_el_del_emisor()
    {
        $ticket = $this->perfil_de_ticket($this->dueno->id, true);
        $this->putJson(self::URL.'/'.$ticket->id, ['page_layout' => $this->diseno()])->assertStatus(200);

        $diseno = $ticket->fresh()->page_layout;
        $this->assertSame('afip_emisor', $diseno['superior'][0]['key']);
        $this->assertSame('afip_receptor', end($diseno['superior'])['key']);
        $this->assertSame(12, end($diseno['superior'])['cols']);
        $this->assertSame('afip_pie', end($diseno['pie'])['key']);

        $hoja = $this->perfil_de_hoja($this->dueno->id, true);
        $con_emisor = $this->diseno();
        array_unshift($con_emisor['superior'], ['tipo' => 'fijo', 'key' => 'afip_emisor']);
        $this->putJson(self::URL.'/'.$hoja->id, ['page_layout' => $con_emisor])->assertStatus(200);

        $keys = array_column(array_filter($hoja->fresh()->page_layout['superior'], function ($item) {
            return $item['tipo'] === 'fijo';
        }), 'key');
        $this->assertSame(['afip_receptor'], array_values($keys), 'En la hoja el emisor lo imprime el encabezado.');
    }

    /**
     * @test
     */
    public function el_duplicado_conserva_el_tipo_de_hoja()
    {
        $ticket = $this->perfil_de_ticket($this->dueno->id, false, [], 55);

        $copia = $this->postJson(self::URL.'/'.$ticket->id.'/duplicate')->assertStatus(201)->json('model');

        $this->assertSame($ticket->sheet_type_id, $copia['sheet_type_id']);
        $this->assertSame('Ticket 55 mm', $copia['sheet_type']['name']);
        $this->assertTrue(PdfColumnProfile::find($copia['id'])->es_ticket());
    }

    /**
     * @test
     */
    public function la_suma_de_anchos_tolera_el_redondeo_de_la_grilla_de_24()
    {
        $rollo = $this->rollo_del_sistema(55);

        /** 10/2/6/6 medias sobre 55 mm, guardadas con el redondeo del diseñador: 23 + 5 + 14 + 14 = 56 mm. */
        $this->postJson(self::URL, $this->alta([
            'sheet_type_id' => $rollo->id,
            'pdf_column_options' => [
                $this->columna('item_name', 23, 0, true),
                $this->columna('item_amount', 5, 1),
                $this->columna('item_price', 14, 2),
                $this->columna('item_subtotal', 14, 3),
            ],
        ]))->assertStatus(201);

        /** Una tabla que de verdad no entra sigue dando 422 (200 mm útiles en la A4). */
        $this->postJson(self::URL, $this->alta([
            'sheet_type_id' => $this->hoja_a4()->id,
            'pdf_column_options' => [
                $this->columna('item_name', 150, 0, true),
                $this->columna('item_amount', 100, 1),
            ],
        ]))->assertStatus(422)->assertJsonStructure(['errors' => ['pdf_column_options']]);
    }
}
