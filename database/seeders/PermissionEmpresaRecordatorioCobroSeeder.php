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
