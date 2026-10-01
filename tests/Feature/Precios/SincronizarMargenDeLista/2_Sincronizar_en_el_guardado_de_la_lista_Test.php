<?php

namespace Tests\Feature\Precios\SincronizarMargenDeLista;

use App\Jobs\ProcessChunkSetFinalPrices;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * `PUT api/price-type/{id}` con la clave `sincronizar_margen` (misión
 * sincronizar-margen-lista-precios, 1/10/2026): el MISMO guardado de la lista sincroniza sus
 * artículos con el margen recién guardado, midiendo "coinciden" contra el margen que la lista
 * tenía guardado ANTES del request.
 *
 * Se prueba, sobre el escenario mezclado de SincronizarMargenTestCase:
 *  - `coinciden` después de cambiar 30 → 35 y el nombre: la lista se guarda entera y solo pasan a
 *    35 los de 30 y NULL sin precio fijado a mano; el resto intacto; recálculo encolado con
 *    exactamente esos ids; la notificación con N;
 *  - `todos` sin el tilde y con el tilde (setear_precio_final pasa a 0 solo con el tilde);
 *  - el invariante: el número del preview es exactamente la cantidad que se actualiza, en los
 *    tres casos;
 *  - margen vacío → pivots en NULL;
 *  - alcance inválido → la lista se guarda y no se sincroniza nada.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group sincronizar-margen-de-lista
 */
class Sincronizar_en_el_guardado_de_la_lista_Test extends SincronizarMargenTestCase
{
    /**
     * 30 → 35 y nombre nuevo, alcance `coinciden`.
     *
     * @return void
     */
    public function test_coinciden_guarda_la_lista_y_actualiza_solo_los_del_margen_actual()
    {
        $this->armar_escenario(30);

        $respuesta = $this->putJson('api/price-type/' . $this->lista->id, $this->payload($this->lista, [
            'name'               => 'zz Mayorista nueva',
            'percentage'         => '35',
            'sincronizar_margen' => ['alcance' => 'coinciden', 'incluir_precio_fijado_a_mano' => false],
        ]));

        $respuesta->assertStatus(200)
            ->assertJsonPath('notifications', [[
                'message' => 'Se actualizo el margen de 3 articulos. Los precios se recalculan en segundo plano.',
                'type'    => 'success',
            ]]);

        // La lista se guardó entera.
        $fila = DB::table('price_types')->where('id', $this->lista->id)->first();
        $this->assertSame('zz Mayorista nueva', $fila->name);
        $this->assertEquals('35.00', $fila->percentage);
        $this->assertSame('none', $fila->update_existing_articles_percentage_mode);

        // Los de 30 y NULL sin precio fijado a mano pasan a 35.
        foreach (['en_30', 'en_null', 'en_30_setear_null'] as $nombre) {
            $this->assertSame('35.00', $this->pivot($nombre, $this->lista->id)['percentage'], $nombre);
        }

        // El resto, intacto.
        $this->assertSame(['percentage' => '25.00', 'setear_precio_final' => 0], $this->pivot('en_25', $this->lista->id));
        $this->assertSame(['percentage' => '30.00', 'setear_precio_final' => 1], $this->pivot('fijado_en_30', $this->lista->id));
        $this->assertSame(['percentage' => '40.00', 'setear_precio_final' => 1], $this->pivot('fijado_en_40', $this->lista->id));
        $this->assertSame('30.00', $this->pivot('borrado', $this->lista->id)['percentage']);
        $this->assertSame('30.00', $this->pivot('en_30', $this->otra->id)['percentage']);
        $this->assertSame('30.00', $this->pivot('solo_en_la_otra', $this->otra->id)['percentage']);
        $this->assertSame('30.00', $this->pivot('de_otro_dueno', $this->lista_ajena->id)['percentage']);

        // Recálculo encolado con exactamente esos ids, a nombre del dueño.
        $this->assertSame($this->ids_de(['en_30', 'en_null', 'en_30_setear_null']), $this->ids_encolados());

        $dueno_id = (int) $this->dueno->id;

        Queue::assertPushed(ProcessChunkSetFinalPrices::class, function ($chunk) use ($dueno_id) {
            $propiedad = new \ReflectionProperty($chunk, 'user_id');
            $propiedad->setAccessible(true);

            return (int) $propiedad->getValue($chunk) === $dueno_id;
        });
    }

    /**
     * `todos` sin el tilde: todos menos los de precio fijado a mano, que quedan intactos.
     *
     * @return void
     */
    public function test_todos_sin_el_tilde_deja_afuera_los_de_precio_fijado_a_mano()
    {
        $this->armar_escenario(30);

        $this->putJson('api/price-type/' . $this->lista->id, $this->payload($this->lista, [
            'percentage'         => '35',
            'sincronizar_margen' => ['alcance' => 'todos', 'incluir_precio_fijado_a_mano' => false],
        ]))->assertStatus(200)
            ->assertJsonPath('notifications.0.message', 'Se actualizo el margen de 4 articulos. Los precios se recalculan en segundo plano.');

        foreach (['en_30', 'en_null', 'en_30_setear_null', 'en_25'] as $nombre) {
            $this->assertSame('35.00', $this->pivot($nombre, $this->lista->id)['percentage'], $nombre);
        }

        $this->assertSame(['percentage' => '30.00', 'setear_precio_final' => 1], $this->pivot('fijado_en_30', $this->lista->id));
        $this->assertSame(['percentage' => '40.00', 'setear_precio_final' => 1], $this->pivot('fijado_en_40', $this->lista->id));
        $this->assertSame('30.00', $this->pivot('borrado', $this->lista->id)['percentage']);
        $this->assertSame('30.00', $this->pivot('en_30', $this->otra->id)['percentage']);
        $this->assertSame('30.00', $this->pivot('de_otro_dueno', $this->lista_ajena->id)['percentage']);

        $this->assertSame($this->ids_de(['en_30', 'en_null', 'en_30_setear_null', 'en_25']), $this->ids_encolados());
    }

    /**
     * `todos` con el tilde: también los de precio fijado a mano, que pierden el precio fijo.
     *
     * @return void
     */
    public function test_todos_con_el_tilde_les_saca_el_precio_fijo_y_les_aplica_el_margen()
    {
        $this->armar_escenario(30);

        $this->putJson('api/price-type/' . $this->lista->id, $this->payload($this->lista, [
            'percentage'         => '35',
            'sincronizar_margen' => ['alcance' => 'todos', 'incluir_precio_fijado_a_mano' => true],
        ]))->assertStatus(200)
            ->assertJsonPath('notifications.0.message', 'Se actualizo el margen de 6 articulos. Los precios se recalculan en segundo plano.');

        $this->assertSame(['percentage' => '35.00', 'setear_precio_final' => 0], $this->pivot('fijado_en_30', $this->lista->id));
        $this->assertSame(['percentage' => '35.00', 'setear_precio_final' => 0], $this->pivot('fijado_en_40', $this->lista->id));
        $this->assertSame(['percentage' => '35.00', 'setear_precio_final' => 0], $this->pivot('en_25', $this->lista->id));

        // Un setear_precio_final NULL no se reescribe: solo los que estaban en 1.
        $this->assertSame(['percentage' => '35.00', 'setear_precio_final' => null], $this->pivot('en_30_setear_null', $this->lista->id));

        $this->assertSame('30.00', $this->pivot('borrado', $this->lista->id)['percentage']);
        $this->assertSame('30.00', $this->pivot('en_30', $this->otra->id)['percentage']);

        $this->assertSame(
            $this->ids_de(['en_30', 'en_null', 'en_30_setear_null', 'en_25', 'fijado_en_30', 'fijado_en_40']),
            $this->ids_encolados()
        );
    }

    /**
     * El tilde no aplica a `coinciden`: aunque venga en true, los de precio fijado a mano no se
     * tocan.
     *
     * @return void
     */
    public function test_el_tilde_no_aplica_a_coinciden()
    {
        $this->armar_escenario(30);

        $this->putJson('api/price-type/' . $this->lista->id, $this->payload($this->lista, [
            'percentage'         => '35',
            'sincronizar_margen' => ['alcance' => 'coinciden', 'incluir_precio_fijado_a_mano' => true],
        ]))->assertStatus(200);

        $this->assertSame(['percentage' => '30.00', 'setear_precio_final' => 1], $this->pivot('fijado_en_30', $this->lista->id));
        $this->assertSame($this->ids_de(['en_30', 'en_null', 'en_30_setear_null']), $this->ids_encolados());
    }

    /**
     * Los tres casos del invariante: alcance, tilde y qué número del preview tiene que coincidir.
     *
     * @return array
     */
    public function casos_del_invariante()
    {
        return [
            'coinciden'         => ['coinciden', false, function ($preview) { return $preview['coinciden_con_el_actual']; }],
            'todos sin el tilde' => ['todos', false, function ($preview) { return $preview['total_articulos'] - $preview['con_precio_fijado_a_mano']; }],
            'todos con el tilde' => ['todos', true, function ($preview) { return $preview['total_articulos']; }],
        ];
    }

    /**
     * 🔴 El número que ve el dueño en el preview es EXACTAMENTE la cantidad de artículos que la
     * sincronización actualiza (y manda a recalcular), con el mismo estado de base.
     *
     * @dataProvider casos_del_invariante
     *
     * @param  string   $alcance
     * @param  bool     $incluir
     * @param  \Closure $esperado
     * @return void
     */
    public function test_el_preview_dice_exactamente_cuantos_se_actualizan($alcance, $incluir, $esperado)
    {
        $this->armar_escenario(30);

        // Ruido que el invariante tiene que aguantar: la lista atada dos veces al mismo artículo,
        // una de ellas con precio fijado a mano.
        $this->atar($this->art['en_25'], $this->lista->id, '30.00', 1, 700);

        $preview = $this->pedir_preview($this->lista->id)->assertStatus(200)->json();

        $antes = $this->foto_de_pivots();

        $respuesta = $this->putJson('api/price-type/' . $this->lista->id, $this->payload($this->lista, [
            'percentage'         => '35',
            'sincronizar_margen' => ['alcance' => $alcance, 'incluir_precio_fijado_a_mano' => $incluir],
        ]))->assertStatus(200);

        $cantidad_esperada = $esperado($preview);

        $this->assertGreaterThan(0, $cantidad_esperada, 'El caso no actualiza nada: no prueba el invariante.');

        $this->assertCount($cantidad_esperada, $this->ids_encolados(), 'El preview no dice cuántos se mandan a recalcular.');

        // Los artículos cuyo pivot de esta lista cambió.
        $cambiados = [];
        $despues = $this->foto_de_pivots();
        foreach ($antes as $i => $fila) {
            if ($fila != $despues[$i]) {
                $this->assertEquals($this->lista->id, $fila['price_type_id'], 'Cambió una fila de otra lista.');
                $cambiados[(int) $fila['article_id']] = true;
            }
        }

        $ids_cambiados = array_keys($cambiados);
        sort($ids_cambiados);

        $this->assertSame($this->ids_encolados(), $ids_cambiados, 'Lo que se recalcula no es lo que se actualizó.');

        $mensaje = $cantidad_esperada == 1 ? '1 articulo' : $cantidad_esperada . ' articulos';
        $respuesta->assertJsonPath('notifications.0.message', 'Se actualizo el margen de ' . $mensaje . '. Los precios se recalculan en segundo plano.');
    }

    /**
     * Margen vacío: los pivots quedan en NULL (mismo criterio que el helper viejo).
     *
     * @return void
     */
    public function test_margen_vacio_deja_los_pivots_en_null()
    {
        $this->armar_escenario(30);

        $this->putJson('api/price-type/' . $this->lista->id, $this->payload($this->lista, [
            'percentage'         => null,
            'sincronizar_margen' => ['alcance' => 'coinciden', 'incluir_precio_fijado_a_mano' => false],
        ]))->assertStatus(200);

        $this->assertNull(DB::table('price_types')->where('id', $this->lista->id)->value('percentage'));

        foreach (['en_30', 'en_null', 'en_30_setear_null'] as $nombre) {
            $this->assertNull($this->pivot($nombre, $this->lista->id)['percentage'], $nombre);
        }

        $this->assertSame('25.00', $this->pivot('en_25', $this->lista->id)['percentage']);
        $this->assertSame($this->ids_de(['en_30', 'en_null', 'en_30_setear_null']), $this->ids_encolados());
    }

    /**
     * Ningún artículo para actualizar: la notificación lo dice, tipo info, y no se encola nada.
     *
     * @return void
     */
    public function test_sin_articulos_para_actualizar_avisa_y_no_encola()
    {
        $this->armar_escenario(30);

        // Lista sin margen guardado → "coinciden" son solo los NULL; se los saca.
        DB::table('price_types')->where('id', $this->lista->id)->update(['percentage' => null]);
        DB::table('article_price_type')->where('price_type_id', $this->lista->id)->whereNull('percentage')->delete();

        $this->putJson('api/price-type/' . $this->lista->id, $this->payload($this->lista, [
            'percentage'         => '35',
            'sincronizar_margen' => ['alcance' => 'coinciden', 'incluir_precio_fijado_a_mano' => false],
        ]))->assertStatus(200)
            ->assertJsonPath('notifications', [[
                'message' => 'No habia articulos para actualizar con ese margen.',
                'type'    => 'info',
            ]]);

        Queue::assertNotPushed(ProcessChunkSetFinalPrices::class);
    }

    /**
     * Alcance inválido: la lista se guarda igual y no se toca ningún artículo.
     *
     * @return void
     */
    public function test_alcance_invalido_guarda_la_lista_y_no_sincroniza()
    {
        $this->armar_escenario(30);

        $antes = $this->foto_de_pivots();

        $this->putJson('api/price-type/' . $this->lista->id, $this->payload($this->lista, [
            'name'               => 'zz Mayorista renombrada',
            'percentage'         => '35',
            'sincronizar_margen' => ['alcance' => 'cualquiera', 'incluir_precio_fijado_a_mano' => true],
        ]))->assertStatus(200)
            ->assertJsonPath('notifications', []);

        $fila = DB::table('price_types')->where('id', $this->lista->id)->first();
        $this->assertSame('zz Mayorista renombrada', $fila->name);
        $this->assertEquals('35.00', $fila->percentage);

        $this->assertEquals($antes, $this->foto_de_pivots());
        Queue::assertNotPushed(ProcessChunkSetFinalPrices::class);
    }
}
