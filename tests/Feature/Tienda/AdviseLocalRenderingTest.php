<?php
namespace Tests\Feature\Tienda;

use App\Http\Controllers\Helpers\ImagenParaMailHelper;
use App\Mail\Advise;
use App\Models\Article;
use App\Models\OnlineConfiguration;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdviseLocalRenderingTest extends TestCase
{
    use DatabaseTransactions;
    private $sandbox;
    private $original;
    private $helper;
    private $base = 'https://assets.vendor.com/storage';

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = config()->all();
        $this->sandbox = base_path('.mision/QI-E-runtime/case-'.bin2hex(random_bytes(8)));
        mkdir($this->sandbox.'/public/sub', 0777, true);
        $this->helper = new ImagenParaMailHelper();
        config(['app.url' => 'https://assets.vendor.com', 'app.APP_URL' => 'https://assets.vendor.com',
            'app.APP_ENV' => 'production', 'app.VPS' => true,
            'filesystems.disks.public' => ['driver' => 'local', 'root' => $this->sandbox.'/public', 'url' => $this->base],
            'mail.image_allowed_hosts' => [], 'mail.image_public_addresses' => []]);
        file_put_contents($this->sandbox.'/public/ok.jpg', $this->fixture('png'));
        file_put_contents($this->sandbox.'/witness.png', $this->fixture('png'));
    }

    protected function tearDown(): void
    {
        try {
            if ($this->original) { config()->set($this->original); }
            if ($this->sandbox && is_dir($this->sandbox)) { $this->removeOwn($this->sandbox); }
        } finally { parent::tearDown(); }
    }

    private function removeOwn($path)
    {
        foreach (scandir($path) as $name) {
            if ($name === '.' || $name === '..') { continue; }
            $child = $path.'/'.$name;
            if (is_dir($child) && !is_link($child)) { $this->removeOwn($child); }
            else { unlink($child); }
        }
        rmdir($path);
    }

    private function fixture($type)
    {
        $fixtures = [
            'png' => 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFElEQVQImWPkEpFjYGBgYGBigAIAA+IAQJ4VbQYAAAAASUVORK5CYII=',
            'jpeg' => '/9j/4AAQSkZJRgABAQEAYABgAAD//gA+Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2NjIpLCBkZWZhdWx0IHF1YWxpdHkK/9sAQwAIBgYHBgUIBwcHCQkICgwUDQwLCwwZEhMPFB0aHx4dGhwcICQuJyAiLCMcHCg3KSwwMTQ0NB8nOT04MjwuMzQy/9sAQwEJCQkMCwwYDQ0YMiEcITIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy/8AAEQgAAgACAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A8RooorYyP//Z',
            'webp' => 'UklGRk4AAABXRUJQVlA4WAoAAAAQAAAAAQAAAQAAQUxQSAUAAAAAAAAAAABWUDggIgAAAHABAJ0BKgIAAgABQCYllAJ0AUAAAP78N4FFFH4jDOsAAAA=',
        ];
        return base64_decode($fixtures[$type]);
    }

    private function prepare($source)
    {
        return $this->helper->prepare($source, 'https://assets.vendor.com', ['assets.vendor.com']);
    }

    private function positive()
    {
        $result = $this->prepare($this->base.'/ok.jpg');
        $this->assertNotNull($result, 'Control positivo local nativo sin mapa');
        $this->assertSame('image/png', getimagesizefromstring($result['bytes'])['mime']);
        return $result;
    }

    public function sources()
    {
        return array_map(function ($s) { return [$s]; }, ['ok.jpg', 'storage/ok.jpg', '/storage/ok.jpg',
            'public/storage/ok.jpg', 'https://assets.vendor.com/storage/ok.jpg',
            'https://assets.vendor.com/public/storage/ok.jpg', 'https://assets.vendor.com/public/public/storage/ok.jpg']);
    }

    /** @dataProvider sources */
    public function test_local_nativo_y_variantes_historicas($source)
    {
        foreach ([false, true] as $public) {
            config(['app.VPS' => !$public, 'filesystems.disks.public.url' => 'https://assets.vendor.com'.($public ? '/public' : '').'/storage',
                'mail.image_public_addresses' => 'malformado']);
            $result = $this->prepare($source);
            $this->assertNotNull($result);
            $this->assertSame('image/png', $result['mime']);
            $im = imagecreatefromstring($result['bytes']);
            $this->assertSame(127, imagecolorsforindex($im, imagecolorat($im, 0, 0))['alpha']);
            imagedestroy($im);
        }
    }

    public function invalidSources()
    {
        $paths = ['../witness.png', './ok.jpg', '%2e%2e/witness.png', '%252e%252e/witness.png', '%00ok.jpg',
            'sub%2fok.jpg', 'sub%5cok.jpg', 'sub\\ok.jpg', 'ok%.jpg', 'ok%2.jpg', 'ok.jpg?q=1', 'ok.jpg#x',
            'sub//ok.jpg', '', str_repeat('a', 4100), implode('/', array_fill(0, 65, 'a'))];
        $urls = array_map(function ($s) { return ['https://assets.vendor.com/storage/'.$s]; }, $paths);
        foreach (['file:///tmp/ok.jpg', 'data:image/png;base64,x', '//assets.vendor.com/storage/ok.jpg',
            'https://u:p@assets.vendor.com/storage/ok.jpg', 'https://assets.vendor.com:444/storage/ok.jpg',
            'http://assets.vendor.com/storage/ok.jpg', 'https://assets.vendor.com.evil.com/storage/ok.jpg',
            'https://other.vendor.com/storage/ok.jpg', 'https://assets.vendor.com/storage-other/ok.jpg',
            '/tmp/ok.jpg', "https://assets.vendor.com/storage/ok.jpg\n"] as $s) { $urls[] = [$s]; }
        return $urls;
    }

    /** @dataProvider invalidSources */
    public function test_fuente_invalida_con_control_positivo($source)
    {
        $this->positive();
        $this->assertNull($this->prepare($source));
        $spy = new class extends ImagenParaMailHelper {
            public $calls = 0;
            protected function download($url, $host, $ip) { $this->calls++; return 'unexpected'; }
        };
        config(['mail.image_public_addresses' => ['assets.vendor.com' => ['8.8.8.8']]]);
        $this->assertNull($spy->prepare($source, 'https://assets.vendor.com', ['assets.vendor.com']));
        $this->assertSame(0, $spy->calls);
    }

    public function test_subcarpeta_espacio_mas_y_base_no_convencional()
    {
        $this->positive();
        foreach (['con espacio', 'con+mas'] as $name) {
            file_put_contents($this->sandbox.'/public/sub/'.$name.'.png', $this->fixture('png'));
            $this->assertNotNull($this->prepare($this->base.'/sub/'.rawurlencode($name).'.png'));
        }
        config(['filesystems.disks.public.url' => 'https://assets.vendor.com/files/media']);
        $this->assertNotNull($this->prepare('https://assets.vendor.com/files/media/ok.jpg'));
        $this->assertNull($this->prepare('https://assets.vendor.com/files/media-other/ok.jpg'));
    }

    public function filesystemCases()
    {
        return array_map(function ($s) { return [$s]; }, ['file-in', 'file-out', 'dir-in', 'dir-out', 'broken', 'loop', 'missing', 'directory', 'fifo', 'unreadable']);
    }

    /** @dataProvider filesystemCases */
    public function test_filesystem_rechaza_sin_download($kind)
    {
        $this->positive();
        $path = $this->sandbox.'/public/bad';
        $source = $this->base.'/bad';
        if ($kind === 'file-in') { symlink('ok.jpg', $path); }
        if ($kind === 'file-out') { symlink('../witness.png', $path); }
        if ($kind === 'dir-in') { symlink('sub', $path); $source .= '/x.png'; file_put_contents($this->sandbox.'/public/sub/x.png', $this->fixture('png')); }
        if ($kind === 'dir-out') { symlink('..', $path); $source .= '/witness.png'; }
        if ($kind === 'broken') { symlink('missing', $path); }
        if ($kind === 'loop') { symlink('bad', $path); }
        if ($kind === 'directory') { mkdir($path); }
        if ($kind === 'fifo') { $this->assertTrue(posix_mkfifo($path, 0600)); }
        if ($kind === 'unreadable') {
            file_put_contents($path, $this->fixture('png')); chmod($path, 0000); clearstatcache();
            $this->assertFalse(is_readable($path), 'Ejecutar como UID no privilegiado');
        }
        $spy = new class extends ImagenParaMailHelper {
            public $calls = 0;
            protected function download($url, $host, $ip) { $this->calls++; return 'unexpected'; }
        };
        config(['mail.image_public_addresses' => ['assets.vendor.com' => ['8.8.8.8']]]);
        $this->assertNull($spy->prepare($source, null, ['assets.vendor.com']));
        $this->assertSame(0, $spy->calls);
    }

    public function invalidContents()
    {
        return [[''], ['<svg/>'], ['<html>not png</html>'], ['corrupt'], [str_repeat('x', 1048577)]];
    }

    /** @dataProvider invalidContents */
    public function test_bytes_invalidos_desde_archivo($bytes)
    {
        $this->positive();
        file_put_contents($this->sandbox.'/public/bad.png', $bytes);
        $this->assertNull($this->prepare($this->base.'/bad.png'));
    }

    public function test_dimensiones_frontera_bytes_y_formatos()
    {
        $this->positive();
        foreach (['jpeg', 'webp'] as $format) {
            file_put_contents($this->sandbox.'/public/f.bin', $this->fixture($format));
            $result = $this->prepare($this->base.'/f.bin');
            if ($format === 'webp' && !function_exists('imagecreatefromwebp')) { $this->assertNull($result); }
            else { $this->assertSame('image/png', $result['mime']); }
        }
        foreach ([[4097, 1], [2001, 2000]] as $size) {
            $im = imagecreatetruecolor($size[0], $size[1]);
            imagepng($im, $this->sandbox.'/public/large.png'); imagedestroy($im);
            $this->assertNull($this->prepare($this->base.'/large.png'));
        }
        $bytes = $this->fixture('png');
        file_put_contents($this->sandbox.'/public/exact.png', str_pad($bytes, 1048576, "\0"));
        $this->assertNotNull($this->prepare($this->base.'/exact.png'));
        file_put_contents($this->sandbox.'/public/exact.png', str_pad($bytes, 1048577, "\0"));
        $this->assertNull($this->prepare($this->base.'/exact.png'));
    }

    public function test_config_invalida_y_allowlist_con_control()
    {
        $this->positive();
        $disk = config('filesystems.disks.public');
        foreach ([['driver' => 's3'], ['root' => '/'], ['root' => $this->sandbox.'/absent'],
            ['root' => 'relative'], ['root' => 'file:///tmp'], ['url' => $this->base.'?x=1'],
            ['url' => $this->base.'#x'], ['url' => $this->base.'//'], ['url' => 'https://assets.vendor.com/a/../storage']] as $change) {
            config(['filesystems.disks.public' => array_merge($disk, $change)]);
            $this->assertNull($this->prepare($this->base.'/ok.jpg'));
        }
        config(['filesystems.disks.public' => $disk]);
        $this->assertNull($this->helper->prepare($this->base.'/ok.jpg', null, []));
        $this->assertNull($this->prepare('https://remote.vendor.com/x'));
    }

    public function test_advise_dueno_persistido_cid_alpha_y_sucesivos_sin_cache()
    {
        $this->positive();
        $owners = [];
        foreach (['A', 'B'] as $index => $label) {
            file_put_contents($this->sandbox.'/public/logo-'.$label.'.png', $this->fixture($index === 0 ? 'png' : 'jpeg'));
            $owners[] = User::create(['name' => 'QI-E '.$label, 'company_name' => 'Marca <'.$label.'>',
                'email' => uniqid().'@example.test', 'password' => 'synthetic-only', 'listas_de_precio' => 0]);
            OnlineConfiguration::create(['user_id' => $owners[$index]->id, 'logo_url' => $this->base.'/logo-'.$label.'.png',
                'default_article_image_url' => 'NEVER-PLACEHOLDER', 'avisar_ingreso_stock_por_mail' => 1]);
        }
        file_put_contents($this->sandbox.'/public/product.jpg', $this->fixture('jpeg'));
        $this->actingAs($owners[1], 'web');
        foreach ([$owners[0], $owners[1], $owners[0]] as $owner) {
            $article = Article::create(['user_id' => $owner->id, 'name' => 'Local <artículo>', 'final_price' => 123.45]);
            $article->images()->create(['hosting_url' => $this->base.($owner->id === $owners[0]->id ? '/product.jpg' : '/ok.jpg')]);
            Mail::mailer('array')->to('buyer@example.test')->send(new Advise((object) ['id' => $article->id, 'user_id' => 999999]));
            $message = Mail::mailer('array')->getSwiftMailer()->getTransport()->messages()->last();
            $this->assertStringContainsString(htmlspecialchars($owner->company_name, ENT_QUOTES, 'UTF-8'), $message->getBody());
            $this->assertStringNotContainsString('NEVER-PLACEHOLDER', $message->getBody());
            $parts = $message->getChildren(); $this->assertCount(2, $parts);
            $this->assertNotSame($parts[0]->getBody(), $parts[1]->getBody());
            foreach ($parts as $part) {
                $this->assertSame('inline', $part->getDisposition());
                $this->assertSame('image/png', $part->getContentType());
                $this->assertStringContainsString('cid:'.$part->getId(), $message->getBody());
                $this->assertSame('image/png', getimagesizefromstring($part->getBody())['mime']);
            }
            if ($owner->id === $owners[0]->id) {
                $this->assertStringNotContainsString('Marca &lt;B&gt;', $message->getBody());
                $this->assertSame($this->helper->convert($this->fixture('png'))['bytes'], $parts[0]->getBody());
                $im = imagecreatefromstring($parts[0]->getBody());
                $this->assertSame(127, imagecolorsforindex($im, imagecolorat($im, 0, 0))['alpha']); imagedestroy($im);
            } else {
                $this->assertSame($this->helper->convert($this->fixture('jpeg'))['bytes'], $parts[0]->getBody());
            }
            $article->images()->delete();
            $this->assertNull((new Advise((object) ['id' => $article->id]))->build()->viewData['image']);
        }
    }
    public function test_fallback_logo_del_mismo_dueno_precios_opt_in_y_borrado()
    {
        $this->positive();
        $owner = User::create(['name' => 'QI-E fallback', 'company_name' => 'Texto <local>',
            'email' => uniqid().'@example.test', 'password' => 'synthetic-only',
            'image_url' => $this->base.'/ok.jpg', 'listas_de_precio' => 1]);
        $config = OnlineConfiguration::create(['user_id' => $owner->id, 'logo_url' => $this->base.'/missing.png',
            'avisar_ingreso_stock_por_mail' => 0]);
        $article = Article::create(['user_id' => $owner->id, 'name' => 'QI-E fallback', 'final_price' => 120]);
        $article->images()->create(['hosting_url' => $this->base.'/bad.png']);
        file_put_contents($this->sandbox.'/public/bad.png', '<svg/>');
        \App\Models\PriceType::create(['user_id' => $owner->id, 'name' => 'Mayorista']);
        \App\Models\PriceType::create(['user_id' => $owner->id, 'name' => 'Minorista']);
        $data = (new Advise((object) ['id' => $article->id]))->build()->viewData;
        $this->assertNotNull($data['logo']);
        $this->assertNull($data['image']);
        $this->assertFalse($data['mostrar_precio']);
        $owner->update(['image_url' => null, 'listas_de_precio' => 0]);
        $data = (new Advise((object) ['id' => $article->id]))->build()->viewData;
        $this->assertNull($data['logo']);
        $this->assertTrue($data['mostrar_precio']);
        Mail::fake();
        $aviso = \App\Models\Advise::create(['article_id' => $article->id, 'email' => 'buyer@example.test']);
        (new \App\Jobs\ProcessSendAdviseMail($aviso, $article))->handle();
        Mail::assertNothingSent();
        $this->assertNotNull($aviso->fresh());
        $id = $article->id; $article->delete();
        $this->expectException(\RuntimeException::class);
        (new Advise((object) ['id' => $id, 'user_id' => $owner->id]))->build();
    }

    public function test_raiz_canonica_symlink_de_despliegue_y_salida_acotada()
    {
        $this->positive();
        symlink('public', $this->sandbox.'/deployment');
        config(['filesystems.disks.public.root' => $this->sandbox.'/deployment']);
        $this->assertNotNull($this->prepare($this->base.'/ok.jpg'));
        // Fixture procedural sintética acotada: JPEG chico cuya recodificación PNG supera el límite.
        $im = imagecreatetruecolor(700, 700);
        $seed = 17;
        for ($y = 0; $y < 700; $y++) {
            for ($x = 0; $x < 700; $x++) {
                $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
                imagesetpixel($im, $x, $y, $seed & 0xffffff);
            }
        }
        $path = $this->sandbox.'/public/noisy.jpg';
        imagejpeg($im, $path, 85); imagedestroy($im);
        $bytes = file_get_contents($path);
        $this->assertLessThanOrEqual(1048576, strlen($bytes));
        $decoded = imagecreatefromstring($bytes);
        ob_start(); imagepng($decoded); $png = ob_get_clean(); imagedestroy($decoded);
        $this->assertGreaterThan(1048576, strlen($png));
        $this->assertNull($this->prepare($this->base.'/noisy.jpg'));
    }

    public function test_remoto_fuera_del_namespace_conserva_pinning_y_local_invalido_no_escapa()
    {
        $this->positive();
        $spy = new class extends ImagenParaMailHelper {
            public $calls = [];
            public $fixture;
            protected function download($url, $host, $ip) { $this->calls[] = [$url, $host, $ip]; return $this->fixture; }
        };
        $spy->fixture = $this->fixture('png');
        $url = 'https://assets.vendor.com/remote/logo.jpg';
        $this->assertNull($spy->prepare($url, null, ['assets.vendor.com']));
        $this->assertSame([], $spy->calls);
        config(['mail.image_public_addresses' => ['assets.vendor.com' => ['8.8.8.8']]]);
        $this->assertNotNull($spy->prepare($url, null, ['assets.vendor.com']));
        $this->assertSame([[$url, 'assets.vendor.com', '8.8.8.8']], $spy->calls);
        $this->assertNull($spy->prepare($this->base.'/missing.png', null, ['assets.vendor.com']));
        $this->assertCount(1, $spy->calls);
        config(['filesystems.disks.public.driver' => 's3']);
        $this->assertNotNull($spy->prepare($url, null, ['assets.vendor.com']));
        $this->assertCount(2, $spy->calls);
    }

}
