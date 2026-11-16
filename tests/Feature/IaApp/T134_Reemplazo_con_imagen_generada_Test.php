<?php

namespace Tests\Feature\IaApp;

use App\Http\Controllers\Helpers\ApiUrlHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaFotoArticuloIaHelper;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\IaImagenPedido;
use App\Models\Image;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;

/**
 * Misión asistente-mejoras-4-dias (revisión de P6, 16/11/2026): publicar una imagen GENERADA con
 * `reemplazar_fotos` saca las fotos que el artículo tenía al proponer (antes el pedido se ignoraba y la foto
 * vieja seguía publicada). La tarjeta lista las que se sacan, con su URL, y espera el sí.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados, union types.
 */
class T134_Reemplazo_con_imagen_generada_Test extends IaAppTestCase
{
    public function test_reemplazar_con_la_imagen_generada_saca_la_foto_vieja()
    {
        $conversacion = $this->conversacion();
        $mensaje = AiMessage::create([
            'ai_conversation_id' => $conversacion->id,
            'rol' => 'assistant',
            'estado' => 'listo',
            'canal' => AiMessage::CANAL_APP,
        ]);
        $articulo = Article::create(['user_id' => $this->dueno->id, 'name' => 'Malbec reemplazo T134', 'status' => 'active']);
        $nombre = 'prueba-ia-' . Str::uuid() . '.webp';
        $binario = (string) (new ImageManager())->canvas(24, 24, '#382836')->encode('webp');
        file_put_contents(storage_path('app/public/' . $nombre), $binario);

        try {
            $original = Image::create([
                'imageable_type' => 'article', 'imageable_id' => $articulo->id,
                'hosting_url' => ApiUrlHelper::storage($nombre),
            ]);
            $uuid = (string) Str::uuid();
            $carpeta = 'ia_imagenes/' . $this->dueno->id;
            $this->carpetas_a_limpiar[] = $carpeta;
            $path = $carpeta . '/' . $uuid . '.webp';
            Storage::disk('local')->put($path, $binario);
            $pedido = IaImagenPedido::create([
                'uuid' => $uuid, 'user_id' => $this->dueno->id, 'ai_message_id' => $mensaje->id,
                'article_id' => $articulo->id, 'referencia_imagen_id' => $original->id,
                'referencia_sha256' => hash('sha256', $binario),
                'referencia_articulo_sha256' => PropuestaFotoArticuloIaHelper::huella_del_articulo($articulo),
                'prompt' => 'Botella sola', 'tamano' => 'cuadrada', 'estado' => 'lista', 'path' => $path,
            ]);

            $respuesta = PropuestaFotoArticuloIaHelper::proponer_generada(ContextoDeCargaIa::de_la_conversacion($conversacion), $mensaje, [
                'pedido_imagen_uuid' => $pedido->uuid, 'reemplazar_fotos' => true,
            ]);

            $this->assertTrue($respuesta['requiere_confirmacion'], json_encode($respuesta));
            $this->assertSame($original->id, $respuesta['fotos_que_se_sacan'][0]['image_id']);
            $this->assertNotEmpty($respuesta['fotos_que_se_sacan'][0]['url']);
            $this->assertCount(1, AiMessageAction::findOrFail($respuesta['tarjeta_id'])->presentacion['fotos_que_se_sacan']);

            $url = '/api/ai-conversations/' . $conversacion->id . '/acciones/' . $respuesta['tarjeta_id'] . '/confirmar';
            $this->postJson($url, [], $this->de_la_app())->assertOk();

            $this->assertSame(1, $articulo->images()->count(), 'Queda solo la generada.');
            $this->assertFalse($articulo->images()->whereKey($original->id)->exists(), 'La foto vieja ya no está publicada.');
            $this->assertSame((int) $pedido->fresh()->publicada_image_id, (int) $articulo->images()->first()->id);
        } finally {
            foreach ($articulo->images()->get() as $imagen) {
                $archivo = PropuestaFotoArticuloIaHelper::archivo_de_la_imagen($imagen)['ruta'];
                if ($archivo && is_file($archivo)) {
                    unlink($archivo);
                }
            }
            if (is_file(storage_path('app/public/' . $nombre))) {
                unlink(storage_path('app/public/' . $nombre));
            }
        }
    }
}
