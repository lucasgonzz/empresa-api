<?php

namespace Tests\Feature\ImagenesInteligentes;

use App\Models\ImageAssignmentItem;
use App\Models\ImageAssignmentRun;
use App\Models\ImageServiceCall;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Los dos endpoints con los que el admin muestra el registro de consultas de imágenes de cada
 * cliente (plan §12.1): `GET api/admin-sync/imagenes/resumen` y `GET api/admin-sync/imagenes/consultas`.
 *
 * 🔴 LAS CLAVES DE LAS RESPUESTAS SON EL CONTRATO con admin-api, que se construye en paralelo contra
 * esos nombres: si una punta renombra un campo, la otra lee null y la pantalla muestra cero sin un
 * solo error. Por eso se afirma sobre `array_keys()` enteras.
 *
 * Mismas reglas que consumo-ia (ConsumoIa/2_Endpoint_Test): la clave se exige solo si el cliente la
 * tiene cargada, 409 sin dueño resoluble, 422 con fechas mal formadas, invertidas o más de 62 días.
 */
class Registro_para_el_admin_Test extends ImagenesInteligentesTestCase
{
    /** La clave que el admin manda en el header. */
    const CLAVE = 'clave-del-admin-para-las-imagenes';

    const RUTA_RESUMEN   = 'api/admin-sync/imagenes/resumen';
    const RUTA_CONSULTAS = 'api/admin-sync/imagenes/consultas';

    /** Las claves exactas de cada fila del registro (contrato §12.1). */
    const CLAVES_DE_FILA = [
        'id', 'created_at', 'tipo', 'origen', 'proveedor', 'modelo', 'criterio', 'consulta',
        'article_id', 'article_name', 'run_id', 'ok', 'cobrada', 'http_status', 'error', 'resultados',
        'candidatas', 'resumen', 'tokens_entrada', 'tokens_salida', 'tokens_cache_escritura',
        'tokens_cache_lectura', 'duracion_ms',
    ];

    /** @var \App\Models\User Otro comercio de la misma base. */
    protected $otro;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.admin_api.api_key' => self::CLAVE]);

        // Apagado, como en producción: la clave la valida el controlador, no el middleware.
        config(['services.admin_api.require_api_key' => false]);

        // La configuración real de una instancia: la base de testing tiene varios dueños.
        config(['app.USER_ID' => $this->owner->id]);

        $this->otro = User::create([
            'name'     => 'Otro comercio de la base',
            'email'    => 'otro-admin-imagenes-'.uniqid().'@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * @return array
     */
    protected function headers()
    {
        return ['X-Admin-Api-Key' => self::CLAVE];
    }

    /**
     * Una fila del registro con fecha puesta a mano.
     *
     * @param  array  $datos
     * @param  string $fecha  'Y-m-d H:i:s'
     * @return \App\Models\ImageServiceCall
     */
    protected function consulta(array $datos, $fecha)
    {
        return ImageServiceCall::create(array_merge([
            'user_id'    => $this->owner->id,
            'origen'     => ImageServiceCall::ORIGEN_ASIGNACION,
            'tipo'       => ImageServiceCall::TIPO_BUSQUEDA,
            'proveedor'  => 'serper',
            'ok'         => true,
            'cobrada'    => true,
            'created_at' => $fecha,
            'updated_at' => $fecha,
        ], $datos));
    }

    /**
     * Una asignación con fecha puesta a mano (sin pasar por crear(): acá solo importa lo que lee el
     * resumen).
     *
     * @param  string $fecha
     * @param  array  $datos
     * @return \App\Models\ImageAssignmentRun
     */
    protected function asignacion_del($fecha, array $datos = [])
    {
        $run = ImageAssignmentRun::create(array_merge([
            'user_id'         => $this->owner->id,
            'uuid'            => (string) Str::uuid(),
            'origen'          => ImageAssignmentRun::ORIGEN_CATALOGO,
            'proveedor'       => ImageAssignmentRun::PROVEEDOR_SERPER,
            'status'          => ImageAssignmentRun::STATUS_TERMINADA,
            'total_articulos' => 3,
            'busquedas'       => 4,
            'validaciones_ia' => 2,
        ], $datos));

        // created_at a mano (create() lo pisaría con ahora).
        $run->created_at = $fecha;
        $run->updated_at = $fecha;
        $run->save();

        return $run;
    }

    /**
     * El escenario de dos días: 10/9 y 11/9, búsquedas de Serper y Google, llamadas a la IA con dos
     * modelos, errores, y una fila de OTRO comercio que no puede aparecer.
     *
     * @return void
     */
    protected function sembrar()
    {
        // 10/9: dos búsquedas de Serper (una falló) y una de Google.
        $this->consulta(['criterio' => 'codigo_de_barras', 'consulta' => '7791234567898', 'resultados' => 10, 'resumen' => '10 resultados', 'http_status' => 200, 'duracion_ms' => 820], '2026-09-10 10:00:00');
        $this->consulta(['criterio' => 'nombre', 'consulta' => 'Pala ancha', 'ok' => false, 'cobrada' => false, 'http_status' => 500, 'error' => 'Serper respondió con error (HTTP 500).'], '2026-09-10 10:01:00');
        $this->consulta(['proveedor' => 'google', 'criterio' => 'nombre', 'consulta' => 'Pala ancha', 'resultados' => 8], '2026-09-10 12:00:00');

        // 10/9: dos llamadas a la IA con Haiku.
        $this->consulta(['tipo' => ImageServiceCall::TIPO_VALIDACION_IA, 'proveedor' => 'anthropic', 'modelo' => 'claude-haiku-4-5-20251001', 'candidatas' => 4, 'resumen' => '1 sí, 3 no', 'tokens_entrada' => 1000, 'tokens_salida' => 100, 'tokens_cache_escritura' => 0, 'tokens_cache_lectura' => 50], '2026-09-10 10:00:05');
        $this->consulta(['tipo' => ImageServiceCall::TIPO_VALIDACION_IA, 'proveedor' => 'anthropic', 'modelo' => 'claude-haiku-4-5-20251001', 'candidatas' => 2, 'tokens_entrada' => 600, 'tokens_salida' => 80, 'tokens_cache_escritura' => 0, 'tokens_cache_lectura' => 0], '2026-09-10 12:00:05');

        // 11/9: una búsqueda de Serper, una IA con otro modelo y una IA que falló (no cobrada).
        $this->consulta(['criterio' => 'codigo_de_barras', 'consulta' => '7790001000019', 'resultados' => 3], '2026-09-11 09:00:00');
        $this->consulta(['tipo' => ImageServiceCall::TIPO_VALIDACION_IA, 'proveedor' => 'anthropic', 'modelo' => 'claude-sonnet-4-5-20250929', 'candidatas' => 1, 'tokens_entrada' => 300, 'tokens_salida' => 40, 'tokens_cache_escritura' => 10, 'tokens_cache_lectura' => 0], '2026-09-11 09:00:05');
        $this->consulta(['tipo' => ImageServiceCall::TIPO_VALIDACION_IA, 'proveedor' => 'anthropic', 'modelo' => 'claude-haiku-4-5-20251001', 'candidatas' => 1, 'ok' => false, 'cobrada' => false, 'http_status' => 529, 'error' => 'Overloaded'], '2026-09-11 09:05:00');

        // Fuera del rango.
        $this->consulta(['criterio' => 'nombre', 'consulta' => 'Fuera del rango'], '2026-09-12 00:00:01');

        // De otro comercio de la misma base, adentro del rango: no puede aparecer nunca.
        $this->consulta(['user_id' => $this->otro->id, 'criterio' => 'nombre', 'consulta' => 'Del otro comercio'], '2026-09-10 11:00:00');
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function el_resumen_trae_totales_dias_modelos_y_asignaciones_con_las_claves_del_contrato()
    {
        $this->sembrar();

        $vieja  = $this->asignacion_del('2026-09-10 08:00:00');
        $nueva  = $this->asignacion_del('2026-09-11 08:00:00', ['origen' => ImageAssignmentRun::ORIGEN_SELECCION, 'status' => ImageAssignmentRun::STATUS_EN_PROCESO]);
        $afuera = $this->asignacion_del('2026-09-09 23:59:59');
        $ajena  = $this->asignacion_del('2026-09-10 09:00:00', ['user_id' => $this->otro->id]);

        // Items de la nueva: 1 asignada, 1 a revisar, 1 no asignada (los conteos del resumen).
        foreach ([ImageAssignmentItem::STATUS_ASIGNADA, ImageAssignmentItem::STATUS_A_REVISAR, ImageAssignmentItem::STATUS_NO_ASIGNADA] as $orden => $estado) {
            ImageAssignmentItem::create([
                'run_id'     => $nueva->id,
                'user_id'    => $this->owner->id,
                'article_id' => $this->nuevo_articulo('Artículo '.$orden)->id,
                'orden'      => $orden + 1,
                'status'     => $estado,
            ]);
        }

        $respuesta = $this->getJson(self::RUTA_RESUMEN.'?desde=2026-09-10&hasta=2026-09-11', $this->headers())
            ->assertStatus(200)
            ->json();

        $this->assertSame(['desde', 'hasta', 'totales', 'dias', 'modelos', 'asignaciones'], array_keys($respuesta));
        $this->assertSame('2026-09-10', $respuesta['desde']);
        $this->assertSame('2026-09-11', $respuesta['hasta']);

        // busquedas_cobradas_por_proveedor es aditivo (plan §13, C2): la de Serper que falló no se cobra.
        $this->assertSame([
            'busquedas'                => 4,
            'busquedas_cobradas'       => 3,
            'busquedas_por_proveedor'  => ['serper' => 3, 'google' => 1],
            'busquedas_cobradas_por_proveedor' => ['serper' => 2, 'google' => 1],
            'validaciones_ia'          => 4,
            'validaciones_ia_cobradas' => 3,
            'errores'                  => 2,
            'tokens_entrada'           => 1900,
            'tokens_salida'            => 220,
            'tokens_cache_escritura'   => 10,
            'tokens_cache_lectura'     => 50,
        ], $respuesta['totales']);

        // busquedas_serper_cobradas / busquedas_google_cobradas son aditivos (plan §13, C2).
        $this->assertSame([
            [
                'fecha' => '2026-09-10', 'busquedas' => 3, 'busquedas_cobradas' => 2, 'busquedas_serper' => 2,
                'busquedas_google' => 1, 'busquedas_serper_cobradas' => 1, 'busquedas_google_cobradas' => 1,
                'validaciones_ia' => 2, 'validaciones_ia_cobradas' => 2, 'errores' => 1,
            ],
            [
                'fecha' => '2026-09-11', 'busquedas' => 1, 'busquedas_cobradas' => 1, 'busquedas_serper' => 1,
                'busquedas_google' => 0, 'busquedas_serper_cobradas' => 1, 'busquedas_google_cobradas' => 0,
                'validaciones_ia' => 2, 'validaciones_ia_cobradas' => 1, 'errores' => 1,
            ],
        ], $respuesta['dias']);

        $this->assertSame([
            [
                'modelo' => 'claude-haiku-4-5-20251001', 'llamadas' => 3, 'tokens_entrada' => 1600,
                'tokens_salida' => 180, 'tokens_cache_escritura' => 0, 'tokens_cache_lectura' => 50,
            ],
            [
                'modelo' => 'claude-sonnet-4-5-20250929', 'llamadas' => 1, 'tokens_entrada' => 300,
                'tokens_salida' => 40, 'tokens_cache_escritura' => 10, 'tokens_cache_lectura' => 0,
            ],
        ], $respuesta['modelos']);

        // Las asignaciones del rango, más nuevas primero; ni la de afuera ni la del otro comercio.
        $this->assertSame([(int) $nueva->id, (int) $vieja->id], array_column($respuesta['asignaciones'], 'id'));

        $this->assertSame([
            'id', 'uuid', 'created_at', 'origen', 'status', 'proveedor', 'total_articulos', 'asignadas',
            'a_revisar', 'no_asignadas', 'busquedas', 'validaciones_ia',
        ], array_keys($respuesta['asignaciones'][0]));

        $this->assertSame((string) $nueva->uuid, $respuesta['asignaciones'][0]['uuid']);
        $this->assertSame(ImageAssignmentRun::ORIGEN_SELECCION, $respuesta['asignaciones'][0]['origen']);
        $this->assertSame(ImageAssignmentRun::STATUS_EN_PROCESO, $respuesta['asignaciones'][0]['status']);
        $this->assertSame('serper', $respuesta['asignaciones'][0]['proveedor']);
        $this->assertSame(3, $respuesta['asignaciones'][0]['total_articulos']);
        $this->assertSame(1, $respuesta['asignaciones'][0]['asignadas']);
        $this->assertSame(1, $respuesta['asignaciones'][0]['a_revisar']);
        $this->assertSame(1, $respuesta['asignaciones'][0]['no_asignadas']);
        $this->assertSame(4, $respuesta['asignaciones'][0]['busquedas']);
        $this->assertSame(2, $respuesta['asignaciones'][0]['validaciones_ia']);
        $this->assertStringStartsWith('2026-09-11T08:00:00', $respuesta['asignaciones'][0]['created_at']);

        $this->assertNotContains((int) $afuera->id, array_column($respuesta['asignaciones'], 'id'));
        $this->assertNotContains((int) $ajena->id, array_column($respuesta['asignaciones'], 'id'));
    }

    /**
     * Sin nada en el rango: todo en cero, pero con las claves (los dos proveedores incluidos).
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function el_resumen_de_un_rango_vacio_trae_ceros_y_las_claves()
    {
        $respuesta = $this->getJson(self::RUTA_RESUMEN.'?desde=2026-08-01&hasta=2026-08-31', $this->headers())
            ->assertStatus(200)
            ->json();

        $this->assertSame(['serper' => 0, 'google' => 0], $respuesta['totales']['busquedas_por_proveedor']);
        $this->assertSame(['serper' => 0, 'google' => 0], $respuesta['totales']['busquedas_cobradas_por_proveedor']);
        $this->assertSame(0, $respuesta['totales']['busquedas']);
        $this->assertSame(0, $respuesta['totales']['tokens_entrada']);
        $this->assertSame([], $respuesta['dias']);
        $this->assertSame([], $respuesta['modelos']);
        $this->assertSame([], $respuesta['asignaciones']);
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function el_registro_trae_las_filas_con_las_claves_del_contrato_mas_nuevas_primero()
    {
        $this->sembrar();

        $respuesta = $this->getJson(self::RUTA_CONSULTAS.'?desde=2026-09-10&hasta=2026-09-11', $this->headers())
            ->assertStatus(200)
            ->json();

        $this->assertSame(['models'], array_keys($respuesta));

        $pagina = $respuesta['models'];

        $this->assertSame(8, $pagina['total'], 'Las 8 del rango de este comercio: ni la de afuera ni la del otro.');
        $this->assertSame(50, (int) $pagina['per_page'], 'De a 50 por defecto.');
        $this->assertSame(1, $pagina['current_page']);
        $this->assertSame(self::CLAVES_DE_FILA, array_keys($pagina['data'][0]));

        // Más nuevas primero.
        $fechas = array_column($pagina['data'], 'created_at');
        $ordenadas = $fechas;
        rsort($ordenadas);
        $this->assertSame($ordenadas, $fechas);
        $this->assertStringStartsWith('2026-09-11T09:05:00', $pagina['data'][0]['created_at']);

        // Los tipos llegan como el contrato: ok/cobrada booleanos, números como números.
        $error = $pagina['data'][0];
        $this->assertSame(ImageServiceCall::TIPO_VALIDACION_IA, $error['tipo']);
        $this->assertFalse($error['ok']);
        $this->assertFalse($error['cobrada']);
        $this->assertSame(529, $error['http_status']);
        $this->assertSame('Overloaded', $error['error']);
        $this->assertSame(1, $error['candidatas']);

        $consultas = array_column($pagina['data'], 'consulta');
        $this->assertNotContains('Del otro comercio', $consultas);
        $this->assertNotContains('Fuera del rango', $consultas);
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function el_registro_se_filtra_por_tipo_asignacion_y_errores()
    {
        $this->sembrar();

        $run = $this->asignacion_del('2026-09-10 08:00:00');
        $this->consulta(['run_id' => $run->id, 'criterio' => 'nombre', 'consulta' => 'De la asignación'], '2026-09-10 08:30:00');
        $this->consulta(['run_id' => $run->id, 'tipo' => ImageServiceCall::TIPO_VALIDACION_IA, 'proveedor' => 'anthropic', 'modelo' => 'claude-haiku-4-5-20251001'], '2026-09-10 08:30:05');

        $rango = '?desde=2026-09-10&hasta=2026-09-11';

        $busquedas = $this->getJson(self::RUTA_CONSULTAS.$rango.'&tipo=busqueda', $this->headers())->assertStatus(200)->json('models');
        $this->assertSame(5, $busquedas['total']);
        $this->assertSame([ImageServiceCall::TIPO_BUSQUEDA], array_values(array_unique(array_column($busquedas['data'], 'tipo'))));

        $validaciones = $this->getJson(self::RUTA_CONSULTAS.$rango.'&tipo=validacion_ia', $this->headers())->assertStatus(200)->json('models');
        $this->assertSame(5, $validaciones['total']);

        $de_la_asignacion = $this->getJson(self::RUTA_CONSULTAS.$rango.'&asignacion='.$run->id, $this->headers())->assertStatus(200)->json('models');
        $this->assertSame(2, $de_la_asignacion['total']);
        $this->assertSame([(int) $run->id], array_values(array_unique(array_column($de_la_asignacion['data'], 'run_id'))));

        $errores = $this->getJson(self::RUTA_CONSULTAS.$rango.'&solo_errores=1', $this->headers())->assertStatus(200)->json('models');
        $this->assertSame(2, $errores['total']);
        $this->assertSame([false], array_values(array_unique(array_column($errores['data'], 'ok'))));

        // Combinados.
        $busquedas_con_error = $this->getJson(self::RUTA_CONSULTAS.$rango.'&tipo=busqueda&solo_errores=1', $this->headers())->assertStatus(200)->json('models');
        $this->assertSame(1, $busquedas_con_error['total']);
        $this->assertSame('Pala ancha', $busquedas_con_error['data'][0]['consulta']);

        // Un tipo que no existe es un error del que llama.
        $this->getJson(self::RUTA_CONSULTAS.$rango.'&tipo=cualquiera', $this->headers())->assertStatus(422);
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function el_registro_se_pagina_entre_10_y_200_por_pagina()
    {
        for ($i = 0; $i < 12; $i++) {
            $this->consulta(['consulta' => 'Consulta '.$i], '2026-09-10 10:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).':00');
        }

        $rango = '?desde=2026-09-10&hasta=2026-09-10';

        $primera = $this->getJson(self::RUTA_CONSULTAS.$rango.'&per_page=10', $this->headers())->assertStatus(200)->json('models');
        $this->assertSame(12, $primera['total']);
        $this->assertSame(2, $primera['last_page']);
        $this->assertCount(10, $primera['data']);
        $this->assertSame('Consulta 11', $primera['data'][0]['consulta']);

        $segunda = $this->getJson(self::RUTA_CONSULTAS.$rango.'&per_page=10&page=2', $this->headers())->assertStatus(200)->json('models');
        $this->assertCount(2, $segunda['data']);
        $this->assertSame('Consulta 0', $segunda['data'][1]['consulta']);

        // Los topes: menos de 10 sube a 10, más de 200 baja a 200.
        $this->assertSame(10, (int) $this->getJson(self::RUTA_CONSULTAS.$rango.'&per_page=3', $this->headers())->json('models.per_page'));
        $this->assertSame(200, (int) $this->getJson(self::RUTA_CONSULTAS.$rango.'&per_page=5000', $this->headers())->json('models.per_page'));
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function la_clave_se_exige_solo_si_el_cliente_la_tiene_cargada()
    {
        $rango = '?desde=2026-09-10&hasta=2026-09-11';

        foreach ([self::RUTA_RESUMEN, self::RUTA_CONSULTAS] as $ruta) {
            // Con clave cargada: equivocada o ausente, 401.
            $this->getJson($ruta.$rango, ['X-Admin-Api-Key' => 'otra-clave'])->assertStatus(401)->assertJson(['error' => 'unauthorized']);
            $this->getJson($ruta.$rango)->assertStatus(401);
            $this->getJson($ruta.$rango, $this->headers())->assertStatus(200);
        }

        // Sin clave cargada en este cliente: se comporta como el resto del grupo.
        config(['services.admin_api.api_key' => '']);

        $this->getJson(self::RUTA_RESUMEN.$rango)->assertStatus(200);
        $this->getJson(self::RUTA_CONSULTAS.$rango)->assertStatus(200);
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function las_fechas_mal_armadas_o_un_rango_de_mas_de_62_dias_dan_422()
    {
        foreach ([self::RUTA_RESUMEN, self::RUTA_CONSULTAS] as $ruta) {
            $this->getJson($ruta.'?desde=2026-01-01&hasta=2026-12-31', $this->headers())->assertStatus(422);
            $this->getJson($ruta.'?desde=2026-09-11&hasta=2026-09-10', $this->headers())->assertStatus(422);
            $this->getJson($ruta.'?desde=ayer&hasta=2026-09-10', $this->headers())->assertStatus(422);

            // 62 días justos pasan.
            $this->getJson($ruta.'?desde=2026-07-01&hasta=2026-08-31', $this->headers())->assertStatus(200);
        }

        // Sin fechas: los últimos 30 días.
        $respuesta = $this->getJson(self::RUTA_RESUMEN, $this->headers())->assertStatus(200)->json();

        $this->assertSame(Carbon::now()->toDateString(), $respuesta['hasta']);
        $this->assertSame(Carbon::now()->subDays(29)->toDateString(), $respuesta['desde']);
    }

    /**
     * Sin USER_ID en una base de varios comercios no se sabe de quién es el registro: 409.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function sin_dueno_resoluble_devuelve_409()
    {
        config(['app.USER_ID' => null]);

        $this->getJson(self::RUTA_RESUMEN.'?desde=2026-09-10&hasta=2026-09-11', $this->headers())->assertStatus(409);
        $this->getJson(self::RUTA_CONSULTAS.'?desde=2026-09-10&hasta=2026-09-11', $this->headers())->assertStatus(409);
    }

    /**
     * El registro de un dueño es solo suyo: con USER_ID apuntando al otro comercio, se ve lo del otro.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function cada_dueno_ve_solo_su_registro()
    {
        $this->sembrar();

        config(['app.USER_ID' => $this->otro->id]);

        $resumen = $this->getJson(self::RUTA_RESUMEN.'?desde=2026-09-10&hasta=2026-09-11', $this->headers())->assertStatus(200)->json();
        $this->assertSame(1, $resumen['totales']['busquedas']);
        $this->assertSame(0, $resumen['totales']['validaciones_ia']);

        $consultas = $this->getJson(self::RUTA_CONSULTAS.'?desde=2026-09-10&hasta=2026-09-11', $this->headers())->assertStatus(200)->json('models');
        $this->assertSame(1, $consultas['total']);
        $this->assertSame('Del otro comercio', $consultas['data'][0]['consulta']);
    }
}
