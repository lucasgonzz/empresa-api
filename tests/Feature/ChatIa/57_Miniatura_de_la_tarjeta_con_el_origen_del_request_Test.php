<?php

namespace Tests\Feature\ChatIa;

use App\Models\AiMessageAction;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Misión asistente-fotos-barras-y-compras (24/9/2026).
 *
 * La tarjeta del alta con foto se propone adentro del job del asistente, donde url() sale de
 * APP_URL. La miniatura que ve la SPA tiene que salir del origen del request con el que la SPA pide
 * el chat (una instalación con `/public`, el puerto de un slot): AiMessageAction::toArray() la
 * rearma desde `presentacion.imagen_mensaje`.
 */
class Miniatura_de_la_tarjeta_con_el_origen_del_request_Test extends TestCase
{
    /**
     * @test
     */
    public function la_miniatura_se_rearma_con_el_origen_del_request_y_no_con_la_de_app_url()
    {
        // Un request de Apache servido desde /public: Laravel deriva la base de SCRIPT_NAME.
        $request = Request::create(
            'https://api-cliente.example.com/public/api/ai-conversations/1',
            'GET',
            [],
            [],
            [],
            ['SCRIPT_NAME' => '/public/index.php', 'SCRIPT_FILENAME' => '/home/cliente/public/index.php', 'PHP_SELF' => '/public/index.php']
        );

        $this->app->instance('request', $request);
        app('url')->setRequest($request);

        $accion = new AiMessageAction();
        $accion->presentacion = [
            'titulo'         => 'Alta de artículo',
            'renglones'      => [],
            'imagen_url'     => 'https://app-url-equivocada.example.com/api/ai-mensajes/7/imagen/1',
            'imagen_mensaje' => ['ai_message_id' => 7, 'orden' => 1],
        ];

        $array = $accion->toArray();

        $this->assertSame(
            'https://api-cliente.example.com/public/api/ai-mensajes/7/imagen/1',
            $array['presentacion']['imagen_url']
        );
    }

    /**
     * Una tarjeta sin la referencia (todas las viejas) sale igual que siempre.
     *
     * @test
     */
    public function una_tarjeta_sin_imagen_mensaje_no_cambia()
    {
        $accion = new AiMessageAction();
        $accion->presentacion = ['titulo' => 'Gasto', 'renglones' => [], 'imagen_url' => 'https://x.example.com/a.webp'];

        $this->assertSame('https://x.example.com/a.webp', $accion->toArray()['presentacion']['imagen_url']);
    }
}
