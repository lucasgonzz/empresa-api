<?php

namespace Tests\Feature\Buyer;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** DDL aislado: jamás agrega el índice legacy a la tabla buyers del fixture del slot. */
class IndiceLegacyDeTelefonoTest extends TestCase
{
    public function test_el_indice_legacy_se_quita_sin_alterar_cuentas_y_es_repetible()
    {
        require_once database_path('migrations/2026_11_12_100000_remove_legacy_buyers_phone_unique.php');
        $original = DB::getDefaultConnection();
        $base = DB::connection()->getDatabaseName();
        $this->assertMatchesRegularExpression('/^empresa_testing_s[0-9]+$/', $base, 'Esta prueba DDL solo usa una base aislada de slot.');
        $temporal = $base.'_ticket108_legacy_'.bin2hex(random_bytes(4));
        $this->assertMatchesRegularExpression('/^empresa_testing_s[0-9]+_ticket108_legacy_[a-f0-9]{8}$/', $temporal);
        $config = config('database.connections.'.$original);
        $config['database'] = $temporal;
        DB::statement('CREATE DATABASE `'.$temporal.'`');
        try {
            config(['database.connections.ticket108_legacy' => $config]);
            DB::setDefaultConnection('ticket108_legacy');
            DB::statement('CREATE TABLE buyers (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(128) NULL, email VARCHAR(110), user_id INT, UNIQUE KEY buyers_phone_unique(phone)) ENGINE=InnoDB');
            DB::table('buyers')->insert(['phone' => '1122334455', 'email' => 'uno@test.local', 'user_id' => 1]);
            try {
                DB::table('buyers')->insert(['phone' => '1122334455', 'email' => 'dos@test.local', 'user_id' => 2]);
                $this->fail('El índice legacy debía reproducir el 1062 confirmado en Fenix.');
            } catch (QueryException $error) {
                $this->assertSame(1062, (int) $error->errorInfo[1]);
                $this->assertStringContainsString('buyers_phone_unique', $error->getMessage());
            }
            $migracion = new \RemoveLegacyBuyersPhoneUnique();
            $migracion->up();
            $migracion->up();
            DB::table('buyers')->insert(['phone' => '1122334455', 'email' => 'dos@test.local', 'user_id' => 2]);
            $migracion->down();
            $this->assertSame(2, DB::table('buyers')->count());
            $this->assertSame(['uno@test.local', 'dos@test.local'], DB::table('buyers')->orderBy('id')->pluck('email')->all());
            $this->assertCount(0, DB::select('SHOW INDEX FROM buyers WHERE Key_name = ?', ['buyers_phone_unique']));

            // El mismo nombre en otro índice no habilita a borrarlo.
            DB::statement('ALTER TABLE buyers ADD INDEX buyers_phone_unique(phone)');
            $migracion->up();
            $this->assertCount(1, DB::select('SHOW INDEX FROM buyers WHERE Key_name = ?', ['buyers_phone_unique']));
            DB::statement('ALTER TABLE buyers DROP INDEX buyers_phone_unique, ADD UNIQUE KEY buyers_phone_unique(phone, email)');
            $migracion->up();
            $this->assertCount(2, DB::select('SHOW INDEX FROM buyers WHERE Key_name = ?', ['buyers_phone_unique']));
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('ticket108_legacy');
            DB::statement('DROP DATABASE `'.$temporal.'`');
        }
    }
}
