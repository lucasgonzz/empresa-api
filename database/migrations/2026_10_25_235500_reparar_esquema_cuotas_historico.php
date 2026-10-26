<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RepararEsquemaCuotasHistorico extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('payment_plan_cuotas')) {
            throw new RuntimeException('Falta la tabla de cuotas; no se puede reparar su esquema.');
        }

        $columnas = ['client_id', 'sale_id', 'user_id', 'estado'];
        $faltantes = array_values(array_filter($columnas, function ($columna) {
            return !Schema::hasColumn('payment_plan_cuotas', $columna);
        }));
        if (!$faltantes) {
            return;
        }

        if (!Schema::hasTable('payment_plans') || DB::table('payment_plans')->exists() || DB::table('payment_plan_cuotas')->exists()) {
            throw new RuntimeException('El esquema de cuotas está incompleto y hay planes o cuotas, o falta la tabla de planes; requiere revisión manual.');
        }

        // Antes del primer ALTER se comprueban también los restos de un intento previo.
        $existentes = DB::select('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['payment_plan_cuotas']);
        $tipos = [];
        foreach ($existentes as $columna) {
            $tipos[$columna->COLUMN_NAME] = $columna;
        }
        foreach (['client_id', 'sale_id', 'user_id'] as $nombre) {
            if (isset($tipos[$nombre]) && (!preg_match('/^int(?:\(\d+\))?$/i', $tipos[$nombre]->COLUMN_TYPE) || $tipos[$nombre]->IS_NULLABLE !== 'YES')) {
                throw new RuntimeException("La columna {$nombre} de cuotas no coincide con el esquema esperado.");
            }
        }
        if (isset($tipos['estado']) && (strtolower($tipos['estado']->COLUMN_TYPE) !== "enum('pendiente','pagado','cancelado')" || $tipos['estado']->IS_NULLABLE !== 'NO' || trim((string) $tipos['estado']->COLUMN_DEFAULT, "'") !== 'pendiente')) {
            throw new RuntimeException('La columna estado de cuotas no coincide con el esquema esperado.');
        }

        foreach ($faltantes as $nombre) {
            Schema::table('payment_plan_cuotas', function (Blueprint $table) use ($nombre) {
                if ($nombre === 'estado') {
                    $table->enum('estado', ['pendiente', 'pagado', 'cancelado'])->default('pendiente');
                } else {
                    $table->integer($nombre)->nullable();
                }
            });
        }
    }

    public function down()
    {
        // La reparación conserva columnas y datos aun al revertir otras migraciones.
    }
}
