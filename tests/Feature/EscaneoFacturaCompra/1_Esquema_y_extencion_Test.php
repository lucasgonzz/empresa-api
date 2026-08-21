<?php

namespace Tests\Feature\EscaneoFacturaCompra;

use App\Models\ExtencionEmpresa;
use App\Models\ProviderOrderScan;
use Database\Seeders\ExtencionEscaneoFacturaCompraProduccionSeeder;
use Database\Seeders\ExtencionEscaneoFacturaCompraSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Misión escaneo-factura-compra — archivo 1: los cimientos. Las dos tablas nuevas con sus
 * columnas, tipos e índices; los defaults que hacen que un escaneo recién creado nazca en
 * 'pendiente' con progreso 0; el alta idempotente de la extensión por las dos vías; y las dos
 * piezas del modelo que el resto de la misión usa sin volver a chequear (el criterio del botón
 * rojo y los casts de JSON).
 *
 * Lo que protege, en una línea cada cosa:
 *
 *  - Los bloques B, C y D se compilan contra ESTOS nombres de columna. Un typo acá no lo avisa
 *    ni PHP ni Vue: se descubre en producción, con la foto ya subida.
 *  - `esta_pendiente_de_revisar()` es la definición ÚNICA del botón rojo. Si alguien la relaja
 *    a "no visto" o la endurece a "confirmado", el botón queda prendido para siempre o no se
 *    prende nunca, y las dos fallas son silenciosas.
 *  - Los seeders se corren a mano sobre bases de clientes que ya existen. Correr uno dos veces
 *    tiene que ser inofensivo.
 *
 * El test del gate por extensión (403 sin ella) NO vive acá sino en el archivo 3: la ruta la
 * declara este bloque pero el controlador lo crea el bloque C, y sin la clase del controlador
 * Laravel devuelve 500 y no 403.
 */
class Esquema_y_extencion_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea todo el módulo. */
    const SLUG = 'escaneo_factura_compra';

    /** Nombre con el que la extensión se muestra en el configurador. */
    const NOMBRE = 'Escaneo de facturas con IA';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        /*
         * Este archivo no llama a la IA, pero la clave real vive en el .env.testing y la cola de
         * phpunit corre en sync: si algún día alguien agrega acá un test que dispara el job, sin
         * esta línea la suite sale a la API de Anthropic de verdad y gasta plata.
         */
        config(['services.anthropic.api_key' => null]);
    }

    /**
     * Detalle de tipo, largo, nulabilidad y default de una columna, vía information_schema.
     *
     * @param  string $tabla
     * @param  string $columna
     * @return object|null
     */
    protected function detalle_de_columna($tabla, $columna)
    {
        return DB::selectOne(
            "SELECT DATA_TYPE as tipo, CHARACTER_MAXIMUM_LENGTH as largo,
                    IS_NULLABLE as nullable, COLUMN_DEFAULT as valor_default
             FROM information_schema.columns
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?",
            [$tabla, $columna]
        );
    }

    /**
     * true si el índice existe en la tabla.
     *
     * @param  string $tabla
     * @param  string $nombre_indice
     * @return bool
     */
    protected function indice_existe($tabla, $nombre_indice)
    {
        $filas = DB::select("SHOW INDEX FROM {$tabla} WHERE Key_name = '{$nombre_indice}'");

        return !empty($filas);
    }

    /**
     * Chequea una columna contra lo que dice el plan: que exista, que sea del tipo esperado,
     * que tenga el largo esperado (si es de texto) y que la nulabilidad sea la esperada.
     *
     * @param  string      $tabla
     * @param  string      $columna
     * @param  string      $tipo_esperado     DATA_TYPE de MySQL: varchar, int, bigint, tinyint, text, longtext, timestamp.
     * @param  int|null    $largo_esperado    Solo para columnas de texto; null si no aplica.
     * @param  bool        $deberia_ser_null  true si la columna tiene que aceptar NULL.
     * @return void
     */
    protected function assert_columna($tabla, $columna, $tipo_esperado, $largo_esperado, $deberia_ser_null)
    {
        $detalle = $this->detalle_de_columna($tabla, $columna);

        $this->assertNotNull($detalle, "Falta la columna {$tabla}.{$columna}.");

        $this->assertEquals(
            $tipo_esperado,
            $detalle->tipo,
            "{$tabla}.{$columna} tendría que ser {$tipo_esperado} y es {$detalle->tipo}."
        );

        if (!is_null($largo_esperado)) {
            $this->assertEquals(
                $largo_esperado,
                (int) $detalle->largo,
                "{$tabla}.{$columna} tendría que tener largo {$largo_esperado}."
            );
        }

        $nulabilidad_esperada = $deberia_ser_null ? 'YES' : 'NO';

        $this->assertEquals(
            $nulabilidad_esperada,
            $detalle->nullable,
            "{$tabla}.{$columna} tendría que ser " . ($deberia_ser_null ? 'nullable' : 'NOT NULL') . '.'
        );
    }

    /**
     * Deja la base sin la fila de la extensión, para que los tests de seeders prueben el alta
     * de verdad y no se apoyen en que la base del slot ya venía sembrada.
     *
     * Se puede borrar sin miedo: `extencion_empresas` no tiene foreign keys apuntándole (el
     * pivot `extencion_empresa_user` se creó sin constraints) y todo el archivo corre adentro
     * de una transacción que se revierte al terminar.
     *
     * @return void
     */
    protected function borrar_la_extension()
    {
        ExtencionEmpresa::where('slug', self::SLUG)->delete();
    }

    /**
     * 1 — Las 15 filas de la tabla de §1.1 (13 columnas propias + el id + los timestamps),
     * cada una con su tipo y su nulabilidad.
     *
     * @group escaneo-factura-compra
     * @test
     */
    public function provider_order_scans_tiene_sus_columnas_con_el_tipo_y_la_nulabilidad_del_plan()
    {
        $this->assertTrue(
            Schema::hasTable('provider_order_scans'),
            'La migración 2026_08_21_150000 tiene que crear provider_order_scans.'
        );

        /* [columna, tipo, largo, acepta null] */
        $columnas = [
            ['id',                'bigint',    null, false],
            ['uuid',              'varchar',   64,   false],
            ['user_id',           'int',       null, false],
            ['auth_user_id',      'int',       null, true],
            ['provider_order_id', 'int',       null, false],
            ['estado',            'varchar',   32,   false],
            ['progreso',          'tinyint',   null, false],
            ['paso',              'varchar',   160,  true],
            ['error',             'text',      null, true],
            ['resultado',         'longtext',  null, true],
            ['visto_at',          'timestamp', null, true],
            ['gestionado_at',     'timestamp', null, true],
            ['resultado_gestion', 'varchar',   16,   true],
            ['aplicado',          'text',      null, true],
            ['created_at',        'timestamp', null, true],
            ['updated_at',        'timestamp', null, true],
        ];

        foreach ($columnas as $columna) {
            $this->assert_columna('provider_order_scans', $columna[0], $columna[1], $columna[2], $columna[3]);
        }

        /*
         * La aserción que da sentido al test: `resultado` tiene que ser longtext y no text. Una
         * factura de 80 renglones con confianzas por campo pasa cómodo los 64 KB de un text, y
         * MySQL trunca en silencio (o tira un error de datos, según el modo): el escaneo queda
         * en 'listo' con un JSON cortado que ya no parsea.
         */
        $detalle_resultado = $this->detalle_de_columna('provider_order_scans', 'resultado');
        $this->assertEquals('longtext', $detalle_resultado->tipo);

        /*
         * auth_user_id nullable a propósito: un escaneo sin auth_user_id no le avisa a nadie,
         * que es mejor que avisarle a los cuatro compañeros del que sacó la foto.
         */
        $detalle_auth = $this->detalle_de_columna('provider_order_scans', 'auth_user_id');
        $this->assertEquals(
            'YES',
            $detalle_auth->nullable,
            'auth_user_id tiene que ser nullable: sin él el escaneo no notifica, pero corre igual.'
        );
    }

    /**
     * 2 — Las 10 filas de la tabla de §1.2 (8 columnas propias + el id + los timestamps).
     *
     * @group escaneo-factura-compra
     * @test
     */
    public function provider_order_scan_images_tiene_sus_columnas_con_el_tipo_y_la_nulabilidad_del_plan()
    {
        $this->assertTrue(
            Schema::hasTable('provider_order_scan_images'),
            'La migración 2026_08_21_150100 tiene que crear provider_order_scan_images.'
        );

        $columnas = [
            ['id',                     'bigint',    null, false],
            ['provider_order_scan_id', 'bigint',    null, false],
            ['provider_order_id',      'int',       null, false],
            ['user_id',                'int',       null, false],
            ['orden',                  'tinyint',   null, false],
            ['path',                   'varchar',   191,  false],
            ['mime',                   'varchar',   40,   true],
            ['bytes',                  'int',       null, true],
            ['nombre_original',        'varchar',   191,  true],
            ['created_at',             'timestamp', null, true],
            ['updated_at',             'timestamp', null, true],
        ];

        foreach ($columnas as $columna) {
            $this->assert_columna('provider_order_scan_images', $columna[0], $columna[1], $columna[2], $columna[3]);
        }

        /*
         * provider_order_scan_id es bigint porque provider_order_scans.id es bigIncrements. Si
         * quedara int, el insert de la foto de un escaneo con id alto explota o trunca, y recién
         * se ve en el cliente más grande.
         */
        $detalle_fk = $this->detalle_de_columna('provider_order_scan_images', 'provider_order_scan_id');
        $this->assertEquals(
            'bigint',
            $detalle_fk->tipo,
            'provider_order_scan_id tiene que ser bigint: apunta a un bigIncrements.'
        );

        /*
         * Las dos columnas desnormalizadas a propósito. No son un descuido de normalización:
         * son las que dejan chequear tenencia sin join en el endpoint que sirve el binario de
         * la factura.
         */
        $this->assertTrue(Schema::hasColumn('provider_order_scan_images', 'provider_order_id'));
        $this->assertTrue(Schema::hasColumn('provider_order_scan_images', 'user_id'));
    }

    /**
     * 3 — Los 5 índices de la primera tabla y los 3 de la segunda.
     *
     * @group escaneo-factura-compra
     * @test
     */
    public function las_dos_tablas_tienen_los_indices_del_plan()
    {
        foreach (['pos_uuid_idx', 'pos_user_idx', 'pos_order_idx'] as $indice) {
            $this->assertTrue(
                $this->indice_existe('provider_order_scans', $indice),
                "Falta el índice {$indice} en provider_order_scans."
            );
        }

        /*
         * El índice del botón rojo: "escaneos listos y sin gestionar de este comercio". Es la
         * consulta que corre en cada carga del listado de compras; sin el índice compuesto es un
         * full scan de una tabla que solo crece (de acá no se borra nunca nada).
         */
        $this->assertTrue(
            $this->indice_existe('provider_order_scans', 'pos_pendientes_idx'),
            'Falta pos_pendientes_idx (user_id, estado, gestionado_at): es la consulta del botón rojo.'
        );

        /* El índice de /en-curso, que arranca en cada carga de la SPA. */
        $this->assertTrue(
            $this->indice_existe('provider_order_scans', 'pos_auth_user_idx'),
            'Falta pos_auth_user_idx (auth_user_id, id).'
        );

        foreach (['posi_scan_idx', 'posi_order_idx', 'posi_user_idx'] as $indice) {
            $this->assertTrue(
                $this->indice_existe('provider_order_scan_images', $indice),
                "Falta el índice {$indice} en provider_order_scan_images."
            );
        }
    }

    /**
     * 4 — Los defaults. Un escaneo recién insertado tiene que nacer en 'pendiente' con progreso
     * 0 aunque el que lo inserta no mande ninguna de las dos columnas.
     *
     * @group escaneo-factura-compra
     * @test
     */
    public function estado_defaultea_a_pendiente_y_progreso_a_cero()
    {
        $detalle_estado = $this->detalle_de_columna('provider_order_scans', 'estado');
        $this->assertEquals(
            'pendiente',
            $detalle_estado->valor_default,
            "El default de estado tiene que ser 'pendiente': es el estado con el que el controlador crea el escaneo."
        );

        $detalle_progreso = $this->detalle_de_columna('provider_order_scans', 'progreso');
        $this->assertNotNull($detalle_progreso->valor_default, 'progreso tiene que tener default.');
        $this->assertEquals(0, (int) $detalle_progreso->valor_default);

        $detalle_orden = $this->detalle_de_columna('provider_order_scan_images', 'orden');
        $this->assertNotNull($detalle_orden->valor_default, 'orden tiene que tener default.');
        $this->assertEquals(1, (int) $detalle_orden->valor_default);

        /* La prueba de fuego del default: insertar sin esas columnas y leer lo que quedó. */
        $scan = ProviderOrderScan::create([
            'uuid'              => 'esquema-defaults-' . uniqid(),
            'user_id'           => 987654,
            'provider_order_id' => 123456,
        ]);

        $scan = $scan->fresh();

        $this->assertEquals('pendiente', $scan->estado);
        $this->assertEquals(0, (int) $scan->progreso);
    }

    /**
     * 5 — El seeder de la extensión crea la fila con el slug y el nombre exactos.
     *
     * 🔴 Los seeders se corren vía `db:seed` (Artisan) y NUNCA instanciándolos y llamando
     * ->run() a mano: el comando real de Laravel envuelve la corrida en Model::unguarded()
     * (SeedCommand::handle()), que es lo que permite que ExtencionEmpresa::firstOrCreate()
     * funcione pese a que el modelo no declara $fillable. Instanciar y correr a mano revienta
     * con MassAssignmentException — probado. Replicar la forma real de invocación es lo que
     * hace al test fiel a producción.
     *
     * @group escaneo-factura-compra
     * @test
     */
    public function el_seeder_crea_la_extension_con_el_slug_y_el_nombre_exactos()
    {
        $this->borrar_la_extension();

        $this->artisan('db:seed', ['--class' => ExtencionEscaneoFacturaCompraSeeder::class, '--force' => true])
            ->assertExitCode(0);

        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        $this->assertNotNull(
            $extencion,
            'El seeder tiene que dejar la fila con slug ' . self::SLUG . ': es el slug que gatea las rutas y el botón.'
        );

        $this->assertEquals(self::NOMBRE, $extencion->name);
    }

    /**
     * 6 — Idempotencia: correrlo dos veces deja UNA sola fila.
     *
     * @group escaneo-factura-compra
     * @test
     */
    public function correr_el_seeder_dos_veces_deja_una_sola_fila()
    {
        $this->borrar_la_extension();

        $this->artisan('db:seed', ['--class' => ExtencionEscaneoFacturaCompraSeeder::class, '--force' => true])
            ->assertExitCode(0);
        $this->artisan('db:seed', ['--class' => ExtencionEscaneoFacturaCompraSeeder::class, '--force' => true])
            ->assertExitCode(0);

        $this->assertEquals(
            1,
            ExtencionEmpresa::where('slug', self::SLUG)->count(),
            'El seeder usa firstOrCreate: correrlo dos veces no puede duplicar la extensión.'
        );
    }

    /**
     * 7 — El seeder suelto de producción, corrido sobre una base que YA tiene la fila, no la
     * duplica ni la pisa.
     *
     * Es el caso real: se corre sobre la base de un cliente en la que la extensión puede haber
     * quedado renombrada a mano desde el configurador. Pisarle el nombre sería cambiarle la
     * pantalla al cliente sin que nadie se lo haya pedido.
     *
     * @group escaneo-factura-compra
     * @test
     */
    public function el_seeder_de_produccion_no_duplica_ni_pisa_la_fila_que_ya_existe()
    {
        $this->borrar_la_extension();

        $nombre_a_mano = 'Escaneo de facturas con IA (renombrada a mano)';

        /* forceCreate: el modelo ExtencionEmpresa no declara $fillable. */
        ExtencionEmpresa::forceCreate([
            'slug' => self::SLUG,
            'name' => $nombre_a_mano,
        ]);

        $this->artisan('db:seed', ['--class' => ExtencionEscaneoFacturaCompraProduccionSeeder::class, '--force' => true])
            ->assertExitCode(0);

        $this->assertEquals(
            1,
            ExtencionEmpresa::where('slug', self::SLUG)->count(),
            'El seeder de producción no puede dejar dos filas para el mismo slug.'
        );

        $this->assertEquals(
            $nombre_a_mano,
            ExtencionEmpresa::where('slug', self::SLUG)->first()->name,
            'firstOrCreate no pisa la fila existente: el nombre que el cliente tenía se respeta.'
        );

        /* Y sobre una base que no la tenía, la crea igual. */
        $this->borrar_la_extension();

        $this->artisan('db:seed', ['--class' => ExtencionEscaneoFacturaCompraProduccionSeeder::class, '--force' => true])
            ->assertExitCode(0);

        $this->assertEquals(1, ExtencionEmpresa::where('slug', self::SLUG)->count());
        $this->assertEquals(self::NOMBRE, ExtencionEmpresa::where('slug', self::SLUG)->first()->name);
    }

    /**
     * 8 — 🔴 LA aserción que protege la definición del botón rojo.
     *
     * "Pendiente de revisar" es estado === 'listo' Y gestionado_at IS NULL. No es "no visto"
     * (mirar el aviso no asienta nada en la compra) y no es "sin confirmar" (un escaneo
     * descartado tampoco tiene que encender el botón). Si alguien relaja o endurece este
     * criterio, el botón queda prendido para siempre o no se prende nunca — y las dos fallas
     * son silenciosas, no hay excepción que las delate.
     *
     * @group escaneo-factura-compra
     * @test
     */
    public function esta_pendiente_de_revisar_es_listo_y_sin_gestionar_y_nada_mas()
    {
        $listo = $this->crear_scan('listo', null);

        $this->assertTrue(
            $listo->esta_pendiente_de_revisar(),
            "Un escaneo en 'listo' y sin gestionar es EL caso que enciende el botón rojo."
        );

        /* Los otros tres estados, todos sin gestionar: ninguno enciende el botón. */
        foreach (['pendiente', 'procesando', 'error'] as $estado) {
            $scan = $this->crear_scan($estado, null);

            $this->assertFalse(
                $scan->esta_pendiente_de_revisar(),
                "Un escaneo en '{$estado}' no tiene nada que revisar todavía."
            );
        }

        /* Listo pero ya resuelto (confirmado o descartado): el botón se apaga. */
        $confirmado = $this->crear_scan('listo', now());

        $this->assertFalse(
            $confirmado->esta_pendiente_de_revisar(),
            'gestionado_at seteado apaga el botón rojo, sin importar cómo haya terminado.'
        );

        /*
         * visto_at NO apaga el botón: el usuario puede haber visto el aviso y apretado
         * "Después". Esta es la mitad del criterio que más fácil se confunde.
         */
        $visto_pero_sin_gestionar = $this->crear_scan('listo', null);
        $visto_pero_sin_gestionar->update(['visto_at' => now()]);

        $this->assertTrue(
            $visto_pero_sin_gestionar->fresh()->esta_pendiente_de_revisar(),
            'Ver el aviso no es resolver el escaneo: el botón rojo tiene que seguir prendido.'
        );
    }

    /**
     * 9 — Los casts: lo que se guarda como array vuelve como array, y las dos fechas vuelven
     * como Carbon.
     *
     * Sin el cast de `resultado`, el modal de revisión recibe un string JSON y el frontend lo
     * tendría que parsear en cada lugar donde lo toca; y `count($scan->resultado['articulos'])`
     * —que es como el endpoint de pendientes calcula la cantidad— explota.
     *
     * @group escaneo-factura-compra
     * @test
     */
    public function los_casts_devuelven_arrays_y_fechas()
    {
        $resultado = [
            'version'   => 1,
            'articulos' => [
                ['fila' => 1, 'nombre' => 'MARTILLO ACERO 500G', 'cantidad' => 12],
                ['fila' => 2, 'nombre' => 'PINZA UNIVERSAL 8"', 'cantidad' => 6],
            ],
            'factura'   => ['es_factura_afip' => true, 'total' => 124300],
            'avisos'    => ['La última fila de la página 1 continuaba en la página 2.'],
        ];

        $aplicado = [
            'articulos_agregados' => 12,
            'articulos_creados'   => 2,
            'factura_guardada'    => 'completa',
        ];

        $scan = $this->crear_scan('listo', now());
        $scan->update([
            'resultado'         => $resultado,
            'aplicado'          => $aplicado,
            'visto_at'          => now(),
            'resultado_gestion' => 'confirmado',
        ]);

        /* fresh(): se lee de la base de verdad, no del array que quedó en memoria. */
        $leido = $scan->fresh();

        $this->assertIsArray($leido->resultado);
        $this->assertEquals($resultado, $leido->resultado);
        $this->assertCount(2, $leido->resultado['articulos']);
        $this->assertEquals('PINZA UNIVERSAL 8"', $leido->resultado['articulos'][1]['nombre']);

        $this->assertIsArray($leido->aplicado);
        $this->assertEquals(12, $leido->aplicado['articulos_agregados']);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $leido->visto_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $leido->gestionado_at);
    }

    /**
     * Crea un escaneo suelto para probar el modelo.
     *
     * No necesita una compra de verdad: las columnas no tienen foreign keys, y lo que se está
     * probando acá es el criterio del modelo, no la integridad referencial. Todo corre adentro
     * de la transacción del test.
     *
     * @param  string $estado
     * @param  \Illuminate\Support\Carbon|null $gestionado_at
     * @return \App\Models\ProviderOrderScan
     */
    protected function crear_scan($estado, $gestionado_at)
    {
        return ProviderOrderScan::create([
            'uuid'              => 'esquema-' . $estado . '-' . uniqid(),
            'user_id'           => 987654,
            'auth_user_id'      => 987655,
            'provider_order_id' => 123456,
            'estado'            => $estado,
            'gestionado_at'     => $gestionado_at,
        ]);
    }
}
