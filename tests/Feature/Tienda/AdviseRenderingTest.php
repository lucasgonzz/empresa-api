<?php

namespace Tests\Feature\Tienda;

use App\Http\Controllers\Helpers\ColorDeMailHelper;
use App\Http\Controllers\Helpers\ImagenParaMailHelper;
use App\Mail\Advise;
use App\Models\Article;
use App\Models\OnlineConfiguration;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdviseImageFixture extends ImagenParaMailHelper
{
    public $requests = [];
    public $bytes;
    protected function download($url, $host, $ip)
    {
        $this->requests[] = $url;
        return $this->bytes;
    }
}

class AdviseRenderingTest extends TestCase
{
    use DatabaseTransactions;

    private $owner;
    private $article;
    private $helper;
    private $configuration;

    protected function setUp(): void
    {
        parent::setUp();
        // IP pública de fixture: sólo llega al doble download, nunca se abre un socket.
        config(['app.url' => 'https://assets.vendor.com', 'app.APP_ENV' => 'production',
            'mail.image_public_addresses' => ['assets.vendor.com' => ['8.8.8.8']],
            'mail.image_allowed_hosts' => ['assets.vendor.com']]);
        $this->owner = User::create(['name' => 'U12', 'company_name' => 'Dueño <seguro>',
            'email' => uniqid().'@example.test', 'password' => 'synthetic-only',
            'listas_de_precio' => 0, 'online' => 'https://shop.vendor.com']);
        $this->configuration = OnlineConfiguration::create(['user_id' => $this->owner->id,
            'primary_color' => '#fff', 'logo_url' => 'https://assets.vendor.com/logo.jpg',
            'default_article_image_url' => 'https://assets.vendor.com/placeholder.png',
            'avisar_ingreso_stock_por_mail' => 1]);
        $this->article = Article::create(['user_id' => $this->owner->id,
            'name' => 'Artículo <escapado>', 'slug' => 'producto', 'final_price' => 123.45]);
        $this->helper = new AdviseImageFixture();
        $this->helper->bytes = $this->fixture('png');
        app()->instance(ImagenParaMailHelper::class, $this->helper);
    }

    private function fixture($type)
    {
        // Bytes embebidos: ninguna imagen ni archivo externo durante la prueba.
        $fixtures = [
            'png' => 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFElEQVQImWPkEpFjYGBgYGBigAIAA+IAQJ4VbQYAAAAASUVORK5CYII=',
            'jpeg' => '/9j/4AAQSkZJRgABAQEAYABgAAD//gA+Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2NjIpLCBkZWZhdWx0IHF1YWxpdHkK/9sAQwAIBgYHBgUIBwcHCQkICgwUDQwLCwwZEhMPFB0aHx4dGhwcICQuJyAiLCMcHCg3KSwwMTQ0NB8nOT04MjwuMzQy/9sAQwEJCQkMCwwYDQ0YMiEcITIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy/8AAEQgAAgACAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A8RooorYyP//Z',
            'webp' => 'UklGRk4AAABXRUJQVlA4WAoAAAAQAAAAAQAAAQAAQUxQSAUAAAAAAAAAAABWUDggIgAAAHABAJ0BKgIAAgABQCYllAJ0AUAAAP78N4FFFH4jDOsAAAA=',
        ];
        return base64_decode($fixtures[$type]);

    }

    private function image()
    {
        $this->article->images()->create(['hosting_url' => 'https://assets.vendor.com/producto.jpg']);
    }

    private function send()
    {
        Mail::mailer('array')->to('buyer@example.test')->send(new Advise((object) ['id' => $this->article->id]));
        return Mail::mailer('array')->getSwiftMailer()->getTransport()->messages()->last();
    }

    public function test_dueno_persistido_no_sesion_imagen_hosting_no_placeholder_y_mime_cid_real()
    {
        $other = User::create(['name' => 'Otro', 'company_name' => 'NO ES EL DUEÑO',
            'email' => uniqid().'@example.test', 'password' => 'synthetic-only']);
        $this->actingAs($other, 'web');
        $this->image();
        $message = $this->send();
        $html = $message->getBody();
        $this->assertStringContainsString('Dueño &lt;seguro&gt;', $html);
        $this->assertStringContainsString('Artículo &lt;escapado&gt;', $html);
        $this->assertStringNotContainsString('NO ES EL DUEÑO', $html);
        $this->assertStringContainsString('https://shop.vendor.com/articulos/producto/'.$this->owner->id, $html);
        // Decisión de Lucas 23/11/2026, adenda B: sólo blancos y grises claros. El primario #fff se descarta (blanco sobre
        // la tarjeta blanca, el defecto del punto 12) y gana el secundario #fe7802, que tiene tono, con texto negro.
        $this->assertStringContainsString('background-color:#fe7802; color:#000000;', $html);
        $this->assertContains('https://assets.vendor.com/producto.jpg', $this->helper->requests);
        $this->assertNotContains('https://assets.vendor.com/placeholder.png', $this->helper->requests);
        $children = $message->getChildren();
        $this->assertCount(2, $children);
        foreach ($children as $part) {
            $this->assertSame('image/png', $part->getContentType());
            $this->assertSame('inline', $part->getDisposition());
            $this->assertStringContainsString('cid:'.$part->getId(), $html);
            $this->assertSame('image/png', getimagesizefromstring($part->getBody())['mime']);
        }
        $this->assertStringContainsString('Content-Type: image/png', $message->toString());
    }

    public function test_sin_imagen_no_reemplaza_producto_por_placeholder()
    {
        $data = (new Advise($this->article))->build()->viewData;
        $this->assertNull($data['image']);
        $this->assertCount(1, $this->helper->requests);
        $this->assertStringNotContainsString('placeholder', $this->send()->getBody());
    }

    public function test_sin_mapa_de_red_o_sin_conversion_nombre_y_sin_imagen()
    {
        $this->image();
        config(['mail.image_public_addresses' => []]);
        $message = $this->send();
        $this->assertSame([], $this->helper->requests);
        $this->assertCount(0, $message->getChildren());
        $this->assertStringContainsString('Dueño &lt;seguro&gt;', $message->getBody());
        $this->assertStringNotContainsString('cid:', $message->getBody());
    }

    public function test_imagen_corrupta_degrada_a_texto_y_no_adjunta()
    {
        $this->image();
        $this->helper->bytes = 'no es una imagen';
        $message = $this->send();
        $this->assertCount(0, $message->getChildren());
        $this->assertStringContainsString('Artículo &lt;escapado&gt;', $message->getBody());
    }

    public function test_precios_ocultos_con_listas_y_sin_precio_valido()
    {
        $this->owner->update(['listas_de_precio' => 1]);
        $data = (new Advise($this->article))->build()->viewData;
        $this->assertFalse($data['mostrar_precio']);
        $this->assertStringNotContainsString('$ 123,45', $this->send()->getBody());
        $this->owner->update(['listas_de_precio' => 0]);
        $this->assertTrue((new Advise($this->article))->build()->viewData['mostrar_precio']);
        $this->article->update(['final_price' => 0]);
        $this->assertFalse((new Advise($this->article))->build()->viewData['mostrar_precio']);
    }

    public function test_cta_insegura_se_omite_y_color_invalido_es_neutro()
    {
        $this->owner->update(['online' => 'javascript:alert(1)']);
        $this->configuration->update(['primary_color' => 'red; color:red']);
        $data = (new Advise($this->article))->build()->viewData;
        $this->assertNull($data['article_url']);
        $this->assertSame('#111827', $data['accent_color']);
        $this->assertStringNotContainsString('Ver artículo', $this->send()->getBody());
    }

    public function test_png_jpeg_webp_por_bytes_no_extension_y_alpha_conservado()
    {
        foreach (['png', 'jpeg', 'webp'] as $type) {
            $prepared = $this->helper->convert($this->fixture($type));
            $this->assertNotNull($prepared, $type);
            $this->assertSame('image/png', $prepared['mime']);
            $this->assertSame('image/png', getimagesizefromstring($prepared['bytes'])['mime']);
        }
        $im = imagecreatefromstring($this->helper->convert($this->fixture('png'))['bytes']);
        $this->assertSame(127, imagecolorsforindex($im, imagecolorat($im, 0, 0))['alpha']);
        imagedestroy($im);
        $this->assertNull($this->helper->convert('<svg/>'));
        $this->assertNull($this->helper->convert(str_repeat('x', 1048577)));
    }

    public function test_url_hosts_y_mapa_privado_no_disparan_descarga()
    {
        foreach (['//vendor.com/a', 'http://127.0.0.1/a', 'http://100.64.0.1/a',
            'http://[::1]/a', 'http://user:pass@vendor.com/a', 'file:///tmp/a',
            'https://vendor.com:8080/a', "https://vendor.com/\nx", 'http://localhost/a',
            'http://2130706433/a', 'http://192.168.1.1/a', 'http://224.0.0.1/a'] as $url) {
            $this->assertNull(ImagenParaMailHelper::publicUrl($url), $url);
        }
        $this->assertSame('https://assets.vendor.com/a', ImagenParaMailHelper::publicUrl('/a', 'https://assets.vendor.com'));
        $this->assertNull($this->helper->prepare('https://other.vendor.com/a', null, ['assets.vendor.com']));
        config(['mail.image_public_addresses' => ['assets.vendor.com' => ['127.0.0.1']]]);
        $this->assertNull($this->helper->prepare('/a', 'https://assets.vendor.com', ['assets.vendor.com']));
        $this->assertSame([], $this->helper->requests);
    }

    public function test_opt_in_apagado_no_envia_ni_borra_el_aviso()
    {
        Mail::fake();
        $this->configuration->update(['avisar_ingreso_stock_por_mail' => 0]);
        $aviso = \App\Models\Advise::create(['article_id' => $this->article->id, 'email' => 'buyer@example.test']);
        (new \App\Jobs\ProcessSendAdviseMail($aviso, $this->article))->handle();
        Mail::assertNothingSent();
        $this->assertNotNull(\App\Models\Advise::find($aviso->id));
    }

    public function test_articulo_inexistente_no_acepta_owner_del_payload()
    {
        $this->expectException(\RuntimeException::class);
        (new Advise((object) ['id' => 4294900000, 'user_id' => $this->owner->id]))->build();
    }

    public function test_limite_dimensiones_y_fallo_download_degradan()
    {
        $im = imagecreatetruecolor(4097, 1);
        ob_start();
        imagepng($im);
        $bytes = ob_get_clean();
        imagedestroy($im);
        $this->assertNull($this->helper->convert($bytes));
        $helper = new class extends ImagenParaMailHelper {
            protected function download($url, $host, $ip)
            {
                throw new \RuntimeException('timeout sintetico');
            }
        };
        $this->assertNull($helper->prepare('/a', 'https://assets.vendor.com', ['assets.vendor.com']));
        // El downloader nativo nunca sale de testing, ni con un mapa válido.
        $this->assertNull((new ImagenParaMailHelper())->prepare('/a', 'https://assets.vendor.com', ['assets.vendor.com']));
    }

    public function test_contraste_cta_claro_oscuro_intermedio()
    {
        foreach (['#fff', '#000', '#888888', '#ff0000', '#12ab34', null] as $color) {
            $p = ColorDeMailHelper::palette($color);
            $a = ColorDeMailHelper::luminance($p['background']);
            $b = ColorDeMailHelper::luminance($p['text']);
            $this->assertGreaterThanOrEqual(4.5, (max($a, $b) + .05) / (min($a, $b) + .05));
        }
    }
}
