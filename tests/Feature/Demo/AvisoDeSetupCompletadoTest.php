<?php

namespace Tests\Feature\Demo;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Http\Controllers\Helpers\DemoTrackingConfigHelper;
use App\Models\DemoEvento;
use App\Models\DemoTrackingConfig;
use App\Services\DemoEventoEmitter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El aviso de vuelta "la instancia quedo armada" (mision 61).
 *
 * Hasta esta mision, el admin se enteraba de que el setup salio bien SOLO por la respuesta
 * HTTP del POST que el mismo dispara, sincronica y de hasta 300 s. Si esa conexion se corta
 * con el setup terminando bien de este lado, el lead queda `fallido` con la instancia
 * perfectamente armada y nunca le aparece el boton de comenzar la demo. El evento
 * `demo.setup.completado` es el segundo camino, independiente de esa conexion.
 *
 * 🔴 LO QUE ESTA CLASE NO EJECUTA, DICHO DERECHO:
 *
 * No se llama a DemoSetupHelper::run(). Su primera linea es un `migrate:fresh` que vaciaria
 * empresa_testing_s1, que es la base sembrada de la que vive toda la suite del slot, y ademas
 * tarda ~110 s medidos. Lo que se ejercita es la secuencia EXACTA con la que termina ese
 * metodo —guardar el canal y despues emitir con la clave derivada del token—, replicada en
 * emitir_como_el_final_del_setup(). Es el mismo recorte que ya declara
 * BusDeEventosTest::test_un_payload_de_setup_sin_las_claves_nuevas_no_configura_ningun_canal.
 *
 * La mitad que una replica NO puede probar —que run() de verdad haga eso, al final y bajo esa
 * condicion— la cubre test_el_setup_emite_el_evento_al_final_y_solo_con_el_canal_guardado,
 * que lee el fuente del metodo real.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing del slot esta sembrada de
 * antes y un refresh la vaciaria.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promocion de constructor, readonly, enum ni #[...].
 */
class AvisoDeSetupCompletadoTest extends TestCase
{
    use DatabaseTransactions;

    /** Token del canal con el que corren estos casos. Mismo valor en todos: es el del lead. */
    const TOKEN = 'zz-token-canal-mision-61';

    /** URL del canal de ingesta del admin. */
    const URL = 'https://admin.test/api/demo-eventos';

    /**
     * Valor original de la memoizacion de runningInConsole(), para restaurarlo.
     *
     * @var bool|null
     */
    private $consola_original = null;

    protected function setUp(): void
    {
        parent::setUp();

        /**
         * La precondicion "esta instancia no es una demo" se ESTABLECE, no se asume: una fila
         * de demo_tracking_config sembrada a mano fuera de la suite da vuelta el resultado de
         * varios de estos casos. Con DatabaseTransactions el borrado se revierte al terminar.
         */
        DemoTrackingConfig::query()->delete();

        DemoTrackingConfigHelper::olvidar_cache();
        DemoEventoEmitter::reiniciar_estado_de_proceso();

        /** Ningun caso de esta clase puede salir a la red de verdad. */
        Http::fake();
    }

    protected function tearDown(): void
    {
        $this->restaurar_consola();

        DemoTrackingConfigHelper::olvidar_cache();
        DemoEventoEmitter::reiniciar_estado_de_proceso();

        parent::tearDown();
    }

    /**
     * 🔴 Hace que la app se comporte como en un request web y no como en la CLI.
     *
     * SIN ESTO, EL CASO DEL CACHE NO MIDE NADA. DemoTrackingConfigHelper::actual() re-consulta
     * la base en CADA llamada cuando runningInConsole() es true, y bajo PHPUnit siempre lo es.
     * O sea que el cache estatico —lo unico que ese caso viene a probar— nunca llega a
     * poblarse, y la asercion pasaria igual aunque guardar() no invalidara nada. El evento se
     * emite desde el request de admin-sync, que es web: hay que medirlo ahi.
     *
     * Se fuerza la propiedad memoizada de la Application por reflexion porque el unico camino
     * publico es la variable de entorno APP_RUNNING_IN_CONSOLE, y esa ya se leyo y se cacheo
     * cuando el framework arranco.
     *
     * @return void
     */
    private function simular_request_web()
    {
        $propiedad = new \ReflectionProperty(get_class($this->app), 'isRunningInConsole');
        $propiedad->setAccessible(true);

        $this->consola_original = $propiedad->getValue($this->app);

        $propiedad->setValue($this->app, false);

        $this->assertFalse($this->app->runningInConsole(), 'El caso tiene que correr como request web.');
    }

    /**
     * Devuelve la memoizacion a como estaba. Va en tearDown y no al final de cada caso: si una
     * asercion falla, dejar la app diciendo "no soy consola" se lleva puestos los casos que
     * corran despues en el mismo proceso.
     *
     * @return void
     */
    private function restaurar_consola()
    {
        if (is_null($this->consola_original)) {
            return;
        }

        $propiedad = new \ReflectionProperty(get_class($this->app), 'isRunningInConsole');
        $propiedad->setAccessible(true);
        $propiedad->setValue($this->app, $this->consola_original);

        $this->consola_original = null;
    }

    /**
     * Replica textual del bloque con el que termina DemoSetupHelper::run().
     *
     * Es una replica y se dice: ver el encabezado de la clase. Que el metodo real tenga este
     * bloque, al final y con esta condicion, lo verifica el caso que lee el fuente.
     *
     * @param array $data Payload del setup, tal como llega desde admin-api.
     * @param int $user_id Id del user demo recien creado.
     * @return \App\Models\DemoEvento|null
     */
    private function emitir_como_el_final_del_setup(array $data, $user_id)
    {
        $canal = null;

        if (!empty($data['demo_eventos_token']) && !empty($data['demo_eventos_url'])) {
            $canal = DemoTrackingConfigHelper::guardar(
                $data['demo_eventos_token'],
                $data['demo_eventos_url'],
                isset($data['demo_plan']) && is_array($data['demo_plan']) ? $data['demo_plan'] : null,
                isset($data['demo_media_urls']) && is_array($data['demo_media_urls']) ? $data['demo_media_urls'] : null
            );
        }

        if (!is_null($canal)) {
            return DemoEventoEmitter::emitir(
                'demo.setup.completado',
                null,
                ['user_id' => $user_id],
                (string) $canal->eventos_token
            );
        }

        return null;
    }

    /**
     * Payload de setup con el canal de la demo v2 configurado.
     *
     * @return array
     */
    private function payload_con_canal()
    {
        return [
            'name'                => 'ZZ Lead de la demo v2',
            'business_type'       => 'ferreteria',
            'demo_eventos_token'  => self::TOKEN,
            'demo_eventos_url'    => self::URL,
            'demo_plan'           => ['version_catalogo' => 2],
            'demo_media_urls'     => ['1.1' => 'https://videos.test/1-1.mp4'],
        ];
    }

    /**
     * Caso 1: con el canal configurado, el setup deja la fila del aviso.
     *
     * @group demo
     * @return void
     */
    public function test_el_setup_con_canal_deja_la_fila_de_demo_setup_completado()
    {
        $this->simular_request_web();

        $antes = DemoEvento::where('nombre', 'demo.setup.completado')->count();

        $evento = $this->emitir_como_el_final_del_setup($this->payload_con_canal(), 777);

        $this->assertNotNull($evento, 'Con canal configurado el setup tiene que dejar su aviso.');
        $this->assertSame('demo.setup.completado', $evento->nombre);
        $this->assertSame(
            $antes + 1,
            DemoEvento::where('nombre', 'demo.setup.completado')->count(),
            'Y tiene que ser exactamente una fila.'
        );

        /** `datos` lleva el user_id del user demo y nada mas. Lo que no viaja no se filtra. */
        $this->assertSame(['user_id' => 777], $evento->datos);

        $this->assertNull($evento->clip_id, 'No es un evento de un clip del plan.');
        $this->assertNull($evento->sincronizado_at, 'Nace pendiente de empuje.');
        $this->assertSame(0, $evento->intentos);

        /**
         * 🔴 La mitad que le importa al POST que el admin esta esperando: emitir NO manda
         * ningun request. El push va agendado en app()->terminating(), o sea despues de que
         * este request ya respondio, asi que no le suma un solo milisegundo al setup.
         */
        Http::assertNothingSent();
    }

    /**
     * Caso 2: sin las claves del canal en el payload, el setup no deja nada.
     *
     * Es el lead de la dinamica anterior, y tambien —lo que de verdad importa— la instancia de
     * cualquiera de los ~40 clientes REALES, que corre este mismo helper al instalarse.
     *
     * @group demo
     * @return void
     */
    public function test_sin_canal_configurado_el_setup_no_deja_ningun_evento()
    {
        $this->simular_request_web();

        $antes = DemoEvento::count();

        $payload_sin_canal = [
            'name'          => 'ZZ Cliente real recien instalado',
            'business_type' => 'ferreteria',
        ];

        $this->assertNull(
            $this->emitir_como_el_final_del_setup($payload_sin_canal, 500),
            'Sin canal en el payload no se emite nada.'
        );

        $this->assertSame($antes, DemoEvento::count(), 'Un cliente real no puede terminar con eventos.');
        $this->assertSame(0, DemoTrackingConfig::count(), 'Ni con una fila de canal.');
        $this->assertFalse(DemoTrackingConfigHelper::hay_canal());

        /**
         * Media clave sola tampoco arma canal: guardar() devuelve null y la condicion del
         * setup mira ESE resultado, no el payload.
         */
        $this->assertNull($this->emitir_como_el_final_del_setup([
            'demo_eventos_token' => self::TOKEN,
        ], 500));

        $this->assertNull($this->emitir_como_el_final_del_setup([
            'demo_eventos_url' => self::URL,
        ], 500));

        $this->assertSame($antes, DemoEvento::count());

        Http::assertNothingSent();
    }

    /**
     * Caso 3: dos corridas del setup contra el MISMO canal dejan una sola fila.
     *
     * No es hipotetico: admin-api reintenta el RunDemoSetupJob cuando la respuesta HTTP se
     * corta, que es exactamente el escenario que este evento viene a cubrir. Sin la clave de
     * idempotencia, el reintento le avisa al admin dos veces del mismo hecho.
     *
     * @group demo
     * @return void
     */
    public function test_dos_corridas_del_setup_contra_el_mismo_canal_dejan_una_sola_fila()
    {
        $this->simular_request_web();

        $antes = DemoEvento::where('nombre', 'demo.setup.completado')->count();

        $primera = $this->emitir_como_el_final_del_setup($this->payload_con_canal(), 777);

        /**
         * La segunda corrida re-guarda el canal (borra la fila y la vuelve a crear, igual que
         * el setup real) antes de volver a emitir. El user_id cambia a proposito: el segundo
         * setup crea otro user, y aun asi el HECHO es el mismo y no puede duplicarse.
         */
        $segunda = $this->emitir_como_el_final_del_setup($this->payload_con_canal(), 778);

        $this->assertNotNull($primera);
        $this->assertNotNull($segunda, 'La segunda emision devuelve la fila que ya estaba, no null.');
        $this->assertSame($primera->id, $segunda->id, 'Las dos corridas son la misma fila.');
        $this->assertSame($primera->uuid, $segunda->uuid, 'El uuid es determinista sobre el token del canal.');

        $this->assertSame(
            $antes + 1,
            DemoEvento::where('nombre', 'demo.setup.completado')->count(),
            'Dos corridas del setup contra el mismo canal no pueden dejar dos avisos.'
        );

        /**
         * Y un canal DISTINTO —otro lead— si es otro aviso. Sin esta mitad, la asercion de
         * arriba pasaria igual con una clave de idempotencia constante, que colapsaria a todos
         * los leads en un solo evento.
         */
        $otro_lead = $this->payload_con_canal();
        $otro_lead['demo_eventos_token'] = 'zz-token-canal-de-otro-lead';

        $tercera = $this->emitir_como_el_final_del_setup($otro_lead, 779);

        $this->assertNotNull($tercera);
        $this->assertNotSame($primera->uuid, $tercera->uuid, 'Otro lead es otro aviso.');
        $this->assertSame($antes + 2, DemoEvento::where('nombre', 'demo.setup.completado')->count());
    }

    /**
     * 🔴 Caso 4: el emisor ve el canal que el setup acaba de escribir, en el MISMO request.
     *
     * Es el punto de falla silenciosa de toda la mision. La guarda 2 de emitir() es
     * DemoTrackingConfigHelper::hay_canal(), que memoiza el resultado en una propiedad
     * estatica por request. Si esa memoizacion sobreviviera al guardar() del setup, la guarda
     * veria el "no hay canal" resuelto ANTES —el estado real de la instancia durante los ~110 s
     * del setup— y el evento no se emitiria NUNCA, sin una sola linea de log que lo explicara.
     *
     * Por eso el caso NO llama a olvidar_cache() en el medio: eso seria testear el test. Lo que
     * se verifica es que guardar() invalida la memoizacion por su cuenta.
     *
     * @group demo
     * @return void
     */
    public function test_el_emisor_ve_el_canal_recien_guardado_en_el_mismo_request()
    {
        $this->simular_request_web();

        /**
         * Se PUEBLA la memoizacion con el estado previo al setup: "esta instancia no tiene
         * canal". Sin esta llamada el cache queda en null y el caso no prueba nada, porque
         * actual() consultaria la base igual.
         */
        $this->assertFalse(DemoTrackingConfigHelper::hay_canal(), 'Antes del setup no hay canal.');

        $canal = DemoTrackingConfigHelper::guardar(self::TOKEN, self::URL, null, null);

        $this->assertNotNull($canal, 'El canal tiene que quedar guardado.');

        /** Sin olvidar_cache() en el medio: es lo que se esta midiendo. */
        $this->assertTrue(
            DemoTrackingConfigHelper::hay_canal(),
            'guardar() tiene que invalidar la memoizacion: si no, la guarda 2 del emisor sigue viendo la instancia sin canal y el evento no se emite nunca.'
        );

        $evento = DemoEventoEmitter::emitir(
            'demo.setup.completado',
            null,
            ['user_id' => 777],
            (string) $canal->eventos_token
        );

        $this->assertNotNull($evento, 'El emisor tiene que pasar la guarda 2 con el canal recien escrito.');
    }

    /**
     * La guarda 1 del emisor no descarta el request de admin-sync.
     *
     * fuera_de_una_sesion_de_demo() es la unica guarda que corre para el 100% del trafico de un
     * cliente real, y descarta cuando hay una sesion arrancada SIN el marcador de demo. El POST
     * de admin-sync es server-to-server: no manda Referer, asi que Sanctum no arranca sesion y
     * la guarda no puede afirmar nada. Tiene que devolver false y dejar decidir a la guarda 2.
     *
     * Si devolviera true, el evento se descartaria antes de tocar la base y la mision no
     * entregaria nada.
     *
     * @group demo
     * @return void
     */
    public function test_la_guarda_1_no_descarta_el_request_sin_sesion_de_admin_sync()
    {
        $this->simular_request_web();

        $metodo = new \ReflectionMethod(DemoEventoEmitter::class, 'fuera_de_una_sesion_de_demo');
        $metodo->setAccessible(true);

        $this->assertFalse(
            $metodo->invoke(null, 'demo.setup.completado'),
            'Sin sesion arrancada la guarda 1 no puede descartar: decide la guarda 2.'
        );
    }

    /**
     * El nombre nuevo esta en las dos listas blancas del emisor.
     *
     * La de NOMBRES_NEGOCIO es la unica puerta: sin el nombre ahi, emitir() lo descarta con un
     * Log::info y devuelve null. La de NOMBRES_CON_PUSH_INMEDIATO es la que hace que el aviso
     * salga apenas responde el setup en vez de esperar hasta 60 s al barrido del minuto, con
     * el lead mirando la pantalla de espera.
     *
     * Y no puede estar en NOMBRES_UX: si el navegador pudiera mandarlo, cualquiera falsificaria
     * un setup exitoso y se saltearia el gate del boton.
     *
     * @group demo
     * @return void
     */
    public function test_el_nombre_esta_en_las_listas_blancas_del_servidor_y_no_en_la_del_navegador()
    {
        $this->assertContains('demo.setup.completado', DemoEventoEmitter::NOMBRES_NEGOCIO);
        $this->assertContains('demo.setup.completado', DemoEventoEmitter::NOMBRES_CON_PUSH_INMEDIATO);
        $this->assertNotContains('demo.setup.completado', DemoEventoEmitter::NOMBRES_UX);

        $this->assertFalse(
            DemoEventoEmitter::nombre_ux_aceptado('demo.setup.completado'),
            'Un evento del servidor no se puede reportar desde el navegador.'
        );
    }

    /**
     * 🔴 Lo que la replica de arriba no puede probar: que run() de VERDAD emita, al final y
     * solo con el canal guardado.
     *
     * Se lee el fuente del metodo real en vez de ejecutarlo porque run() arranca con un
     * `migrate:fresh` que vaciaria la base del slot (ver el encabezado de la clase). Es una
     * asercion estructural y se sabe: no prueba que ande, prueba que este donde tiene que
     * estar. Cubre los dos errores que la replica dejaria pasar enteros —que alguien mueva la
     * emision arriba del migrate:fresh, o que la condicione al payload en vez de al resultado
     * de guardar()— y los dos son silenciosos: el evento simplemente no llega y el lead se
     * queda sin el boton.
     *
     * @group demo
     * @return void
     */
    public function test_el_setup_emite_el_evento_al_final_y_solo_con_el_canal_guardado()
    {
        $metodo = new \ReflectionMethod(DemoSetupHelper::class, 'run');

        $lineas = file($metodo->getFileName());

        $fuente = implode('', array_slice(
            $lineas,
            $metodo->getStartLine() - 1,
            $metodo->getEndLine() - $metodo->getStartLine() + 1
        ));

        $pos_emision = strpos($fuente, 'DemoEventoEmitter::emitir(');

        $this->assertNotFalse($pos_emision, 'run() tiene que emitir el aviso de setup completado.');

        /**
         * strrpos y no strpos: los nombres de los dos helpers aparecen antes en los docblocks
         * del metodo, y lo que se quiere ubicar es la llamada de verdad, que es la ultima.
         */
        $pos_canal = strrpos($fuente, 'DemoTrackingConfigHelper::guardar(');
        $pos_ingreso = strrpos($fuente, 'DemoIngresoTokenHelper::guardar(');
        $pos_migrate = strpos($fuente, "Artisan::call('migrate:fresh'");

        $this->assertNotFalse($pos_canal);
        $this->assertNotFalse($pos_ingreso);
        $this->assertNotFalse($pos_migrate);

        $this->assertGreaterThan(
            $pos_migrate,
            $pos_emision,
            'La emision no puede ir antes del migrate:fresh: vacia demo_eventos y el aviso se pierde.'
        );
        $this->assertGreaterThan(
            $pos_canal,
            $pos_emision,
            'La emision va DESPUES de guardar el canal: antes, la guarda 2 del emisor no lo ve.'
        );
        $this->assertGreaterThan(
            $pos_ingreso,
            $pos_emision,
            'Y despues del token de ingreso, que es lo ultimo que el lead necesita para entrar.'
        );

        /**
         * La emision cuelga del RESULTADO de guardar(), no de las claves del payload. Con un
         * token mas largo que la columna, o con el insert fallado, guardar() devuelve null y
         * el canal no existe: emitir ahi deja una fila que nadie va a poder empujar nunca.
         */
        $cola = substr($fuente, $pos_canal, $pos_emision - $pos_canal);

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*is_null\(\s*\$canal\s*\)\s*\)/',
            $cola,
            'La emision tiene que estar condicionada al resultado de guardar(), no al payload.'
        );

        /** Y el cherry-pick de la mision 59, que es lo que deja llegar al final del metodo. */
        $this->assertStringContainsString('set_time_limit(0)', $fuente);
        $this->assertStringContainsString('ignore_user_abort(true)', $fuente);
    }
}
