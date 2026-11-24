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

/**
 * Doble del helper de imágenes: sin red real. Registra las descargas pedidas y devuelve
 * los bytes y las direcciones IP que cada test le indique.
 */
class AdviseTruvariImageFixture extends ImagenParaMailHelper
{
    /** @var array Descargas pedidas: cada una con url, host e ip. */
    public $downloads = [];

    /** @var string|null Bytes que devuelve la "descarga". */
    public $bytes;

    /** @var array Direcciones IP con las que "resuelve" cualquier host. */
    public $addresses = [];

    /** @var array Hosts que se pidió resolver. */
    public $resolved = [];

    protected function download($url, $host, $ip)
    {
        $this->downloads[] = ['url' => $url, 'host' => $host, 'ip' => $ip];
        return $this->bytes;
    }

    protected function resolveHost($host)
    {
        $this->resolved[] = $host;
        return $this->addresses;
    }
}

/**
 * Corrección A (pedido de Truvari) sobre el mail "Volvió el stock": botón visible con un primario
 * casi blanco, logo WEBP enorme y foto de producto pesada que antes no se adjuntaban.
 * Todo con datos y bytes sintéticos, sin red.
 */
class AdviseTruvariTest extends TestCase
{
    use DatabaseTransactions;

    /** Host de empresa-api de Truvari (donde viven el logo y las fotos). */
    const HOST = 'api-truvari.comerciocity.com';

    /** @var string Carpeta temporal que hace de disco público. */
    private $sandbox;

    /** @var array Configuración original para restaurarla al terminar. */
    private $original;

    private $owner;
    private $article;
    private $configuration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = config()->all();
        $this->sandbox = storage_path('framework/testing/ca3-'.bin2hex(random_bytes(8)));
        mkdir($this->sandbox.'/public', 0777, true);
        // Disco público local en el mismo host que la tienda de Truvari (hosting compartido: /public/storage).
        config(['app.url' => 'https://'.self::HOST, 'app.APP_URL' => 'https://'.self::HOST,
            'app.APP_ENV' => 'production',
            'filesystems.disks.public' => ['driver' => 'local', 'root' => $this->sandbox.'/public',
                'url' => 'https://'.self::HOST.'/public/storage'],
            'mail.image_allowed_hosts' => [], 'mail.image_public_addresses' => []]);
        $this->owner = User::create(['name' => 'Truvari', 'company_name' => 'Truvari Bebidas',
            'email' => uniqid().'@example.test', 'password' => 'synthetic-only',
            'listas_de_precio' => 0, 'online' => 'https://tienda.truvari-bebidas.com']);
        // Configuración real de Truvari leída de su API pública el 23/11.
        $this->configuration = OnlineConfiguration::create(['user_id' => $this->owner->id,
            'primary_color' => '#E9E7EA', 'secondary_color' => '#250237',
            'add_to_cart_button_color' => '#250237', 'text_color' => '#464646',
            'hover_text_color' => '#FFF', 'background_color' => '#FFFFFF',
            'avisar_ingreso_stock_por_mail' => 1]);
        $this->article = Article::create(['user_id' => $this->owner->id,
            'name' => 'Vino Malbec', 'slug' => 'vino-malbec', 'final_price' => 1500]);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->original) {
                config()->set($this->original);
            }
            if ($this->sandbox && is_dir($this->sandbox)) {
                $this->removeOwn($this->sandbox);
            }
        } finally {
            parent::tearDown();
        }
    }

    /** Borra recursivamente la carpeta temporal propia del test. */
    private function removeOwn($path)
    {
        foreach (scandir($path) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $child = $path.'/'.$name;
            if (is_dir($child) && !is_link($child)) {
                $this->removeOwn($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }

    /** Logo real de Truvari: WEBP con alpha de 3590x3590, esquina transparente y un cuadrado opaco al medio. */
    private function big_webp_logo()
    {
        $size = 3590;
        $im = imagecreatetruecolor($size, $size);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledrectangle($im, 900, 900, 2690, 2690, imagecolorallocatealpha($im, 37, 2, 55, 0));
        ob_start();
        imagewebp($im);
        $bytes = ob_get_clean();
        imagedestroy($im);
        return $bytes;
    }

    /** Foto de producto 1533x1533 con ruido por bloque: el PNG a tamaño completo pesa más de 1 MB. */
    private function noisy_jpeg()
    {
        mt_srand(7);
        $size = 1533;
        $im = imagecreatetruecolor($size, $size);
        for ($by = 0; $by < $size; $by += 8) {
            for ($bx = 0; $bx < $size; $bx += 8) {
                $base = [mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)];
                for ($y = $by; $y < min($size, $by + 8); $y++) {
                    for ($x = $bx; $x < min($size, $bx + 8); $x++) {
                        $c = [];
                        foreach ($base as $v) {
                            $c[] = max(0, min(255, $v + mt_rand(-30, 30)));
                        }
                        imagesetpixel($im, $x, $y, imagecolorallocate($im, $c[0], $c[1], $c[2]));
                    }
                }
            }
        }
        ob_start();
        imagejpeg($im, null, 60);
        $jpeg = ob_get_clean();
        ob_start();
        imagepng(imagecreatefromstring($jpeg));
        $png_full_size = ob_get_clean();
        imagedestroy($im);
        // Control del propio test: a tamaño completo el PNG supera el tope de 1 MB y el JPEG entra en el disco local.
        $this->assertGreaterThan(1048576, strlen($png_full_size));
        $this->assertLessThanOrEqual(1048576, strlen($jpeg));
        return $jpeg;
    }

    /** Guarda bytes en el disco público temporal y devuelve su URL pública. */
    private function store($name, $bytes)
    {
        file_put_contents($this->sandbox.'/public/'.$name, $bytes);
        return 'https://'.self::HOST.'/public/storage/'.$name;
    }

    private function send()
    {
        Mail::mailer('array')->to('buyer@example.test')->send(new Advise((object) ['id' => $this->article->id]));
        return Mail::mailer('array')->getSwiftMailer()->getTransport()->messages()->last();
    }

    private function remote_helper()
    {
        $helper = new AdviseTruvariImageFixture();
        $helper->bytes = $this->big_webp_logo();
        return $helper;
    }

    /** CA3.1 Un primario casi blanco no se usa de fondo: gana el siguiente candidato que contrasta. */
    public function test_paleta_prefiere_el_candidato_que_contrasta()
    {
        $this->assertSame(['background' => '#250237', 'text' => '#ffffff'],
            ColorDeMailHelper::palette('#E9E7EA', array('#250237', '#250237')));
        $this->assertSame('#c5111d', ColorDeMailHelper::palette('#c5111d', array('#250237'))['background']);
        // Decisión de Lucas 23/11/2026, adenda B: sólo blancos y grises claros. El secundario #fe7802 (naranja con tono,
        // 2,6:1 contra blanco) ya es un fondo aceptable: antes caía al neutro #111827.
        $this->assertSame('#fe7802', ColorDeMailHelper::palette('red;position:fixed', array(null, '#fe7802'))['background']);
        $this->assertSame('#111827', ColorDeMailHelper::palette('#E9E7EA')['background']);
        // Orden de candidatos: primario, botón de agregar al carrito, secundario; los inválidos no cuentan.
        $this->assertSame('#123456', ColorDeMailHelper::palette('#E9E7EA', array('#FFEEEE', '#123456'))['background']);
        // Decisión de Lucas 23/11/2026, adenda B: sólo blancos y grises claros (el inválido no cuenta y gana #fe7802).
        $this->assertSame('#fe7802', ColorDeMailHelper::palette('#E9E7EA', array('bad', '#fe7802'))['background']);
    }

    /** Casos de la tabla de la adenda B: [primario, botón carrito, secundario, fondo esperado, texto esperado]. */
    public function adenda_b_cases()
    {
        return array(
            'truvari' => array('#E9E7EA', '#250237', '#250237', '#250237', '#ffffff'),
            'naranja' => array('#FF9800', null, '#fe7802', '#FF9800', '#000000'),
            'verde' => array('#4CAF50', null, null, '#4CAF50', '#000000'),
            'amarillo' => array('#FFC107', null, null, '#FFC107', '#000000'),
            'celeste' => array('#03A9F4', null, null, '#03A9F4', '#000000'),
            'naranja por defecto' => array('#fe7802', null, null, '#fe7802', '#000000'),
            'gris claro con secundario' => array('#CCCCCC', null, '#250237', '#250237', '#ffffff'),
            'gris claro sin nada' => array('#CCCCCC', null, null, '#111827', '#ffffff'),
            'amarillo casi blanco' => array('#FFF9C4', null, '#fe7802', '#fe7802', '#000000'),
            'gris 777777' => array('#777777', null, null, '#777777', '#000000'),
            'rojo' => array('#c5111d', null, null, '#c5111d', '#ffffff'),
            'blanco corto' => array('#fff', null, '#fe7802', '#fe7802', '#000000'),
            'con espacios' => array('  #4CAF50 ', null, null, '  #4CAF50 ', '#000000'),
        );
    }

    /**
     * CA3.1 bis (adenda B): sólo los casi blancos y los grises claros se reemplazan; los colores con tono se respetan.
     *
     * @dataProvider adenda_b_cases
     */
    public function test_paleta_adenda_b_solo_reemplaza_blancos_y_grises_claros($primary, $cart, $secondary, $background, $text)
    {
        $palette = ColorDeMailHelper::palette($primary, array($cart, $secondary));
        // El fondo se devuelve normalizado (trim), con las mayúsculas originales.
        $this->assertSame(trim($background), $palette['background']);
        $this->assertSame($text, $palette['text']);
    }

    /** CA3.2 El mail "Volvió el stock" usa el fondo oscuro de la marca para "Ver artículo". */
    public function test_el_boton_ver_articulo_usa_el_color_oscuro_de_la_marca()
    {
        $data = (new Advise($this->article))->build()->viewData;
        $this->assertSame('#250237', $data['accent_color']);
        $this->assertSame('#ffffff', $data['cta_text_color']);
        $html = $this->send()->getBody();
        $this->assertStringContainsString('https://tienda.truvari-bebidas.com/articulos/vino-malbec/'.$this->owner->id, $html);
        $this->assertStringContainsString('background-color:#250237; color:#ffffff;', $html);
        $this->assertStringNotContainsString('background-color:#E9E7EA', $html);
    }

    /** CA3.3 El logo WEBP de 3590x3590 con alpha sale del disco local, reducido a 440 px y con transparencia. */
    public function test_logo_webp_enorme_se_reduce_y_conserva_alpha()
    {
        $webp = $this->big_webp_logo();
        $this->assertLessThanOrEqual(1048576, strlen($webp));
        $this->configuration->update(['logo_url' => $this->store('179011714449320.webp', $webp)]);
        $logo = (new Advise($this->article))->build()->viewData['logo'];
        $this->assertNotNull($logo);
        $this->assertSame('image/png', $logo['mime']);
        $info = getimagesizefromstring($logo['bytes']);
        $this->assertSame('image/png', $info['mime']);
        $this->assertLessThanOrEqual(440, max($info[0], $info[1]));
        $im = imagecreatefromstring($logo['bytes']);
        $this->assertSame(127, imagecolorsforindex($im, imagecolorat($im, 0, 0))['alpha']);
        imagedestroy($im);
    }

    /** CA3.4 Una foto 1533x1533 pesada llega reducida a 192 px y el mail trae logo y foto adjuntos. */
    public function test_foto_pesada_se_reduce_y_el_mail_trae_logo_y_foto()
    {
        $this->configuration->update(['logo_url' => $this->store('logo.webp', $this->big_webp_logo())]);
        $this->article->images()->create(['hosting_url' => $this->store('foto.jpg', $this->noisy_jpeg())]);
        $data = (new Advise($this->article))->build()->viewData;
        $this->assertNotNull($data['image']);
        $this->assertSame('image/png', $data['image']['mime']);
        $info = getimagesizefromstring($data['image']['bytes']);
        $this->assertSame('image/png', $info['mime']);
        $this->assertLessThanOrEqual(192, max($info[0], $info[1]));
        $this->assertLessThanOrEqual(1048576, strlen($data['image']['bytes']));
        $message = $this->send();
        $this->assertCount(2, $message->getChildren());
        foreach ($message->getChildren() as $part) {
            $this->assertSame('inline', $part->getDisposition());
            $this->assertSame('image/png', getimagesizefromstring($part->getBody())['mime']);
        }
    }

    /** CA3.5 Un host de comerciocity.com se descarga sólo si resuelve a IP pública, sin redirecciones ni red real. */
    public function test_foto_remota_por_sufijo_solo_con_ip_publica()
    {
        $helper = $this->remote_helper();
        $helper->addresses = ['86.38.190.205'];
        $prepared = $helper->prepare('https://cdn.comerciocity.com/a.webp', 'https://'.self::HOST, array());
        $this->assertNotNull($prepared);
        $this->assertSame('image/png', $prepared['mime']);
        $this->assertLessThanOrEqual(440, max(array_slice(getimagesizefromstring($prepared['bytes']), 0, 2)));
        $this->assertCount(1, $helper->downloads);
        $this->assertSame('cdn.comerciocity.com', $helper->downloads[0]['host']);
        $this->assertSame('86.38.190.205', $helper->downloads[0]['ip']);

        foreach ([array('10.0.0.5'), array('86.38.190.205', '192.168.0.1'), array()] as $addresses) {
            $blocked = $this->remote_helper();
            $blocked->addresses = $addresses;
            $this->assertNull($blocked->prepare('https://cdn.comerciocity.com/a.webp', 'https://'.self::HOST, array()));
            $this->assertCount(0, $blocked->downloads);
        }
        // Host parecido, sin TLS o con puerto: se rechaza sin resolver ni descargar.
        foreach (['https://cdn.comerciocity.com.evil.com/a.webp', 'https://evilcomerciocity.com/a.webp',
            'http://cdn.comerciocity.com/a.webp', 'https://cdn.comerciocity.com:8443/a.webp'] as $url) {
            $other = $this->remote_helper();
            $other->addresses = array('86.38.190.205');
            $this->assertNull($other->prepare($url, 'https://'.self::HOST, array()), $url);
            $this->assertCount(0, $other->downloads, $url);
            $this->assertSame(array(), $other->resolved, $url);
        }
    }
}
