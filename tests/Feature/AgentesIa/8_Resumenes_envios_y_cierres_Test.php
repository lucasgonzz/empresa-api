<?php

namespace Tests\Feature\AgentesIa;

use App\Jobs\GenerarResumenSugerenciaJob;
use App\Jobs\GenerarResumenSugerenciaOfertaJob;
use App\Jobs\ProcessArticleBatchImagesJob;
use App\Jobs\ProcessCategoryImagesJob;
use App\Jobs\ProcessImageAssignmentRunJob;
use App\Jobs\ProcessProductEnrichmentJob;
use App\Jobs\RunProviderOrderScanJob;
use App\Jobs\SendPromocionWhatsappJob;
use App\Jobs\SendRecordatorioCobroJob;
use App\Jobs\SendSaleWhatsappJob;
use App\Models\BackgroundProcess;
use App\Models\Client;
use App\Models\ExtencionEmpresa;
use App\Models\ImageAssignmentRun;
use App\Models\OfferSuggestion;
use App\Models\ProductEnrichment;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderScan;
use App\Models\Sale;
use App\Models\StockSuggestion;
use App\Models\WhatsappBotConfig;
use App\Services\ProductFacts\ProductEnrichmentService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Misión agentes-ia-extension-unica, correcciones tras la revisión independiente (22/10/2026).
 *
 * Tres cosas:
 *
 *  1. Los resúmenes con IA de lo que NO es de la extensión (sugerencias de stock, motor de ofertas, actividad
 *     de clientes) exigen `agentes_ia`; el resto de esos módulos sigue andando sin ella.
 *  2. Los envíos de WhatsApp de la cola revalidan `agentes_ia` y salen en silencio.
 *  3. Los jobs de IA que se cortan por falta de extensión CIERRAN su registro (corrida, escaneo, tarea) como
 *     fallido con un mensaje claro, en vez de dejarlo colgado.
 *
 * La extensión `agentes_ia` se quita en el setUp de la base; `tracking_buyers` y `motor_de_ofertas` se dan a mano.
 */
class Resumenes_envios_y_cierres_Test extends AgentesIaTestCase
{
    /**
     * Le da al comercio una extensión suelta (creando la fila si falta) y recarga la relación.
     *
     * @param  string  $slug
     * @return void
     */
    protected function dar_otra($slug)
    {
        $fila = ExtencionEmpresa::where('slug', $slug)->first();

        if (is_null($fila)) {
            $fila = ExtencionEmpresa::forceCreate(['slug' => $slug, 'name' => $slug]);
        }

        $this->comercio()->extencions()->syncWithoutDetaching([$fila->id]);
        $this->comercio()->load('extencions');
        $this->actingAs($this->comercio(), 'web');
    }

    /* ------------------------------------------------------------------ */
    /* 1. Resúmenes con IA                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * @test
     * @return void
     */
    public function el_resumen_de_actividad_de_clientes_exige_agentes_ia_ademas_de_tracking_buyers()
    {
        $this->dar_otra('tracking_buyers');

        $sin = $this->postJson('api/actividad-de-clientes/resumen', []);

        $this->assertTrue($this->es_el_403_de_la_extension($sin), 'Con solo tracking_buyers el resumen tiene que dar 403 de agentes_ia: ' . $sin->getContent());

        /* La lectura de la actividad en sí NO pide agentes_ia. */
        $this->assertFalse($this->es_el_403_de_la_extension($this->getJson('api/actividad-de-clientes')));

        $this->dar_extension();

        $this->assertFalse($this->es_el_403_de_la_extension($this->postJson('api/actividad-de-clientes/resumen', [])), 'Con las dos extensiones el resumen no puede dar el 403 de agentes_ia.');
    }

    /**
     * @test
     * @return void
     */
    public function el_resumen_de_ofertas_exige_agentes_ia_pero_el_motor_de_ofertas_sigue_abierto_sin_ella()
    {
        $this->dar_otra('motor_de_ofertas');

        $this->getJson('api/offer-suggestion')->assertStatus(200);

        $sin = $this->postJson('api/offer-suggestion/999999/resumen', []);

        $this->assertTrue($this->es_el_403_de_la_extension($sin), 'El resumen de ofertas sin agentes_ia tiene que dar 403: ' . $sin->getContent());

        $this->dar_extension();

        $con = $this->postJson('api/offer-suggestion/999999/resumen', []);

        $this->assertFalse($this->es_el_403_de_la_extension($con));
        $con->assertStatus(404);
    }

    /**
     * El job que pide el resumen de una sugerencia de stock no sale a la IA sin la extensión: el estado
     * «pendiente» vuelve a null (la vista dice «sin resumen») y no se manda nada. Con la extensión, sí pide.
     *
     * @test
     * @return void
     */
    public function el_resumen_de_stock_no_llama_a_la_ia_sin_la_extension_y_si_con_ella()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $sugerencia = StockSuggestion::create([
            'modo'              => 'minimo',
            'origen'            => 'absoluto',
            'limite_origen'     => 'minimo',
            'status'            => 'terminado',
            'user_id'           => $this->comercio()->id,
            'resumen_ia_estado' => 'pendiente',
        ]);

        (new GenerarResumenSugerenciaJob($sugerencia->id))->handle();

        Http::assertNothingSent();
        $this->assertNull($sugerencia->fresh()->resumen_ia_estado, 'Sin la extensión el resumen no se pide y no queda «pendiente».');

        $this->dar_extension();
        $sugerencia->resumen_ia_estado = 'pendiente';
        $sugerencia->save();

        (new GenerarResumenSugerenciaJob($sugerencia->id))->handle();

        Http::assertSent(function ($request) {
            return strpos((string) $request->url(), 'anthropic') !== false;
        });
    }

    /**
     * Lo mismo para el resumen del motor de ofertas (el que sale solo al terminar la corrida y el del cron).
     *
     * @test
     * @return void
     */
    public function el_resumen_de_ofertas_no_llama_a_la_ia_sin_la_extension_y_si_con_ella()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $sugerencia = OfferSuggestion::create([
            'user_id' => $this->comercio()->id, 'status' => 'terminado', 'origen_generacion' => 'manual',
            'dias_vigencia_sugerida' => 15, 'resumen_ia_estado' => 'pendiente',
        ]);

        (new GenerarResumenSugerenciaOfertaJob($sugerencia->id))->handle();

        Http::assertNothingSent();
        $this->assertNull($sugerencia->fresh()->resumen_ia_estado);

        $this->dar_extension();
        $sugerencia->resumen_ia_estado = 'pendiente';
        $sugerencia->save();

        (new GenerarResumenSugerenciaOfertaJob($sugerencia->id))->handle();

        Http::assertSent(function ($request) {
            return strpos((string) $request->url(), 'anthropic') !== false;
        });
    }

    /* ------------------------------------------------------------------ */
    /* 2. Envíos de WhatsApp de la cola                                     */
    /* ------------------------------------------------------------------ */

    /**
     * @test
     * @return void
     */
    public function el_comprobante_automatico_por_whatsapp_no_se_procesa_sin_la_extension()
    {
        $cliente = Client::create(['name' => 'Sin teléfono', 'user_id' => $this->comercio()->id]);
        $venta   = Sale::create(['user_id' => $this->comercio()->id, 'client_id' => $cliente->id, 'moneda_id' => 1, 'total' => 1000, 'terminada' => 1]);

        WhatsappBotConfig::create([
            'user_id' => $this->comercio()->id, 'kapso_api_key' => 'k', 'phone_number_id' => '5491100000077',
            'webhook_secret' => 's', 'is_active' => true, 'auto_send_sale_pdf' => true,
        ]);

        Log::spy();

        (new SendSaleWhatsappJob($venta->id))->handle();

        Log::shouldNotHaveReceived('info');

        /* Control: con la extensión el job llega hasta su siguiente guarda (cliente sin teléfono) y lo anota. */
        $this->dar_extension();

        (new SendSaleWhatsappJob($venta->id))->handle();

        Log::shouldHaveReceived('info')->once();
        Http::assertNothingSent();
    }

    /**
     * @test
     * @return void
     */
    public function el_recordatorio_de_cobro_de_la_cola_no_se_procesa_sin_la_extension()
    {
        Log::spy();

        (new SendRecordatorioCobroJob($this->comercio()->id, 999999, 30))->handle();

        Log::shouldNotHaveReceived('info');

        $this->dar_extension();

        (new SendRecordatorioCobroJob($this->comercio()->id, 999999, 30))->handle();

        /* Con la extensión llega al «el cliente ya no existe». */
        Log::shouldHaveReceived('info')->atLeast()->once();
    }

    /**
     * @test
     * @return void
     */
    public function la_promocion_por_whatsapp_de_la_cola_no_se_procesa_sin_la_extension()
    {
        Log::spy();

        (new SendPromocionWhatsappJob($this->comercio()->id, 999999, 'Texto', 'https://tienda.test/x', '15 días'))->handle();

        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------ */
    /* 3. Los registros no quedan colgados                                  */
    /* ------------------------------------------------------------------ */

    /**
     * La corrida de asignación de imágenes —pendiente o en proceso— queda FALLIDA con el motivo y su registro
     * visible se cierra. Las dos formas de corrida activa.
     *
     * @test
     * @return void
     */
    public function la_corrida_de_imagenes_sin_la_extension_queda_fallida_y_cierra_su_registro()
    {
        foreach ([ImageAssignmentRun::STATUS_PENDIENTE, ImageAssignmentRun::STATUS_EN_PROCESO] as $estado) {

            $proceso = BackgroundProcess::create([
                'uuid'    => (string) Str::uuid(),
                'user_id' => $this->comercio()->id,
                'tipo'    => 'imagenes_automaticas',
                'titulo'  => 'Imágenes automáticas',
                'status'  => BackgroundProcess::STATUS_PENDIENTE,
            ]);

            $corrida = ImageAssignmentRun::create([
                'user_id'               => $this->comercio()->id,
                'auth_user_id'          => $this->comercio()->id,
                'uuid'                  => (string) Str::uuid(),
                'origen'                => 'seleccion',
                'proveedor'             => 'google',
                'status'                => $estado,
                'total_articulos'       => 3,
                'background_process_id' => $proceso->id,
            ]);

            (new ProcessImageAssignmentRunJob($corrida->id))->handle();

            $corrida = $corrida->fresh();

            $this->assertSame(ImageAssignmentRun::STATUS_FALLIDA, $corrida->status, 'La corrida ' . $estado . ' no quedó fallida.');
            $this->assertStringContainsString('Agentes de IA', (string) $corrida->motivo_estado);
            $this->assertNotNull($corrida->finished_at);
            $this->assertNotSame(BackgroundProcess::STATUS_PENDIENTE, $proceso->fresh()->status, 'El registro visible de la corrida ' . $estado . ' quedó colgado.');
        }

        Http::assertNothingSent();
    }

    /**
     * @test
     * @return void
     */
    public function el_escaneo_de_factura_sin_la_extension_queda_en_error_con_el_motivo()
    {
        $compra = ProviderOrder::create(['provider_id' => $this->proveedor_cualquiera(), 'user_id' => $this->comercio()->id]);

        $escaneo = ProviderOrderScan::create([
            'uuid'              => (string) Str::uuid(),
            'user_id'           => $this->comercio()->id,
            'auth_user_id'      => $this->comercio()->id,
            'provider_order_id' => $compra->id,
        ]);

        (new RunProviderOrderScanJob($escaneo->id))->handle();

        $escaneo = $escaneo->fresh();

        $this->assertSame('error', $escaneo->estado);
        $this->assertStringContainsString('Agentes de IA', (string) $escaneo->error);
        Http::assertNothingSent();
    }

    /**
     * @test
     * @return void
     */
    public function la_ficha_por_gtin_sin_la_extension_queda_fallida_con_el_motivo()
    {
        $tarea = ProductEnrichment::create([
            'uuid'         => (string) Str::uuid(),
            'user_id'      => $this->comercio()->id,
            'auth_user_id' => $this->comercio()->id,
            'bar_code'     => '7790000000017',
            'gtin'         => '07790000000017',
            'status'       => 'queued',
        ]);

        (new ProcessProductEnrichmentJob($tarea->id))->handle(app(ProductEnrichmentService::class));

        $tarea = $tarea->fresh();

        $this->assertSame('failed', $tarea->status);
        $this->assertStringContainsString('Agentes de IA', (string) $tarea->message);
        Http::assertNothingSent();
    }

    /**
     * Los procesos visibles de la búsqueda masiva de imágenes y de las imágenes de categorías se cierran como
     * fallidos (la píldora de procesos no queda girando).
     *
     * @test
     * @return void
     */
    public function los_procesos_visibles_de_imagenes_sin_la_extension_quedan_fallidos()
    {
        $proceso = BackgroundProcess::create([
                'uuid'    => (string) Str::uuid(),
            'user_id' => $this->comercio()->id,
            'tipo'    => 'imagenes_automaticas',
            'titulo'  => 'Imágenes automáticas',
            'status'  => BackgroundProcess::STATUS_PENDIENTE,
        ]);

        (new ProcessArticleBatchImagesJob([1, 2], $this->comercio()->id, 'k', 'cx', 100, '', $proceso->id))->handle();

        $proceso = $proceso->fresh();

        $this->assertSame(BackgroundProcess::STATUS_FALLO, $proceso->status);
        $this->assertStringContainsString('Agentes de IA', (string) $proceso->error_message);

        $proceso_cat = BackgroundProcess::create([
                'uuid'    => (string) Str::uuid(),
            'user_id' => $this->comercio()->id,
            'tipo'    => ProcessCategoryImagesJob::TIPO_PROCESO,
            'titulo'  => 'Imágenes de categorías',
            'status'  => BackgroundProcess::STATUS_PENDIENTE,
        ]);

        (new ProcessCategoryImagesJob($this->comercio()->id, $this->comercio()->id, 1, [], 'k', 'cx', 100, 'sin_imagen', $proceso_cat->id))->handle();

        $this->assertSame(BackgroundProcess::STATUS_FALLO, $proceso_cat->fresh()->status);
        Http::assertNothingSent();
    }

    /**
     * @return int
     */
    protected function proveedor_cualquiera()
    {
        return $this->proveedor(\Database\Seeders\testing\TestingFerreteriaSeeder::PROVIDER_BSAS)->id;
    }
}
