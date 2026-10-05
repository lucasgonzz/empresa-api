<?php

namespace Tests\Feature\IvaEnArticulosSinIva;

use App\Http\Controllers\Helpers\sale\ConsolidarFacturacionHelper;
use App\Http\Controllers\Helpers\sale\IvaEnArticulosSinIvaEsquemaHelper;
use App\Models\AfipInformation;
use App\Models\Budget;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Archivo 3 — LA GUARDA DE ESQUEMA: se puede vender y presupuestar aunque la columna todavia no
 * exista.
 *
 * Un deploy de empresa sube los archivos ANTES de migrar (`DeploymentService::execute_steps()` de
 * admin-api: `upload_api` -> `sync_env_keys` -> `run_migrations`). `Sale` y `Budget` declaran
 * `$guarded = []`: sin `IvaEnArticulosSinIvaEsquemaHelper`, en esa ventana el alta y la edicion de
 * TODA venta y TODO presupuesto morian con `Unknown column 'iva_en_articulos_sin_iva'`.
 *
 * Tecnica de ForzarTotal/9 (y de Presupuestos/4 antes): la columna se RENOMBRA por una conexion PDO
 * aparte (el DDL de MySQL hace commit implicito de la sesion que lo corre), y la transaccion del
 * trait se cierra antes del rename y se abre otra despues (adentro de una transaccion REPEATABLE
 * READ ya abierta, `information_schema` responde con el snapshot y seguiria viendo la columna:
 * estos tests darian verde en falso). Lo que el test escribe va adentro de la transaccion nueva, que
 * se revierte igual.
 *
 * @group sales
 * @group presupuestos
 * @group iva_en_articulos_sin_iva
 */
class Guarda_de_esquema_de_iva_en_articulos_sin_iva_Test extends IvaEnArticulosSinIvaTestCase
{
    /** El nombre al que se corre la columna para que la guarda no la encuentre. */
    const COLUMNA_ESCONDIDA = 'iva_en_articulos_sin_iva_escondida_por_el_test';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurar_columnas_si_quedaron_escondidas();

        IvaEnArticulosSinIvaEsquemaHelper::olvidar();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        IvaEnArticulosSinIvaEsquemaHelper::olvidar();

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
     * Renombra la columna en las tablas que la tengan con el nombre `$desde`.
     *
     * @param  string  $desde
     * @param  string  $hacia
     * @return void
     */
    protected function renombrar_columnas($desde, $hacia)
    {
        $pdo = $this->conexion_aparte();

        foreach (IvaEnArticulosSinIvaEsquemaHelper::TABLAS as $tabla) {

            if (Schema::hasColumn($tabla, $desde)) {
                $pdo->exec('ALTER TABLE `'.$tabla.'` RENAME COLUMN `'.$desde.'` TO `'.$hacia.'`');
            }
        }

        IvaEnArticulosSinIvaEsquemaHelper::olvidar();
    }

    /**
     * Red de seguridad: si una corrida anterior se corto entre los dos renames, la columna quedo
     * con el nombre escondido. Se devuelve sola antes de que ningun test la necesite.
     *
     * @return void
     */
    protected function restaurar_columnas_si_quedaron_escondidas()
    {
        if (!Schema::hasColumn('sales', self::COLUMNA_ESCONDIDA)
            && !Schema::hasColumn('budgets', self::COLUMNA_ESCONDIDA)) {
            return;
        }

        $this->renombrar_columnas(self::COLUMNA_ESCONDIDA, IvaEnArticulosSinIvaEsquemaHelper::COLUMNA);
    }

    /**
     * Corre el cuerpo del test con la base SIN la columna, verificando antes que el escenario se
     * armo (sin esas dos aserciones, un test que no lograra esconderla pasaria igual sin probar nada).
     *
     * @param  \Closure  $cuerpo
     * @return void
     */
    protected function sin_la_columna($cuerpo)
    {
        DB::rollBack();

        $this->renombrar_columnas(IvaEnArticulosSinIvaEsquemaHelper::COLUMNA, self::COLUMNA_ESCONDIDA);

        DB::beginTransaction();

        try {

            $this->assertFalse(
                IvaEnArticulosSinIvaEsquemaHelper::hay_columna('sales'),
                'El escenario no se armo: la guarda sigue viendo la columna en sales.'
            );

            $this->assertFalse(
                IvaEnArticulosSinIvaEsquemaHelper::hay_columna('budgets'),
                'El escenario no se armo: la guarda sigue viendo la columna en budgets.'
            );

            $cuerpo();

        } finally {

            DB::rollBack();

            $this->renombrar_columnas(self::COLUMNA_ESCONDIDA, IvaEnArticulosSinIvaEsquemaHelper::COLUMNA);

            /* Para que el rollback del tearDown del trait tenga una transaccion que cerrar. */
            DB::beginTransaction();
        }
    }

    /**
     * Alta de venta (con la clave en 1, como la mandaria la SPA nueva) y su actualizacion.
     *
     * @test
     */
    public function sin_la_columna_se_puede_guardar_y_actualizar_una_venta()
    {
        $self = $this;

        $this->sin_la_columna(function () use ($self) {

            $modelo = $self->crear_venta_por_endpoint($self->payload_venta([
                'iva_en_articulos_sin_iva' => 1,
            ]));

            $venta = Sale::find($modelo['id']);

            // Con y sin la clave: los dos caminos del update pasan por la guarda.
            $self->putJson('api/sale/'.$venta->id, $self->payload_actualizar_venta($venta, [
                'iva_en_articulos_sin_iva' => 1,
            ]))->assertStatus(200);

            $self->putJson('api/sale/'.$venta->id, $self->payload_actualizar_venta($venta))->assertStatus(200);
        });
    }

    /**
     * Alta de presupuesto, su actualizacion, confirmarlo y duplicarlo.
     *
     * @test
     */
    public function sin_la_columna_se_puede_presupuestar_actualizar_confirmar_y_duplicar()
    {
        $self = $this;

        $this->sin_la_columna(function () use ($self) {

            $self->dar_extencion_duplicar();

            $modelo = $self->crear_presupuesto_por_endpoint($self->payload_presupuesto([
                'iva_en_articulos_sin_iva' => 1,
            ]));

            $budget = Budget::find($modelo['id']);

            /*
             * El PUT va sin renglones, asi que el total va en 0: si quedara el del alta, el
             * duplicado de abajo cortaria con "El total del presupuesto no corresponde con los
             * productos ingresados", que no tiene nada que ver con la guarda.
             */
            $self->putJson('api/budget/'.$budget->id, $self->payload_actualizar_presupuesto($budget, [
                'total'                    => 0,
                'iva_en_articulos_sin_iva' => 1,
            ]))->assertStatus(200);

            $self->postJson('api/budget/'.$budget->id.'/duplicate')->assertStatus(201);

            $self->postJson('api/budget/'.$budget->id.'/confirmar')->assertStatus(200);
        });
    }

    /**
     * La venta consolidada de facturacion.
     *
     * @test
     */
    public function sin_la_columna_se_puede_consolidar_facturacion()
    {
        $self = $this;

        $this->sin_la_columna(function () use ($self) {

            $cliente = $self->cliente();

            $afip_information = AfipInformation::where('user_id', $self->comercio()->id)->first();

            $self->assertNotNull($afip_information, 'Falta la configuracion de AFIP del fixture.');

            $venta = $self->venta_en_base([
                'client_id'                  => $cliente->id,
                'omitir_en_cuenta_corriente' => 1,
            ]);

            $consolidada = ConsolidarFacturacionHelper::consolidar(
                [$venta->id],
                $cliente->id,
                $self->comercio()->id,
                $afip_information->id,
                1,
                false,
                [],
                false
            );

            $self->assertNotNull($consolidada->id, 'La venta consolidada tiene que haberse creado.');
        });
    }

    /**
     * Con la columna puesta el campo se sigue guardando: una guarda que lo apagara siempre tambien
     * pasaria los tests de arriba.
     *
     * @test
     */
    public function con_la_columna_el_campo_se_sigue_guardando()
    {
        $this->assertTrue(
            IvaEnArticulosSinIvaEsquemaHelper::hay_columna('sales'),
            'La base del slot tiene que tener la columna para este test.'
        );

        $modelo = $this->crear_venta_por_endpoint($this->payload_venta([
            'iva_en_articulos_sin_iva' => 1,
        ]));

        $this->assertSame(1, (int) Sale::find($modelo['id'])->iva_en_articulos_sin_iva);
    }

    /**
     * Una tabla que no es `sales` ni `budgets` es un error de programacion: corta con excepcion, no
     * responde `false` en silencio.
     *
     * @test
     */
    public function una_tabla_desconocida_corta_con_excepcion()
    {
        $this->expectException(\InvalidArgumentException::class);

        IvaEnArticulosSinIvaEsquemaHelper::hay_columna('sale');
    }
}
