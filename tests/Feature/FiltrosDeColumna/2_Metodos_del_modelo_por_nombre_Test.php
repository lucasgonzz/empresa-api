<?php

namespace Tests\Feature\FiltrosDeColumna;

use App\Http\Controllers\Helpers\ColumnFiltersHelper;
use App\Models\Article;
use App\Models\ProductionBatch;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

/**
 * Un nombre que sale del pedido no puede terminar invocando un método del modelo que no sea una
 * relación (misión filtros-key-sin-inyeccion, 5/10/2026). Se prueba el MECANISMO, no un ejemplo:
 *
 *  - el orden por relación (`<relacion>_id` + ordenar_de) y el "en blanco" de un FK invocaban
 *    `(new Modelo)->{key sin _id}()`: `save_id` / `touch_id` hacían un INSERT;
 *  - las relation_props de global-search (GlobalSearchQueryHelper y el desglose de coincidencias de
 *    GlobalSearchMatchesHelper) invocaban `relation` con method_exists + try/catch: `save` /
 *    `touch` / `push` hacían un INSERT y el catch se tragaba el resto.
 *
 * Se usa `sales` porque ahí un `(new Sale)->save()` vacío SÍ inserta una fila (ninguna columna
 * obligatoria sin default): si la guarda no está, la cantidad de filas cambia y se ve.
 *
 * @group filtros_key_sin_inyeccion
 */
class Metodos_del_modelo_por_nombre_Test extends FiltrosDeColumnaTestCase
{
    /**
     * Cantidad de filas de sales, borradas incluidas.
     *
     * @return int
     */
    protected function filas_de_ventas()
    {
        return (int) DB::table('sales')->count();
    }

    /** @test */
    public function un_key_con_nombre_de_metodo_de_eloquent_da_422_y_no_escribe_nada()
    {
        $antes = $this->filas_de_ventas();

        foreach (['save_id', 'touch_id', 'restore_id', 'push_id', 'delete_id'] as $key) {

            // Con orden (las flechas del header): apply_order_filter invocaba `save()`.
            $respuesta = $this->postJson('api/search/sale', [
                'filters' => [$this->filtro_spa($key, 'select', ['ordenar_de' => 'ASC'])],
            ]);
            $this->assert_rechazo_de_la_guarda($respuesta, $key . ' con ordenar_de');

            // Con "en blanco": relation_for_blank_check invocaba `restore()` (trait SoftDeletes,
            // que PHP reporta como declarado en el modelo).
            $respuesta = $this->postJson('api/search/sale', [
                'filters' => [$this->filtro_spa($key, 'select', ['en_blanco' => 1])],
            ]);
            $this->assert_rechazo_de_la_guarda($respuesta, $key . ' con en_blanco');

            $this->assertSame($antes, $this->filas_de_ventas(), 'El filtro ' . $key . ' escribió en sales.');
        }
    }

    /** @test */
    public function relation_props_con_nombre_de_metodo_de_eloquent_no_escriben_nada()
    {
        $texto = 'ZZRELPROPS' . uniqid();

        // Una venta del dueño que matchea por una prop propia válida: así la página de resultados
        // no viene vacía y el desglose de coincidencias (GlobalSearchMatchesHelper) también valida
        // las relaciones del pedido — es el segundo camino que las invocaba.
        $venta = DB::table('sales')->insertGetId([
            'user_id'      => $this->dueno->id,
            'observations' => $texto,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $antes = $this->filas_de_ventas();

        foreach (['save', 'touch', 'push', 'restore', 'delete'] as $relacion) {
            $respuesta = $this->postJson('api/global-search/sale', [
                'query_value'    => $texto,
                'props'          => [['key' => 'observations', 'keyword_mode' => 'alguna']],
                'relation_props' => [['relation' => $relacion, 'props' => ['name'], 'keyword_mode' => 'alguna']],
                'extra_filters'  => [],
                'filters'        => [],
                'conector'       => 'or',
            ]);

            $respuesta->assertStatus(200);
            $this->assertSame([(int) $venta], $this->ids_de($respuesta), 'La relation_prop "' . $relacion . '" cambió el resultado.');
            $this->assertNotNull($respuesta->json('matches'), 'El desglose de coincidencias no se armó: el test no estaría cubriendo GlobalSearchMatchesHelper.');

            $this->assertSame($antes, $this->filas_de_ventas(), 'La relation_prop "' . $relacion . '" escribió en sales.');
        }

        $this->assertNull(Sale::withTrashed()->find($venta)->deleted_at, 'La venta del dueño quedó borrada.');
    }

    /** @test */
    public function relacion_real_solo_devuelve_relaciones_declaradas_en_app()
    {
        $articulos_antes = Article::withTrashed()->count();

        // Relaciones de verdad.
        $this->assertInstanceOf(BelongsTo::class, ColumnFiltersHelper::relacion_real(Article::class, 'category'));
        $this->assertInstanceOf(MorphMany::class, ColumnFiltersHelper::relacion_real(Article::class, 'images'));

        // Métodos de Eloquent (Model) y de traits de Illuminate (SoftDeletes::restore, que PHP
        // reporta con el modelo como clase declarante).
        foreach (['save', 'touch', 'push', 'delete', 'forceDelete', 'restore', 'refresh', 'replicate', 'getTable'] as $metodo) {
            $this->assertNull(ColumnFiltersHelper::relacion_real(Article::class, $metodo), $metodo . ' no es una relación.');
        }

        // Un accessor con efectos (lee la base) no se invoca.
        $this->assertNull(ColumnFiltersHelper::relacion_real(ProductionBatch::class, 'getAmountsByStatusAttribute'));

        // Nombres que ni siquiera son identificadores, o que no son texto.
        foreach (['category; drop', 'category()', 'category ', '../category', '', '1category'] as $nombre) {
            $this->assertNull(ColumnFiltersHelper::relacion_real(Article::class, $nombre), '"' . $nombre . '" no es un nombre de relación.');
        }
        $this->assertNull(ColumnFiltersHelper::relacion_real(Article::class, null));
        $this->assertNull(ColumnFiltersHelper::relacion_real(Article::class, ['category']));

        // Un método que no existe, y una clase que no existe.
        $this->assertNull(ColumnFiltersHelper::relacion_real(Article::class, 'no_existe_esta_relacion'));
        $this->assertNull(ColumnFiltersHelper::relacion_real('App\\Models\\NoExisteEsteModelo', 'category'));

        $this->assertSame($articulos_antes, Article::withTrashed()->count(), 'Validar nombres escribió en articles.');
    }
}
