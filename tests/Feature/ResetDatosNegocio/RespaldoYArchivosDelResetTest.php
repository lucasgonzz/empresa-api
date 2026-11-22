<?php

namespace Tests\Feature\ResetDatosNegocio;

use Illuminate\Support\Facades\DB;
use Tests\Feature\ResetDatosNegocio\Concerns\BaseDescartableParaElReset;
use Tests\TestCase;

/**
 * CA6 y CA7 — El respaldo previo y los archivos del reset de datos de negocio (misión reset-datos-cliente).
 *
 * CA6: antes de vaciar nada, el mismo proceso que borra escribe un volcado SQL comprimido de lo que va a vaciar;
 * si no puede, no borra. Un volcado que no se puede cargar no es un respaldo, así que el test lo RESTAURA en una
 * base aparte y cuenta las filas.
 * CA7: las imágenes de los artículos y las carpetas con ids de negocio (adjuntos de ventas, escaneos...) se MUEVEN
 * a una carpeta de respaldo, nunca se borran; los archivos que no son de negocio (un logo) no se tocan.
 *
 * Corre sobre la base descartable y con el storage redirigido (ver BaseDescartableParaElReset).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe, argumentos nombrados ni union types.
 */
class RespaldoYArchivosDelResetTest extends TestCase
{
    use BaseDescartableParaElReset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->iniciar_entorno_del_reset();
    }

    protected function tearDown(): void
    {
        $this->terminar_entorno_del_reset();
        parent::tearDown();
    }

    /**
     * Los respaldos `.sql.gz` que hay en la carpeta de respaldos del reset.
     *
     * @return array Rutas absolutas, ordenadas por nombre.
     */
    protected function respaldos_sql()
    {
        $archivos = glob(storage_path('app/respaldos-reset-datos') . '/*.sql.gz');
        $archivos = $archivos === false ? [] : $archivos;
        sort($archivos);

        return $archivos;
    }

    /**
     * Carpetas `<stamp>-archivos` donde el reset deja los archivos movidos.
     *
     * @return array Rutas absolutas.
     */
    protected function carpetas_de_archivos_movidos()
    {
        $carpetas = glob(storage_path('app/respaldos-reset-datos') . '/*-archivos', GLOB_ONLYDIR);

        return $carpetas === false ? [] : $carpetas;
    }

    /** @test */
    public function test_el_respaldo_existe_y_trae_lo_vaciado()
    {
        $con_filas = $this->tablas_a_vaciar_con_filas();
        $caja_antes = (array) DB::table('cajas')->orderBy('id')->first();
        $this->assertEquals(1500, (float) $caja_antes['saldo']);
        $cantidad_de_users = DB::table('users')->count();
        $cantidad_de_addresses = DB::table('addresses')->count();
        $cantidad_de_discounts = DB::table('discounts')->count();
        $cantidad_de_tokens = DB::table('personal_access_tokens')->count();

        $respuesta = $this->simular_y_ejecutar_el_reset();
        $respuesta->assertStatus(200);

        // Un único respaldo, con el nombre <AAAAmmdd-HHiiss>-<base>.sql.gz, fuera de `public`.
        $respaldos = $this->respaldos_sql();
        $this->assertCount(1, $respaldos);
        $archivo = $respaldos[0];
        $this->assertMatchesRegularExpression('/^\d{8}-\d{6}-' . preg_quote($this->base_descartable, '/') . '\.sql\.gz$/', basename($archivo));
        $this->assertStringNotContainsString('/app/public/', $archivo);

        // La respuesta lo describe: bytes y sha256 coinciden con el archivo real.
        $respaldo = $respuesta->json('respaldo');
        $this->assertGreaterThan(0, $respaldo['bytes']);
        $this->assertSame(filesize($archivo), $respaldo['bytes']);
        $this->assertSame(hash_file('sha256', $archivo), $respaldo['sha256']);
        $this->assertSame(basename($archivo), basename($respaldo['ruta_relativa']));

        // Descomprimido trae el esquema y las filas.
        $sql = gzdecode(file_get_contents($archivo));
        $this->assertNotFalse($sql);
        $this->assertStringContainsString('CREATE TABLE `articles`', $sql);
        $this->assertStringContainsString('INSERT INTO `articles`', $sql);

        // Y se puede cargar: restaurado en otra base, trae las filas de todo lo vaciado.
        $this->restaurar_el_respaldo($archivo);
        $this->assertSame(3, $this->filas_restauradas('articles'));
        foreach ($con_filas as $tabla => $filas) {
            $this->assertSame($filas, $this->filas_restauradas($tabla), 'Filas restauradas de ' . $tabla);
        }

        // También las tablas que se conservan pero se ajustan: el respaldo las trae como estaban ANTES del ajuste.
        $this->assertSame($cantidad_de_users, $this->filas_restauradas('users'));
        $this->assertSame($cantidad_de_addresses, $this->filas_restauradas('addresses'));
        $this->assertSame($cantidad_de_discounts, $this->filas_restauradas('discounts'));
        $this->assertSame($cantidad_de_tokens, $this->filas_restauradas('personal_access_tokens'));
        $caja_restaurada = (array) DB::table($this->base_de_restauracion . '.cajas')->orderBy('id')->first();
        $this->assertEquals(1500, (float) $caja_restaurada['saldo'], 'El respaldo guarda la caja con su saldo anterior.');
        $this->assertSame(1, (int) $caja_restaurada['abierta']);
        $this->assertSame(0, (int) DB::table($this->base_de_restauracion . '.users')->where('id', $this->id_del_dueno)->value('eliminar_articulos_offline'));
        $this->assertSame(7, (int) DB::table($this->base_de_restauracion . '.users')->where('id', $this->id_del_dueno)->value('agente_aviso_stock_cursor'));

        // Y mientras tanto la base viva quedó vacía de negocio (el respaldo no es una excusa para no borrar).
        $this->assertSame(0, DB::table('articles')->count());
    }

    /** @test */
    public function test_si_el_respaldo_falla_no_se_borra_nada()
    {
        // La simulación sale bien: es la ejecución la que se encuentra sin donde escribir el respaldo.
        $token = $this->obtener_un_token_de_simulacion();
        $foto = $this->foto_de_las_tablas();

        // El directorio de respaldos no se puede crear ni escribir: en su lugar hay un archivo común (esto falla
        // también corriendo como root, a diferencia de un chmod).
        file_put_contents(storage_path('app/respaldos-reset-datos'), 'esto es un archivo, no una carpeta');

        $respuesta = $this->ejecutar_el_reset($token);

        $respuesta->assertStatus(500);
        $respuesta->assertJson(['ok' => false, 'error' => 'respaldo_fallido']);
        $this->assertNotEmpty($respuesta->json('mensaje'));

        // Nada se vació: ni las tablas, ni los ajustes, ni los archivos.
        $this->assertBaseIntacta($foto, 'Si el respaldo falla no se borra nada.');
        $this->assertSame(3, DB::table('articles')->count());
        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_type', 'like', '%Buyer')->count());
        $this->assertSame(0, (int) DB::table('users')->where('id', $this->id_del_dueno)->value('eliminar_articulos_offline'));
        $this->assertNotNull($this->buscar_archivo_con_contenido(storage_path('app/public'), $this->contenido_de_la_imagen));
        $this->assertNotNull($this->buscar_archivo_con_contenido(storage_path('app/vender-items'), $this->contenido_del_adjunto));
        $this->assertCandadoLibre();

        // El intento fallido queda registrado.
        $codigos = $this->filas_del_registro()->pluck('codigo')->all();
        $this->assertContains('respaldo_fallido', $codigos);
    }

    /** @test */
    public function test_la_retencion_conserva_los_tres_respaldos_mas_nuevos()
    {
        $token = $this->obtener_un_token_de_simulacion();

        // Cuatro respaldos viejos de corridas anteriores (el nombre y la fecha del archivo coinciden: el más
        // viejo es el A y el más nuevo es el D).
        $carpeta = storage_path('app/respaldos-reset-datos');
        mkdir($carpeta, 0775, true);
        $viejos = ['20260101-000000', '20260201-000000', '20260301-000000', '20260401-000000'];
        foreach ($viejos as $indice => $stamp) {
            $ruta = $carpeta . '/' . $stamp . '-' . $this->base_descartable . '.sql.gz';
            file_put_contents($ruta, gzencode('-- respaldo viejo ' . $stamp));
            touch($ruta, strtotime('2026-0' . ($indice + 1) . '-01 00:00:00'));
        }

        $this->ejecutar_el_reset($token)->assertStatus(200);

        // Quedan el nuevo y los dos más nuevos de los viejos: los dos más viejos se borraron, después del respaldo exitoso.
        $nombres = array_map('basename', $this->respaldos_sql());
        $this->assertCount(3, $nombres, 'Se conservan los 3 respaldos de reset más nuevos: ' . implode(', ', $nombres));
        $this->assertNotContains('20260101-000000-' . $this->base_descartable . '.sql.gz', $nombres);
        $this->assertNotContains('20260201-000000-' . $this->base_descartable . '.sql.gz', $nombres);
        $this->assertContains('20260301-000000-' . $this->base_descartable . '.sql.gz', $nombres);
        $this->assertContains('20260401-000000-' . $this->base_descartable . '.sql.gz', $nombres);
    }

    /** @test */
    public function test_las_imagenes_y_carpetas_se_mueven_al_respaldo()
    {
        $pid = getmypid();
        // Las otras carpetas con ids de negocio que el reset también mueve (imported_files se deja sin crear:
        // una carpeta que no existe se saltea sin error).
        $contenidos = [
            'provider_order_scans/500/escaneo.pdf' => 'escaneo-' . $pid,
            'asistente_imagenes/500/3/foto.jpg' => 'asistente-' . $pid,
            'whatsapp/5/audio.ogg' => 'whatsapp-' . $pid,
        ];
        foreach ($contenidos as $ruta => $contenido) {
            $this->escribir_archivo_del_storage('app/' . $ruta, $contenido);
        }
        $ruta_de_la_imagen = storage_path('app/public/reset-test-' . $pid . '.webp');
        $ruta_del_adjunto = storage_path('app/vender-items/1/x.txt');
        $this->assertFileExists($ruta_de_la_imagen);
        $this->assertFileExists($ruta_del_adjunto);

        $respuesta = $this->simular_y_ejecutar_el_reset();
        $respuesta->assertStatus(200);
        $respuesta->assertJson(['estado' => 'ok']);
        $this->assertSame([], $respuesta->json('archivos.errores'));
        $this->assertGreaterThanOrEqual(2, $respuesta->json('archivos.movidos'));
        $this->assertStringContainsString('vender-items', json_encode($respuesta->json('archivos.carpetas')));

        // Ya no están en su lugar...
        $this->assertFileDoesNotExist($ruta_de_la_imagen);
        $this->assertFileDoesNotExist($ruta_del_adjunto);
        foreach (array_keys($contenidos) as $ruta) {
            $this->assertFileDoesNotExist(storage_path('app/' . $ruta), $ruta . ' tendría que haberse movido.');
        }

        // ...y sí en <stamp>-archivos, con el contenido intacto (se movieron, no se borraron).
        $carpetas = $this->carpetas_de_archivos_movidos();
        $this->assertCount(1, $carpetas, 'Una carpeta <stamp>-archivos por reset.');
        $this->assertMatchesRegularExpression('/^\d{8}-\d{6}-archivos$/', basename($carpetas[0]));
        $this->assertNotNull($this->buscar_archivo_con_contenido($carpetas[0], $this->contenido_de_la_imagen), 'La imagen del artículo está en el respaldo.');
        $this->assertNotNull($this->buscar_archivo_con_contenido($carpetas[0], $this->contenido_del_adjunto), 'El adjunto de la venta está en el respaldo.');
        foreach ($contenidos as $contenido) {
            $this->assertNotNull($this->buscar_archivo_con_contenido($carpetas[0], $contenido), 'Falta en el respaldo: ' . $contenido);
        }
    }

    /** @test */
    public function test_un_logo_no_referenciado_queda_en_su_lugar()
    {
        $logo = storage_path('app/public/logo-' . getmypid() . '.png');
        $this->assertFileExists($logo);

        $this->simular_y_ejecutar_el_reset()->assertStatus(200);

        // Comparte carpeta con las imágenes de artículos, pero no está en `images`: no se toca.
        $this->assertFileExists($logo);
        $this->assertSame($this->contenido_del_logo, file_get_contents($logo));
        $this->assertDirectoryExists(storage_path('app/public'));
        foreach ($this->carpetas_de_archivos_movidos() as $carpeta) {
            $this->assertNull($this->buscar_archivo_con_contenido($carpeta, $this->contenido_del_logo), 'El logo no se mueve al respaldo.');
        }
    }

    /** @test */
    public function test_una_ruta_con_punto_punto_en_images_no_saca_archivos_de_su_carpeta()
    {
        $pid = getmypid();

        // Un archivo importante FUERA de storage/app/public, y una fila de `images` que intenta apuntar a él con "..".
        $importante = $this->escribir_archivo_del_storage('app/no-tocar-' . $pid . '.txt', 'no-tocar-' . $pid);
        DB::table('images')->insert([
            'hosting_url' => 'https://api.cliente.ejemplo.test/public/storage/../../../app/no-tocar-' . $pid . '.txt',
            'imageable_id' => 2,
            'imageable_type' => 'article',
        ]);
        DB::table('images')->insert([
            'hosting_url' => '../no-tocar-' . $pid . '.txt',
            'imageable_id' => 3,
            'imageable_type' => 'article',
        ]);

        $this->simular_y_ejecutar_el_reset()->assertStatus(200);

        // Sólo se toma el nombre del archivo (basename) y se busca dentro de storage/app/public: el archivo de
        // afuera ni se mueve ni se borra.
        $this->assertFileExists($importante);
        $this->assertSame('no-tocar-' . $pid, file_get_contents($importante));
        foreach ($this->carpetas_de_archivos_movidos() as $carpeta) {
            $this->assertNull($this->buscar_archivo_con_contenido($carpeta, 'no-tocar-' . $pid));
        }
    }
}
