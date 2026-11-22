<?php

namespace Tests\Feature\DisenoVender;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Http\Controllers\Helpers\UserSetupHelper;
use App\Http\Controllers\Helpers\VenderLayoutHelper;
use App\Models\User;
use App\Models\VenderLayout;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use ReflectionMethod;
use Tests\TestCase;

/**
 * El "Diseño ágil" de Vender y su seeder `VenderLayoutAgilSeeder` (misión
 * vender-secciones-personalizables, 22/11/2026): CA4 (el seeder) y CA5 (la siembra en las altas).
 *
 * El diseño ágil NO vive en el backend: se arma en código en el SPA (`diseno_agil.js`) y la base
 * sólo guarda `{"version":2,"plantilla":"agil"}`. Lo que sí hace el backend es sembrarlo, UNA vez
 * por dueño, SIN usarlo (el que está en uso no se toca) y sin pisar nada de lo que el dueño ya
 * armó. Es un seeder de la versión que corre sobre las bases de producción que ya existen, así que
 * lo que importa es que sea idempotente y no destructivo.
 *
 * Casos de CA4:
 *   (a) dueño con sólo el predeterminado en uso -> 2 diseños; el nuevo es "Diseño ágil", sin uso,
 *       con la plantilla sola, y el predeterminado sigue en uso.
 *   (b) dueño sin diseños -> predeterminado en uso + ágil sin uso.
 *   (c) correrlo dos veces -> sigue habiendo un solo ágil.
 *   (d) dueño con el ágil RENOMBRADO y EDITADO (layout explícito con `plantilla 'agil'`) -> no
 *       crea otro: se reconoce por la plantilla, no por el nombre.
 *   (e) dueño con otro diseño en uso -> sigue en uso ese.
 *   (f) un empleado no recibe diseños propios.
 *   (g) `crear_agil_si_no_tiene()` sobre un empleado devuelve null.
 *
 * Se corre con `Artisan::call('db:seed', ['--class' => 'VenderLayoutAgilSeeder'])`, con el nombre
 * corto, que es exactamente como lo llaman `UserSetupHelper` y `DemoSetupHelper`. Corre adentro de
 * la transacción del test y se revierte con ella (también los ágiles que les crea a los otros
 * dueños de la base de testing).
 *
 * Los tests del seeder arrancan afirmando que la clase existe: mientras no exista fallan por esa
 * aserción y no por un `BindingResolutionException` que tapa lo que se quería probar.
 *
 * @group disenos_de_vender
 */
class Diseno_agil_Test extends TestCase
{
    use DatabaseTransactions;

    /** Nombre corto con el que los setups llaman al seeder (`db:seed --class=...`). */
    const SEEDER = 'VenderLayoutAgilSeeder';

    /** El seeder del predeterminado: el ágil tiene que correr DESPUÉS de él. */
    const SEEDER_PREDETERMINADO = 'VenderLayoutSeeder';

    /** El layout con el que se siembra el ágil. */
    const LAYOUT_AGIL = ['version' => 2, 'plantilla' => 'agil'];

    /*
     * ---------------------------------------------------------------------------------------
     *  Andamio
     * ---------------------------------------------------------------------------------------
     */

    /**
     * Afirma que el seeder existe (mismo camino que usa `db:seed`: nombre corto bajo
     * `Database\Seeders\`).
     *
     * @return void
     */
    protected function afirmar_que_existe_el_seeder()
    {
        $this->assertTrue(
            class_exists('Database\\Seeders\\'.self::SEEDER),
            'Falta la clase Database\\Seeders\\'.self::SEEDER.' (PLAN §2.6).'
        );
    }

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
     * Crea un diseño directo en la base, sin pasar por el endpoint.
     *
     * @param  int         $user_id
     * @param  string      $name
     * @param  bool        $en_uso
     * @param  array|null  $layout
     * @return \App\Models\VenderLayout
     */
    protected function crear_diseno($user_id, $name, $en_uso = false, $layout = null)
    {
        return VenderLayout::create([
            'user_id' => $user_id,
            'name'    => $name,
            'layout'  => $layout,
            'en_uso'  => $en_uso,
        ]);
    }

    /**
     * Corre el seeder por el mismo camino que los setups y exige que termine bien.
     *
     * @return void
     */
    protected function correr_el_seeder()
    {
        $this->afirmar_que_existe_el_seeder();

        $codigo = Artisan::call('db:seed', ['--class' => self::SEEDER, '--force' => true]);

        $this->assertSame(0, $codigo, 'El seeder terminó con código '.$codigo.'.');
    }

    /**
     * Los diseños ágiles de un dueño: los que tienen `plantilla 'agil'` en el layout (así los
     * reconoce el seeder, sin mirar el nombre).
     *
     * @param  int  $owner_id
     * @return \Illuminate\Support\Collection
     */
    protected function agiles_de($owner_id)
    {
        return VenderLayout::where('user_id', $owner_id)->orderBy('id')->get()->filter(function ($diseno) {
            return is_array($diseno->layout) && ($diseno->layout['plantilla'] ?? null) === 'agil';
        })->values();
    }

    /**
     * Un layout v2 explícito (el que guarda el editor cuando el dueño toca el ágil): conserva la
     * `plantilla` de origen y tiene sus propias secciones.
     *
     * @return array
     */
    protected function layout_agil_editado()
    {
        return [
            'version'     => 2,
            'plantilla'   => 'agil',
            'etapas'      => [
                'etapa_1' => [['key' => 'cliente', 'cols' => 12]],
                'etapa_2' => [['key' => 'resumen', 'cols' => 12]],
                'etapa_3' => [],
            ],
            'sacados'     => [],
            'secciones'   => [
                'etapa_1' => ['titulo' => 'Mi mostrador', 'descripcion' => null, 'color' => 'rojo', 'alto' => 12, 'alto_fijo' => true, 'plegado' => 'no'],
                'etapa_2' => ['titulo' => null, 'descripcion' => null, 'color' => 'verde', 'alto' => 8, 'alto_fijo' => true, 'plegado' => 'no'],
                'etapa_3' => ['titulo' => null, 'descripcion' => null, 'color' => 'naranja', 'alto' => 4, 'alto_fijo' => true, 'plegado' => 'no'],
            ],
            'disposicion' => [
                ['columnas' => [
                    ['ancho' => 4, 'secciones' => ['etapa_1']],
                    ['ancho' => 8, 'secciones' => ['etapa_2', 'etapa_3']],
                ]],
            ],
        ];
    }

    /**
     * La lista privada `base_seeders()` de un helper de setup.
     *
     * @param  string  $clase
     * @return string[]
     */
    protected function base_seeders($clase)
    {
        $metodo = new ReflectionMethod($clase, 'base_seeders');
        $metodo->setAccessible(true);

        return $metodo->invoke(null);
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  CA4 — el seeder
     * ---------------------------------------------------------------------------------------
     */

    /**
     * (a) Un dueño con sólo el predeterminado en uso termina con 2 diseños: el predeterminado
     * (sigue en uso, intacto) y el "Diseño ágil" nuevo, sin uso, con la plantilla sola.
     *
     * @test
     */
    public function crea_el_agil_sin_uso_y_deja_el_predeterminado_en_uso()
    {
        $dueno = $this->dueno_sin_disenos();

        $predeterminado = $this->crear_diseno($dueno->id, VenderLayoutHelper::NOMBRE_PREDETERMINADO, true, null);

        $this->correr_el_seeder();

        $disenos = VenderLayout::where('user_id', $dueno->id)->orderBy('id')->get();

        $this->assertCount(2, $disenos);

        $this->assertSame($predeterminado->id, $disenos[0]->id);
        $this->assertSame('Diseño predeterminado', $disenos[0]->name);
        $this->assertNull($disenos[0]->layout);
        $this->assertTrue($disenos[0]->en_uso, 'El predeterminado tiene que seguir en uso.');

        $agil = $disenos[1];

        $this->assertSame('Diseño ágil', $agil->name);
        $this->assertSame(self::LAYOUT_AGIL, $agil->layout);
        $this->assertFalse($agil->en_uso, 'El ágil se siembra SIN uso: elegirlo es decisión del dueño.');
        $this->assertSame($dueno->id, $agil->user_id);

        // Un solo diseño en uso por dueño (el invariante del ABM).
        $this->assertSame(1, VenderLayout::where('user_id', $dueno->id)->where('en_uso', true)->count());
    }

    /**
     * (b) Un dueño sin ningún diseño recibe primero el predeterminado EN USO y después el ágil sin
     * uso: el ágil nunca queda como único diseño y en uso.
     *
     * @test
     */
    public function a_un_dueno_sin_disenos_le_crea_el_predeterminado_en_uso_y_el_agil()
    {
        $dueno = $this->dueno_sin_disenos();

        $this->correr_el_seeder();

        $disenos = VenderLayout::where('user_id', $dueno->id)->orderBy('id')->get();

        $this->assertCount(2, $disenos);

        $this->assertSame('Diseño predeterminado', $disenos[0]->name);
        $this->assertNull($disenos[0]->layout);
        $this->assertTrue($disenos[0]->en_uso);

        $this->assertSame('Diseño ágil', $disenos[1]->name);
        $this->assertSame(self::LAYOUT_AGIL, $disenos[1]->layout);
        $this->assertFalse($disenos[1]->en_uso);

        $this->assertSame([$disenos[0]->id], VenderLayout::where('user_id', $dueno->id)->where('en_uso', true)->pluck('id')->all());
    }

    /**
     * (c) Correrlo dos veces no duplica el ágil (ni en el dueño de prueba ni en ningún otro dueño de
     * la base).
     *
     * @test
     */
    public function correrlo_dos_veces_no_duplica()
    {
        $dueno = $this->dueno_sin_disenos();

        $this->correr_el_seeder();
        $this->correr_el_seeder();

        $this->assertSame(2, VenderLayout::where('user_id', $dueno->id)->count());
        $this->assertCount(1, $this->agiles_de($dueno->id));

        // Ningún dueño de la base quedó con más de un ágil.
        $duenos = User::query()->whereNull('owner_id')->pluck('id')->all();

        foreach ($duenos as $owner_id) {
            $this->assertLessThanOrEqual(
                1,
                $this->agiles_de($owner_id)->count(),
                'El dueño '.$owner_id.' quedó con más de un diseño ágil.'
            );
        }
    }

    /**
     * (d) Un dueño que ya tiene el ágil, RENOMBRADO y EDITADO (layout v2 explícito con la
     * `plantilla` de origen), no recibe otro: el seeder lo reconoce por la plantilla y no por el
     * nombre, y lo deja intacto.
     *
     * @test
     */
    public function no_crea_otro_si_el_dueno_ya_tiene_el_agil_renombrado_y_editado()
    {
        $dueno = $this->dueno_sin_disenos();

        $this->crear_diseno($dueno->id, 'Diseño predeterminado', true, null);
        $propio = $this->crear_diseno($dueno->id, 'Mi mostrador rápido', false, $this->layout_agil_editado());

        $antes = VenderLayout::where('user_id', $dueno->id)->orderBy('id')->get()->toArray();

        $this->correr_el_seeder();

        $despues = VenderLayout::where('user_id', $dueno->id)->orderBy('id')->get()->toArray();

        $this->assertSame($antes, $despues, 'El seeder no tiene que tocar a un dueño que ya tiene un diseño con la plantilla ágil.');
        $this->assertCount(2, $despues);
        $this->assertSame($this->layout_agil_editado(), $propio->fresh()->layout);
        $this->assertSame('Mi mostrador rápido', $propio->fresh()->name);
    }

    /**
     * (e) Un dueño que tiene otro diseño en uso (propio, no el predeterminado) sigue con ese en
     * uso: el seeder agrega el ágil sin uso y no mueve el "en uso".
     *
     * @test
     */
    public function no_cambia_el_diseno_en_uso_de_un_dueno_con_diseno_propio()
    {
        $dueno = $this->dueno_sin_disenos();

        $propio_layout = [
            'version' => 1,
            'etapas'  => ['etapa_1' => [['key' => 'cliente', 'cols' => 6]], 'etapa_2' => [], 'etapa_3' => []],
            'sacados' => [],
        ];

        $mostrador = $this->crear_diseno($dueno->id, 'Mostrador', true, $propio_layout);

        $this->correr_el_seeder();

        $disenos = VenderLayout::where('user_id', $dueno->id)->orderBy('id')->get();

        $this->assertCount(2, $disenos);

        $this->assertSame($mostrador->id, $disenos[0]->id);
        $this->assertSame($propio_layout, $disenos[0]->layout);
        $this->assertTrue($disenos[0]->en_uso, 'El diseño propio tiene que seguir en uso.');

        $this->assertSame('Diseño ágil', $disenos[1]->name);
        $this->assertFalse($disenos[1]->en_uso);

        $this->assertSame([$mostrador->id], VenderLayout::where('user_id', $dueno->id)->where('en_uso', true)->pluck('id')->all());
    }

    /**
     * (f) El seeder no le crea nada a un empleado: los diseños son del dueño.
     *
     * @test
     */
    public function no_le_crea_nada_a_un_empleado()
    {
        $dueno = $this->dueno_sin_disenos();

        $empleado = User::create([
            'owner_id' => $dueno->id,
            'name'     => 'zz Empleado (seeder del diseño ágil)',
            'password' => bcrypt('zz-password-testing'),
            'status'   => 'commerce',
        ]);

        $this->correr_el_seeder();

        $this->assertSame(0, VenderLayout::where('user_id', $empleado->id)->count());
        $this->assertSame(2, VenderLayout::where('user_id', $dueno->id)->count());
    }

    /**
     * (g) El helper que usa el seeder devuelve null sobre un empleado y sobre un id que no existe,
     * y devuelve el diseño cuando lo crea; con el ágil ya creado, null.
     *
     * @test
     */
    public function el_helper_solo_crea_el_agil_para_un_dueno_que_no_lo_tiene()
    {
        $this->assertTrue(
            method_exists(VenderLayoutHelper::class, 'crear_agil_si_no_tiene'),
            'Falta VenderLayoutHelper::crear_agil_si_no_tiene($owner_id) (PLAN §2.6).'
        );

        $dueno = $this->dueno_sin_disenos();

        $empleado = User::create([
            'owner_id' => $dueno->id,
            'name'     => 'zz Empleado (helper del diseño ágil)',
            'password' => bcrypt('zz-password-testing'),
            'status'   => 'commerce',
        ]);

        $this->assertNull(VenderLayoutHelper::crear_agil_si_no_tiene($empleado->id));
        $this->assertSame(0, VenderLayout::where('user_id', $empleado->id)->count());

        $inexistente = ((int) User::max('id')) + 1000;

        $this->assertNull(VenderLayoutHelper::crear_agil_si_no_tiene($inexistente));
        $this->assertSame(0, VenderLayout::where('user_id', $inexistente)->count());

        // Sobre un dueño sin diseños crea el ágil (y, antes, el predeterminado en uso).
        $creado = VenderLayoutHelper::crear_agil_si_no_tiene($dueno->id);

        $this->assertInstanceOf(VenderLayout::class, $creado);
        $this->assertSame('Diseño ágil', $creado->name);
        $this->assertSame(self::LAYOUT_AGIL, $creado->layout);
        $this->assertFalse($creado->en_uso);
        $this->assertSame('Diseño ágil', VenderLayoutHelper::NOMBRE_AGIL);

        $this->assertSame(2, VenderLayout::where('user_id', $dueno->id)->count());
        $this->assertSame(1, VenderLayout::where('user_id', $dueno->id)->where('en_uso', true)->count());

        // Segunda vez: ya lo tiene.
        $this->assertNull(VenderLayoutHelper::crear_agil_si_no_tiene($dueno->id));
        $this->assertSame(2, VenderLayout::where('user_id', $dueno->id)->count());
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  CA5 — la siembra en las altas
     * ---------------------------------------------------------------------------------------
     *
     * NO se llama a `UserSetupHelper::run()` ni a `DemoSetupHelper::run()` (las dos arrancan con
     * `migrate:fresh`, que vaciaría la base de testing). Se lee por reflexión la lista de seeders
     * que recorren, igual que `3_Setup_siembra_el_diseno_Test`.
     */

    /**
     * El nombre corto resuelve a la clase: `db:seed` le antepone `Database\Seeders\` a un nombre
     * sin barras, y si la clase no existe el setup revienta a mitad de camino.
     *
     * @test
     */
    public function el_nombre_corto_del_seeder_resuelve_a_su_clase()
    {
        $this->afirmar_que_existe_el_seeder();
    }

    /**
     * `UserSetupHelper` y `DemoSetupHelper` siembran el ágil, DESPUÉS del predeterminado (el ágil
     * asegura primero el predeterminado, pero el orden de la lista tiene que decir lo mismo).
     *
     * @test
     */
    public function los_setups_siembran_el_agil_despues_del_predeterminado()
    {
        foreach ([UserSetupHelper::class, DemoSetupHelper::class] as $clase) {
            $seeders = $this->base_seeders($clase);

            $this->assertContains(self::SEEDER, $seeders, $clase.'::base_seeders() tiene que sembrar el diseño ágil.');
            $this->assertContains(self::SEEDER_PREDETERMINADO, $seeders);

            $this->assertSame(
                1,
                count(array_keys($seeders, self::SEEDER, true)),
                $clase.'::base_seeders() tiene que nombrar al seeder del ágil una sola vez.'
            );

            $this->assertGreaterThan(
                array_search(self::SEEDER_PREDETERMINADO, $seeders, true),
                array_search(self::SEEDER, $seeders, true),
                $clase.': '.self::SEEDER.' tiene que ir DESPUÉS de '.self::SEEDER_PREDETERMINADO.'.'
            );
        }
    }

    /**
     * `DatabaseSeeder` lo llama exactamente UNA vez, en código vivo (no comentado), en la cola
     * común de `run()` (la misma profundidad de llaves que el seeder del predeterminado) y
     * DESPUÉS de él.
     *
     * Se escanea con `token_get_all()` y no con `strpos()` por lo mismo que
     * `3_Setup_siembra_el_diseno_Test`: el archivo tiene llamadas comentadas y un match de texto no
     * distingue código vivo de código muerto.
     *
     * @test
     */
    public function database_seeder_lo_llama_una_vez_en_la_cola_comun_despues_del_predeterminado()
    {
        $archivo = base_path('database/seeders/DatabaseSeeder.php');

        $agil = $this->apariciones_vivas($archivo, self::SEEDER);
        $predeterminado = $this->apariciones_vivas($archivo, self::SEEDER_PREDETERMINADO);

        $this->assertCount(1, $predeterminado, 'Cambió DatabaseSeeder::run(): '.self::SEEDER_PREDETERMINADO.' tiene que seguir llamándose una vez; revisar este test.');
        $this->assertCount(1, $agil, 'DatabaseSeeder tiene que llamar a '.self::SEEDER.' exactamente una vez.');

        $this->assertSame(
            $predeterminado[0]['profundidad'],
            $agil[0]['profundidad'],
            self::SEEDER.' tiene que estar en la cola común de run(), a la misma profundidad que '.self::SEEDER_PREDETERMINADO.'.'
        );

        $this->assertGreaterThan(
            $predeterminado[0]['linea'],
            $agil[0]['linea'],
            self::SEEDER.' tiene que correr después de '.self::SEEDER_PREDETERMINADO.'.'
        );
    }

    /**
     * Las apariciones VIVAS (no comentadas) de un símbolo en un archivo, con su línea y su
     * profundidad de llaves.
     *
     * @param  string  $archivo
     * @param  string  $simbolo
     * @return array  Cada ítem: ['linea' => int, 'profundidad' => int].
     */
    protected function apariciones_vivas($archivo, $simbolo)
    {
        $apariciones = array();
        $profundidad = 0;

        foreach (token_get_all(file_get_contents($archivo)) as $token) {

            if (!is_array($token)) {
                if ($token === '{') {
                    $profundidad++;
                } elseif ($token === '}') {
                    $profundidad--;
                }
                continue;
            }

            // `{$var}` y `${var}` también abren bloque para el tokenizador y cierran con un '}' pelado.
            if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                $profundidad++;
                continue;
            }

            if ($token[0] === T_STRING && $token[1] === $simbolo) {
                $apariciones[] = array('linea' => $token[2], 'profundidad' => $profundidad);
            }
        }

        return $apariciones;
    }
}
