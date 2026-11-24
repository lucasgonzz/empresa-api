<?php

namespace Tests\Feature\Tienda;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderCommunicationSchemaTest extends TestCase
{
    private $ids = [];
    private const TABLE = 'order_communication_deliveries';

    private function identity(array $changes = [])
    {
        return array_merge([
            'owner_id' => 4294900001,
            'order_id' => 9000000001,
            'channel' => 'email',
            'communication_type' => 'order_received',
            'recipient_hash' => hash('sha256', strtolower(trim(' Buyer@Example.test '))),
        ], $changes);
    }

    private function insert(array $changes = [])
    {
        $id = DB::table(self::TABLE)->insertGetId($this->identity($changes));
        $this->ids[] = $id;
        return $id;
    }

    protected function tearDown(): void
    {
        try {
            if ($this->ids && Schema::hasTable(self::TABLE)) {
                DB::table(self::TABLE)->whereIn('id', $this->ids)->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_migracion_vacia_reversible_y_aditiva_sin_tocar_pedidos_ni_usuarios()
    {
        // No destruir registros si alguien corre este test sobre una base compartida.
        $this->assertSame(0, DB::table(self::TABLE)->count());
        $antes = [
            DB::table('users')->orderBy('id')->get()->toJson(),
            DB::table('orders')->orderBy('id')->get()->toJson(),
        ];
        require_once database_path('migrations/2026_11_19_120200_create_order_communication_deliveries_table.php');
        $migration = new \CreateOrderCommunicationDeliveriesTable();
        try {
            $migration->down();
            $this->assertFalse(Schema::hasTable(self::TABLE));
            $migration->up();
            $this->assertTrue(Schema::hasTable(self::TABLE));
            $this->assertSame(0, DB::table(self::TABLE)->count());
            $id = $this->insert();
            $this->assertGreaterThan(0, $id);
        } finally {
            // También prueba que rollback elimina el registro nuevo; nunca recrea históricos.
            $migration->down();
            $migration->up();
            $this->ids = [];
        }
        $this->assertSame(0, DB::table(self::TABLE)->count());
        $this->assertSame($antes, [
            DB::table('users')->orderBy('id')->get()->toJson(),
            DB::table('orders')->orderBy('id')->get()->toJson(),
        ]);
    }

    public function test_identidad_normalizada_no_se_duplica_aunque_cambie_estado()
    {
        $this->insert(['status' => 'transport_accepted', 'accepted_at' => '2026-11-20 00:00:01']);
        try {
            $this->insert([
                'recipient_hash' => hash('sha256', strtolower(trim('buyer@example.test'))),
                'status' => 'prepared',
            ]);
            $this->fail('La identidad aceptada no puede volver a crearse.');
        } catch (QueryException $error) {
            $this->assertSame('23000', $error->errorInfo[0]);
            $this->assertSame(1062, $error->errorInfo[1]);
        }
        $this->assertSame(1, DB::table(self::TABLE)->whereIn('id', $this->ids)->count());
    }

    public function test_cada_dimension_de_identidad_permanece_independiente()
    {
        $this->insert();
        foreach ([
            ['owner_id' => 4294900002],
            ['order_id' => 9000000002],
            ['channel' => 'browser'],
            ['communication_type' => 'new_order_merchant'],
            ['recipient_hash' => hash('sha256', 'other@example.test')],
        ] as $dimension) {
            $this->insert($dimension);
        }
        $this->assertSame(6, DB::table(self::TABLE)->whereIn('id', $this->ids)->count());
    }

    public function test_actualizacion_con_owner_no_modifica_otro_tenant_con_mismo_pedido_y_destinatario()
    {
        $propio = $this->insert();
        $ajeno = $this->insert(['owner_id' => 4294900002]);
        $afectados = DB::table(self::TABLE)->where('owner_id', 4294900001)
            ->where('order_id', 9000000001)->update(['status' => 'attempting', 'attempt_count' => 1]);
        $this->assertSame(1, $afectados);
        $this->assertSame('attempting', DB::table(self::TABLE)->where('id', $propio)->value('status'));
        $this->assertSame('prepared', DB::table(self::TABLE)->where('id', $ajeno)->value('status'));
        $this->assertSame(0, (int) DB::table(self::TABLE)->where('id', $ajeno)->value('attempt_count'));
    }

    public function test_registro_conserva_intento_fallo_y_aceptacion_sin_direccion_ni_payload()
    {
        $id = $this->insert();
        $row = DB::table(self::TABLE)->find($id);
        $this->assertSame('prepared', $row->status);
        $this->assertSame(0, (int) $row->attempt_count);
        foreach (['last_attempt_at', 'accepted_at', 'failed_at', 'error_code'] as $field) {
            $this->assertNull($row->$field);
        }
        DB::table(self::TABLE)->where('id', $id)->update([
            'status' => 'failed', 'attempt_count' => 1,
            'last_attempt_at' => '2026-11-20 00:00:01',
            'failed_at' => '2026-11-20 00:00:02', 'error_code' => 'transport_rejected',
        ]);
        $this->assertNull(DB::table(self::TABLE)->find($id)->accepted_at);
        DB::table(self::TABLE)->where('id', $id)->update([
            'status' => 'transport_accepted', 'attempt_count' => 2,
            'last_attempt_at' => '2026-11-20 00:00:03', 'accepted_at' => '2026-11-20 00:00:04',
        ]);
        $row = DB::table(self::TABLE)->find($id);
        $this->assertSame('2026-11-20 00:00:04', $row->accepted_at);
        $this->assertSame('2026-11-20 00:00:02', $row->failed_at);
        $this->assertSame(2, (int) $row->attempt_count);
        $this->assertStringNotContainsString('buyer@example.test', json_encode($row));
        foreach (['recipient', 'email', 'payload', 'error_message'] as $field) {
            $this->assertFalse(Schema::hasColumn(self::TABLE, $field));
        }
    }

    public function test_resultado_incierto_no_se_confunde_con_fallo_o_aceptacion()
    {
        $id = $this->insert([
            'status' => 'uncertain', 'attempt_count' => 1,
            'last_attempt_at' => '2026-11-20 00:00:01', 'error_code' => 'transport_result_unknown',
        ]);
        $row = DB::table(self::TABLE)->find($id);
        $this->assertSame('uncertain', $row->status);
        $this->assertNull($row->accepted_at);
        $this->assertNull($row->failed_at);
    }

    public function test_estado_desconocido_es_rechazado_por_motor_real()
    {
        $this->expectException(QueryException::class);
        $this->insert(['status' => 'delivered_to_inbox']);
    }

    public function test_identidad_completa_es_obligatoria()
    {
        $this->expectException(QueryException::class);
        $this->insert(['recipient_hash' => null]);
    }

    public function test_otra_conexion_no_puede_reservar_la_misma_identidad_confirmada()
    {
        $this->insert();
        // Conexión PDO distinta: la exclusión no depende de memoria local del job.
        config(['database.connections.order_comm_race' => config('database.connections.mysql')]);
        try {
            $connection = DB::connection('order_comm_race');
            $this->assertNotSame(DB::connection()->getPdo(), $connection->getPdo());
            try {
                $connection->table(self::TABLE)->insert($this->identity());
                $this->fail('Otra conexión creó una segunda reserva.');
            } catch (QueryException $error) {
                $this->assertSame(1062, $error->errorInfo[1]);
            }
        } finally {
            DB::purge('order_comm_race');
        }
    }
}
