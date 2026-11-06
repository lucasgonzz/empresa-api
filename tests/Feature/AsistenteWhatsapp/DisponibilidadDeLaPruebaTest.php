<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\Article;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

class DisponibilidadDeLaPruebaTest extends AsistenteWhatsappTestCase
{
    const RUTA = 'api/admin-sync/asistente/disponibilidad';

    public function test_la_consulta_usa_la_clave_y_el_dueno_del_canal_de_mensajes(): void
    {
        $this->getJson(self::RUTA)->assertStatus(401);

        $this->getJson(self::RUTA, $this->headers())->assertStatus(403);

        config(['app.USER_ID' => $this->empleado->id]);
        $this->getJson(self::RUTA, $this->headers())->assertStatus(409);
    }

    public function test_la_prueba_requiere_credenciales_para_responder_y_escanear_facturas(): void
    {
        $this->dar_extension();
        config(['services.deepseek.api_key' => null]);

        $this->getJson(self::RUTA, $this->headers())->assertStatus(503)
            ->assertExactJson(['disponible' => false, 'motivo' => 'ia_sin_credenciales']);

        config(['services.deepseek.api_key' => 'clave-de-prueba']);
        $this->getJson(self::RUTA, $this->headers())->assertStatus(503)
            ->assertExactJson(['disponible' => false, 'motivo' => 'escaneo_factura_sin_credenciales']);
    }

    public function test_el_dueno_puede_recibir_una_respuesta_sin_indexar_su_catalogo(): void
    {
        $this->dar_extension();
        config([
            'app.FOR_USER' => 'prueba',
            'services.deepseek.api_key' => 'clave-de-prueba',
            'services.deepseek.base_url' => 'https://deepseek.test/anthropic',
            'services.anthropic.api_key' => 'clave-de-escaneo-de-prueba',
            'services.openai.api_key' => '',
        ]);
        Queue::fake();
        Http::fake([
            'https://deepseek.test/anthropic/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Hola, soy el asistente de tu negocio.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 20, 'output_tokens' => 10],
            ]),
        ]);

        $articulo = Article::create(['name' => 'Artículo sin indexar', 'user_id' => $this->comercio->id]);
        $this->assertNull($articulo->fresh()->embedding_generated_at);

        $this->getJson(self::RUTA, $this->headers())->assertOk()
            ->assertExactJson(['disponible' => true, 'user_id' => (int) $this->comercio->id]);
        Queue::assertNothingPushed();

        $id = $this->mandar(['texto' => 'Hola, ¿qué podés hacer?'])->assertStatus(202)->json('ai_message_id');
        app()->call([new ResponderMensajeChatIaJob($id), 'handle']);

        $this->getJson('api/admin-sync/asistente/mensajes/' . $id, $this->headers())->assertOk()
            ->assertJson(['estado' => 'listo', 'contenido' => 'Hola, soy el asistente de tu negocio.']);
    }
}
