<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cambia el DEFAULT de columna de `agente_proveedor` y `agente_confianza` para que todo negocio
 * NUEVO nazca con el agente resuelto (misión agente-ia-default-deepseek-directo, 28/9/2026,
 * pedido de Lucas).
 *
 * `agente_proveedor` pasa de 'anthropic' a 'deepseek': el asistente de un negocio nuevo corre con
 * DeepSeek de entrada (ver ProveedorIaHelper::PROVEEDOR_POR_DEFECTO, que se actualiza junto con
 * esta migración porque tiene que coincidir con el default de columna).
 *
 * `agente_confianza` pasa de 'resuelto' a 'directo': un negocio nuevo arranca con el agente
 * ejecutando en el acto lo que HerramientasDeCarga::AUTO_CONFIRMABLES_DIRECTO permite, sin dejar
 * tarjeta (ver ConfianzaDelAgenteIaHelper::POR_DEFECTO, misma razón).
 *
 * `agente_pensamiento` NO se toca: su default sigue siendo 'agil', que con DeepSeek YA es Flash
 * (ProveedorIaHelper::PENSAMIENTOS_POR_PROVEEDOR). No hace falta migración para esa columna.
 *
 * 🔴 SOLO CAMBIA EL DEFAULT DE COLUMNA, no reescribe filas existentes. Un dueño que ya tiene
 * 'anthropic'/'resuelto' guardado (aunque sea el mismo valor que el default viejo) NO se toca acá:
 * MySQL solo aplica un ALTER ... DEFAULT a los INSERT futuros que no manden la columna. Aplicarlo
 * retroactivamente a los clientes que ya existen es una escritura de producción que corre desde la
 * raíz del pool, fuera de esta migración (ver plan de la misión).
 *
 * Sin foreign keys, con ALTER directo: no hace falta guard de "columna ya existe" porque las dos
 * columnas ya están creadas por migraciones anteriores; esto solo les cambia el default.
 */
class CambiarDefaultsAgenteIaDeepseekDirecto extends Migration
{
    /**
     * Cambia el default de las dos columnas.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('agente_proveedor', 20)->default('deepseek')->change();
            $table->string('agente_confianza', 20)->default('directo')->change();
        });
    }

    /**
     * Vuelve los defaults a los valores anteriores.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('agente_proveedor', 20)->default('anthropic')->change();
            $table->string('agente_confianza', 20)->default('resuelto')->change();
        });
    }
}
