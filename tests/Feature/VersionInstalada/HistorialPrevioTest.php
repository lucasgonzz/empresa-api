<?php

namespace Tests\Feature\VersionInstalada;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\EmpresaTestCase;

/**
 * CA4 de la misión version-instalada-historial: el historial previo se toma de `synced_versions` con su fecha de
 * publicación (aproximada), sólo hasta la versión que corre el código, una vez por comercio.
 *
 * @group version-instalada
 */
class HistorialPrevioTest extends EmpresaTestCase
{
    /** Clave de admin de la instancia. */
    const CLAVE_DE_ADMIN = 'clave-de-admin-del-test-de-version-instalada';

    /** @var User Dueño del comercio de la instancia. */
    protected $comercio;

    /** @var string|null Ruta del archivo VERSION temporal. */
    protected $archivo_version;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::create([
            'name' => 'Comercio A', 'email' => 'a-' . uniqid() . '@test.local', 'password' => Hash::make('secret'),
        ]);

        config(['app.USER_ID' => $this->comercio->id]);
        config(['services.admin_api.api_key' => self::CLAVE_DE_ADMIN]);

        DB::table('synced_version_notifications')->delete();
        DB::table('synced_versions')->delete();

        // El código instalado es la 4.5.0.
        $this->archivo_version = sys_get_temp_dir() . '/version-instalada-test-' . uniqid();
        file_put_contents($this->archivo_version, "4.5.0\n");
        config(['app.version_file' => $this->archivo_version]);

        Carbon::setTestNow('2026-11-23 03:14:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->archivo_version && file_exists($this->archivo_version)) {
            unlink($this->archivo_version);
        }
        parent::tearDown();
    }

    /** Inserta una versión sincronizada con su fecha de publicación. */
    protected function sincronizada($uuid, $version, $published_at)
    {
        DB::table('synced_versions')->insert([
            'uuid' => $uuid, 'version' => $version, 'title' => 'Versión ' . $version,
            'description' => null, 'published_at' => $published_at, 'is_current' => false,
            'created_at' => '2026-11-22 00:00:00', 'updated_at' => '2026-11-22 00:00:00',
        ]);
    }

    /** Filas del comercio por versión. */
    protected function filas_por_version()
    {
        return DB::table('installed_versions')->where('owner_id', $this->comercio->id)
            ->get()->keyBy('version');
    }

    /** CA4: el primer GET copia 4.3.0 y 4.4.0, detecta 4.5.0 y deja afuera la 4.7.0 (más nueva que el código). */
    public function test_copia_synced_versions_hasta_la_version_del_codigo()
    {
        $this->sincronizada('u-430', '4.3.0', '2026-10-01 09:00:00');
        $this->sincronizada('u-440', '4.4.0', '2026-11-01 10:00:00');
        $this->sincronizada('u-470', '4.7.0', '2026-11-20 08:00:00');

        Sanctum::actingAs($this->comercio, ['*']);
        $this->getJson('api/installed-version')->assertStatus(200);

        $filas = $this->filas_por_version();
        $this->assertSame(['4.3.0', '4.4.0', '4.5.0'], $filas->keys()->sort()->values()->all());

        $this->assertSame('historial_previo', $filas['4.3.0']->source);
        $this->assertSame('2026-10-01 09:00:00', $filas['4.3.0']->installed_at);
        $this->assertSame('historial_previo', $filas['4.4.0']->source);
        $this->assertSame('2026-11-01 10:00:00', $filas['4.4.0']->installed_at);
        $this->assertSame('detectada', $filas['4.5.0']->source);
        $this->assertSame('2026-11-23 03:14:00', $filas['4.5.0']->installed_at);
    }

    /** CA4: consultar de nuevo no agrega filas. */
    public function test_no_duplica_en_consultas_sucesivas()
    {
        $this->sincronizada('u-430', '4.3.0', '2026-10-01 09:00:00');
        $this->sincronizada('u-440', '4.4.0', '2026-11-01 10:00:00');

        Sanctum::actingAs($this->comercio, ['*']);
        $this->getJson('api/installed-version')->assertStatus(200);
        $this->getJson('api/installed-version')->assertStatus(200);

        $this->assertSame(3, DB::table('installed_versions')->where('owner_id', $this->comercio->id)->count());
    }

    /** CA4: si la 4.5.0 estaba como fecha aproximada y llega el cierre real, pasa a `instalacion` con la hora real. */
    public function test_la_instalacion_reemplaza_la_fecha_aproximada()
    {
        DB::table('installed_versions')->insert([
            'owner_id' => $this->comercio->id, 'version' => '4.5.0', 'installed_at' => '2026-11-20 09:00:00',
            'source' => 'historial_previo', 'created_at' => '2026-11-22 00:00:00', 'updated_at' => '2026-11-22 00:00:00',
        ]);

        $this->withHeader('X-Admin-Api-Key', self::CLAVE_DE_ADMIN);
        $this->putJson('api/admin-sync/update-default-version', ['spa_url' => 'https://cliente-a.comerciocity.com'])
            ->assertStatus(200);

        $filas = DB::table('installed_versions')->where('owner_id', $this->comercio->id)->get();
        $this->assertCount(1, $filas);
        $this->assertSame('instalacion', $filas[0]->source);
        $this->assertSame('2026-11-23 03:14:00', $filas[0]->installed_at);
    }
}
