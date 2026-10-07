<?php

namespace Tests\Feature\Sucursales;

use App\Http\Controllers\Helpers\address\EliminarSucursalHelper;
use App\Models\Address;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Archivo 14 — la eliminación de sucursales con la base SIN `deposit_movements.stock_moved_at`
 * (segunda ronda de revisión, 5/10/2026, punto F1).
 *
 * Un deploy de empresa sube los archivos antes de migrar. Los bloqueos de la eliminación (traslados
 * pendientes) nombraban `stock_moved_at` a secas: en esa ventana, el resumen y la eliminación de
 * CUALQUIER sucursal daban 500. Con la guarda de esquema, sin la columna la única marca de "movido"
 * es `recibido_at`.
 *
 * Mismo patrón que `5_Ajuste_de_precios_guarda_de_esquema_Test`: la columna se renombra desde una
 * conexión aparte (un ALTER hace commit implícito y rompería la transacción del test), con red de
 * seguridad en setUp por si una corrida anterior se cortó con la columna escondida.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group sucursales
 */
class Eliminar_sucursal_guarda_de_esquema_Test extends SucursalesTestCase
{
    const COLUMNA = 'stock_moved_at';

    const ESCONDIDA = 'stock_moved_at_escondida_por_el_test';

    protected function setUp(): void
    {
        parent::setUp();

        if (Schema::hasColumn('deposit_movements', self::ESCONDIDA)) {
            $this->renombrar(self::ESCONDIDA, self::COLUMNA);
        }

        EliminarSucursalHelper::olvidar_esquema();
    }

    protected function tearDown(): void
    {
        EliminarSucursalHelper::olvidar_esquema();

        parent::tearDown();
    }

    /**
     * Renombra la columna desde una conexión aparte.
     *
     * @param  string  $de
     * @param  string  $a
     * @return void
     */
    protected function renombrar($de, $a)
    {
        $pdo = $this->otra_conexion();

        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Sin esto, un metadata lock colgaría la suite (el default de MySQL para MDL es 1 año).
        $pdo->exec('SET SESSION lock_wait_timeout = 10');

        $pdo->exec('ALTER TABLE `deposit_movements` RENAME COLUMN `'.$de.'` TO `'.$a.'`');

        EliminarSucursalHelper::olvidar_esquema();
    }

    /**
     * Test 1 — sin la columna, el resumen y la eliminación andan; un traslado sin `recibido_at`
     * sigue bloqueando.
     *
     * @test
     */
    public function sin_stock_moved_at_el_resumen_y_la_eliminacion_andan()
    {
        $principal = $this->sucursal_principal();

        DB::rollBack();

        $this->renombrar(self::COLUMNA, self::ESCONDIDA);

        DB::beginTransaction();

        try {

            $this->assertFalse(Schema::hasColumn('deposit_movements', self::COLUMNA), 'El escenario no se armó: la columna sigue.');
            $this->assertFalse(EliminarSucursalHelper::hay_columna_stock_moved_at(), 'El escenario no se armó: la guarda la sigue viendo.');

            $libre = $this->nueva_sucursal('zz Esquema sin traslados');

            $this->getJson('api/address/'.$libre->id.'/eliminar-resumen')->assertStatus(200)->assertJsonPath('bloqueos', []);

            $this->eliminar_sucursal($libre->id)->assertStatus(200);
            $this->assertNull(Address::find($libre->id));

            // Un traslado pendiente (sin recibido_at) sigue bloqueando sin la columna nueva.
            $con_traslado = $this->nueva_sucursal('zz Esquema con traslado');

            DB::table('deposit_movements')->insert([
                'num' => 990051, 'from_address_id' => $principal->id, 'to_address_id' => $con_traslado->id,
                'deposit_movement_status_id' => 1, 'user_id' => $this->comercio()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $this->getJson('api/address/'.$con_traslado->id.'/eliminar-resumen')->assertStatus(200)->assertJsonPath('bloqueos.0.codigo', 'traslados_pendientes');

        } finally {

            DB::rollBack();

            $this->renombrar(self::ESCONDIDA, self::COLUMNA);

            // Para que el rollback del tearDown del trait tenga una transacción que cerrar.
            DB::beginTransaction();
        }
    }
}
