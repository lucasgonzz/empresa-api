<?php

namespace Tests\Feature\Precios;

use App\Models\PriceChange;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\ListasSiempre\ListasSiempreTestCase;

/**
 * Historial de cambios de precio (api/price-change/{article_id}) para un empleado SIN el permiso
 * de costos, en negocios que NO trabajan con listas de precio (flag users.listas_de_precio = 0).
 *
 * Ahí `final_price` es el precio público de siempre: la SPA lo muestra en la columna «Precio Final»
 * y no tiene de dónde sacarlo si la API lo borra. `cost` y `price` siguen ocultos para ese empleado.
 */
class HistorialSinListasEmpleadoSinCostoTest extends ListasSiempreTestCase
{
    /**
     * Crea un empleado del dueño, sin el permiso de costos (admin_access = 0 y sin permisos).
     *
     * @param  \App\Models\User  $dueno
     * @param  int               $admin_access  1 para un empleado con acceso total (sí ve costos).
     * @return \App\Models\User
     */
    private function empleado($dueno, $admin_access = 0)
    {
        return User::create([
            'name'         => 'zz empleado historial sin listas',
            'email'        => uniqid().'@test.local',
            'owner_id'     => $dueno->id,
            'admin_access' => $admin_access,
            'password'     => Hash::make(uniqid()),
        ]);
    }

    /**
     * Arma un artículo con un cambio de precio viejo y otro nuevo que trae `precio_final_anterior`.
     *
     * @param  \App\Models\User  $dueno
     * @return array  [Article, PriceChange (el más nuevo)]
     */
    private function articulo_con_historial($dueno)
    {
        $articulo = $this->articulo($dueno, 'historial sin listas', ['cost' => 100, 'percentage_gain' => 50]);
        $this->recalcular($articulo, $dueno);

        // Segundo cambio: el precio final pasa a 333 y el anterior queda registrado.
        $cambio = PriceChange::create([
            'article_id'            => $articulo->id,
            'cost'                  => 100,
            'price'                 => 150,
            'final_price'           => 333,
            'precio_final_anterior' => 150,
            'employee_id'           => $dueno->id,
        ]);

        return [$articulo, $cambio];
    }

    /**
     * Pide el historial y devuelve la fila del cambio indicado.
     *
     * @param  int  $article_id
     * @param  int  $price_change_id
     * @return array
     */
    private function fila_del_historial($article_id, $price_change_id)
    {
        $filas = $this->getJson('api/price-change/'.$article_id)->assertOk()->json('models');
        foreach ($filas as $fila) {
            if ($fila['id'] == $price_change_id) {
                return $fila;
            }
        }
        $this->fail('El cambio de precio no vino en el historial');
    }

    /** @test */
    public function ca1_negocio_sin_listas_empleado_sin_costo_ve_precio_final()
    {
        $dueno = $this->dueno_nuevo('sin-listas');
        list($articulo, $cambio) = $this->articulo_con_historial($dueno);
        $this->actingAs($this->empleado($dueno), 'web');

        $fila = $this->fila_del_historial($articulo->id, $cambio->id);

        $this->assertArrayHasKey('final_price', $fila);
        $this->assertEquals(333, $fila['final_price']);
        $this->assertArrayHasKey('precio_final_anterior', $fila);
        $this->assertEquals(150, $fila['precio_final_anterior']);
        $this->assertArrayNotHasKey('cost', $fila);
        $this->assertArrayNotHasKey('price', $fila);
    }

    /** @test */
    public function ca2_negocio_sin_flag_y_con_listas_propias_empleado_sin_costo_ve_precio_final()
    {
        $dueno = $this->dueno_nuevo('flag-cero-con-listas');
        $this->otra_lista($dueno, 'Mayorista', 100);
        list($articulo, $cambio) = $this->articulo_con_historial($dueno);
        $this->actingAs($this->empleado($dueno), 'web');

        $fila = $this->fila_del_historial($articulo->id, $cambio->id);

        $this->assertArrayHasKey('final_price', $fila);
        $this->assertEquals(333, $fila['final_price']);
        $this->assertArrayHasKey('precio_final_anterior', $fila);
        $this->assertEquals(150, $fila['precio_final_anterior']);
        $this->assertArrayNotHasKey('cost', $fila);
        $this->assertArrayNotHasKey('price', $fila);
    }

    /** @test */
    public function ca5_dueno_y_empleado_con_acceso_total_siguen_viendo_todo()
    {
        $dueno = $this->dueno_nuevo('ve-todo');
        list($articulo, $cambio) = $this->articulo_con_historial($dueno);

        foreach ([$dueno, $this->empleado($dueno, 1)] as $usuario) {
            $this->actingAs($usuario, 'web');
            $fila = $this->fila_del_historial($articulo->id, $cambio->id);
            $this->assertEquals(333, $fila['final_price']);
            $this->assertEquals(150, $fila['precio_final_anterior']);
            $this->assertEquals(100, $fila['cost']);
            $this->assertEquals(150, $fila['price']);
        }
    }
}
