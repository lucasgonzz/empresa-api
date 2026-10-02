<?php

namespace Tests\Feature\Pdf;

use App\Models\PdfColumnProfile;
use App\Models\User;
use App\Services\PdfColumnService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Los flags "Mostrar Sub Total en el pie" (`show_subtotal_in_footer`) e "Imprimir con fecha
 * actual" (`use_current_date`) del formulario de Diseños de PDF tienen que persistir por la API.
 *
 * Por qué existe este test (misión pdf-presupuestos-y-pedidos-personalizables, 29/9/2026):
 * `PdfColumnProfileController` descartaba `show_subtotal_in_footer` en silencio, tanto en `store()`
 * como en `update()` (nunca estuvo en el `create([...])`, ni en el `$request->only([...])`, ni en
 * las reglas), y `use_current_date` solo entraba por `update()`. El formulario los manda, la API
 * responde 200 y el valor queda intacto: el dueño destilda "Sub Total", ve el aviso de éxito y el
 * PDF sigue imprimiéndolo. Era un bug de las ventas; esta misión lo extiende a presupuestos y
 * pedidos online al mostrarles esos mismos checkboxes, así que dejaba dos controles muertos.
 *
 * El test 4 de esta carpeta (`Observaciones_del_cliente_persisten_por_api`) dice que `store()` no
 * se puede ejercitar por HTTP porque `columns` es NOT NULL sin default. Eso ya no es cierto: el
 * controlador manda `'columns' => []`. Acá el `store()` SÍ se prueba por HTTP, con un diseño de
 * presupuesto, que de paso demuestra que el controlador acepta `budget` de punta a punta.
 *
 * @group pdf-documentos
 */
class Flags_del_pie_persisten_por_api_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Owner autenticado (mismo patrón que `Observaciones_del_cliente_persisten_por_api_Test`).
     *
     * @return \App\Models\User
     */
    protected function autenticar()
    {
        $owner = User::find(500);
        if (is_null($owner)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($owner, 'web');

        return $owner;
    }

    /**
     * Perfil de presupuesto mínimo, creado directo por Eloquent (para probar el `update()`).
     *
     * @param int   $owner_id
     * @param array $overrides
     * @return \App\Models\PdfColumnProfile
     */
    protected function crear_perfil(int $owner_id, array $overrides = [])
    {
        return PdfColumnProfile::create(array_merge([
            'user_id'            => $owner_id,
            'model_name'         => 'budget',
            'name'               => 'zz Presupuesto de test flags del pie',
            'paper_width_mm'     => 210,
            'printable_width_mm' => 210,
            'columns'            => [],
        ], $overrides));
    }

    /**
     * Las dos primeras opciones del catálogo del modelo, con el formato que manda la SPA.
     *
     * @param string $model_name
     * @return array<int, array<string, mixed>>
     */
    protected function opciones_de_columnas($model_name)
    {
        $payload = [];
        $orden = 0;

        foreach (PdfColumnService::get_options($model_name)->take(2) as $opcion) {
            $payload[] = [
                'id'    => $opcion->id,
                'pivot' => ['visible' => true, 'order' => $orden++, 'width' => 50, 'wrap_content' => false],
            ];
        }

        return $payload;
    }

    /**
     * @param array $extra
     * @return array
     */
    protected function payload_de_alta($extra = [])
    {
        return array_merge([
            'model_name'         => 'budget',
            'name'               => 'zz Presupuesto de test alta por API',
            'paper_width_mm'     => 210,
            'printable_width_mm' => 210,
            'margin_mm'          => 5,
            'pdf_column_options' => $this->opciones_de_columnas('budget'),
        ], $extra);
    }

    /**
     * @test
     */
    public function el_update_persiste_show_subtotal_in_footer_en_los_dos_sentidos()
    {
        $owner = $this->autenticar();
        $perfil = $this->crear_perfil($owner->id, ['show_subtotal_in_footer' => true]);

        $this->putJson('api/pdf-column-profiles/'.$perfil->id, ['show_subtotal_in_footer' => false])
            ->assertStatus(200);

        $this->assertFalse(
            (bool) $perfil->fresh()->show_subtotal_in_footer,
            'El update tiene que persistir show_subtotal_in_footer: si no esta en el $request->only() '
                .'el PUT devuelve 200 y el checkbox del formulario no hace nada.'
        );

        $this->putJson('api/pdf-column-profiles/'.$perfil->id, ['show_subtotal_in_footer' => true])
            ->assertStatus(200);

        $this->assertTrue((bool) $perfil->fresh()->show_subtotal_in_footer);
    }

    /**
     * `$request->only([...])` es "sometimes": renombrar el diseño no puede tocar el flag.
     *
     * @test
     */
    public function un_update_que_no_menciona_el_flag_no_lo_toca()
    {
        $owner = $this->autenticar();
        $perfil = $this->crear_perfil($owner->id, ['show_subtotal_in_footer' => false, 'use_current_date' => true]);

        $this->putJson('api/pdf-column-profiles/'.$perfil->id, ['name' => 'zz Presupuesto renombrado'])
            ->assertStatus(200);

        $recargado = $perfil->fresh();
        $this->assertFalse((bool) $recargado->show_subtotal_in_footer, 'Un update parcial no puede reencender el Sub Total.');
        $this->assertTrue((bool) $recargado->use_current_date, 'Un update parcial no puede apagar la fecha actual.');
    }

    /**
     * @test
     */
    public function el_update_rechaza_un_valor_que_no_es_booleano()
    {
        $owner = $this->autenticar();
        $perfil = $this->crear_perfil($owner->id);

        $this->putJson('api/pdf-column-profiles/'.$perfil->id, ['show_subtotal_in_footer' => 'quizas'])
            ->assertStatus(422);
    }

    /**
     * El alta por la API real, con un diseño de PRESUPUESTO: lo que el formulario manda al guardar
     * un diseño nuevo tiene que quedar en la base.
     *
     * @test
     */
    public function el_alta_de_un_presupuesto_persiste_los_flags_del_pie()
    {
        $this->autenticar();

        $response = $this->postJson('api/pdf-column-profiles', $this->payload_de_alta([
            'show_subtotal_in_footer' => false,
            'use_current_date'        => true,
            'show_total_in_footer'    => false,
        ]));

        $response->assertSuccessful();

        $perfil = PdfColumnProfile::find($response->json('model.id'));

        $this->assertNotNull($perfil, 'El alta no devolvio el diseño creado.');
        $this->assertSame('budget', $perfil->model_name, 'El controlador tiene que aceptar el modelo `budget`.');
        $this->assertFalse((bool) $perfil->show_subtotal_in_footer, 'El alta descarto show_subtotal_in_footer.');
        $this->assertTrue((bool) $perfil->use_current_date, 'El alta descarto use_current_date.');
        $this->assertFalse((bool) $perfil->show_total_in_footer);
        $this->assertCount(2, $perfil->pdf_column_options, 'El alta tiene que asociar las columnas del catalogo de presupuesto.');
    }

    /**
     * Sin mandar los flags, el alta conserva los defaults de siempre: Sub Total visible y fecha del
     * comprobante (los diseños que ya existen en producción no cambian de comportamiento).
     *
     * @test
     */
    public function el_alta_sin_mencionar_los_flags_usa_los_defaults_de_siempre()
    {
        $this->autenticar();

        $response = $this->postJson('api/pdf-column-profiles', $this->payload_de_alta([
            'model_name'         => 'order',
            'name'               => 'zz Pedido online de test alta por API',
            'pdf_column_options' => $this->opciones_de_columnas('order'),
        ]));

        $response->assertSuccessful();

        $perfil = PdfColumnProfile::find($response->json('model.id'));

        $this->assertSame('order', $perfil->model_name);
        $this->assertTrue((bool) $perfil->show_subtotal_in_footer, 'Default: el Sub Total se muestra.');
        $this->assertFalse((bool) $perfil->use_current_date, 'Default: la fecha es la del comprobante.');
    }

    /**
     * Las opciones de columna se validan contra el catálogo del MODELO del diseño: un id de una
     * columna de venta no vale para un presupuesto (y al revés).
     *
     * @test
     */
    public function una_columna_de_otro_modelo_no_se_puede_asociar_a_un_presupuesto()
    {
        $this->autenticar();

        $columna_de_venta = PdfColumnService::get_options('sale')->first();
        $this->assertNotNull($columna_de_venta, 'El catalogo de venta esta vacio en la base de testing.');

        $this->postJson('api/pdf-column-profiles', $this->payload_de_alta([
            'pdf_column_options' => [[
                'id'    => $columna_de_venta->id,
                'pivot' => ['visible' => true, 'order' => 0, 'width' => 50],
            ]],
        ]))->assertStatus(422);
    }
}
