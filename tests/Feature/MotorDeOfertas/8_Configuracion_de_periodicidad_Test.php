<?php

namespace Tests\Feature\MotorDeOfertas;

use App\Models\ExtencionEmpresa;
use App\Models\OfferSuggestion;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión motor-de-ofertas-por-cliente — archivo 8: LA PERIODICIDAD, DE PUNTA A PUNTA.
 *
 * 🔴 Existe porque la misión de sugerencias de compra ya pagó este error una
 * vez: se escribió UNA sola de las dos puntas (la columna nacía en 'nunca' y
 * nadie la escribía jamás, porque UserController::update() no tenía el guard),
 * la generación automática —el corazón del pedido— no existía, y TODOS los
 * tests estaban en verde: asignaban la columna directo por Eloquent, que es la
 * puerta que ningún usuario usa nunca.
 *
 * Por eso acá NO se escribe la columna a mano: se guarda por el PUT real de
 * Configuración → general, se relee de la base y recién después se corre el
 * comando. Un test que no pase por api/user/{id} no detecta la mitad que falta.
 *
 * Usa el usuario 500 (el USER_ID de .env.testing) y no uno recién creado:
 * update() no es un PATCH parcial y un usuario mínimo dispara efectos
 * colaterales. Mismo criterio que el molde de SugerenciasCompras.
 */
class Configuracion_de_periodicidad_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea la generación automática. */
    const SLUG = 'motor_de_ofertas';

    /**
     * Por STRING y no por ::class a propósito: así el archivo se carga aunque el
     * pipeline no esté todavía en la rama, y el test falla por lo que tiene que
     * fallar ("no genera") en vez de tumbar la suite con un class not found.
     */
    const JOB_PADRE = 'App\Jobs\GenerateOfferSuggestionChunksJob';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        // Sin esto el pipeline sale a la API real de Anthropic: la clave vive en
        // el .env.testing y la cola de phpunit corre en sync.
        config(['services.anthropic.api_key' => null]);
    }

    /**
     * Usuario 500 de la base del slot. Deja config('app.USER_ID') apuntando a
     * él, que es contra lo que resuelve ofertas:generar. @return User
     */
    protected function usuario_de_prueba()
    {
        $user = User::find(500);

        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        config(['app.USER_ID' => $user->id]);

        return $user;
    }

    /** @param User $user @return void */
    protected function dar_extension($user)
    {
        // forceCreate y no firstOrCreate: ExtencionEmpresa no declara $fillable
        // y fuera de Model::unguarded() (que solo aplica a db:seed) create falla.
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => self::SLUG, 'name' => 'Motor de ofertas por cliente']);
        }
        // syncWithoutDetaching y no attach: el usuario 500 puede ya tenerla y un
        // attach duplicado deja dos filas en el pivot.
        $user->extencions()->syncWithoutDetaching([$extencion->id]);
        $user->load('extencions');
    }

    /**
     * 🔴 LA PUERTA REAL. El payload arranca del estado completo del usuario y
     * solo pisa lo que el test necesita, igual que un guardado real del form.
     *
     * @param User $user @param array $overrides @return \Illuminate\Testing\TestResponse
     */
    protected function guardar_configuracion($user, array $overrides)
    {
        $this->actingAs($user, 'web');

        return $this->putJson('api/user/' . $user->id, array_merge($user->fresh()->toArray(), $overrides));
    }

    /**
     * 🔴 PUNTA A. Sin el guard en UserController::update() se pone rojo: el PUT
     * devuelve 200 igual (el endpoint no falla, ignora la clave) y la columna
     * queda en su default 'nunca'. Es el bug exacto que compras no detectó.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function el_put_de_configuracion_guarda_la_periodicidad_y_se_lee_de_vuelta()
    {
        $user = $this->usuario_de_prueba();

        $response = $this->guardar_configuracion($user, ['ofertas_periodicidad' => 'semanal']);

        $response->assertStatus(200);
        $this->assertEquals(
            'semanal',
            $user->fresh()->ofertas_periodicidad,
            'PUNTA A: sin el bloque de ofertas_periodicidad en UserController::update() la columna queda en nunca y la generacion automatica no existe.'
        );
    }

    /**
     * 🔴 EL CIRCUITO ENTERO, lo que la misión pasada no probó: se guarda por el
     * PUT real, se relee de la base, y recién ahí se corre el comando y se
     * comprueba que EFECTIVAMENTE genera. Cada eslabón por separado puede estar
     * verde y la funcionalidad no existir.
     *
     * De paso blinda que la marca se escribe al CREAR (el job está fakeado, no
     * corrió nada): si esperara al final, una corrida colgada haría que el
     * schedule la relance todos los días.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function guardar_diaria_por_el_put_hace_que_ofertas_generar_si_genere()
    {
        Queue::fake();
        $user = $this->usuario_de_prueba();
        $this->dar_extension($user);
        $user->ofertas_ultima_generacion_at = null;
        $user->save();

        $this->guardar_configuracion($user, ['ofertas_periodicidad' => 'diaria'])->assertStatus(200);

        // Precondición explícita: si esto fallara, las aserciones de abajo no
        // probarían nada (ofertas:generar podría generar por otro motivo).
        $this->assertEquals('diaria', $user->fresh()->ofertas_periodicidad);

        $this->artisan('ofertas:generar')->assertExitCode(0);

        $this->assertEquals(
            1,
            OfferSuggestion::where('user_id', $user->id)->count(),
            'Con la periodicidad guardada por el PUT real, ofertas:generar tiene que generar una corrida.'
        );
        $this->assertEquals(
            'automatica',
            OfferSuggestion::where('user_id', $user->id)->first()->origen_generacion
        );
        Queue::assertPushed(self::JOB_PADRE);
        $this->assertNotNull(
            $user->fresh()->ofertas_ultima_generacion_at,
            'La marca se escribe al crear la corrida, no al terminarla.'
        );
    }

    /**
     * La otra mitad del circuito: 'nunca' es el default y tiene que comportarse
     * como apagado. Sin esto, un comando que generara siempre pasaría el de arriba.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function con_nunca_guardado_por_el_put_el_comando_no_genera_nada()
    {
        Queue::fake();
        $user = $this->usuario_de_prueba();
        $this->dar_extension($user);

        $this->guardar_configuracion($user, ['ofertas_periodicidad' => 'nunca'])->assertStatus(200);
        $this->assertEquals('nunca', $user->fresh()->ofertas_periodicidad);

        $this->artisan('ofertas:generar')->assertExitCode(0);

        $this->assertEquals(
            0,
            OfferSuggestion::where('user_id', $user->id)->count(),
            'Con nunca no se genera nada: es el default de la columna, y ninguna cuenta existente puede empezar a ofertarle a sus clientes solo por migrar.'
        );
        Queue::assertNotPushed(self::JOB_PADRE);
    }

    /**
     * Un valor fuera del vocabulario no rompe el guardado: igual que sus dos
     * hermanas, el back no valida al ESCRIBIR — valida al LEER, en el comando.
     * Lo que sí queda blindado es que ese valor corrupto nunca genere basura.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function un_valor_invalido_no_rompe_el_guardado_ni_genera_basura()
    {
        Queue::fake();
        $user = $this->usuario_de_prueba();
        $this->dar_extension($user);

        $this->guardar_configuracion($user, ['ofertas_periodicidad' => 'diariamente'])->assertStatus(200);
        $this->assertEquals('diariamente', $user->fresh()->ofertas_periodicidad);

        $this->artisan('ofertas:generar')->assertExitCode(0);

        $this->assertEquals(
            0,
            OfferSuggestion::where('user_id', $user->id)->count(),
            'Una periodicidad fuera del vocabulario tiene que comportarse como no genera, nunca como basura ejecutable.'
        );
        Queue::assertNotPushed(self::JOB_PADRE);
    }

    /**
     * La extensión es el gate de venta, no la periodicidad: ni guardando
     * 'diaria' por el PUT real ni con --force se genera nada sin motor_de_ofertas.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function sin_la_extension_no_genera_ni_con_force_aunque_la_periodicidad_este_guardada()
    {
        Queue::fake();
        $user = $this->usuario_de_prueba();

        // A propósito SIN la extensión: el usuario 500 de la base sembrada puede
        // traerla puesta de otra corrida, así que se saca explícitamente.
        $user->extencions()->detach();
        $user->load('extencions');

        $this->guardar_configuracion($user, ['ofertas_periodicidad' => 'diaria'])->assertStatus(200);
        $this->assertEquals('diaria', $user->fresh()->ofertas_periodicidad);

        $this->artisan('ofertas:generar', ['--force' => true])->assertExitCode(0);

        $this->assertEquals(
            0,
            OfferSuggestion::where('user_id', $user->id)->count(),
            'La extension es el gate de venta: sin ella no se genera nada, ni con --force.'
        );
        Queue::assertNotPushed(self::JOB_PADRE);
    }

    /**
     * El PUT no puede pisar la marca del scheduler: el form manda el modelo
     * entero, así que si update() aceptara la clave cada guardado retrocedería
     * la marca y adelantaría la próxima corrida.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function el_put_no_puede_tocar_la_marca_de_ultima_generacion()
    {
        $user = $this->usuario_de_prueba();

        // Marca conocida, puesta como la pondría el comando.
        $user->ofertas_ultima_generacion_at = '2026-08-10 06:00:00';
        $user->save();

        $response = $this->guardar_configuracion($user, [
            'ofertas_periodicidad' => 'mensual',
            // El form manda el modelo entero: la clave viaja aunque nadie la
            // haya editado. El backend tiene que ignorarla.
            'ofertas_ultima_generacion_at' => '2020-01-01 00:00:00',
        ]);
        $response->assertStatus(200);

        $fresco = $user->fresh();
        $this->assertEquals('mensual', $fresco->ofertas_periodicidad);
        $this->assertEquals(
            '2026-08-10 06:00:00',
            \Carbon\Carbon::parse($fresco->ofertas_ultima_generacion_at)->format('Y-m-d H:i:s'),
            'La marca del scheduler la escribe solo el comando: un PUT con la clave no puede moverla.'
        );
    }

    /**
     * 🔴 PUNTA B, la comprobación cruzada con la SPA. La columna y el guard del
     * backend no sirven de nada si Configuración → general no declara el campo:
     * no hay pantalla desde donde guardarlo, que es la mitad que faltó la misión
     * pasada. Se lee el worktree hermano; si no está al lado, se saltea.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function el_modelo_user_js_de_la_spa_declara_la_clave_de_periodicidad()
    {
        $ruta = base_path('../empresa-spa/src/models/user.js');

        if (!file_exists($ruta)) {
            $this->markTestSkipped('El worktree de empresa-spa no esta al lado de empresa-api.');
        }
        $contenido = file_get_contents($ruta);

        $this->assertStringContainsString(
            "key: 'ofertas_periodicidad'",
            $contenido,
            'PUNTA B: sin el campo en models/user.js no hay pantalla desde donde guardar la periodicidad, y el guard del backend queda muerto.'
        );
        $this->assertStringContainsString(
            "if_has_extencion: 'motor_de_ofertas'",
            $contenido,
            'El campo tiene que estar gateado por la extension, si no aparece en cuentas que no compraron el motor.'
        );

        // Las cinco opciones del vocabulario, las mismas que lee el comando.
        foreach (['nunca', 'diaria', 'semanal', 'quincenal', 'mensual'] as $valor) {
            $this->assertStringContainsString("value: '" . $valor . "'", $contenido, 'Falta la opcion ' . $valor . ' en el select de la SPA.');
        }
    }
}
