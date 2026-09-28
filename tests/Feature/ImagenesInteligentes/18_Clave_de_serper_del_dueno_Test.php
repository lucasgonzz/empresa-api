<?php

namespace Tests\Feature\ImagenesInteligentes;

use App\Events\ArticleBatchImagesProcessed;
use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Http\Controllers\Helpers\ImagenesAutomaticasHelper;
use App\Http\Controllers\Helpers\UserSetupHelper;
use App\Jobs\ProcessImageAssignmentRunJob;
use App\Models\ImageAssignmentItem;
use App\Models\ImageAssignmentRun;
use App\Models\ImageServiceCall;
use App\Models\User;
use App\Services\ImageAssignment\ImageServiceCallLogger;
use App\Services\ImageSearch\GoogleCustomSearchImageProvider;
use App\Services\ImageSearch\ImageSearchProviderFactory;
use App\Services\ImageSearch\SerperImageSearchProvider;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;

/**
 * La clave de Serper del comercio (misión serper-en-user-setup, 28/9/2026).
 *
 * Hasta esta misión la clave de Serper salía solo de SERPER_API_KEY (el .env del servidor). Ahora el
 * admin la manda en el payload de user-setup y de demo-setup (campo opcional `serper_api_key`), los
 * dos helpers de alta la guardan en la fila del dueño y el buscador la usa ANTES que la del .env,
 * que queda de respaldo.
 *
 * Lo que protege, en el orden del plan (§3.7):
 *   - user-setup y demo-setup guardan la clave recortada, y sin ella (o vacía, o sin forma de clave)
 *     dejan null sin frenar la instalación;
 *   - la del dueño le gana a la del .env, el .env es el respaldo, y sin ninguna se va a Google;
 *   - el motor busca con la del dueño (el header que sale a Serper es el suyo);
 *   - la previa y el lanzamiento del catálogo, la selección y el job andan con la del dueño y el .env
 *     vacío, y el job corta si no queda ninguna;
 *   - 🔴 la clave no viaja al navegador: ni en get_user del dueño, ni en el del empleado (que trae al
 *     dueño entero adentro), ni en la serialización del modelo que devuelve UserController@update;
 *   - el registro de consultas tacha la del dueño aunque el .env esté vacío.
 *
 * Nada sale a la red: Serper, las imágenes y la IA pasan por el Http::fake de ImagenesInteligentesTestCase.
 */
class Clave_de_serper_del_dueno_Test extends ImagenesInteligentesTestCase
{
    /**
     * La clave de Serper del dueño: alfanumérica y con la forma que valida el admin (32 a 64
     * caracteres), distinta de la de config ('SERPER-DE-PRUEBA', la pone ImagenesInteligentesTestCase).
     */
    const CLAVE_DEL_DUENO = 'serperDelDueno0123456789abcdefABCD';

    /** Un EAN-13 de fábrica válido: el motor busca primero por código. */
    const CODIGO_REAL = '7791234567898';

    /**
     * Ids de las altas de los setups: altos para no chocar con el dueño del fixture, y distintos de
     * los que ya usan otros tests (900000 a 900098). Uno por caso, en rangos que no se pisan: cada
     * alta es un User::create() con id explícito, y todo se deshace con la transacción del test.
     *
     *   900281 a 900285  user-setup, con la clave y sin ella
     *   900301 a 900305  user-setup, con valores que no son una clave
     *   900321 a 900324  demo-setup
     */
    const ID_USER_SETUP           = 900281;
    const ID_USER_SETUP_SIN_FORMA = 900300;
    const ID_DEMO_SETUP           = 900321;

    /** @var array Las líneas de log capturadas por capturar_logs(): [nivel, texto (mensaje + contexto)]. */
    protected $logs = [];

    protected function setUp(): void
    {
        parent::setUp();

        // El job cierra la asignación y avisa por Pusher: acá no hace falta que salga nada.
        Event::fake([ArticleBatchImagesProcessed::class, BackgroundProcessUpdated::class]);
    }

    /* ----------------------------------------------------------------------------------------
     * Ayudas
     * -------------------------------------------------------------------------------------- */

    /**
     * Le carga al dueño del test su clave de Serper, como la habría dejado el setup.
     *
     * @param  string|null $clave
     * @return void
     */
    protected function con_clave_del_dueno($clave = self::CLAVE_DEL_DUENO)
    {
        $this->owner->serper_api_key = $clave;
        $this->owner->save();
    }

    /**
     * El servidor sin SERPER_API_KEY.
     *
     * @return void
     */
    protected function sin_clave_del_servidor()
    {
        config(['services.serper.api_key' => '']);
    }

    /**
     * Las claves (distintas) que salieron en el header X-API-KEY de los pedidos a Serper.
     *
     * @return array
     */
    protected function claves_enviadas_a_serper()
    {
        $claves = [];

        foreach (Http::recorded() as $par) {
            if (strpos($par[0]->url(), 'google.serper.dev') !== false) {
                $claves[] = $par[0]->toPsrRequest()->getHeaderLine('X-API-KEY');
            }
        }

        return array_values(array_unique($claves));
    }

    /**
     * Invoca un método privado estático de un helper de setup (el mismo recurso que
     * SetupsPlantillaYCondicionFiscalTest: run() entero arranca con un migrate:fresh).
     *
     * @param  string $clase
     * @param  string $metodo
     * @param  array  $argumentos
     * @return mixed
     */
    protected function invocar_privado($clase, $metodo, array $argumentos)
    {
        $reflection = new ReflectionMethod($clase, $metodo);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(null, $argumentos);
    }

    /**
     * Da de alta un dueño por UserSetupHelper::create_user() con ese payload extra.
     *
     * @param  int   $id
     * @param  array $extra
     * @return \App\Models\User
     */
    protected function alta_por_user_setup($id, array $extra)
    {
        return $this->invocar_privado(UserSetupHelper::class, 'create_user', [array_merge([
            'user_id'      => $id,
            'user_name'    => 'Cliente Serper '.$id,
            'company_name' => 'Cliente Serper '.$id,
            'email'        => 'cliente-serper-'.$id.'@comerciocity.test',
        ], $extra)]);
    }

    /**
     * Da de alta un dueño por DemoSetupHelper::create_demo_user() con ese payload extra (el id de la
     * demo sale de config('app.USER_ID'), como en el setup de verdad).
     *
     * @param  int   $id
     * @param  array $extra
     * @return \App\Models\User
     */
    protected function alta_por_demo_setup($id, array $extra)
    {
        config(['app.USER_ID' => $id]);

        return $this->invocar_privado(DemoSetupHelper::class, 'create_demo_user', [array_merge([
            'name'         => 'Demo Serper '.$id,
            'company_name' => 'Demo Serper '.$id,
            'email'        => 'demo-serper-'.$id.'@comerciocity.test',
        ], $extra)]);
    }

    /**
     * La clave guardada en la base (DB::table: el modelo la tiene oculta y acá se quiere el valor real).
     *
     * @param  int $user_id
     * @return string|null
     */
    protected function clave_guardada($user_id)
    {
        return DB::table('users')->where('id', $user_id)->value('serper_api_key');
    }

    /**
     * Empieza a juntar TODAS las líneas de log del test (de cualquier nivel) en $this->logs, con un
     * listener de MessageLogged. A diferencia de Log::spy() + shouldHaveReceived(), que pasa con que
     * UNA llamada cumpla, esto deja afirmar que NINGUNA línea trae algo.
     *
     * @return void
     */
    protected function capturar_logs()
    {
        $this->logs = [];

        $test = $this;

        Event::listen(MessageLogged::class, function (MessageLogged $evento) use ($test) {
            $test->logs[] = [
                'nivel' => (string) $evento->level,
                'texto' => (string) $evento->message.' '.$test->contexto_como_texto((array) $evento->context),
            ];
        });
    }

    /**
     * El contexto de una línea de log como texto, con las excepciones abiertas (json_encode de un
     * Throwable da `{}` y escondería lo que trae).
     *
     * @param  array $contexto
     * @return string
     */
    public function contexto_como_texto(array $contexto)
    {
        $plano = [];

        foreach ($contexto as $clave => $valor) {
            $plano[$clave] = $valor instanceof \Throwable
                ? $valor->getMessage().' '.$valor->getTraceAsString()
                : $valor;
        }

        return (string) json_encode($plano, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * Un artículo con código real, y el Serper / la IA falsos que le asignan una imagen.
     *
     * @return \App\Models\Article
     */
    protected function articulo_asignable()
    {
        $articulo = $this->nuevo_articulo('Yerba mate 1 kg', self::CODIGO_REAL);

        $this->falsear(
            [self::CODIGO_REAL => [$this->resultado($this->url_imagen('yerba'), 1000, 1000, 1)]],
            [$this->url_imagen('yerba') => $this->png(1000, 1000, 'rojo')],
            ['rojo' => $this->veredicto('si', 'high')]
        );

        return $articulo;
    }

    /* ----------------------------------------------------------------------------------------
     * user-setup y demo-setup
     * -------------------------------------------------------------------------------------- */

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function el_user_setup_guarda_la_clave_de_serper_del_payload_recortada()
    {
        $user = $this->alta_por_user_setup(self::ID_USER_SETUP, ['serper_api_key' => '  '.self::CLAVE_DEL_DUENO."\n"]);

        $this->assertSame(self::CLAVE_DEL_DUENO, $this->clave_guardada($user->id));

        // Y es la que usa el buscador para ese dueño (releído de la base, como lo lee el job).
        $this->assertSame(self::CLAVE_DEL_DUENO, ImageSearchProviderFactory::clave_serper_para(User::find($user->id)));
    }

    /**
     * Sin la clave, vacía o en null: la columna queda en null y el buscador usa la del .env. Sin
     * fallback hardcodeado (a diferencia de la de Google).
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function el_user_setup_sin_la_clave_o_con_la_clave_vacia_la_deja_en_null()
    {
        $casos = [
            'sin la clave' => [],
            'vacía'        => ['serper_api_key' => ''],
            'solo espacios' => ['serper_api_key' => '   '],
            'null'         => ['serper_api_key' => null],
        ];

        $id = self::ID_USER_SETUP;

        foreach ($casos as $caso => $extra) {
            $id++;

            $user = $this->alta_por_user_setup($id, $extra);

            $this->assertNull($this->clave_guardada($user->id), 'Caso "'.$caso.'": la columna tendría que quedar en null.');
            $this->assertSame('SERPER-DE-PRUEBA', ImageSearchProviderFactory::clave_serper_para(User::find($user->id)), 'Caso "'.$caso.'": el buscador cae a la del .env.');
        }
    }

    /**
     * El setup corre con la base recién vaciada: algo que no es una clave no puede tirar el alta del
     * dueño (más larga que la columna reventaría el INSERT) ni ganarle a una buena del .env. Se
     * descarta, se avisa en el log SIN el valor, y la instalación sigue.
     *
     * Caso por caso (revisión independiente del 28/9/2026): en CADA uno tiene que salir el aviso, y
     * NINGUNA línea de log —de ningún nivel— puede traer el valor. La versión anterior pasaba con que
     * una sola llamada a Log::warning cumpliera (shouldHaveReceived()->atLeast()->times(1)), así que
     * no probaba lo que decía: un aviso con el valor adentro no la ponía en rojo.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function el_user_setup_descarta_lo_que_no_tiene_forma_de_clave_y_el_alta_sigue()
    {
        $this->capturar_logs();

        // caso => [el valor que viaja en el payload, los pedazos suyos que no pueden aparecer en ningún
        // log]. Pedazos y no el valor entero: un valor recortado o con el salto de línea escapado en el
        // JSON del contexto tiene que ponerlo en rojo igual.
        $casos = [
            'más larga que la columna' => [
                'serperLarga'.str_repeat('x', ImageSearchProviderFactory::LARGO_MAXIMO_CLAVE_SERPER),
                ['serperLarga'],
            ],
            'con espacios en el medio' => ['serper con espacios 0123456789abcdef', ['serper con espacios', '0123456789abcdef']],
            'con un salto de línea'    => ["serperPrimeraParte\nsegundaParte0123", ['serperPrimeraParte', 'segundaParte0123']],
            'un array'                 => [['serperAdentroDeUnArray0123456789'], ['serperAdentroDeUnArray']],
            'un número'                => [12345678901234, ['12345678901234']],
        ];

        $id = self::ID_USER_SETUP_SIN_FORMA;

        foreach ($casos as $caso => $datos) {
            $id++;

            list($valor, $prohibidos) = $datos;

            // Solo las líneas de log de ESTE caso.
            $this->logs = [];

            $user = $this->alta_por_user_setup($id, ['serper_api_key' => $valor]);

            $this->assertNotNull(User::find($user->id), 'Caso "'.$caso.'": el alta del dueño tiene que seguir.');
            $this->assertNull($this->clave_guardada($user->id), 'Caso "'.$caso.'": no se guarda lo que no es una clave.');

            // El aviso sale en este caso...
            $avisos = array_filter($this->logs, function ($log) {
                return $log['nivel'] === 'warning' && strpos($log['texto'], 'clave de Serper') !== false;
            });

            $this->assertNotEmpty($avisos, 'Caso "'.$caso.'": tiene que salir el aviso en el log.');

            // ...y ninguna línea de log, de ningún nivel, trae el valor.
            foreach ($this->logs as $log) {
                foreach ($prohibidos as $prohibido) {
                    $this->assertStringNotContainsString($prohibido, $log['texto'], 'Caso "'.$caso.'": una línea de log ('.$log['nivel'].') trae el valor.');
                }
            }
        }
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function el_demo_setup_guarda_la_clave_de_serper_y_sin_ella_la_deja_en_null()
    {
        $con = $this->alta_por_demo_setup(self::ID_DEMO_SETUP, ['serper_api_key' => ' '.self::CLAVE_DEL_DUENO.' ']);

        $this->assertSame(self::CLAVE_DEL_DUENO, $this->clave_guardada($con->id));

        $sin = $this->alta_por_demo_setup(self::ID_DEMO_SETUP + 1, []);
        $this->assertNull($this->clave_guardada($sin->id), 'Sin la clave en el payload, la demo nace con null (usa la del .env).');

        $vacia = $this->alta_por_demo_setup(self::ID_DEMO_SETUP + 2, ['serper_api_key' => '']);
        $this->assertNull($this->clave_guardada($vacia->id), 'Vacía es lo mismo que ausente.');

        $larga = $this->alta_por_demo_setup(self::ID_DEMO_SETUP + 3, ['serper_api_key' => str_repeat('b', 150)]);
        $this->assertNotNull(User::find($larga->id), 'Una clave imposible no tira el alta de la demo.');
        $this->assertNull($this->clave_guardada($larga->id));
    }

    /* ----------------------------------------------------------------------------------------
     * Qué clave gana
     * -------------------------------------------------------------------------------------- */

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function la_clave_del_dueno_le_gana_a_la_del_servidor()
    {
        $this->con_clave_del_dueno();

        // El .env tiene la suya ('SERPER-DE-PRUEBA'): gana la del dueño.
        $this->assertSame(self::CLAVE_DEL_DUENO, ImageSearchProviderFactory::clave_serper_para($this->owner));
        $this->assertTrue(ImageSearchProviderFactory::serper_configurado($this->owner));
        $this->assertSame('serper', ImageSearchProviderFactory::nombre_para($this->owner));
        $this->assertInstanceOf(SerperImageSearchProvider::class, ImageSearchProviderFactory::para($this->owner));

        // Sin dueño, como antes de la misión: solo la del servidor.
        $this->assertSame('SERPER-DE-PRUEBA', ImageSearchProviderFactory::clave_serper_para());

        // Con el .env vacío, la del dueño sola alcanza (y sin dueño no hay ninguna).
        $this->sin_clave_del_servidor();

        $this->assertTrue(ImageSearchProviderFactory::serper_configurado($this->owner));
        $this->assertSame('serper', ImageSearchProviderFactory::nombre_para($this->owner));
        $this->assertFalse(ImageSearchProviderFactory::serper_configurado());
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function sin_la_clave_del_dueno_se_usa_la_del_servidor_y_sin_ninguna_va_a_google()
    {
        // Sin clave propia (null o solo espacios): la del .env.
        foreach ([null, '   '] as $valor) {
            $this->con_clave_del_dueno($valor);

            $this->assertSame('SERPER-DE-PRUEBA', ImageSearchProviderFactory::clave_serper_para($this->owner->fresh()));
            $this->assertSame('serper', ImageSearchProviderFactory::nombre_para($this->owner->fresh()));
        }

        // Sin ninguna de las dos: Google, como siempre.
        $this->sin_clave_del_servidor();

        $this->assertSame('', ImageSearchProviderFactory::clave_serper_para($this->owner->fresh()));
        $this->assertFalse(ImageSearchProviderFactory::serper_configurado($this->owner->fresh()));
        $this->assertSame('google', ImageSearchProviderFactory::nombre_para($this->owner->fresh()));
        $this->assertInstanceOf(GoogleCustomSearchImageProvider::class, ImageSearchProviderFactory::para($this->owner->fresh()));
    }

    /**
     * No alcanza con que la factory diga "serper": el pedido que sale a Serper tiene que llevar la
     * clave del dueño en el header, no la del .env.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function el_motor_busca_en_serper_con_la_clave_del_dueno()
    {
        $this->con_clave_del_dueno();

        $articulo = $this->articulo_asignable();
        $run      = $this->asignacion([$articulo]);

        $item = $this->procesar($run, $articulo);

        $this->assertSame(ImageAssignmentItem::STATUS_ASIGNADA, $item->status, (string) $item->motivo_detalle);
        $this->assertSame([self::CLAVE_DEL_DUENO], $this->claves_enviadas_a_serper(), 'Con las dos cargadas, sale la del dueño.');
    }

    /* ----------------------------------------------------------------------------------------
     * Los tres puntos que antes miraban solo el .env, y la selección
     * -------------------------------------------------------------------------------------- */

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function la_previa_y_el_lanzamiento_del_catalogo_andan_con_la_clave_del_dueno_y_el_env_vacio()
    {
        $this->sin_clave_del_servidor();
        $this->con_clave_del_dueno();

        $this->nuevo_articulo('Catálogo con la clave del dueño', null, ['online' => 1, 'stock' => 2]);

        $this->actuar_como($this->owner, true);

        Queue::fake();

        $previa = $this->getJson('api/image-assignment-runs/catalogo/previa');

        $previa->assertStatus(200);
        $this->assertTrue($previa->json('proveedor_configurado'));
        $this->assertSame('serper', $previa->json('proveedor'));
        $this->assertSame(1, (int) $previa->json('a_buscar'));

        $respuesta = $this->postJson('api/image-assignment-runs/catalogo');

        $respuesta->assertStatus(201);
        $respuesta->assertJsonPath('model.proveedor', 'serper');
        $respuesta->assertJsonPath('model.total_articulos', 1);

        Queue::assertPushed(ProcessImageAssignmentRunJob::class, 1);

        // La clave nunca en lo que se le devuelve a la SPA.
        $this->assertStringNotContainsString(self::CLAVE_DEL_DUENO, $previa->getContent());
        $this->assertStringNotContainsString(self::CLAVE_DEL_DUENO, $respuesta->getContent());
    }

    /**
     * Sin ninguna clave el catálogo sigue sin lanzarse, y el mensaje sigue nombrando SERPER_API_KEY
     * (ahora también la del comercio).
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function sin_ninguna_clave_el_catalogo_no_se_lanza_y_lo_dice()
    {
        $this->sin_clave_del_servidor();

        $this->nuevo_articulo('Catálogo sin ninguna clave');

        $this->actuar_como($this->owner, true);

        Queue::fake();

        $mensaje = (string) $this->postJson('api/image-assignment-runs/catalogo')->assertStatus(422)->json('message');

        $this->assertStringContainsString('SERPER_API_KEY', $mensaje);
        $this->assertStringContainsString('la del comercio', $mensaje);

        Queue::assertNothingPushed();
    }

    /**
     * La asignación por selección (el botón del listado y el asistente, ImagenesAutomaticasHelper::encolar)
     * elige Serper con la clave del dueño aunque el .env no la tenga.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function una_asignacion_por_seleccion_elige_serper_con_la_clave_del_dueno()
    {
        $this->sin_clave_del_servidor();

        $articulo = $this->nuevo_articulo('Seleccionado');

        Queue::fake();

        // Sin la del dueño y sin la del .env: Google, como siempre.
        $sin = ImagenesAutomaticasHelper::encolar($this->owner, [$articulo->id], $this->owner->id);
        $this->assertSame(ImageAssignmentRun::PROVEEDOR_GOOGLE, ImageAssignmentRun::where('uuid', $sin['batch_uuid'])->value('proveedor'));

        // Con la del dueño: Serper.
        $this->con_clave_del_dueno();

        $con = ImagenesAutomaticasHelper::encolar($this->owner->fresh(), [$articulo->id], $this->owner->id);
        $this->assertSame(ImageAssignmentRun::PROVEEDOR_SERPER, ImageAssignmentRun::where('uuid', $con['batch_uuid'])->value('proveedor'));
    }

    /**
     * El job relee al dueño en cada tramo: con la clave del dueño y el .env vacío no corta con "no hay
     * clave", busca con la del dueño y termina.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function el_job_no_corta_con_la_clave_del_dueno_y_el_env_vacio()
    {
        $articulo = $this->articulo_asignable();
        $run      = $this->asignacion([$articulo]);

        $this->sin_clave_del_servidor();
        $this->con_clave_del_dueno();

        Queue::fake();

        (new ProcessImageAssignmentRunJob($run->id))->handle();

        $run->refresh();
        $this->assertSame(ImageAssignmentRun::STATUS_TERMINADA, $run->status, (string) $run->motivo_estado);
        $this->assertSame(ImageAssignmentItem::STATUS_ASIGNADA, ImageAssignmentItem::where('run_id', $run->id)->value('status'));
        $this->assertSame([self::CLAVE_DEL_DUENO], $this->claves_enviadas_a_serper());
    }

    /**
     * La contracara: una corrida de Serper sin ninguna clave (ni la del dueño ni la del .env) se
     * frena de entrada, sin salir a buscar, con un motivo que dice cuál falta.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function el_job_corta_si_no_queda_ninguna_clave()
    {
        $articulo = $this->articulo_asignable();
        $run      = $this->asignacion([$articulo]);

        $this->sin_clave_del_servidor();

        Queue::fake();

        (new ProcessImageAssignmentRunJob($run->id))->handle();

        $run->refresh();
        $this->assertSame(ImageAssignmentRun::STATUS_FALLIDA, $run->status);
        $this->assertStringContainsString('clave de Serper', (string) $run->motivo_estado);
        $this->assertStringContainsString('SERPER_API_KEY', (string) $run->motivo_estado);
        $this->assertSame(0, $this->requests_a('google.serper.dev'), 'No sale a buscar sin clave.');
    }

    /* ----------------------------------------------------------------------------------------
     * 🔴 La clave no viaja al navegador
     * -------------------------------------------------------------------------------------- */

    /**
     * AuthController@get_user devuelve el modelo User entero (UserHelper::getFullModel). La clave no
     * puede aparecer ni como campo ni en ningún lado del cuerpo.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function get_user_del_dueno_no_expone_la_clave()
    {
        $this->con_clave_del_dueno();

        $respuesta = $this->getJson('api/user');

        $respuesta->assertStatus(200);
        $this->assertSame((int) $this->owner->id, (int) $respuesta->json('user.id'));
        $this->assertArrayNotHasKey('serper_api_key', $respuesta->json('user'));
        $this->assertStringNotContainsString(self::CLAVE_DEL_DUENO, $respuesta->getContent());
    }

    /**
     * El get_user de un EMPLEADO mete al dueño entero adentro (AuthController::set_employee_props):
     * la clave tampoco puede viajar por ahí.
     *
     * Test aparte y no a continuación del del dueño: UserHelper::user() lee primero
     * session('auth_user'), que deja el get_user anterior, y el segundo pedido seguiría siendo el
     * del dueño.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function get_user_del_empleado_trae_al_dueno_sin_la_clave()
    {
        $this->con_clave_del_dueno();

        $empleado = User::create([
            'name'     => 'Empleado imágenes inteligentes',
            'email'    => 'imagenes-inteligentes-empleado-'.uniqid().'@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->owner->id,
        ]);

        $this->actuar_como($empleado);

        $respuesta = $this->getJson('api/user');

        $respuesta->assertStatus(200);
        $this->assertSame((int) $empleado->id, (int) $respuesta->json('user.id'));
        $this->assertSame((int) $this->owner->id, (int) $respuesta->json('user.owner.id'), 'La respuesta del empleado trae al dueño adentro: es justo lo que se quiere probar.');
        $this->assertStringNotContainsString(self::CLAVE_DEL_DUENO, $respuesta->getContent());
    }

    /**
     * UserController@update devuelve el modelo serializado: la clave no sale en toArray() ni en
     * toJson(), aunque el modelo la tenga (el buscador la lee de ahí).
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function el_modelo_serializado_no_trae_la_clave()
    {
        $this->con_clave_del_dueno();

        $dueno = User::find($this->owner->id);

        $this->assertSame(self::CLAVE_DEL_DUENO, $dueno->serper_api_key, 'El modelo la tiene: el buscador la lee de acá.');
        $this->assertArrayNotHasKey('serper_api_key', $dueno->toArray());
        $this->assertStringNotContainsString(self::CLAVE_DEL_DUENO, $dueno->toJson());
    }

    /* ----------------------------------------------------------------------------------------
     * El registro de consultas
     * -------------------------------------------------------------------------------------- */

    /**
     * La segunda red: el registro de consultas (lo mira el admin) tacha la clave del dueño aunque el
     * .env esté vacío y aunque el error no la traiga con ninguna forma reconocible.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function el_registro_de_consultas_tacha_la_clave_del_dueno()
    {
        $this->sin_clave_del_servidor();
        $this->con_clave_del_dueno();

        $fila = ImageServiceCallLogger::registrar([
            'user_id'   => $this->owner->id,
            'tipo'      => ImageServiceCall::TIPO_BUSQUEDA,
            'proveedor' => 'serper',
            'error'     => 'Rechazada la clave '.self::CLAVE_DEL_DUENO.'.',
        ]);

        $this->assertSame('Rechazada la clave ***.', $fila->error);

        // Las piezas sueltas: las claves del dueño y sin_claves() con claves de más.
        $this->assertSame([self::CLAVE_DEL_DUENO], ImageServiceCallLogger::claves_del_dueno($this->owner->id));
        $this->assertSame('antes *** después', ImageServiceCallLogger::sin_claves('antes '.self::CLAVE_DEL_DUENO.' después', [self::CLAVE_DEL_DUENO]));

        // Un dueño sin clave, o un id que no existe: nada que tachar, y no explota.
        $this->con_clave_del_dueno(null);

        $this->assertSame([], ImageServiceCallLogger::claves_del_dueno($this->owner->id));
        $this->assertSame([], ImageServiceCallLogger::claves_del_dueno(0));
        $this->assertSame([], ImageServiceCallLogger::claves_del_dueno(987654321));
    }

    /**
     * De punta a punta: un error de Serper que (hipotéticamente) nombra la clave del dueño no la deja
     * ni en lo que ve el comercio (diagnóstico, motivo) ni en el registro del admin.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function un_error_de_serper_que_nombra_la_clave_del_dueno_no_la_deja_a_la_vista()
    {
        $this->sin_clave_del_servidor();
        $this->con_clave_del_dueno();

        $articulo = $this->nuevo_articulo('Rastrillo de 14 dientes', self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo]);

        $this->falsear(['error' => 'Invalid API key '.self::CLAVE_DEL_DUENO], [], []);

        $item = $this->procesar($run, $articulo);

        $this->assertSame(ImageAssignmentItem::STATUS_NO_ASIGNADA, $item->status);
        $this->assertSame([self::CLAVE_DEL_DUENO], $this->claves_enviadas_a_serper(), 'Buscó con la del dueño.');

        $a_la_vista = json_encode($item->diagnostico, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).' '.$item->motivo_detalle;

        $this->assertStringContainsString('Invalid API key', $a_la_vista, 'El error se sigue viendo.');
        $this->assertStringNotContainsString(self::CLAVE_DEL_DUENO, $a_la_vista);

        $registros = ImageServiceCall::where('run_id', $run->id)->get();

        $this->assertGreaterThan(0, $registros->count());

        foreach ($registros as $registro) {
            $this->assertStringNotContainsString(self::CLAVE_DEL_DUENO, (string) $registro->error);
        }
    }
}
