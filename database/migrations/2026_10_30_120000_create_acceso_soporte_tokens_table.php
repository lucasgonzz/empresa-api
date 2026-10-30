<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los accesos de soporte de un solo uso (misión acceso-soporte-firmado, 30/10/2026).
 *
 * EL PORQUÉ. Hasta hoy, para entrar al sistema de un cliente a mirar un problema, o el agente (Claude Code,
 * Codex) usaba una sesión que Lucas había guardado a mano entrando cliente por cliente, o se usaba el login
 * maestro. Esta tabla es la mitad de la tercera vía: el admin le pide a ESTE sistema (con la clave compartida
 * `X-Admin-Api-Key`) un token de un solo uso y de vida corta, y con ese token se abre una sesión del dueño sin
 * que nadie escriba una contraseña. Ver `AccesoSoporteHelper` (emitir / canjear) y los dos controladores.
 *
 * Qué guarda y qué NO guarda:
 *
 *   - 🔴 NUNCA el token en claro: solo `token_hash` (sha256 en hexadecimal, 64 caracteres). El claro viaja una
 *     única vez, en la respuesta de la emisión. Si la base se filtra, estos hashes no sirven para entrar.
 *   - La fila NO se borra al usarse: es el rastro de auditoría de quién pidió el acceso (`solicitante`,
 *     `motivo`, `ip_solicitud`), cuándo (`created_at`) y quién lo canjeó y desde dónde (`usado_en`,
 *     `ip_canje`, `user_agent_canje`).
 *
 * Sin claves foráneas (regla del repo): `owner_id` apunta a `users.id` por convención, nada lo impone en la base.
 *
 * 🔴 `owner_id` y NO `user_id`, a propósito. `EsquemaDeDatosIaHelper` deriva como entidad consultable por el
 * asistente toda tabla con columna `user_id` que no esté en su lista negra (medido el 30/10/2026: con `user_id`
 * esta tabla aparecía como la entidad `acceso_soporte_token`, con quién entró, desde qué IP y navegador, a
 * disposición de `consultar_datos`). Es el mismo motivo por el que `ai_avisos_del_asistente` y
 * `ai_envios_fallidos` usan `owner_id`: el rastro de los accesos de soporte es del sistema, no un dato del
 * negocio que se lee desde el chat.
 *
 * `vence_en` es NULLABLE a propósito: en MySQL, un `timestamp` NOT NULL sin default toma `CURRENT_TIMESTAMP` y
 * hasta se auto-actualiza en cada UPDATE según la configuración del servidor, y un vencimiento que se mueve
 * solo es exactamente lo que no se quiere acá. Con NULL, una fila sin vencimiento no se puede canjear nunca
 * (la condición `vence_en > ahora` da falso), que es el lado seguro.
 *
 * Aditiva y con `hasTable`: correrla dos veces no rompe.
 */
class CreateAccesoSoporteTokensTable extends Migration
{
    /**
     * Crea la tabla si falta.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('acceso_soporte_tokens')) {
            return;
        }

        Schema::create('acceso_soporte_tokens', function (Blueprint $table) {
            $table->bigIncrements('id');

            // sha256 del token en hexadecimal (64 caracteres). Es por donde se busca al canjear.
            $table->char('token_hash', 64)->default('');

            // El dueño al que da acceso este token (sin FK). `owner_id` y no `user_id`: ver el docblock.
            $table->unsignedBigInteger('owner_id')->default(0);

            // Quién pidió el acceso ("claude", "codex", un nombre) y por qué. Saneados al emitir.
            $table->string('solicitante', 60)->nullable();
            $table->string('motivo', 120)->nullable();

            // IP desde la que llegó el pedido de emisión (la del admin). IPv6 entra en 45 caracteres.
            $table->string('ip_solicitud', 45)->nullable();

            // Hasta cuándo se puede canjear (NULL = no se puede canjear nunca; ver el docblock).
            $table->timestamp('vence_en')->nullable();

            // Cuándo se canjeó, y desde qué IP y navegador. NULL en `usado_en` = todavía sin usar.
            $table->timestamp('usado_en')->nullable();
            $table->string('ip_canje', 45)->nullable();
            $table->string('user_agent_canje', 150)->nullable();

            $table->timestamps();

            $table->index('token_hash', 'ast_hash_idx');
            $table->index('owner_id', 'ast_owner_idx');
        });
    }

    /**
     * Borra la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('acceso_soporte_tokens');
    }
}
