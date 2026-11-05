<?php

namespace Tests\Feature\IaApp;

use App\Http\Controllers\Helpers\ApiUrlHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaFotoArticuloIaHelper;
use App\Models\AiMessage;
use App\Models\Article;
use App\Models\IaImagenPedido;
use App\Models\Image;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;

class Publicacion_de_imagen_generada_Test extends IaAppTestCase
{
    /** @dataProvider escenarios_de_publicacion */
    public function test_confirma_la_portada_solo_si_la_presentacion_sigue_vigente(bool $cambio)
    {
        $conversacion = $this->conversacion();
        $mensaje = AiMessage::create([
            'ai_conversation_id' => $conversacion->id,
            'rol' => 'assistant',
            'estado' => 'listo',
            'canal' => AiMessage::CANAL_APP,
        ]);
        $articulo = Article::create([
            'user_id' => $this->dueno->id, 'name' => 'Merlot x6', 'status' => 'active', 'presentacion' => 6,
        ]);
        $nombre = 'prueba-ia-' . Str::uuid() . '.webp';
        $binario = (string) (new ImageManager())->canvas(24, 24, '#382836')->encode('webp');
        $origen = storage_path('app/public/' . $nombre);
        file_put_contents($origen, $binario);

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
                'prompt' => 'Seis botellas', 'tamano' => 'cuadrada', 'estado' => 'lista', 'path' => $path,
            ]);

            $contexto = ContextoDeCargaIa::de_la_conversacion($conversacion);
            $respuesta = PropuestaFotoArticuloIaHelper::proponer_generada($contexto, $mensaje, [
                'pedido_imagen_uuid' => $pedido->uuid, 'como_principal' => true,
            ]);

            $this->assertSame(1, $articulo->images()->count());
            $url = '/api/ai-conversations/' . $conversacion->id . '/acciones/' . $respuesta['tarjeta_id'] . '/confirmar';

            if ($cambio) {
                $articulo->update(['presentacion' => 12]);
                $this->postJson($url, [], $this->de_la_app())->assertStatus(422);
                $this->assertSame(1, $articulo->images()->count());
                $this->assertNull($pedido->fresh()->publicada_image_id);
                return;
            }

            $this->postJson($url, [], $this->de_la_app())->assertOk();
            $this->postJson($url, [], $this->de_la_app())->assertStatus(409);
            $this->assertSame(2, $articulo->images()->count());
            $this->assertTrue($articulo->images()->whereKey($original->id)->exists());
            $this->assertSame((int) $pedido->fresh()->publicada_image_id, (int) PropuestaFotoArticuloIaHelper::portada_del_articulo($articulo->id)->id);
        } finally {
            foreach ($articulo->images()->get() as $imagen) {
                $archivo = PropuestaFotoArticuloIaHelper::archivo_de_la_imagen($imagen)['ruta'];
                if ($archivo && is_file($archivo)) {
                    unlink($archivo);
                }
            }
            if (is_file($origen)) {
                unlink($origen);
            }
        }
    }

    public function escenarios_de_publicacion(): array
    {
        return ['sin cambios' => [false], 'cambió la presentación' => [true]];
    }
}
