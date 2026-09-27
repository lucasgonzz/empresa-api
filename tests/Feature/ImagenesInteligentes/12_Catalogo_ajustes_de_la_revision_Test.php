<?php

namespace Tests\Feature\ImagenesInteligentes;

use App\Http\Controllers\Helpers\ImageAssignmentRunHelper;
use App\Models\ImageAssignmentItem;
use App\Models\ImageAssignmentRun;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Los ajustes del catálogo que salieron de la revisión independiente (plan §13):
 *
 *   - B2: sin IA, la previa lo dice (ia_configurada / ia_motivo) y el POST del catálogo da 422.
 *   - B3: el catálogo no mete artículos que ya esperan turno en otra asignación en curso; uno con una
 *     propuesta a revisar en OTRA asignación queda `en_otra_asignacion` sin buscar; y no se reanuda
 *     una de catálogo con otra de catálogo en curso.
 *   - B7: detener / reanudar las de selección o del asistente lo puede cualquiera del comercio; las
 *     de catálogo, solo el acceso maestro.
 *   - B10: para las rechazadas y quitadas, los 90 días se cuentan desde el rechazo.
 */
class Catalogo_ajustes_de_la_revision_Test extends ImagenesInteligentesTestCase
{
    /** Un EAN-13 de fábrica válido. */
    const CODIGO_REAL = '7791234567898';

    /**
     * Una asignación con fecha y estado puestos a mano.
     *
     * @param  array $datos
     * @return \App\Models\ImageAssignmentRun
     */
    protected function corrida(array $datos)
    {
        return ImageAssignmentRun::create(array_merge([
            'user_id'         => $this->owner->id,
            'uuid'            => (string) Str::uuid(),
            'origen'          => ImageAssignmentRun::ORIGEN_SELECCION,
            'proveedor'       => ImageAssignmentRun::PROVEEDOR_SERPER,
            'status'          => ImageAssignmentRun::STATUS_TERMINADA,
            'total_articulos' => 1,
        ], $datos));
    }

    /**
     * Un item de una asignación.
     *
     * @param  \App\Models\ImageAssignmentRun $run
     * @param  \App\Models\Article            $articulo
     * @param  array                          $datos
     * @return \App\Models\ImageAssignmentItem
     */
    protected function item_de($run, $articulo, array $datos)
    {
        return ImageAssignmentItem::create(array_merge([
            'run_id'       => $run->id,
            'user_id'      => $this->owner->id,
            'article_id'   => $articulo->id,
            'article_name' => $articulo->name,
            'orden'        => 1,
        ], $datos));
    }

    /**
     * B2: sin la clave de la IA (o con la validación apagada), la previa lo dice y el POST del
     * catálogo da 422 con un mensaje claro: sin IA todo terminaría "a revisar" pagando búsquedas.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function sin_ia_la_previa_lo_dice_y_el_catalogo_no_se_lanza()
    {
        $this->nuevo_articulo('Catálogo sin IA', null, ['online' => 1]);
        $this->actuar_como($this->owner, true);

        config(['services.anthropic.api_key' => '']);

        $previa = $this->getJson('api/image-assignment-runs/catalogo/previa')->assertStatus(200);
        $this->assertFalse($previa->json('ia_configurada'));
        $this->assertStringContainsString('ANTHROPIC_API_KEY', (string) $previa->json('ia_motivo'));

        Queue::fake();

        $post = $this->postJson('api/image-assignment-runs/catalogo')->assertStatus(422);
        $this->assertStringContainsString('validación con IA', (string) $post->json('message'));
        $this->assertSame(0, ImageAssignmentRun::where('user_id', $this->owner->id)->count());

        // Con la clave pero la validación apagada, lo mismo.
        config(['services.anthropic.api_key' => 'ANTHROPIC-DE-PRUEBA', 'services.article_image_validation.enabled' => false]);

        $previa = $this->getJson('api/image-assignment-runs/catalogo/previa')->assertStatus(200);
        $this->assertFalse($previa->json('ia_configurada'));
        $this->assertStringContainsString('apagada', (string) $previa->json('ia_motivo'));
        $this->postJson('api/image-assignment-runs/catalogo')->assertStatus(422);

        // Con todo en orden.
        config(['services.article_image_validation.enabled' => true]);

        $previa = $this->getJson('api/image-assignment-runs/catalogo/previa')->assertStatus(200);
        $this->assertTrue($previa->json('ia_configurada'));
        $this->assertNull($previa->json('ia_motivo'));
    }

    /**
     * B3: un artículo que ya espera su turno en otra asignación EN CURSO no entra al catálogo (y la
     * previa lo cuenta aparte).
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function el_catalogo_no_mete_articulos_que_esperan_turno_en_otra_asignacion()
    {
        $en_otra = $this->nuevo_articulo('Espera en otra', null, ['online' => 1]);
        $libre   = $this->nuevo_articulo('Libre', null, ['online' => 1]);

        $en_curso = $this->corrida(['status' => ImageAssignmentRun::STATUS_EN_PROCESO]);
        $this->item_de($en_curso, $en_otra, ['status' => ImageAssignmentItem::STATUS_PENDIENTE]);

        // Uno pendiente en una asignación que ya terminó (detenida) no cuenta: puede entrar.
        $viejo    = $this->nuevo_articulo('Pendiente en una detenida', null, ['online' => 1]);
        $detenida = $this->corrida(['status' => ImageAssignmentRun::STATUS_DETENIDA]);
        $this->item_de($detenida, $viejo, ['status' => ImageAssignmentItem::STATUS_PENDIENTE]);

        $this->actuar_como($this->owner, true);

        $previa = $this->getJson('api/image-assignment-runs/catalogo/previa')->assertStatus(200);
        $this->assertSame(1, $previa->json('excluidos_en_otra_asignacion'));

        Queue::fake();

        $this->postJson('api/image-assignment-runs/catalogo')->assertStatus(201);

        $catalogo = ImageAssignmentRun::where('user_id', $this->owner->id)->where('origen', ImageAssignmentRun::ORIGEN_CATALOGO)->first();
        $ids      = ImageAssignmentItem::where('run_id', $catalogo->id)->pluck('article_id')->map(function ($id) {
            return (int) $id;
        })->all();

        $this->assertContains((int) $libre->id, $ids);
        $this->assertContains((int) $viejo->id, $ids);
        $this->assertNotContains((int) $en_otra->id, $ids);
    }

    /**
     * B3: en una de catálogo, un artículo con una propuesta a revisar en OTRA asignación queda
     * `en_otra_asignacion`, sin buscar. En una de selección se busca igual.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function en_el_catalogo_un_articulo_con_una_propuesta_en_otra_asignacion_no_se_busca()
    {
        $articulo = $this->nuevo_articulo('Taladro con propuesta', self::CODIGO_REAL);

        $otra = $this->corrida([]);
        $this->item_de($otra, $articulo, ['status' => ImageAssignmentItem::STATUS_A_REVISAR, 'motivo' => 'confianza_media']);

        $catalogo = $this->asignacion([$articulo], ['origen' => ImageAssignmentRun::ORIGEN_CATALOGO]);

        $this->falsear(
            [self::CODIGO_REAL => [$this->resultado($this->url_imagen('taladro'), 900, 900, 1)]],
            [$this->url_imagen('taladro') => $this->png(900, 900, 'rojo')],
            ['rojo' => $this->veredicto('si', 'high')]
        );

        $item = $this->procesar($catalogo, $articulo);

        $this->assertSame(ImageAssignmentItem::STATUS_NO_ASIGNADA, $item->status);
        $this->assertSame('en_otra_asignacion', $item->motivo);
        // Texto cambiado a propósito en el pulido del 27/9/2026 (terminología): la corrida entera es
        // "la asignación" y "búsqueda" es cada consulta al buscador. Antes: "...en otra búsqueda".
        $this->assertStringStartsWith('Esperando revisión en otra asignación', (string) $item->motivo_detalle);
        $this->assertSame(0, (int) $item->busquedas);
        $this->assertSame([], $this->consultas_serper);

        // La de selección sí lo busca.
        $seleccion = $this->asignacion([$articulo]);

        $this->assertSame(ImageAssignmentItem::STATUS_ASIGNADA, $this->procesar($seleccion, $articulo)->status);
        $this->assertCount(1, $this->consultas_serper);
    }

    /**
     * B3: no se reanuda una de catálogo mientras hay OTRA de catálogo en curso.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function no_se_reanuda_una_de_catalogo_con_otra_de_catalogo_en_curso()
    {
        $detenida = $this->corrida(['origen' => ImageAssignmentRun::ORIGEN_CATALOGO, 'status' => ImageAssignmentRun::STATUS_DETENIDA]);
        $en_curso = $this->corrida(['origen' => ImageAssignmentRun::ORIGEN_CATALOGO, 'status' => ImageAssignmentRun::STATUS_EN_PROCESO]);

        Queue::fake();

        $resultado = ImageAssignmentRunHelper::reanudar($detenida);

        $this->assertSame(422, $resultado['status']);
        // Texto cambiado a propósito en el pulido del 27/9/2026 (terminología): antes decía "otra
        // búsqueda de todo el catálogo"; la corrida entera es "la asignación".
        $this->assertStringContainsString('otra asignación de todo el catálogo', (string) $resultado['message']);
        $this->assertSame(ImageAssignmentRun::STATUS_DETENIDA, $detenida->fresh()->status);
        Queue::assertNothingPushed();

        // Terminada la otra, se puede.
        $en_curso->update(['status' => ImageAssignmentRun::STATUS_TERMINADA]);

        $this->assertSame(200, ImageAssignmentRunHelper::reanudar($detenida)['status']);
    }

    /**
     * B7: las de selección (o del asistente) las detiene y reanuda cualquiera del comercio; las de
     * catálogo, solo el acceso maestro; las de otro comercio no existen.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function detener_y_reanudar_una_de_seleccion_no_pide_el_acceso_maestro()
    {
        Queue::fake();

        $seleccion = $this->corrida(['status' => ImageAssignmentRun::STATUS_EN_PROCESO]);
        $asistente = $this->corrida(['origen' => ImageAssignmentRun::ORIGEN_ASISTENTE, 'status' => ImageAssignmentRun::STATUS_EN_PROCESO]);
        $catalogo  = $this->corrida(['origen' => ImageAssignmentRun::ORIGEN_CATALOGO, 'status' => ImageAssignmentRun::STATUS_EN_PROCESO]);

        // Sin la sesión del acceso maestro.
        $this->postJson('api/image-assignment-runs/'.$seleccion->id.'/detener')->assertStatus(200)->assertJsonPath('model.status', 'detenida');
        $this->postJson('api/image-assignment-runs/'.$seleccion->id.'/reanudar')->assertStatus(200);
        $this->postJson('api/image-assignment-runs/'.$asistente->id.'/detener')->assertStatus(200);

        $this->postJson('api/image-assignment-runs/'.$catalogo->id.'/detener')->assertStatus(403);
        $this->postJson('api/image-assignment-runs/'.$catalogo->id.'/reanudar')->assertStatus(403);
        $this->assertSame(ImageAssignmentRun::STATUS_EN_PROCESO, $catalogo->fresh()->status);

        $this->assertStringNotContainsString('acceso maestro', (string) $asistente->fresh()->motivo_estado);

        // Una de otro comercio: 404, aunque sea de selección.
        $otro = User::create([
            'name'     => 'Otro comercio',
            'email'    => 'otro-b7-'.uniqid().'@test.local',
            'password' => Hash::make('secret'),
        ]);

        $ajena = $this->corrida(['user_id' => $otro->id, 'status' => ImageAssignmentRun::STATUS_EN_PROCESO]);

        $this->postJson('api/image-assignment-runs/'.$ajena->id.'/detener')->assertStatus(404);
        $this->assertSame(ImageAssignmentRun::STATUS_EN_PROCESO, $ajena->fresh()->status);
    }

    /**
     * B10: para una rechazada (o quitada), los 90 días se cuentan desde el rechazo: una propuesta de
     * hace 100 días que alguien rechazó hace 10 es un "no" de hace 10.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function los_90_dias_de_una_rechazada_se_cuentan_desde_el_rechazo()
    {
        $rechazada_hace_poco  = $this->nuevo_articulo('Rechazada hace poco', null, ['online' => 1]);
        $rechazada_hace_mucho = $this->nuevo_articulo('Rechazada hace mucho', null, ['online' => 1]);
        $quitada_hace_poco    = $this->nuevo_articulo('Quitada hace poco', null, ['online' => 1]);

        $vieja = $this->corrida([]);

        $this->item_de($vieja, $rechazada_hace_poco, ['status' => ImageAssignmentItem::STATUS_RECHAZADA, 'motivo' => 'rechazada', 'procesado_at' => Carbon::now()->subDays(100), 'revisado_at' => Carbon::now()->subDays(10)]);
        $this->item_de($vieja, $rechazada_hace_mucho, ['status' => ImageAssignmentItem::STATUS_RECHAZADA, 'motivo' => 'rechazada', 'procesado_at' => Carbon::now()->subDays(100), 'revisado_at' => Carbon::now()->subDays(95)]);
        $this->item_de($vieja, $quitada_hace_poco, ['status' => ImageAssignmentItem::STATUS_QUITADA, 'motivo' => 'quitada', 'procesado_at' => Carbon::now()->subDays(120), 'revisado_at' => Carbon::now()->subDays(3)]);

        $ids = ImageAssignmentRunHelper::ids_del_catalogo((int) $this->owner->id, 100);

        $this->assertNotContains((int) $rechazada_hace_poco->id, $ids, 'La rechazó hace 10 días: no se vuelve a buscar.');
        $this->assertNotContains((int) $quitada_hace_poco->id, $ids);
        $this->assertContains((int) $rechazada_hace_mucho->id, $ids, 'Pasaron los 90 días del rechazo.');
    }
}
