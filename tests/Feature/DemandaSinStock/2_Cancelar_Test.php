<?php

namespace Tests\Feature\DemandaSinStock;

use App\Models\Article;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión demanda-sin-stock-vender — archivo 2: cancelar un freno registrado
 * (PUT api/unmet-demand/{id}/cancel), criterio CA5.
 *
 * Es el botón "Cancelar" del aviso de Vender: el vendedor se equivocó y no
 * quiere que ese freno cuente como sugerencia de compra. Cancelar NO borra:
 * marca `canceled_at`, así queda rastro y el motor y el asistente lo ignoran.
 */
class Cancelar_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User Dueño del comercio. */
    protected $duenio;

    /** @var User Empleado del dueño (quien opera Vender). */
    protected $empleado;

    /** @var User Otro dueño. */
    protected $otro_duenio;

    /** @var Article Artículo del dueño. */
    protected $mecha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->duenio = User::create([
            'name'     => 'Duenio demanda D2',
            'email'    => 'demanda-d2-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado demanda D2',
            'email'    => 'demanda-d2-emp-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->duenio->id,
        ]);

        $this->otro_duenio = User::create([
            'name'     => 'Otro duenio demanda D2',
            'email'    => 'demanda-d2-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->mecha = Article::create([
            'name'    => 'Mecha 8',
            'user_id' => $this->duenio->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Registra un freno por el endpoint real (así la fila nace como en
     * producción) y devuelve su id.
     *
     * @return int
     */
    protected function registrar_freno()
    {
        $this->actingAs($this->empleado, 'web');

        $respuesta = $this->postJson('api/unmet-demand', [
            'article_id'       => $this->mecha->id,
            'motivo'           => 'sin_stock',
            'cantidad_pedida'  => null,
            'stock_disponible' => 0,
        ]);

        $respuesta->assertStatus(201);

        return (int) $respuesta->json('model.id');
    }

    /**
     * CA5: cancelar responde 200 con canceled_at y deja la fila en la base.
     *
     * @group demanda-sin-stock
     * @test
     */
    public function cancelar_marca_y_no_borra()
    {
        $id = $this->registrar_freno();

        $this->actingAs($this->empleado, 'web');
        $respuesta = $this->putJson('api/unmet-demand/' . $id . '/cancel');

        $respuesta->assertStatus(200);
        $this->assertNotNull($respuesta->json('model.canceled_at'));
        $this->assertEquals($id, $respuesta->json('model.id'));

        $fila = DB::table('unmet_demands')->where('id', $id)->first();
        $this->assertNotNull($fila, 'Cancelar no borra la fila.');
        $this->assertNotNull($fila->canceled_at);
        $this->assertEquals($this->duenio->id, $fila->user_id);
    }

    /**
     * CA5: un segundo PUT responde 200 y no pisa la fecha de la cancelación.
     *
     * @group demanda-sin-stock
     * @test
     */
    public function cancelar_es_idempotente()
    {
        $id = $this->registrar_freno();

        Carbon::setTestNow(Carbon::parse('2026-11-21 10:00:00'));
        $this->actingAs($this->empleado, 'web');
        $primera = $this->putJson('api/unmet-demand/' . $id . '/cancel');
        $primera->assertStatus(200);

        Carbon::setTestNow(Carbon::parse('2026-11-21 11:30:00'));
        $segunda = $this->putJson('api/unmet-demand/' . $id . '/cancel');
        $segunda->assertStatus(200);

        $this->assertNotNull($primera->json('model.canceled_at'));
        $this->assertEquals(
            $primera->json('model.canceled_at'),
            $segunda->json('model.canceled_at'),
            'El segundo cancelar devuelve la misma fecha.'
        );

        $fila = DB::table('unmet_demands')->where('id', $id)->first();
        $this->assertSame('2026-11-21 10:00:00', (string) $fila->canceled_at, 'La base conserva la primera fecha.');
    }

    /**
     * CA5: la fila de otro dueño es 404 y su canceled_at sigue null.
     *
     * @group demanda-sin-stock
     * @test
     */
    public function otro_duenio_da_404()
    {
        $id = $this->registrar_freno();

        $this->actingAs($this->otro_duenio, 'web');
        $respuesta = $this->putJson('api/unmet-demand/' . $id . '/cancel');

        $respuesta->assertStatus(404);
        $respuesta->assertJsonPath('message', 'No encontrado.');

        $fila = DB::table('unmet_demands')->where('id', $id)->first();
        $this->assertNotNull($fila);
        $this->assertNull($fila->canceled_at, 'Un dueño ajeno no puede cancelar el freno de otro comercio.');

        // Y un id que no existe también es 404.
        $this->putJson('api/unmet-demand/99999999/cancel')->assertStatus(404);
    }
}
