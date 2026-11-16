<?php

namespace Tests\Feature\Envios;

use App\Models\Combo;
use App\Models\PromocionVinoteca;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

class MedidasDePromocionesYCombosTest extends EmpresaTestCase
{
    private function medidas()
    {
        return ['peso' => '7.1250', 'alto' => '35.2500', 'ancho' => '20.5000', 'profundidad' => '25.7500'];
    }

    private function payload($tipo)
    {
        $datos = ['name' => 'Paquete de prueba', 'cost' => 100, 'articles' => [], 'online' => 0];
        return array_merge($datos, $tipo === 'combo'
            ? ['price' => 200]
            : ['final_price' => 200, 'stock' => 2, 'address_id' => 0, 'description' => 'Dos cajas']);
    }

    private function tabla($tipo)
    {
        return $tipo === 'combo' ? 'combos' : 'promocion_vinotecas';
    }

    public function tipos()
    {
        return [['combo'], ['promocion-vinoteca']];
    }

    /** @dataProvider tipos */
    public function test_alta_edicion_lectura_omision_y_limpieza_de_medidas($tipo)
    {
        $payload = $this->payload($tipo);
        $alta = $this->postJson('api/'.$tipo, array_merge($payload, $this->medidas()))->assertCreated();
        $id = $alta->json('model.id');
        foreach ($this->medidas() as $campo => $valor) {
            $this->assertEquals($valor, $alta->json('model.'.$campo));
            $this->assertEquals($valor, DB::table($this->tabla($tipo))->where('id', $id)->value($campo));
        }
        // Una SPA anterior no conoce las medidas: editar el nombre no las borra.
        $payload['name'] = 'Paquete renombrado';
        $this->putJson('api/'.$tipo.'/'.$id, $payload)->assertOk();
        $lectura = $this->getJson('api/'.$tipo.'/'.$id)->assertOk();
        foreach ($this->medidas() as $campo => $valor) {
            $this->assertEquals($valor, $lectura->json('model.'.$campo));
        }
        $cambios = array_merge($payload, ['peso' => '8.1234', 'alto' => null, 'ancho' => 0]);
        $edicion = $this->putJson('api/'.$tipo.'/'.$id, $cambios)->assertOk();
        $this->assertEquals('8.1234', $edicion->json('model.peso'));
        $this->assertNull($edicion->json('model.alto'));
        $this->assertEquals(0, $edicion->json('model.ancho'));
        $this->assertEquals('25.7500', $edicion->json('model.profundidad'));
    }

    /** @dataProvider tipos */
    public function test_alta_vieja_sin_medidas_no_inventa_datos($tipo)
    {
        $alta = $this->postJson('api/'.$tipo, $this->payload($tipo))->assertCreated();
        foreach (array_keys($this->medidas()) as $campo) {
            $this->assertNull($alta->json('model.'.$campo));
        }
    }

    public function valores_invalidos()
    {
        $casos = [];
        foreach (['combo', 'promocion-vinoteca'] as $tipo) {
            foreach ([-1, 'una caja', true, [], '0.00001', '999999999999999999999999999'] as $valor) {
                $casos[] = [$tipo, $valor];
            }
        }
        return $casos;
    }

    /** @dataProvider valores_invalidos */
    public function test_medidas_invalidas_rechazan_alta_y_edicion_sin_escribir($tipo, $valor)
    {
        $tabla = $this->tabla($tipo);
        $payload = $this->payload($tipo);
        $cantidad = DB::table($tabla)->count();
        $this->postJson('api/'.$tipo, array_merge($payload, ['peso' => $valor]))
            ->assertStatus(422)->assertJsonValidationErrors('peso');
        $this->assertSame($cantidad, DB::table($tabla)->count());
        $alta = $this->postJson('api/'.$tipo, array_merge($payload, $this->medidas()))->assertCreated();
        $id = $alta->json('model.id');
        $antes = DB::table($tabla)->where('id', $id)->first();
        $this->putJson('api/'.$tipo.'/'.$id, array_merge($payload, ['ancho' => $valor]))
            ->assertStatus(422)->assertJsonValidationErrors('ancho');
        $this->assertEquals($antes, DB::table($tabla)->where('id', $id)->first());
    }

    public function test_formulario_completo_de_promo_con_medidas_sigue_siendo_compatible()
    {
        $alta = $this->postJson('api/promocion-vinoteca', array_merge($this->payload('promocion-vinoteca'), $this->medidas()))->assertCreated();
        $promo = PromocionVinoteca::withAll()->findOrFail($alta->json('model.id'));
        $datos = $promo->toArray();
        $datos['description'] = 'Embalaje reforzado';
        $this->putJson('api/promocion-vinoteca/'.$promo->id, $datos)->assertOk();
        $this->assertEquals('7.1250', $promo->fresh()->peso);
    }

    public function test_las_medidas_no_recalculan_precio_stock_ni_componentes()
    {
        $combo = Combo::create(['name' => 'Caja armada', 'num' => 999123, 'cost' => 150, 'price' => 250, 'user_id' => auth()->id()]);
        $this->putJson('api/combo/'.$combo->id, array_merge($this->payload('combo'), $this->medidas(), ['cost' => 150, 'price' => 250]))->assertOk();
        $this->assertEquals(150, $combo->fresh()->cost);
        $this->assertEquals(250, $combo->fresh()->price);
        $this->assertSame(0, $combo->articles()->count());
    }

    /** Código nuevo antes de migrar: el alta y la edición siguen andando sin nombrar las columnas. */
    public function test_sin_columnas_de_medidas_los_dos_endpoints_siguen_funcionando()
    {
        DB::rollBack();
        $config = config('database.connections.'.config('database.default'));
        $pdo = new \PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'],
            $config['username'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('SET SESSION lock_wait_timeout = 10');
        $renombradas = [];
        try {
            foreach (['combos', 'promocion_vinotecas'] as $tabla) {
                foreach (array_keys($this->medidas()) as $campo) {
                    $pdo->exec('ALTER TABLE `'.$tabla.'` RENAME COLUMN `'.$campo.'` TO `'.$campo.'_sin_migrar`');
                    $renombradas[] = [$tabla, $campo];
                }
            }
            DB::beginTransaction();
            foreach (['combo', 'promocion-vinoteca'] as $tipo) {
                $datos = array_merge($this->payload($tipo), $this->medidas());
                $alta = $this->postJson('api/'.$tipo, $datos)->assertCreated();
                $this->assertArrayNotHasKey('peso', $alta->json('model'));
                $this->putJson('api/'.$tipo.'/'.$alta->json('model.id'), $datos)->assertOk();
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach (array_reverse($renombradas) as $par) {
                $pdo->exec('ALTER TABLE `'.$par[0].'` RENAME COLUMN `'.$par[1].'_sin_migrar` TO `'.$par[1].'`');
            }
            DB::beginTransaction();
        }
    }
}
