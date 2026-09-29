<?php

namespace Tests\Feature\EtiquetasDeGondola;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Http\Controllers\Helpers\UserSetupHelper;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Un sistema nuevo (`UserSetupHelper`) y una demo (`DemoSetupHelper`) nacen con sus diseños de
 * etiquetas de góndola (misión disenos-etiquetas-gondola, 29/9/2026).
 *
 * Mismo criterio que `DisenoVender/3_Setup_siembra_el_diseno_Test`: NO se llama a `run()` (arranca
 * con `migrate:fresh` y vaciaría la base del slot); se lee por reflexión la lista de seeders que
 * `run()` recorre, que es lo que se desalinea en silencio.
 *
 * @group etiquetas_de_gondola
 */
class Setup_siembra_los_disenos_de_etiquetas_Test extends TestCase
{
    /** Nombre corto con el que los setups llaman al seeder (`db:seed --class=...`). */
    const SEEDER = 'ArticleTicketDesignSeeder';

    /**
     * @param  string  $clase
     * @return string[]
     */
    protected function base_seeders($clase)
    {
        $metodo = new ReflectionMethod($clase, 'base_seeders');
        $metodo->setAccessible(true);

        return $metodo->invoke(null);
    }

    /** @test */
    public function user_setup_helper_siembra_los_disenos_de_etiquetas()
    {
        $this->assertContains(self::SEEDER, $this->base_seeders(UserSetupHelper::class));
    }

    /** @test */
    public function demo_setup_helper_siembra_los_disenos_de_etiquetas()
    {
        $this->assertContains(self::SEEDER, $this->base_seeders(DemoSetupHelper::class));
    }

    /**
     * En los dos setups corre después del de Vender (los dos necesitan al dueño ya creado). Las
     * listas (`crear_price_types()`) se crean antes del foreach que recorre estos seeders.
     *
     * @test
     */
    public function corre_despues_del_diseno_de_vender()
    {
        foreach (array(UserSetupHelper::class, DemoSetupHelper::class) as $clase) {
            $seeders = $this->base_seeders($clase);

            $this->assertGreaterThan(
                array_search('VenderLayoutSeeder', $seeders, true),
                array_search(self::SEEDER, $seeders, true),
                $clase
            );
        }
    }

    /**
     * El nombre corto resuelve a la clase: `db:seed` le antepone `Database\Seeders\`.
     *
     * @test
     */
    public function el_nombre_corto_del_seeder_resuelve_a_su_clase()
    {
        $this->assertTrue(class_exists('Database\\Seeders\\'.self::SEEDER));
    }
}
