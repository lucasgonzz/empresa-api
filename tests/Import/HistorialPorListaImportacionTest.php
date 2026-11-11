<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\PriceChange;
use App\Models\PriceType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class HistorialPorListaImportacionTest extends ImportTestCase
{
    public function test_importacion_registra_lista_exclusiva_sin_duplicar_reintento()
    {
        $this->tenant->listas_de_precio = 1;
        $this->tenant->save();
        $this->actingAs($this->tenant->fresh(), 'web');
        $lista = PriceType::create(['name' => 'Historial', 'percentage' => 100, 'position' => 1, 'user_id' => $this->tenant->id]);
        $articulo = $this->seed['A1'];
        $articulo->price_types()->syncWithoutDetaching([$lista->id => ['setear_precio_final' => 1, 'final_price' => 200]]);
        ArticleHelper::setFinalPrice($articulo->fresh(), $this->tenant->id, $this->tenant->fresh());
        $base = $articulo->fresh()->final_price;
        $cantidad = PriceChange::where('article_id', $articulo->id)->count();
        $nombre = 'zz_historial_'.uniqid().'.xlsx';
        $ruta = __DIR__.'/fixtures/'.$nombre;
        $libro = new Spreadsheet();
        $libro->getActiveSheet()->fromArray([
            ['Codigo', 'Nombre', 'Precio', 'Setear'],
            [$articulo->bar_code, $articulo->name, 100, 'Si'],
        ], null, 'A1', true);
        (new Xlsx($libro))->save($ruta);
        $mapeo = [
            'prop_codigo_de_barras' => 1, 'prop_nombre' => 2,
            'prop_sku' => -1, 'prop_codigo_de_proveedor' => -1,
            'prop_costo' => -1, 'prop_precio' => -1, 'prop_stock_actual' => -1, 'prop_iva' => -1,
            'prop_$_final_historial' => 3, 'prop_setear_precio_final_historial' => 4,
        ];
        try {
            $importacion = $this->importar($nombre, $mapeo);
            $this->assertSame($base, $articulo->fresh()->final_price);
            $this->assertSame($cantidad + 1, PriceChange::where('article_id', $articulo->id)->count());
            $evento = PriceChange::where('article_id', $articulo->id)->orderByDesc('id')->first();
            $detalle = collect($evento->cambios_listas)->firstWhere('lista_id', $lista->id);
            $this->assertSame('200.00', $detalle['precio_anterior']);
            $this->assertSame('100.00', $detalle['precio_nuevo']);
            $this->assertSame('importacion', $evento->origen);
            $this->importar($nombre, $mapeo);
            $this->assertSame($cantidad + 1, PriceChange::where('article_id', $articulo->id)->count());
        } finally {
            @unlink($ruta);
        }
    }

    public function test_reversion_real_registra_precios_de_lista_restaurados()
    {
        $this->tenant->listas_de_precio = 1;
        $this->tenant->save();
        $this->actingAs($this->tenant->fresh(), 'web');
        $lista = PriceType::create(['name' => 'Historial', 'percentage' => 100, 'user_id' => $this->tenant->id]);
        $articulo = $this->seed['A1'];
        $articulo->cost = 100;
        $articulo->percentage_gain = 50;
        $articulo->save();
        $articulo->price_types()->syncWithoutDetaching([$lista->id => ['setear_precio_final' => 0, 'percentage' => 100]]);
        ArticleHelper::setFinalPrice($articulo->fresh(), $this->tenant->id, $this->tenant->fresh());
        $precio_previo = $articulo->fresh()->price_types()->find($lista->id)->pivot->final_price;
        $nombre = 'zz_reversion_historial_'.uniqid().'.xlsx';
        $ruta = __DIR__.'/fixtures/'.$nombre;
        $libro = new Spreadsheet();
        $libro->getActiveSheet()->fromArray([['Codigo', 'Nombre', 'Costo'], [$articulo->bar_code, $articulo->name, 50]], null, 'A1', true);
        (new Xlsx($libro))->save($ruta);
        try {
            $importacion = $this->importar($nombre, [
                'prop_codigo_de_barras' => 1, 'prop_nombre' => 2, 'prop_costo' => 3,
                'prop_sku' => -1, 'prop_codigo_de_proveedor' => -1, 'prop_precio' => -1, 'prop_stock_actual' => -1, 'prop_iva' => -1,
            ]);
            $precio_importado = $articulo->fresh()->price_types()->find($lista->id)->pivot->final_price;
            $this->assertNotEquals($precio_previo, $precio_importado);
            $cantidad = PriceChange::where('article_id', $articulo->id)->count();
            $this->postJson('/api/import-history/rollback/'.$importacion->id)->assertStatus(202);
            $this->assertSame('revertida', $importacion->fresh()->rollback_status);
            $this->assertEquals($precio_previo, $articulo->fresh()->price_types()->find($lista->id)->pivot->final_price);
            $this->assertSame($cantidad + 1, PriceChange::where('article_id', $articulo->id)->count());
            $evento = PriceChange::where('article_id', $articulo->id)->orderByDesc('id')->first();
            $detalle = collect($evento->cambios_listas)->firstWhere('lista_id', $lista->id);
            $this->assertSame($precio_importado, $detalle['precio_anterior']);
            $this->assertSame($precio_previo, $detalle['precio_nuevo']);
            $this->assertSame('reversion_importacion', $evento->origen);
        } finally {
            @unlink($ruta);
        }
    }
}
