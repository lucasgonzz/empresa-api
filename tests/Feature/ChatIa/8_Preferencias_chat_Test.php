<?php

namespace Tests\Feature\ChatIa;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Misión chat-ia-y-modulo-ia — P8: preferencias de UI del chat POR PERSONA.
 *
 * Lo que protege este archivo es la separación persona/cuenta de D1-D2: la
 * posición del botón, el ancho de la sidebar y el ancho del modal entero
 * (19/8/2026) se guardan en la fila del usuario AUTENTICADO (empleado incluido),
 * nunca en la del dueño — el bug
 * que se está evitando es el de vender_left_width, que por resolverse con el
 * dueño es por cuenta. Y que los valores viajan solos en GET api/user, sin
 * requests extra.
 */
class Preferencias_chat_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $empleado;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: los tests jamás salen a la red.
        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'         => 'Comercio chat-ia P8',
            'company_name' => 'Ferreteria P8',
            'email'        => 'chat-ia-p8-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado chat-ia P8',
            'email'    => 'chat-ia-p8-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function las_columnas_de_preferencias_existen_en_users()
    {
        $this->assertTrue(Schema::hasColumn('users', 'chat_ia_fab_position'));
        $this->assertTrue(Schema::hasColumn('users', 'chat_ia_sidebar_width'));
        $this->assertTrue(Schema::hasColumn('users', 'chat_ia_panel_width'));
    }

    /**
     * @group chat-ia
     * @test
     */
    public function un_empleado_guarda_su_posicion_y_no_se_escribe_en_la_fila_del_dueno()
    {
        $this->actingAs($this->empleado, 'web');

        $response = $this->putJson('api/user/set-chat-ia-preferencias', [
            'chat_ia_fab_position'  => '24,600',
            'chat_ia_sidebar_width' => 300,
            'chat_ia_panel_width'   => 1100,
        ]);

        $response->assertStatus(200);
        $this->assertEquals('24,600', $response->json('chat_ia_fab_position'));
        $this->assertEquals(300, $response->json('chat_ia_sidebar_width'));
        $this->assertEquals(1100, $response->json('chat_ia_panel_width'));

        // La fila del EMPLEADO tiene los valores...
        $this->empleado->refresh();
        $this->assertEquals('24,600', $this->empleado->chat_ia_fab_position);
        $this->assertEquals(300, (int) $this->empleado->chat_ia_sidebar_width);
        $this->assertEquals(1100, (int) $this->empleado->chat_ia_panel_width);

        // ...y la del dueño quedó intacta: la preferencia es de la persona.
        $this->comercio->refresh();
        $this->assertNull($this->comercio->chat_ia_fab_position);
        $this->assertNull($this->comercio->chat_ia_sidebar_width);
        $this->assertNull($this->comercio->chat_ia_panel_width);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function los_valores_fuera_de_rango_se_rechazan_con_422_sin_guardar_nada()
    {
        $this->actingAs($this->empleado, 'web');

        $invalidos = [
            // Posiciones rotas: coordenada gigante, texto, decimales, negativo, una sola parte.
            ['chat_ia_fab_position' => '99999,20'],
            ['chat_ia_fab_position' => 'izquierda,arriba'],
            ['chat_ia_fab_position' => '12.5,30'],
            ['chat_ia_fab_position' => '-5,30'],
            ['chat_ia_fab_position' => '120'],
            // Anchos fuera del rango 180..420 de la SPA, o no enteros.
            ['chat_ia_sidebar_width' => 90],
            ['chat_ia_sidebar_width' => 1000],
            ['chat_ia_sidebar_width' => 'ancho'],
            // Anchos de panel fuera del rango 720..1600 que clampa la SPA, o no enteros.
            // 🔴 El 500 y el 1700 son los que blindan que este rango NO se pueda relajar
            // de un lado solo: si la SPA y el endpoint dejan de decir el mismo numero, el
            // usuario arrastra hasta un ancho que se pierde en silencio al recargar.
            ['chat_ia_panel_width' => 500],
            ['chat_ia_panel_width' => 1700],
            ['chat_ia_panel_width' => 900.5],
            ['chat_ia_panel_width' => 'ancho'],
            // Una clave válida no salva a la otra: no se guarda nada.
            ['chat_ia_fab_position' => '10,10', 'chat_ia_sidebar_width' => 5000],
            ['chat_ia_sidebar_width' => 300, 'chat_ia_panel_width' => 5000],
        ];

        foreach ($invalidos as $payload) {
            $this->putJson('api/user/set-chat-ia-preferencias', $payload)
                ->assertStatus(422);
        }

        $this->empleado->refresh();
        $this->assertNull($this->empleado->chat_ia_fab_position, 'Ningún 422 puede haber persistido una posición.');
        $this->assertNull($this->empleado->chat_ia_sidebar_width, 'Ningún 422 puede haber persistido un ancho.');
        $this->assertNull($this->empleado->chat_ia_panel_width, 'Ningún 422 puede haber persistido un ancho de panel.');
    }

    /**
     * Las claves son opcionales: mandar una sola toca esa sola.
     *
     * @group chat-ia
     * @test
     */
    public function cada_clave_se_puede_guardar_por_separado()
    {
        $this->actingAs($this->empleado, 'web');

        $this->putJson('api/user/set-chat-ia-preferencias', [
            'chat_ia_sidebar_width' => 260,
        ])->assertStatus(200);

        $this->empleado->refresh();
        $this->assertEquals(260, (int) $this->empleado->chat_ia_sidebar_width);
        $this->assertNull($this->empleado->chat_ia_fab_position);

        $this->putJson('api/user/set-chat-ia-preferencias', [
            'chat_ia_fab_position' => '0,0',
        ])->assertStatus(200);

        $this->empleado->refresh();
        $this->assertEquals('0,0', $this->empleado->chat_ia_fab_position);
        $this->assertEquals(260, (int) $this->empleado->chat_ia_sidebar_width, 'Guardar la posición no puede pisar el ancho.');

        // El ancho del MODAL es una tercera medida independiente: guardarlo no puede
        // tocar ni la posicion del boton ni el ancho de la sidebar de adentro.
        $this->putJson('api/user/set-chat-ia-preferencias', [
            'chat_ia_panel_width' => 1240,
        ])->assertStatus(200);

        $this->empleado->refresh();
        $this->assertEquals(1240, (int) $this->empleado->chat_ia_panel_width);
        $this->assertEquals('0,0', $this->empleado->chat_ia_fab_position);
        $this->assertEquals(260, (int) $this->empleado->chat_ia_sidebar_width, 'El ancho del modal no puede pisar el de la sidebar.');
    }

    /**
     * D1: las preferencias viajan solas en el GET api/user de la PERSONA
     * (getFullModel(false) devuelve la fila del auth_user, y User no tiene
     * estas columnas en hidden). Cero requests extra en el arranque.
     *
     * @group chat-ia
     * @test
     */
    public function get_api_user_del_empleado_devuelve_sus_propios_valores()
    {
        $this->empleado->chat_ia_fab_position = '40,700';
        $this->empleado->chat_ia_sidebar_width = 320;
        $this->empleado->chat_ia_panel_width = 1180;
        $this->empleado->save();

        // El dueño con otros valores, para probar que viaja la fila correcta.
        $this->comercio->chat_ia_fab_position = '5,5';
        $this->comercio->save();

        $this->actingAs($this->empleado, 'web');

        $response = $this->getJson('api/user');
        $response->assertStatus(200);

        $this->assertEquals('40,700', $response->json('user.chat_ia_fab_position'));
        $this->assertEquals(320, (int) $response->json('user.chat_ia_sidebar_width'));
        $this->assertEquals(1180, (int) $response->json('user.chat_ia_panel_width'), 'Sin esto la SPA arrancaria con el ancho por defecto en cada recarga.');
    }
}
