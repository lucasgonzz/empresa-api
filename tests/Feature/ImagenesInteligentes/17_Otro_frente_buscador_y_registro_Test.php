<?php

namespace Tests\Feature\ImagenesInteligentes;

use App\Events\ArticleBatchImagesProcessed;
use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\ImageAssignmentRunHelper;
use App\Jobs\ProcessImageAssignmentRunJob;
use App\Models\Article;
use App\Models\BackgroundProcess;
use App\Models\Image;
use App\Models\ImageAssignmentItem;
use App\Models\ImageAssignmentRun;
use App\Models\ImageServiceCall;
use App\Models\User;
use App\Services\ImageAssignment\ImageServiceCallLogger;
use Carbon\Carbon;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Lo que queda de la sexta pasada (re-verificador independiente, 27/9/2026):
 *
 *   - B1: aprobar-varios trae del otro frente como mucho 20 candidatas por pedido (Hostinger bloquea
 *     la IP con ~100 pedidos seguidos); el resto vuelve en `fallidos` para reintentar y sigue a
 *     revisar. La copia que queda en el otro frente se anota en el log.
 *   - Orden en aprobar: el artículo se mira ANTES de ir al otro frente (un artículo borrado termina
 *     no_asignada / articulo_borrado, contrato §5.3).
 *   - S2: un error de conexión del buscador se muestra legible (diagnóstico, motivo_detalle, motivo
 *     de la asignación y registro visible); el detalle va al log y al registro de consultas del admin.
 *   - Retención: registrar() también purga lo de más de 180 días del dueño, una vez por día.
 */
class Otro_frente_buscador_y_registro_Test extends ImagenesInteligentesTestCase
{
    /** El storage del OTRO frente del mismo cliente (cada frente tiene el suyo). */
    const OTRO_FRENTE = 'https://api-ferreteria2.comerciocity.com/storage/';

    /**
     * Una asignación con N artículos "a revisar" cuyas candidatas quedaron en el otro frente.
     *
     * @param  int $cantidad
     * @return array  Los items.
     */
    protected function a_revisar_en_el_otro_frente($cantidad)
    {
        $articulos = [];

        for ($i = 1; $i <= $cantidad; $i++) {
            $articulos[] = $this->nuevo_articulo('Artículo del otro frente '.$i, null);
        }

        $run   = $this->asignacion($articulos);
        $items = [];

        foreach (ImageAssignmentItem::where('run_id', $run->id)->orderBy('orden')->get() as $item) {
            $archivo = ImageAssignmentItem::PREFIJO_CANDIDATA.(string) Str::uuid().'.webp';

            $item->update([
                'status'         => ImageAssignmentItem::STATUS_A_REVISAR,
                'motivo'         => 'confianza_media',
                'imagen_archivo' => $archivo,
                'imagen_url'     => self::OTRO_FRENTE.$archivo,
                'imagen_meta'    => ['ancho' => 800, 'alto' => 800, 'fondo_blanco' => true, 'fondo_blanco_ratio' => 0.97, 'avisos' => ['La IA lo reconoce con confianza media']],
            ]);

            $items[] = $item->fresh();
        }

        return $items;
    }

    /**
     * Un Http::fake que sirve estos archivos (y 404 para todo lo demás).
     *
     * @param  array $urls  url => binario
     * @return void
     */
    protected function servir(array $urls)
    {
        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));

        Http::fake(function ($request) use ($urls) {
            if (isset($urls[$request->url()])) {
                return Http::response($urls[$request->url()], 200, ['Content-Type' => 'image/webp']);
            }

            return Http::response('no', 404, ['Content-Type' => 'text/plain']);
        });
    }

    /**
     * B1: 22 candidatas en el otro frente, aprobadas de una vez: se traen 20 (20 pedidos al otro
     * frente, no 22) y las 2 que pasan el tope vuelven en `fallidos` con "Reintentá…", sin tocar el
     * item. Reintentadas en otro pedido, salen. Cada copia que queda en el otro frente, al log.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function aprobar_varios_trae_del_otro_frente_como_mucho_veinte_por_pedido()
    {
        $items = $this->a_revisar_en_el_otro_frente(22);
        $webp  = $this->webp(800, 800, 'verde');
        $urls  = [];

        foreach ($items as $item) {
            $urls[$item->imagen_url] = $webp;
        }

        $this->servir($urls);

        Log::spy();

        $ids = [];

        foreach ($items as $item) {
            $ids[] = $item->id;
        }

        $respuesta = $this->postJson('api/image-assignment-items/aprobar-varios', ['ids' => $ids])->assertStatus(200);

        $this->assertSame(20, $respuesta->json('aprobados'));
        $this->assertCount(2, $respuesta->json('fallidos'));
        $this->assertSame(20, $this->requests_a(self::OTRO_FRENTE), 'Veinte pedidos al otro frente, no veintidós.');

        $fallidos = [];

        foreach ($respuesta->json('fallidos') as $fallido) {
            $this->assertSame('Reintentá: la imagen está en el otro servidor.', $fallido['message']);
            $fallidos[] = (int) $fallido['id'];
        }

        // Las dos del final, intactas: siguen a revisar, apuntando a la misma candidata.
        $this->assertSame([(int) $items[20]->id, (int) $items[21]->id], $fallidos);

        foreach ([$items[20], $items[21]] as $item) {
            $fresco = $item->fresh();

            $this->assertSame(ImageAssignmentItem::STATUS_A_REVISAR, $fresco->status);
            $this->assertSame($item->imagen_archivo, $fresco->imagen_archivo);
            $this->assertNull($fresco->revisado_at);
        }

        Log::shouldHaveReceived('info')
            ->with('[ImagenesInteligentes] Se trajo la candidata del otro frente: allá queda una copia huérfana.', \Mockery::on(function ($contexto) {
                return isset($contexto['item_id'], $contexto['candidata'], $contexto['url']);
            }))
            ->times(20);

        // Reintentadas en otro pedido, salen.
        $otra_vez = $this->postJson('api/image-assignment-items/aprobar-varios', ['ids' => $fallidos])->assertStatus(200);

        $this->assertSame(2, $otra_vez->json('aprobados'));
        $this->assertSame(22, ImageAssignmentItem::whereIn('id', $ids)->where('status', ImageAssignmentItem::STATUS_APROBADA)->count());
    }

    /**
     * Orden en aprobar: con el artículo borrado, no se va a buscar la candidata al otro frente; el
     * item termina no_asignada / articulo_borrado (§5.3), también en un aprobar-varios (y ahí no gasta
     * el tope de descargas).
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function con_el_articulo_borrado_no_se_va_a_buscar_la_candidata_al_otro_frente()
    {
        $items = $this->a_revisar_en_el_otro_frente(2);

        $this->servir([
            $items[0]->imagen_url => $this->webp(800, 800),
            $items[1]->imagen_url => $this->webp(800, 800),
        ]);

        Article::find($items[0]->article_id)->delete();
        Article::find($items[1]->article_id)->delete();

        $this->postJson('api/image-assignment-items/'.$items[0]->id.'/aprobar')
            ->assertStatus(422)
            ->assertJsonPath('message', 'El artículo ya no existe: no se puede aprobar su imagen.');

        $primero = $items[0]->fresh();
        $this->assertSame(ImageAssignmentItem::STATUS_NO_ASIGNADA, $primero->status);
        $this->assertSame('articulo_borrado', $primero->motivo);
        $this->assertNull($primero->imagen_archivo);
        $this->assertNotNull($primero->revisado_at);

        $lote = $this->postJson('api/image-assignment-items/aprobar-varios', ['ids' => [$items[1]->id]])->assertStatus(200);

        $this->assertSame(0, $lote->json('aprobados'));
        $this->assertSame('El artículo ya no existe: no se puede aprobar su imagen.', $lote->json('fallidos.0.message'));
        $this->assertSame('articulo_borrado', $items[1]->fresh()->motivo);

        $this->assertSame(0, $this->requests_a(self::OTRO_FRENTE), 'Ni un pedido al otro frente.');
        $this->assertSame(0, Image::whereIn('imageable_id', [$items[0]->article_id, $items[1]->article_id])->where('imageable_type', 'article')->count());
    }

    /**
     * S2: un error de CONEXIÓN de Serper se muestra legible en el diagnóstico, en el motivo_detalle,
     * en el motivo de la asignación y en el registro visible; el detalle técnico (sin claves) queda
     * en el registro de consultas del admin. Uno que no es timeout dice "No se pudo conectar".
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function un_error_de_conexion_del_buscador_se_muestra_legible()
    {
        Event::fake([ArticleBatchImagesProcessed::class, BackgroundProcessUpdated::class]);

        $articulos = [];

        for ($i = 1; $i <= 5; $i++) {
            $articulos[] = $this->nuevo_articulo('Escalera tijera '.$i, $this->con_verificador('77900097000'.$i));
        }

        $run = $this->asignacion($articulos);

        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
        Http::fake(function ($request) {
            if (strpos($request->url(), 'google.serper.dev') !== false) {
                throw new ConnectException('cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received for '.$request->url(), $request->toPsrRequest());
            }

            return Http::response('no', 404);
        });

        Queue::fake();

        (new ProcessImageAssignmentRunJob($run->id))->handle();

        $run->refresh();
        $item = ImageAssignmentItem::where('run_id', $run->id)->orderBy('orden')->first();

        $a_la_vista = json_encode($item->diagnostico, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            .' '.$item->motivo_detalle
            .' '.$run->motivo_estado
            .' '.BackgroundProcess::find($run->background_process_id)->error_message;

        $this->assertSame(ImageAssignmentRun::STATUS_FALLIDA, $run->status);
        $this->assertSame('error_de_busqueda', $item->motivo);
        $this->assertStringContainsString('El buscador no respondió a tiempo', $a_la_vista);
        $this->assertStringContainsString('El proveedor de búsqueda falló en 5 artículos seguidos (El buscador no respondió a tiempo).', (string) $run->motivo_estado);
        $this->assertStringNotContainsString('cURL', $a_la_vista);
        $this->assertStringNotContainsString('google.serper.dev', $a_la_vista);

        // El registro de consultas (lo mira el admin) conserva el detalle técnico, sin la clave.
        $registro = ImageServiceCall::where('run_id', $run->id)->where('tipo', ImageServiceCall::TIPO_BUSQUEDA)->first();
        $this->assertStringContainsString('cURL error 28', (string) $registro->error);
        $this->assertStringNotContainsString('SERPER-DE-PRUEBA', (string) $registro->error);

        // Un error de conexión que no es un timeout.
        $otro     = $this->nuevo_articulo('Escalera de aluminio', $this->con_verificador('779000980001'));
        $otro_run = $this->asignacion([$otro]);

        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
        Http::fake(function ($request) {
            if (strpos($request->url(), 'google.serper.dev') !== false) {
                throw new ConnectException('cURL error 6: Could not resolve host: google.serper.dev', $request->toPsrRequest());
            }

            return Http::response('no', 404);
        });

        $otro_item = $this->procesar($otro_run, $otro);

        $this->assertSame('No se pudo conectar con el buscador.', $this->diagnostico_de($otro_item, 'codigo_de_barras')['error']);
        $this->assertStringNotContainsString('cURL', (string) $otro_item->motivo_detalle);
    }

    /**
     * Retención (S5 + A4): registrar() también purga lo de más de 180 días del dueño (un dueño que
     * solo usa el asistente nunca crea una asignación), solo lo suyo y una vez por día.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function registrar_tambien_purga_lo_viejo_del_dueno_una_vez_por_dia()
    {
        Cache::flush();

        $otro = User::create([
            'name'     => 'Otro comercio de la base',
            'email'    => 'otro-purga-'.uniqid().'@test.local',
            'password' => Hash::make('secret'),
        ]);

        $viejo_propio = $this->consulta_con_fecha($this->owner->id, Carbon::now()->subDays(181));
        $viejo_ajeno  = $this->consulta_con_fecha($otro->id, Carbon::now()->subDays(200));
        $reciente     = $this->consulta_con_fecha($this->owner->id, Carbon::now()->subDays(179));

        $nueva = ImageServiceCallLogger::registrar([
            'user_id'   => $this->owner->id,
            'origen'    => ImageServiceCall::ORIGEN_ASISTENTE_CODIGO_DE_BARRAS,
            'tipo'      => ImageServiceCall::TIPO_BUSQUEDA,
            'proveedor' => 'google',
            'ok'        => true,
        ]);

        $this->assertNotNull($nueva);
        $this->assertNull(ImageServiceCall::find($viejo_propio->id), 'Lo viejo del dueño se purgó.');
        $this->assertNotNull(ImageServiceCall::find($viejo_ajeno->id), 'Lo de otro dueño de la base no se toca.');
        $this->assertNotNull(ImageServiceCall::find($reciente->id));

        // El mismo día no vuelve a purgar.
        $otro_viejo = $this->consulta_con_fecha($this->owner->id, Carbon::now()->subDays(190));

        ImageServiceCallLogger::registrar([
            'user_id'   => $this->owner->id,
            'tipo'      => ImageServiceCall::TIPO_BUSQUEDA,
            'proveedor' => 'google',
            'ok'        => true,
        ]);

        $this->assertNotNull(ImageServiceCall::find($otro_viejo->id), 'Ya se purgó hoy: sale mañana.');
    }

    /**
     * Una fila del registro con fecha puesta a mano (sin pasar por registrar()).
     *
     * @param  int    $user_id
     * @param  Carbon $fecha
     * @return \App\Models\ImageServiceCall
     */
    protected function consulta_con_fecha($user_id, Carbon $fecha)
    {
        return ImageServiceCall::create([
            'user_id'    => $user_id,
            'origen'     => ImageServiceCall::ORIGEN_ASIGNACION,
            'tipo'       => ImageServiceCall::TIPO_BUSQUEDA,
            'proveedor'  => 'serper',
            'ok'         => true,
            'cobrada'    => true,
            'created_at' => $fecha,
            'updated_at' => $fecha,
        ]);
    }
}
