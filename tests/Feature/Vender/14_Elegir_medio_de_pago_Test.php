<?php

namespace Tests\Feature\Vender;

use App\Http\Controllers\CommonLaravel\AuthController;
use App\Models\Article;
use App\Models\PriceType;
use App\Models\Sale;
use App\Models\User;
use Tests\EmpresaTestCase;

class Elegir_medio_de_pago_Test extends EmpresaTestCase
{
    private const OPCION = 'vender_exigir_eleccion_medio_pago';
    private const METODO_PREDETERMINADO = 'default_current_acount_payment_method_id';
    private const EFECTIVO = 3;
    private const TRANSFERENCIA = 4;

    public function test_el_dueno_puede_activar_y_desactivar_sin_perder_el_medio_ni_afectar_otro_comercio(): void
    {
        $dueno = auth()->user();
        $dueno->update([self::OPCION => false, self::METODO_PREDETERMINADO => self::TRANSFERENCIA]);
        $otro = User::factory()->create();
        $this->assertFalse($otro->fresh()->{self::OPCION});

        $perfil = $dueno->fresh()->toArray();
        unset($perfil[self::METODO_PREDETERMINADO]);
        $perfil[self::OPCION] = true;
        $url = 'api/user/' . $dueno->id;
        $this->putJson($url, $perfil)->assertOk()->assertJsonPath('model.' . self::OPCION, true);
        $this->assertFalse($otro->fresh()->{self::OPCION});

        // Una SPA anterior puede guardar la configuración sin mandar la opción nueva.
        unset($perfil[self::OPCION]);
        $this->putJson($url, $perfil)->assertOk()->assertJsonPath('model.' . self::OPCION, true);

        $perfil[self::OPCION] = false;
        $this->putJson($url, $perfil)->assertOk()->assertJsonPath('model.' . self::OPCION, false);
        $this->assertSame(self::TRANSFERENCIA, (int) $dueno->fresh()->{self::METODO_PREDETERMINADO});
    }

    public function test_el_empleado_lee_la_opcion_del_dueno_y_no_la_puede_cambiar(): void
    {
        $dueno = auth()->user();
        $dueno->update([self::OPCION => true]);
        $empleado = User::factory()->create(['owner_id' => $dueno->id, 'admin_access' => 1]);
        $this->actingAs($empleado, 'web');

        $perfil = $empleado->fresh()->toArray();
        $perfil[self::OPCION] = false;
        $this->putJson('api/user/' . $dueno->id, $perfil)->assertOk();
        $this->assertTrue($dueno->fresh()->{self::OPCION});

        $perfil[self::OPCION] = true;
        $this->putJson('api/user/' . $empleado->id, $perfil)->assertOk();
        $this->assertFalse($empleado->fresh()->{self::OPCION});
        $usuario = (new AuthController())->set_employee_props($empleado->fresh());
        $this->assertTrue($usuario->toArray()['owner'][self::OPCION]);
    }

    public function test_el_alta_de_contado_rechaza_el_selector_vacio_y_guarda_el_medio_elegido(): void
    {
        $dueno = auth()->user();
        $dueno->update([self::OPCION => true, self::METODO_PREDETERMINADO => self::TRANSFERENCIA]);
        $lista = PriceType::create(['name' => 'zz Medio de pago', 'user_id' => $dueno->id, 'position' => 5]);
        $articulo = Article::create([
            'name' => 'zz Venta de contado con elección de medio',
            'user_id' => $dueno->id,
            'final_price' => 100,
            'status' => 'active',
        ]);
        $venta = [
            'price_type_id' => $lista->id,
            'client_id' => null,
            'moneda_id' => 1,
            'save_current_acount' => 0,
            'omitir_en_cuenta_corriente' => 0,
            'current_acount_payment_method_id' => 0,
            'selected_payment_methods' => [],
            'discount_stock' => 0,
            'discounts_in_services' => 1,
            'surchages_in_services' => 1,
            'discounts' => [],
            'surchages' => [],
            'total' => 100,
            'sub_total' => 100,
            'terminada' => 1,
            'items' => [[
                'is_article' => true,
                'id' => $articulo->id,
                'name' => $articulo->name,
                'price_vender' => 100,
                'amount' => 1,
            ]],
        ];

        $cantidad_anterior = Sale::where('user_id', $dueno->id)->count();
        $this->postJson('api/sale', $venta)->assertStatus(422)->assertJsonPath('sin_metodo_de_pago', true);
        $this->assertSame($cantidad_anterior, Sale::where('user_id', $dueno->id)->count());

        $venta['current_acount_payment_method_id'] = self::EFECTIVO;
        $respuesta = $this->postJson('api/sale', $venta)->assertStatus(201);
        $guardada = Sale::findOrFail($respuesta->json('model.id'));
        $medio = $guardada->current_acount_payment_methods()->whereKey(self::EFECTIVO)->firstOrFail();
        $this->assertEquals(100, $medio->pivot->amount);
    }
}
