<?php

namespace Tests\Feature\DisenoVender;

use App\Http\Controllers\Helpers\VenderLayoutHelper;
use App\Models\User;
use App\Models\VenderLayout;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `VenderLayoutSeeder` (misión diseno-vender-configurable, 28/9/2026): el "Diseño predeterminado"
 * de cada dueño que no tiene ningún diseño.
 *
 * Es el seeder de la versión que corre sobre las bases de producción que ya existen, así que lo
 * que importa es que sea idempotente y que no pise nada: correrlo dos veces no duplica, un dueño
 * que ya armó sus diseños queda como estaba, y un empleado no recibe diseño propio.
 *
 * Se corre con `Artisan::call('db:seed', ['--class' => 'VenderLayoutSeeder'])`, con el nombre corto,
 * que es exactamente como lo llaman `UserSetupHelper` y `DemoSetupHelper`: así el test prueba
 * también que ese nombre resuelve a la clase. Corre sobre la misma conexión, adentro de la
 * transacción del test, y se revierte con ella (también los predeterminados que les crea a los
 * otros dueños de la base de testing).
 *
 * @group disenos_de_vender
 */
class Seeder_del_diseno_predeterminado_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * El dueño 500, sin ningún diseño (el borrado se revierte con la transacción).
     *
     * @return \App\Models\User
     */
    protected function dueno_sin_disenos()
    {
        $dueno = User::find(500);

        if (is_null($dueno)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        VenderLayout::where('user_id', $dueno->id)->delete();

        return $dueno;
    }

    /**
     * Corre el seeder por el mismo camino que los setups.
     *
     * @return void
     */
    protected function correr_el_seeder()
    {
        $codigo = Artisan::call('db:seed', ['--class' => 'VenderLayoutSeeder', '--force' => true]);

        $this->assertSame(0, $codigo, 'El seeder terminó con código '.$codigo.'.');
    }

    /**
     * @test
     */
    public function crea_el_predeterminado_para_un_dueno_sin_disenos()
    {
        $dueno = $this->dueno_sin_disenos();

        $this->correr_el_seeder();

        $disenos = VenderLayout::where('user_id', $dueno->id)->get();

        $this->assertCount(1, $disenos);

        $predeterminado = $disenos->first();

        $this->assertSame('Diseño predeterminado', $predeterminado->name);
        $this->assertNull($predeterminado->layout);
        $this->assertTrue($predeterminado->en_uso);
    }

    /**
     * @test
     */
    public function correrlo_dos_veces_no_duplica()
    {
        $dueno = $this->dueno_sin_disenos();

        $this->correr_el_seeder();
        $this->correr_el_seeder();

        $this->assertSame(1, VenderLayout::where('user_id', $dueno->id)->count());

        // Ningún dueño de la base quedó con más de un diseño.
        $duenos_con_mas_de_uno = VenderLayout::query()
            ->selectRaw('user_id, COUNT(*) AS cantidad')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('user_id')
            ->all();

        $this->assertSame([], $duenos_con_mas_de_uno);
    }

    /**
     * Un dueño que ya tiene diseños queda exactamente como estaba, aunque ninguno sea el
     * predeterminado (y aunque ninguno esté en uso: el seeder no arregla eso, solo siembra).
     *
     * @test
     */
    public function no_toca_a_un_dueno_que_ya_tiene_disenos()
    {
        $this->dueno_sin_disenos();

        $otro = User::create([
            'name'         => 'zz Dueño con diseños propios',
            'company_name' => 'zz Comercio con diseños',
            'email'        => 'zz_dueno_con_disenos@prueba.local',
            'password'     => bcrypt('zz-password-testing'),
            'status'       => 'commerce',
        ]);

        $layout = [
            'version' => 1,
            'etapas'  => [
                'etapa_1' => [['key' => 'cliente', 'cols' => 6]],
                'etapa_2' => [],
                'etapa_3' => [],
            ],
            'sacados' => [],
        ];

        $mostrador = VenderLayout::create(['user_id' => $otro->id, 'name' => 'Mostrador', 'layout' => $layout, 'en_uso' => false]);
        $deposito = VenderLayout::create(['user_id' => $otro->id, 'name' => 'Depósito', 'layout' => null, 'en_uso' => false]);

        $antes = VenderLayout::where('user_id', $otro->id)->orderBy('id')->get()->toArray();

        $this->correr_el_seeder();

        $despues = VenderLayout::where('user_id', $otro->id)->orderBy('id')->get()->toArray();

        $this->assertSame($antes, $despues);
        $this->assertSame([$mostrador->id, $deposito->id], array_column($despues, 'id'));
    }

    /**
     * @test
     */
    public function no_le_crea_nada_a_un_empleado()
    {
        $dueno = $this->dueno_sin_disenos();

        $empleado = User::create([
            'owner_id' => $dueno->id,
            'name'     => 'zz Empleado (seeder de diseños)',
            'password' => bcrypt('zz-password-testing'),
            'status'   => 'commerce',
        ]);

        $this->correr_el_seeder();

        $this->assertSame(0, VenderLayout::where('user_id', $empleado->id)->count());
        $this->assertSame(1, VenderLayout::where('user_id', $dueno->id)->count());
    }

    /**
     * El helper que comparten el seeder y el index: no crea nada si el id es de un empleado, si no
     * existe, o si el dueño ya tiene alguno; y devuelve el diseño cuando lo crea.
     *
     * @test
     */
    public function el_helper_solo_crea_para_un_dueno_sin_disenos()
    {
        $dueno = $this->dueno_sin_disenos();

        $empleado = User::create([
            'owner_id' => $dueno->id,
            'name'     => 'zz Empleado (helper de diseños)',
            'password' => bcrypt('zz-password-testing'),
            'status'   => 'commerce',
        ]);

        $this->assertNull(VenderLayoutHelper::crear_predeterminado_si_no_tiene($empleado->id));
        $this->assertSame(0, VenderLayout::where('user_id', $empleado->id)->count());

        $inexistente = ((int) User::max('id')) + 1000;

        $this->assertNull(VenderLayoutHelper::crear_predeterminado_si_no_tiene($inexistente));
        $this->assertSame(0, VenderLayout::where('user_id', $inexistente)->count());

        $creado = VenderLayoutHelper::crear_predeterminado_si_no_tiene($dueno->id);

        $this->assertInstanceOf(VenderLayout::class, $creado);
        $this->assertSame($dueno->id, $creado->user_id);

        $this->assertNull(VenderLayoutHelper::crear_predeterminado_si_no_tiene($dueno->id));
        $this->assertSame(1, VenderLayout::where('user_id', $dueno->id)->count());
    }
}
