<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reemplaza provincia_id y location_id por campos de texto libre provincia y localidad.
 */
class SaleSenderInfosProvinciaLocalidadText extends Migration
{
    public function up()
    {
        Schema::table('sale_sender_infos', function (Blueprint $table) {
            $table->string('provincia', 120)->nullable()->after('cuit');
            $table->string('localidad', 120)->nullable()->after('provincia');
        });

        $rows = DB::table('sale_sender_infos')->select('id', 'provincia_id', 'location_id')->get();

        foreach ($rows as $row) {
            $provincia_nombre = null;
            $localidad_nombre = null;

            if (! empty($row->provincia_id)) {
                $provincia_nombre = DB::table('provincias')->where('id', $row->provincia_id)->value('name');
            }
            if (! empty($row->location_id)) {
                $localidad_nombre = DB::table('locations')->where('id', $row->location_id)->value('name');
            }

            if ($provincia_nombre !== null || $localidad_nombre !== null) {
                DB::table('sale_sender_infos')->where('id', $row->id)->update([
                    'provincia' => $provincia_nombre,
                    'localidad' => $localidad_nombre,
                ]);
            }
        }

        Schema::table('sale_sender_infos', function (Blueprint $table) {
            $table->dropColumn(['provincia_id', 'location_id']);
        });
    }

    public function down()
    {
        Schema::table('sale_sender_infos', function (Blueprint $table) {
            $table->unsignedInteger('provincia_id')->nullable()->after('cuit');
            $table->unsignedInteger('location_id')->nullable()->after('provincia_id');
        });

        Schema::table('sale_sender_infos', function (Blueprint $table) {
            $table->dropColumn(['provincia', 'localidad']);
        });
    }
}
