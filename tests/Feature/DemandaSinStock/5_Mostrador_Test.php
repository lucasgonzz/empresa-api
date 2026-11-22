<?php

namespace Tests\Feature\DemandaSinStock;

use App\Models\Article;
use App\Models\PurchaseSuggestion;
use App\Models\User;
use App\Services\Mostrador\RecolectorCompras;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Mostrador\MostradorTestCase;

/**
 * Misión demanda-sin-stock-vender, ronda 2 — archivo 5: la señal llega al camino vivo.
 *
 * El único cálculo diario de compras es el del mostrador (RecolectorCompras). Sus candidatos
 * salían sólo de artículos con mínimo o con ventas; un artículo en cero, sin ventas y sin
 * mínimo, que Vender frenó por falta de stock, nunca llegaba al motor. Estos tests recorren el
 * camino real `(new RecolectorCompras)->recolectar($owner, now())` y fijan que:
 *  - con frenos recientes sin cancelar, el artículo aparece en el bloque de compras;
 *  - no aparece si los frenos están cancelados, tienen más de 30 días o son de otro dueño.
 */
class Mostrador_Test extends MostradorTestCase
{
    /**
     * Inserta un freno en unmet_demands.
     *
     * @param int $article_id
     * @param int $owner_id
     * @param array $extra Campos a pisar (canceled_at, created_at, ...)
     * @return void
     */
    protected function freno($article_id, $owner_id, array $extra = [])
    {
        DB::table('unmet_demands')->insert(array_merge([
            'user_id'          => $owner_id,
            'employee_id'      => $owner_id,
            'article_id'       => $article_id,
            'motivo'           => 'sin_stock',
            'cantidad_pedida'  => null,
            'stock_disponible' => 0,
            'created_at'       => now()->subDays(2),
            'updated_at'       => now()->subDays(2),
        ], $extra));
    }

    /**
     * Ids de artículos que el informe de compras muestra (con y sin proveedor).
     *
     * @return array
     */
    protected function articulos_en_compras()
    {
        $h = (new RecolectorCompras())->recolectar($this->comercio, now());

        $ids = array_column($h['sin_proveedor'], 'article_id');

        foreach ($h['por_proveedor'] as $proveedor) {
            $ids = array_merge($ids, array_column($proveedor['articulos'], 'article_id'));
        }

        return array_map('intval', $ids);
    }

    /**
     * @group demanda-sin-stock
     * @test
     */
    public function un_articulo_en_cero_sin_ventas_ni_minimo_con_frenos_recientes_aparece_en_compras()
    {
        $taladro = $this->articulo('Taladro', ['stock' => 0, 'provider_id' => null]);
        $antes = PurchaseSuggestion::count();

        $this->freno($taladro->id, $this->comercio->id);

        $this->assertContains($taladro->id, $this->articulos_en_compras());
        $this->assertSame($antes, PurchaseSuggestion::count(), 'El recolector no deja purchase_suggestion.');
    }

    /**
     * @group demanda-sin-stock
     * @test
     */
    public function sin_frenos_el_mismo_articulo_no_aparece()
    {
        $taladro = $this->articulo('Taladro', ['stock' => 0, 'provider_id' => null]);

        $this->assertNotContains($taladro->id, $this->articulos_en_compras());
    }

    /**
     * @group demanda-sin-stock
     * @test
     */
    public function los_frenos_cancelados_no_lo_hacen_aparecer()
    {
        $taladro = $this->articulo('Taladro', ['stock' => 0, 'provider_id' => null]);

        $this->freno($taladro->id, $this->comercio->id, ['canceled_at' => now()->subDay()]);

        $this->assertNotContains($taladro->id, $this->articulos_en_compras());
    }

    /**
     * @group demanda-sin-stock
     * @test
     */
    public function los_frenos_de_hace_mas_de_30_dias_no_lo_hacen_aparecer()
    {
        $taladro = $this->articulo('Taladro', ['stock' => 0, 'provider_id' => null]);

        $this->freno($taladro->id, $this->comercio->id, [
            'created_at' => now()->subDays(31),
            'updated_at' => now()->subDays(31),
        ]);

        $this->assertNotContains($taladro->id, $this->articulos_en_compras());
    }

    /**
     * @group demanda-sin-stock
     * @test
     */
    public function los_frenos_de_otro_duenio_no_lo_hacen_aparecer()
    {
        $otro = User::create([
            'name'     => 'Otro dueño mostrador',
            'email'    => 'mostrador-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $taladro = $this->articulo('Taladro', ['stock' => 0, 'provider_id' => null]);

        // El freno está a nombre de otro comercio (aunque apunte a este artículo).
        $this->freno($taladro->id, $otro->id);

        $this->assertNotContains($taladro->id, $this->articulos_en_compras());
    }
}
