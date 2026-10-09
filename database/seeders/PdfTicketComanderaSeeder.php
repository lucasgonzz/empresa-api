<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\PdfTicketComanderaSetupHelper;
use App\Http\Controllers\Helpers\SheetTypeHelper;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Los dos diseños de ticket de comandera por defecto ("Ticket remito" y "Ticket factura") para
 * todos los dueños (misión diseno-ticket-comandera, 9/10/2026, §6 del plan).
 *
 * Es el seeder suelto para PRODUCCIÓN: va en la lista `seeders` de la versión. Nacen sin diseño
 * (page_layout NULL), así que no cambian lo que imprime ninguna comandera (decisión D1).
 * Idempotente: un dueño que ya tiene un ticket de una clase no recibe otro (ver
 * PdfTicketComanderaSetupHelper).
 *
 * Itera SOLO dueños (`owner_id` null), nunca `User::all()`: un empleado no tiene diseños propios.
 * Antes asegura los dos rollos del sistema (Ticket 55 mm y Ticket 80 mm) y el catálogo de columnas
 * de venta, para no depender del orden de los seeders en una base vieja.
 */
class PdfTicketComanderaSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run()
    {
        SheetTypeHelper::asegurar_tickets_del_sistema();
        PdfTicketComanderaSetupHelper::sync_catalog_options();

        User::query()
            ->whereNull('owner_id')
            ->select('id')
            ->orderBy('id')
            ->chunkById(200, function ($users) {
                foreach ($users as $user) {
                    PdfTicketComanderaSetupHelper::apply_for_owner($user->id);
                }
            });
    }
}
