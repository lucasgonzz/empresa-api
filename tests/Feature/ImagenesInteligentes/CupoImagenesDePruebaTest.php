<?php

namespace Tests\Feature\ImagenesInteligentes;

use App\Http\Controllers\Helpers\PruebaHelper;
use App\Jobs\ProcessImageAssignmentRunJob;
use App\Models\ImageAssignmentItem;
use App\Models\ImageAssignmentRun;
use App\Services\ImageAssignment\CupoImagenesDePrueba;
use Illuminate\Support\Facades\Queue;

class CupoImagenesDePruebaTest extends ImagenesInteligentesTestCase
{
    const CLAVE_ADMIN = 'clave-falsa-cupo-imagenes-prueba';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.FOR_USER' => 'prueba',
            'app.USER_ID' => $this->owner->id,
            'services.admin_api.api_key' => self::CLAVE_ADMIN,
        ]);
        PruebaHelper::olvidar_cache();
        PruebaHelper::activar_desde_payload($this->owner, ['vence_at' => now()->addDays(7)->toIso8601String()]);
        $this->falsear([], [], []);
    }

    protected function tearDown(): void
    {
        PruebaHelper::olvidar_cache();
        parent::tearDown();
    }

    public function test_el_dueno_puede_buscar_hasta_el_cupo_y_el_admin_puede_cambiarlo_sin_reiniciarlo()
    {
        $this->getJson('/api/image-assignment-runs/tope-demo')
            ->assertOk()->assertJson(['unidad' => 'producto', 'tope' => 30, 'restantes' => 30]);

        $this->postJson('/api/admin-sync/prueba/cupo-imagenes', ['tope' => 1])->assertStatus(401);

        $this->cambiar_tope(1)->assertOk();
        $this->nuevo_articulo('Pinza de punta');
        $this->nuevo_articulo('Martillo');
        $this->getJson('/api/image-assignment-runs/catalogo/previa')
            ->assertOk()->assertJson(['a_buscar' => 1, 'tope_demo' => ['restantes' => 1]]);

        Queue::fake();
        $respuesta = $this->postJson('/api/image-assignment-runs/catalogo');
        $respuesta->assertCreated()->assertJsonPath('model.total_articulos', 1);
        (new ProcessImageAssignmentRunJob($respuesta->json('model.id')))->handle();
        $this->assertCount(1, $this->consultas_serper);

        $this->postJson('/api/image-assignment-runs/catalogo')->assertStatus(422);
        $this->cambiar_tope(4)->assertOk()->assertJson(['imagenes' => ['tope' => 4, 'usadas' => 1, 'restantes' => 3]]);
        $this->getJson('/api/admin-sync/prueba/estado', ['X-Admin-Api-Key' => self::CLAVE_ADMIN])
            ->assertOk()->assertJson(['imagenes' => ['tope' => 4, 'usadas' => 1, 'restantes' => 3]]);
        $this->cambiar_tope(0)->assertOk()->assertJson(['imagenes' => ['tope' => 0, 'usadas' => 1, 'restantes' => 0]]);
    }

    public function test_codigo_y_nombre_y_un_reintento_del_mismo_item_cuentan_un_solo_producto()
    {
        $this->cambiar_tope(1)->assertOk();
        $primero = $this->nuevo_articulo('Pinza de punta', $this->con_verificador('779000940002'));
        $segundo = $this->nuevo_articulo('Martillo');
        $run = $this->asignacion([$primero], ['origen' => ImageAssignmentRun::ORIGEN_ASISTENTE]);
        $otro_run = $this->asignacion([$segundo], ['origen' => ImageAssignmentRun::ORIGEN_SELECCION]);

        $this->procesar($run, $primero);
        $this->assertCount(2, $this->consultas_serper);
        $this->procesar($run, $primero);
        $this->assertCount(4, $this->consultas_serper);

        $item = $this->procesar($otro_run, $segundo);
        $this->assertSame(ImageAssignmentItem::STATUS_SIN_PROCESAR, $item->status);
        $this->assertCount(4, $this->consultas_serper);
        $this->assertSame(['tope' => 1, 'usadas' => 1, 'restantes' => 0], CupoImagenesDePrueba::estado($this->owner->id));
    }

    public function test_la_seleccion_crea_solo_los_items_que_entran_en_el_saldo()
    {
        $this->cambiar_tope(1)->assertOk();
        $primero = $this->nuevo_articulo('Pinza de punta');
        $segundo = $this->nuevo_articulo('Martillo');
        Queue::fake();

        $this->postJson('/api/google/batch-assign-images', ['article_ids' => [$primero->id, $segundo->id]])
            ->assertOk()->assertJson(['articulos' => 1, 'saltados_por_cupo' => 1]);
    }

    public function test_al_contratar_el_dueno_puede_seguir_buscando_con_el_cupo_agotado()
    {
        $this->cambiar_tope(0)->assertOk();
        PruebaHelper::fijar_vencimiento($this->owner->id, null);
        $this->getJson('/api/image-assignment-runs/tope-demo')->assertOk()->assertJson(['aplica' => false]);
        $this->getJson('/api/image-assignment-runs/catalogo/previa')->assertOk();
        $articulo = $this->nuevo_articulo('Pinza de punta');
        $run = $this->asignacion([$articulo], ['aplica_tope_diario' => true]);

        $this->procesar($run, $articulo);
        $this->assertCount(1, $this->consultas_serper);
        $this->assertSame(['tope' => 0, 'usadas' => 0, 'restantes' => 0], CupoImagenesDePrueba::estado($this->owner->id));
    }

    public function test_una_corrida_que_arranca_tras_el_vencimiento_no_busca()
    {
        $articulo = $this->nuevo_articulo('Pinza de punta');
        $run = $this->asignacion([$articulo]);
        PruebaHelper::fijar_vencimiento($this->owner->id, now()->subMinute());

        (new ProcessImageAssignmentRunJob($run->id))->handle();
        $this->assertCount(0, $this->consultas_serper);
        $this->assertSame(PruebaHelper::MENSAJE_VENCIDA, $run->fresh()->motivo_estado);
    }

    public function test_cambiar_el_cupo_exige_la_clave_del_admin_aunque_el_flag_general_este_apagado()
    {
        config(['services.admin_api.require_api_key' => false]);
        $this->postJson('/api/admin-sync/prueba/cupo-imagenes', ['tope' => 0])->assertStatus(401);

        config(['services.admin_api.api_key' => null]);
        $this->cambiar_tope(0)->assertStatus(401);
        $this->assertSame(30, CupoImagenesDePrueba::estado($this->owner->id)['tope']);
    }

    private function cambiar_tope(int $tope)
    {
        return $this->postJson('/api/admin-sync/prueba/cupo-imagenes', ['tope' => $tope], ['X-Admin-Api-Key' => self::CLAVE_ADMIN]);
    }
}
