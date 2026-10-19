<?php

namespace App\Http\Controllers\Helpers;

use App\Models\SheetType;

/**
 * Tipos de hoja que ve un dueño y los anchos de comandera propios que agrega desde el formulario de
 * Diseño de PDF (misión diseno-ticket-comandera, 9/10/2026, contrato §3.1 del plan).
 *
 * Decisión D-L2 de Lucas: el "ancho personalizado" es un ancho de ROLLO de comandera (40 a 120 mm),
 * queda guardado para el negocio y aparece en el select de los demás diseños. Las hojas siguen
 * siendo las del sistema (A4; Carta, Oficio y A5 se eligen adentro del diseñador).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class SheetTypeHelper
{
    /** Ancho mínimo (mm) de un rollo de comandera que se puede agregar. */
    const ANCHO_MINIMO_DE_COMANDERA = 40;

    /** Ancho máximo (mm) de un rollo de comandera que se puede agregar. */
    const ANCHO_MAXIMO_DE_COMANDERA = 120;

    /**
     * Los tipos de hoja que puede usar el dueño: los del sistema y los suyos, con los rollos de
     * comandera primero (de menor a mayor ancho) y después las hojas.
     *
     * @param int $owner_id
     * @return \Illuminate\Support\Collection<int, \App\Models\SheetType>
     */
    public static function del_dueno($owner_id)
    {
        /** Se ordena en PHP: el criterio (tickets primero) no es una columna. */
        $tipos = SheetType::query()
            ->delSistemaODelDueno($owner_id)
            ->orderBy('id')
            ->get();

        return $tipos->sort(function ($a, $b) {
            /** 0 = ticket, 1 = hoja: los tickets van primero. */
            $clase_a = $a->es_ticket() ? 0 : 1;
            $clase_b = $b->es_ticket() ? 0 : 1;

            if ($clase_a !== $clase_b) {
                return $clase_a <=> $clase_b;
            }

            if ((int) $a->width !== (int) $b->width) {
                return (int) $a->width <=> (int) $b->width;
            }

            return (int) $a->id <=> (int) $b->id;
        })->values();
    }

    /**
     * El rollo de comandera de ese ancho que ya ve el dueño (del sistema o suyo), o null.
     *
     * @param int $owner_id
     * @param int $ancho_mm
     * @return \App\Models\SheetType|null
     */
    public static function ticket_existente($owner_id, $ancho_mm)
    {
        return SheetType::query()
            ->delSistemaODelDueno($owner_id)
            ->deTicket()
            ->where('width', (int) $ancho_mm)
            /** Primero el del sistema (user_id NULL ordena antes), después el más viejo. */
            ->orderByRaw('sheet_types.user_id IS NOT NULL')
            ->orderBy('id')
            ->first();
    }

    /**
     * Devuelve el rollo de comandera de ese ancho para el dueño: el que ya existe (del sistema o
     * suyo) o uno nuevo, propio del dueño, llamado "Ticket {ancho} mm".
     *
     * @param int $owner_id
     * @param int $ancho_mm ya validado (ANCHO_MINIMO_DE_COMANDERA..ANCHO_MAXIMO_DE_COMANDERA).
     * @return array{model: \App\Models\SheetType, creado: bool}
     */
    public static function asegurar_ticket_del_dueno($owner_id, $ancho_mm)
    {
        $existente = self::ticket_existente($owner_id, $ancho_mm);

        if (! is_null($existente)) {
            return ['model' => $existente, 'creado' => false];
        }

        $nuevo = SheetType::create([
            'name' => self::nombre_de_ticket($ancho_mm),
            'width' => (int) $ancho_mm,
            'height' => null,
            'user_id' => (int) $owner_id,
        ]);

        return ['model' => $nuevo, 'creado' => true];
    }

    /**
     * Nombre con el que se ve un rollo de comandera en el select ("Ticket 72 mm"). Es el mismo
     * formato que los dos del sistema (SheetTypeSeeder).
     *
     * @param int $ancho_mm
     * @return string
     */
    public static function nombre_de_ticket($ancho_mm)
    {
        return 'Ticket '.(int) $ancho_mm.' mm';
    }

    /**
     * Asegura los dos rollos del sistema (55 y 80 mm). Es lo mismo que hace SheetTypeSeeder para
     * ellos, por nombre, sin tocar el A4 ni los personalizados. Lo usan los perfiles de ticket por
     * defecto (PdfTicketComanderaSetupHelper) antes de crearse, para no depender del orden de los
     * seeders en una base de producción.
     *
     * @return array{55: \App\Models\SheetType, 80: \App\Models\SheetType}
     */
    public static function asegurar_tickets_del_sistema()
    {
        $tickets = [];

        foreach ([55, 80] as $ancho_mm) {
            $tickets[$ancho_mm] = SheetType::updateOrCreate(
                ['name' => self::nombre_de_ticket($ancho_mm), 'user_id' => null],
                ['width' => $ancho_mm, 'height' => null]
            );
        }

        return $tickets;
    }
}
