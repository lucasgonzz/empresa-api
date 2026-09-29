<?php

namespace Tests\Feature\DisenoVender;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Http\Controllers\Helpers\UserSetupHelper;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Un sistema nuevo (`UserSetupHelper`), una demo (`DemoSetupHelper`) y una corrida local
 * (`DatabaseSeeder`) nacen con el "Diseño predeterminado" de Vender (misión
 * diseno-vender-configurable, 28/9/2026).
 *
 * NO se llama a `UserSetupHelper::run()` ni a `DemoSetupHelper::run()`: las dos arrancan con
 * `migrate:fresh`, que vaciaría la base de testing del slot de la que vive el resto de la suite
 * (mismo motivo que `Preferencias/5_Default_pdf_a4_en_setup_Test`). Se lee por reflexión la lista
 * de seeders que `run()` recorre, que es lo que se desalinea en silencio: si el seeder se cae de
 * una lista, ese camino arma sistemas sin diseño y nadie se entera hasta que el index lo crea solo
 * (el index lo cubre, pero la regla de la misión es que el diseño exista desde el alta).
 *
 * @group disenos_de_vender
 */
class Setup_siembra_el_diseno_Test extends TestCase
{
    /** Nombre corto con el que los setups llaman al seeder (`db:seed --class=...`). */
    const SEEDER = 'VenderLayoutSeeder';

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

    /**
     * @test
     */
    public function user_setup_helper_siembra_el_diseno_predeterminado()
    {
        $this->assertContains(self::SEEDER, $this->base_seeders(UserSetupHelper::class));
    }

    /**
     * @test
     */
    public function demo_setup_helper_siembra_el_diseno_predeterminado()
    {
        $this->assertContains(self::SEEDER, $this->base_seeders(DemoSetupHelper::class));
    }

    /**
     * El nombre corto resuelve a la clase: `db:seed` le antepone `Database\Seeders\` a un nombre
     * sin barras, y si la clase no existe ahí el setup revienta a mitad de camino.
     *
     * @test
     */
    public function el_nombre_corto_del_seeder_resuelve_a_su_clase()
    {
        $this->assertTrue(class_exists('Database\\Seeders\\'.self::SEEDER));
    }

    /**
     * `DatabaseSeeder` lo llama UNA vez, en código vivo (no comentado), en la cola que comparten
     * todas las ramas de `run()` —la misma profundidad de llaves que `GlobalSearchDefaultsSeeder`,
     * que está ahí por el mismo motivo: necesita a los dueños ya creados—.
     *
     * Se escanea con `token_get_all()` y no con `strpos()` por lo mismo que
     * `Semilla/AlineacionLocalDemoTest`: el archivo tiene llamadas comentadas y un match de texto no
     * distingue código vivo de código muerto.
     *
     * @test
     */
    public function database_seeder_lo_llama_en_la_cola_comun_de_run()
    {
        $archivo = base_path('database/seeders/DatabaseSeeder.php');

        $propio = $this->apariciones_vivas($archivo, self::SEEDER);
        $cola = $this->apariciones_vivas($archivo, 'GlobalSearchDefaultsSeeder');

        $this->assertCount(1, $propio, 'DatabaseSeeder tiene que llamar a '.self::SEEDER.' exactamente una vez.');
        $this->assertCount(1, $cola, 'Cambió la cola común de DatabaseSeeder::run(); revisar este test.');

        $this->assertSame(
            $cola[0]['profundidad'],
            $propio[0]['profundidad'],
            self::SEEDER.' tiene que estar en la cola común de run(), fuera del if/else de las ramas.'
        );

        $this->assertGreaterThan(
            $cola[0]['linea'],
            $propio[0]['linea'],
            self::SEEDER.' tiene que correr después de que existan los dueños (al final de run()).'
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
