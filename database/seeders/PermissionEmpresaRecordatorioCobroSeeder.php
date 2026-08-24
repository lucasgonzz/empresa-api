<?php

namespace Database\Seeders;

use App\Models\PermissionEmpresa;
use Illuminate\Database\Seeder;

/**
 * Registra el permiso de empleado `alerts.recordatorio_cobro` (misión
 * recordatorio-cobro-whatsapp), en el mismo espíritu que `PermissionEmpresaWhatsappSeeder`.
 *
 * 🔴 El permiso NO es "ver la alerta de cobros". La solapa Cobros del módulo de alertas se
 * sigue viendo sin él, con la lista de deudores completa. Lo que habilita es MANDARLE el
 * recordatorio al cliente final por WhatsApp, de a uno o a todos los deudores del filtro de
 * una sola vez. Son dos capacidades distintas: mirar quién debe es información interna,
 * escribirle al cliente en nombre del negocio no.
 *
 * Existe además de la entrada en `PermissionSeeder` porque los dos seeders sirven a casos
 * distintos, como manda la regla del repo: `PermissionSeeder` usa `create()` y arma el
 * catálogo de una base nueva; este usa `firstOrCreate()` y es el que se corre suelto sobre
 * una base de producción que ya existe, sin duplicar filas:
 *   php artisan db:seed --class=PermissionEmpresaRecordatorioCobroSeeder
 *
 * 🔴 POR ESO MISMO NO ESTÁ REGISTRADO EN `DatabaseSeeder`, y no es un olvido: no lo agregues.
 * `permission_empresas.slug` no tiene índice único y ningún seeder trunca la tabla, así que si
 * este corriera además de `PermissionSeeder` —que ya siembra `alerts.recordatorio_cobro`— toda
 * base nueva nacería con el permiso DUPLICADO y el empleado lo vería dos veces en pantalla.
 * `DatabaseSeeder` tiene el comentario largo, con el detalle de por qué la entrada que se saca es
 * esa y no la de `PermissionSeeder`.
 */
class PermissionEmpresaRecordatorioCobroSeeder extends Seeder
{
    /**
     * Ejecuta el seeder.
     *
     * @return void
     */
    public function run()
    {
        // Permiso para disparar el recordatorio de cobro por WhatsApp desde alertas > Cobros.
        PermissionEmpresa::firstOrCreate(
            ['slug' => 'alerts.recordatorio_cobro'],
            [
                'name' => 'Mandar recordatorios de cobro por WhatsApp',
                'model_name' => 'Alertas',
            ]
        );
    }
}
