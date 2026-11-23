<?php

namespace Tests\Feature\VersionInstalada;

use App\Http\Controllers\Helpers\ResetDatosNegocio\CatalogoDeTablasDelResetHelper;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\EmpresaTestCase;

/**
 * CA5, CA6 y CA7 de la misión version-instalada-historial: `GET api/installed-version` devuelve la versión actual
 * y el historial del comercio del usuario logueado (dueño o empleado), con fechas en hora argentina.
 *
 * @group version-instalada
 */
class ConsultaDeVersionInstaladaTest extends EmpresaTestCase
{
    /** Ruta del endpoint nuevo. */
    const RUTA = 'api/installed-version';

    /** @var User Dueño A. */
    protected $comercio;

    /** @var User Empleado de A. */
    protected $empleado;

    /** @var User Dueño B (otro comercio de la misma base). */
    protected $vecino;

    /** @var string|null Ruta del archivo VERSION temporal. */
    protected $archivo_version;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::create([
            'name' => 'Comercio A', 'email' => 'a-' . uniqid() . '@test.local', 'password' => Hash::make('secret'),
        ]);
        $this->empleado = User::create([
            'name' => 'Empleado de A', 'owner_id' => $this->comercio->id,
            'email' => 'emp-' . uniqid() . '@test.local', 'password' => Hash::make('secret'),
        ]);
        $this->vecino = User::create([
            'name' => 'Comercio B', 'email' => 'b-' . uniqid() . '@test.local', 'password' => Hash::make('secret'),
        ]);

        config(['app.USER_ID' => $this->comercio->id]);

        DB::table('synced_version_notifications')->delete();
        DB::table('synced_versions')->delete();

        Carbon::setTestNow('2026-11-23 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->archivo_version && file_exists($this->archivo_version)) {
            unlink($this->archivo_version);
        }
        parent::tearDown();
    }

    /** Deja el archivo VERSION con el contenido dado (null = no existe). */
    protected function poner_version_del_codigo($contenido)
    {
        $this->archivo_version = sys_get_temp_dir() . '/version-instalada-test-' . uniqid();
        if ($contenido !== null) {
            file_put_contents($this->archivo_version, $contenido . "\n");
        }
        config(['app.version_file' => $this->archivo_version]);
    }

    /** Inserta una fila de installed_versions. */
    protected function fila($owner_id, $version, $installed_at, $source)
    {
        DB::table('installed_versions')->insert([
            'owner_id' => $owner_id, 'version' => $version, 'installed_at' => $installed_at,
            'source' => $source, 'created_at' => '2026-11-22 00:00:00', 'updated_at' => '2026-11-22 00:00:00',
        ]);
    }

    /** CA5: actual + historial en orden descendente y con offset -03:00. */
    public function test_devuelve_actual_e_historial_ordenado_con_offset()
    {
        $this->poner_version_del_codigo('4.5.0');
        $this->fila($this->comercio->id, '4.4.0', '2026-11-01 10:00:00', 'historial_previo');
        $this->fila($this->comercio->id, '4.5.0', '2026-11-23 03:14:00', 'instalacion');

        Sanctum::actingAs($this->comercio, ['*']);

        $this->getJson(self::RUTA)
            ->assertStatus(200)
            ->assertJsonPath('timezone', 'America/Argentina/Buenos_Aires')
            ->assertJsonPath('code_version', '4.5.0')
            ->assertJsonPath('current.version', '4.5.0')
            ->assertJsonPath('current.installed_at', '2026-11-23T03:14:00-03:00')
            ->assertJsonPath('current.source', 'instalacion')
            ->assertJsonCount(2, 'history')
            ->assertJsonPath('history.0.version', '4.5.0')
            ->assertJsonPath('history.0.installed_at', '2026-11-23T03:14:00-03:00')
            ->assertJsonPath('history.1.version', '4.4.0')
            ->assertJsonPath('history.1.installed_at', '2026-11-01T10:00:00-03:00')
            ->assertJsonPath('history.1.source', 'historial_previo');
    }

    /** CA5: un empleado ve el historial de su comercio (el del dueño). */
    public function test_empleado_ve_el_historial_de_su_comercio()
    {
        $this->poner_version_del_codigo('4.5.0');
        $this->fila($this->comercio->id, '4.5.0', '2026-11-23 03:14:00', 'instalacion');

        Sanctum::actingAs($this->empleado, ['*']);

        $this->getJson(self::RUTA)
            ->assertStatus(200)
            ->assertJsonPath('current.version', '4.5.0')
            ->assertJsonCount(1, 'history');
    }

    /** CA5: nunca aparecen filas de otro comercio de la misma base. */
    public function test_no_ve_otro_comercio()
    {
        $this->poner_version_del_codigo('4.5.0');
        $this->fila($this->comercio->id, '4.5.0', '2026-11-23 03:14:00', 'instalacion');
        $this->fila($this->vecino->id, '9.9.9', '2026-11-22 03:14:00', 'instalacion');

        Sanctum::actingAs($this->comercio, ['*']);

        $respuesta = $this->getJson(self::RUTA)->assertStatus(200);

        $versiones = [];
        foreach ($respuesta->json('history') as $item) {
            $versiones[] = $item['version'];
        }
        $this->assertSame(['4.5.0'], $versiones);
    }

    /** CA5: sin sesión, 401. */
    public function test_sin_sesion_401()
    {
        // EmpresaTestCase deja una sesión web abierta: se cierra para probar el pedido anónimo.
        auth()->logout();

        $this->getJson(self::RUTA)->assertStatus(401);
    }

    /** CA6: sin VERSION, sin filas ni synced_versions, se muestra la versión que ya tenía el dueño. */
    public function test_sin_registro_usa_version_name()
    {
        $this->poner_version_del_codigo(null);
        $this->comercio->version_name = '4.2.0';
        $this->comercio->save();

        Sanctum::actingAs($this->comercio, ['*']);

        $this->getJson(self::RUTA)
            ->assertStatus(200)
            ->assertJsonPath('current.version', '4.2.0')
            ->assertJsonPath('current.installed_at', null)
            ->assertJsonPath('current.source', 'sin_registro')
            ->assertJsonCount(0, 'history');
    }

    /** CA7: la tabla nueva está clasificada como de sistema (el reset de datos la conserva). */
    public function test_la_tabla_esta_clasificada_en_el_reset_como_de_sistema()
    {
        $this->assertContains('installed_versions', CatalogoDeTablasDelResetHelper::TABLAS_DE_SISTEMA);
    }
}
