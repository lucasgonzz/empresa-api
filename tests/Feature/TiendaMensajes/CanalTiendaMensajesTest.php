<?php

namespace Tests\Feature\TiendaMensajes;

use App\Events\TiendaChatActualizado;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\EmpresaTestCase;

/**
 * El canal privado `tienda-mensajes.{owner_id}` (misión mensajes-tienda-online, 28/9/2026,
 * contrato C1): lo escuchan el dueño y sus empleados, y NADIE de otro comercio.
 *
 * Cómo se prueba la autorización: el `BROADCAST_DRIVER` de testing es `log`, cuyo `auth()` no
 * hace nada (un POST a `broadcasting/auth` daría 200 para cualquiera y no probaría nada). Se llama
 * directo a `verifyUserCanAccessChannel()` del broadcaster donde `routes/channels.php` registró
 * los callbacks: es exactamente lo que ejecuta `PusherBroadcaster::auth()` en producción (con el
 * nombre ya sin el prefijo `private-`), y tira `AccessDeniedHttpException` cuando el callback
 * niega, que es el 403 que recibe Echo.
 *
 * PHP 7.4.
 */
class CanalTiendaMensajesTest extends EmpresaTestCase
{
    use ConversacionesDePrueba;

    /**
     * ¿El usuario puede suscribirse a `private-tienda-mensajes.{owner_id}`?
     *
     * @param  \App\Models\User  $user
     * @param  int  $owner_id
     * @return bool
     */
    protected function puede_escuchar($user, $owner_id)
    {
        $request = Request::create('/broadcasting/auth', 'POST', [
            'socket_id'    => '1234.5678',
            'channel_name' => 'private-tienda-mensajes.'.$owner_id,
        ]);
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $broadcaster = Broadcast::driver();

        $metodo = new \ReflectionMethod($broadcaster, 'verifyUserCanAccessChannel');
        $metodo->setAccessible(true);

        try {
            $metodo->invoke($broadcaster, $request, 'tienda-mensajes.'.$owner_id);
            return true;
        } catch (AccessDeniedHttpException $e) {
            return false;
        }
    }

    /**
     * @test
     */
    public function el_dueno_y_sus_empleados_escuchan_y_otro_comercio_no()
    {
        $dueno = $this->crear_dueno('canal');
        $empleado = $this->crear_empleado($dueno);

        $otro_dueno = $this->crear_dueno('canal otro');
        $empleado_del_otro = $this->crear_empleado($otro_dueno);

        $this->assertTrue($this->puede_escuchar($dueno, $dueno->id), 'El dueño no puede escuchar su propio canal.');
        $this->assertTrue($this->puede_escuchar($empleado, $dueno->id), 'El empleado no puede escuchar el canal de su comercio.');

        $this->assertFalse($this->puede_escuchar($otro_dueno, $dueno->id), 'Otro comercio escucha los mensajes ajenos.');
        $this->assertFalse($this->puede_escuchar($empleado_del_otro, $dueno->id), 'El empleado de otro comercio escucha los mensajes ajenos.');

        // Y el empleado no escucha el canal de otro comercio.
        $this->assertFalse($this->puede_escuchar($empleado, $otro_dueno->id));
    }

    /**
     * @test
     */
    public function el_evento_sale_por_el_canal_privado_del_dueno_con_su_nombre_corto()
    {
        $evento = new TiendaChatActualizado(400, ['buyer_id' => 1]);

        $this->assertInstanceOf(PrivateChannel::class, $evento->broadcastOn());
        $this->assertSame('private-tienda-mensajes.400', $evento->broadcastOn()->name);
        $this->assertSame('TiendaChatActualizado', $evento->broadcastAs());
        $this->assertSame(['buyer_id' => 1], $evento->broadcastWith());
        $this->assertInstanceOf(\Illuminate\Contracts\Broadcasting\ShouldBroadcastNow::class, $evento);
    }
}
