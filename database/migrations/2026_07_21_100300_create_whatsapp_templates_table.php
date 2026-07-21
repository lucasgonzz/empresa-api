<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `whatsapp_templates`: plantillas de WhatsApp por empresa, usadas para poder
 * responder cuando la ventana de servicio de 24 h de Meta ya cerró
 * (`WhatsappChat::is_within_service_window()` en false). Incluye tanto las 5 plantillas
 * estándar `cc_cli_*` que precarga ComercioCity (`is_system` = true, sembradas por
 * `WhatsappTemplateStandardSeeder`) como las que arme cada empresa a mano (grupo 137,
 * Prompt 04).
 *
 * Sin foreign keys por convención del proyecto (ver CLAUDE.md): la relación lógica con
 * `users` se resuelve en Eloquent, no en el motor. Los índices son manuales.
 */
class CreateWhatsappTemplatesTable extends Migration
{
    /**
     * Crea la tabla si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('whatsapp_templates')) {
            Schema::create('whatsapp_templates', function (Blueprint $table) {
                $table->id();

                // Dueño/empresa a la que pertenece la plantilla.
                $table->unsignedBigInteger('user_id');
                $table->index('user_id', 'wte_user_idx');

                // Nombre visible en el ERP (ej: "Recordatorio de deuda").
                $table->string('name', 80);

                // Nombre técnico exacto tal como está cargado en Meta (ej: "cc_cli_recordatorio_pago").
                $table->string('meta_template_name', 120);
                $table->index('meta_template_name', 'wte_meta_name_idx');

                // Categoría de Meta: 'utility' | 'marketing'.
                $table->string('category', 20);

                // Código de idioma de la plantilla en Meta.
                $table->string('language', 10)->default('es_AR');

                // Cuerpo tal como está en Meta, con placeholders {{1}}, {{2}}...
                $table->text('body_preview');

                // Labels de las variables en el orden en que aparecen en el body (array JSON de strings).
                $table->json('variables')->nullable();

                // Estado del ciclo de vida de la plantilla: borrador | pendiente_meta | aprobada | rechazada.
                $table->string('status', 20)->default('borrador');

                // Plantillas estándar de ComercioCity: no editables ni borrables por el usuario.
                $table->boolean('is_system')->default(false);

                $table->timestamps();

                // Ayuda a listar/filtrar rápido las plantillas usables (aprobadas) de una empresa.
                $table->index(['user_id', 'status'], 'wte_user_status_idx');
            });
        }
    }

    /**
     * Elimina la tabla si existe (rollback completo).
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('whatsapp_templates');
    }
}
