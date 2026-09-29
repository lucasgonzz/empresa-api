<?php

namespace Tests\Feature\Precios\DescuentosYMasivasEnLote;

use App\Models\Article;
use App\Models\Category;
use App\Models\Provider;
use Illuminate\Support\Facades\Notification;

/**
 * Mision `recalculo-precios-motor-rapido` (28/9/2026) — los disparadores de punta a punta: el
 * guardado real por el endpoint, el ProcessSetFinalPrices que despacha (corriendo en la misma
 * request, con la cola `sync` de phpunit.xml) y los precios que quedan.
 *
 * 4_Disparadores_del_recalculo_Test mira QUE se despacha (con la cola falsa); este mira que el
 * alcance llegue de verdad a los articulos: que un cambio solo del dolar del proveedor recalcule
 * SOLO sus articulos en dolares, que el margen recalcule todo el proveedor y nada mas, y que el
 * margen de una categoria recalcule solo la categoria.
 *
 * Tecnica: a todos los articulos se les pisa el precio final con 1 despues de calcularlo. El que se
 * recalcula deja de valer 1; el que no, sigue en 1.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promocion de constructor, readonly, enum ni #[...].
 *
 * @group costeo-precios
 */
class Disparadores_de_punta_a_punta_Test extends DescuentosYMasivasEnLoteTestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        /* El aviso de "precios actualizados" sale por Pusher y no es lo que se mide aca. */
        Notification::fake();

        /* La cola en sync explicita: el recalculo tiene que correr adentro del request. */
        config(['queue.default' => 'sync']);
    }

    /**
     * @param  \App\Models\Provider $provider
     * @param  array $cambios
     * @return \Illuminate\Testing\TestResponse
     */
    private function guardar_proveedor($provider, array $cambios)
    {
        $provider = $provider->fresh();

        return $this->putJson('api/provider/' . $provider->id, array_merge([
            'name'                       => $provider->name,
            'phone'                      => $provider->phone,
            'address'                    => $provider->address,
            'email'                      => $provider->email,
            'razon_social'               => $provider->razon_social,
            'cuit'                       => $provider->cuit,
            'observations'               => $provider->observations,
            'location_id'                => $provider->location_id,
            'provincia_id'               => $provider->provincia_id,
            'iva_condition_id'           => $provider->iva_condition_id,
            'percentage_gain'            => $provider->percentage_gain,
            'dolar'                      => $provider->dolar,
            'porcentaje_comision_negro'  => $provider->porcentaje_comision_negro,
            'porcentaje_comision_blanco' => $provider->porcentaje_comision_blanco,
            'price_from_cost_mas_iva'    => $provider->price_from_cost_mas_iva,
        ], $cambios));
    }

    /**
     * @param  int $id
     * @return float
     */
    private function precio($id)
    {
        return (float) Article::find($id)->final_price;
    }

    /**
     * @test
     */
    public function cambiar_solo_el_dolar_del_proveedor_recalcula_solo_sus_articulos_en_dolares()
    {
        $dueno = $this->crear_dueno();

        $provider = $this->crear_proveedor($dueno, ['percentage_gain' => 30, 'dolar' => 1300]);
        $otro     = $this->crear_proveedor($dueno, ['percentage_gain' => 30, 'dolar' => 1300]);

        $en_dolares      = $this->crear_articulo($dueno, ['cost' => 10, 'cost_in_dollars' => 1, 'provider_id' => $provider->id])->id;
        $en_pesos        = $this->crear_articulo($dueno, ['cost' => 1000, 'provider_id' => $provider->id])->id;
        $de_otro_en_usd  = $this->crear_articulo($dueno, ['cost' => 20, 'cost_in_dollars' => 1, 'provider_id' => $otro->id])->id;

        $ids = [$en_dolares, $en_pesos, $de_otro_en_usd];

        $this->calentar($ids, $dueno->id);
        $this->pisar_precio_final($ids, 1);

        $this->guardar_proveedor($provider, ['dolar' => 1400])->assertStatus(200);

        $this->assertNotEquals(1, $this->precio($en_dolares), 'El articulo en dolares del proveedor se recalcula con el dolar nuevo.');
        $this->assertEquals(1, $this->precio($en_pesos), 'El articulo en pesos del mismo proveedor NO se recalcula: el dolar no lo toca.');
        $this->assertEquals(1, $this->precio($de_otro_en_usd), 'El articulo en dolares de OTRO proveedor no se recalcula.');
    }

    /**
     * @test
     */
    public function cambiar_el_margen_recalcula_todo_el_proveedor_y_nada_mas()
    {
        $dueno = $this->crear_dueno();

        $provider = $this->crear_proveedor($dueno, ['percentage_gain' => 30, 'dolar' => 1300]);
        $otro     = $this->crear_proveedor($dueno, ['percentage_gain' => 30]);

        $en_dolares = $this->crear_articulo($dueno, ['cost' => 10, 'cost_in_dollars' => 1, 'provider_id' => $provider->id])->id;
        $en_pesos   = $this->crear_articulo($dueno, ['cost' => 1000, 'provider_id' => $provider->id])->id;
        $de_otro    = $this->crear_articulo($dueno, ['cost' => 1000, 'provider_id' => $otro->id])->id;

        $ids = [$en_dolares, $en_pesos, $de_otro];

        $this->calentar($ids, $dueno->id);
        $this->pisar_precio_final($ids, 1);

        $this->guardar_proveedor($provider, ['percentage_gain' => 40])->assertStatus(200);

        $this->assertNotEquals(1, $this->precio($en_dolares));
        $this->assertNotEquals(1, $this->precio($en_pesos));
        $this->assertEquals(1, $this->precio($de_otro), 'El articulo de otro proveedor no se recalcula.');

        /* 1000 de costo, 40% de margen del proveedor, IVA 21% al vender: 1694. */
        $this->assertEqualsWithDelta(1694, $this->precio($en_pesos), 0.01);
    }

    /**
     * @test
     */
    public function cambiar_el_margen_de_una_categoria_recalcula_solo_la_categoria()
    {
        $dueno = $this->crear_dueno();

        $categoria = Category::create([
            'name'            => 'zz Categoria punta a punta ' . uniqid(),
            'user_id'         => $dueno->id,
            'percentage_gain' => 10,
        ]);

        $de_la_categoria = $this->crear_articulo($dueno, ['cost' => 1000, 'category_id' => $categoria->id])->id;
        $de_otra         = $this->crear_articulo($dueno, ['cost' => 1000])->id;

        $ids = [$de_la_categoria, $de_otra];

        $this->calentar($ids, $dueno->id);
        $this->pisar_precio_final($ids, 1);

        $this->putJson('api/category/' . $categoria->id, [
            'name'                      => $categoria->name,
            'image_url'                 => null,
            'descripcion'               => null,
            'percentage_gain'           => 20,
            'show_in_pdf_personalizado' => 0,
            'price_types'               => [],
        ])->assertStatus(200);

        /* 1000 de costo, 20% de margen de la categoria, IVA 21%: 1452. */
        $this->assertEqualsWithDelta(1452, $this->precio($de_la_categoria), 0.01);
        $this->assertEquals(1, $this->precio($de_otra), 'Un articulo fuera de la categoria no se recalcula.');
    }
}
