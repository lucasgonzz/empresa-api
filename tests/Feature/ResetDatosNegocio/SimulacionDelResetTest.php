<?php

namespace Tests\Feature\ResetDatosNegocio;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ResetDatosNegocio\Concerns\BaseDescartableParaElReset;
use Tests\TestCase;

/**
 * CA2 y parte de CA9 — La simulación (dry-run) del reset de datos de negocio.
 *
 * `POST /api/admin-sync/reset-datos-negocio` sin `dry_run` es una simulación: cuenta lo que se vaciaría, no escribe
 * nada en las tablas de negocio y, si no hay nada que lo impida, entrega un token de un solo uso con el que después
 * se puede ejecutar. De ese token la base guarda sólo el sha256.
 *
 * Corre sobre la base descartable (ver BaseDescartableParaElReset). Sin RefreshDatabase ni DatabaseTransactions.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe, argumentos nombrados ni union types.
 */
class SimulacionDelResetTest extends TestCase
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

    /** @test */
    public function test_la_simulacion_cuenta_y_no_borra_nada()
    {
        $foto = $this->foto_de_las_tablas();
        $esperadas = $this->tablas_a_vaciar_con_filas();
        $this->assertSame(3, $esperadas['articles']);

        $respuesta = $this->simular_el_reset();

        $respuesta->assertStatus(200);
        $respuesta->assertJson([
            'ok' => true,
            'dry_run' => true,
            'base' => $this->base_descartable,
            'ejecutable' => true,
        ]);
        $this->assertSame([], $respuesta->json('bloqueos'), 'Sin problemas no tiene que haber bloqueos.');

        // Conteos por tabla: los del fixture, y el total es la suma de todo lo que se vaciaría.
        $this->assertSame(3, $respuesta->json('a_vaciar.articles'));
        $this->assertSame(2, $respuesta->json('a_vaciar.sales'));
        $this->assertSame(2, $respuesta->json('a_vaciar.clients'));
        $this->assertSame(2, $respuesta->json('a_vaciar.current_acounts'));
        $this->assertSame(1, $respuesta->json('a_vaciar.providers'));
        $this->assertSame(1, $respuesta->json('a_vaciar.stock_movements'));
        foreach ($esperadas as $tabla => $filas) {
            $this->assertSame($filas, $respuesta->json('a_vaciar.' . $tabla), 'Conteo de ' . $tabla);
        }
        $this->assertSame(array_sum($esperadas), $respuesta->json('total_filas'));

        // Datos que el SPA muestra: ajustes, archivos, estimaciones y registro.
        $this->assertIsArray($respuesta->json('ajustes_previstos'));
        $this->assertNotEmpty($respuesta->json('ajustes_previstos'));
        $this->assertSame(1, $respuesta->json('archivos_previstos.imagenes'));
        $carpetas = $respuesta->json('archivos_previstos.carpetas');
        $this->assertIsArray($carpetas);
        $this->assertTrue($this->una_carpeta_termina_en($carpetas, 'vender-items', true), 'vender-items existe y tiene que figurar como existente.');
        $this->assertTrue($this->una_carpeta_termina_en($carpetas, 'whatsapp', false), 'whatsapp no existe y tiene que figurar como inexistente.');
        $this->assertGreaterThan(0, $respuesta->json('respaldo_estimado_bytes'));
        $this->assertGreaterThan(0, $respuesta->json('espacio_libre_bytes'));
        $this->assertIsInt($respuesta->json('registro_id'));

        // Token: 40 caracteres que vencen en 15 minutos (ISO-8601 UTC).
        $token = $respuesta->json('token_de_simulacion');
        $this->assertSame(40, strlen($token));
        $vence = Carbon::parse($respuesta->json('token_vence_en'));
        $this->assertEqualsWithDelta(Carbon::now()->addMinutes(15)->getTimestamp(), $vence->getTimestamp(), 120, 'El token vence a los 15 minutos.');

        // Sin efectos: ni una fila cambió, los archivos siguen y no se escribió ningún respaldo.
        $this->assertBaseIntacta($foto);
        $this->assertSame([], $this->archivos_con_extension(storage_path('app/respaldos-reset-datos'), '.sql.gz'), 'Una simulación no escribe respaldos.');
        $this->assertCandadoLibre();

        // Y quedó una sola fila de registro, de modo simulación.
        $filas = $this->filas_del_registro();
        $this->assertCount(1, $filas);
        $this->assertSame('simulacion', $filas[0]->modo);
    }

    /** @test */
    public function test_la_simulacion_guarda_solo_el_hash_del_token()
    {
        $respuesta = $this->simular_el_reset();
        $respuesta->assertStatus(200);
        $token = $respuesta->json('token_de_simulacion');
        $this->assertSame(40, strlen($token));

        $filas = $this->filas_del_registro();
        $this->assertCount(1, $filas);
        $fila = $filas[0];

        $this->assertSame('simulacion', $fila->modo);
        $this->assertNotSame($token, $fila->token_hash, 'El token nunca se guarda en claro.');
        $this->assertSame(hash('sha256', $token), $fila->token_hash, 'Se guarda el sha256 del token.');

        // El token en claro no está en ninguna columna de ninguna fila ni en el log.
        $this->assertStringNotContainsString($token, json_encode($filas->all()));
        $this->assertStringNotContainsString($token, $this->texto_del_log_capturado());
    }

    /** @test */
    public function test_la_simulacion_no_emite_token_si_hay_un_bloqueo()
    {
        // Un segundo dueño: el reset se niega, pero la simulación lo cuenta como bloqueo en vez de fallar.
        DB::table('users')->insert([
            'name' => 'Otro dueño', 'email' => 'otro-dueno-' . getmypid() . '@ejemplo.test',
            'password' => 'no-importa', 'status' => 'commerce', 'owner_id' => null,
        ]);
        $foto = $this->foto_de_las_tablas();

        $respuesta = $this->simular_el_reset();

        $respuesta->assertStatus(200);
        $respuesta->assertJson(['ok' => true, 'dry_run' => true, 'ejecutable' => false]);
        $this->assertNull($respuesta->json('token_de_simulacion'));
        $this->assertNull($respuesta->json('token_vence_en'));
        $codigos = array_column($respuesta->json('bloqueos'), 'codigo');
        $this->assertContains('varios_duenos', $codigos);
        $bloqueo = $respuesta->json('bloqueos.0');
        $this->assertArrayHasKey('mensaje', $bloqueo);
        $this->assertArrayHasKey('detalle', $bloqueo);
        $this->assertSame(3, $respuesta->json('a_vaciar.articles'), 'Igual cuenta lo que vaciaría.');

        $this->assertBaseIntacta($foto);
    }

    /** @test */
    public function test_registros_devuelve_los_ultimos_sin_token_hash()
    {
        // 12 simulaciones: el listado trae sólo las 10 últimas.
        $ultimo_registro_id = null;
        $tokens = [];
        for ($i = 0; $i < 12; $i++) {
            $respuesta = $this->simular_el_reset(['solicitante' => 'agente-test', 'origen' => 'claude', 'motivo' => 'prueba de aceptación']);
            $respuesta->assertStatus(200);
            $tokens[] = $respuesta->json('token_de_simulacion');
            $ultimo_registro_id = $respuesta->json('registro_id');
        }

        $respuesta = $this->getJson($this->ruta_del_reset . '/registros?limit=10', ['X-Admin-Api-Key' => $this->clave_de_admin_del_test]);

        $respuesta->assertStatus(200);
        $respuesta->assertJson(['ok' => true]);
        $registros = $respuesta->json('registros');
        $this->assertCount(10, $registros);

        // Son los 10 más nuevos (los dos primeros quedan afuera).
        $ids_devueltos = array_column($registros, 'id');
        $ids_de_la_base = DB::table('reset_datos_negocio_registros')->orderBy('id', 'desc')->limit(10)->pluck('id')->all();
        sort($ids_devueltos);
        sort($ids_de_la_base);
        $this->assertEquals($ids_de_la_base, $ids_devueltos);
        $this->assertContains($ultimo_registro_id, $ids_devueltos);

        // Contrato C1: estos campos, y nunca el hash del token.
        $campos = ['id', 'modo', 'estado', 'codigo', 'solicitante', 'origen', 'motivo', 'total_filas', 'respaldo_ruta', 'respaldo_bytes', 'started_at', 'finished_at'];
        foreach ($campos as $campo) {
            $this->assertArrayHasKey($campo, $registros[0], 'Falta el campo ' . $campo . ' en los registros.');
        }
        $this->assertSame('simulacion', $registros[0]['modo']);
        $this->assertSame('agente-test', $registros[0]['solicitante']);
        $this->assertSame('claude', $registros[0]['origen']);
        $this->assertSame('prueba de aceptación', $registros[0]['motivo']);

        $cuerpo = $respuesta->getContent();
        $this->assertStringNotContainsString('token_hash', $cuerpo);
        foreach ($tokens as $token) {
            $this->assertStringNotContainsString($token, $cuerpo);
            $this->assertStringNotContainsString(hash('sha256', $token), $cuerpo);
        }
    }

    /** @test */
    public function test_registros_exige_la_clave_de_admin()
    {
        $this->getJson($this->ruta_del_reset . '/registros')->assertStatus(401);
        $this->getJson($this->ruta_del_reset . '/registros', ['X-Admin-Api-Key' => 'clave-equivocada'])->assertStatus(401);
    }

    /**
     * ¿Alguna clave del mapa `ruta => existe` termina en ese nombre de carpeta y con ese valor?
     *
     * @param array $carpetas Mapa ruta => bool.
     * @param string $nombre Nombre de la carpeta (ej. vender-items).
     * @param bool $existe Valor esperado.
     * @return bool
     */
    protected function una_carpeta_termina_en(array $carpetas, $nombre, $existe)
    {
        foreach ($carpetas as $ruta => $valor) {
            $ruta = rtrim((string) $ruta, '/');
            $largo = strlen($nombre);
            if (strlen($ruta) >= $largo && substr($ruta, -$largo) === $nombre && (bool) $valor === $existe) {
                return true;
            }
        }

        return false;
    }

    /**
     * Archivos de una carpeta cuyo nombre termina con una extensión (recursivo; vacío si la carpeta no existe).
     *
     * @param string $carpeta Carpeta.
     * @param string $extension Terminación del nombre, ej. `.sql.gz`.
     * @return array
     */
    protected function archivos_con_extension($carpeta, $extension)
    {
        $encontrados = [];
        foreach ($this->archivos_de($carpeta) as $ruta) {
            if (substr($ruta, -strlen($extension)) === $extension) {
                $encontrados[] = $ruta;
            }
        }

        return $encontrados;
    }
}
