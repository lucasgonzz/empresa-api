<?php

namespace Tests\Feature\PruebaGratuita;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use Database\Seeders\ExtencionSupportChatEnPruebaSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionMethod;
use Tests\TestCase;

/**
 * E2 de la misión prueba-ayuda-unificada-en-soporte (10/11/2026): el lead de la prueba gratuita tiene
 * Soporte (`support_chat`) y la demo sigue sin él.
 *
 *  1. DemoSetupHelper suma la extensión solo cuando el setup arma una prueba (objeto `prueba` o
 *     `FOR_USER=prueba`), antes del sync de las extensiones.
 *  2. ExtencionSupportChatEnPruebaSeeder se la prende al dueño de una instancia de prueba que ya existe,
 *     sin duplicar la fila, y no toca nada fuera de una instancia de prueba.
 */
class SoporteEnLaPruebaTest extends TestCase
{
    use DatabaseTransactions;

    private $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::create([
            'name' => 'Dueño de la prueba con soporte',
            'email' => uniqid('soporte-prueba-') . '@test.local',
            'password' => bcrypt('prueba-local'),
        ]);

        config(['app.USER_ID' => $this->dueno->id]);
        PruebaHelper::olvidar_cache();
    }

    protected function tearDown(): void
    {
        PruebaHelper::olvidar_cache();
        parent::tearDown();
    }

    private function es_setup_de_prueba(array $data)
    {
        $metodo = new ReflectionMethod(DemoSetupHelper::class, 'es_setup_de_prueba');
        $metodo->setAccessible(true);

        return $metodo->invoke(null, $data);
    }

    public function test_el_setup_suma_soporte_solo_cuando_arma_una_prueba()
    {
        config(['app.FOR_USER' => 'demo']);
        $this->assertFalse($this->es_setup_de_prueba(['business_type' => 'ferreteria']), 'La demo queda idéntica: sin Soporte.');
        $this->assertFalse($this->es_setup_de_prueba(['prueba' => 'no es objeto']));
        $this->assertTrue($this->es_setup_de_prueba(['prueba' => ['dias' => 7]]), 'El alta de la landing trae `prueba`.');

        config(['app.FOR_USER' => 'prueba']);
        $this->assertTrue($this->es_setup_de_prueba([]), 'El pool de prueba se arma sin lead y ya nace con Soporte.');

        $this->assertSame('support_chat', DemoSetupHelper::EXTENCION_SOPORTE_DE_LA_PRUEBA);
    }

    /**
     * El setup completo no se puede correr acá (vacía la base); lo que se fija es que la extensión entra a
     * la lista ANTES del sync que la engancha al dueño, que es lo que hace que la prueba nazca con ella.
     */
    public function test_la_extension_de_la_prueba_entra_antes_del_sync()
    {
        $run    = new ReflectionMethod(DemoSetupHelper::class, 'run');
        $lineas = file($run->getFileName());
        $fuente = implode('', array_slice($lineas, $run->getStartLine() - 1, $run->getEndLine() - $run->getStartLine() + 1));

        $suma = strpos($fuente, '$extencions[] = self::EXTENCION_SOPORTE_DE_LA_PRUEBA;');
        $sync = strpos($fuente, '$user->extencions()->sync(');

        $this->assertNotFalse($suma, 'run() ya no suma la extensión de Soporte de la prueba.');
        $this->assertNotFalse($sync);
        $this->assertLessThan($sync, $suma);
    }

    public function test_el_seeder_le_prende_soporte_al_dueno_de_una_prueba_que_ya_existe_sin_duplicar()
    {
        config(['app.FOR_USER' => 'prueba']);
        $filas = ExtencionEmpresa::where('slug', 'support_chat')->count();

        (new ExtencionSupportChatEnPruebaSeeder())->run();
        (new ExtencionSupportChatEnPruebaSeeder())->run();

        $this->assertSame(max(1, $filas), ExtencionEmpresa::where('slug', 'support_chat')->count(), 'No duplica la fila.');
        $dueno = User::find($this->dueno->id);
        $this->assertTrue(UserHelper::hasExtencion('support_chat', $dueno));
        $this->assertSame(1, $dueno->extencions()->where('slug', 'support_chat')->count(), 'No la engancha dos veces.');
    }

    public function test_el_seeder_no_toca_nada_fuera_de_una_instancia_de_prueba()
    {
        config(['app.FOR_USER' => 'demo']);

        (new ExtencionSupportChatEnPruebaSeeder())->run();

        $this->assertFalse(UserHelper::hasExtencion('support_chat', User::find($this->dueno->id)));
    }
}
