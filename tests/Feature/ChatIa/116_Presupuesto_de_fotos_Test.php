<?php

namespace Tests\Feature\ChatIa;

use App\Exceptions\AsistenteIaException;
use App\Http\Controllers\Helpers\asistente_ia\ProveedorIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\TranscripcionDeFotosIaHelper;
use App\Models\AiMessage;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Support\Facades\Http;
use Intervention\Image\ImageManager;

/**
 * El respaldo de Haiku comparte los 100 segundos de las lecturas del turno. El reloj falso
 * permite llegar al respaldo después de 92 o 96 segundos sin esperar ni usar proveedores reales.
 * Las fotos son lienzos generados; la evidencia guarda tiempos y conteos, nunca sus payloads.
 *
 * PHP 7.4: sin match, argumentos nombrados ni union types.
 *
 * @group chat-ia
 */
class Presupuesto_de_fotos_Test extends PlanillaTestCase
{
    protected $pedidos_a_haiku = [];

    protected $pedidos_a_deepseek = [];

    protected $falla_haiku = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Aislamos la transcripción de respaldo de la lectura de planillas y del análisis persistente.
        config([
            'services.lectura_planilla.activa' => false,
            'services.fotos_del_asistente.activa' => false,
        ]);

        Http::fake(function ($request, $options) {
            $cuerpo = json_decode($request->body(), true);
            $modelo = (string) ($cuerpo['model'] ?? '');

            if ($modelo === ProveedorIaHelper::MODELO_HAIKU_IMAGENES) {
                $this->pedidos_a_haiku[] = ['modelo' => $modelo, 'timeout' => (int) $options['timeout']];

                if ($this->falla_haiku) {
                    return Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'sin disponibilidad']], 529);
                }

                $textos = [];

                foreach ($cuerpo['messages'][0]['content'] as $bloque) {
                    if (($bloque['type'] ?? '') === 'text' && preg_match('/^FOTO (\d+)$/', $bloque['text'], $coincidencia)) {
                        $textos[] = 'FOTO ' . $coincidencia[1] . ': Un martillo marca Acme con mango de madera.';
                    }
                }

                return Http::response($this->texto_del_modelo(implode("\n", $textos)), 200);
            }

            $this->pedidos_a_deepseek[] = [
                'modelo' => $modelo,
                'lleva_imagenes' => $this->contiene_imagenes($cuerpo),
                'lleva_transcripcion' => mb_strpos(json_encode($cuerpo['messages'], JSON_UNESCAPED_UNICODE), 'Un martillo marca Acme') !== false,
            ];

            return Http::response($this->texto_del_modelo('El martillo es marca Acme.'), 200);
        });
    }

    /** Recorre también bloques anidados sin incluir el contenido de la imagen en una aserción. */
    protected function contiene_imagenes(array $bloques): bool
    {
        foreach ($bloques as $clave => $valor) {
            if (($clave === 'type' && in_array($valor, ['image', 'image_url', 'document'], true))
                || (is_array($valor) && $this->contiene_imagenes($valor))) {
                return true;
            }
        }

        return false;
    }

    /** El mismo reloj del service: arranca en cero y avanza antes de pedir el respaldo. */
    protected function servicio_con_tiempo_transcurrido($transcurrido)
    {
        return new class($transcurrido) extends AsistenteIaService {
            protected $transcurrido;

            protected $consultas_al_reloj = 0;

            public function __construct($transcurrido)
            {
                $this->transcurrido = (int) $transcurrido;
            }

            protected function ahora(): int
            {
                return 0;
            }

            protected function segundos_del_turno($inicio_del_loop, $assistant_message = null): int
            {
                $this->consultas_al_reloj++;

                return $this->consultas_al_reloj === 1 ? 0 : $this->transcurrido;
            }
        };
    }

    /** El turno real por WhatsApp con una foto sintética nueva. */
    protected function turno_con_foto()
    {
        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, '¿Qué marca es el martillo?', AiMessage::CANAL_WHATSAPP);
        $this->foto($pedido);

        return [$conversation, $this->assistant($conversation, AiMessage::CANAL_WHATSAPP)];
    }

    /** Payload del helper con imágenes válidas generadas en memoria y sin filas de fotos reales. */
    protected function payload_de_fotos($cantidad = 1)
    {
        $content = [['type' => 'text', 'text' => '¿Qué marca es el martillo?']];

        for ($i = 0; $i < $cantidad; $i++) {
            $binario = (string) (new ImageManager())->canvas(16, 16, $i === 0 ? '#ffffff' : '#000000')->encode('png');
            $content[] = ['type' => 'image', 'source' => [
                'type' => 'base64',
                'media_type' => 'image/png',
                'data' => base64_encode($binario),
            ]];
        }

        return [['role' => 'user', 'content' => $content]];
    }

    /** @test */
    public function despues_de_92_segundos_haiku_solo_dispone_de_ocho_y_deepseek_recibe_texto()
    {
        list($conversation, $assistant) = $this->turno_con_foto();

        $texto = $this->servicio_con_tiempo_transcurrido(92)->responder($conversation, $assistant);

        $this->assertSame('El martillo es marca Acme.', $texto);
        $this->assertCount(1, $this->pedidos_a_haiku);
        $this->assertSame(8, $this->pedidos_a_haiku[0]['timeout']);
        $this->assertCount(1, $this->pedidos_a_deepseek);
        $this->assertSame('deepseek-pro-c107', $this->pedidos_a_deepseek[0]['modelo']);
        $this->assertFalse($this->pedidos_a_deepseek[0]['lleva_imagenes']);
        $this->assertTrue($this->pedidos_a_deepseek[0]['lleva_transcripcion']);
    }

    /** @test */
    public function con_menos_de_cinco_segundos_no_arranca_haiku_ni_deepseek()
    {
        list($conversation, $assistant) = $this->turno_con_foto();

        $texto = $this->servicio_con_tiempo_transcurrido(96)->responder($conversation, $assistant);

        $this->assertStringContainsString('Me llegó la foto y quedó guardada.', $texto);
        $this->assertStringContainsString('Escribime «seguí»', $texto);
        $this->assertNull($conversation->messages()->where('rol', 'user')->first()->imagenes()->first()->gestionada_at);
        $this->assertCount(0, $this->pedidos_a_haiku);
        $this->assertCount(0, $this->pedidos_a_deepseek);
        Http::assertNothingSent();
    }

    /** @test */
    public function una_falla_autentica_de_haiku_conserva_el_error_tecnico_y_no_manda_fotos_a_deepseek()
    {
        $this->falla_haiku = true;
        list($conversation, $assistant) = $this->turno_con_foto();

        try {
            $this->servicio_con_tiempo_transcurrido(92)->responder($conversation, $assistant);
            $this->fail('El rechazo real de Haiku tiene que conservar la falla técnica.');
        } catch (AsistenteIaException $e) {
            $this->assertSame(AsistenteIaException::MOTIVO_FALLA_TECNICA, $e->motivo());
            $this->assertNull($e->mensaje_para_la_persona());
        }

        $this->assertCount(1, $this->pedidos_a_haiku);
        $this->assertSame(8, $this->pedidos_a_haiku[0]['timeout']);
        $this->assertCount(0, $this->pedidos_a_deepseek);
    }

    /** @test */
    public function el_cache_completo_sigue_disponible_con_margen_cero()
    {
        $conversation = $this->conversacion();
        $messages = $this->payload_de_fotos();
        $calentado = TranscripcionDeFotosIaHelper::reemplazar_fotos($messages, [], $conversation);

        $this->assertNotNull($calentado);
        $this->assertCount(1, $this->pedidos_a_haiku);

        $desde_cache = TranscripcionDeFotosIaHelper::reemplazar_fotos($messages, [], $conversation, 0);

        $this->assertNotNull($desde_cache);
        $this->assertFalse($this->contiene_imagenes($desde_cache));
        $this->assertStringContainsString('Un martillo marca Acme', $desde_cache[0]['content']);
        $this->assertCount(1, $this->pedidos_a_haiku, 'El caché completo no necesita otra lectura.');
        $this->assertCount(0, $this->pedidos_a_deepseek);
    }

    /** @test */
    public function el_cache_parcial_con_margen_cero_falla_sin_nuevas_llamadas()
    {
        $conversation = $this->conversacion();
        $calentado = TranscripcionDeFotosIaHelper::reemplazar_fotos($this->payload_de_fotos(), [], $conversation);

        $this->assertNotNull($calentado);
        $this->assertCount(1, $this->pedidos_a_haiku);

        $incompleto = TranscripcionDeFotosIaHelper::reemplazar_fotos($this->payload_de_fotos(2), [], $conversation, 0);

        $this->assertNull($incompleto);
        $this->assertCount(1, $this->pedidos_a_haiku, 'La segunda foto no puede iniciar otro pedido sin margen.');
        $this->assertCount(0, $this->pedidos_a_deepseek);
    }

    /** @test */
    public function el_llamador_legacy_con_tres_argumentos_conserva_los_treinta_segundos()
    {
        $transcripto = TranscripcionDeFotosIaHelper::reemplazar_fotos($this->payload_de_fotos(), [], $this->conversacion());

        $this->assertNotNull($transcripto);
        $this->assertFalse($this->contiene_imagenes($transcripto));
        $this->assertCount(1, $this->pedidos_a_haiku);
        $this->assertSame(30, $this->pedidos_a_haiku[0]['timeout']);
    }

    /** @test */
    public function el_camino_rapido_con_margen_suficiente_sigue_respondiendo()
    {
        list($conversation, $assistant) = $this->turno_con_foto();

        $texto = $this->servicio_con_tiempo_transcurrido(10)->responder($conversation, $assistant);

        $this->assertSame('El martillo es marca Acme.', $texto);
        $this->assertCount(1, $this->pedidos_a_haiku);
        $this->assertSame(30, $this->pedidos_a_haiku[0]['timeout']);
        $this->assertCount(1, $this->pedidos_a_deepseek);
        $this->assertFalse($this->pedidos_a_deepseek[0]['lleva_imagenes']);
        $this->assertTrue($this->pedidos_a_deepseek[0]['lleva_transcripcion']);
    }
}
