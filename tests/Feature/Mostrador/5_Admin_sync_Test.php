<?php

namespace Tests\Feature\Mostrador;

use App\Jobs\CalcularHechosMostradorJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\MostradorMemoria;
use App\Models\MostradorReporte;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;

/**
 * Misión modulo-ia-mostrador — P5: los endpoints admin-sync/mostrador/* que consume
 * la skill /mostrador.
 *
 * POST hechos crea la fila por la unique y la actualiza sin pisar el contenido que
 * depositó la skill; PUT reportes/{id} acepta un contenido válido y rechaza con 422
 * y errores legibles uno con un bloque desconocido, uno sin resumen primero y uno con
 * un tono inválido; GET contexto devuelve la memoria, los mensajes nuevos y los no
 * leídos; PUT memoria hace upsert; y con ADMIN_SYNC_REQUIRE_API_KEY el header manda.
 */
class Admin_sync_Test extends MostradorTestCase
{
    /** Ruta base del grupo. */
    const BASE = 'api/admin-sync/mostrador';

    /**
     * @group mostrador
     * @test
     */
    public function hechos_devuelve_404_sin_dueno_o_sin_extension_y_422_con_tipo_o_fecha_invalidos()
    {
        // Dueño sin la extensión.
        $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'dia'])
            ->assertStatus(404);

        // Dueño inexistente.
        $this->dar_extension();

        $this->postJson(self::BASE . '/hechos', ['user_id' => 999999999, 'tipo' => 'dia'])
            ->assertStatus(404);

        // Un empleado no es un dueño.
        $this->postJson(self::BASE . '/hechos', ['user_id' => $this->empleado()->id, 'tipo' => 'dia'])
            ->assertStatus(404);

        $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'ventas'])
            ->assertStatus(422);

        $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id])
            ->assertStatus(422);

        $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'dia', 'fecha' => '13/09/2026'])
            ->assertStatus(422);

        // Con forma de fecha pero inválida: 422, no 500.
        $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'dia', 'fecha' => '2026-13-45'])
            ->assertStatus(422);

        $this->assertSame(0, MostradorReporte::where('user_id', $this->comercio->id)->count());
    }

    /**
     * Dos POST del mismo informe a la vez: el segundo INSERT choca contra la unique
     * (user_id, tipo, fecha). Se simula metiendo la fila competidora en el `creating`
     * de la nuestra: en vez de un 500, se relee la que ganó y se guardan los hechos ahí.
     *
     * @group mostrador
     * @test
     */
    public function la_carrera_por_la_unique_relee_la_fila_en_vez_de_dar_500()
    {
        $this->dar_extension();

        $competidora = null;
        $comercio = $this->comercio;
        $fecha = $this->ayer->format('Y-m-d');

        MostradorReporte::creating(function ($reporte) use (&$competidora, $comercio, $fecha) {
            if (!is_null($competidora)) {
                return;
            }

            $competidora = 'creando';
            $competidora = MostradorReporte::create([
                'user_id' => $comercio->id,
                'tipo'    => 'dia',
                'fecha'   => $fecha,
                'estado'  => 'hechos',
            ]);
        });

        $respuesta = $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'dia']);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('reporte_id', $competidora->id);
        $respuesta->assertJsonPath('estado', 'hechos');
        $respuesta->assertJsonPath('hechos.aplica', true);

        $this->assertSame(1, MostradorReporte::where('user_id', $this->comercio->id)->where('tipo', 'dia')->count());
        $this->assertNotNull($competidora->fresh()->hechos_at);
    }

    /**
     * dia y tienda hablan de un día cerrado: hoy o más adelante es 422. compras y stock
     * hablan siempre de hoy: la fecha del body se ignora y la respuesta lo avisa.
     *
     * @group mostrador
     * @test
     */
    public function la_fecha_tiene_que_ser_un_dia_cerrado_para_dia_y_tienda_y_siempre_hoy_para_compras_y_stock()
    {
        $this->dar_extension();

        $hoy = now()->format('Y-m-d');

        $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'dia', 'fecha' => $hoy])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'El día tiene que estar cerrado: un informe de dia habla de ayer o de un día anterior, no de hoy.']);

        $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'tienda', 'fecha' => now()->addDays(3)->format('Y-m-d')])
            ->assertStatus(422);

        // Ayer y anteayer sí.
        $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'dia', 'fecha' => $this->ayer->copy()->subDay()->format('Y-m-d')])
            ->assertStatus(200)
            ->assertJsonPath('fecha', $this->ayer->copy()->subDay()->format('Y-m-d'))
            ->assertJsonPath('fecha_ignorada', false);

        // compras con otra fecha: es hoy igual, con el aviso.
        $respuesta = $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'compras', 'fecha' => $this->ayer->format('Y-m-d')]);
        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('fecha', $hoy);
        $respuesta->assertJsonPath('fecha_ignorada', true);

        // stock sin fecha, o con la de hoy: sin aviso.
        $this->sucursal('Única');

        $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'stock'])
            ->assertStatus(200)
            ->assertJsonPath('fecha', $hoy)
            ->assertJsonPath('fecha_ignorada', false);

        $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'stock', 'fecha' => $hoy])
            ->assertStatus(200)
            ->assertJsonPath('fecha_ignorada', false);

        $this->assertSame(0, MostradorReporte::where('user_id', $this->comercio->id)->where('fecha', '!=', $hoy)->where('tipo', 'compras')->count());
    }

    /**
     * Compras y stock por encima del umbral no se calculan en el request: la fila queda
     * en 'calculando', se despacha CalcularHechosMostradorJob y la respuesta es 202; un
     * POST repetido no despacha otro; el job deja 'hechos' y GET reportes/{id} los sirve.
     *
     * @group mostrador
     * @test
     */
    public function por_encima_del_umbral_compras_va_a_la_cola_y_se_consulta_por_polling()
    {
        $this->dar_extension();
        config(['mostrador.umbral_async' => 0]);

        // Un artículo bajo su mínimo: un candidato, que ya supera el umbral 0.
        $acme = $this->proveedor_nuevo('Acme');
        $lija = $this->articulo('Lija', ['stock' => 2, 'stock_min' => 10, 'provider_id' => $acme->id, 'cost' => 50]);
        $lija->providers()->attach($acme->id, ['cost' => 50]);

        Bus::fake();

        $respuesta = $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'compras']);

        $respuesta->assertStatus(202);
        $respuesta->assertJsonPath('estado', 'calculando');
        $respuesta->assertJsonPath('hechos', null);
        $respuesta->assertJsonPath('fecha', now()->format('Y-m-d'));
        $respuesta->assertJsonPath('error_mensaje', null);

        $reporte_id = $respuesta->json('reporte_id');

        Bus::assertDispatched(CalcularHechosMostradorJob::class, 1);
        $this->assertSame('calculando', MostradorReporte::find($reporte_id)->estado);

        // Mientras calcula: otro POST responde 202 sin despachar de nuevo, y el polling dice calculando.
        $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'compras'])
            ->assertStatus(202)
            ->assertJsonPath('reporte_id', $reporte_id);
        Bus::assertDispatched(CalcularHechosMostradorJob::class, 1);

        $this->getJson(self::BASE . '/reportes/' . $reporte_id)
            ->assertStatus(200)
            ->assertJsonPath('estado', 'calculando')
            ->assertJsonPath('hechos', null);

        // El job corre (acá a mano, como lo haría el worker).
        (new CalcularHechosMostradorJob($reporte_id))->handle();

        $poll = $this->getJson(self::BASE . '/reportes/' . $reporte_id);
        $poll->assertStatus(200);
        $poll->assertJsonPath('reporte_id', $reporte_id);
        $poll->assertJsonPath('tipo', 'compras');
        $poll->assertJsonPath('estado', 'hechos');
        $poll->assertJsonPath('hechos.aplica', true);
        $poll->assertJsonPath('hechos.por_proveedor.0.articulos.0.article_id', $lija->id);
        $poll->assertJsonPath('error_mensaje', null);
        $this->assertNotNull($poll->json('hechos_at'));

        // Volver a correr el job sobre una fila que ya no está calculando no hace nada.
        MostradorReporte::where('id', $reporte_id)->update(['hechos_at' => '2026-01-01 05:00:00']);
        (new CalcularHechosMostradorJob($reporte_id))->handle();
        $this->assertSame('2026-01-01 05:00:00', MostradorReporte::find($reporte_id)->hechos_at->toDateTimeString());

        // Por debajo del umbral (o para dia): sincrónico, 200.
        config(['mostrador.umbral_async' => 2000]);

        $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'compras', 'forzar' => true])
            ->assertStatus(200)
            ->assertJsonPath('estado', 'hechos')
            ->assertJsonPath('hechos.aplica', true);

        $this->getJson(self::BASE . '/reportes/999999999')->assertStatus(404);
    }

    /**
     * Si el job revienta, la fila queda en 'error' con el motivo para que el polling no
     * espere para siempre; una fila colgada en 'calculando' más allá del timeout se vuelve
     * a despachar; y un recálculo sobre un informe ya listo lo deja listo.
     *
     * @group mostrador
     * @test
     */
    public function el_job_deja_error_con_motivo_y_una_fila_colgada_se_vuelve_a_despachar()
    {
        $this->dar_extension();
        config(['mostrador.umbral_async' => 0]);
        $this->sucursal('Depósito');
        $this->sucursal('Norte');

        $tornillo = $this->articulo('Tornillo', ['stock' => 10]);
        $tornillo->addresses()->attach(\App\Models\Address::where('user_id', $this->comercio->id)->first()->id, ['amount' => 8, 'stock_min' => 2]);
        $tornillo->addresses()->attach(\App\Models\Address::where('user_id', $this->comercio->id)->orderByDesc('id')->first()->id, ['amount' => 2, 'stock_min' => 5]);

        Bus::fake();

        $reporte_id = $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'stock'])
            ->assertStatus(202)
            ->json('reporte_id');

        // El dueño desaparece antes de que corra el job: error con motivo.
        MostradorReporte::where('id', $reporte_id)->update(['user_id' => 999999999]);
        (new CalcularHechosMostradorJob($reporte_id))->handle();

        $reporte = MostradorReporte::find($reporte_id);
        $this->assertSame('error', $reporte->estado);
        $this->assertStringContainsString('ya no existe', $reporte->error_mensaje);

        $this->getJson(self::BASE . '/reportes/' . $reporte_id)
            ->assertStatus(200)
            ->assertJsonPath('estado', 'error')
            ->assertJsonPath('error_mensaje', $reporte->error_mensaje);

        // Vuelve a ser del comercio; el próximo POST recalcula (en error no se devuelve lo guardado).
        MostradorReporte::where('id', $reporte_id)->update(['user_id' => $this->comercio->id]);

        $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'stock'])
            ->assertStatus(202)
            ->assertJsonPath('reporte_id', $reporte_id)
            ->assertJsonPath('error_mensaje', null);
        Bus::assertDispatched(CalcularHechosMostradorJob::class, 2);

        // Colgada: 'calculando' desde hace más del timeout → se vuelve a despachar.
        MostradorReporte::where('id', $reporte_id)->update(['updated_at' => now()->subSeconds(config('mostrador.timeout_job') + 60)]);

        $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'stock'])->assertStatus(202);
        Bus::assertDispatched(CalcularHechosMostradorJob::class, 3);

        // Un informe ya listo, recalculado con forzar por la cola, vuelve a quedar listo.
        (new CalcularHechosMostradorJob($reporte_id))->handle();
        $this->assertSame('hechos', MostradorReporte::find($reporte_id)->estado);

        $this->putJson(self::BASE . '/reportes/' . $reporte_id, [
            'titulo' => 'Stock', 'resumen' => 'Un traslado.', 'contenido' => $this->contenido_valido(),
        ])->assertStatus(200);

        $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'stock', 'forzar' => true])
            ->assertStatus(202)
            ->assertJsonPath('estado', 'calculando');

        (new CalcularHechosMostradorJob($reporte_id))->handle();

        $reporte = MostradorReporte::find($reporte_id);
        $this->assertSame('listo', $reporte->estado);
        $this->assertSame('Stock', $reporte->titulo);
        $this->assertTrue($reporte->hechos['aplica']);
        $this->assertCount(1, $reporte->hechos['movimientos_sugeridos']);
    }

    /**
     * @group mostrador
     * @test
     */
    public function hechos_crea_la_fila_y_la_vuelve_a_calcular_sin_pisar_el_contenido()
    {
        $this->dar_extension();

        $respuesta = $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'dia']);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('tipo', 'dia');
        $respuesta->assertJsonPath('estado', 'hechos');
        $respuesta->assertJsonPath('fecha', $this->ayer->format('Y-m-d'));
        $respuesta->assertJsonPath('hechos.aplica', true);
        $respuesta->assertJsonPath('hechos.ventas.cantidad', 0);

        $reporte_id = $respuesta->json('reporte_id');

        $reporte = MostradorReporte::find($reporte_id);
        $this->assertNotNull($reporte);
        $this->assertNotNull($reporte->hechos_at);
        $this->assertNull($reporte->contenido);

        // Segunda corrida el mismo día: la misma fila (la unique), sin duplicar.
        $segunda = $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'dia', 'fecha' => $this->ayer->format('Y-m-d')]);
        $segunda->assertStatus(200);
        $this->assertSame($reporte_id, $segunda->json('reporte_id'));
        $this->assertSame(1, MostradorReporte::where('user_id', $this->comercio->id)->count());

        // La skill deposita el texto.
        $this->putJson(self::BASE . '/reportes/' . $reporte_id, [
            'titulo'    => 'Un jueves tranquilo',
            'resumen'   => 'Sin ventas, dos cobranzas pendientes.',
            'contenido' => $this->contenido_valido(),
        ])->assertStatus(200);

        // Ya listo y sin forzar: devuelve lo guardado sin recalcular.
        MostradorReporte::where('id', $reporte_id)->update(['hechos_at' => '2026-01-01 05:00:00']);

        $tercera = $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'dia']);
        $tercera->assertStatus(200);
        $tercera->assertJsonPath('estado', 'listo');
        $tercera->assertJsonPath('hechos.aplica', true);

        $reporte->refresh();
        $this->assertSame('2026-01-01 05:00:00', $reporte->hechos_at->toDateTimeString());
        $this->assertSame('Un jueves tranquilo', $reporte->titulo);

        // Con forzar recalcula los hechos y el contenido sigue intacto.
        $cuarta = $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'dia', 'forzar' => true]);
        $cuarta->assertStatus(200);
        $cuarta->assertJsonPath('estado', 'listo');

        $reporte->refresh();
        $this->assertNotSame('2026-01-01 05:00:00', $reporte->hechos_at->toDateTimeString());
        $this->assertSame('listo', $reporte->estado);
        $this->assertSame('Un jueves tranquilo', $reporte->titulo);
        $this->assertSame('resumen', $reporte->contenido['bloques'][0]['tipo']);
    }

    /**
     * @group mostrador
     * @test
     */
    public function hechos_de_stock_con_una_sucursal_guarda_aplica_false()
    {
        $this->dar_extension();
        $this->sucursal('Única');

        $respuesta = $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'stock']);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('hechos.aplica', false);
        $respuesta->assertJsonPath('fecha', now()->format('Y-m-d'));

        $this->assertSame('hechos', MostradorReporte::find($respuesta->json('reporte_id'))->estado);
    }

    /**
     * @group mostrador
     * @test
     */
    public function depositar_acepta_un_contenido_valido_y_deja_el_informe_listo()
    {
        $reporte = $this->reporte_con_hechos();

        $respuesta = $this->putJson(self::BASE . '/reportes/' . $reporte->id, [
            'titulo'    => 'Rendimiento de ayer',
            'resumen'   => 'Tres ventas y un pago.',
            'contenido' => $this->contenido_valido(),
        ]);

        $respuesta->assertStatus(200);
        $respuesta->assertExactJson(['ok' => true, 'reporte_id' => $reporte->id]);

        $reporte->refresh();
        $this->assertSame('listo', $reporte->estado);
        $this->assertNotNull($reporte->generado_at);
        $this->assertSame('Rendimiento de ayer', $reporte->titulo);
        $this->assertSame('Tres ventas y un pago.', $reporte->resumen);
        $this->assertCount(8, $reporte->contenido['bloques']);

        // También como string JSON (el script de la skill arma el body desde un archivo).
        $otro = $this->reporte_con_hechos('tienda');

        $this->putJson(self::BASE . '/reportes/' . $otro->id, [
            'titulo'    => 'Tu tienda',
            'resumen'   => 'Dos pedidos.',
            'contenido' => json_encode($this->contenido_valido()),
        ])->assertStatus(200);

        $this->assertSame('listo', $otro->fresh()->estado);
    }

    /**
     * @group mostrador
     * @test
     */
    public function depositar_rechaza_con_422_y_errores_legibles_lo_que_no_cumple_el_formato()
    {
        $reporte = $this->reporte_con_hechos();

        $this->putJson(self::BASE . '/reportes/999999999', [
            'titulo' => 'x', 'resumen' => 'x', 'contenido' => $this->contenido_valido(),
        ])->assertStatus(404);

        // Un bloque desconocido.
        $contenido = $this->contenido_valido();
        $contenido['bloques'][1] = ['tipo' => 'grafico', 'texto' => 'no existe'];

        $respuesta = $this->depositar($reporte, $contenido);
        $respuesta->assertStatus(422);
        $this->assertStringContainsString('bloques[1].tipo', implode("\n", $respuesta->json('errores')));

        // Sin resumen primero.
        $contenido = $this->contenido_valido();
        $contenido['bloques'][0] = ['tipo' => 'parrafo', 'texto' => 'Arranca con un párrafo.'];

        $respuesta = $this->depositar($reporte, $contenido);
        $respuesta->assertStatus(422);
        $this->assertStringContainsString('primer bloque tiene que ser "resumen"', implode("\n", $respuesta->json('errores')));

        // Un tono inválido.
        $contenido = $this->contenido_valido();
        $contenido['bloques'][1]['items'][0]['tono'] = 'verde';

        $respuesta = $this->depositar($reporte, $contenido);
        $respuesta->assertStatus(422);
        $this->assertStringContainsString('bloques[1].items[0].tono', implode("\n", $respuesta->json('errores')));

        // Una clave desconocida.
        $contenido = $this->contenido_valido();
        $contenido['bloques'][0]['html'] = '<b>no</b>';

        $respuesta = $this->depositar($reporte, $contenido);
        $respuesta->assertStatus(422);
        $this->assertStringContainsString('bloques[0].html: clave desconocida', implode("\n", $respuesta->json('errores')));

        // Una acción con tipo fuera de la lista y una imagen que no es http(s).
        $contenido = $this->contenido_valido();
        $contenido['bloques'][7]['items'][0]['tipo'] = 'vender';
        $contenido['bloques'][6]['items'][0]['imagen_url'] = 'javascript:alert(1)';

        $respuesta = $this->depositar($reporte, $contenido);
        $respuesta->assertStatus(422);
        $errores = implode("\n", $respuesta->json('errores'));
        $this->assertStringContainsString('bloques[7].items[0].tipo', $errores);
        $this->assertStringContainsString('bloques[6].items[0].imagen_url', $errores);

        // Versión distinta y demasiadas cifras.
        $contenido = $this->contenido_valido();
        $contenido['version'] = 2;
        $contenido['bloques'][1]['items'] = array_fill(0, 7, ['etiqueta' => 'x', 'valor' => '1']);

        $respuesta = $this->depositar($reporte, $contenido);
        $respuesta->assertStatus(422);
        $errores = implode("\n", $respuesta->json('errores'));
        $this->assertStringContainsString('contenido.version', $errores);
        $this->assertStringContainsString('bloques[1].items: tiene que tener entre 1 y 6', $errores);

        // Sin título.
        $this->putJson(self::BASE . '/reportes/' . $reporte->id, [
            'resumen' => 'x', 'contenido' => $this->contenido_valido(),
        ])->assertStatus(422);

        // Nada de eso dejó el informe listo.
        $this->assertSame('hechos', $reporte->fresh()->estado);
    }

    /**
     * lista.items ahora admite article_id/imagen_url opcionales (misión
     * mostrador-fotos-y-modales): un artículo válido se acepta y viaja tal cual; un id que
     * no es entero o una imagen que no es http(s) se rechazan con la ruta exacta. El resto
     * de las listas del sistema (que no hablan de artículos) sigue sin necesitar ninguna de
     * las dos claves.
     *
     * @group mostrador
     * @test
     */
    public function lista_acepta_article_id_e_imagen_url_opcionales_y_rechaza_lo_invalido()
    {
        $reporte = $this->reporte_con_hechos();

        // bloques[4] es el `lista` de contenido_valido().
        $contenido = $this->contenido_valido();
        $contenido['bloques'][4]['items'][0]['article_id'] = 7;
        $contenido['bloques'][4]['items'][0]['imagen_url'] = 'https://api.test/storage/articulo.webp';

        $this->depositar($reporte, $contenido)->assertStatus(200);
        $this->assertSame(7, $reporte->fresh()->contenido['bloques'][4]['items'][0]['article_id']);
        $this->assertSame('https://api.test/storage/articulo.webp', $reporte->fresh()->contenido['bloques'][4]['items'][0]['imagen_url']);

        // article_id que no es entero.
        $contenido = $this->contenido_valido();
        $contenido['bloques'][4]['items'][0]['article_id'] = '7';

        $respuesta = $this->depositar($reporte, $contenido);
        $respuesta->assertStatus(422);
        $this->assertStringContainsString('bloques[4].items[0].article_id', implode("\n", $respuesta->json('errores')));

        // imagen_url que no es http(s).
        $contenido = $this->contenido_valido();
        $contenido['bloques'][4]['items'][0]['imagen_url'] = 'javascript:alert(1)';

        $respuesta = $this->depositar($reporte, $contenido);
        $respuesta->assertStatus(422);
        $this->assertStringContainsString('bloques[4].items[0].imagen_url', implode("\n", $respuesta->json('errores')));
    }

    /**
     * Bloque nuevo `clientes` (misión mostrador-fotos-y-modales), hermano de `articulos`
     * para "clientes que más deben": client_id entero, nombre, y las opcionales
     * linea_1/linea_2/deuda/tono. Rechaza sin client_id entero, con más ítems que el tope,
     * y con una clave desconocida. Y, a propósito, acepta un client_id que no es de este
     * dueño: es de solo lectura (igual que articulos.article_id) y no pasa por
     * clientes_ajenos() — el que scopea por dueño es GET client/{id} al abrir el modal.
     *
     * @group mostrador
     * @test
     */
    public function bloque_clientes_acepta_lo_valido_y_rechaza_lo_que_no_cumple_el_formato()
    {
        $reporte = $this->reporte_con_hechos();

        $contenido = $this->contenido_valido();
        $contenido['bloques'][] = [
            'tipo'   => 'clientes',
            'titulo' => 'Quiénes más deben',
            'items'  => [
                ['client_id' => 5, 'nombre' => 'Distribuidora Norte', 'linea_1' => '41 días sin pagar', 'deuda' => '$ 412.000', 'tono' => 'alerta'],
            ],
        ];

        $this->depositar($reporte, $contenido)->assertStatus(200);
        $reporte->refresh();
        $this->assertSame('clientes', $reporte->contenido['bloques'][8]['tipo']);
        $this->assertSame(5, $reporte->contenido['bloques'][8]['items'][0]['client_id']);
        $this->assertSame('$ 412.000', $reporte->contenido['bloques'][8]['items'][0]['deuda']);

        // client_id que no es entero.
        $contenido = $this->contenido_valido();
        $contenido['bloques'][] = ['tipo' => 'clientes', 'items' => [['client_id' => '5', 'nombre' => 'Norte']]];

        $respuesta = $this->depositar($reporte, $contenido);
        $respuesta->assertStatus(422);
        $this->assertStringContainsString('bloques[8].items[0].client_id', implode("\n", $respuesta->json('errores')));

        // Más ítems que el tope (10).
        $contenido = $this->contenido_valido();
        $contenido['bloques'][] = [
            'tipo'  => 'clientes',
            'items' => array_fill(0, 11, ['client_id' => 1, 'nombre' => 'x']),
        ];

        $respuesta = $this->depositar($reporte, $contenido);
        $respuesta->assertStatus(422);
        $this->assertStringContainsString('bloques[8].items: tiene que tener entre 1 y 10', implode("\n", $respuesta->json('errores')));

        // Clave desconocida.
        $contenido = $this->contenido_valido();
        $contenido['bloques'][] = ['tipo' => 'clientes', 'items' => [['client_id' => 1, 'nombre' => 'x', 'telefono' => '123']]];

        $respuesta = $this->depositar($reporte, $contenido);
        $respuesta->assertStatus(422);
        $this->assertStringContainsString('bloques[8].items[0].telefono: clave desconocida', implode("\n", $respuesta->json('errores')));

        // Un client_id que no es de este dueño (ni existe): se acepta en el formato.
        $contenido = $this->contenido_valido();
        $contenido['bloques'][] = ['tipo' => 'clientes', 'items' => [['client_id' => 999999999, 'nombre' => 'Ajeno']]];

        $this->depositar($reporte, $contenido)->assertStatus(200);
    }

    /**
     * @group mostrador
     * @test
     */
    public function contexto_devuelve_memoria_mensajes_nuevos_y_no_leidos()
    {
        $this->dar_extension();

        $this->getJson(self::BASE . '/contexto/999999999')->assertStatus(404);

        $reporte = $this->reporte_con_hechos();
        $reporte->update([
            'titulo'      => 'Rendimiento de ayer',
            'resumen'     => 'Tres ventas.',
            'contenido'   => $this->contenido_valido(),
            'estado'      => 'listo',
            'generado_at' => now(),
        ]);

        $conversacion = AiConversation::create([
            'user_id'       => $this->comercio->id,
            'auth_user_id'  => $this->comercio->id,
            'titulo'        => 'Rendimiento de ayer · 13/09',
            'origen'        => 'mostrador_reporte',
            'referencia_id' => $reporte->id,
        ]);

        $pregunta = AiMessage::create(['ai_conversation_id' => $conversacion->id, 'rol' => 'user', 'contenido' => '¿Quién me debe más?', 'estado' => 'listo']);
        $respuesta_ia = AiMessage::create(['ai_conversation_id' => $conversacion->id, 'rol' => 'assistant', 'contenido' => 'Pérez, $900.', 'estado' => 'listo']);
        AiMessage::create(['ai_conversation_id' => $conversacion->id, 'rol' => 'assistant', 'contenido' => null, 'estado' => 'pendiente']);

        // Una conversación del chat común no entra.
        $otra = AiConversation::create(['user_id' => $this->comercio->id, 'auth_user_id' => $this->comercio->id]);
        AiMessage::create(['ai_conversation_id' => $otra->id, 'rol' => 'user', 'contenido' => 'hola', 'estado' => 'listo']);

        $respuesta = $this->getJson(self::BASE . '/contexto/' . $this->comercio->id);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('memoria', null);
        $respuesta->assertJsonCount(1, 'conversaciones_nuevas');
        $respuesta->assertJsonPath('conversaciones_nuevas.0.reporte_id', $reporte->id);
        $respuesta->assertJsonPath('conversaciones_nuevas.0.tipo', 'dia');
        $respuesta->assertJsonPath('conversaciones_nuevas.0.titulo', 'Rendimiento de ayer');
        $respuesta->assertJsonCount(2, 'conversaciones_nuevas.0.mensajes');
        $respuesta->assertJsonPath('conversaciones_nuevas.0.mensajes.0.id', $pregunta->id);
        $respuesta->assertJsonPath('conversaciones_nuevas.0.mensajes.0.rol', 'user');
        $respuesta->assertJsonPath('conversaciones_nuevas.0.mensajes.1.contenido', 'Pérez, $900.');
        $respuesta->assertJsonCount(1, 'no_leidos');
        $respuesta->assertJsonPath('no_leidos.0.reporte_id', $reporte->id);
        $respuesta->assertJsonPath('no_leidos.0.resumen', 'Tres ventas.');

        // La memoria mueve el "desde": solo lo posterior al último sintetizado.
        $this->putJson(self::BASE . '/memoria/' . $this->comercio->id, [
            'texto'               => 'Le importan las cobranzas.',
            'hasta_ai_message_id' => $pregunta->id,
        ])->assertStatus(200)->assertExactJson(['ok' => true]);

        $respuesta = $this->getJson(self::BASE . '/contexto/' . $this->comercio->id);
        $respuesta->assertJsonPath('memoria.texto', 'Le importan las cobranzas.');
        $respuesta->assertJsonPath('memoria.hasta_ai_message_id', $pregunta->id);
        $respuesta->assertJsonCount(1, 'conversaciones_nuevas.0.mensajes');
        $respuesta->assertJsonPath('conversaciones_nuevas.0.mensajes.0.id', $respuesta_ia->id);

        // El query param pisa la memoria.
        $this->getJson(self::BASE . '/contexto/' . $this->comercio->id . '?desde_ai_message_id=0')
            ->assertJsonCount(2, 'conversaciones_nuevas.0.mensajes');

        $this->getJson(self::BASE . '/contexto/' . $this->comercio->id . '?desde_ai_message_id=' . $respuesta_ia->id)
            ->assertJsonCount(0, 'conversaciones_nuevas');

        // Leído: deja de estar en no_leidos.
        $reporte->update(['leido_at' => now()]);

        $this->getJson(self::BASE . '/contexto/' . $this->comercio->id)->assertJsonCount(0, 'no_leidos');
    }

    /**
     * @group mostrador
     * @test
     */
    public function memoria_hace_upsert()
    {
        $this->putJson(self::BASE . '/memoria/999999999', ['texto' => 'x', 'hasta_ai_message_id' => 1])->assertStatus(404);

        $this->putJson(self::BASE . '/memoria/' . $this->comercio->id, ['texto' => ['no', 'es', 'texto'], 'hasta_ai_message_id' => 1])->assertStatus(422);
        $this->putJson(self::BASE . '/memoria/' . $this->comercio->id, ['texto' => 'x', 'hasta_ai_message_id' => 'doce'])->assertStatus(422);

        $this->putJson(self::BASE . '/memoria/' . $this->comercio->id, ['texto' => 'Primera síntesis.', 'hasta_ai_message_id' => 10])
            ->assertStatus(200);
        $this->putJson(self::BASE . '/memoria/' . $this->comercio->id, ['texto' => 'Segunda síntesis.', 'hasta_ai_message_id' => 25])
            ->assertStatus(200);

        $this->assertSame(1, MostradorMemoria::where('user_id', $this->comercio->id)->count());

        $memoria = MostradorMemoria::where('user_id', $this->comercio->id)->first();
        $this->assertSame('Segunda síntesis.', $memoria->texto);
        $this->assertSame(25, $memoria->hasta_ai_message_id);
    }

    /**
     * @group mostrador
     * @test
     */
    public function duenos_lista_solo_los_que_tienen_la_extension()
    {
        $this->getJson(self::BASE . '/duenos')->assertStatus(200)->assertJsonMissing(['user_id' => $this->comercio->id]);

        $this->dar_extension();
        $this->sucursal('Casa central');
        $this->sucursal('Norte');

        $respuesta = $this->getJson(self::BASE . '/duenos');
        $respuesta->assertStatus(200);

        $fila = collect($respuesta->json('duenos'))->firstWhere('user_id', $this->comercio->id);

        $this->assertNotNull($fila);
        $this->assertSame('Ferretería Mostrador', $fila['nombre']);
        $this->assertSame($this->comercio->email, $fila['email']);
        $this->assertFalse($fila['tiene_tienda']);
        $this->assertSame(2, $fila['sucursales']);
        $this->assertNull($fila['ultimo_reporte_at']);

        // Con USER_ID configurado, la instancia atiende a ese dueño y a nadie más.
        $otro = User::create(['name' => 'Otro dueño', 'email' => 'otro-' . uniqid() . '@test.local', 'password' => Hash::make('secret')]);
        $this->dar_extension($otro);

        config(['app.USER_ID' => $otro->id]);

        $ids = array_column($this->getJson(self::BASE . '/duenos')->json('duenos'), 'user_id');
        $this->assertSame([$otro->id], $ids);
    }

    /**
     * @group mostrador
     * @test
     */
    public function con_la_clave_exigida_el_header_manda()
    {
        config(['services.admin_api.require_api_key' => true]);
        config(['services.admin_api.api_key' => 'clave-de-prueba']);

        $this->getJson(self::BASE . '/duenos')->assertStatus(401);

        $this->getJson(self::BASE . '/duenos', ['X-Admin-Api-Key' => 'otra'])->assertStatus(401);

        $this->getJson(self::BASE . '/duenos', ['X-Admin-Api-Key' => 'clave-de-prueba'])->assertStatus(200);
    }

    /**
     * caja habla siempre de hoy, como compras y stock (misión mostrador-caja-vencimientos): la
     * fecha del body se ignora y la respuesta lo avisa. Un tipo que no existe sigue siendo 422,
     * con el mensaje que el motor de la skill reconoce contra un API sin el informe de caja.
     *
     * @group mostrador
     * @test
     */
    public function hechos_de_caja_hablan_de_hoy_aunque_el_body_traiga_otra_fecha()
    {
        $this->dar_extension();

        $hoy = now()->format('Y-m-d');

        \App\Models\Caja::create(['num' => 1, 'name' => 'Efectivo', 'user_id' => $this->comercio->id]);

        $respuesta = $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'caja', 'fecha' => $this->ayer->format('Y-m-d')]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('tipo', 'caja');
        $respuesta->assertJsonPath('fecha', $hoy);
        $respuesta->assertJsonPath('fecha_ignorada', true);
        $respuesta->assertJsonPath('estado', 'hechos');
        $respuesta->assertJsonPath('hechos.aplica', true);
        $respuesta->assertJsonPath('hechos.fecha', $hoy);
        $respuesta->assertJsonPath('hechos.horizonte_dias', 7);

        // Sin fecha: hoy, sin aviso, y la misma fila.
        $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'caja'])
            ->assertStatus(200)
            ->assertJsonPath('reporte_id', $respuesta->json('reporte_id'))
            ->assertJsonPath('fecha', $hoy)
            ->assertJsonPath('fecha_ignorada', false);

        $this->assertSame(1, MostradorReporte::where('user_id', $this->comercio->id)->where('tipo', 'caja')->count());

        $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'ventas'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'El tipo tiene que ser uno de: dia, caja, tienda, compras, stock.']);
    }

    /**
     * El client_id de una acción "cobrar" es el cliente al que el botón del informe le ofrece
     * mandar el recordatorio de cobro: tiene que ser un cliente del dueño del informe. Uno de otro
     * dueño o uno borrado es 422 nombrando el id; en una acción que no es de cobrar, o si no es un
     * entero, 422 por el formato.
     *
     * @group mostrador
     * @test
     */
    public function depositar_acepta_el_client_id_de_un_cliente_del_dueno_y_rechaza_los_ajenos()
    {
        $reporte = $this->reporte_con_hechos('caja');

        $perez = $this->cliente('Pérez');

        $borrado = $this->cliente('Cliente borrado');
        $borrado->delete();

        $otro = User::create(['name' => 'Otro dueño', 'email' => 'otro-' . uniqid() . '@test.local', 'password' => Hash::make('secret')]);
        $ajeno = \App\Models\Client::create(['name' => 'Cliente de otro', 'user_id' => $otro->id]);

        // De otro dueño.
        $respuesta = $this->depositar($reporte, $this->con_accion_de_cliente($ajeno->id));
        $respuesta->assertStatus(422);
        $this->assertStringContainsString(
            'acciones.client_id: ' . $ajeno->id . ' no es un cliente de este negocio',
            implode("\n", $respuesta->json('errores'))
        );

        // Del dueño, pero borrado.
        $respuesta = $this->depositar($reporte, $this->con_accion_de_cliente($borrado->id));
        $respuesta->assertStatus(422);
        $this->assertStringContainsString(
            'acciones.client_id: ' . $borrado->id . ' no es un cliente de este negocio',
            implode("\n", $respuesta->json('errores'))
        );

        // Del dueño, en una acción que no es de cobrar.
        $respuesta = $this->depositar($reporte, $this->con_accion_de_cliente($perez->id, 'revisar'));
        $respuesta->assertStatus(422);
        $this->assertStringContainsString(
            'bloques[7].items[1].client_id: solo va en acciones de tipo "cobrar"',
            implode("\n", $respuesta->json('errores'))
        );

        // Un id que no es un entero.
        $respuesta = $this->depositar($reporte, $this->con_accion_de_cliente((string) $perez->id));
        $respuesta->assertStatus(422);
        $this->assertStringContainsString(
            'bloques[7].items[1].client_id: tiene que ser un entero positivo',
            implode("\n", $respuesta->json('errores'))
        );

        // Nada de eso dejó el informe listo.
        $this->assertSame('hechos', $reporte->fresh()->estado);

        // Del dueño y en una acción de cobrar: se guarda con el client_id tal cual.
        $this->depositar($reporte, $this->con_accion_de_cliente($perez->id))->assertStatus(200);

        $reporte->refresh();
        $this->assertSame('listo', $reporte->estado);
        $this->assertSame($perez->id, $reporte->contenido['bloques'][7]['items'][1]['client_id']);
    }

    /**
     * El contenido válido con una acción más, que lleva client_id.
     *
     * @param mixed $client_id
     * @param string $tipo
     * @return array
     */
    protected function con_accion_de_cliente($client_id, $tipo = 'cobrar')
    {
        $contenido = $this->contenido_valido();

        $contenido['bloques'][7]['items'][] = [
            'texto'     => 'Mandarle el recordatorio de cobro',
            'tipo'      => $tipo,
            'client_id' => $client_id,
        ];

        return $contenido;
    }

    /**
     * Un informe con hechos (sin texto) del comercio del test.
     *
     * @param string $tipo
     * @return MostradorReporte
     */
    protected function reporte_con_hechos($tipo = 'dia')
    {
        return MostradorReporte::create([
            'user_id'   => $this->comercio->id,
            'tipo'      => $tipo,
            'fecha'     => $this->ayer->format('Y-m-d'),
            'hechos'    => ['aplica' => true, 'fecha' => $this->ayer->format('Y-m-d')],
            'hechos_at' => now(),
        ]);
    }

    /**
     * PUT del contenido con título y resumen válidos.
     *
     * @param MostradorReporte $reporte
     * @param array $contenido
     * @return \Illuminate\Testing\TestResponse
     */
    protected function depositar($reporte, array $contenido)
    {
        return $this->putJson(self::BASE . '/reportes/' . $reporte->id, [
            'titulo'    => 'Título',
            'resumen'   => 'Resumen.',
            'contenido' => $contenido,
        ]);
    }
}
