<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\Helpers\PdfColumnProfileTicketHelper;
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

        /**
         * Hoja → ticket con el diseño de hoja reenviado por el formulario: el diseño de hoja no sirve
         * en el rollo y queda el DERIVADO del ticket (cambio de especificación del 9/10/2026: antes
         * quedaba null, que imprime el Ticket 2.0 de siempre al ancho del puesto).
         */
        $this->putJson(self::URL.'/'.$hoja->id, $this->eco_del_formulario($hoja, ['sheet_type_id' => $rollo->id]))->assertStatus(200);
        $ticket = $hoja->fresh();
        $this->assertTrue($ticket->es_ticket());
        $this->assertEquals(PdfColumnProfileTicketHelper::derivado_del_ticket(false), $ticket->page_layout);
        $this->assertSame(80, (int) $ticket->paper_width_mm);

        /**
         * Las columnas de la A4 (100/20/40/40 sobre 200 mm útiles = 12/2/5/5 medias) pasan al rollo
         * conservando sus medias columnas (D9): 40/7/17/17 = 81 sobre 80, y con la regla del
         * diseñador (cambio de especificación del 9/10/2026: la suma no pasa del útil) se le saca
         * 1 mm a la que más subió al redondear; en el empate, a la más ancha (Precio): 40/7/16/17.
         */
        $anchos = [];
        foreach ($ticket->pdf_column_options()->orderBy('pdf_column_option_profile.order')->get() as $opcion) {
            if ($opcion->pivot->visible) {
                $anchos[] = (int) $opcion->pivot->width;
            }
        }
        $this->assertSame([40, 7, 16, 17], $anchos);
        $this->assertSame(80, array_sum($anchos));

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

        /**
         * Al borde del redondeo (cambio de especificación del 9/10/2026: la tolerancia es
         * ceil(columnas / 2) mm, no "lo que entre en 24 medias"): 4 columnas en una A4 de 200 útiles
         * pueden sumar hasta 202.
         */
        $a4 = $this->hoja_a4()->id;
        $this->postJson(self::URL, $this->alta([
            'sheet_type_id' => $a4,
            'pdf_column_options' => [
                $this->columna('item_name', 101, 0, true),
                $this->columna('item_amount', 33, 1),
                $this->columna('item_price', 34, 2),
                $this->columna('item_subtotal', 34, 3),
            ],
        ]))->assertStatus(201);

        /**
         * Una tabla armada a mano en mm (anchos que no son de la grilla: 15 y 30 no están a 1 mm de
         * una media columna de 8,33) sigue con la regla exacta: 201 en 200 es 422, como en ChatIa/29.
         */
        $this->postJson(self::URL, $this->alta([
            'sheet_type_id' => $a4,
            'pdf_column_options' => [
                $this->columna('row_index', 8, 0),
                $this->columna('item_id', 15, 1),
                $this->columna('item_bar_code', 30, 2),
                $this->columna('item_name', 133, 3, true),
                $this->columna('item_amount', 15, 4),
            ],
        ]))->assertStatus(422)->assertJsonStructure(['errors' => ['pdf_column_options']]);

        /** Un mm más (203) ya no es redondeo. */
        $this->postJson(self::URL, $this->alta([
            'sheet_type_id' => $a4,
            'pdf_column_options' => [
                $this->columna('item_name', 101, 0, true),
                $this->columna('item_amount', 33, 1),
                $this->columna('item_price', 34, 2),
                $this->columna('item_subtotal', 35, 3),
            ],
        ]))->assertStatus(422)->assertJsonStructure(['errors' => ['pdf_column_options']]);

        /**
         * Muchas columnas angostas que suman 240 mm en 200 útiles: la primera versión las aceptaba
         * porque cada una "entraba" en una o dos medias columnas; ahora dan 422.
         */
        $angostas = [];
        $orden = 0;
        foreach (['row_index', 'item_id', 'item_bar_code', 'item_provider_code', 'item_name', 'item_amount', 'item_cost', 'item_cost_total', 'item_price_without_iva', 'item_subtotal_without_iva', 'item_iva_amount', 'item_price_with_iva'] as $resolver) {
            $angostas[] = $this->columna($resolver, 20, $orden++);
        }
        $this->postJson(self::URL, $this->alta(['sheet_type_id' => $a4, 'pdf_column_options' => $angostas]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['pdf_column_options']]);

        /** Una tabla que de verdad no entra sigue dando 422 (200 mm útiles en la A4). */
        $this->postJson(self::URL, $this->alta([
            'sheet_type_id' => $this->hoja_a4()->id,
            'pdf_column_options' => [
                $this->columna('item_name', 150, 0, true),
                $this->columna('item_amount', 100, 1),
            ],
        ]))->assertStatus(422)->assertJsonStructure(['errors' => ['pdf_column_options']]);
    }

    /**
     * Los anchos (mm) de las columnas de un perfil por value_resolver, visibles y ocultas.
     *
     * @param PdfColumnProfile $perfil
     * @return array<string, array{0: int, 1: bool}> resolver => [ancho, visible]
     */
    private function anchos_por_columna(PdfColumnProfile $perfil)
    {
        $anchos = [];
        foreach ($perfil->fresh()->pdf_column_options()->orderBy('pdf_column_option_profile.order')->get() as $opcion) {
            $anchos[$opcion->value_resolver] = [(int) $opcion->pivot->width, (bool) $opcion->pivot->visible];
        }

        return $anchos;
    }

    /**
     * Las columnas de un perfil en la forma del pedido, con otros anchos (como las manda el SPA ya
     * convertidas al papel nuevo).
     *
     * @param PdfColumnProfile     $perfil
     * @param array<string, int>   $anchos resolver => mm
     * @return array
     */
    private function columnas_convertidas(PdfColumnProfile $perfil, array $anchos)
    {
        $opciones = [];
        foreach ($perfil->fresh()->pdf_column_options()->orderBy('pdf_column_option_profile.order')->get() as $opcion) {
            $opciones[] = [
                'id' => $opcion->id,
                'pivot' => [
                    'visible' => (bool) $opcion->pivot->visible,
                    'order' => (int) $opcion->pivot->order,
                    'width' => isset($anchos[$opcion->value_resolver]) ? $anchos[$opcion->value_resolver] : (int) $opcion->pivot->width,
                    'wrap_content' => (bool) $opcion->pivot->wrap_content,
                ],
            ];
        }

        return $opciones;
    }

    /**
     * Un ticket de 80 mm con 30/10/20/20 (9/3/6/6 medias) y una columna oculta de 30 mm.
     *
     * @return PdfColumnProfile
     */
    private function ticket_80_de_30_10_20_20()
    {
        $ticket = $this->perfil_de_ticket($this->dueno->id, false, [], 80, [
            'item_name' => [30, true],
            'item_amount' => [10, false],
            'item_price' => [20, false],
            'item_subtotal' => [20, false],
        ]);

        $oculta = PdfColumnOption::where('model_name', 'sale')->where('value_resolver', 'item_bar_code')->first();
        $ticket->pdf_column_options()->attach($oculta->id, ['visible' => false, 'order' => 9, 'width' => 30, 'wrap_content' => false]);

        return $ticket->fresh();
    }

    /**
     * @test
     */
    public function ticket_80_a_a4_sin_columnas_las_reescala_y_con_columnas_convertidas_las_respeta()
    {
        $a4 = $this->hoja_a4()->id;

        /** Sin columnas en el pedido (SPA viejo, PUT parcial): desde el útil viejo, 9/3/6/6 sobre 200. */
        $sin = $this->ticket_80_de_30_10_20_20();
        $this->putJson(self::URL.'/'.$sin->id, ['sheet_type_id' => $a4])->assertStatus(200);
        $anchos = $this->anchos_por_columna($sin);
        $this->assertSame([75, true], $anchos['item_name']);
        $this->assertSame([25, true], $anchos['item_amount']);
        $this->assertSame([50, true], $anchos['item_price']);
        $this->assertSame([50, true], $anchos['item_subtotal']);
        $this->assertSame([75, false], $anchos['item_bar_code'], 'La oculta conserva sus 9 medias columnas.');

        /** Con las columnas ya convertidas por el SPA: tal cual (antes "Cant" terminaba en 1 mm). */
        $con = $this->ticket_80_de_30_10_20_20();
        $this->putJson(self::URL.'/'.$con->id, [
            'sheet_type_id' => $a4,
            'pdf_column_options' => $this->columnas_convertidas($con, ['item_name' => 76, 'item_amount' => 24, 'item_price' => 50, 'item_subtotal' => 50]),
        ])->assertStatus(200);
        $anchos = $this->anchos_por_columna($con);
        $this->assertSame([76, 24, 50, 50], [$anchos['item_name'][0], $anchos['item_amount'][0], $anchos['item_price'][0], $anchos['item_subtotal'][0]]);
        $this->assertSame([30, false], $anchos['item_bar_code'], 'Si no se reescalan las visibles, la oculta tampoco.');
    }

    /**
     * @test
     */
    public function a4_a_ticket_80_sin_columnas_las_reescala_y_con_columnas_convertidas_las_respeta()
    {
        $rollo = $this->rollo_del_sistema(80)->id;
        $columnas_a4 = [
            'item_name' => [75, true],
            'item_amount' => [25, false],
            'item_price' => [50, false],
            'item_subtotal' => [50, false],
        ];

        $sin = $this->perfil_de_hoja($this->dueno->id, false);
        $sin->pdf_column_options()->detach();
        $this->adjuntar_columnas($sin, $columnas_a4);
        $this->putJson(self::URL.'/'.$sin->id, ['sheet_type_id' => $rollo])->assertStatus(200);
        $anchos = $this->anchos_por_columna($sin);
        $this->assertSame([30, 10, 20, 20], [$anchos['item_name'][0], $anchos['item_amount'][0], $anchos['item_price'][0], $anchos['item_subtotal'][0]]);

        /**
         * Con las columnas convertidas por el SPA, aunque el redondeo las pase 1 mm del rollo
         * (31/10/20/20 = 81): tal cual. Antes se las volvía a escalar como si estuvieran en la A4 y
         * la tabla quedaba en 34 mm.
         */
        $con = $this->perfil_de_hoja($this->dueno->id, false);
        $con->pdf_column_options()->detach();
        $this->adjuntar_columnas($con, $columnas_a4);
        $this->putJson(self::URL.'/'.$con->id, [
            'sheet_type_id' => $rollo,
            'pdf_column_options' => $this->columnas_convertidas($con, ['item_name' => 31, 'item_amount' => 10, 'item_price' => 20, 'item_subtotal' => 20]),
        ])->assertStatus(200);
        $anchos = $this->anchos_por_columna($con);
        $this->assertSame([31, 10, 20, 20], [$anchos['item_name'][0], $anchos['item_amount'][0], $anchos['item_price'][0], $anchos['item_subtotal'][0]]);
    }

    /**
     * @test
     */
    public function cambiar_el_ancho_del_rollo_reescala_las_columnas_y_conserva_el_diseno()
    {
        $rollo_55 = $this->rollo_del_sistema(55)->id;

        /**
         * 80 → 55 sin columnas (antes daba 422 por la suma de anchos): 9/3/6/6 medias sobre 55 son
         * 21/7/14/14 = 56, y el mm que sobra sale de Nombre (la que más subió): 20/7/14/14. Un ticket
         * sin diseño pasa al derivado (con null imprimiría al ancho del puesto).
         */
        $sin_diseno = $this->ticket_80_de_30_10_20_20();
        $this->putJson(self::URL.'/'.$sin_diseno->id, ['sheet_type_id' => $rollo_55])->assertStatus(200);
        $anchos = $this->anchos_por_columna($sin_diseno);
        $this->assertSame([20, 7, 14, 14], [$anchos['item_name'][0], $anchos['item_amount'][0], $anchos['item_price'][0], $anchos['item_subtotal'][0]]);
        $this->assertSame(55, (int) $sin_diseno->fresh()->paper_width_mm);
        $this->assertEquals(PdfColumnProfileTicketHelper::derivado_del_ticket(false), $sin_diseno->fresh()->page_layout);

        /** Con las columnas convertidas por el SPA, tal cual; un ticket diseñado conserva su diseño. */
        $disenado = $this->ticket_80_de_30_10_20_20();
        $disenado->page_layout = $this->diseno('cliente_nombre');
        $disenado->save();
        $this->putJson(self::URL.'/'.$disenado->id, $this->eco_del_formulario($disenado, [
            'sheet_type_id' => $rollo_55,
            'pdf_column_options' => $this->columnas_convertidas($disenado, ['item_name' => 20, 'item_amount' => 7, 'item_price' => 14, 'item_subtotal' => 14]),
        ]))->assertStatus(200);
        $anchos = $this->anchos_por_columna($disenado);
        $this->assertSame([20, 7, 14, 14], [$anchos['item_name'][0], $anchos['item_amount'][0], $anchos['item_price'][0], $anchos['item_subtotal'][0]]);
        $this->assertSame([30, false], $anchos['item_bar_code']);
        $this->assertSame('cliente_nombre', $disenado->fresh()->page_layout['superior'][0]['campos'][0]['key']);
    }

    /**
     * @test
     */
    public function un_ticket_nuevo_nace_con_el_derivado_y_volver_al_de_siempre_guarda_null()
    {
        $rollo = $this->rollo_del_sistema(80)->id;

        /** Sin diseño en el pedido: el derivado del ticket (remito y factura, con sus fijos). */
        $remito = $this->postJson(self::URL, $this->alta(['sheet_type_id' => $rollo]))->assertStatus(201)->json('model.id');
        $this->assertEquals(PdfColumnProfileTicketHelper::derivado_del_ticket(false), PdfColumnProfile::find($remito)->page_layout);

        $factura = $this->postJson(self::URL, $this->alta(['sheet_type_id' => $rollo, 'is_afip_ticket' => true, 'page_layout' => null]))->assertStatus(201)->json('model.id');
        $diseno_factura = PdfColumnProfile::find($factura)->page_layout;
        $this->assertEquals(PdfColumnProfileTicketHelper::derivado_del_ticket(true), $diseno_factura);
        $this->assertSame('afip_emisor', $diseno_factura['superior'][1]['key']);

        /** Con un diseño en el pedido, ese. */
        $propio = $this->postJson(self::URL, $this->alta(['sheet_type_id' => $rollo, 'page_layout' => $this->diseno('cliente_nombre')]))->assertStatus(201)->json('model.id');
        $this->assertSame('cliente_nombre', PdfColumnProfile::find($propio)->page_layout['superior'][0]['campos'][0]['key']);

        /** Una hoja nueva sin diseño sigue en null (el PDF de siempre). */
        $hoja = $this->postJson(self::URL, $this->alta(['sheet_type_id' => $this->hoja_a4()->id]))->assertStatus(201)->json('model.id');
        $this->assertNull(PdfColumnProfile::find($hoja)->page_layout);

        /** "Volver al ticket de siempre" (null explícito, sin cambiar de papel): null, como siempre. */
        $this->putJson(self::URL.'/'.$remito, ['page_layout' => null])->assertStatus(200);
        $this->assertNull(PdfColumnProfile::find($remito)->page_layout);

        /** El duplicado copia el diseño tal cual. */
        $copia = $this->postJson(self::URL.'/'.$factura.'/duplicate')->assertStatus(201)->json('model.id');
        $this->assertEquals($diseno_factura, PdfColumnProfile::find($copia)->page_layout);
    }
}
