<?php

namespace Tests\Feature\Migraciones;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EsquemaCuotasHistoricoTest extends TestCase
{
    /** @test */
    public function repara_solo_tablas_vacias_reanuda_y_preserva_datos_canonicos()
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('La inspección de esquema requiere MySQL o MariaDB.');
        }
        $conexion = DB::getDefaultConnection();
        $original = config("database.connections.$conexion.database");
        $temporal = 'empresa_testing_cuotas_' . bin2hex(random_bytes(6));
        DB::statement("CREATE DATABASE `$temporal`");
        try {
            config(["database.connections.$conexion.database" => $temporal]);
            DB::purge($conexion);
            Schema::clearResolvedInstance('db.schema');
            require_once database_path('migrations/2026_10_25_235500_reparar_esquema_cuotas_historico.php');
            DB::statement('CREATE TABLE payment_plans (id INT PRIMARY KEY)');
            DB::statement("CREATE TABLE payment_plan_cuotas (id INT PRIMARY KEY, status ENUM('pending','paid','canceled') NOT NULL DEFAULT 'pending')");
            $migracion = new \RepararEsquemaCuotasHistorico();

            DB::statement("INSERT INTO payment_plan_cuotas (id,status) VALUES (1,'paid')");
            try {
                $migracion->up();
                $this->fail('El esquema incompleto con datos debe detenerse.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('requiere revisión manual', $e->getMessage());
            }
            $this->assertFalse(Schema::hasColumn('payment_plan_cuotas', 'client_id'));
            $this->assertSame('paid', DB::table('payment_plan_cuotas')->value('status'));
            DB::table('payment_plan_cuotas')->delete();
            DB::statement('INSERT INTO payment_plans VALUES (1)');
            try {
                $migracion->up();
                $this->fail('Los planes existentes deben detener la reparación.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('requiere revisión manual', $e->getMessage());
            }
            DB::table('payment_plans')->delete();

            // Un ALTER anterior pudo completarse antes de una interrupción.
            DB::statement('ALTER TABLE payment_plan_cuotas ADD client_id INT NULL');
            $migracion->up();
            $migracion->up();
            foreach (['client_id', 'sale_id', 'user_id', 'estado', 'status'] as $columna) {
                $this->assertTrue(Schema::hasColumn('payment_plan_cuotas', $columna));
            }
            DB::statement("INSERT INTO payment_plan_cuotas (id,status,estado) VALUES (2,'paid','pagado')");
            $migracion->up();
            $migracion->down();
            $this->assertSame('paid', DB::table('payment_plan_cuotas')->value('status'));
            $this->assertSame('pagado', DB::table('payment_plan_cuotas')->value('estado'));

            DB::statement('DROP TABLE payment_plan_cuotas');
            DB::statement('CREATE TABLE payment_plan_cuotas (id INT PRIMARY KEY, client_id VARCHAR(20) NULL)');
            try {
                $migracion->up();
                $this->fail('Un esquema parcial incompatible debe detenerse antes de ALTER.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('client_id', $e->getMessage());
            }
            $this->assertFalse(Schema::hasColumn('payment_plan_cuotas', 'sale_id'));
        } finally {
            DB::purge($conexion);
            config(["database.connections.$conexion.database" => $original]);
            Schema::clearResolvedInstance('db.schema');
            DB::statement("DROP DATABASE `$temporal`");
        }
    }
}
