<?php

namespace Tests\Feature\Semilla;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\EmpresaTestCase;

/**
 * Misión 63 (siembra-local-igual-a-demo): la corrida de seeders de LOCAL y el armado de una
 * instancia de DEMO tienen que dejar los mismos datos.
 *
 * Por qué el test tiene esta forma y no siembra las dos bases de verdad: el camino real de la
 * demo es `DemoSetupHelper::run()`, que arranca con un `migrate:fresh` -- o sea que vaciaría la
 * base de testing del slot, se llevaría puesto el fixture de `TestingFerreteriaSeeder` y dejaría
 * al resto de la suite sin nada contra qué correr -- y tarda del orden de media hora (medido:
 * 28 minutos contra `empresa_testing_s2`). Eso no entra en una suite que gobierna merges.
 *
 * Lo que sí se puede fijar acá, y es donde estuvo el problema real, son las dos cosas que se
 * desalinearon en silencio:
 *
 * 1. QUÉ SEEDERS corre cada camino. La lista de la demo es un array escrito a mano en
 *    `DemoSetupHelper` y la de local es el árbol de `if` de `DatabaseSeeder`: nada las ataba, y
 *    la medición del 25/8/2026 encontró 32 seeders que corrían en local y no en la demo. Los
 *    tests 1 y 2 resuelven la lista de la demo por el mismo camino que `run()` y la comparan
 *    contra el conjunto canónico.
 * 2. Que los seeders que se agregaron a la demo sean IDEMPOTENTES. Varios de ellos ya existían
 *    como seeder suelto para correr sobre bases de producción, y `permission_empresas.slug` /
 *    `extencion_empresas.slug` no tienen índice único: un `create()` pelado deja la fila
 *    repetida en la pantalla del sistema. Los tests 3 a 5 los corren dos veces contra la base y
 *    verifican que la segunda no agregue nada.
 *
 * @group semilla
 */
class AlineacionLocalDemoTest extends EmpresaTestCase
{
    /**
     * Seeders que las DOS corridas -- `php artisan migrate:fresh --seed` en local con
     * `FOR_USER=demo`, y `DemoSetupHelper::run()` en una instancia de demo -- tienen que ejecutar.
     *
     * Es la lista que quedó después de la alineación de la misión 63, medida corriendo los dos
     * caminos contra `empresa_testing_s2` y comparando la salida real de `db:seed`. No están los
     * catálogos estructurales que ya estaban en las dos listas desde antes (monedas, IVA,
     * unidades de medida, etc.): lo que este test cuida son los que se habían caído de una de las
     * dos.
     *
     * 🔴 Si agregás un seeder a `DatabaseSeeder` en un tramo que corre para `FOR_USER=demo`,
     * agregalo también a `DemoSetupHelper::base_seeders()` y sumalo acá. Si no, local y la demo
     * vuelven a mostrar datos distintos y nadie se entera hasta que un lead ve una pantalla vacía
     * que en local estaba llena.
     */
    const SEEDERS_COMPARTIDOS = [
        /* Catálogos estructurales que faltaban del lado de la demo. */
        'ProvinciaSeeder',
        'PaisExportacionSeeder',
        'PlatformSeeder',
        'MeliListingTypeSeeder',
        'MeliBuyingModeSeeder',
        'MeliItemConditionSeeder',
        'TiendaNubeOrderStatusSeeder',
        'ConceptoMovimientoCajaCompensacionSeeder',
        'PermissionEmpresaWhatsappSeeder',
        'ExtencionDuplicarPresupuestosSeeder',
        'ExtencionEmpresaAiExcelImportSeeder',
        'ExtencionEmpresaDescriptionSeeder',

        /* Contenido de ejemplo que faltaba del lado de la demo. */
        'ArticlePdfSeeder',
        'CuotaSeeder',
        'ProviderDiscountSeeder',
        'TurnoCajaSeeder',
        'DeliveryDaySeeder',
        'MeliPlatformConnectorSeeder',
        'OrderSeeder',
        'CartSeeder',

        /* Catálogos del módulo de producción, que faltaban del lado de LOCAL. */
        'OrderProductionStatusSeeder',
        'ProductionBatchStatusSeeder',
        'ProductionBatchMovementTypeSeeder',
        'RecipeRouteTypeSeeder',

        /* Los que ya estaban en los dos y sostienen la aritmética de `semilla:datos`. */
        'AddressSeeder',
        'ClientSeeder',
        'BuyerSeeder',
        'ProviderSeeder',
        'ExpenseCategorySeeder',
        'ExpenseConceptSeeder',
        'SaleTaxSeeder',
        'FerreteriaArticlesSeeder',
        'BudgetSeeder',
        'ChequeSeeder',
        'CajaSeeder',
    ];

    /**
     * Pares `[antes, después]` que la lista de la demo tiene que respetar, con el motivo por el
     * que cada uno importa. Un reordenamiento que los rompa no falla ruidoso: deja filas
     * huérfanas o vacías, que es como se descubrieron.
     */
    const DEPENDENCIAS_DE_ORDEN = [
        /* Usa `provider_id => 1`, el primer proveedor que crea ProviderSeeder. */
        ['ProviderSeeder', 'ProviderDiscountSeeder'],
        /* Escribe address_id 1..4 contra las sucursales de AddressSeeder. */
        ['AddressSeeder', 'ClientSeeder'],
        /* ExpenseConceptSeeder hardcodea expense_category_id 1 y 2. */
        ['ExpenseCategorySeeder', 'ExpenseConceptSeeder'],
        /* Los conceptos de compensación ocupan los ids 7..10; los base, del 1 al 6. */
        ['ConceptoMovimientoCajaSeeder', 'ConceptoMovimientoCajaCompensacionSeeder'],
        /* MeliPlatformConnectorSeeder usa platform_id => 1, que crea PlatformSeeder. */
        ['PlatformSeeder', 'MeliPlatformConnectorSeeder'],
        /* Los dos leen el catálogo: sin artículos dejan pedidos y carritos sin renglones. */
        ['FerreteriaArticlesSeeder', 'OrderSeeder'],
        ['FerreteriaArticlesSeeder', 'CartSeeder'],
        /* Necesitan los compradores ya creados. */
        ['BuyerSeeder', 'OrderSeeder'],
        ['BuyerSeeder', 'CartSeeder'],
        /* No inserta extensiones: le escribe la descripción a las que ya insertaron las otras. */
        ['ExtencionDuplicarPresupuestosSeeder', 'ExtencionEmpresaDescriptionSeeder'],
        ['ExtencionEmpresaAiExcelImportSeeder', 'ExtencionEmpresaDescriptionSeeder'],
    ];

    /**
     * Resuelve la lista de seeders de una demo por el MISMO camino que `DemoSetupHelper::run()`:
     * la lista base más lo que le agregan las reglas por tipo de negocio y por las casillas del
     * formulario. Se usa reflexión porque los tres métodos son privados y no se los hace públicos
     * solo para el test -- este helper corre en producción cada vez que un lead agenda una demo,
     * y ampliarle la superficie pública para probarlo es al revés de lo que conviene.
     *
     * @param array<string,mixed> $data Payload del formulario del lead.
     * @return string[] Nombres de clase de los seeders, en el orden en que `run()` los ejecuta.
     */
    protected function seeders_de_la_demo(array $data = ['business_type' => 'ferreteria'])
    {
        $clase = DemoSetupHelper::class;

        $base = new ReflectionMethod($clase, 'base_seeders');
        $base->setAccessible(true);

        /** @var string[] $seeders */
        $seeders = $base->invoke(null);

        /* Las dos reglas mutan $seeders y $extencions por referencia. */
        $extencions = [];

        $por_tipo = new ReflectionMethod($clase, 'apply_business_type_rules');
        $por_tipo->setAccessible(true);
        $por_tipo->invokeArgs(null, [$data, &$extencions, &$seeders]);

        $por_flags = new ReflectionMethod($clase, 'apply_flag_rules');
        $por_flags->setAccessible(true);
        $por_flags->invokeArgs(null, [$data, &$extencions, &$seeders]);

        return $seeders;
    }

    /**
     * Test 1 -- ningún seeder del conjunto compartido puede faltar de la lista de la demo.
     *
     * @return void
     */
    public function test_la_lista_de_seeders_de_la_demo_incluye_todo_lo_que_siembra_una_corrida_local()
    {
        $seeders = $this->seeders_de_la_demo();

        $faltantes = array_values(array_diff(self::SEEDERS_COMPARTIDOS, $seeders));

        $this->assertSame(
            [],
            $faltantes,
            'Estos seeders corren en la corrida local y se cayeron de DemoSetupHelper: '
                .implode(', ', $faltantes)
                .'. Una demo va a mostrar menos datos que los que Lucas verifica en local.'
        );
    }

    /**
     * Test 2 -- el orden relativo de los pares con dependencia real.
     *
     * @return void
     */
    public function test_la_lista_de_seeders_de_la_demo_respeta_el_orden_de_las_dependencias()
    {
        $seeders = $this->seeders_de_la_demo();

        foreach (self::DEPENDENCIAS_DE_ORDEN as $par) {
            $posicion_del_primero = array_search($par[0], $seeders, true);
            $posicion_del_segundo = array_search($par[1], $seeders, true);

            $this->assertNotFalse($posicion_del_primero, $par[0].' no está en la lista de la demo.');
            $this->assertNotFalse($posicion_del_segundo, $par[1].' no está en la lista de la demo.');

            $this->assertLessThan(
                $posicion_del_segundo,
                $posicion_del_primero,
                $par[0].' tiene que correr ANTES que '.$par[1].': el segundo depende de las filas '
                    .'que deja el primero y, si corre antes, no falla, siembra de menos.'
            );
        }
    }

    /**
     * Test 3 -- los cuatro conceptos de compensación de caja quedan en los ids 7 a 10 y correr el
     * seeder dos veces no agrega ninguno.
     *
     * @return void
     */
    public function test_los_conceptos_de_compensacion_de_caja_quedan_en_los_ids_7_a_10_y_no_duplican()
    {
        $esperados = [
            7  => 'Eliminación de Venta',
            8  => 'Eliminación de Gasto',
            9  => 'Eliminación de Pago de Cliente',
            10 => 'Eliminación de Pago a Proveedor',
        ];

        Artisan::call('db:seed', ['--class' => 'ConceptoMovimientoCajaCompensacionSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'ConceptoMovimientoCajaCompensacionSeeder', '--force' => true]);

        foreach ($esperados as $id => $nombre) {
            $filas = DB::table('concepto_movimiento_cajas')->where('id', $id)->get();

            $this->assertCount(1, $filas, 'El concepto de caja '.$id.' tiene que existir una sola vez.');
            $this->assertSame($nombre, $filas->first()->name);
        }
    }

    /**
     * Test 4 -- los dos permisos de los chats de WhatsApp existen después de correr su seeder, y
     * correrlo de nuevo no los repite. Importa porque `permission_empresas.slug` no tiene índice
     * único: un duplicado se ve como dos filas iguales en la pantalla de empleados.
     *
     * @return void
     */
    public function test_los_permisos_de_whatsapp_se_siembran_y_el_seeder_es_idempotente()
    {
        $slugs = ['whatsapp.see_owner_chats', 'whatsapp.see_other_users_chats'];

        Artisan::call('db:seed', ['--class' => 'PermissionEmpresaWhatsappSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'PermissionEmpresaWhatsappSeeder', '--force' => true]);

        foreach ($slugs as $slug) {
            $this->assertSame(
                1,
                DB::table('permission_empresas')->where('slug', $slug)->count(),
                'El permiso '.$slug.' quedó repetido en permission_empresas.'
            );
        }
    }

    /**
     * Test 5 -- `NuevosPermisosListadoSeeder` dejó de correr desde `DatabaseSeeder` porque sus
     * cuatro slugs ya los siembra `PermissionSeeder`, pero sigue existiendo como seeder suelto
     * para las bases de producción viejas. Ahí es donde tiene que ser idempotente.
     *
     * @return void
     */
    public function test_nuevos_permisos_listado_es_idempotente()
    {
        $slugs = [
            'article.percentage_gain',
            'article.provider',
            'article.stock_only_sucursal',
            'article.stock_min_max',
        ];

        $antes = [];
        foreach ($slugs as $slug) {
            $antes[$slug] = DB::table('permission_empresas')->where('slug', $slug)->count();
        }

        Artisan::call('db:seed', ['--class' => 'NuevosPermisosListadoSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'NuevosPermisosListadoSeeder', '--force' => true]);

        foreach ($slugs as $slug) {
            $despues = DB::table('permission_empresas')->where('slug', $slug)->count();

            /*
                Si la base de testing ya traía el permiso, dos corridas no tienen que agregar
                nada; si no lo traía, tiene que quedar exactamente una fila. Las dos condiciones
                se cubren con la misma aserción: nunca más de una.
            */
            $this->assertSame(
                max(1, $antes[$slug]),
                $despues,
                'Correr NuevosPermisosListadoSeeder dos veces duplicó el permiso '.$slug.'.'
            );
        }
    }
}
