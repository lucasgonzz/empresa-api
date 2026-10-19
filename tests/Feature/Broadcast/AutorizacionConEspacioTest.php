<?php

namespace Tests\Feature\Broadcast;

use App\Broadcasting\EspacioDeCanales;
use App\Broadcasting\PusherBroadcasterConEspacio;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `POST broadcasting/auth` con el espacio de nombres (misión pusher-canales-por-sistema, 19/10/2026).
 *
 * Lo que protege: cada sistema firma SOLO los canales privados de SU espacio. Antes de esta misión,
 * el usuario 5 de cualquier sistema de la flota quedaba autorizado al canal del usuario 5 de este
 * (mismo id, misma app de Pusher), y eso incluía el canal del candado de sesión.
 *
 * Pega por HTTP, como Echo: ruta de `Broadcast::routes()` → `BroadcastController` → `Broadcast::auth()`
 * → `PusherBroadcasterConEspacio::auth()` → callbacks de `routes/channels.php`. Para que eso pase por
 * el broadcaster de verdad y no por el `log` de testing, ver `PusherDePrueba`.
 *
 * Con el `PusherBroadcaster` de vendor fallan los cortes: un sufijo de otro espacio da 200 (Laravel
 * matchea `chat.user.{id}` con `Str::is`, el parámetro llega como `'5@otro'` y `(int)` da 5), y el
 * nombre sin sufijo también.
 *
 * PHP 7.4.
 */
class AutorizacionConEspacioTest extends TestCase
{
    use DatabaseTransactions;
    use PusherDePrueba;

    /** @var string Espacio de este sistema (base de testing + secret de prueba). */
    protected $espacio;

    /** @var string Espacio de otro sistema de la misma app de Pusher. */
    protected $otro_espacio;

    /** @var User Dueño de este comercio. */
    protected $dueno;

    /** @var User Empleado del dueño. */
    protected $empleado;

    /** @var User Dueño de OTRO comercio de la misma base. */
    protected $otro_dueno;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.espacio'                  => '',
            'broadcasting.canales_viejos_de_tienda' => true,
        ]);

        $this->usar_pusher_de_prueba();

        // 🔴 Que de verdad pase por el broadcaster nuevo: si no, el test probaría el log.
        $this->assertInstanceOf(PusherBroadcasterConEspacio::class, Broadcast::driver());

        $this->espacio = EspacioDeCanales::calcular(
            DB::connection()->getDatabaseName(),
            config('app.env'),
            $this->secret_de_prueba
        );
        $this->otro_espacio = EspacioDeCanales::calcular('u767360347_ferretotal', 'production', $this->secret_de_prueba);

        $this->dueno = User::create([
            'name'         => 'Dueño canales auth',
            'company_name' => 'Comercio canales auth',
            'email'        => 'canales-auth-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado canales auth',
            'email'    => 'canales-auth-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->dueno->id,
        ]);

        $this->otro_dueno = User::create([
            'name'         => 'Otro dueño canales auth',
            'company_name' => 'Otro comercio canales auth',
            'email'        => 'canales-auth-otro-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);
    }

    /**
     * POST a broadcasting/auth como lo hace el autorizador de Echo del SPA.
     *
     * @param string $channel_name
     * @return \Illuminate\Testing\TestResponse
     */
    protected function autorizar($channel_name)
    {
        return $this->postJson('broadcasting/auth', [
            'socket_id'    => '1234.5678',
            'channel_name' => $channel_name,
        ]);
    }

    /**
     * La firma que Pusher espera para ese nombre de canal exacto.
     *
     * @param string $channel_name
     * @return string
     */
    protected function firma_esperada($channel_name)
    {
        return $this->key_de_prueba . ':' . hash_hmac('sha256', '1234.5678:' . $channel_name, $this->secret_de_prueba);
    }

    /**
     * @test
     */
    public function el_privado_con_mi_espacio_se_autoriza_y_se_firma_con_el_nombre_completo()
    {
        $this->actingAs($this->empleado, 'web');

        $canal = 'private-chat.user.' . $this->empleado->id . '@' . $this->espacio;

        $respuesta = $this->autorizar($canal);

        $respuesta->assertStatus(200);

        // 🔴 Firmado sobre el nombre COMPLETO, con sufijo: es contra el que Pusher valida la suscripción.
        $this->assertSame($this->firma_esperada($canal), $respuesta->json('auth'));
    }

    /**
     * @test
     */
    public function el_privado_con_el_espacio_de_otro_sistema_da_403()
    {
        $this->actingAs($this->empleado, 'web');

        $this->autorizar('private-chat.user.' . $this->empleado->id . '@' . $this->otro_espacio)
            ->assertStatus(403);

        $this->autorizar('private-App.Models.User.' . $this->empleado->id . '@' . $this->otro_espacio)
            ->assertStatus(403);

        // Un sufijo ajeno disfrazado adelante del propio tampoco.
        $this->autorizar('private-chat.user.' . $this->empleado->id . '@' . $this->otro_espacio . '@' . $this->espacio)
            ->assertStatus(403);
    }

    /**
     * Sin sufijo ya no lo emite nadie de este sistema: autorizarlo sería dejar escuchar lo que emita
     * otro sistema con el mismo id.
     *
     * @test
     */
    public function el_privado_sin_sufijo_da_403()
    {
        $this->actingAs($this->empleado, 'web');

        $this->autorizar('private-chat.user.' . $this->empleado->id)->assertStatus(403);
        $this->autorizar('private-App.Models.User.' . $this->empleado->id)->assertStatus(403);
        $this->autorizar('private-whatsapp.' . $this->dueno->id)->assertStatus(403);
    }

    /**
     * El candado de sesión con el espacio propio sigue andando.
     *
     * @test
     */
    public function el_canal_del_candado_con_mi_espacio_se_autoriza()
    {
        $this->actingAs($this->dueno, 'web');

        $canal = 'private-App.Models.User.' . $this->dueno->id . '@' . $this->espacio;

        $this->autorizar($canal)->assertStatus(200)->assertJson(['auth' => $this->firma_esperada($canal)]);
    }

    /**
     * Con sufijo propio, el callback de `routes/channels.php` sigue mandando.
     *
     * @test
     */
    public function con_mi_espacio_el_callback_sigue_mandando()
    {
        $this->actingAs($this->empleado, 'web');

        // El chat es por persona: el empleado no abre el del dueño.
        $this->autorizar('private-chat.user.' . $this->dueno->id . '@' . $this->espacio)->assertStatus(403);

        // Mensajes de la tienda: el empleado abre el de su comercio y no el de otro comercio.
        $this->autorizar('private-tienda-mensajes.' . $this->dueno->id . '@' . $this->espacio)->assertStatus(200);
        $this->autorizar('private-tienda-mensajes.' . $this->otro_dueno->id . '@' . $this->espacio)->assertStatus(403);
    }

    /**
     * Compatibilidad con la tienda vieja: en transición, Mensajes sin sufijo se sigue autorizando, con
     * el mismo callback (otro comercio no entra).
     *
     * @test
     */
    public function mensajes_de_la_tienda_sin_sufijo_en_transicion_se_autoriza_con_su_callback()
    {
        $this->actingAs($this->empleado, 'web');

        $canal = 'private-tienda-mensajes.' . $this->dueno->id;

        $this->autorizar($canal)->assertStatus(200)->assertJson(['auth' => $this->firma_esperada($canal)]);

        $this->autorizar('private-tienda-mensajes.' . $this->otro_dueno->id)->assertStatus(403);
    }

    /**
     * @test
     */
    public function mensajes_de_la_tienda_sin_sufijo_en_estricto_da_403()
    {
        config(['broadcasting.canales_viejos_de_tienda' => false]);

        $this->actingAs($this->empleado, 'web');

        $this->autorizar('private-tienda-mensajes.' . $this->dueno->id)->assertStatus(403);

        // Con el sufijo propio sí.
        $this->autorizar('private-tienda-mensajes.' . $this->dueno->id . '@' . $this->espacio)->assertStatus(200);
    }
}
