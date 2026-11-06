<?php

namespace Tests\Feature\ListasSiempre;

use App\Models\PriceType;
use Illuminate\Support\Facades\Queue;

class Orden_de_listas_Test extends ListasSiempreTestCase
{
    /** @test */
    public function el_alta_ubica_la_lista_nueva_y_ordena_las_existentes()
    {
        Queue::fake();
        [$dueno, $base] = $this->con_precio_final($this->dueno_nuevo());
        $mayorista = $this->otra_lista($dueno, 'Mayorista', 20, 6);
        $articulo = $this->articulo($dueno, 'artículo abierto', ['cost' => 1000]);
        $this->actingAs($dueno, 'web');

        $respuesta = $this->postJson('api/price-type', [
            'name' => 'Distribuidores',
            'percentage' => 10,
            'position' => 99,
            'orden_listas' => [$base->id, null, $mayorista->id],
            'articulo_abierto_id' => $articulo->id,
            'categories' => [],
            'sub_categories' => [],
        ])->assertCreated()->assertJsonPath('model.position', 2);

        $this->assertSame(3, (int) $base->fresh()->position);
        $this->assertSame(1, (int) $mayorista->fresh()->position);
        $this->assertSame('Distribuidores', $respuesta->json('model.name'));
        $respuesta->assertJsonPath('posiciones_listas', [
            ['id' => $base->id, 'position' => 3],
            ['id' => $respuesta->json('model.id'), 'position' => 2],
            ['id' => $mayorista->id, 'position' => 1],
        ]);
        // La cola está simulada: el precio se calculó antes de devolver el alta.
        $nueva_lista = PriceType::findOrFail($respuesta->json('model.id'));
        $pivote = $this->pivote($articulo, $nueva_lista);
        $this->assertNotNull($pivote);
        $this->assertSame(1100.0, (float) $pivote->final_price);
    }

    /** @test */
    public function editar_una_lista_puede_reordenar_las_demas_y_el_cliente_viejo_puede_guardar_posicion()
    {
        Queue::fake();
        [$dueno, $base] = $this->con_precio_final($this->dueno_nuevo());
        $mayorista = $this->otra_lista($dueno, 'Mayorista', 20, 6);
        $this->actingAs($dueno, 'web');
        $datos = array_merge($mayorista->toArray(), ['categories' => [], 'sub_categories' => []]);

        $this->putJson('api/price-type/'.$mayorista->id, array_merge($datos, [
            'orden_listas' => [$base->id, $mayorista->id],
        ]))->assertOk()->assertJsonPath('model.position', 1);
        $this->assertSame(2, (int) $base->fresh()->position);

        $respuesta_vieja = $this->putJson('api/price-type/'.$mayorista->id, array_merge($datos, [
            'position' => 9,
        ]))->assertOk();
        $this->assertArrayNotHasKey('posiciones_listas', $respuesta_vieja->json());
        $this->assertSame(9, (int) $mayorista->fresh()->position);
        $this->assertSame(2, (int) $base->fresh()->position);
    }

    /** @test */
    public function un_orden_incompleto_duplicado_o_ajeno_no_guarda_ningun_cambio()
    {
        Queue::fake();
        [$dueno, $base] = $this->con_precio_final($this->dueno_nuevo());
        $mayorista = $this->otra_lista($dueno, 'Mayorista', 20, 6);
        $otro_dueno = $this->dueno_nuevo('otro');
        $ajena = $this->otra_lista($otro_dueno, 'Ajena', 20, 9);
        $articulo_ajeno = $this->articulo($otro_dueno, 'artículo ajeno');
        $this->actingAs($dueno, 'web');

        foreach ([[$mayorista->id], [$mayorista->id, $mayorista->id], [$mayorista->id, $ajena->id]] as $orden) {
            $this->putJson('api/price-type/'.$mayorista->id, [
                'name' => 'Nombre nuevo',
                'position' => 99,
                'orden_listas' => $orden,
            ])->assertStatus(422)->assertJsonValidationErrors('orden_listas');
        }

        $this->postJson('api/price-type', [
            'name' => 'Lista nueva',
            'orden_listas' => [$base->id, $mayorista->id],
        ])->assertStatus(422)->assertJsonValidationErrors('orden_listas');

        $this->postJson('api/price-type', [
            'name' => 'Lista nueva',
            'orden_listas' => [$base->id, null, $mayorista->id],
            'articulo_abierto_id' => $articulo_ajeno->id,
            'categories' => [],
            'sub_categories' => [],
        ])->assertNotFound();

        $this->assertSame(2, PriceType::where('user_id', $dueno->id)->count());
        $this->assertSame('Mayorista', $mayorista->fresh()->name);
        $this->assertSame(6, (int) $mayorista->fresh()->position);
        $this->assertSame(9, (int) $ajena->fresh()->position);
    }
}
