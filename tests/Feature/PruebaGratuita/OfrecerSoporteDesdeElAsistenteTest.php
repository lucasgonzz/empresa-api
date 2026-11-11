<?php

namespace Tests\Feature\PruebaGratuita;

use App\Events\ChatIaMensajeActualizado;
use App\Http\Controllers\Helpers\DemoTrackingConfigHelper;
use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\asistente_ia\AdjuntosIaHelper;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiConversation;
use App\Models\DemoTrackingConfig;
use App\Models\ExtencionEmpresa;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientaDeOfrecerSoporte;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * E1 de la misión prueba-ayuda-unificada-en-soporte (10/11/2026): el asistente personal de la prueba
 * ofrece pasar una duda de uso a Soporte con `ofrecer_soporte`.
 *
 * Lo que se cuida:
 *  - La herramienta existe SOLO en la prueba, con `support_chat` y en el canal del sistema.
 *  - Lo que deja llega a `ai_messages.adjuntos` como `{tipo: 'soporte', url: null, texto, articulo_id: null}`.
 *  - No manda nada por sí sola: ni un ticket ni un mensaje de soporte, ni un pedido al admin.
 *  - El admin (WhatsApp) nunca recibe el adjunto de soporte.
 */
class OfrecerSoporteDesdeElAsistenteTest extends TestCase
{
    use DatabaseTransactions;

    const ADMIN = 'https://admin.test/api/prueba-chat';
    const PAGINA = 'manual_sistema/compras.md';
    const PREGUNTA = '¿Cómo cargo una compra a un proveedor?';

    private $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::create([
            'name' => 'Dueño de la prueba',
            'email' => uniqid('ofrecer-soporte-') . '@test.local',
            'password' => bcrypt('prueba-local'),
        ]);
        $this->dar_extension('agentes_ia', 'Agentes de IA');

        config([
            'app.FOR_USER' => 'prueba',
            'app.USER_ID' => $this->dueno->id,
            'cache.default' => 'array',
            'broadcasting.default' => 'null',
            'services.admin_api.url' => 'https://admin.test',
            'services.admin_api.inbound_key' => 'clave-interna-de-la-instancia',
            'services.deepseek.api_key' => 'clave-de-pruebas',
            'services.deepseek.base_url' => 'https://proveedor.test',
        ]);

        DemoTrackingConfig::query()->delete();
        DemoTrackingConfigHelper::olvidar_cache();
        PruebaHelper::olvidar_cache();
        PruebaHelper::activar_desde_payload($this->dueno, ['dias' => 7]);
        DemoTrackingConfigHelper::guardar('clave-eventos-de-prueba', 'https://admin.test/api/demo-eventos');
    }

    protected function tearDown(): void
    {
        DemoTrackingConfigHelper::olvidar_cache();
        PruebaHelper::olvidar_cache();
        parent::tearDown();
    }

    public function test_con_soporte_el_job_guarda_la_oferta_en_los_adjuntos_sin_mandar_nada()
    {
        $this->dar_extension('support_chat', 'Chat de soporte');
        Event::fake([ChatIaMensajeActualizado::class]);
        $this->fakear_loop_que_ofrece_soporte();

        $tickets = SupportTicket::count();
        $mensajes = SupportMessage::count();

        $pendiente = $this->conversacion_con_pendiente('sistema');
        (new ResponderMensajeChatIaJob($pendiente->id))->handle();
        $pendiente->refresh();

        $this->assertSame('listo', $pendiente->estado);
        $this->assertSame(
            [['tipo' => 'soporte', 'url' => null, 'texto' => self::PREGUNTA, 'articulo_id' => null]],
            $pendiente->adjuntos
        );
        // assertEquals y no assertSame: la columna es JSON de MySQL, que guarda las claves en su propio orden.
        $this->assertEquals(
            [['tipo' => 'soporte', 'url' => null, 'texto' => self::PREGUNTA, 'articulo_id' => null]],
            json_decode((string) \DB::table('ai_messages')->where('id', $pendiente->id)->value('adjuntos'), true),
            'La oferta queda guardada en la columna, no solo en el accessor.'
        );

        // La herramienta viajó en el pedido al proveedor, después de la del manual.
        Http::assertSent(function ($pedido) {
            if (strpos($pedido->url(), 'proveedor.test/') === false) {
                return false;
            }
            $herramientas = array_column($pedido['tools'], 'name');
            return in_array(HerramientaDeOfrecerSoporte::NOMBRE, $herramientas, true);
        });

        // No manda nada por sí sola: ni ticket, ni mensaje, ni pedido a las rutas de soporte del admin.
        $this->assertSame($tickets, SupportTicket::count());
        $this->assertSame($mensajes, SupportMessage::count());
        Http::assertNotSent(function ($pedido) {
            return strpos($pedido->url(), 'admin.test') !== false
                && strpos($pedido->url(), '/manual') === false
                && strpos($pedido->url(), '/demo-eventos') === false;
        });
    }

    public function test_sin_la_extension_de_soporte_no_se_ofrece()
    {
        Http::fake(['proveedor.test/*' => Http::response($this->fin('Abrí Compras.'))]);

        (new AsistenteIaService())->responder($this->conversacion(), $this->conversacion_con_pendiente('sistema'));

        $this->assertSinLaHerramienta();
    }

    public function test_fuera_de_la_prueba_no_se_ofrece()
    {
        $this->dar_extension('support_chat', 'Chat de soporte');
        config(['app.FOR_USER' => null]);
        PruebaHelper::olvidar_cache();
        Http::fake(['proveedor.test/*' => Http::response($this->fin('Abrí Compras.'))]);

        (new AsistenteIaService())->responder($this->conversacion(), $this->conversacion_con_pendiente('sistema'));

        $this->assertSinLaHerramienta();
    }

    public function test_fuera_del_canal_del_sistema_no_se_ofrece()
    {
        $this->dar_extension('support_chat', 'Chat de soporte');
        $service = new AsistenteIaService();

        // build_tools() arma el juego con lo que decidió el turno; sin turno del sistema no hay oferta.
        foreach ([[false, false], [true, false], [false, true]] as $canal) {
            $nombres = array_column($service->build_tools(false, $canal[0], $this->dueno, $canal[1]), 'name');
            $this->assertNotContains(HerramientaDeOfrecerSoporte::NOMBRE, $nombres);
        }

        // Y un mensaje de WhatsApp del mismo dueño, con todo lo demás igual, tampoco la recibe.
        Http::fake(['proveedor.test/*' => Http::response($this->fin('Abrí Compras.'))]);
        try {
            $service->responder($this->conversacion(), $this->conversacion_con_pendiente('whatsapp'));
        } catch (\Throwable $e) {
            // Si WhatsApp exige algo más de configuración, lo que importa es qué herramientas viajaron.
        }

        Http::assertNotSent(function ($pedido) {
            return strpos($pedido->url(), 'proveedor.test/') !== false
                && in_array(HerramientaDeOfrecerSoporte::NOMBRE, array_column((array) $pedido['tools'], 'name'), true);
        });
    }

    public function test_la_oferta_se_normaliza_y_el_admin_no_la_recibe()
    {
        $largo = str_repeat('a', AdjuntosIaHelper::MAX_TEXTO_SOPORTE + 50);

        $normalizados = AdjuntosIaHelper::normalizar([
            ['tipo' => 'soporte', 'texto' => '   '],
            ['tipo' => 'soporte', 'texto' => $largo, 'url' => 'https://otro.test/x'],
            ['tipo' => 'soporte', 'texto' => 'Una segunda oferta no entra'],
            ['tipo' => 'imagen', 'url' => 'https://fotos.test/a.jpg', 'texto' => 'Martillo', 'articulo_id' => 12],
        ]);

        $this->assertCount(2, $normalizados);
        $this->assertSame(['tipo', 'url', 'texto', 'articulo_id'], array_keys($normalizados[0]));
        $this->assertSame('soporte', $normalizados[0]['tipo']);
        $this->assertNull($normalizados[0]['url'], 'La oferta no lleva URL aunque el modelo mande una.');
        $this->assertSame(AdjuntosIaHelper::MAX_TEXTO_SOPORTE, mb_strlen($normalizados[0]['texto']));
        $this->assertSame('imagen', $normalizados[1]['tipo']);

        $this->assertSame(
            [['tipo' => 'imagen', 'url' => 'https://fotos.test/a.jpg', 'texto' => 'Martillo']],
            AdjuntosIaHelper::para_el_admin($normalizados)
        );

        $this->assertArrayHasKey('error', HerramientaDeOfrecerSoporte::ejecutar(['pregunta' => '  ']));
    }

    private function assertSinLaHerramienta()
    {
        Http::assertSent(function ($pedido) {
            return strpos($pedido->url(), 'proveedor.test/') !== false;
        });
        Http::assertNotSent(function ($pedido) {
            return strpos($pedido->url(), 'proveedor.test/') !== false
                && in_array(HerramientaDeOfrecerSoporte::NOMBRE, array_column((array) $pedido['tools'], 'name'), true);
        });
    }

    private function fakear_loop_que_ofrece_soporte()
    {
        Http::fake([
            self::ADMIN . '/manual*' => Http::response(['disponible' => true, 'indice' => '- ' . self::PAGINA]),
            // El hito `prueba.agente` del turno: es del recorrido de la prueba, no un envío a Soporte.
            'admin.test/api/demo-eventos*' => Http::response(['ok' => true]),
            'proveedor.test/*' => Http::sequence()
                ->push([
                    'stop_reason' => 'tool_use',
                    'content' => [[
                        'type' => 'tool_use',
                        'id' => 'soporte_1',
                        'name' => HerramientaDeOfrecerSoporte::NOMBRE,
                        'input' => ['pregunta' => self::PREGUNTA],
                    ]],
                ])
                ->push($this->fin('Entrá a Compras y tocá Nueva compra. ¿Querés que Soporte te arme un tour?')),
        ]);
    }

    private function fin($texto)
    {
        return ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $texto]]];
    }

    private $conversacion;

    private function conversacion()
    {
        if ($this->conversacion === null) {
            $this->conversacion = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]);
        }

        return $this->conversacion;
    }

    private function conversacion_con_pendiente($canal)
    {
        $this->conversacion()->messages()->create(['rol' => 'user', 'contenido' => self::PREGUNTA, 'canal' => $canal]);

        return $this->conversacion()->messages()->create(['rol' => 'assistant', 'estado' => 'pendiente', 'canal' => $canal]);
    }

    private function dar_extension($slug, $nombre)
    {
        $extension = ExtencionEmpresa::where('slug', $slug)->first();
        if ($extension === null) {
            $extension = new ExtencionEmpresa();
            $extension->name = $nombre;
            $extension->slug = $slug;
            $extension->save();
        }
        $this->dueno->extencions()->syncWithoutDetaching([$extension->id]);
        $this->dueno->unsetRelation('extencions');
    }
}
