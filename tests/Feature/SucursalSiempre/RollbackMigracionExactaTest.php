<?php

namespace Tests\Feature\SucursalSiempre;

require_once __DIR__.'/../ListasSiempre/RollbackHistorialMigracionTest.php';

use App\Models\Address;
use App\Models\ArticleVariant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ListasSiempre\RollbackIntegracionTestCase;

class RollbackMigracionExactaTest extends RollbackIntegracionTestCase
{
    public function test_respaldo_nuevo_entrega_orden_fisico_y_transacciones_completas()
    {
        $dueno = $this->nuevo_dueno();
        $this->nuevo_articulo($dueno, ['stock' => 12]);
        $sql = $this->migrar_y_leer('sucursales:migrar-a-principal', $dueno);
        // El legado sirve para diagnosticar P1/P2, nunca acredita el contrato nuevo.
        $this->bloques_reversion($sql, true);
    }

    public function test_restauracion_comercial_incluye_timestamps_crudos_y_stock_de_variantes()
    {
        $dueno = $this->nuevo_dueno();
        $direccion = Address::create(['street' => 'Existente', 'user_id' => $dueno->id, 'default_address' => 0]);
        DB::table('addresses')->where('id', $direccion->id)->update(['default_address' => 0, 'updated_at' => '2020-01-02 03:04:05']);
        $ids = []; $variantes = [];
        foreach ([null, 0, -3, 9] as $stock) {
            $articulo = $this->nuevo_articulo($dueno, ['stock' => $stock]);
            $ids[] = $articulo->id;
            if ($stock === 9) {
                foreach ([5, 4] as $n => $cantidad) {
                    $v = ArticleVariant::create(['article_id' => $articulo->id, 'variant_description' => 'Regresión '.$n, 'stock' => $cantidad]);
                    DB::table('article_variants')->where('id', $v->id)->update(['updated_at' => '2020-02-0'.($n + 1).' 04:05:06']);
                    $variantes[] = $v->id;
                }
            }
        }
        $papelera = $this->nuevo_articulo($dueno, ['stock' => 4]);
        $papelera->delete();
        $ids[] = $papelera->id;
        $antes = $this->foto_comercial($dueno->id, $ids, $variantes);
        $sql = $this->migrar_y_leer('sucursales:migrar-a-principal', $dueno);
        $this->assertSame(1, (int) DB::table('addresses')->where('id', $direccion->id)->value('default_address'));
        $this->ejecutar_reversion($sql);
        $this->assertSame($antes, $this->foto_comercial($dueno->id, $ids, $variantes), 'P2: igualdad íntegra, incluidos NULL y timestamps exactos.');
    }

}
