<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\PdfDocumentSetupHelper;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Diseños de PDF por defecto de los presupuestos ("Presupuesto", "Presupuesto sin precios",
 * "Presupuesto con imágenes") y de los pedidos online ("Pedido online"), para todos los dueños.
 *
 * Es el seeder suelto para PRODUCCIÓN: se publica en la lista `seeders` de la versión, detrás de
 * `PdfColumnOptionSeeder` (que sincroniza el catálogo de columnas de `budget` y `order`).
 * Idempotente: los diseños que un dueño ya tiene no se tocan (ver `PdfDocumentSetupHelper`).
 *
 * Itera SOLO dueños (`owner_id` null), nunca `User::all()`: un empleado no tiene diseños propios.
 */
class PdfColumnProfileDocumentosSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run()
    {
        PdfDocumentSetupHelper::sync_catalog_options();

        User::query()
            ->whereNull('owner_id')
            ->select('id')
            ->orderBy('id')
            ->chunkById(200, function ($users) {
                foreach ($users as $user) {
                    PdfDocumentSetupHelper::apply_for_owner($user->id);
                }
            });
    }
}
