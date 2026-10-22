<?php

namespace Tests\Feature\AgentesIa;

use App\Http\Controllers\Helpers\AgentesIaHelper;
use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Http\Controllers\Helpers\UserSetupHelper;
use App\Http\Controllers\UserExtencionController;
use App\Models\ExtencionEmpresa;
use Database\Seeders\ExtencionAgentesIaSeeder;
use Database\Seeders\ExtencionEmpresaDescriptionSeeder;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;

/**
 * Misión agentes-ia-extension-unica (22/10/2026) — el catálogo y las altas.
 *
 * Lo que protege:
 *
 *  - `ExtencionAgentesIaSeeder` es idempotente: correrlo dos veces no duplica la fila (la tabla no tiene unique
 *    en `slug`) y deja `agentes_ia` viva, con descripción y módulo.
 *  - Marca `en_desuso` las seis extensiones que reemplaza, con el motivo, y NO las borra ni toca el pivote
 *    `extencion_empresa_user` (un rollback las necesita y los clientes las tienen asignadas).
 *  - El padrón de `ExtencionEmpresaDescriptionSeeder` (que corre ÚLTIMO en las bases nuevas) no vuelve a dejar
 *    vivas a las viejas ni a `agentes_ia` en desuso.
 *  - Un sistema real nuevo NO la trae (nadie hereda) y una demo SÍ.
 *  - El configurador de extensiones tiene el grupo «Agentes de IA»: no cae en «Sin clasificar».
 */
class Seeders_y_altas_Test extends AgentesIaTestCase
{
    /**
     * Cantidad de filas del catálogo con ese slug.
     *
     * @param  string  $slug
     * @return int
     */
    protected function filas($slug)
    {
        return ExtencionEmpresa::where('slug', $slug)->count();
    }

    /**
     * Deja el catálogo con las seis viejas (vivas, como están hoy en producción) y sin `agentes_ia`.
     *
     * @return array<string,\App\Models\ExtencionEmpresa>  Las viejas por slug.
     */
    protected function catalogo_de_produccion_antes_de_actualizar()
    {
        ExtencionEmpresa::where('slug', 'agentes_ia')->delete();

        $viejas = [];

        foreach (AgentesIaHelper::SLUGS_REEMPLAZADAS as $slug) {

            $fila = ExtencionEmpresa::where('slug', $slug)->first();

            if (is_null($fila)) {
                $fila = ExtencionEmpresa::forceCreate(['slug' => $slug, 'name' => 'Nombre viejo de ' . $slug]);
            }

            $fila->forceFill(['en_desuso' => false, 'description' => null, 'modulo' => null])->save();

            $viejas[$slug] = $fila;
        }

        return $viejas;
    }

    /**
     * @test
     * @return void
     */
    public function el_seeder_crea_la_fila_y_correrlo_dos_veces_no_duplica()
    {
        $this->catalogo_de_produccion_antes_de_actualizar();

        $this->assertSame(0, $this->filas('agentes_ia'));

        (new ExtencionAgentesIaSeeder())->run();
        (new ExtencionAgentesIaSeeder())->run();

        $this->assertSame(1, $this->filas('agentes_ia'), 'El seeder duplicó la fila al correr dos veces.');

        $fila = ExtencionEmpresa::where('slug', 'agentes_ia')->first();

        $this->assertSame('Agentes de IA', $fila->name);
        $this->assertSame('Agentes de IA', $fila->modulo);
        $this->assertFalse($fila->en_desuso);
        $this->assertNotEmpty($fila->description);
    }

    /**
     * @test
     * @return void
     */
    public function el_seeder_marca_en_desuso_las_seis_viejas_sin_borrarlas_ni_tocar_el_pivote()
    {
        $viejas = $this->catalogo_de_produccion_antes_de_actualizar();

        /* El comercio tiene asignadas las seis viejas, como hoy en producción. */
        $this->comercio()->extencions()->syncWithoutDetaching(array_map(function ($fila) {
            return $fila->id;
        }, array_values($viejas)));

        $vinculos_antes = DB::table('extencion_empresa_user')->count();

        (new ExtencionAgentesIaSeeder())->run();

        foreach ($viejas as $slug => $fila) {

            $actual = $fila->fresh();

            $this->assertNotNull($actual, $slug . ' se borró del catálogo.');
            $this->assertTrue($actual->en_desuso, $slug . ' no quedó en desuso.');
            $this->assertStringContainsString('Reemplazada por Agentes de IA', $actual->description);
            $this->assertSame(1, $this->filas($slug), $slug . ' quedó duplicada.');
        }

        $this->assertSame($vinculos_antes, DB::table('extencion_empresa_user')->count(), 'El seeder tocó las asignaciones de los comercios.');

        /* Y NO le da la nueva a nadie: «nadie hereda». */
        $this->assertFalse(AgentesIaHelper::tiene($this->comercio()->fresh()), 'El seeder le asignó agentes_ia a un comercio.');
    }

    /**
     * Si alguien la había dejado en desuso o sin describir, el seeder la deja viva y completa.
     *
     * @test
     * @return void
     */
    public function el_seeder_completa_una_fila_incompleta_sin_duplicarla()
    {
        $this->catalogo_de_produccion_antes_de_actualizar();

        ExtencionEmpresa::forceCreate(['slug' => 'agentes_ia', 'name' => 'Agentes de IA', 'en_desuso' => true]);

        (new ExtencionAgentesIaSeeder())->run();

        $this->assertSame(1, $this->filas('agentes_ia'));

        $fila = ExtencionEmpresa::where('slug', 'agentes_ia')->first();

        $this->assertFalse($fila->en_desuso);
        $this->assertNotEmpty($fila->description);
        $this->assertSame('Agentes de IA', $fila->modulo);
    }

    /**
     * El padrón (que se escribe ÚLTIMO en una base nueva) coincide con lo que dice el seeder: `agentes_ia` viva,
     * las seis viejas en desuso.
     *
     * @test
     * @return void
     */
    public function el_padron_de_descripciones_no_revive_a_las_viejas_ni_apaga_a_agentes_ia()
    {
        $padron = [];

        foreach (ExtencionEmpresaDescriptionSeeder::padron() as $fila) {
            $padron[$fila['slug']] = $fila;
        }

        $this->assertArrayHasKey('agentes_ia', $padron);
        $this->assertFalse($padron['agentes_ia']['en_desuso']);
        $this->assertSame('Agentes de IA', $padron['agentes_ia']['modulo']);

        foreach (AgentesIaHelper::SLUGS_REEMPLAZADAS as $slug) {

            $this->assertArrayHasKey($slug, $padron, $slug . ' no está en el padrón: la pantalla la mostraría «sin clasificar».');
            $this->assertTrue($padron[$slug]['en_desuso'], $slug . ' está viva en el padrón.');
            $this->assertSame('Agentes de IA', $padron[$slug]['modulo']);
        }
    }

    /**
     * Correr el seeder de descripciones sobre una base con el catálogo de producción deja `agentes_ia` y las
     * viejas como corresponde, sin pisar nada más.
     *
     * @test
     * @return void
     */
    public function el_seeder_de_descripciones_es_coherente_con_el_de_agentes_ia()
    {
        $this->catalogo_de_produccion_antes_de_actualizar();

        (new ExtencionAgentesIaSeeder())->run();
        (new ExtencionEmpresaDescriptionSeeder())->aplicar();

        $this->assertFalse(ExtencionEmpresa::where('slug', 'agentes_ia')->first()->en_desuso);

        foreach (AgentesIaHelper::SLUGS_REEMPLAZADAS as $slug) {
            $this->assertTrue(ExtencionEmpresa::where('slug', $slug)->first()->en_desuso, $slug . ' quedó viva después del padrón.');
        }
    }

    /**
     * Las altas: un sistema real nuevo NO la trae y una demo SÍ. Por reflexión sobre `base_extencions()`, como
     * hace la suite de siembra (correr `run()` entero arranca con `migrate:fresh`).
     *
     * @test
     * @return void
     */
    public function un_sistema_nuevo_no_la_trae_y_una_demo_si()
    {
        $demo = new ReflectionMethod(DemoSetupHelper::class, 'base_extencions');
        $demo->setAccessible(true);

        $real = new ReflectionMethod(UserSetupHelper::class, 'base_extencions');
        $real->setAccessible(true);

        $this->assertContains('agentes_ia', $demo->invoke(null));
        $this->assertNotContains('agentes_ia', $real->invoke(null));

        foreach (AgentesIaHelper::SLUGS_REEMPLAZADAS as $slug) {
            $this->assertNotContains($slug, $demo->invoke(null), 'La demo otorga la extensión vieja ' . $slug);
            $this->assertNotContains($slug, $real->invoke(null), 'El sistema nuevo otorga la extensión vieja ' . $slug);
        }
    }

    /**
     * El configurador de extensiones tiene el grupo «Agentes de IA» entre los módulos conocidos: la extensión
     * nueva no cae en «Sin clasificar».
     *
     * @test
     * @return void
     */
    public function el_configurador_tiene_el_modulo_agentes_de_ia()
    {
        $this->assertContains('Agentes de IA', UserExtencionController::ORDEN_MODULOS);
        $this->assertSame(ExtencionAgentesIaSeeder::MODULO, 'Agentes de IA');
    }
}
