<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Agrega `articles_export_key` a `users`: la clave que identifica de forma publica y no
 * enumerable al comercio en el endpoint de export de articulos para n8n (prompt 02 del
 * grupo 211). El endpoint es publico y no lleva autenticacion, asi que la key en el path
 * de la URL es lo unico que evita que se pueda listar el catalogo de cualquier comercio
 * probando ids consecutivos.
 *
 * Solo los owners (usuarios con `owner_id` NULL) tienen key: la key es del comercio, no
 * de cada empleado que trabaja en el.
 */
class AddArticlesExportKeyToUsersTable extends Migration
{
    /**
     * Agrega la columna con indice unico y backfillea la key para los owners existentes
     * que todavia no la tengan.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            // Clave publica y aleatoria del comercio para el export de articulos. Nullable
            // porque los sub-usuarios (empleados) no la tienen: solo la usa el owner.
            $table->string('articles_export_key', 64)->nullable()->unique('users_articles_export_key_unique');
        });

        // Backfill: a cada owner (owner_id NULL) que todavia no tenga key le generamos una
        // de 48 caracteres aleatorios. Se recorre fila por fila para que cada owner reciba
        // una key distinta (Str::random no se puede reusar en un solo UPDATE masivo).
        $owners_sin_key = DB::table('users')
            ->whereNull('owner_id')
            ->whereNull('articles_export_key')
            ->get(['id']);

        foreach ($owners_sin_key as $owner) {
            DB::table('users')
                ->where('id', $owner->id)
                ->update(['articles_export_key' => Str::random(48)]);
        }
    }

    /**
     * Revierte el indice unico y la columna.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_articles_export_key_unique');
            $table->dropColumn('articles_export_key');
        });
    }
}
