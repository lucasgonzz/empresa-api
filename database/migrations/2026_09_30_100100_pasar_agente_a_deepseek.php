<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pasa a DeepSeek el asistente de los dueños que todavía lo tienen en Claude (misión
 * modelos-ia-por-cliente, 30/9/2026, decisión 3 de Lucas: "el código deja todo en DeepSeek por
 * defecto, también los dueños actuales que quedaron en Claude").
 *
 * La migración del 28/9 (cambiar_defaults_agente_ia_deepseek_directo) solo cambió el DEFAULT de la
 * columna, que vale para los INSERT futuros: los dueños que ya existían con 'anthropic' guardado
 * siguieron en Claude. Esto los pasa.
 *
 * 🔴 SOLO `agente_proveedor`, y solo en los dueños (`owner_id IS NULL`, que es donde se lee la
 * configuración del asistente). NO toca:
 * - `agente_pensamiento`: un 'equilibrado' guardado con Claude cae solo a 'agil' (Flash) en DeepSeek,
 *   ya lo resuelve ProveedorIaHelper::pensamiento_de(); 'agil' y 'profundo' existen en los dos.
 * - `agente_confianza`: no tiene nada que ver con el proveedor.
 *
 * 🔴 ES SEGURO AUNQUE LA INSTALACIÓN NO TENGA DEEPSEEK_API_KEY (hoy ningún .env de producción la
 * tiene): ProveedorIaHelper::proveedor_de() cae a Anthropic cuando el elegido no tiene clave, así que
 * el asistente de esos dueños sigue corriendo con Claude, con el mismo modelo que hoy, hasta que se
 * cargue la clave. Lo que cambia es lo ELEGIDO, no lo que corre.
 *
 * 🔴 `down()` NO REVIERTE LOS DATOS, a propósito: después de correr esto ya no se puede saber qué
 * dueños estaban en Claude por elección y cuáles por el default viejo, y volver a poner 'anthropic'
 * a todos los 'deepseek' pisaría a los que eligieron DeepSeek a mano. Si hiciera falta volver, se
 * elige de nuevo desde el admin (solapa "Inteligencia artificial") o desde "Configurá tu asistente".
 */
class PasarAgenteADeepseek extends Migration
{
    /**
     * Reescribe `agente_proveedor` de 'anthropic' a 'deepseek' en los dueños.
     *
     * @return void
     */
    public function up()
    {
        /* Guard: una base vieja sin la columna (anterior al 22/9/2026) no tiene nada que pasar. */
        if (! Schema::hasColumn('users', 'agente_proveedor')) {
            return;
        }

        DB::table('users')
            ->whereNull('owner_id')
            ->where('agente_proveedor', 'anthropic')
            ->update(['agente_proveedor' => 'deepseek']);
    }

    /**
     * No revierte datos (ver el docblock de la clase).
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
