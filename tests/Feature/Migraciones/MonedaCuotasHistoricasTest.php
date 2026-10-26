<?php

namespace Tests\Feature\Migraciones;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** @group migraciones-moneda-cuotas */
class MonedaCuotasHistoricasTest extends TestCase
{
    /** @test */
    public function rellena_cuotas_de_los_dos_esquemas_sin_modificar_huerfanas_y_se_puede_repetir()
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('La migración usa UPDATE JOIN de MySQL.');
        }

        $nombreConexion = DB::getDefaultConnection();
        $baseOriginal = config("database.connections.$nombreConexion.database");
        $baseTemporal = 'empresa_testing_cuotas_' . bin2hex(random_bytes(6));
        // La base es nueva y exclusiva de este ensayo; nunca se vacían tablas de otra base.
        DB::statement("CREATE DATABASE `$baseTemporal`");
        try {
            config(["database.connections.$nombreConexion.database" => $baseTemporal]);
            DB::purge($nombreConexion);
            Schema::clearResolvedInstance('db.schema');
            require_once database_path('migrations/2026_10_21_100000_add_moneda_id_to_payment_plans_tables.php');

            foreach (['payment_plan_id', 'sale_id'] as $vinculo) {
                DB::statement('DROP TABLE IF EXISTS payment_plan_cuotas');
                DB::statement('DROP TABLE IF EXISTS payment_plans');
                DB::statement('DROP TABLE IF EXISTS sales');
                DB::statement('CREATE TABLE sales (id INT PRIMARY KEY, moneda_id INT)');
                DB::statement('CREATE TABLE payment_plans (id INT PRIMARY KEY, sale_id INT NULL)');
                DB::statement("CREATE TABLE payment_plan_cuotas (id INT PRIMARY KEY, `$vinculo` INT NULL)");
                DB::statement('INSERT INTO sales VALUES (1,2),(2,1)');
                DB::statement('INSERT INTO payment_plans VALUES (1,1),(2,2),(3,NULL)');
                DB::statement('INSERT INTO payment_plan_cuotas VALUES (1,1),(2,2),(3,NULL),(4,999)');

                $migracion = new \AddMonedaIdToPaymentPlansTables();
                $migracion->up();
                $migracion->up();
                $planes = DB::table('payment_plans')->orderBy('id')->pluck('moneda_id')->toArray();
                $cuotas = DB::table('payment_plan_cuotas')->orderBy('id')->pluck('moneda_id')->toArray();
                $this->assertSame([2, 1, 1], array_map('intval', $planes), $vinculo);
                $this->assertSame([2, 1, 1, 1], array_map('intval', $cuotas), $vinculo);
            }

            DB::statement('DROP TABLE payment_plan_cuotas');
            DB::statement('CREATE TABLE payment_plan_cuotas (id INT PRIMARY KEY, payment_plan_id INT NULL, sale_id INT NULL)');
            DB::statement('INSERT INTO payment_plans (id,sale_id) VALUES (4,999)');
            DB::statement('INSERT INTO payment_plan_cuotas VALUES (1,1,1),(2,2,2),(3,NULL,1),(4,999,1),(5,3,1),(6,NULL,NULL),(7,1,NULL),(8,1,999),(9,4,1)');
            $migracion->up();
            $cuotas = DB::table('payment_plan_cuotas')->orderBy('id')->pluck('moneda_id')->toArray();
            $this->assertSame([2, 1, 2, 2, 2, 1, 2, 2, 2], array_map('intval', $cuotas));

            // Dos ventas diferentes son datos ambiguos: no elegir una silenciosamente.
            DB::statement('UPDATE payment_plans SET moneda_id = 1');
            DB::statement('UPDATE payment_plan_cuotas SET moneda_id = 1');
            DB::statement('INSERT INTO payment_plan_cuotas (id,payment_plan_id,sale_id) VALUES (10,2,1)');
            try {
                $migracion->up();
                $this->fail('Debió rechazar los vínculos contradictorios.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('ventas distintas', $e->getMessage());
            }
            $this->assertSame([1, 1, 1, 1], array_map('intval', DB::table('payment_plans')->orderBy('id')->pluck('moneda_id')->toArray()));
            $this->assertSame([1, 1, 1, 1, 1, 1, 1, 1, 1, 1], array_map('intval', DB::table('payment_plan_cuotas')->orderBy('id')->pluck('moneda_id')->toArray()));
        } finally {
            DB::purge($nombreConexion);
            config(["database.connections.$nombreConexion.database" => $baseOriginal]);
            Schema::clearResolvedInstance('db.schema');
            DB::statement("DROP DATABASE `$baseTemporal`");
        }
    }
}
