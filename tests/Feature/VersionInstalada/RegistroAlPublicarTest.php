<?php

namespace Tests\Feature\VersionInstalada;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * CA3 de la misión version-instalada-historial: la publicación manual desde el admin
 * (`POST api/admin-sync/publish-version`) también registra la versión, una sola vez por uuid, y sólo cuando el
 * código instalado no la contradice. La respuesta del endpoint no cambia.
 *
 * @group version-instalada
 */
class RegistroAlPublicarTest extends EmpresaTestCase
{
    /** Ruta del contrato con admin-api. */
    const RUTA = 'api/admin-sync/publish-version';

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
        $this->withHeader('X-Admin-Api-Key', self::CLAVE_DE_ADMIN);

        // La base de testing puede traer versiones sincronizadas de otras suites: se parte de cero.
        DB::table('synced_version_notifications')->delete();
        DB::table('synced_versions')->delete();

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

    /** Deja el archivo VERSION con el contenido dado (null = el archivo no existe). */
    protected function poner_version_del_codigo($contenido)
    {
        $this->archivo_version = sys_get_temp_dir() . '/version-instalada-test-' . uniqid();
        if ($contenido !== null) {
            file_put_contents($this->archivo_version, $contenido . "\n");
        }
        config(['app.version_file' => $this->archivo_version]);
    }

    /**
     * Publica una versión como lo hace el admin.
     *
     * @param  string $uuid    Uuid de la versión en el admin.
     * @param  string $version Número de versión.
     * @return \Illuminate\Testing\TestResponse
     */
    protected function publicar($uuid, $version)
    {
        return $this->postJson(self::RUTA, [
            'version' => [
                'uuid' => $uuid, 'version' => $version, 'title' => 'Versión ' . $version,
                'description' => 'Descripción', 'published_at' => '2026-11-20 09:00:00',
            ],
            'notifications' => [],
        ]);
    }

    /** Filas del comercio. */
    protected function filas()
    {
        return DB::table('installed_versions')->where('owner_id', $this->comercio->id)->get();
    }

    /** CA3: publicar registra una fila `sincronizacion`; re-publicar el mismo uuid otro día no cambia nada. */
    public function test_publicar_registra_una_vez_por_uuid()
    {
        $this->poner_version_del_codigo(null);

        $this->publicar('uuid-u1', '4.5.0')->assertStatus(200);

        $filas = $this->filas();
        $this->assertCount(1, $filas);
        $this->assertSame('4.5.0', $filas[0]->version);
        $this->assertSame('sincronizacion', $filas[0]->source);
        $this->assertSame('uuid-u1', $filas[0]->synced_version_uuid);
        $this->assertSame('2026-11-23 03:14:00', $filas[0]->installed_at);

        Carbon::setTestNow('2026-11-24 10:00:00');
        $this->publicar('uuid-u1', '4.5.0')->assertStatus(200);

        $filas = $this->filas();
        $this->assertCount(1, $filas);
        $this->assertSame('2026-11-23 03:14:00', $filas[0]->installed_at);
    }

    /** CA3: si el código instalado es 4.6.0, publicar una 4.4.0 no la cuenta como instalada. */
    public function test_publicar_una_version_distinta_del_codigo_no_crea_fila()
    {
        $this->poner_version_del_codigo('4.6.0');

        $this->publicar('uuid-u0', '4.4.0')->assertStatus(200);

        $instaladas = DB::table('installed_versions')
            ->where('owner_id', $this->comercio->id)
            ->where('version', '4.4.0')
            ->whereIn('source', ['instalacion', 'sincronizacion', 'detectada'])
            ->count();

        $this->assertSame(0, $instaladas);
    }

    /** CA3: el contrato con el admin se mantiene (status y forma de la respuesta). */
    public function test_la_respuesta_de_publish_version_no_cambia()
    {
        $this->poner_version_del_codigo(null);

        $this->publicar('uuid-u1', '4.5.0')
            ->assertStatus(200)
            ->assertExactJson([
                'ok' => true,
                'synced_version' => [
                    'uuid' => 'uuid-u1', 'version' => '4.5.0', 'notifications_count' => 0,
                ],
            ]);

        // Y el registro nuevo existe (el contrato se mantiene Y la función se cumple).
        $this->assertCount(1, $this->filas());
    }
}
