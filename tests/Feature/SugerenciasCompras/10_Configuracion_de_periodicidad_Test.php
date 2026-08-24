<?php

namespace Tests\Feature\SugerenciasCompras;

use App\Jobs\GeneratePurchaseSuggestionChunksJob;
use App\Models\ExtencionEmpresa;
use App\Models\PurchaseSuggestion;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Arreglo A2 (pasada post-chequeo, el más importante de la tanda):
 * users.sugerencias_compras_periodicidad nacía en 'nunca' y NADIE la
 * escribía jamás, porque UserController::update() no tenía el guard que sí
 * tiene el molde de sugerencias de stock (sugerencias_periodicidad,
 * UserController.php:246-247 / empresa-spa/src/models/user.js:503). Sin esa
 * punta, el schedule de las 05:30 salía siempre en la primera línea
 * ("periodicidad nunca") y compras:generar —comando, retención y la
 * conversación diaria del chat— nunca podían activarse desde Configuración →
 * general: era funcionalidad que nunca pudo haber andado.
 *
 * Este archivo prueba el circuito por la puerta REAL (el PUT del formulario
 * de Configuración → general), a diferencia de
 * 5_Pipeline_y_prioridad_Test.php::compras_generar_*, que asignan la columna
 * directo por Eloquent (`$user->sugerencias_compras_periodicidad = ...`) y
 * por eso jamás hubieran detectado que el guard faltaba en el controller.
 *
 * Usa el usuario 500 (el mismo USER_ID de .env.testing) en vez de un usuario
 * recién creado: UserController::update() no es un PATCH parcial (casi todos
 * sus campos se asignan sin has() desde el request) y pegarle con un
 * usuario mínimo corre efectos colaterales (recálculo de precios, cambio de
 * versión, etc.) por fuera de lo que este archivo quiere probar. Mismo
 * criterio que SugerenciasStock/7_Generacion_automatica_Test.php e
 * Iva/1_Condicion_Y_Factura_Test.php para este mismo endpoint.
 */
class Configuracion_de_periodicidad_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea la generación automática. */
    const SLUG = 'sugerencias_compras';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['services.anthropic.api_key' => null]);
    }

    /**
     * Usuario 500 de la base de testing del slot (dueño, sin extensiones ni
     * sugerencias previas). Deja además config('app.USER_ID') apuntando a él,
     * que es contra lo que resuelve compras:generar.
     *
     * @return User
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

    /**
     * @param User $user
     * @return void
     */
    protected function dar_extension($user)
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::SLUG,
                'name' => 'Sugerencias de compra a proveedores',
            ]);
        }

        $user->extencions()->attach($extencion->id);
        $user->load('extencions');
    }

    /**
     * PUT de Configuración → general con el modelo completo. Igual que
     * documenta Iva/1_Condicion_Y_Factura_Test.php::full_profile_payload():
     * update() no es un PATCH parcial, así que el payload arranca del estado
     * actual completo del usuario y solo pisa lo que el test necesita — igual
     * que un guardado real del formulario de propiedades del SPA.
     *
     * @param User $user
     * @param array $overrides
     * @return \Illuminate\Testing\TestResponse
     */
    protected function guardar_configuracion($user, array $overrides)
    {
        $this->actingAs($user, 'web');

        $payload = array_merge($user->fresh()->toArray(), $overrides);

        return $this->putJson('api/user/' . $user->id, $payload);
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function el_put_de_configuracion_guarda_la_periodicidad_y_se_lee_de_vuelta()
    {
        $user = $this->usuario_de_prueba();

        $response = $this->guardar_configuracion($user, ['sugerencias_compras_periodicidad' => 'semanal']);

        $response->assertStatus(200);
        $this->assertEquals('semanal', $user->fresh()->sugerencias_compras_periodicidad);
    }

    /**
     * Un valor fuera del vocabulario (nunca/diaria/semanal/quincenal/mensual)
     * no puede romper el guardado: mismo criterio que sugerencias_periodicidad
     * de stock, que tampoco valida contra una whitelist al escribir — quien
     * valida es la LECTURA, en compras:generar (DIAS_POR_PERIODICIDAD). Lo
     * que sí tiene que quedar blindado es que ese valor corrupto nunca
     * "genere basura": ninguna PurchaseSuggestion fantasma.
     *
     * @group sugerencias-compras
     * @test
     */
    public function un_valor_invalido_no_rompe_el_guardado_ni_genera_basura()
    {
        Queue::fake();
        $user = $this->usuario_de_prueba();
        $this->dar_extension($user);

        $response = $this->guardar_configuracion($user, ['sugerencias_compras_periodicidad' => 'diariamente']);

        $response->assertStatus(200);
        $this->assertEquals(
            'diariamente',
            $user->fresh()->sugerencias_compras_periodicidad,
            'Igual que stock: el back no valida contra una whitelist al guardar, valida al leer.'
        );

        $this->artisan('compras:generar')->assertExitCode(0);

        $this->assertEquals(
            0,
            PurchaseSuggestion::where('user_id', $user->id)->count(),
            'Una periodicidad fuera de DIAS_POR_PERIODICIDAD tiene que comportarse como "no genera", nunca como basura ejecutable.'
        );
        Queue::assertNotPushed(GeneratePurchaseSuggestionChunksJob::class);
    }

    /**
     * 🔴 EL TEST QUE PRUEBA A2. Hoy (sin el guard en UserController::update())
     * guardar 'diaria' desde Configuración → general no hacía NADA: el PUT
     * devolvía 200 pero la columna se quedaba en 'nunca', así que
     * compras:generar jamás generaba nada. Con el arreglo, el circuito
     * completo PUT → columna → comando queda vivo de punta a punta.
     *
     * @group sugerencias-compras
     * @test
     */
    public function guardar_diaria_por_el_put_hace_que_compras_generar_si_genere()
    {
        Queue::fake();
        $user = $this->usuario_de_prueba();
        $this->dar_extension($user);

        $response = $this->guardar_configuracion($user, ['sugerencias_compras_periodicidad' => 'diaria']);
        $response->assertStatus(200);

        // Precondición explícita: si esto fallara, la aserción de abajo no
        // probaría nada (compras:generar podría estar generando por otro motivo).
        $this->assertEquals('diaria', $user->fresh()->sugerencias_compras_periodicidad);

        $this->artisan('compras:generar')->assertExitCode(0);

        $this->assertEquals(
            1,
            PurchaseSuggestion::where('user_id', $user->id)->count(),
            'A2: con la periodicidad guardada por el PUT real, compras:generar tiene que generar.'
        );
        $this->assertEquals(
            'automatica',
            PurchaseSuggestion::where('user_id', $user->id)->first()->origen_generacion
        );
        Queue::assertPushed(GeneratePurchaseSuggestionChunksJob::class);
    }

    /**
     * La extensión es el gate de venta, no la periodicidad: ni guardando
     * 'diaria' por el PUT real ni con --force se genera nada si el comercio
     * no tiene sugerencias_compras.
     *
     * @group sugerencias-compras
     * @test
     */
    public function sin_la_extension_no_genera_ni_con_force_aunque_la_periodicidad_este_guardada()
    {
        Queue::fake();
        $user = $this->usuario_de_prueba();

        // A propósito SIN dar_extension().
        $response = $this->guardar_configuracion($user, ['sugerencias_compras_periodicidad' => 'diaria']);
        $response->assertStatus(200);
        $this->assertEquals('diaria', $user->fresh()->sugerencias_compras_periodicidad);

        $this->artisan('compras:generar', ['--force' => true])->assertExitCode(0);

        $this->assertEquals(
            0,
            PurchaseSuggestion::where('user_id', $user->id)->count(),
            'La extensión es el gate de venta: sin ella no se genera nada, ni con --force.'
        );
        Queue::assertNotPushed(GeneratePurchaseSuggestionChunksJob::class);
    }

    /**
     * El PUT no puede pisar la marca del scheduler: el form manda el modelo
     * entero, así que si update() aceptara esta clave cada guardado
     * retrocedería (o adelantaría) la próxima corrida automática. Mismo
     * chequeo que SugerenciasStock/7_Generacion_automatica_Test.php hace para
     * sugerencias_ultima_generacion_at.
     *
     * @group sugerencias-compras
     * @test
     */
    public function el_put_no_puede_tocar_la_marca_de_ultima_generacion()
    {
        $user = $this->usuario_de_prueba();

        // Marca conocida, puesta como la pondría el comando.
        $user->sugerencias_compras_ultima_generacion_at = '2026-08-10 05:30:00';
        $user->save();

        $response = $this->guardar_configuracion($user, [
            'sugerencias_compras_periodicidad'         => 'mensual',
            // El form manda el modelo entero: la clave viaja aunque nadie la
            // haya editado. El backend tiene que ignorarla (no hay ningún
            // $model->sugerencias_compras_ultima_generacion_at = ... en
            // update(), a propósito).
            'sugerencias_compras_ultima_generacion_at' => '2020-01-01 00:00:00',
        ]);
        $response->assertStatus(200);

        $fresco = $user->fresh();
        $this->assertEquals('mensual', $fresco->sugerencias_compras_periodicidad);
        $this->assertEquals(
            '2026-08-10 05:30:00',
            \Carbon\Carbon::parse($fresco->sugerencias_compras_ultima_generacion_at)->format('Y-m-d H:i:s'),
            'La marca del scheduler la escribe solo el comando: un PUT con la clave no puede moverla.'
        );
    }
}
