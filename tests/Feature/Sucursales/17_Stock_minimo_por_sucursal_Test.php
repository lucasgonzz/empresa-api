<?php
namespace Tests\Feature\Sucursales;

use App\Exports\ArticleStockMinimoExport;
use App\Http\Controllers\Helpers\inventoryPerformance\InventoryPerformanceHelper;
use App\Models\InventoryPerformance;
use App\Models\PermissionEmpresa;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/** Ticket #21: filas, resúmenes y Excel comparten el alcance real de la persona. */
class Stock_minimo_por_sucursal_Test extends SucursalesTestCase
{
    protected $a; protected $b; protected $report; protected $item;
    protected function setUp(): void {
        parent::setUp();
        $this->a = $this->nueva_sucursal('zz Ticket 21 A');
        $this->b = $this->nueva_sucursal('zz Ticket 21 B');
        InventoryPerformance::where('user_id', $this->comercio()->id)->delete();
        $this->item = $this->nuevo_articulo('zz Ticket 21 compartido', ['stock'=>999, 'stock_min'=>20, 'cost'=>10]);
        $this->report = InventoryPerformance::create(['user_id'=>$this->comercio()->id, 'stock_minimo'=>3, 'cantidad_articulos'=>77, 'valor_inventario_en_costos'=>999999]);
        $this->fila($this->item, $this->a->id, -2, 5);
        $this->fila($this->item, $this->b->id, 1, 3);
        $this->fila($this->item, null, 999, 1000);
    }
    protected function fila($item, $address, $stock, $min) {
        DB::table('article_inventory_performance')->insert(['article_id'=>$item->id, 'inventory_performance_id'=>$this->report->id, 'address_id'=>$address, 'stock_address'=>$stock, 'stock_min_address'=>$min]);
    }
    protected function restringido($address) {
        $persona = $this->nuevo_empleado('zz Ticket 21 empleado', $address);
        $permiso = PermissionEmpresa::unguarded(function () { return PermissionEmpresa::firstOrCreate(['slug'=>'article.stock_only_sucursal'], ['name'=>'Ver solo su sucursal']); });
        $persona->permissions()->attach($permiso->id);
        // Sanctum conserva su usuario durante varios requests del mismo test.
        $this->app['auth']->forgetGuards();
        $this->actingAs($persona, 'web');
        return $persona;
    }
    protected function listado($params=[]) {
        return $this->getJson('/api/inventory-performance/articles-stock-minimo?'.http_build_query($params));
    }
    public function test_dueno_selecciona_a_b_y_general_sin_mover_stock() {
        foreach ([$this->a->id=>-2, $this->b->id=>1] as $address=>$stock) {
            $res=$this->listado(['address_id'=>$address])->assertOk()->assertJsonPath('models.total',1)->assertJsonPath('summary.stock_minimo',1);
            $this->assertEquals($stock,$res->json('models.data.0.stock'));
            $this->assertEquals($address,$res->json('models.data.0.pivot.address_id'));
        }
        $this->listado()->assertOk()->assertJsonPath('models.total',3);
        $this->assertEquals(999,$this->item->fresh()->stock);
        $this->assertSame(3,DB::table('article_inventory_performance')->where('inventory_performance_id',$this->report->id)->count());
    }
    public function test_restringidos_a_b_no_reciben_stock_ni_metricas_globales() {
        foreach ([$this->a->id=>[-2,70,1],$this->b->id=>[1,20,0]] as $address=>$expected) {
            $this->restringido($address);
            $res=$this->listado()->assertOk()->assertJsonPath('models.total',1)->assertJsonPath('summary.sin_stock',$expected[2]);
            $this->assertEquals($expected[0],$res->json('models.data.0.stock'));
            $this->assertEquals($expected[1],$res->json('summary.costo_reposicion_stock_minimo'));
            $this->assertNull($res->json('models.data.0.stock_max'));
            $index=$this->getJson('/api/inventory-performance')->assertOk()->assertJsonPath('models.0.stock_minimo',1);
            $this->assertNull($index->json('models.0.valor_inventario_en_costos'));
            $this->assertNull($index->json('models.0.cantidad_articulos'));
            $this->assertCount(1,$index->json('stock_minimo_scope.addresses'));
        }
    }
    public function test_no_puede_forzar_otra_sucursal_incluso_sin_reporte() {
        $this->restringido($this->a->id);
        $this->listado(['address_id'=>$this->b->id])->assertForbidden();
        $this->getJson('/articles-stock-minimo/excel?address_id='.$this->b->id)->assertForbidden();
        $this->report->delete();
        $this->listado(['address_id'=>$this->b->id])->assertForbidden();
    }
    public function test_sin_sucursal_o_con_sucursal_ajena_recibe_vacio() {
        $ajena=$this->nueva_sucursal('zz ajena',['user_id'=>$this->otro_comercio()->id]);
        foreach ([null,$ajena->id,999999999] as $address) {
            $this->restringido($address);
            $this->listado()->assertOk()->assertJsonPath('models.total',0)->assertJsonPath('summary.stock_minimo',0);
            $this->getJson('/api/inventory-performance')->assertOk()->assertJsonPath('models.0.stock_minimo',0);
        }
    }
    public function test_admin_y_empleado_sin_restriccion_conservan_vision_general() {
        $persona=$this->restringido($this->a->id); $persona->admin_access=1; $persona->save();
        $this->app['auth']->forgetGuards();
        $this->actingAs($persona->fresh(),'web');
        $this->listado()->assertOk()->assertJsonPath('models.total',3);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->nuevo_empleado('zz Central',$this->a->id),'web');
        $this->listado(['address_id'=>$this->b->id])->assertOk()->assertJsonPath('models.total',1);
        $this->listado()->assertOk()->assertJsonPath('models.total',3);
    }
    public function test_busqueda_y_paginado_respetan_alcance_y_campana() {
        $otro=$this->nuevo_articulo('zz Tornillo ticket21',['stock'=>777,'cost'=>4,'bar_code'=>'ticket21-barras']);
        $this->fila($otro,$this->a->id,0,2); $this->restringido($this->a->id);
        $this->listado(['per_page'=>1,'page'=>2])->assertOk()->assertJsonPath('models.total',2)->assertJsonPath('models.data.0.name',$otro->name)->assertJsonPath('summary.stock_minimo',2);
        $res=$this->listado(['search'=>'ticket21-barras'])->assertOk()->assertJsonPath('models.total',1)->assertJsonPath('models.data.0.name',$otro->name)->assertJsonPath('summary.stock_minimo',1);
        $this->assertEquals(8,$res->json('summary.costo_reposicion_stock_minimo'));
        $this->listado(['search'=>'inexistente'])->assertOk()->assertJsonPath('models.total',0)->assertJsonPath('summary.stock_minimo',0);
        $this->getJson('/api/inventory-performance')->assertOk()->assertJsonPath('models.0.stock_minimo',2);
    }
    public function test_ajenos_compradores_y_pivots_corruptos_quedan_fuera() {
        $ajeno=$this->otro_comercio();
        $address=$this->nueva_sucursal('zz ajena',['user_id'=>$ajeno->id]);
        $comprador=$this->nueva_sucursal('zz domicilio comprador',['buyer_id'=>123]);
        $this->listado(['address_id'=>$address->id])->assertForbidden();
        $this->listado(['address_id'=>$comprador->id])->assertForbidden();
        $item=$this->nuevo_articulo('zz artículo ajeno',['user_id'=>$ajeno->id]);
        $this->fila($item,$this->a->id,0,2); $this->fila($this->item,$address->id,0,2);
        $this->listado()->assertOk()->assertJsonPath('models.total',3);
        $this->getJson('/api/inventory-performance')->assertOk()->assertJsonPath('models.0.stock_minimo',3);
    }
    public function test_parametros_invalidos_no_habilitan_alcance() {
        foreach (['A.9','1abc','-1','0','1.5'] as $value) { $this->listado(['address_id'=>$value])->assertStatus(422); }
        $this->listado(['address_id'=>[$this->a->id]])->assertStatus(422);
        $this->listado(['search'=>['x']])->assertStatus(422);
        $this->listado(['per_page'=>0])->assertStatus(422);
    }
    public function test_excel_usa_las_mismas_filas_y_stock_de_sucursal() {
        Excel::fake(); $this->restringido($this->a->id);
        $this->get('/articles-stock-minimo/excel?search=compartido')->assertOk();
        Excel::assertDownloaded('articulos_stock_minimo'.date('d-m-y').'.xlsx',function(ArticleStockMinimoExport $export) {
            $rows=$export->collection(); $this->assertCount(1,$rows); $map=$export->map($rows->first());
            $this->assertEquals(-2,$map[6]); $this->assertEquals(5,$map[7]); $this->assertSame($this->a->street,$map[13]); return true;
        });
    }
    public function test_generacion_global_no_tapa_faltantes_de_los_locales() {
        $this->item->stock=1; $this->item->stock_min=10; $this->item->status='active'; $this->item->save();
        foreach ([$this->a,$this->b] as $address) {
            DB::table('address_article')->insert(['article_id'=>$this->item->id,'address_id'=>$address->id,'amount'=>0,'stock_min'=>5]);
        }
        $helper=new InventoryPerformanceHelper($this->comercio()->id); $helper->procesar_articulos();
        $rows=collect($helper->articles_stock_minimo)->where('article_id',$this->item->id);
        $this->assertCount(3,$rows); $this->assertCount(1,$rows->whereNull('address_id'));
        $this->assertCount(1,$rows->where('address_id',$this->a->id)); $this->assertCount(1,$rows->where('address_id',$this->b->id));
    }
    public function test_permiso_de_edicion_general_no_amplia_lectura_restringida() {
        $persona=$this->restringido($this->a->id);
        $permiso=PermissionEmpresa::unguarded(function () { return PermissionEmpresa::firstOrCreate(['slug'=>'article.edit_stock'],['name'=>'Editar stock']); });
        $persona->permissions()->attach($permiso->id);
        $this->app['auth']->forgetGuards();
        $this->actingAs($persona->fresh(),'web');
        $this->listado()->assertOk()->assertJsonPath('models.total',1);
        $this->listado(['address_id'=>$this->b->id])->assertForbidden();
    }
    public function test_generacion_con_stock_global_nulo_conserva_faltante_de_sucursal() {
        $this->item->stock=null; $this->item->status='active'; $this->item->save();
        DB::table('address_article')->insert(['article_id'=>$this->item->id,'address_id'=>$this->a->id,'amount'=>1,'stock_min'=>5]);
        $helper=new InventoryPerformanceHelper($this->comercio()->id); $helper->procesar_articulos();
        $rows=collect($helper->articles_stock_minimo)->where('article_id',$this->item->id);
        $this->assertCount(1,$rows);
        $this->assertEquals($this->a->id,$rows->first()['address_id']);
    }

    public function test_index_ignora_filtro_temporal_y_busqueda() {
        $this->getJson('/api/inventory-performance?address_id='.$this->a->id.'&search=inexistente')
            ->assertOk()->assertJsonPath('models.0.stock_minimo',3)->assertJsonPath('stock_minimo_scope.address_id',null);
    }

}
