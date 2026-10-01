<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El modelo de IA de tres tareas, elegible por cliente desde el admin (misión
 * modelos-ia-por-cliente, 30/9/2026, pedido de Lucas).
 *
 * Va en la fila del DUEÑO (`owner_id IS NULL`), el mismo precedente que `agente_proveedor` y
 * `agente_pensamiento`: la configuración es del comercio, no de cada persona.
 *
 * - `ia_modelo_whatsapp`: el bot de WhatsApp que les contesta a los clientes del negocio.
 * - `ia_modelo_imagenes`: la verificación de imágenes de la asignación automática.
 * - `ia_modelo_excel`: la importación de Excel (identificar columnas y la recomendación).
 *
 * Cada una guarda un id de opción del catálogo de ModelosIaHelper (`deepseek_flash`,
 * `deepseek_pro`, `claude_haiku`, `claude_sonnet`, `claude_opus`), nunca el id del modelo: el id
 * real sale de config/services.php y se mueve por .env sin migrar.
 *
 * 🔴 NULL = EL DEFAULT DE LA TAREA, y el default vive en CÓDIGO (ModelosIaHelper::DEFAULTS_POR_TAREA),
 * no en la columna. Así cambiar el default de una tarea es un deploy, no una migración que reescribe
 * filas de todos los clientes. Por eso las columnas no tienen default.
 *
 * El asistente NO lleva columna: su opción se traduce a las dos que ya existen
 * (`agente_proveedor` + `agente_pensamiento`), así el modal del dueño y el admin escriben lo mismo.
 *
 * string(30): los ids son cortos; 30 deja margen para opciones nuevas sin abrir la puerta a
 * cualquier cosa. Sin índice (se lee la fila del dueño, nunca se busca por esto) y sin foreign keys.
 * Con guard por columna para que sea segura de re-ejecutar.
 */
class AddIaModelosToUsersTable extends Migration
{
    /**
     * Las tres columnas nuevas, en el orden en que se muestran en el admin.
     *
     * @var array<int, string>
     */
    protected $columnas = ['ia_modelo_whatsapp', 'ia_modelo_imagenes', 'ia_modelo_excel'];

    /**
     * Agrega las tres columnas nullable, cada una con su guard hasColumn.
     *
     * @return void
     */
    public function up()
    {
        foreach ($this->columnas as $columna) {
            if (Schema::hasColumn('users', $columna)) {
                continue;
            }

            Schema::table('users', function (Blueprint $table) use ($columna) {
                /* Id de opción de ModelosIaHelper. Null = el default de la tarea (en código). */
                $table->string($columna, 30)->nullable();
            });
        }
    }

    /**
     * Elimina las columnas que existan.
     *
     * @return void
     */
    public function down()
    {
        foreach ($this->columnas as $columna) {
            if (! Schema::hasColumn('users', $columna)) {
                continue;
            }

            Schema::table('users', function (Blueprint $table) use ($columna) {
                $table->dropColumn($columna);
            });
        }
    }
}
