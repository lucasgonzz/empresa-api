<?php

namespace Tests\Feature\Semilla;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Models\OnlineConfiguration;
use App\Models\OnlinePriceType;
use Illuminate\Support\Facades\Artisan;
use ReflectionMethod;
use Tests\EmpresaTestCase;

/**
 * Misión "demo lista para grabar" (25/8/2026), punto 3a del plan.
 *
 * La tienda de la demo mostraba "Inicie sesion o Solicite alta de cliente para ver precios" en
 * cada tarjeta: `DemoSetupHelper::tienda()` creaba la `OnlineConfiguration` con
 * `online_price_type_id => 3`, que es `only_buyers_with_comerciocity_client`.
 *
 * Lo que fija este archivo son las dos mitades del arreglo, que van juntas:
 *
 * 1. Una instancia de DEMO arranca con el slug `all` ("Cualquiera que ingrese a la Web"), que es
 *    el que `tienda-spa::puede_ver_precios()` deja pasar sin sesión.
 * 2. La instalación de un CLIENTE REAL sigue arrancando con el criterio de siempre. Con qué
 *    criterio publica sus precios un comercio de verdad no es algo que se mueva de costado.
 *
 * El id se compara resolviendo la fila por SLUG, nunca contra un número escrito acá: si mañana
 * cambia el orden de `OnlinePriceTypeSeeder`, este test tiene que seguir diciendo la verdad.
 *
 * @group semilla
 */
class DemoSetupTiendaOnlineTest extends EmpresaTestCase
{
    /**
     * `TestingFerreteriaSeeder` no siembra `online_price_types` (la tabla queda vacía en la base
     * de testing), así que se corre acá el mismo seeder que `DemoSetupHelper::base_seeders()`
     * corre antes de `tienda()`. Va dentro de la transacción del test, así que el rollback lo
     * deshace.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('db:seed', ['--class' => 'OnlinePriceTypeSeeder', '--force' => true]);
    }

    /**
     * Invoca el `tienda()` privado del helper y devuelve la `OnlineConfiguration` que dejó.
     *
     * @param bool $es_instancia_de_demostracion
     * @return \App\Models\OnlineConfiguration
     */
    protected function correr_tienda($es_instancia_de_demostracion)
    {
        $user_id = config('semilla.user_id');
        config(['app.USER_ID' => $user_id]);

        OnlineConfiguration::where('user_id', $user_id)->delete();

        $metodo = new ReflectionMethod(DemoSetupHelper::class, 'tienda');
        $metodo->setAccessible(true);
        $metodo->invoke(null, $es_instancia_de_demostracion);

        return OnlineConfiguration::where('user_id', $user_id)->firstOrFail();
    }

    /**
     * @return void
     */
    public function test_una_demo_arranca_con_precios_visibles_para_cualquiera()
    {
        $configuracion = $this->correr_tienda(true);

        $tipo = OnlinePriceType::find($configuracion->online_price_type_id);

        $this->assertNotNull(
            $tipo,
            'La OnlineConfiguration de la demo quedó apuntando a un online_price_type que no existe.'
        );

        $this->assertSame(
            'all',
            $tipo->slug,
            'La tienda de la demo no arranca en "all", así que sus productos siguen sin mostrar precios '.
            'a quien entra sin sesión.'
        );
    }

    /**
     * @return void
     */
    public function test_un_cliente_real_conserva_el_criterio_de_siempre()
    {
        $configuracion = $this->correr_tienda(false);

        $tipo = OnlinePriceType::find($configuracion->online_price_type_id);

        $this->assertNotNull($tipo, 'La OnlineConfiguration quedó apuntando a un online_price_type que no existe.');

        $this->assertSame(
            'only_buyers_with_comerciocity_client',
            $tipo->slug,
            'Se le cambió de costado el criterio de precios a la tienda de un cliente real.'
        );
    }

    /**
     * Contrato con `tienda-spa`/`tienda-api`, que leen esta misma base: el slug tiene que existir
     * tal cual, escrito así. `tienda-spa::puede_ver_precios()` compara contra strings literales,
     * no contra ids.
     *
     * @return void
     */
    public function test_los_tres_slugs_que_mira_la_tienda_existen_con_el_nombre_exacto()
    {
        $slugs = OnlinePriceType::orderBy('id')->pluck('slug')->toArray();

        $this->assertSame(
            ['all', 'only_registered', 'only_buyers_with_comerciocity_client'],
            $slugs,
            'Los slugs de online_price_types dejaron de ser los que compara tienda-spa.'
        );
    }
}
