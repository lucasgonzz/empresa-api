<?php

namespace Tests\Feature\Catalogos;

use App\Http\Controllers\RecursosInicialesController;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Feature tests de POST /api/recursos-iniciales (mision 41, 12/8/2026).
 *
 * El endpoint devuelve en una sola respuesta los catalogos que la SPA pedia de a uno al arrancar
 * (~70 requests secuenciales). Lo unico que promete es que el payload de cada modelo sea IDENTICO
 * al del endpoint individual, y eso es lo que mide el primer test.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing de esta rama esta sembrada de antes
 * y un refresh la vaciaria. Mismo patron que el resto de la suite.
 */
class Recursos_iniciales_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Usuario autenticado de los tests de esta rama. Null si la base de testing no lo tiene.
     *
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * La URI del endpoint individual de un modelo, sacada de las rutas REALES y no de una lista a
     * mano: si manana alguien le cambia la ruta a un catalogo, el test lo sigue solo.
     *
     * @param  string  $controller Clase del controller.
     * @return string|null  URI sin la barra inicial, o null si no tiene una ruta GET de indice.
     */
    protected function uri_individual($controller)
    {
        $corto = str_replace('App\Http\Controllers\\', '', $controller);

        foreach (Route::getRoutes() as $ruta) {
            $accion = $ruta->getActionName();
            // 🔴 Comparacion EXACTA del nombre de la accion, no `strpos`. Con strpos,
            // `OrderStatusController@index` matchea adentro de `ProviderOrderStatusController@index`
            // --es un sufijo suyo-- y el test terminaba comparando el payload de order_status contra
            // la ruta de provider-order-status. Se detecto porque el test se puso rojo con un
            // mensaje que nombraba las dos rutas distintas.
            $termina_en = '\\' . $corto . '@index';
            if ($accion !== $controller . '@index'
                && substr($accion, -strlen($termina_en)) !== $termina_en) {
                continue;
            }
            if (!in_array('GET', $ruta->methods())) {
                continue;
            }
            $uri = $ruta->uri();
            // Una ruta con parametros no es el indice liviano que buscamos.
            if (strpos($uri, '{') !== false) {
                continue;
            }
            return $uri;
        }

        return null;
    }

    /**
     * EL TEST QUE IMPORTA: para cada modelo de la whitelist, el payload del masivo tiene que ser
     * igual al del individual.
     *
     * Recorre la whitelist sola --no son 71 tests escritos a mano-- asi que el dia que se agregue un
     * modelo queda cubierto sin tocar este archivo. Es tambien la unica forma de que "el payload es
     * identico" sea una afirmacion medida y no una intencion escrita en un comentario.
     *
     * @group catalogos
     * @test
     */
    public function cada_modelo_de_la_whitelist_devuelve_lo_mismo_que_su_endpoint_individual()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $whitelist = RecursosInicialesController::whitelist();
        $this->assertNotEmpty($whitelist, 'La whitelist no puede estar vacia.');

        $nombres = array_keys($whitelist);
        $masiva = $this->postJson('/api/recursos-iniciales', array('models' => $nombres));
        $masiva->assertStatus(200);
        $cuerpo = $masiva->json();

        $sin_ruta = array();
        $comparados = 0;

        foreach ($whitelist as $nombre => $controller) {
            $uri = $this->uri_individual($controller);
            if (is_null($uri)) {
                // Se anota y se sigue: que un catalogo no tenga ruta GET sin parametros es un dato
                // del mapa de rutas, no una falla de este endpoint.
                $sin_ruta[] = $nombre;
                continue;
            }

            $this->assertArrayHasKey(
                $nombre,
                $cuerpo['models'],
                'El modelo ' . $nombre . ' esta en la whitelist pero no volvio en la respuesta masiva.'
            );

            $individual = $this->getJson('/' . ltrim($uri, '/'));
            $individual->assertStatus(200);

            $this->assertEquals(
                $individual->json(),
                $cuerpo['models'][$nombre],
                'El payload masivo de ' . $nombre . ' difiere del de su endpoint individual (' . $uri . ').'
            );
            $comparados++;
        }

        $this->assertGreaterThan(0, $comparados, 'No se comparo ningun modelo: revisar uri_individual().');

        if (count($sin_ruta) > 0) {
            fwrite(STDERR, "\n  modelos de la whitelist sin ruta GET sin parametros: " . implode(', ', $sin_ruta) . "\n");
        }
    }

    /**
     * Punto 2 del criterio: lo que no esta en la whitelist vuelve en no_soportados y no ejecuta nada.
     *
     * @group catalogos
     * @test
     */
    public function un_modelo_que_no_esta_en_la_whitelist_vuelve_en_no_soportados()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        // `article` y `sale` existen como catalogos de la SPA pero quedaron AFUERA a proposito
        // (su index() pide parametros). Son el mejor caso de prueba: nombres validos del dominio
        // que igual tienen que rebotar.
        $respuesta = $this->postJson('/api/recursos-iniciales', array(
            'models' => array('iva', 'article', 'sale', 'table_column_preference'),
        ));

        $respuesta->assertStatus(200);
        $cuerpo = $respuesta->json();

        $this->assertArrayHasKey('iva', $cuerpo['models']);
        $this->assertArrayNotHasKey('article', $cuerpo['models']);
        $this->assertArrayNotHasKey('sale', $cuerpo['models']);
        $this->assertArrayNotHasKey('table_column_preference', $cuerpo['models']);

        $this->assertContains('article', $cuerpo['no_soportados']);
        $this->assertContains('sale', $cuerpo['no_soportados']);
        $this->assertContains('table_column_preference', $cuerpo['no_soportados']);
    }

    /**
     * Punto 3: un nombre inventado o malicioso vuelve en no_soportados sin resolver ninguna clase
     * ni tocar el disco.
     *
     * Es el test que protege la decision de diseno mas importante del endpoint. Si alguna vez
     * alguien reemplaza la whitelist literal por una resolucion dinamica del estilo
     * `app('App\Http\Controllers\' . $nombre . 'Controller')`, este test se pone rojo.
     *
     * @group catalogos
     * @test
     */
    public function un_nombre_malicioso_no_resuelve_ninguna_clase()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $maliciosos = array(
            '../../etc/passwd',
            'User',
            '\\App\\Models\\User',
            'App\Http\Controllers\UserController',
            'iva; DROP TABLE users',
            'IVA',
        );

        $respuesta = $this->postJson('/api/recursos-iniciales', array('models' => $maliciosos));

        $respuesta->assertStatus(200);
        $cuerpo = $respuesta->json();

        $this->assertEmpty($cuerpo['models'], 'Ningun nombre malicioso puede devolver datos.');
        foreach ($maliciosos as $nombre) {
            $this->assertContains($nombre, $cuerpo['no_soportados'], 'Falta ' . $nombre . ' en no_soportados.');
        }

        // El string vacio va en su propia comprobacion y NO en la lista de arriba, por un motivo
        // del framework y no del endpoint: `ConvertEmptyStringsToNull` es middleware global
        // (app/Http/Kernel.php:23), asi que un "" del body llega al controller como `null`. Rebota
        // igual --que es lo que pide el criterio-- pero vuelve en no_soportados como null, no como
        // "". Se comprueba lo que importa: que no devuelva datos y que quede registrado.
        $vacio = $this->postJson('/api/recursos-iniciales', array('models' => array('')));
        $vacio->assertStatus(200);
        $this->assertEmpty($vacio->json()['models'], 'Un nombre vacio no puede devolver datos.');
        $this->assertCount(1, $vacio->json()['no_soportados']);

        // 'IVA' en mayusculas tiene que rebotar: la whitelist se compara EXACTO, sin normalizar.
        $this->assertArrayNotHasKey('IVA', $cuerpo['models']);
        $this->assertArrayNotHasKey('iva', $cuerpo['models']);
    }

    /**
     * Punto 4: sin autenticar responde igual que los endpoints individuales sin autenticar.
     *
     * @group catalogos
     * @test
     */
    public function sin_autenticar_responde_igual_que_los_individuales()
    {
        $individual = $this->getJson('/api/iva');
        $masiva = $this->postJson('/api/recursos-iniciales', array('models' => array('iva')));

        $this->assertEquals(
            $individual->getStatusCode(),
            $masiva->getStatusCode(),
            'Sin autenticar, el masivo tiene que responder con el mismo status que el individual.'
        );
    }

    /**
     * Punto 5: dos usuarios distintos reciben cada uno sus datos.
     *
     * Sale solo porque se llama al index() real --con sus filtros por userId()-- pero se verifica
     * con un test y no por lectura, que es lo que pedia la mision.
     *
     * @group catalogos
     * @test
     */
    public function dos_usuarios_distintos_reciben_cada_uno_sus_datos()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $otro = User::where('id', '!=', 500)->first();
        if (is_null($otro)) {
            $this->markTestSkipped('La base de testing tiene un solo usuario: no se puede comparar.');
        }

        // Un catalogo por usuario: brand filtra por userId() en su index().
        $modelos = array('models' => array('brand'));

        $this->actingAs($user, 'web');
        $del_500 = $this->postJson('/api/recursos-iniciales', $modelos)->json();

        $this->actingAs($otro, 'web');
        $del_otro = $this->postJson('/api/recursos-iniciales', $modelos)->json();

        // Cada uno tiene que recibir exactamente lo que le devuelve su propio endpoint individual.
        $this->actingAs($user, 'web');
        $individual_500 = $this->getJson('/api/brand')->json();
        $this->assertEquals($individual_500, $del_500['models']['brand']);

        $this->actingAs($otro, 'web');
        $individual_otro = $this->getJson('/api/brand')->json();
        $this->assertEquals($individual_otro, $del_otro['models']['brand']);
    }

    /**
     * Punto 6: si un modelo revienta, los demas vuelven igual.
     *
     * Se fuerza el fallo reemplazando en el contenedor el controller de un modelo por uno que tira
     * excepcion. Es la unica forma de probar el camino del catch sin romper un controller de verdad.
     *
     * @group catalogos
     * @test
     */
    public function si_un_modelo_revienta_los_demas_vuelven_igual()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $this->app->bind('App\Http\Controllers\BrandController', function () {
            return new ControllerQueRevienta();
        });

        $respuesta = $this->postJson('/api/recursos-iniciales', array(
            'models' => array('iva', 'brand', 'moneda'),
        ));

        $respuesta->assertStatus(200);
        $cuerpo = $respuesta->json();

        $this->assertArrayHasKey('iva', $cuerpo['models'], 'iva tiene que volver aunque brand falle.');
        $this->assertArrayHasKey('moneda', $cuerpo['models'], 'moneda tiene que volver aunque brand falle.');
        $this->assertArrayNotHasKey('brand', $cuerpo['models']);
        $this->assertContains('brand', $cuerpo['con_error']);
    }

    /**
     * Un body sin la clave models, o con algo que no es un array, no puede tumbar el endpoint.
     *
     * @group catalogos
     * @test
     */
    public function un_body_mal_formado_devuelve_422_y_no_revienta()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $this->postJson('/api/recursos-iniciales', array())->assertStatus(422);
        $this->postJson('/api/recursos-iniciales', array('models' => 'iva'))->assertStatus(422);

        // Un array con basura adentro no es un body mal formado: cada elemento rebota por su cuenta.
        $con_basura = $this->postJson('/api/recursos-iniciales', array(
            'models' => array(array('iva'), 123, null, 'iva'),
        ));
        $con_basura->assertStatus(200);
        $this->assertArrayHasKey('iva', $con_basura->json()['models']);
    }
}

/**
 * Doble de prueba: un controller cuyo index() siempre tira. Vive en este archivo a proposito --no es
 * un helper reutilizable, es el andamio de un solo test--.
 */
class ControllerQueRevienta
{
    public function index()
    {
        throw new \RuntimeException('fallo a proposito, para el test del catch');
    }
}
