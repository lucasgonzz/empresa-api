<?php

namespace Tests\Feature\TiendaMensajes;

use Tests\EmpresaTestCase;

/**
 * Los endpoints viejos de mensajes, que siguen vivos para la SPA de los clientes sin actualizar
 * (misión mensajes-tienda-online, 28/9/2026, contrato C4): `GET message/{buyer_id}` y
 * `GET message/set-read/{buyer_id}`.
 *
 * Hasta esta misión no verificaban que el comprador fuera del comercio: en las bases compartidas
 * (`u767360347_empresa`, 51 comercios adentro) se leían y se marcaban chats ajenos por id. Ahora
 * un comprador ajeno da la lista vacía y el `set-read` no toca nada. Un pedido legítimo sigue
 * dando lo mismo que antes.
 *
 * PHP 7.4.
 */
class EndpointsViejosTest extends EmpresaTestCase
{
    use ConversacionesDePrueba;

    /** @var \App\Models\User */
    protected $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = $this->crear_dueno('viejos');

        $this->actuar_como($this->dueno);
    }

    /**
     * @test
     */
    public function la_conversacion_vieja_de_un_comprador_ajeno_viene_vacia()
    {
        $ajeno = $this->crear_comprador_de($this->crear_dueno('viejos ajeno'));
        $this->mensaje_del_comprador($ajeno, ['text' => 'Esto es de otro comercio']);

        $this->getJson('api/message/'.$ajeno->id)
             ->assertStatus(200)
             ->assertExactJson(['models' => []]);
    }

    /**
     * @test
     */
    public function la_conversacion_vieja_de_un_comprador_propio_sigue_igual()
    {
        $comprador = $this->crear_comprador_de($this->dueno);
        $primero = $this->mensaje_del_comprador($comprador, ['text' => 'uno']);
        $segundo = $this->mensaje_del_comercio($comprador, ['text' => 'dos']);

        $models = $this->getJson('api/message/'.$comprador->id)->assertStatus(200)->json('models');

        $this->assertSame([$primero->id, $segundo->id], array_map(function ($m) {
            return $m['id'];
        }, $models));
        // Sigue trayendo el `article` completo del withAll() (null acá: sin artículo).
        $this->assertArrayHasKey('article', $models[0]);
    }

    /**
     * @test
     */
    public function el_set_read_viejo_no_toca_a_un_comprador_ajeno()
    {
        $ajeno = $this->crear_comprador_de($this->crear_dueno('viejos ajeno set-read'));
        $mensaje = $this->mensaje_del_comprador($ajeno);

        $this->getJson('api/message/set-read/'.$ajeno->id)->assertStatus(200);

        $this->assertSame(0, (int) $mensaje->fresh()->read);
    }

    /**
     * @test
     */
    public function el_set_read_viejo_marca_los_del_comprador_propio_con_un_solo_update()
    {
        $comprador = $this->crear_comprador_de($this->dueno);
        $pendientes = [
            $this->mensaje_del_comprador($comprador),
            $this->mensaje_del_comprador($comprador),
            $this->mensaje_del_comprador($comprador),
        ];
        $del_comercio = $this->mensaje_del_comercio($comprador, ['read' => 0]);

        $self = $this;

        $consultas = $this->consultas_de(function () use ($self, $comprador) {
            $self->getJson('api/message/set-read/'.$comprador->id)->assertStatus(200);
        });

        foreach ($pendientes as $mensaje) {
            $this->assertSame(1, (int) $mensaje->fresh()->read);
        }
        $this->assertSame(0, (int) $del_comercio->fresh()->read);

        $updates = array_filter($consultas, function ($consulta) {
            return preg_match('/^update `messages`/i', $consulta['query']) === 1;
        });

        $this->assertCount(1, $updates);
    }
}
