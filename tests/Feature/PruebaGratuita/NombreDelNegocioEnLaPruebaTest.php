<?php

namespace Tests\Feature\PruebaGratuita;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Models\User;
use App\Services\DemoIndustriaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionMethod;
use Tests\TestCase;

/**
 * El nombre del negocio en la prueba gratuita (misión prueba-e2e-campana-imperio, 11/11/2026).
 *
 * El defecto: en una prueba, debajo del nombre del usuario (panel del Asistente personal) decía
 * «Punto Fierro · Demo» en vez del nombre que el negocio cargó en el alta. El demo setup guardaba el
 * `company_name` del payload y, unas líneas después, `DemoIndustriaService::preparar()` lo pisaba con la
 * marca de ejemplo del rubro.
 *
 *  1. En un setup de PRUEBA que trae `company_name`, ese nombre sobrevive al rubro (dueño y empleados).
 *  2. La DEMO queda idéntica: sigue mostrando la marca de ejemplo aunque el payload traiga `company_name`.
 *  3. El pool de pruebas que se arma antes de tener lead (sin nombre) también queda con la marca de ejemplo.
 *
 * El setup completo no se puede correr acá (vacía la base): se fijan las dos piezas que lo deciden.
 */
class NombreDelNegocioEnLaPruebaTest extends TestCase
{
    use DatabaseTransactions;

    private function nombre_del_negocio(array $data)
    {
        $metodo = new ReflectionMethod(DemoSetupHelper::class, 'nombre_del_negocio_de_la_prueba');
        $metodo->setAccessible(true);

        return $metodo->invoke(null, $data);
    }

    private function crear_usuario($nombre, $owner_id = null)
    {
        return User::create([
            'name'         => $nombre,
            'email'        => uniqid('nombre-prueba-') . '@test.local',
            'password'     => bcrypt('prueba-local'),
            'company_name' => 'Nombre anterior',
            'owner_id'     => $owner_id,
        ]);
    }

    public function test_solo_un_setup_de_prueba_con_nombre_conserva_el_del_negocio()
    {
        config(['app.FOR_USER' => 'demo']);

        $this->assertSame('Ferretería El Tornillo', $this->nombre_del_negocio([
            'company_name' => '  Ferretería El Tornillo ',
            'prueba'       => ['dias' => 7],
        ]), 'El alta de la landing trae `prueba` y el nombre que cargó el negocio.');

        $this->assertNull($this->nombre_del_negocio(['company_name' => 'Ferretería El Tornillo']), 'La demo queda idéntica: marca de ejemplo.');
        $this->assertNull($this->nombre_del_negocio(['company_name' => '   ', 'prueba' => ['dias' => 7]]));
        $this->assertNull($this->nombre_del_negocio(['company_name' => ['no es texto'], 'prueba' => ['dias' => 7]]));
        $this->assertNull($this->nombre_del_negocio(['prueba' => ['dias' => 7]]));

        config(['app.FOR_USER' => 'prueba']);

        $this->assertNull($this->nombre_del_negocio([]), 'El pool se arma sin lead: no hay nombre que conservar.');
        $this->assertSame('Ferretería El Tornillo', $this->nombre_del_negocio(['company_name' => 'Ferretería El Tornillo']));
    }

    public function test_el_rubro_respeta_el_nombre_del_negocio_y_sin_el_usa_la_marca_de_ejemplo()
    {
        $marca = config('demo_industrias.ferreteria.nombre_demo');
        $this->assertNotEmpty($marca);

        $dueno = $this->crear_usuario('Dueño de la prueba');
        $empleado = $this->crear_usuario('Empleado de la prueba', $dueno->id);

        $this->assertTrue(DemoIndustriaService::preparar($dueno, 'ferreteria', 'Ferretería El Tornillo'));

        $this->assertSame('Ferretería El Tornillo', $dueno->fresh()->company_name);
        $this->assertSame('Ferretería El Tornillo', $empleado->fresh()->company_name);
        $this->assertNotEmpty($dueno->fresh()->image_url, 'El logo sigue siendo el del rubro.');

        // La demo (sin tercer argumento) queda como siempre.
        $de_demo = $this->crear_usuario('Dueño de la demo');
        $su_empleado = $this->crear_usuario('Empleado de la demo', $de_demo->id);

        $this->assertTrue(DemoIndustriaService::preparar($de_demo, 'ferreteria'));

        $this->assertSame($marca, $de_demo->fresh()->company_name);
        $this->assertSame($marca, $su_empleado->fresh()->company_name);

        // Un nombre vacío no borra la identidad: cae a la marca de ejemplo.
        $this->assertTrue(DemoIndustriaService::preparar($dueno, 'ferreteria', '   '));
        $this->assertSame($marca, $dueno->fresh()->company_name);
    }
}
