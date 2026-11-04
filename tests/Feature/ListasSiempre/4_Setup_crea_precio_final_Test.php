<?php

namespace Tests\Feature\ListasSiempre;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Http\Controllers\Helpers\UserSetupHelper;
use App\Models\PriceType;
use ReflectionMethod;

/**
 * D5 de la misión prueba-listas-siempre: el user setup y el demo setup dejan SIEMPRE una lista. Sin
 * listas en el formulario, la «Precio final»; con listas, las del formulario (y no la «Precio final»).
 * Y las tres extensiones de listas van siempre.
 *
 * `run()` de los dos helpers arranca con un `migrate:fresh` (no se puede correr en la suite), así que
 * se prueban los pasos por reflexión, con un dueño propio, igual que `6_User_setup_con_sucursal_Test`.
 */
class Setup_crea_precio_final_Test extends ListasSiempreTestCase
{
    /**
     * @param  string  $clase
     * @param  string  $metodo
     * @param  array   $argumentos
     * @return mixed
     */
    protected function invocar_privado($clase, $metodo, array $argumentos)
    {
        $reflection = new ReflectionMethod($clase, $metodo);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(null, $argumentos);
    }

    /**
     * @param  string  $clase
     * @param  array   $data
     * @return array  [User, Collection de listas]
     */
    protected function correr_crear_price_types($clase, array $data)
    {
        $dueno = $this->dueno_nuevo('setup-'.strtolower(class_basename($clase)));

        // crear_price_types() usa config('app.USER_ID'), que run() alinea con el dueño nuevo.
        config(array('app.USER_ID' => $dueno->id));

        $this->invocar_privado($clase, 'crear_price_types', array($data));

        return array($dueno, PriceType::where('user_id', $dueno->id)->orderBy('position')->get());
    }

    /** @test */
    public function el_user_setup_sin_listas_en_el_formulario_crea_precio_final()
    {
        list($dueno, $listas) = $this->correr_crear_price_types(UserSetupHelper::class, array());

        $this->assertCount(1, $listas);
        $this->assertSame(PriceTypeHelper::NOMBRE_PRECIO_FINAL, $listas[0]->name);
        $this->assertSame(1, (int) $listas[0]->es_precio_final);
        $this->assertSame(1, (int) $listas[0]->position);
        $this->assertNull($listas[0]->percentage, 'Sin margen por defecto: el margen es el del artículo.');
    }

    /** @test */
    public function el_user_setup_con_listas_en_el_formulario_crea_esas_y_no_precio_final()
    {
        list($dueno, $listas) = $this->correr_crear_price_types(UserSetupHelper::class, array(
            'use_price_lists'    => 1,
            'price_lists_detail' => array(array('name' => 'Mayorista', 'margin' => 10), array('name' => 'Minorista', 'margin' => 30)),
        ));

        $this->assertSame(array('Mayorista', 'Minorista'), $listas->pluck('name')->all());
        $this->assertSame(0, PriceType::where('user_id', $dueno->id)->where('es_precio_final', 1)->count());
    }

    /** @test */
    public function la_tilde_sin_nombres_tambien_deja_precio_final()
    {
        list($dueno, $listas) = $this->correr_crear_price_types(UserSetupHelper::class, array('use_price_lists' => 1, 'price_type_1' => ''));

        $this->assertSame(array(PriceTypeHelper::NOMBRE_PRECIO_FINAL), $listas->pluck('name')->all());
    }

    /** @test */
    public function el_demo_setup_sin_listas_crea_precio_final_y_con_listas_las_del_payload()
    {
        list($dueno, $listas) = $this->correr_crear_price_types(DemoSetupHelper::class, array());

        $this->assertSame(array(PriceTypeHelper::NOMBRE_PRECIO_FINAL), $listas->pluck('name')->all());
        $this->assertSame(1, (int) $listas[0]->es_precio_final);

        list($dueno, $listas) = $this->correr_crear_price_types(DemoSetupHelper::class, array('use_price_lists' => 1, 'price_type_1' => 'Mayorista', 'price_type_2' => 'Minorista'));

        $this->assertSame(array('Mayorista', 'Minorista'), $listas->pluck('name')->all());
        $this->assertSame(0, PriceType::where('user_id', $dueno->id)->where('es_precio_final', 1)->count());
    }

    /** @test */
    public function las_extensiones_de_listas_van_siempre_en_los_dos_setups()
    {
        foreach (array(UserSetupHelper::class, DemoSetupHelper::class) as $clase) {

            $extencions = array();
            $seeders = array();

            $this->invocar_privado($clase, 'apply_flag_rules', array(array(), &$extencions, &$seeders));

            foreach (array('articulo_margen_de_ganancia_segun_lista_de_precios', 'cambiar_price_type_en_vender', 'cambiar_price_type_en_vender_item_por_item') as $slug) {
                $this->assertContains($slug, $extencions, class_basename($clase).' tiene que dar '.$slug.' sin pedir use_price_lists.');
            }
        }
    }

    /**
     * El seeder de los diseños de etiquetas corre DESPUÉS de crear las listas: el código de run() lo
     * llama después de crear_price_types(). Se lee el código porque run() no se puede ejecutar.
     *
     * @test
     */
    public function las_listas_se_crean_antes_de_los_seeders_en_los_dos_setups()
    {
        foreach (array(UserSetupHelper::class, DemoSetupHelper::class) as $clase) {
            $codigo = file_get_contents((new \ReflectionClass($clase))->getFileName());

            $posicion_listas = strpos($codigo, 'self::crear_price_types($data);');
            $posicion_seeders = strpos($codigo, 'foreach ($seeders as $seeder)');

            $this->assertNotFalse($posicion_listas, class_basename($clase).' llama a crear_price_types() siempre.');
            $this->assertNotFalse($posicion_seeders);
            $this->assertLessThan($posicion_seeders, $posicion_listas, class_basename($clase).': las listas van antes del foreach de seeders.');
        }
    }
}
