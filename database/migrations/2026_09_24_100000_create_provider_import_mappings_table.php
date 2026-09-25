<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración de columnas guardada por proveedor para la importación de artículos con IA
 * (misión importacion-excel-motor-rapido, 24/9/2026).
 *
 * Una fila por (user_id, model_name, provider_id): el mapeo encabezado → propiedad que el usuario
 * confirmó (o corrigió) la última vez que importó un Excel de ese proveedor, más la firma de los
 * encabezados de ese archivo para reconocer el formato la próxima vez.
 *
 * Reglas del repo: sin foreign keys, índices con nombre corto, strings acotados.
 */
class CreateProviderImportMappingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('provider_import_mappings', function (Blueprint $table) {
            $table->bigIncrements('id');

            /* Dueño (owner) de la importación: mismo user_id que usan las corridas de análisis. */
            $table->unsignedInteger('user_id');

            /* Hoy sólo 'article' (clientes y proveedores no tienen proveedor); queda para no rehacer la tabla. */
            $table->string('model_name', 30)->default('article');

            /* Proveedor confirmado por el usuario en el paso 2 del modal. Sin FK, regla del repo. */
            $table->unsignedBigInteger('provider_id');

            /* sha1 de los encabezados normalizados (trim, minúsculas, sin tildes, espacios colapsados), en orden. */
            $table->string('headers_hash', 64);

            /* Fila 1-based del encabezado con la que se analizó ese archivo; null = detección automática. */
            $table->integer('header_row')->nullable();

            /* Nombre de la hoja del libro que se importó. */
            $table->string('hoja_nombre', 120)->nullable();

            /*
             * JSON: una entrada por columna NO ignorada
             * {excel_column, excel_column_normalizada, excel_column_index, excel_column_letter, system_property, corregida}.
             */
            $table->longText('column_mapping');

            /* Cuántas de esas columnas el usuario corrigió respecto de lo que propuso la IA. */
            $table->integer('columnas_corregidas')->default(0);

            /* Cuántas veces se confirmó el paso 2 con este proveedor (upsert: se incrementa). */
            $table->integer('veces_usado')->default(1);

            /* Última confirmación; es la fecha que se le muestra al usuario ("la última vez que…"). */
            $table->timestamp('ultimo_uso_at')->nullable();

            $table->timestamps();

            /* Búsqueda por proveedor (paso 2 y cambio de proveedor) y por firma de encabezados (análisis). */
            $table->index(['user_id', 'provider_id', 'model_name'], 'pim_user_provider_idx');
            $table->index(['user_id', 'headers_hash'], 'pim_user_hash_idx');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('provider_import_mappings');
    }
}
