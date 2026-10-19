<?php

namespace Tests\Feature\Pdf;

use App\Models\SheetType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Tests\Feature\Pdf\Concerns\PerfilesDeTicketDeComandera;
use Tests\TestCase;

/**
 * Los tipos de hoja de los diseños de PDF y los anchos de comandera propios del negocio (misión
 * diseno-ticket-comandera, 9/10/2026, contrato §3.1): `GET api/sheet-types` y `POST
 * api/sheet-types`, contra los endpoints reales y la base.
 *
 * @group pdf-ticket-comandera
 */
class Tipos_de_hoja_por_dueno_Test extends TestCase
{
    use DatabaseTransactions;
    use PerfilesDeTicketDeComandera;

    const URL = 'api/sheet-types';

    /**
     * Un dueño nuevo, autenticado.
     *
     * @return \App\Models\User
     */
    protected function dueno_autenticado()
    {
        $dueno = User::create([
            'name' => 'Dueno tipos de hoja',
            'company_name' => 'Dueno tipos de hoja',
            'email' => 'hojas-'.uniqid().'@test.local',
            'password' => 'x',
        ]);

        /**
         * El guard de Sanctum cachea el usuario del primer pedido del test: sin olvidarlo, un
         * segundo dueño seguiría autenticado como el primero.
         */
        Auth::forgetGuards();
        $this->actingAs($dueno, 'web');

        return $dueno;
    }

    /**
     * @test
     */
    public function el_get_lista_los_del_sistema_y_los_del_dueno_con_los_rollos_primero_por_ancho()
    {
        $dueno = $this->dueno_autenticado();
        $a4 = $this->hoja_a4();
        $r80 = $this->rollo_del_sistema(80);
        $r55 = $this->rollo_del_sistema(55);
        $propio = $this->rollo_propio($dueno->id, 72);

        $otro = User::create(['name' => 'Otro', 'company_name' => 'Otro', 'email' => 'hojas-otro-'.uniqid().'@test.local', 'password' => 'x']);
        $ajeno = $this->rollo_propio($otro->id, 64);

        $models = $this->getJson(self::URL)->assertStatus(200)->json('models');
        $ids = array_column($models, 'id');

        $this->assertContains($a4->id, $ids);
        $this->assertContains($propio->id, $ids);
        $this->assertNotContains($ajeno->id, $ids, 'El ancho propio de otro negocio no se ve.');

        /** Los rollos (alto null) primero y de menor a mayor ancho; después las hojas. */
        $posicion = array_flip($ids);
        $this->assertLessThan($posicion[$propio->id], $posicion[$r55->id]);
        $this->assertLessThan($posicion[$r80->id], $posicion[$propio->id]);
        $this->assertLessThan($posicion[$a4->id], $posicion[$r80->id]);

        $fila = $models[$posicion[$propio->id]];
        $this->assertSame('Ticket 72 mm', $fila['name']);
        $this->assertSame(72, $fila['width']);
        $this->assertNull($fila['height']);
        $this->assertSame($dueno->id, $fila['user_id']);
        $this->assertNull($models[$posicion[$r80->id]]['user_id'], 'Los del sistema van con user_id null.');
    }

    /**
     * @test
     */
    public function el_post_crea_un_rollo_propio_y_no_duplica()
    {
        $dueno = $this->dueno_autenticado();
        $r80 = $this->rollo_del_sistema(80);

        $creado = $this->postJson(self::URL, ['width' => 76])->assertStatus(201)->json('model');
        $this->assertSame('Ticket 76 mm', $creado['name']);
        $this->assertSame(76, $creado['width']);
        $this->assertNull($creado['height']);
        $this->assertSame($dueno->id, $creado['user_id']);
        $this->assertDatabaseHas('sheet_types', ['id' => $creado['id'], 'user_id' => $dueno->id, 'width' => 76, 'height' => null]);

        /** El mismo ancho otra vez: 200 con el que ya existe. */
        $otra_vez = $this->postJson(self::URL, ['width' => '76'])->assertStatus(200)->json('model');
        $this->assertSame($creado['id'], $otra_vez['id']);
        $this->assertSame(1, SheetType::where('user_id', $dueno->id)->where('width', 76)->count());

        /** Un ancho del sistema: 200 con el del sistema, sin crear uno propio. */
        $del_sistema = $this->postJson(self::URL, ['width' => 80])->assertStatus(200)->json('model');
        $this->assertSame($r80->id, $del_sistema['id']);
        $this->assertSame(0, SheetType::where('user_id', $dueno->id)->where('width', 80)->count());
    }

    /**
     * @test
     */
    public function un_ancho_fuera_de_rango_o_no_entero_da_422()
    {
        $dueno = $this->dueno_autenticado();

        foreach ([39, 121, 72.5, 'abc', null, ''] as $ancho) {
            $this->postJson(self::URL, ['width' => $ancho])
                ->assertStatus(422)
                ->assertJsonStructure(['message', 'errors' => ['width']]);
        }

        $this->assertSame(0, SheetType::where('user_id', $dueno->id)->count(), 'Un 422 no crea nada.');

        /** Los bordes valen. */
        $this->postJson(self::URL, ['width' => 40])->assertStatus(201);
        $this->postJson(self::URL, ['width' => 120])->assertStatus(201);
    }

    /**
     * @test
     */
    public function el_rollo_propio_de_un_dueno_no_lo_ve_otro_y_otro_crea_el_suyo()
    {
        $primero = $this->dueno_autenticado();
        $suyo = $this->postJson(self::URL, ['width' => 66])->assertStatus(201)->json('model');

        $segundo = $this->dueno_autenticado();
        $ids = array_column($this->getJson(self::URL)->assertStatus(200)->json('models'), 'id');
        $this->assertNotContains($suyo['id'], $ids);

        $del_segundo = $this->postJson(self::URL, ['width' => 66])->assertStatus(201)->json('model');
        $this->assertNotSame($suyo['id'], $del_segundo['id']);
        $this->assertSame($segundo->id, $del_segundo['user_id']);
        $this->assertNotSame($primero->id, $segundo->id);
    }
}
