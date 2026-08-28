<?php

namespace Tests\Feature\Semilla;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Http\Controllers\Helpers\UserSetupHelper;
use App\Models\OnlineConfiguration;
use App\Models\OnlineTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use ReflectionMethod;
use Tests\EmpresaTestCase;

/**
 * Mision "plantilla ComercioCity y condicion fiscal en los setups" (27/8/2026).
 *
 * Fija dos cosas que hasta ahora quedaban libradas a un default y que Lucas pidio explicitas, en
 * los DOS caminos de alta -- la demo (`DemoSetupHelper`) y la instalacion de un cliente real
 * (`UserSetupHelper`):
 *
 * 1. La tienda online nace con la plantilla de diseño ComercioCity. Antes ninguno de los dos
 *    helpers pasaba `online_template_id`, asi que la columna caia en su default de migracion
 *    (`1` = "Moderno") sin que nada lo dijera en voz alta.
 * 2. La cuenta nace con la dinamica de costeo por condicion fiscal encendida
 *    (`usar_condicion_fiscal_en_costeo = 1`) y como Responsable Inscripto
 *    (`condicion_iva_precios = 'RRII'`), igual que ya hacia `HelperController::store_user()`.
 *
 * El id de la plantilla se compara resolviendo la fila por SLUG y nunca contra un numero escrito
 * aca: si mañana cambia el orden de `OnlineTemplateSeeder`, este test tiene que seguir diciendo
 * la verdad. Es el mismo criterio de `DemoSetupTiendaOnlineTest`.
 *
 * @group semilla
 */
class SetupsPlantillaYCondicionFiscalTest extends EmpresaTestCase
{
    /**
     * Id de usuario que se usa para las altas de este archivo. Es alto a proposito: los dos
     * helpers hacen `User::create()` con un id explicito y chocarian con el dueño que sembro
     * `TestingFerreteriaSeeder`. Todo corre dentro de la transaccion del test, asi que el
     * rollback lo deshace.
     */
    const USER_ID_DE_PRUEBA = 900001;

    /**
     * `TestingFerreteriaSeeder` no siembra `online_templates` (la tabla queda vacia en la base de
     * testing), asi que se corre aca el mismo seeder que `base_seeders()` corre antes de
     * `tienda()` en los dos helpers. Va dentro de la transaccion del test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('db:seed', ['--class' => 'OnlineTemplateSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'OnlinePriceTypeSeeder', '--force' => true]);
    }

    /**
     * Invoca un metodo privado estatico de un helper de setup.
     *
     * @param string $clase
     * @param string $metodo
     * @param array  $argumentos
     * @return mixed
     */
    protected function invocar_privado($clase, $metodo, array $argumentos)
    {
        $reflection = new ReflectionMethod($clase, $metodo);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(null, $argumentos);
    }

    /**
     * Deja la `OnlineConfiguration` que creo el `tienda()` del helper pedido.
     *
     * @param string $clase
     * @param array  $argumentos
     * @return \App\Models\OnlineConfiguration
     */
    protected function correr_tienda($clase, array $argumentos)
    {
        config(['app.USER_ID' => self::USER_ID_DE_PRUEBA]);

        OnlineConfiguration::where('user_id', self::USER_ID_DE_PRUEBA)->delete();

        $this->invocar_privado($clase, 'tienda', $argumentos);

        return OnlineConfiguration::where('user_id', self::USER_ID_DE_PRUEBA)->firstOrFail();
    }

    /**
     * Slug de la plantilla a la que apunta una `OnlineConfiguration`.
     *
     * @param \App\Models\OnlineConfiguration $configuracion
     * @return string|null
     */
    protected function slug_de_la_plantilla($configuracion)
    {
        $plantilla = OnlineTemplate::find($configuracion->online_template_id);

        $this->assertNotNull(
            $plantilla,
            'La OnlineConfiguration quedo apuntando a un online_template que no existe.'
        );

        return $plantilla->slug;
    }

    /**
     * @return void
     */
    public function test_la_tienda_de_la_demo_nace_con_la_plantilla_comerciocity()
    {
        $configuracion = $this->correr_tienda(DemoSetupHelper::class, [true]);

        $this->assertSame(
            'comerciocity',
            $this->slug_de_la_plantilla($configuracion),
            'La tienda de la demo no arranca con la plantilla ComercioCity.'
        );
    }

    /**
     * @return void
     */
    public function test_la_tienda_de_un_cliente_real_nace_con_la_plantilla_comerciocity()
    {
        $configuracion = $this->correr_tienda(UserSetupHelper::class, [[]]);

        $this->assertSame(
            'comerciocity',
            $this->slug_de_la_plantilla($configuracion),
            'La tienda de un cliente real no arranca con la plantilla ComercioCity.'
        );
    }

    /**
     * El slug tiene que existir escrito asi, en minusculas y sin separadores: `tienda-spa` arma
     * la clase CSS concatenando 'plantilla-' + slug, y contra esa clase estan escritos todos los
     * selectores de la plantilla. Un slug distinto la deja elegible pero sin un solo estilo
     * aplicado, y no da error en ningun lado.
     *
     * @return void
     */
    public function test_el_slug_que_mira_la_tienda_existe_con_el_nombre_exacto()
    {
        $this->assertNotNull(
            OnlineTemplate::where('slug', 'comerciocity')->first(),
            'Dejo de existir la fila "comerciocity" en online_templates, que es la que consume tienda-spa.'
        );
    }

    /**
     * @return void
     */
    public function test_el_usuario_de_la_demo_nace_con_el_costeo_por_condicion_fiscal_activo()
    {
        config(['app.USER_ID' => self::USER_ID_DE_PRUEBA]);

        $user = $this->invocar_privado(DemoSetupHelper::class, 'create_demo_user', [[
            'name'          => 'Demo de prueba',
            'company_name'  => 'Demo de prueba',
            'email'         => 'demo-plantilla-'.self::USER_ID_DE_PRUEBA.'@comerciocity.test',
        ]]);

        // Se relee de la base y no se mira el modelo en memoria: asi el test tambien cae si
        // alguna vez `User` deja de tener `$guarded = []` y el mass assignment se come las dos
        // claves en silencio.
        $guardado = User::find($user->id);

        $this->assertEquals(
            1,
            $guardado->usar_condicion_fiscal_en_costeo,
            'La demo nace con la tilde "Calcular costos segun la condicion de IVA" apagada.'
        );

        $this->assertSame(
            User::CONDICION_RRII,
            $guardado->condicion_iva_precios,
            'La demo no nace como Responsable Inscripto.'
        );
    }

    /**
     * @return void
     */
    public function test_el_usuario_de_un_cliente_real_nace_con_el_costeo_por_condicion_fiscal_activo()
    {
        $user = $this->invocar_privado(UserSetupHelper::class, 'create_user', [[
            'user_id'       => self::USER_ID_DE_PRUEBA,
            'user_name'     => 'Cliente de prueba',
            'company_name'  => 'Cliente de prueba',
            'email'         => 'cliente-plantilla-'.self::USER_ID_DE_PRUEBA.'@comerciocity.test',
        ]]);

        // Mismo criterio que el test de la demo: se relee de la base.
        $guardado = User::find($user->id);

        $this->assertEquals(
            1,
            $guardado->usar_condicion_fiscal_en_costeo,
            'Un cliente real nace con la tilde "Calcular costos segun la condicion de IVA" apagada.'
        );

        $this->assertSame(
            User::CONDICION_RRII,
            $guardado->condicion_iva_precios,
            'Un cliente real no nace como Responsable Inscripto.'
        );
    }
}
