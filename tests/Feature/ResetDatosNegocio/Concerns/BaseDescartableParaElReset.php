<?php

namespace Tests\Feature\ResetDatosNegocio\Concerns;

use App\Http\Controllers\Helpers\DemoSetupLockHelper;
use App\Http\Controllers\Helpers\ResetDatosNegocio\CatalogoDeTablasDelResetHelper;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\TestHandler;

/**
 * Entorno aislado para los tests que EJECUTAN el reset de datos de negocio (misión reset-datos-cliente).
 *
 * POR QUÉ UNA BASE DESCARTABLE
 * ----------------------------
 * El reset vacía con TRUNCATE, y TRUNCATE hace commit implícito: no se puede envolver en una transacción que
 * se deshaga al final del test (lo que sí hacen `DatabaseTransactions` / `RefreshDatabase`). Si estos tests
 * corrieran contra la base del slot (`empresa_testing_sN`) la dejarían vacía para el resto de la suite. Por eso
 * cada test crea su propia base `<DB_DATABASE>_test_reset<pid>` (el usuario de `cc-db` tiene permisos sobre
 * `<base>_test_%`), con el esquema completo copiado de la base del slot (`CREATE TABLE ... LIKE`) y las filas de
 * las tablas de CONFIGURACIÓN y SISTEMA, apunta la conexión por defecto ahí y, al terminar, la borra.
 *
 * Los tests que usan este trait NO deben usar `RefreshDatabase` ni `DatabaseTransactions`.
 *
 * El `storage` también se redirige: a una carpeta temporal con el pid, para que el respaldo, los archivos
 * movidos y el candado (`DemoSetupLockHelper`) no toquen el `storage/app` real del worktree.
 *
 * USO (en cada clase de test):
 *   - setUp():   parent::setUp(); $this->iniciar_entorno_del_reset();
 *   - tearDown(): $this->terminar_entorno_del_reset(); parent::tearDown();
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe, argumentos nombrados ni union types.
 */
trait BaseDescartableParaElReset
{
    /** Ruta del endpoint (contrato C1 del plan). */
    protected $ruta_del_reset = '/api/admin-sync/reset-datos-negocio';

    /** Clave de admin que se carga en la instancia durante los tests (ADMIN_API_INBOUND_KEY). */
    protected $clave_de_admin_del_test = 'clave-test-reset';

    /** Nombre de la conexión por defecto (la que se redirige a la base descartable). */
    protected $conexion_del_reset;

    /** Nombre de la base del slot (de donde se copia el esquema). */
    protected $base_del_slot;

    /** Nombre de la base descartable donde corre el test. */
    protected $base_descartable;

    /** Nombre de la base donde un test restaura el respaldo para verificarlo (null si no se creó). */
    protected $base_de_restauracion = null;

    /** Ruta del storage original, para devolverlo en tearDown. */
    protected $storage_original;

    /** Carpeta temporal que hace de storage durante el test. */
    protected $storage_temporal;

    /** Handle del candado cuando el test simula "ya hay una corrida". */
    protected $candado_del_test = false;

    /** Id del dueño (la única fila de `users` con owner_id NULL del slot). */
    protected $id_del_dueno;

    /** Id del empleado que siembra el fixture (users.owner_id = dueño). */
    protected $id_del_empleado;

    /** Contenido de cada archivo sembrado, para reconocerlo después de moverlo. */
    protected $contenido_de_la_imagen;
    protected $contenido_del_adjunto;
    protected $contenido_del_logo;

    /** Nombres de las listas del catálogo, para no repetir la ruta de la clase. */
    protected $clase_del_catalogo = CatalogoDeTablasDelResetHelper::class;

    /**
     * Prepara todo: storage temporal, base descartable con esquema + configuración, conexión redirigida,
     * clave de admin, captura de logs y el fixture de negocio del plan (§6).
     *
     * @return void
     */
    protected function iniciar_entorno_del_reset()
    {
        // Sin el catálogo no hay forma de saber qué filas se copian: falla con un mensaje claro.
        if (!class_exists($this->clase_del_catalogo)) {
            $this->fail('Falta la clase ' . $this->clase_del_catalogo . ' (todavía no se implementó el reset de datos de negocio).');
        }

        $this->conexion_del_reset = config('database.default');
        $this->base_del_slot = (string) DB::connection()->getDatabaseName();

        // Nunca se crea ni se borra nada si la base activa no es de testing.
        $this->assertStringContainsString('test', strtolower($this->base_del_slot), 'La base activa no es de testing.');

        $this->base_descartable = $this->base_del_slot . '_test_reset' . getmypid();

        $this->redirigir_el_storage();
        $this->crear_la_base_descartable();
        $this->apuntar_la_conexion_a($this->base_descartable);

        // Clave de admin de la instancia (el middleware `admin.api.key:siempre` la lee de acá).
        config(['services.admin_api.api_key' => $this->clave_de_admin_del_test]);

        $this->capturar_el_log();
        $this->sembrar_el_negocio_del_cliente();
    }

    /**
     * Deshace todo lo de iniciar_entorno_del_reset(): suelta el candado, vuelve a la base del slot, borra las
     * bases descartables y la carpeta temporal. Idempotente: se puede llamar aunque el setUp haya fallado a medias.
     *
     * @return void
     */
    protected function terminar_entorno_del_reset()
    {
        Carbon::setTestNow();

        DemoSetupLockHelper::soltar($this->candado_del_test);
        $this->candado_del_test = false;

        if ($this->base_del_slot !== null && $this->conexion_del_reset !== null) {
            $this->apuntar_la_conexion_a($this->base_del_slot);

            // Cinturón y tirantes: sólo se borran bases con el patrón de este trait.
            foreach ([$this->base_descartable, $this->base_de_restauracion] as $nombre) {
                if ($nombre !== null && strpos($nombre, '_test_reset') !== false) {
                    DB::statement('DROP DATABASE IF EXISTS `' . $nombre . '`');
                }
            }
        }

        if ($this->storage_original !== null) {
            $this->app->useStoragePath($this->storage_original);
        }

        if ($this->storage_temporal !== null && strpos($this->storage_temporal, sys_get_temp_dir()) === 0
            && strpos($this->storage_temporal, 'reset_datos_test_') !== false && is_dir($this->storage_temporal)) {
            (new Filesystem())->deleteDirectory($this->storage_temporal);
        }
    }

    /**
     * Redirige `storage_path()` y los discos `local` / `public` a una carpeta temporal con el pid del proceso.
     *
     * @return void
     */
    protected function redirigir_el_storage()
    {
        $this->storage_original = $this->app->storagePath();
        $this->storage_temporal = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'reset_datos_test_' . getmypid();

        // Si quedó una carpeta de una corrida anterior con el mismo pid, se parte de cero.
        if (is_dir($this->storage_temporal)) {
            (new Filesystem())->deleteDirectory($this->storage_temporal);
        }

        mkdir($this->storage_temporal . '/app/public', 0775, true);
        mkdir($this->storage_temporal . '/framework/cache', 0775, true);
        mkdir($this->storage_temporal . '/logs', 0775, true);

        $this->app->useStoragePath($this->storage_temporal);

        config([
            'filesystems.disks.local.root' => $this->storage_temporal . '/app',
            'filesystems.disks.public.root' => $this->storage_temporal . '/app/public',
        ]);
        Storage::forgetDisk(['local', 'public']);
    }

    /**
     * Crea la base descartable: mismo esquema que la base del slot (todas las tablas) y las filas de las tablas de
     * CONFIGURACIÓN y SISTEMA. Las tablas de negocio quedan vacías (el fixture las llena después).
     *
     * @return void
     */
    protected function crear_la_base_descartable()
    {
        $slot = $this->base_del_slot;
        $nueva = $this->base_descartable;

        DB::statement('DROP DATABASE IF EXISTS `' . $nueva . '`');
        DB::statement('CREATE DATABASE `' . $nueva . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        // Las tablas reales de la base del slot (sin vistas).
        $tablas = DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $slot)
            ->where('TABLE_TYPE', 'BASE TABLE')
            ->pluck('TABLE_NAME')
            ->all();

        foreach ($tablas as $tabla) {
            DB::statement('CREATE TABLE `' . $nueva . '`.`' . $tabla . '` LIKE `' . $slot . '`.`' . $tabla . '`');
        }

        // Filas de configuración y de sistema: lo que el user setup y el dueño dejan armado.
        $catalogo = $this->clase_del_catalogo;
        $a_copiar = array_merge($catalogo::TABLAS_DE_CONFIGURACION, $catalogo::TABLAS_DE_SISTEMA);

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($a_copiar as $tabla) {
            if (in_array($tabla, $tablas, true)) {
                DB::statement('INSERT INTO `' . $nueva . '`.`' . $tabla . '` SELECT * FROM `' . $slot . '`.`' . $tabla . '`');
            }
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * Apunta la conexión por defecto a otra base y la reconecta.
     *
     * @param string $base Nombre de la base.
     * @return void
     */
    protected function apuntar_la_conexion_a($base)
    {
        config(['database.connections.' . $this->conexion_del_reset . '.database' => $base]);
        DB::purge($this->conexion_del_reset);
        DB::reconnect($this->conexion_del_reset);
    }

    /**
     * Captura todo lo que la aplicación escribe en el log durante el test (canal Monolog con TestHandler).
     *
     * @return void
     */
    protected function capturar_el_log()
    {
        config([
            'logging.channels.captura_del_reset' => ['driver' => 'monolog', 'handler' => TestHandler::class],
            'logging.default' => 'captura_del_reset',
        ]);
        Log::forgetChannel('captura_del_reset');
    }

    /**
     * Todo lo que se escribió en el log, como una sola cadena (mensaje + contexto de cada línea).
     *
     * @return string
     */
    protected function texto_del_log_capturado()
    {
        $texto = '';
        $manejadores = Log::channel('captura_del_reset')->getLogger()->getHandlers();

        foreach ($manejadores as $manejador) {
            foreach ($manejador->getRecords() as $registro) {
                $texto .= $registro['level_name'] . ' ' . $registro['message'] . ' ' . json_encode($registro['context']) . "\n";
            }
        }

        return $texto;
    }

    /**
     * Siembra el fixture común del plan (§6): datos de negocio + una fila de cada cosa de configuración que los
     * ajustes tocan, más archivos en el storage temporal.
     *
     * @return void
     */
    protected function sembrar_el_negocio_del_cliente()
    {
        $ahora = now()->toDateTimeString();
        $pid = getmypid();

        $this->id_del_dueno = (int) DB::table('users')->whereNull('owner_id')->value('id');
        $this->assertGreaterThan(0, $this->id_del_dueno, 'El fixture del slot no trae dueño.');
        $dueno = $this->id_del_dueno;

        // Usuarios: el dueño con cursores y marcas de generación cargadas; un empleado con los cursores en NULL.
        DB::table('users')->where('id', $dueno)->update([
            'eliminar_articulos_offline' => 0,
            'agente_aviso_stock_cursor' => 7,
            'agente_aviso_pedidos_cursor' => 9,
            'sugerencias_ultima_generacion_at' => $ahora,
            'sugerencias_compras_ultima_generacion_at' => $ahora,
            'ofertas_ultima_generacion_at' => $ahora,
        ]);
        $this->id_del_empleado = DB::table('users')->insertGetId([
            'name' => 'Empleado del reset',
            'email' => 'empleado-reset-' . $pid . '@ejemplo.test',
            'password' => 'no-importa',
            'status' => 'commerce',
            'owner_id' => $dueno,
            'eliminar_articulos_offline' => 0,
            'agente_aviso_stock_cursor' => null,
            'agente_aviso_pedidos_cursor' => null,
        ]);

        // Caja existente (configuración) con estado de negocio encima: saldo, abierta y apertura vigente.
        $id_de_caja = (int) DB::table('cajas')->orderBy('id')->value('id');
        $id_de_apertura = DB::table('apertura_cajas')->insertGetId(['saldo_apertura' => 0, 'caja_id' => $id_de_caja]);
        DB::table('cajas')->where('id', $id_de_caja)->update([
            'saldo' => 1500,
            'abierta' => 1,
            'abierta_at' => $ahora,
            'cerrada_at' => $ahora,
            'current_apertura_caja_id' => $id_de_apertura,
        ]);
        DB::table('movimiento_cajas')->insert(['apertura_caja_id' => $id_de_apertura, 'caja_id' => $id_de_caja, 'ingreso' => 1500, 'saldo' => 1500]);

        // 3 artículos, uno con una imagen cuyo archivo existe en storage/app/public.
        $ids_de_articulos = [];
        for ($i = 1; $i <= 3; $i++) {
            $ids_de_articulos[] = DB::table('articles')->insertGetId(['user_id' => $dueno, 'name' => 'Artículo del reset ' . $i]);
        }
        DB::table('images')->insert([
            'hosting_url' => 'https://api.cliente.ejemplo.test/public/storage/reset-test-' . $pid . '.webp',
            'imageable_id' => $ids_de_articulos[0],
            'imageable_type' => 'article',
        ]);

        // 2 clientes, 1 proveedor, 2 ventas con una línea cada una.
        $id_cliente_1 = DB::table('clients')->insertGetId(['name' => 'Cliente reset 1', 'user_id' => $dueno]);
        DB::table('clients')->insert(['name' => 'Cliente reset 2', 'user_id' => $dueno]);
        $id_proveedor = DB::table('providers')->insertGetId(['name' => 'Proveedor reset', 'user_id' => $dueno]);
        $id_venta_1 = DB::table('sales')->insertGetId(['user_id' => $dueno, 'client_id' => $id_cliente_1, 'num' => 1, 'total' => 100, 'terminada' => 1]);
        $id_venta_2 = DB::table('sales')->insertGetId(['user_id' => $dueno, 'client_id' => $id_cliente_1, 'num' => 2, 'total' => 50, 'terminada' => 1]);
        DB::table('article_sale')->insert(['article_id' => $ids_de_articulos[0], 'sale_id' => $id_venta_1, 'amount' => 1, 'name' => 'Artículo del reset 1', 'price' => 100]);
        DB::table('article_sale')->insert(['article_id' => $ids_de_articulos[1], 'sale_id' => $id_venta_2, 'amount' => 1, 'name' => 'Artículo del reset 2', 'price' => 50]);

        // Cuentas corrientes: una de cliente y una de proveedor (los saldos que el pedido de Lucas menciona).
        DB::table('current_acounts')->insert(['status' => 'sin_pagar', 'user_id' => $dueno, 'client_id' => $id_cliente_1, 'sale_id' => $id_venta_1, 'debe' => 100, 'saldo' => 100]);
        DB::table('current_acounts')->insert(['status' => 'sin_pagar', 'user_id' => $dueno, 'provider_id' => $id_proveedor, 'haber' => 80, 'saldo' => -80]);

        DB::table('stock_movements')->insert(['article_id' => $ids_de_articulos[0], 'amount' => 5, 'user_id' => $dueno]);
        DB::table('categories')->insert(['name' => 'Categoría del reset', 'user_id' => $dueno]);

        // Tienda: comprador con token, dirección propia y un pedido.
        $id_comprador = DB::table('buyers')->insertGetId(['name' => 'Comprador del reset']);
        DB::table('personal_access_tokens')->insert(['tokenable_type' => 'App\\Buyer', 'tokenable_id' => $id_comprador, 'name' => 'tienda', 'token' => hash('sha256', 'token-comprador-' . $pid)]);
        DB::table('personal_access_tokens')->insert(['tokenable_type' => 'App\\Models\\User', 'tokenable_id' => $dueno, 'name' => 'erp', 'token' => hash('sha256', 'token-usuario-' . $pid)]);
        DB::table('addresses')->insert(['street' => 'Calle del comprador', 'buyer_id' => $id_comprador, 'user_id' => $dueno]);
        DB::table('orders')->insert(['status' => 'unconfirmed', 'deliver' => 0, 'buyer_id' => $id_comprador, 'order_status_id' => 1, 'user_id' => $dueno]);

        // Descuentos: uno atado a un cliente (se borra) y uno general (se conserva).
        DB::table('discounts')->insert(['name' => 'Descuento del cliente', 'percentage' => 10, 'user_id' => $dueno, 'client_id' => $id_cliente_1]);
        DB::table('discounts')->insert(['name' => 'Descuento general', 'percentage' => 5, 'user_id' => $dueno]);

        // Cola de trabajos (se vacía: los jobs llevan ids de negocio).
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);

        // Configuración con un enlace a negocio que el reset tiene que soltar (o que tiene que conservar).
        DB::table('column_positions')->insert(['name' => 'Mapeo del reset', 'model_name' => 'article', 'start_row' => 2, 'positions' => '{}', 'user_id' => $dueno, 'provider_id' => $id_proveedor]);
        DB::table('balanzas')->insert(['user_id' => $dueno, 'nombre' => 'Balanza del reset', 'prefijo' => '20', 'article_id' => $ids_de_articulos[0]]);
        DB::table('pdf_column_profiles')->insert(['user_id' => $dueno, 'model_name' => 'sale', 'name' => 'Perfil del reset', 'columns' => '[]']);

        // Permiso del dueño (la base del slot no trae permisos de empresa: se usa un id cualquiera, sin FK).
        $id_de_permiso = DB::table('permission_empresas')->orderBy('id')->value('id');
        DB::table('permission_empresa_user')->insert(['permission_empresa_id' => $id_de_permiso === null ? 1 : $id_de_permiso, 'user_id' => $dueno]);

        // Archivos: la imagen del artículo, un logo que NO está en `images`, y una carpeta por id de venta.
        $this->contenido_de_la_imagen = 'imagen-del-articulo-' . $pid;
        $this->contenido_del_logo = 'logo-del-comercio-' . $pid;
        $this->contenido_del_adjunto = 'adjunto-de-la-venta-' . $pid;
        $this->escribir_archivo_del_storage('app/public/reset-test-' . $pid . '.webp', $this->contenido_de_la_imagen);
        $this->escribir_archivo_del_storage('app/public/logo-' . $pid . '.png', $this->contenido_del_logo);
        $this->escribir_archivo_del_storage('app/vender-items/1/x.txt', $this->contenido_del_adjunto);
    }

    /**
     * Escribe un archivo dentro del storage temporal creando las carpetas que falten.
     *
     * @param string $ruta_relativa Ruta relativa a storage_path().
     * @param string $contenido Contenido del archivo.
     * @return string Ruta absoluta.
     */
    protected function escribir_archivo_del_storage($ruta_relativa, $contenido)
    {
        $ruta = storage_path($ruta_relativa);
        if (!is_dir(dirname($ruta))) {
            mkdir(dirname($ruta), 0775, true);
        }
        file_put_contents($ruta, $contenido);

        return $ruta;
    }

    /**
     * Manda un pedido al endpoint del reset.
     *
     * @param array $datos Cuerpo JSON.
     * @param bool|string $clave true = la clave correcta; false = sin header; un string = esa clave.
     * @param array $cabeceras Cabeceras extra.
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedir_el_reset(array $datos = [], $clave = true, array $cabeceras = [])
    {
        if ($clave === true) {
            $cabeceras['X-Admin-Api-Key'] = $this->clave_de_admin_del_test;
        } elseif (is_string($clave)) {
            $cabeceras['X-Admin-Api-Key'] = $clave;
        }

        return $this->postJson($this->ruta_del_reset, $datos, $cabeceras);
    }

    /**
     * Pide la simulación (sin `dry_run`, como la manda el admin por defecto).
     *
     * @param array $extra Parámetros extra (flags, solicitante...).
     * @return \Illuminate\Testing\TestResponse
     */
    protected function simular_el_reset(array $extra = [])
    {
        return $this->pedir_el_reset($extra);
    }

    /**
     * Pide la ejecución real con los tres datos de confirmación.
     *
     * @param string|null $token Token de la simulación (null = no se manda).
     * @param array $extra Parámetros extra.
     * @param string|null $base Nombre de base a confirmar (null = el correcto).
     * @return \Illuminate\Testing\TestResponse
     */
    protected function ejecutar_el_reset($token, array $extra = [], $base = null)
    {
        $datos = ['dry_run' => false, 'confirmar_base_de_datos' => $base === null ? $this->base_descartable : $base];
        if ($token !== null) {
            $datos['token_de_simulacion'] = $token;
        }

        return $this->pedir_el_reset(array_merge($datos, $extra));
    }

    /**
     * Simula y devuelve el token, exigiendo que la simulación haya sido ejecutable.
     *
     * @param array $extra Parámetros extra de la simulación.
     * @return string Token de 40 caracteres.
     */
    protected function obtener_un_token_de_simulacion(array $extra = [])
    {
        $respuesta = $this->simular_el_reset($extra);
        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('ejecutable'), 'La simulación del fixture tendría que ser ejecutable: ' . $respuesta->getContent());
        $token = $respuesta->json('token_de_simulacion');
        $this->assertIsString($token);
        $this->assertSame(40, strlen($token));

        return $token;
    }

    /**
     * Simula y ejecuta de corrido.
     *
     * @param array $extra Parámetros extra para las dos llamadas.
     * @return \Illuminate\Testing\TestResponse La respuesta de la ejecución.
     */
    protected function simular_y_ejecutar_el_reset(array $extra = [])
    {
        $token = $this->obtener_un_token_de_simulacion($extra);

        return $this->ejecutar_el_reset($token, $extra);
    }

    /**
     * Cantidad de filas por tabla de TODA la base descartable (y max(id) cuando la tabla tiene `id`).
     *
     * @param array $excluir Tablas que no se miden.
     * @return array tabla => ['filas' => int, 'max_id' => int|null]
     */
    protected function foto_de_las_tablas(array $excluir = [])
    {
        $foto = [];
        $tablas = DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $this->base_descartable)
            ->where('TABLE_TYPE', 'BASE TABLE')
            ->orderBy('TABLE_NAME')
            ->pluck('TABLE_NAME')
            ->all();

        foreach ($tablas as $tabla) {
            if (in_array($tabla, $excluir, true)) {
                continue;
            }
            $max_id = Schema::hasColumn($tabla, 'id') ? DB::table($tabla)->max('id') : null;
            $foto[$tabla] = ['filas' => (int) DB::table($tabla)->count(), 'max_id' => $max_id === null ? null : (int) $max_id];
        }

        return $foto;
    }

    /**
     * Tablas de A_VACIAR que hoy existen en la base descartable y tienen filas (tabla => filas).
     *
     * @return array
     */
    protected function tablas_a_vaciar_con_filas()
    {
        $catalogo = $this->clase_del_catalogo;
        $con_filas = [];

        foreach ($catalogo::TABLAS_A_VACIAR as $tabla) {
            if (Schema::hasTable($tabla)) {
                $filas = (int) DB::table($tabla)->count();
                if ($filas > 0) {
                    $con_filas[$tabla] = $filas;
                }
            }
        }

        return $con_filas;
    }

    /**
     * Exige que el negocio y la configuración sigan exactamente como estaban en `$foto` (la tabla de registro del
     * reset se excluye: ella sí cambia con cada intento) y que el archivo de la imagen siga en su lugar.
     *
     * @param array $foto Resultado de foto_de_las_tablas() tomado antes del pedido.
     * @param string $mensaje Contexto para el mensaje de falla.
     * @return void
     */
    protected function assertBaseIntacta(array $foto, $mensaje = '')
    {
        $despues = $this->foto_de_las_tablas(['reset_datos_negocio_registros']);
        unset($foto['reset_datos_negocio_registros']);

        $this->assertEquals($foto, $despues, 'La base no tendría que haber cambiado. ' . $mensaje);
        $this->assertSame(3, (int) DB::table('articles')->count(), 'Los 3 artículos tienen que seguir. ' . $mensaje);
        $this->assertEquals(1500, (float) DB::table('cajas')->orderBy('id')->value('saldo'), 'La caja tiene que seguir con su saldo. ' . $mensaje);
        $this->assertFileExists(storage_path('app/public/reset-test-' . getmypid() . '.webp'));
        $this->assertFileExists(storage_path('app/vender-items/1/x.txt'));
    }

    /**
     * Filas de `reset_datos_negocio_registros` (falla con un mensaje claro si la migración no está).
     *
     * @return \Illuminate\Support\Collection
     */
    protected function filas_del_registro()
    {
        $this->assertTrue(Schema::hasTable('reset_datos_negocio_registros'), 'Falta la tabla reset_datos_negocio_registros (migración del reset).');

        return DB::table('reset_datos_negocio_registros')->orderBy('id')->get();
    }

    /**
     * Valor de AUTO_INCREMENT que muestra SHOW CREATE TABLE (null si no lo muestra, o sea, está en 1).
     *
     * Se usa SHOW CREATE TABLE y no information_schema.TABLES porque este último queda cacheado después de un TRUNCATE.
     *
     * @param string $tabla Nombre de la tabla.
     * @return int|null
     */
    protected function auto_increment_de($tabla)
    {
        $fila = (array) DB::selectOne('SHOW CREATE TABLE `' . $tabla . '`');
        $sql = isset($fila['Create Table']) ? $fila['Create Table'] : '';

        if (preg_match('/AUTO_INCREMENT=(\d+)/', $sql, $coincidencias)) {
            return (int) $coincidencias[1];
        }

        return null;
    }

    /**
     * Lista, de forma recursiva, los archivos de una carpeta (rutas absolutas).
     *
     * @param string $carpeta Carpeta a recorrer.
     * @return array
     */
    protected function archivos_de($carpeta)
    {
        $archivos = [];
        if (!is_dir($carpeta)) {
            return $archivos;
        }

        $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($carpeta, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterador as $archivo) {
            if ($archivo->isFile()) {
                $archivos[] = $archivo->getPathname();
            }
        }

        return $archivos;
    }

    /**
     * Busca, dentro de una carpeta, un archivo con exactamente ese contenido.
     *
     * @param string $carpeta Carpeta donde buscar (recursivo).
     * @param string $contenido Contenido buscado.
     * @return string|null Ruta absoluta o null si no está.
     */
    protected function buscar_archivo_con_contenido($carpeta, $contenido)
    {
        foreach ($this->archivos_de($carpeta) as $ruta) {
            if (file_get_contents($ruta) === $contenido) {
                return $ruta;
            }
        }

        return null;
    }

    /**
     * Toma el candado de los setups como si hubiera otra corrida en marcha.
     *
     * @return void
     */
    protected function tomar_el_candado_de_los_setups()
    {
        $this->candado_del_test = DemoSetupLockHelper::tomar();
        $this->assertNotFalse($this->candado_del_test, 'El test no pudo tomar el candado.');
    }

    /**
     * Exige que el candado haya quedado libre (que el pedido lo soltó en su `finally`).
     *
     * @return void
     */
    protected function assertCandadoLibre()
    {
        $manija = DemoSetupLockHelper::tomar();
        $this->assertNotFalse($manija, 'El pedido dejó el candado tomado.');
        DemoSetupLockHelper::soltar($manija);
    }

    /**
     * Restaura el respaldo `.sql.gz` en una base aparte (sin tocar la descartable) y devuelve su nombre, para poder
     * contar filas de lo que el respaldo trae. Que el volcado se pueda cargar es lo que lo hace un respaldo.
     *
     * @param string $ruta_del_gz Ruta absoluta del archivo `.sql.gz`.
     * @return string Nombre de la base donde quedó restaurado.
     */
    protected function restaurar_el_respaldo($ruta_del_gz)
    {
        $sql = gzdecode(file_get_contents($ruta_del_gz));
        $this->assertNotFalse($sql, 'El respaldo no es un gzip válido.');

        $this->base_de_restauracion = $this->base_descartable . '_rest';
        DB::statement('DROP DATABASE IF EXISTS `' . $this->base_de_restauracion . '`');
        DB::statement('CREATE DATABASE `' . $this->base_de_restauracion . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $c = config('database.connections.' . $this->conexion_del_reset);
        $pdo = new \PDO(
            'mysql:host=' . $c['host'] . ';port=' . $c['port'] . ';dbname=' . $this->base_de_restauracion . ';charset=utf8mb4',
            $c['username'],
            $c['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        // Con varias sentencias en una sola llamada, PDO sólo informa el error de la primera salvo que se
        // recorra cada resultado con nextRowset(): así un INSERT roto a mitad del volcado hace fallar el test.
        $consulta = $pdo->query($sql);
        do {
            // Se consume cada resultado.
        } while ($consulta->nextRowset());

        return $this->base_de_restauracion;
    }

    /**
     * Cantidad de filas de una tabla de la base de restauración.
     *
     * @param string $tabla Tabla.
     * @return int
     */
    protected function filas_restauradas($tabla)
    {
        return (int) DB::table($this->base_de_restauracion . '.' . $tabla)->count();
    }
}
