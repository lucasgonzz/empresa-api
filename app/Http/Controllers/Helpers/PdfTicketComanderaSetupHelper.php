<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\Seeders\PdfColumnProfileSeederHelper;
use App\Models\PdfColumnProfile;
use App\Models\User;
use App\Services\PdfColumnService;
use Illuminate\Support\Facades\DB;

/**
 * Los dos diseños de ticket de comandera por defecto de cada dueño: "Ticket remito" y "Ticket
 * factura" (misión diseno-ticket-comandera, 9/10/2026, pedido 7 de Lucas y §6 del plan).
 *
 * Nacen SIN diseño (`page_layout` NULL): imprimen exactamente el Ticket 2.0 de siempre hasta que
 * alguien los abra en el diseñador (decisión D1). Por eso el release no cambia lo que imprime
 * ninguna comandera; lo único que cambia es que el menú Imprimir los lista por nombre en lugar de
 * "Ticket 2.0" (D-L1) y que el atajo "Ticket 2.0" los usa como el ticket por defecto de cada clase.
 *
 * Cada uno:
 * - venta, `is_afip_ticket` 0 (remito) o 1 (factura), "por defecto" en su clase (D5);
 * - tipo de hoja "Ticket 80 mm" (o "Ticket 55 mm" si el dueño tiene configurado un rollo de
 *   hasta 65 mm en `users.sale_ticket_width`), papel = ancho del rollo, margen 0;
 * - columnas visibles Nombre (con salto de línea), Cant, Precio y Sub total en 9/3/6/6 medias
 *   columnas sobre el ancho del rollo, en mm con la regla del diseñador (la suma no pasa del
 *   rollo); el resto del catálogo, no visible. 9/3/6/6 y no 10/2/6/6 (ajuste del 9/10/2026): con
 *   2 medias "Cant" quedaba en 3 caracteres útiles a 80 mm ("Can") y en 1 a 55 mm.
 *
 * IDEMPOTENTE: si el dueño ya tiene un ticket de esa clase (el de una corrida anterior, uno que armó
 * él, o el que renombró), no se crea otro. Se puede correr las veces que haga falta.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class PdfTicketComanderaSetupHelper
{
    /** Nombre del ticket remito por defecto. */
    const TICKET_REMITO = 'Ticket remito';

    /** Nombre del ticket factura por defecto. */
    const TICKET_FACTURA = 'Ticket factura';

    /** Hasta este ancho configurado (mm) el dueño usa el rollo de 55 mm. */
    const ANCHO_MAXIMO_DE_ROLLO_CHICO = 65;

    /** Medias columnas (de 24) de cada columna visible, en orden: Nombre, Cant, Precio, Sub total. */
    const MEDIAS_COLUMNAS = [
        'Nombre del artículo' => 9,
        'Cantidad' => 3,
        'Precio unitario' => 6,
        'Subtotal línea' => 6,
    ];

    /**
     * Crea los tickets por defecto que le falten a un dueño.
     *
     * @param int $owner_id users.id con owner_id null.
     * @return array{user_id: int, skipped_reason: string|null, creados: array<int, string>}
     */
    public static function apply_for_owner($owner_id)
    {
        $resultado = [
            'user_id' => (int) $owner_id,
            'skipped_reason' => null,
            'creados' => [],
        ];

        $owner = User::query()->whereNull('owner_id')->where('id', $owner_id)->first();
        if (is_null($owner)) {
            $resultado['skipped_reason'] = 'no_es_owner';

            return $resultado;
        }

        $rollos = SheetTypeHelper::asegurar_tickets_del_sistema();
        $rollo = $rollos[self::ancho_del_rollo_del_dueno($owner)];

        DB::beginTransaction();
        try {
            /**
             * Se bloquea la fila del dueño mientras se mira "ya tiene el ticket" y se crea: dos
             * actualizaciones que corren a la vez sobre una base compartida (varios comercios en la
             * misma base, el seeder de cada uno) no pueden ver las dos "no tiene" y crear dos.
             */
            User::query()->where('id', $owner->id)->lockForUpdate()->first();

            foreach ([false, true] as $es_factura) {
                $ya_tiene = PdfColumnProfile::query()
                    ->where('user_id', $owner->id)
                    ->where('model_name', 'sale')
                    ->where('is_afip_ticket', $es_factura)
                    ->deTicket()
                    ->exists();

                if ($ya_tiene) {
                    continue;
                }

                $perfil = PdfColumnProfile::create([
                    'user_id' => $owner->id,
                    'model_name' => 'sale',
                    'name' => $es_factura ? self::TICKET_FACTURA : self::TICKET_REMITO,
                    /** json NOT NULL sin default (ver PdfColumnProfileController::store()). */
                    'columns' => [],
                    /**
                     * 🔴 NO nace por defecto (revisor de merge, 9/10/2026). El código VIEJO elige el PDF
                     * de una venta con `where('is_default', true)` sin mirar el tipo de hoja: mientras
                     * el frente viejo sigue sirviendo durante el despliegue, en las pestañas ya abiertas
                     * y en los comercios de una base compartida que todavía están en una versión
                     * anterior, un ticket por defecto le ganaba al Remito / Factura comun de hoja y el
                     * PDF salía con las columnas del ticket. El código nuevo lo elige igual: el ticket
                     * por defecto de una clase es el de `is_default`, y si no hay ninguno el de menor
                     * id (SaleTicketComanderaHelper::ticket_por_defecto y el menú del SPA).
                     */
                    'is_default' => false,
                    'is_default_whatsapp' => false,
                    'is_default_whatsapp_afip' => false,
                    'is_default_tienda' => false,
                    'paper_width_mm' => (int) $rollo->width,
                    'printable_width_mm' => (int) $rollo->width,
                    'paper_height_mm' => null,
                    'margin_mm' => 0,
                    'sheet_type_id' => $rollo->id,
                    'is_afip_ticket' => $es_factura,
                    'show_totals_on_each_page' => false,
                    /** NULL = el Ticket 2.0 de siempre (D1). */
                    'page_layout' => null,
                ]);

                PdfColumnProfileSeederHelper::assign_profile_options($perfil, 'sale', self::columnas((int) $rollo->width));

                $resultado['creados'][] = $perfil->name;
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $resultado;
    }

    /**
     * Las columnas visibles de los tickets por defecto, en el formato de
     * PdfColumnProfileSeederHelper::assign_profile_options(): cada una con los mm de sus medias
     * columnas sobre el rollo con la MISMA regla que el diseñador (round(medias × ancho / 24) y, si
     * la suma se pasa del rollo, 1 mm menos a la que más subió al redondear:
     * PdfColumnProfileTicketHelper::mm_de_medias()). Así la suma nunca pasa del rollo y el alta no
     * depende de la tolerancia de la validación. 80 mm: 30/10/20/20; 55 mm: 20/7/14/14 (que el
     * diseñador vuelve a leer como 9/3/6/6).
     *
     * @param int $ancho_mm
     * @return array<int, array<string, mixed>>
     */
    public static function columnas($ancho_mm)
    {
        $mm = PdfColumnProfileTicketHelper::mm_de_medias(self::MEDIAS_COLUMNAS, (int) $ancho_mm);

        return [
            ['name' => 'Nombre del artículo', 'width' => $mm['Nombre del artículo'], 'wrap_content' => true],
            ['name' => 'Cantidad', 'width' => $mm['Cantidad']],
            ['name' => 'Precio unitario', 'width' => $mm['Precio unitario']],
            ['name' => 'Subtotal línea', 'width' => $mm['Subtotal línea']],
        ];
    }

    /**
     * El rollo del dueño: 55 mm si configuró un ancho de ticket de 1 a 65 mm, si no 80 mm.
     *
     * @param \App\Models\User $owner
     * @return int 55 | 80
     */
    public static function ancho_del_rollo_del_dueno($owner)
    {
        $configurado = (int) $owner->sale_ticket_width;

        return ($configurado >= 1 && $configurado <= self::ANCHO_MAXIMO_DE_ROLLO_CHICO) ? 55 : 80;
    }

    /**
     * Sincroniza el catálogo de columnas de venta (lo necesita assign_profile_options()). Lo llama
     * el seeder suelto una vez antes de recorrer los dueños.
     *
     * @return void
     */
    public static function sync_catalog_options()
    {
        PdfColumnService::sync_catalog_options('sale');
    }
}
