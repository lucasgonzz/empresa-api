<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de auditoría de cambios (misión auditoria-de-cambios, 30/9/2026).
 *
 * Una fila por cada creación, modificación, borrado o restauración que el sistema hace sobre un
 * modelo de Eloquent, escrita por `App\Services\AuditLog\AuditLogRecorder`. Sirve para responder
 * "quién cambió este precio, cuándo y desde qué pantalla". Por ahora NO tiene pantalla ni ruta:
 * se consulta por SQL (soporte).
 *
 * Por qué UNA tabla y no `updated_by`/`deleted_by` en cada tabla: hay más de 300 modelos, eso serían
 * 300 migraciones, y una columna "quién" no guarda QUÉ cambió ni el valor anterior.
 *
 * Columnas con criterio:
 *  - `user_id` es el DUEÑO del comercio (no quien hizo la acción): hay bases con 51 comercios
 *    adentro y toda consulta de auditoría es "lo de este comercio". Quien lo hizo de verdad va en
 *    `actor_id` (un empleado, por ejemplo); `actor_name` es una foto del nombre porque un empleado
 *    se borra o se renombra.
 *  - `auditable_type` guarda el nombre completo de la clase (App\Models\Article), igual que
 *    `background_processes.referencia_type`. NO se usa el morph map: el mapa impuesto de este repo
 *    solo conoce cuatro modelos y el resto reventaría.
 *  - `batch_uuid` agrupa todo lo que pasó en UNA acción (una venta toca la venta, sus renglones,
 *    la caja, la cuenta corriente...).
 *  - `old_values` / `new_values` son `longText` y no `json`: hay clientes migrados desde MariaDB,
 *    donde `json` es un alias de longtext con otro comportamiento, y nadie consulta adentro del
 *    JSON desde SQL (mismo criterio que `article_ticket_designs.diseno`).
 *  - Sin `updated_at`: una fila de auditoría es inmutable.
 *
 * Sin foreign keys físicas ni unique compuestos (reglas del repo) y con nombres de índice cortos.
 * Solo agrega una tabla nueva: compatible hacia atrás con cualquier versión anterior del sistema,
 * que simplemente no la usa. Y `AuditLogRecorder` nunca rompe una operación si la tabla no existe.
 */
class CreateAuditLogsTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('audit_logs')) {
            return;
        }

        Schema::create('audit_logs', function (Blueprint $table) {

            $table->bigIncrements('id');

            /* Dueño del comercio: `owner_id` del actor, o su propio id si es el dueño. */
            $table->unsignedBigInteger('user_id')->nullable();

            /* Quién lo hizo de verdad. Null = cola, consola o request sin sesión. */
            $table->unsignedBigInteger('actor_id')->nullable();

            /* Nombre del actor al momento del cambio. */
            $table->string('actor_name', 120)->nullable();

            /* Clase completa del modelo (App\Models\Article) e id de la fila afectada. */
            $table->string('auditable_type', 100);
            $table->unsignedBigInteger('auditable_id')->nullable();

            /* created | updated | deleted | restored | truncated */
            $table->string('event', 20);

            /* JSON. En un update, solo los campos que cambiaron; en un delete, la fila entera. */
            $table->longText('old_values')->nullable();
            $table->longText('new_values')->nullable();

            /* http | queue | console, y el detalle de dónde salió (ruta, job o comando). */
            $table->string('source', 10);
            $table->string('origin', 150)->nullable();
            $table->string('ip', 45)->nullable();

            /* Agrupa todo lo que salió de un mismo request o job. */
            $table->char('batch_uuid', 36);

            /* Sin updated_at: la fila no se modifica. */
            $table->timestamp('created_at')->nullable();

            $table->index(['auditable_type', 'auditable_id'], 'aud_model_idx');
            $table->index(['user_id', 'created_at'], 'aud_owner_date_idx');
            $table->index(['actor_id', 'created_at'], 'aud_actor_date_idx');
            $table->index('batch_uuid', 'aud_batch_idx');
        });
    }

    /**
     * Borra la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('audit_logs');
    }
}
