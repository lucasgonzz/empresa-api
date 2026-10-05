<?php

namespace Tests\Feature\Sucursales;

use App\Http\Controllers\Helpers\address\AjusteDePreciosDeSucursalHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Archivo 5 — LA GUARDA DE ESQUEMA: sin las columnas todavia, se puede crear y editar una sucursal.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 LA VENTANA QUE ESTO TAPA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Un deploy de empresa sube los archivos ANTES de migrar (`DeploymentService::execute_steps()` de
 *  admin-api: `upload_api` -> `sync_env_keys` -> `run_migrations`). En esa ventana el cliente tiene
 *  este codigo y no tiene `ajuste_precio_tipo` / `ajuste_precio_porcentaje`. Si el controlador las
 *  nombrara a secas, crear o editar una sucursal daria un 500 (`Unknown column`) hasta que termine la
 *  migracion. La guarda hace que, sin las columnas, el ajuste se ignore y todo lo demas siga andando.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  ⚠️ COMO SE ESCONDEN LAS COLUMNAS (tecnica de `tests/Feature/RecargosEnPrecios/7`)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *   1. El DDL de MySQL hace COMMIT IMPLICITO de la sesion que lo corre: el ALTER va por una conexion
 *      PDO APARTE, para no cerrar la transaccion del test.
 *   2. En MySQL 8 `information_schema` responde con el snapshot de la transaccion REPEATABLE READ
 *      abierta: se cierra la transaccion del trait ANTES del rename y se abre una nueva despues. Sin
 *      eso la guarda seguiria viendo las columnas y los tests darian VERDE EN FALSO.
 *
 *  Se RENOMBRAN y no se borran: si algo se corta a la mitad, las columnas siguen enteras bajo el otro
 *  nombre y `setUp()` las devuelve solas en la corrida siguiente.
 *
 * @group sucursales
 */
class Ajuste_de_precios_guarda_de_esquema_Test extends SucursalesTestCase
{
    /** Las dos columnas de la guarda y el nombre al que se corren para que no las encuentre. */
    const SUFIJO_ESCONDIDA = '_escondida_por_el_test';

    /**
     * @return array<int,string>
     */
    protected function columnas()
    {
        return [
            AjusteDePreciosDeSucursalHelper::COLUMNA_TIPO,
            AjusteDePreciosDeSucursalHelper::COLUMNA_PORCENTAJE,
        ];
    }

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurar_columnas_si_quedaron_escondidas();

        AjusteDePreciosDeSucursalHelper::olvidar();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        AjusteDePreciosDeSucursalHelper::olvidar();

        parent::tearDown();
    }

    /**
     * Conexion PDO aparte contra la MISMA base, armada con la config de Laravel.
     *
     * @return \PDO
     */
    protected function conexion_aparte()
    {
        $config = config('database.connections.'.config('database.default'));

        $dsn = 'mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'];

        $pdo = new \PDO($dsn, $config['username'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        /* Sin esto, un metadata lock cuelga la suite: el default de MySQL para MDL es 1 año. */
        $pdo->exec('SET SESSION lock_wait_timeout = 10');

        return $pdo;
    }

    /**
     * Renombra cada una de las dos columnas de `addresses` que este con el nombre `$de` (+ sufijo).
     *
     * @param  bool  $esconder  true = de `columna` a `columna<sufijo>`; false = de vuelta.
     * @return void
     */
    protected function renombrar_columnas($esconder)
    {
        $pdo = $this->conexion_aparte();

        foreach ($this->columnas() as $columna) {

            $de = $esconder ? $columna : $columna.self::SUFIJO_ESCONDIDA;
            $a  = $esconder ? $columna.self::SUFIJO_ESCONDIDA : $columna;

            $existe = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ".
                "AND TABLE_NAME = 'addresses' AND COLUMN_NAME = '".$de."'"
            )->fetchColumn();

            if ((int) $existe === 0) {
                continue;
            }

            $pdo->exec('ALTER TABLE `addresses` RENAME COLUMN `'.$de.'` TO `'.$a.'`');
        }

        AjusteDePreciosDeSucursalHelper::olvidar();
    }

    /**
     * Red de seguridad: si una corrida anterior se corto con las columnas escondidas, se devuelven.
     *
     * @return void
     */
    protected function restaurar_columnas_si_quedaron_escondidas()
    {
        foreach ($this->columnas() as $columna) {

            if (Schema::hasColumn('addresses', $columna.self::SUFIJO_ESCONDIDA)) {

                $this->renombrar_columnas(false);

                return;
            }
        }
    }

    /**
     * Corre el cuerpo con la base SIN las dos columnas.
     *
     * Antes del cuerpo verifica el escenario: sin esa asercion de control, un test que no lograra
     * esconder las columnas pasaria igual —con las columnas puestas— sin probar nada.
     *
     * @param  \Closure  $cuerpo
     * @return void
     */
    protected function sin_las_columnas($cuerpo)
    {
        DB::rollBack();

        $this->renombrar_columnas(true);

        DB::beginTransaction();

        try {

            foreach ($this->columnas() as $columna) {
                $this->assertFalse(
                    Schema::hasColumn('addresses', $columna),
                    'El escenario no se armo: la tabla sigue teniendo '.$columna.'.'
                );
            }

            $this->assertFalse(
                AjusteDePreciosDeSucursalHelper::columnas_existen(),
                'El escenario no se armo: la guarda sigue viendo las columnas.'
            );

            $cuerpo();

        } finally {

            DB::rollBack();

            $this->renombrar_columnas(false);

            /* Para que el rollback del tearDown del trait tenga una transaccion que cerrar. */
            DB::beginTransaction();
        }
    }

    /**
     * Test 8 del plan — sin las columnas se puede CREAR una sucursal aunque el request traiga el
     * ajuste (se descarta, no se escribe), EDITARLA, y hasta mandar un ajuste que seria invalido: sin
     * columnas no hay nada que proteger y no tiene sentido devolver un 422 por algo que no se va a
     * guardar. La lectura sigue andando.
     *
     * @test
     */
    public function sin_las_columnas_se_puede_crear_editar_y_leer_una_sucursal()
    {
        $self = $this;

        $this->sin_las_columnas(function () use ($self) {

            /* Alta con un ajuste valido: se crea y el ajuste no se escribe en ningun lado. */
            $response = $self->postJson('api/address', $self->payload_sucursal([
                'ajuste_precio_tipo'       => 'recargo',
                'ajuste_precio_porcentaje' => 10,
            ]));

            $response->assertStatus(201);

            $id = (int) $response->json('model.id');

            $self->assertArrayNotHasKey('ajuste_precio_tipo', $response->json('model'), 'Sin la columna, el modelo no la trae.');

            $fila = DB::table('addresses')->where('id', $id)->first();

            $self->assertNotNull($fila, 'La sucursal se tiene que haber creado.');

            foreach ($self->columnas() as $columna) {
                $self->assertNull(
                    $fila->{$columna.self::SUFIJO_ESCONDIDA},
                    'Sin la guarda, el controlador habria nombrado '.$columna.' y explotado; con la guarda no escribe nada.'
                );
            }

            /* Edicion con un ajuste valido y con el resto de los campos: se actualizan los campos. */
            $self->putJson('api/address/'.$id, $self->payload_sucursal([
                'street'                   => 'zz Sucursal editada sin las columnas',
                'ajuste_precio_tipo'       => 'descuento',
                'ajuste_precio_porcentaje' => 5,
            ]))->assertStatus(200);

            $self->assertSame(
                'zz Sucursal editada sin las columnas',
                DB::table('addresses')->where('id', $id)->value('street'),
                'El PUT tiene que haber actualizado la calle aunque el ajuste se haya descartado.'
            );

            /* Un ajuste que con las columnas seria un 422 se ignora: no hay nada que escribir. */
            $self->postJson('api/address', $self->payload_sucursal([
                'ajuste_precio_tipo'       => 'otro',
                'ajuste_precio_porcentaje' => 'abc',
            ]))->assertStatus(201);

            /* Y la lectura del listado y de la sucursal. */
            $self->getJson('api/address')->assertStatus(200);
            $self->getJson('api/address/'.$id)->assertStatus(200);
        });
    }

    /**
     * Test 9 — con una sola de las dos columnas la guarda tambien dice "no": la invariante (las dos o
     * ninguna) no se puede cumplir escribiendo una sola, asi que una base a medio migrar no escribe
     * el ajuste.
     *
     * @test
     */
    public function con_una_sola_de_las_dos_columnas_la_guarda_dice_que_no()
    {
        DB::rollBack();

        $pdo = $this->conexion_aparte();

        $columna = AjusteDePreciosDeSucursalHelper::COLUMNA_PORCENTAJE;

        $pdo->exec('ALTER TABLE `addresses` RENAME COLUMN `'.$columna.'` TO `'.$columna.self::SUFIJO_ESCONDIDA.'`');

        DB::beginTransaction();

        try {

            AjusteDePreciosDeSucursalHelper::olvidar();

            $this->assertFalse(
                AjusteDePreciosDeSucursalHelper::columnas_existen(),
                'Con solo el tipo presente, la guarda tiene que responder que las columnas no existen.'
            );

        } finally {

            DB::rollBack();

            $pdo->exec('ALTER TABLE `addresses` RENAME COLUMN `'.$columna.self::SUFIJO_ESCONDIDA.'` TO `'.$columna.'`');

            AjusteDePreciosDeSucursalHelper::olvidar();

            DB::beginTransaction();
        }
    }

    /**
     * Test 10 — CON las dos columnas, la guarda las ve. Es la no-regresion: una guarda que dijera
     * siempre "no" pasaria los tests de arriba, y el resto de la suite daria rojo por otro lado sin
     * decir por que.
     *
     * @test
     */
    public function con_las_dos_columnas_la_guarda_las_ve()
    {
        AjusteDePreciosDeSucursalHelper::olvidar();

        $this->assertTrue(
            AjusteDePreciosDeSucursalHelper::columnas_existen(),
            'La base del slot tiene que tener las dos columnas de addresses (¿falta migrar?).'
        );
    }
}
