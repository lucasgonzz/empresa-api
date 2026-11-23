<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de versiones instaladas en el sistema del cliente: una fila por comercio y por versión,
 * con la fecha real en que esa versión quedó corriendo. Sin foreign keys (regla del proyecto).
 */
class CreateInstalledVersionsTable extends Migration
{
    /**
     * Crea la tabla y sus dos índices (el unique es int + string(30): no es un unique compuesto largo).
     *
     * @return void
     */
    public function up()
    {
        Schema::create('installed_versions', function (Blueprint $table) {
            $table->bigIncrements('id');
            // Dueño del comercio (0 = no se pudo resolver; en la práctica no se usa).
            $table->unsignedBigInteger('owner_id')->default(0);
            // Número de versión, igual que synced_versions.version.
            $table->string('version', 30);
            // uuid de synced_versions si la versión vino del admin.
            $table->string('synced_version_uuid', 36)->nullable();
            $table->string('title', 200)->nullable();
            // Fecha de instalación (o de publicación si source = historial_previo).
            $table->timestamp('installed_at')->nullable();
            // instalacion | sincronizacion | detectada | historial_previo
            $table->string('source', 20)->default('instalacion');
            $table->timestamps();

            $table->unique(['owner_id', 'version'], 'iv_owner_version_uq');
            $table->index(['owner_id', 'installed_at'], 'iv_owner_installed_idx');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('installed_versions');
    }
}
