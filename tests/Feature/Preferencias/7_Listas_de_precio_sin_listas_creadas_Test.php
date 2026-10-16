<?php

namespace Tests\Feature\Preferencias;

use App\Models\PriceType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature tests de tildar / destildar "trabajar con listas de precio" en la configuración general.
 *
 * El bug: en una cuenta SIN ninguna lista de precio creada (el caso de las demos), el primer
 * guardado devolvía 500 y el segundo andaba. `UserController@update` guarda el flag en el dueño
 * y recién después sincroniza la tabla `article_price_type`; esa sincronización leía
 * `PriceType::...->first()->position` y con cero listas `first()` es null. El flag ya estaba
 * guardado, así que el reintento veía "sin cambios" y salía antes de llegar a esa línea.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing está sembrada de antes.
 */
class Listas_de_precio_sin_listas_creadas_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Dueño de los tests, con el flag en el valor pedido y SIN listas de precio.
     *
     * @param int $listas_de_precio Valor inicial del flag.
     * @return \App\Models\User|null Null si la base de testing no tiene el usuario sembrado.
     */
    protected function dueno_sin_listas($listas_de_precio)
    {
        $user = User::find(500);
        if (is_null($user)) {
            return null;
        }

        PriceType::where('user_id', $user->id)->delete();
        $user->listas_de_precio = $listas_de_precio;
        $user->save();

        return $user->fresh();
    }

    /**
     * Payload del formulario: el update asigna campo por campo desde el request, así que se manda
     * el perfil completo del dueño más el flag nuevo (lo que hace la SPA al guardar).
     *
     * @param \App\Models\User $user
     * @param int $listas_de_precio
     * @return array<string, mixed>
     */
    protected function payload(User $user, $listas_de_precio)
    {
        return array_merge($user->toArray(), ['listas_de_precio' => $listas_de_precio]);
    }

    /**
     * @group preferencias
     * @test
     */
    public function tildar_listas_de_precio_sin_listas_creadas_guarda_sin_error()
    {
        Queue::fake();
        $user = $this->dueno_sin_listas(0);
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $response = $this->putJson('api/user/'.$user->id, $this->payload($user, 1));

        $response->assertStatus(200);
        $this->assertEquals(1, (int) $user->fresh()->listas_de_precio);
    }

    /**
     * @group preferencias
     * @test
     */
    public function destildar_listas_de_precio_sin_listas_creadas_guarda_sin_error()
    {
        Queue::fake();
        $user = $this->dueno_sin_listas(1);
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $response = $this->putJson('api/user/'.$user->id, $this->payload($user, 0));

        $response->assertStatus(200);
        $this->assertEquals(0, (int) $user->fresh()->listas_de_precio);
    }

    /**
     * Con listas creadas el camino de siempre no cambia: tildar crea la pivot de cada artículo
     * contra cada lista. Protege que el arreglo no rompa el caso que ya andaba.
     *
     * @group preferencias
     * @test
     */
    public function tildar_listas_de_precio_con_una_lista_creada_sigue_andando()
    {
        Queue::fake();
        $user = $this->dueno_sin_listas(0);
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        PriceType::create([
            'num'        => 1,
            'name'       => 'Lista de prueba',
            'percentage' => 10,
            'position'   => 1,
            'user_id'    => $user->id,
        ]);
        $this->actingAs($user, 'web');

        $response = $this->putJson('api/user/'.$user->id, $this->payload($user, 1));

        $response->assertStatus(200);
        $this->assertEquals(1, (int) $user->fresh()->listas_de_precio);
    }
}
