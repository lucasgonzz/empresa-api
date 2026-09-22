<?php

namespace Tests\Feature\Semilla;

use App\Models\OnlineTemplate;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Mision "plantilla-comerciocity-flota" (21/9/2026): Lucas pidio agregar la plantilla
 * "ComercioCity" a Unicas y a todos los clientes que no la tengan.
 *
 * La parte de los setups (`DemoSetupHelper`/`UserSetupHelper`) ya la cubre
 * `SetupsPlantillaYCondicionFiscalTest` desde el 27/8/2026 -- eso solo resuelve instalaciones
 * NUEVAS. Este test verifica la cobertura que faltaba: una base YA instalada, sin la fila, que
 * el proximo upgrade del cliente deja resuelta sola via migracion (mismo criterio que
 * `asegurar_payment_method_type_mercado_pago`).
 *
 * @group semilla
 */
class AsegurarOnlineTemplateComercioCityTest extends EmpresaTestCase
{
    /**
     * Instancia la migracion sin pasar por el autoload de Composer: los archivos de
     * `database/migrations` no son PSR-4, asi que hace falta `require` explicito, igual que
     * corre `php artisan migrate` internamente.
     *
     * @return \Illuminate\Database\Migrations\Migration
     */
    protected function migracion()
    {
        $archivo = glob(database_path('migrations/*_asegurar_online_template_comerciocity.php'));

        $this->assertNotEmpty($archivo, 'No encontre el archivo de la migracion asegurar_online_template_comerciocity.');

        require_once $archivo[0];

        return new \AsegurarOnlineTemplateComercioCity();
    }

    /**
     * Simula la base de un cliente que todavia no tiene la plantilla: la borra si esta (la
     * siembra `OnlineTemplateSeeder` de `TestingFerreteriaSeeder`/`DatabaseSeeder`), corre la
     * migracion, y verifica que la deja con el nombre y el slug exactos que espera `tienda-spa`.
     *
     * @return void
     */
    public function test_crea_la_plantilla_comerciocity_si_no_existe()
    {
        DB::table('online_templates')->where('slug', 'comerciocity')->delete();

        $this->migracion()->up();

        $plantilla = OnlineTemplate::where('slug', 'comerciocity')->first();

        $this->assertNotNull($plantilla, 'La migracion no creo la fila comerciocity.');
        $this->assertSame('ComercioCity', $plantilla->name);
    }

    /**
     * Correrla dos veces no puede duplicar la fila -- es exactamente el caso de un cliente que
     * ya la tenia (Unicas, y los demas que el barrido del 21/9/2026 encontro con la fila puesta).
     *
     * @return void
     */
    public function test_no_duplica_si_la_plantilla_ya_existe()
    {
        $this->migracion()->up();
        $this->migracion()->up();

        $this->assertSame(
            1,
            OnlineTemplate::where('slug', 'comerciocity')->count(),
            'Correr la migracion dos veces duplico la fila comerciocity.'
        );
    }

    /**
     * `down()` no revierte nada a proposito (ver el comentario de la migracion): borrar la fila
     * dejaria huerfanas las OnlineConfiguration que ya la eligieron.
     *
     * @return void
     */
    public function test_down_no_borra_la_plantilla()
    {
        $this->migracion()->up();

        $this->migracion()->down();

        $this->assertNotNull(
            OnlineTemplate::where('slug', 'comerciocity')->first(),
            'El down() de la migracion borro la plantilla comerciocity.'
        );
    }
}
