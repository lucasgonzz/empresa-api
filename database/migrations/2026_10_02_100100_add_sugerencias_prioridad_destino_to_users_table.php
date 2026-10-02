<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: criterio de prioridad al repartir desde el depósito madre (misión deposito-madre,
 * 2/10/2026).
 *
 * users.sugerencias_prioridad_destino decide qué sucursal se lleva el stock del madre cuando no
 * alcanza para todas, y en qué orden se listan los traslados:
 *
 * - 'ventas_sucursal': la sucursal que más plata facturó en pesos en los últimos 90 días, con
 *   ventas terminadas, sin borradas, sin consolidaciones de facturación y sin las ventas en
 *   dólares (CoberturaService::facturacion_por_sucursal()).
 * - 'ventas_articulo': la velocidad de venta de ESE artículo en la sucursal: unidades de los
 *   últimos 90 días por día, mezclada 2/3 – 1/3 con la misma ventana del año anterior cuando hay
 *   historia interanual (CoberturaService::velocidades_para()).
 *
 * Sin depósito madre la columna no se usa: el orden sigue siendo por urgencia (cobertura).
 *
 * Mismo patrón que el resto de la configuración de sugerencias (2026_08_14_120300): columna en
 * users → asignación con guard en UserController@update → propiedad en empresa-spa
 * src/models/user.js. Longitud explícita en el string, sin foreign keys.
 */
class AddSugerenciasPrioridadDestinoToUsersTable extends Migration
{
    /**
     * Ejecuta la migración: agrega la columna si todavía no existe (guard hasColumn para que sea
     * segura de re-ejecutar).
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('users', 'sugerencias_prioridad_destino')) {
            Schema::table('users', function (Blueprint $table) {
                // ventas_sucursal / ventas_articulo — ver CoberturaService::PRIORIDADES_DESTINO.
                $table->string('sugerencias_prioridad_destino', 30)->default('ventas_sucursal');
            });
        }
    }

    /**
     * Revierte la migración: elimina la columna si existe.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('users', 'sugerencias_prioridad_destino')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('sugerencias_prioridad_destino');
            });
        }
    }
}
