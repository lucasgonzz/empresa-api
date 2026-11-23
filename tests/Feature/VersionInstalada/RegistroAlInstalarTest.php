<?php

namespace Tests\Feature\VersionInstalada;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * CA1 y CA2 de la misión version-instalada-historial: al terminar cada actualización, el admin llama a
 * `PUT api/admin-sync/update-default-version`, y el sistema del cliente registra en `installed_versions` la
 * versión que dice el archivo VERSION del paquete, con la fecha real de ese momento.
 *
 * El contrato con el admin NO cambia: mismo status y mismas claves de respuesta que hoy.
 *
 * @group version-instalada
 */
class RegistroAlInstalarTest extends EmpresaTestCase
{
    /** Ruta del contrato con admin-api. */
    const RUTA = 'api/admin-sync/update-default-version';

    /** Clave de admin de la instancia (el escenario real de producción). */
    const CLAVE_DE_ADMIN = 'clave-de-admin-del-test-de-version-instalada';

    /** @var User Dueño A: el comercio de la instancia. */
    protected $comercio;

    /** @var User Dueño B: otro comercio de la misma base. */
    protected $vecino;

    /** @var string|null Ruta del archivo VERSION temporal del test. */
    protected $archivo_version;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::create([
            'name' => 'Comercio A', 'email' => 'a-' . uniqid() . '@test.local', 'password' => Hash::make('secret'),
        ]);
        $this->vecino = User::create([
            'name' => 'Comercio B', 'email' => 'b-' . uniqid() . '@test.local', 'password' => Hash::make('secret'),
        ]);

        config(['app.USER_ID' => $this->comercio->id]);
        config(['services.admin_api.api_key' => self::CLAVE_DE_ADMIN]);
        $this->withHeader('X-Admin-Api-Key', self::CLAVE_DE_ADMIN);

        // Hora fija (hora argentina).
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

    /**
     * Deja el archivo VERSION del paquete con el contenido dado (o inexistente si es null).
     *
     * @param  string|null $contenido Número de versión; null = el paquete no trae archivo VERSION.
     * @return void
     */
    protected function poner_version_del_codigo($contenido)
    {
        $this->archivo_version = sys_get_temp_dir() . '/version-instalada-test-' . uniqid();
        if ($contenido !== null) {
            file_put_contents($this->archivo_version, $contenido . "\n");
        }
        config(['app.version_file' => $this->archivo_version]);
    }

    /**
     * Llama al endpoint como lo llama el admin al terminar el deployment.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function cerrar_actualizacion()
    {
        return $this->putJson(self::RUTA, ['spa_url' => 'https://cliente-a.comerciocity.com']);
    }

    /** Filas de installed_versions de un comercio. */
    protected function filas($owner_id)
    {
        return DB::table('installed_versions')->where('owner_id', $owner_id)->get();
    }

    /** CA1: el cierre registra la versión del código para el comercio de la instancia. */
    public function test_el_cierre_de_la_actualizacion_registra_la_version_del_comercio()
    {
        $this->poner_version_del_codigo('4.5.0');

        $this->cerrar_actualizacion()
            ->assertStatus(200)
            ->assertJsonStructure(['ok', 'default_version', 'api_url', 'owner_id', 'users_updated'])
            ->assertJson(['ok' => true, 'owner_id' => $this->comercio->id]);

        $filas = $this->filas($this->comercio->id);
        $this->assertCount(1, $filas);
        $this->assertSame('4.5.0', $filas[0]->version);
        $this->assertSame('instalacion', $filas[0]->source);
        $this->assertSame('2026-11-23 03:14:00', $filas[0]->installed_at);
    }

    /** CA1: el comercio vecino de la misma base no recibe ninguna fila. */
    public function test_no_registra_al_comercio_vecino()
    {
        $this->poner_version_del_codigo('4.5.0');

        $this->cerrar_actualizacion()->assertStatus(200);

        $this->assertCount(0, $this->filas($this->vecino->id));
    }

    /** CA2: repetir el cierre otro día no duplica la fila ni mueve la fecha de instalación. */
    public function test_repetir_el_cierre_no_duplica_ni_cambia_la_fecha()
    {
        $this->poner_version_del_codigo('4.5.0');

        $this->cerrar_actualizacion()->assertStatus(200);

        Carbon::setTestNow('2026-11-24 10:00:00');
        $this->cerrar_actualizacion()->assertStatus(200);

        $filas = $this->filas($this->comercio->id);
        $this->assertCount(1, $filas);
        $this->assertSame('2026-11-23 03:14:00', $filas[0]->installed_at);
    }

    /** CA2: sin archivo VERSION (paquete viejo) el cierre responde igual y no registra nada. */
    public function test_sin_archivo_version_no_registra_y_responde_igual()
    {
        $this->poner_version_del_codigo(null);

        $this->cerrar_actualizacion()
            ->assertStatus(200)
            ->assertJsonStructure(['ok', 'default_version', 'api_url', 'owner_id', 'users_updated'])
            ->assertJson(['ok' => true]);

        $this->assertCount(0, $this->filas($this->comercio->id));
    }
}
