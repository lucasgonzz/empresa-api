<?php

namespace Tests\Feature\Sales;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ticket 2 (18/9/2026) — logo del negocio en el header del Ticket 2.0.
 *
 * `GET sale/{sale_id}/ticket-2-logo` es lo que el mixin `print_ticket/index.js` del SPA
 * consulta para agregar el logo a `content` antes de imprimir. Estos tests miden el
 * endpoint y no la conversion a bitmap en si (esa la mide, indirectamente, la firma
 * `GS v 0` del caso feliz): el detalle pixel a pixel del empaquetado queda para quien
 * toque `SaleTicketRasterHelper::pack_raster_bit_image()` de nuevo.
 *
 * Lo que se protege, en orden de gravedad:
 *  1. Que scopee por user_id, igual que el resto de las rutas de sale/{sale_id}/... — sin
 *     esto, un id ajeno filtraria el logo (y la venta) de otro comercio.
 *  2. Que sin logo cargado (ni en la sucursal ni en el negocio) conteste 200 con
 *     has_logo: false, y no un error: el Ticket 2.0 tiene que poder imprimir igual.
 *  3. Que con un logo real accesible localmente arme bytes ESC/POS que empiecen con la
 *     firma de `GS v 0` (0x1D 0x76 0x30 0x00, modo normal) — el contrato que consume
 *     `agregar_logo_ticket()` del lado del SPA.
 *
 * DatabaseTransactions sobre la base sembrada del slot (empresa_testing_s20), calcado del
 * resto de tests/Feature/Sales/: no hay RefreshDatabase porque vaciaria la base compartida
 * con el resto de la suite.
 *
 * El fixture del caso feliz reusa la convencion de tests/Feature/Pdf/1_Resolucion_De_Imagen_Para_Pdf_Test.php:
 * un JPG real generado con GD bajo storage/app/public/, referenciado por una URL con
 * "/storage/" en el path — asi `GeneralHelper::pdf_image_path()` lo resuelve LOCAL, sin
 * salir a la red (reforzado acá con Http::fake() como red de contencion).
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class TicketLogoRasterTest extends TestCase
{
    use DatabaseTransactions;

    /** @var int Usuario del fixture de testing. */
    const USER_ID = 500;

    /** @var \App\Models\User */
    protected $user;

    /** Archivos creados por el test, para borrarlos en tearDown(). */
    protected $archivos_creados = [];

    /** Directorios creados por el test, para borrarlos en tearDown(). */
    protected $directorios_creados = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::find(self::USER_ID);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        // Estado limpio a proposito: si la base del slot ya trae un logo cargado para el
        // 500, el caso "sin logo" mediria otra cosa. Vive dentro de la transaccion del test.
        $this->user->image_url = null;
        $this->user->save();

        $this->archivos_creados = [];
        $this->directorios_creados = [];

        $this->actingAs($this->user, 'web');
    }

    protected function tearDown(): void
    {
        foreach ($this->archivos_creados as $archivo) {
            if (is_file($archivo)) {
                @unlink($archivo);
            }
        }

        foreach (array_reverse($this->directorios_creados) as $directorio) {
            if (is_dir($directorio)) {
                @rmdir($directorio);
            }
        }

        parent::tearDown();
    }

    /**
     * Clona el usuario de pruebas para el caso de la venta ajena (misma tecnica que
     * tests/Feature/Sales/8_Abrir_venta_por_id_Test.php): un id inventado no pasaria la FK
     * de sales.user_id, asi que el test mediria la FK y no el scope del endpoint.
     *
     * @return \App\Models\User
     */
    protected function otro_comercio()
    {
        $otro = User::find(self::USER_ID)->replicate();
        $otro->email = 'zz_test_otro_comercio_ticket_logo_raster@ejemplo.test';
        $otro->articles_export_key = null;
        $otro->save();

        return $otro;
    }

    /**
     * @param  int  $user_id
     * @param  array  $overrides
     * @return \App\Models\Sale
     */
    protected function crear_venta($user_id = self::USER_ID, $overrides = [])
    {
        return Sale::create(array_merge([
            'user_id'                    => $user_id,
            'client_id'                  => null,
            'omitir_en_cuenta_corriente' => 0,
            'save_current_acount'        => 0,
            'terminada'                  => 1,
            'is_cerrada'                 => 0,
            'caja_id'                    => null,
            'sub_total'                  => 250,
            'total'                      => 250,
        ], $overrides));
    }

    /**
     * @param  int  $sale_id
     * @param  int  $ancho_mm
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedir_logo($sale_id, $ancho_mm = 80)
    {
        return $this->getJson('api/sale/'.$sale_id.'/ticket-2-logo?ancho_mm='.$ancho_mm);
    }

    /**
     * Crea un JPG real de 10x10 bajo storage/app/public/ y lo agenda para borrar. Calcado
     * de tests/Feature/Pdf/1_Resolucion_De_Imagen_Para_Pdf_Test.php.
     *
     * @param  string  $ruta_relativa
     * @return string
     */
    protected function crear_jpg($ruta_relativa)
    {
        $ruta = $this->preparar_ruta($ruta_relativa);
        $imagen = imagecreatetruecolor(120, 60);
        // Negro solido: con blanco puro el PNG/JPG de 1x1 podria comprimir a "todo blanco"
        // y el binarizador (gray < 128) daria un raster todo en cero, un fixture demasiado
        // debil para distinguir "empaqueto bien" de "empaqueto todo apagado".
        $negro = imagecolorallocate($imagen, 0, 0, 0);
        imagefilledrectangle($imagen, 0, 0, 120, 60, $negro);
        imagejpeg($imagen, $ruta, 90);
        imagedestroy($imagen);
        $this->archivos_creados[] = $ruta;

        return $ruta;
    }

    /**
     * @param  string  $ruta_relativa
     * @return string
     */
    protected function preparar_ruta($ruta_relativa)
    {
        $ruta = storage_path('app/public/'.$ruta_relativa);
        $directorio = dirname($ruta);

        if (! is_dir($directorio)) {
            mkdir($directorio, 0775, true);
            $this->directorios_creados[] = $directorio;
        }

        return $ruta;
    }

    /**
     * Sin logo cargado ni en la sucursal ni en el negocio: 200, has_logo false. El Ticket
     * 2.0 tiene que poder imprimir igual, sin logo, como se banco siempre hasta ahora.
     *
     * @group sales
     * @test
     */
    public function venta_sin_logo_responde_has_logo_false()
    {
        $venta = $this->crear_venta();

        $respuesta = $this->pedir_logo($venta->id);

        $respuesta->assertStatus(200);
        $this->assertFalse((bool) $respuesta->json('has_logo'));
    }

    /**
     * La venta de OTRO comercio no se devuelve, aunque el id exista: mismo criterio de
     * scope que el resto de las rutas de sale/{sale_id}/... — sin esto, un id ajeno
     * filtraria el logo (y la existencia) de la venta de otro comercio.
     *
     * @group sales
     * @test
     */
    public function venta_de_otro_comercio_responde_404()
    {
        $ajena = $this->crear_venta($this->otro_comercio()->id);

        $respuesta = $this->pedir_logo($ajena->id);

        $respuesta->assertStatus(404);
    }

    /**
     * Un id que no existe tambien es 404, igual que una venta ajena: no hay diferencia
     * observable entre "no es tuya" y "no existe", que es la postura correcta de seguridad.
     *
     * @group sales
     * @test
     */
    public function venta_inexistente_responde_404()
    {
        $inexistente = Sale::max('id') + 100000;

        $respuesta = $this->pedir_logo($inexistente);

        $respuesta->assertStatus(404);
    }

    /**
     * La ruta exige autenticacion. Barato y es lo unico que protege de que alguien la
     * mueva fuera del grupo de auth:sanctum sin darse cuenta.
     *
     * @group sales
     * @test
     */
    public function la_ruta_exige_autenticacion()
    {
        \Illuminate\Support\Facades\Auth::logout();

        $venta = $this->crear_venta();

        $respuesta = $this->getJson('api/sale/'.$venta->id.'/ticket-2-logo');

        $respuesta->assertStatus(401);
    }

    /**
     * 🔴 EL CASO FELIZ: con un logo real y accesible localmente, el endpoint arma bytes
     * ESC/POS que empiezan con la firma de GS v 0 (comando 0x1D 0x76 0x30, modo normal
     * 0x00) — el contrato exacto que agregar_logo_ticket() del SPA agrega a `content` sin
     * inspeccionar nada mas.
     *
     * @group sales
     * @test
     */
    public function venta_con_logo_responde_has_logo_true_con_raster_gs_v_0()
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            $this->markTestSkipped('La extension GD no esta disponible en este entorno.');
        }

        // Red de contencion: este camino tiene que resolver LOCAL. Si algo lo manda a la
        // red, Http::fake() sin reglas corta con una excepcion en vez de un timeout mudo.
        Http::fake();

        $prefijo = 'test_ticket_logo_'.uniqid();
        $this->crear_jpg($prefijo.'.jpg');

        $this->user->image_url = 'https://api-cliente.comerciocity.com/storage/'.$prefijo.'.jpg';
        $this->user->save();

        $venta = $this->crear_venta();

        $respuesta = $this->pedir_logo($venta->id, 80);

        $respuesta->assertStatus(200);
        $this->assertTrue((bool) $respuesta->json('has_logo'), 'Con un logo cargado y accesible, has_logo tiene que ser true.');

        $raster_base64 = $respuesta->json('raster_base64');
        $this->assertNotNull($raster_base64, 'Con has_logo true tiene que venir el raster.');

        $bytes = base64_decode($raster_base64);

        $this->assertNotFalse($bytes);
        $this->assertGreaterThan(8, strlen($bytes), 'El raster tiene que traer header + al menos un byte de datos.');

        $firma = substr($bytes, 0, 4);
        $this->assertSame("\x1D\x76\x30\x00", $firma, 'El raster tiene que empezar con GS v 0 en modo normal.');

        // xL/xH del primer header: ancho_total_dots / 8. Con ancho_mm=80, TICKET_WIDTH da
        // 48 caracteres -> 576 puntos -> 72 bytes de ancho (ya alineado a 8, sin resto).
        $xL = ord($bytes[4]);
        $xH = ord($bytes[5]);
        $width_bytes = $xL + ($xH * 256);
        $this->assertSame(72, $width_bytes, 'El ancho empaquetado tiene que coincidir con TICKET_WIDTH a 80mm.');
    }
}
