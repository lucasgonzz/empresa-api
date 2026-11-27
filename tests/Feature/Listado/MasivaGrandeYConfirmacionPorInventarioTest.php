<?php

namespace Tests\Feature\Listado;

use App\Http\Controllers\Helpers\masivas\EjecucionPorTandasHelper as Tandas;
use App\Models\MasiveUpdate;
use App\Models\Provider;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión masiva-sin-tope-confirmar-75 (27/11/2026).
 *
 * Una actualización masiva de artículos ya no se corta en 3000: el techo pasa a ser técnico
 * (50.000), el vencimiento de la ejecución por tandas escala con el tamaño y, cuando la pantalla
 * lo pide (`check_inventory_percentage`), una masiva que alcanza MÁS del 75% del inventario activo
 * del dueño exige confirmación (`confirmed_massive`) antes de encolarse.
 *
 * Cada test crea su propio dueño para controlar el inventario exacto (el porcentaje depende de él).
 * El worker no corre: se usa Queue::fake() y las tandas se ejecutan a mano avanzando el reloj.
 */
class MasivaGrandeYConfirmacionPorInventarioTest extends TestCase
{
    use DatabaseTransactions;

    /** Mensaje exacto del rechazo por superar el techo técnico de artículos. */
    const MENSAJE_TECHO_ARTICULOS = 'No se pudo actualizar: la selección alcanza 50.001 artículos y el máximo permitido '
        . 'por actualización masiva es 50.000. Acotá el filtro (por ejemplo por categoría, proveedor o rango de código) '
        . 'y repetí la acción por tandas.';

    /** @var \App\Models\User Dueño de la prueba en curso. */
    private $dueno;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-27 10:00:00'));
        $this->dueno = $this->nuevo_dueno();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Crea un dueño (owner_id nulo) sin artículos y lo deja autenticado.
     *
     * @return \App\Models\User
     */
    private function nuevo_dueno()
    {
        $dueno = User::create(['name' => 'zz dueno masiva grande', 'email' => uniqid('mg') . '@test.local', 'password' => 'prueba']);
        $this->actingAs($dueno, 'web');
        return $dueno;
    }

    /**
     * Inserta artículos del dueño por lotes de 1000 (nunca uno por uno) y devuelve sus ids en orden.
     *
     * @param int $cantidad
     * @param array $atributos Columnas que pisan las del artículo base (status, provider_id, deleted_at...).
     * @return int[]
     */
    private function articulos($cantidad, array $atributos = [])
    {
        $marca = bin2hex(random_bytes(4));
        $minimo_id = (int) DB::table('articles')->max('id');
        for ($desde = 0; $desde < $cantidad; $desde += 1000) {
            $lote = [];
            for ($i = $desde; $i < min($cantidad, $desde + 1000); $i++) {
                $lote[] = array_merge(['name' => 'zz grande ' . $marca . ' ' . $i, 'user_id' => $this->dueno->id,
                    'cost' => 100, 'percentage_gain' => 10, 'status' => 'active',
                    'created_at' => now(), 'updated_at' => now()], $atributos);
            }
            DB::table('articles')->insert($lote);
        }
        return array_map('intval', DB::table('articles')->where('user_id', $this->dueno->id)->where('id', '>', $minimo_id)
            ->where('name', 'like', 'zz grande ' . $marca . ' %')->orderBy('id')->pluck('id')->all());
    }

    /**
     * Crea un proveedor del dueño de la prueba.
     *
     * @param string $nombre
     * @return \App\Models\Provider
     */
    private function proveedor($nombre)
    {
        return Provider::create(['name' => 'zz ' . $nombre, 'user_id' => $this->dueno->id]);
    }

    /**
     * Formulario de actualización: costo +10%.
     *
     * @return array
     */
    private function update_form()
    {
        return [['type' => 'number', 'key' => 'increment_cost', 'value' => 10]];
    }

    /**
     * PUT de la pantalla con selección manual.
     *
     * @param int[] $ids
     * @param array $extra Claves extra del request (indicador y confirmación).
     * @return \Illuminate\Testing\TestResponse
     */
    private function actualizar_seleccion(array $ids, array $extra = [])
    {
        return $this->putJson('api/update/article', array_merge(['from_filter' => false, 'models_id' => $ids,
            'update_form' => $this->update_form()], $extra));
    }

    /**
     * PUT de la pantalla por filtro de proveedor.
     *
     * @param int $provider_id
     * @param array $extra
     * @return \Illuminate\Testing\TestResponse
     */
    private function actualizar_por_proveedor($provider_id, array $extra = [])
    {
        return $this->putJson('api/update/article', array_merge(['from_filter' => true,
            'filter_form' => [['key' => 'provider_id', 'type' => 'search', 'igual_que' => $provider_id]],
            'update_form' => $this->update_form()], $extra));
    }

    /**
     * Corre, una por una, las tandas de una masiva (50 artículos cada una) avanzando el reloj 5 s,
     * igual que el worker real respetando la pausa. Corta al llegar a un estado final.
     *
     * @param int $masiva_id
     * @param int $maximo_de_tandas Cota de seguridad del bucle.
     * @return int Tandas ejecutadas.
     */
    private function correr_tandas($masiva_id, $maximo_de_tandas)
    {
        $ejecutadas = 0;
        while ($ejecutadas < $maximo_de_tandas) {
            $masiva = MasiveUpdate::findOrFail($masiva_id);
            if (!in_array($masiva->status, ['pending', 'processing'], true)) {
                break;
            }
            $estado = Tandas::estado($masiva);
            Carbon::setTestNow(Carbon::createFromTimestamp(max(now()->timestamp, $estado['no_antes'])));
            Tandas::ejecutar($masiva_id, $estado['cursor'], $estado['generacion']);
            $ejecutadas++;
        }
        return $ejecutadas;
    }

    /**
     * Cantidad de actualizaciones masivas del dueño de la prueba.
     *
     * @return int
     */
    private function masivas_del_dueno()
    {
        return MasiveUpdate::where('user_id', $this->dueno->id)->count();
    }

    // ------------------------------------------------------------------ CA1, CA2, CA3: encolado

    public function test_seleccion_de_27580_se_encola_con_vencimiento_escalado()
    {
        $ids = $this->articulos(27580);
        $respuesta = $this->actualizar_seleccion($ids);
        $respuesta->assertStatus(200);
        $this->assertSame(27580, $respuesta->json('queued_count'));
        $criterio = json_decode(MasiveUpdate::findOrFail($respuesta->json('masive_update_id'))->criteria_json, true);
        $this->assertCount(27580, $criterio['resolved_models_id']);
        $this->assertCount(27580, array_unique($criterio['resolved_models_id']), 'La selección congelada no puede repetir ids.');
        $this->assertSame(49680, $criterio['tandas']['deadline'] - $criterio['tandas']['inicio'],
            '552 tandas × 90 s = 49.680 s de vencimiento.');
    }

    public function test_filtro_que_alcanza_27580_se_encola()
    {
        $proveedor = $this->proveedor('proveedor grande');
        $this->articulos(27580, ['provider_id' => $proveedor->id]);
        $respuesta = $this->actualizar_por_proveedor($proveedor->id);
        $respuesta->assertStatus(200);
        $this->assertSame(27580, $respuesta->json('queued_count'), 'El pluck de ids por filtro ya no puede cortar en 3000.');
    }

    public function test_bordes_2999_3000_3001_aceptan_y_50001_rechaza_con_mensaje()
    {
        $ids = $this->articulos(3001);
        foreach ([2999, 3000, 3001] as $cantidad) {
            $respuesta = $this->actualizar_seleccion(array_slice($ids, 0, $cantidad));
            $this->assertSame(200, $respuesta->getStatusCode(), $cantidad . ' artículos tienen que encolarse: ' . $respuesta->getContent());
            $this->assertSame($cantidad, $respuesta->json('queued_count'));
            // El dueño admite pocas pendientes a la vez: se cierra la anterior para probar el siguiente borde.
            MasiveUpdate::where('user_id', $this->dueno->id)->update(['status' => 'completed']);
        }
        $antes = $this->masivas_del_dueno();
        // 50.001 ids: el corte es antes de mirar a quién pertenecen, no hace falta que existan.
        $respuesta = $this->actualizar_seleccion(range(9000000, 9050000));
        $respuesta->assertStatus(422);
        $this->assertSame(self::MENSAJE_TECHO_ARTICULOS, $respuesta->json('message'));
        $this->assertSame($antes, $this->masivas_del_dueno(), 'El rechazo no crea ninguna actualización masiva.');
    }

    // ------------------------------------------------------------------ CA4, CA5: ejecución y reversión

    public function test_3001_se_ejecuta_completo_en_61_tandas()
    {
        $ids = $this->articulos(3001);
        $respuesta = $this->actualizar_seleccion($ids);
        $respuesta->assertStatus(200);
        $masiva_id = $respuesta->json('masive_update_id');
        $this->assertSame(61, $this->correr_tandas($masiva_id, 80));
        $masiva = MasiveUpdate::findOrFail($masiva_id);
        $this->assertSame('completed', $masiva->status, (string) $masiva->error_message);
        $this->assertSame(3001, (int) $masiva->affected_count);
        $this->assertSame(3001, DB::table('articles')->whereIn('id', $ids)->where('cost', 110)->count());
        $this->assertSame(3001, DB::table('masive_update_article')->where('masive_update_id', $masiva_id)->count());
    }

    public function test_snapshot_de_27580_pasa_la_validacion_entre_tandas()
    {
        $ids = $this->articulos(27580);
        $masiva_id = $this->actualizar_seleccion($ids)->json('masive_update_id');
        $this->assertNotNull($masiva_id);
        $this->assertSame(2, $this->correr_tandas($masiva_id, 2));
        $this->assertSame(100, Tandas::estado(MasiveUpdate::findOrFail($masiva_id))['cursor']);
        $this->assertSame(1, $this->correr_tandas($masiva_id, 1));
        $masiva = MasiveUpdate::findOrFail($masiva_id);
        $this->assertSame('processing', $masiva->status, (string) $masiva->error_message);
        $this->assertSame(150, Tandas::estado($masiva)['cursor'], 'La tanda 3 valida el checkpoint de 27.580 ids y avanza.');
    }

    public function test_reversion_de_3001_vuelve_todos_los_costos()
    {
        $ids = $this->articulos(3001);
        $padre = $this->actualizar_seleccion($ids);
        $padre->assertStatus(200);
        $padre_id = $padre->json('masive_update_id');
        $this->correr_tandas($padre_id, 80);
        $this->assertSame('completed', MasiveUpdate::findOrFail($padre_id)->status);
        $respuesta = $this->postJson('api/masive-update/' . $padre_id . '/revert');
        $respuesta->assertStatus(200);
        $reversion_id = $respuesta->json('masive_update_id');
        $this->assertSame(61, $this->correr_tandas($reversion_id, 80));
        $reversion = MasiveUpdate::findOrFail($reversion_id);
        $this->assertSame('completed', $reversion->status, (string) $reversion->error_message);
        $this->assertSame('reverted', MasiveUpdate::findOrFail($padre_id)->status);
        $this->assertSame(3001, DB::table('articles')->whereIn('id', $ids)->where('cost', 100)->count());
    }

    // ------------------------------------------------------------------ CA6: vencimiento escalado

    public function test_segundos_de_vencimiento_y_checkpoint_fuera_de_rango()
    {
        $this->assertSame(7200, Tandas::segundos_de_vencimiento(1));
        $this->assertSame(7200, Tandas::segundos_de_vencimiento(4000));
        $this->assertSame(7290, Tandas::segundos_de_vencimiento(4001));
        $this->assertSame(49680, Tandas::segundos_de_vencimiento(27580));
        $this->assertSame(90000, Tandas::segundos_de_vencimiento(50000));

        // Checkpoint de 27.580 ids con el vencimiento exacto: válido, la tanda avanza.
        $ids = range(1, 27580);
        $criterio = ['resolved_models_id' => $ids, 'used_filters' => [['key' => 'Seleccion manual']], 'update_form' => $this->update_form()];
        $valida = \App\Http\Controllers\Helpers\MasiveUpdateHelper::create_pending_update($this->dueno->id, $this->dueno->id, 'article', false, $criterio);
        $json = json_decode($valida->criteria_json, true);
        $this->assertSame(49680, $json['tandas']['deadline'] - $json['tandas']['inicio']);
        Tandas::ejecutar($valida->id, 0, 0);
        $this->assertSame('processing', $valida->fresh()->status, (string) $valida->fresh()->error_message);
        $this->assertSame(50, Tandas::estado($valida->fresh())['cursor']);
        MasiveUpdate::where('id', $valida->id)->update(['status' => 'completed']);

        // Un segundo de más sobre el vencimiento permitido: el checkpoint es inválido.
        $invalida = \App\Http\Controllers\Helpers\MasiveUpdateHelper::create_pending_update($this->dueno->id, $this->dueno->id, 'article', false, $criterio);
        $json = json_decode($invalida->criteria_json, true);
        $json['tandas']['deadline'] = $json['tandas']['inicio'] + 49681;
        $invalida->criteria_json = json_encode($json);
        $invalida->save();
        Tandas::ejecutar($invalida->id, 0, 0);
        $this->assertSame('failed', $invalida->fresh()->status);
        $this->assertStringContainsString('El snapshot o los límites del checkpoint son inválidos', (string) $invalida->fresh()->error_message);
    }

    // ------------------------------------------------------------------ CA7 a CA12: confirmación por inventario

    /**
     * Deja al dueño con 100 activos, 10 inactivos y 5 eliminados; devuelve los ids de los activos.
     *
     * @return int[]
     */
    private function inventario_de_100_activos_con_ruido()
    {
        $activos = $this->articulos(100);
        $this->articulos(10, ['status' => 'inactive']);
        $this->articulos(5, ['deleted_at' => now()]);
        return $activos;
    }

    public function test_mas_del_75_con_indicador_pide_confirmacion_y_no_encola()
    {
        $activos = $this->inventario_de_100_activos_con_ruido();
        $respuesta = $this->actualizar_seleccion(array_slice($activos, 0, 76), ['check_inventory_percentage' => true]);
        $respuesta->assertStatus(422);
        $this->assertTrue($respuesta->json('requires_confirmation'));
        $this->assertSame('confirmar_porcentaje_inventario', $respuesta->json('code'));
        $this->assertEqualsWithDelta(76.0, $respuesta->json('percentage'), 0.001,
            'El inventario son los activos no eliminados (100), no los 115 de la tabla.');
        $this->assertSame(76, $respuesta->json('count'));
        $this->assertSame(100, $respuesta->json('total'));
        $this->assertSame('Esta actualización afecta el 76% de tu inventario (76 de 100 artículos). ¿Confirmás que querés seguir?',
            $respuesta->json('message'));
        $this->assertSame(0, $this->masivas_del_dueno());
        Queue::assertNothingPushed();
    }

    public function test_75_exacto_no_pide_confirmacion()
    {
        $activos = $this->inventario_de_100_activos_con_ruido();
        $respuesta = $this->actualizar_seleccion(array_slice($activos, 0, 75), ['check_inventory_percentage' => true]);
        $respuesta->assertStatus(200);
        $this->assertSame(75, $respuesta->json('queued_count'));
    }

    public function test_confirmado_se_encola()
    {
        $activos = $this->inventario_de_100_activos_con_ruido();
        $respuesta = $this->actualizar_seleccion(array_slice($activos, 0, 76),
            ['check_inventory_percentage' => true, 'confirmed_massive' => true]);
        $respuesta->assertStatus(200);
        $this->assertSame(76, $respuesta->json('queued_count'));
        $this->assertSame(1, $this->masivas_del_dueno());
    }

    public function test_sin_indicador_no_pide_confirmacion()
    {
        $activos = $this->inventario_de_100_activos_con_ruido();
        // La pantalla vieja (y el hotfix sólo de API) no manda el indicador: 100 de 100 sigue sin preguntar.
        $respuesta = $this->actualizar_seleccion($activos);
        $respuesta->assertStatus(200);
        $this->assertSame(100, $respuesta->json('queued_count'));
    }

    public function test_por_filtro_pide_confirmacion_con_decimal()
    {
        // 80 de 100 activos alcanzados por el filtro de proveedor.
        $proveedor_a = $this->proveedor('proveedor A');
        $this->articulos(80, ['provider_id' => $proveedor_a->id]);
        $this->articulos(20);
        $respuesta = $this->actualizar_por_proveedor($proveedor_a->id, ['check_inventory_percentage' => true]);
        $respuesta->assertStatus(422);
        $this->assertTrue($respuesta->json('requires_confirmation'));
        $this->assertSame(80, $respuesta->json('count'));
        $this->assertSame(100, $respuesta->json('total'));
        $this->assertSame(0, $this->masivas_del_dueno());
    }

    public function test_por_filtro_informa_el_porcentaje_con_decimal()
    {
        // 93 de 110 activos: 84,545...% se informa con un decimal y coma.
        $proveedor_b = $this->proveedor('proveedor B');
        $this->articulos(93, ['provider_id' => $proveedor_b->id]);
        $this->articulos(17);
        $respuesta = $this->actualizar_por_proveedor($proveedor_b->id, ['check_inventory_percentage' => true]);
        $respuesta->assertStatus(422);
        $this->assertEqualsWithDelta(84.5, $respuesta->json('percentage'), 0.001);
        $this->assertSame('Esta actualización afecta el 84,5% de tu inventario (93 de 110 artículos). ¿Confirmás que querés seguir?',
            $respuesta->json('message'));
    }

    public function test_otro_modelo_no_pide_confirmacion()
    {
        // El indicador sólo se mira en artículos: dos proveedores de dos (100%) se encolan sin preguntar.
        $primero = $this->proveedor('uno');
        $segundo = $this->proveedor('dos');
        $respuesta = $this->putJson('api/update/provider', ['from_filter' => false, 'models_id' => [$primero->id, $segundo->id],
            'update_form' => [['type' => 'text', 'key' => 'observations', 'value' => 'zz masiva']], 'check_inventory_percentage' => true]);
        $respuesta->assertStatus(200);
        $this->assertSame(2, $respuesta->json('queued_count'));
    }

    public function test_otro_modelo_conserva_tope_de_3000_con_mensaje_claro()
    {
        $respuesta = $this->putJson('api/update/provider', ['from_filter' => false, 'models_id' => range(9000000, 9002999),
            'update_form' => [['type' => 'text', 'key' => 'observations', 'value' => 'zz masiva']]]);
        $respuesta->assertStatus(422);
        $this->assertSame('No se pudo actualizar: la selección alcanza 3.000 registros y el máximo permitido por actualización '
            . 'masiva es 2.999. Acotá el filtro (por ejemplo por categoría, proveedor o rango de código) y repetí la acción por tandas.',
            $respuesta->json('message'));
    }
}
