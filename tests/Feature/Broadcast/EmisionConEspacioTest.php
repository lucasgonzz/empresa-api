<?php

namespace Tests\Feature\Broadcast;

use App\Broadcasting\EspacioDeCanales;
use App\Broadcasting\PusherBroadcasterConEspacio;
use App\Events\ChatIaMensajeActualizado;
use App\Events\RespuestaDelComercio;
use App\Events\TiendaChatActualizado;
use App\Models\User;
use App\Notifications\GlobalNotification;
use App\Notifications\SessionForcedLogoutNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Lo que de verdad sale hacia Pusher (misión pusher-canales-por-sistema, 19/10/2026): cada evento y
 * cada notificación del repo, sin tocar su `broadcastOn()`, sale por el nombre FÍSICO del canal
 * (`@{espacio}`) y NO por el lógico.
 *
 * Se emite por el camino completo —`event()` / `$user->notify()` → `BroadcastEvent` → broadcaster del
 * provider → SDK de Pusher real— y se mira el cuerpo del POST que el SDK intentó mandar. Sin red: ver
 * `PusherDePrueba`.
 *
 * Con el `PusherBroadcaster` de vendor (antes de esta misión) todos estos tests fallan: el canal sale
 * con el nombre lógico, que es el que escuchaba cualquier otro sistema con el mismo id de dueño.
 *
 * PHP 7.4.
 */
class EmisionConEspacioTest extends TestCase
{
    use DatabaseTransactions;
    use PusherDePrueba;

    /** @var string Espacio de la base de testing con el secret de prueba. */
    protected $espacio;

    /** @var User Notificable de las notificaciones (la notificación decide el canal, no él). */
    protected $usuario;

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

        $broadcaster = $this->usar_pusher_de_prueba();

        $this->assertInstanceOf(
            PusherBroadcasterConEspacio::class,
            $broadcaster,
            'El provider no está devolviendo el broadcaster con espacio.'
        );

        // Calculado a mano, con las entradas reales: no se le cree a espacio().
        $this->espacio = EspacioDeCanales::calcular(
            DB::connection()->getDatabaseName(),
            config('app.env'),
            $this->secret_de_prueba
        );

        $this->usuario = User::create([
            'name'         => 'Comercio canales por sistema',
            'company_name' => 'Comercio canales',
            'email'        => 'canales-por-sistema-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);
    }

    /**
     * Payload mínimo de `GlobalNotification`.
     *
     * @param int $owner_id
     * @return array
     */
    protected function datos_de_aviso($owner_id)
    {
        return [
            'message_text'          => 'Escaneo de factura terminado',
            'color_variant'         => 'success',
            'functions_to_execute'  => [],
            'info_to_show'          => [],
            'owner_id'              => $owner_id,
            'is_only_for_auth_user' => false,
        ];
    }

    /**
     * El caso que motivó la misión: el aviso de demo3 al dueño 2600 lo escuchaba Ferretotal.
     *
     * @test
     */
    public function el_aviso_global_al_dueno_2600_sale_solo_por_el_canal_con_espacio()
    {
        $this->usuario->notify(new GlobalNotification($this->datos_de_aviso(2600)));

        $this->assertSame(
            [['global_notification.2600@' . $this->espacio]],
            $this->canales_emitidos(),
            'El aviso no salió (o salió por otro canal). Sin el espacio sale por global_notification.2600, que escucha cualquier sistema con el dueño 2600.'
        );
    }

    /**
     * @test
     */
    public function el_chat_del_asistente_sale_por_su_privado_con_espacio()
    {
        event(new ChatIaMensajeActualizado($this->usuario->id, 12, 34, 'listo'));

        $this->assertSame(
            [['private-chat.user.' . $this->usuario->id . '@' . $this->espacio]],
            $this->canales_emitidos()
        );
    }

    /**
     * 🔴 El candado de sesión: sin el espacio, un login en otro sistema con el mismo id expulsaba a
     * este usuario.
     *
     * @test
     */
    public function la_expulsion_del_candado_sale_por_el_privado_del_usuario_con_espacio()
    {
        $this->usuario->notify(new SessionForcedLogoutNotification());

        $this->assertSame(
            [['private-App.Models.User.' . $this->usuario->id . '@' . $this->espacio]],
            $this->canales_emitidos()
        );
    }

    /**
     * En transición, la respuesta al comprador sale por los DOS nombres en un solo trigger: el físico
     * para una tienda nueva y el lógico para una vieja.
     *
     * @test
     */
    public function la_respuesta_al_comprador_en_transicion_sale_por_los_dos_nombres()
    {
        event(new RespuestaDelComercio(400, 7, ['buyer_id' => 7]));

        $this->assertSame(
            [['tienda-respuestas.400.7@' . $this->espacio, 'tienda-respuestas.400.7']],
            $this->canales_emitidos()
        );
    }

    /**
     * @test
     */
    public function la_respuesta_al_comprador_en_estricto_sale_solo_por_el_fisico()
    {
        config(['broadcasting.canales_viejos_de_tienda' => false]);

        event(new RespuestaDelComercio(400, 7, ['buyer_id' => 7]));

        $this->assertSame(
            [['tienda-respuestas.400.7@' . $this->espacio]],
            $this->canales_emitidos()
        );
    }

    /**
     * El privado de Mensajes de la tienda, que también escucha un empresa-spa viejo sin espacio.
     *
     * @test
     */
    public function los_mensajes_de_la_tienda_en_transicion_salen_por_los_dos_nombres()
    {
        event(new TiendaChatActualizado(400, ['buyer_id' => 1]));

        $this->assertSame(
            [['private-tienda-mensajes.400@' . $this->espacio, 'private-tienda-mensajes.400']],
            $this->canales_emitidos()
        );
    }

    /**
     * El override manda también al emitir (es lo que se usaría para alinear a mano el ERP y la tienda).
     *
     * @test
     */
    public function con_override_el_canal_sale_con_ese_espacio()
    {
        config(['broadcasting.espacio' => 'manual123']);

        $this->usuario->notify(new GlobalNotification($this->datos_de_aviso(2600)));

        $this->assertSame([['global_notification.2600@manual123']], $this->canales_emitidos());
    }
}
