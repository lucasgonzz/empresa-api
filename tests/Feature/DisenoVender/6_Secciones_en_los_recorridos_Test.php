<?php

namespace Tests\Feature\DisenoVender;

use App\Models\User;
use App\Models\VenderLayout;
use App\Services\SupportTourService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Los recorridos de soporte (`SupportTourService`) entienden las secciones nuevas de Vender
 * (misión vender-secciones-personalizables, 22/11/2026): CA6.
 *
 * `SupportTourService::campo_vender_disponible($dueno, $campo)` decide si un campo de Vender (hoy,
 * `recargos`, para guiar a aplicar un recargo) está a la vista del negocio según su diseño EN USO:
 * un campo que está en `sacados` y no está ubicado en ninguna sección NO está disponible.
 *
 * El problema que cuida: el método recorría sólo `VenderLayoutHelper::ETAPAS` (`etapa_1..3`). Con
 * secciones creadas por el usuario (`seccion_4`...) un campo ubicado en una de ellas, y además
 * nombrado en `sacados`, se daba por NO disponible, y el recorrido le habría mandado al dueño a
 * buscar un campo que sí ve. El método tiene que recorrer TODAS las claves de `$layout['etapas']`.
 *
 * El método es privado y de instancia: se invoca por reflexión sobre `new SupportTourService()`
 * (la clase no tiene constructor). Los diseños se guardan directo en la base (sin pasar por la
 * normalización), que es lo que lee el servicio.
 *
 * DatabaseTransactions: lo que se crea acá se revierte.
 *
 * @group disenos_de_vender
 */
class Secciones_en_los_recorridos_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * El dueño 500, sin diseños (el borrado se revierte con la transacción).
     *
     * @return \App\Models\User
     */
    protected function dueno_sin_disenos()
    {
        $dueno = User::find(500);

        if (is_null($dueno)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        VenderLayout::where('user_id', $dueno->id)->delete();

        return $dueno;
    }

    /**
     * Deja `$layout` como el diseño EN USO del dueño y le pregunta al servicio si `$campo` está
     * disponible.
     *
     * @param  array|null  $layout
     * @param  string      $campo
     * @return bool
     */
    protected function disponible_con_el_diseno($layout, $campo = 'recargos')
    {
        $dueno = $this->dueno_sin_disenos();

        VenderLayout::create([
            'user_id' => $dueno->id,
            'name'    => 'Diseño de prueba de los recorridos',
            'layout'  => $layout,
            'en_uso'  => true,
        ]);

        $metodo = new ReflectionMethod(SupportTourService::class, 'campo_vender_disponible');
        $metodo->setAccessible(true);

        return $metodo->invoke(new SupportTourService(), $dueno, $campo);
    }

    /**
     * Un v2 con `recargos` ubicado en una sección creada por el usuario (`seccion_4`) y también
     * nombrado en `sacados`: la ubicación explícita gana sobre la bandeja, así que está disponible.
     * Hoy da `false` porque sólo se miran `etapa_1..3`.
     *
     * @test
     */
    public function un_campo_ubicado_en_una_seccion_nueva_esta_disponible()
    {
        $layout = [
            'version'     => 2,
            'etapas'      => [
                'etapa_2'   => [],
                'seccion_4' => [['key' => 'recargos', 'cols' => 6]],
            ],
            'sacados'     => ['recargos'],
            'secciones'   => [
                'etapa_2'   => ['titulo' => null, 'descripcion' => null, 'color' => 'verde', 'alto' => 8, 'alto_fijo' => true, 'plegado' => 'no'],
                'seccion_4' => ['titulo' => 'Extras', 'descripcion' => null, 'color' => 'violeta', 'alto' => 4, 'alto_fijo' => false, 'plegado' => 'no'],
            ],
            'disposicion' => [
                ['columnas' => [
                    ['ancho' => 8, 'secciones' => ['etapa_2']],
                    ['ancho' => 4, 'secciones' => ['seccion_4']],
                ]],
            ],
        ];

        $this->assertTrue($this->disponible_con_el_diseno($layout));
    }

    /**
     * El mismo diseño sin ubicar el campo en ninguna sección: está sólo en `sacados`, o sea que no
     * se ve en Vender y el recorrido no tiene qué señalar.
     *
     * @test
     */
    public function un_campo_sacado_y_sin_ubicar_no_esta_disponible()
    {
        $layout = [
            'version'     => 2,
            'etapas'      => [
                'etapa_2'   => [],
                'seccion_4' => [['key' => 'observaciones', 'cols' => 6]],
            ],
            'sacados'     => ['recargos'],
            'secciones'   => [],
            'disposicion' => [
                ['columnas' => [
                    ['ancho' => 8, 'secciones' => ['etapa_2']],
                    ['ancho' => 4, 'secciones' => ['seccion_4']],
                ]],
            ],
        ];

        $this->assertFalse($this->disponible_con_el_diseno($layout));
    }

    /**
     * El diseño ágil guardado como plantilla sola (`{version: 2, plantilla: 'agil'}`, sin `etapas`)
     * no saca ningún campo: el ágil "no saca nada" (PLAN §2.6), así que el campo está disponible.
     *
     * @test
     */
    public function la_plantilla_agil_sola_deja_el_campo_disponible()
    {
        $this->assertTrue($this->disponible_con_el_diseno(['version' => 2, 'plantilla' => 'agil']));
    }

    /**
     * Lo que ya andaba sigue andando: un v1 con el campo en una de las 3 etapas y en `sacados` está
     * disponible; sin ubicarlo, no; y sin `sacados` o con el diseño predeterminado (`null`), sí.
     *
     * @test
     */
    public function el_v1_se_comporta_como_siempre()
    {
        $en_etapa_3 = [
            'version' => 1,
            'etapas'  => ['etapa_1' => [], 'etapa_2' => [], 'etapa_3' => [['key' => 'recargos', 'cols' => 6]]],
            'sacados' => ['recargos'],
        ];

        $sin_ubicar = [
            'version' => 1,
            'etapas'  => ['etapa_1' => [], 'etapa_2' => [], 'etapa_3' => []],
            'sacados' => ['recargos'],
        ];

        $sin_sacar = [
            'version' => 1,
            'etapas'  => ['etapa_1' => [], 'etapa_2' => [], 'etapa_3' => []],
            'sacados' => [],
        ];

        $this->assertTrue($this->disponible_con_el_diseno($en_etapa_3));
        $this->assertFalse($this->disponible_con_el_diseno($sin_ubicar));
        $this->assertTrue($this->disponible_con_el_diseno($sin_sacar));
        $this->assertTrue($this->disponible_con_el_diseno(null));
    }
}
