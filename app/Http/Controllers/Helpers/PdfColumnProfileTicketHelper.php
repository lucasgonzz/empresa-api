<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\PdfLayout\CatalogoDeCamposPdf;
use App\Http\Controllers\Helpers\PdfLayout\DisenoDePaginaPdf;
use App\Models\PdfColumnProfile;
use App\Models\SheetType;

/**
 * Reglas de un diseño de PDF que es un TICKET DE COMANDERA (misión diseno-ticket-comandera,
 * 9/10/2026, contrato §3.2 del plan). Las usa PdfColumnProfileController en store() y update().
 *
 * Qué es un ticket (D2): un perfil de modelo `sale` cuyo tipo de hoja no tiene alto (rollo
 * continuo). Sin tipo de hoja es hoja (D12).
 *
 * Lo que la API fuerza en un ticket, venga lo que venga del formulario:
 * - el papel es el rollo: paper_width_mm = printable_width_mm = ancho del rollo, margin_mm = 0 y
 *   paper_height_mm = null;
 * - nunca es "Predeterminado WhatsApp / WhatsApp factura / Tienda" ni "pie en cada hoja": todo eso
 *   es de un PDF, y un ticket nunca se dibuja como PDF (D4).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class PdfColumnProfileTicketHelper
{
    /** La hoja con la que vuelve un ticket que pasa a ser hoja sin traer la suya (A4, margen 5). */
    const HOJA_POR_DEFECTO = [
        'paper_width_mm' => 210,
        'printable_width_mm' => 210,
        'margin_mm' => 5,
    ];

    /** El 422 de un ticket en un modelo que no es venta. */
    const MENSAJE_TICKET_SOLO_EN_VENTA = 'Solo un diseño de venta puede imprimirse en una comandera. Elegí una hoja para este diseño.';

    /**
     * El tipo de hoja que puede usar el dueño con ese id (del sistema o suyo), o null.
     *
     * @param mixed $sheet_type_id
     * @param int   $owner_id
     * @return \App\Models\SheetType|null
     */
    public static function tipo_de_hoja($sheet_type_id, $owner_id)
    {
        if (! is_numeric($sheet_type_id) || (int) $sheet_type_id <= 0) {
            return null;
        }

        return SheetType::query()
            ->delSistemaODelDueno($owner_id)
            ->where('id', (int) $sheet_type_id)
            ->first();
    }

    /**
     * ¿Ese tipo de hoja es un rollo de comandera?
     *
     * @param \App\Models\SheetType|null $sheet_type
     * @return bool
     */
    public static function es_ticket($sheet_type)
    {
        return ! is_null($sheet_type) && $sheet_type->es_ticket();
    }

    /**
     * ¿El perfil guardado es un ticket? Se resuelve por su sheet_type_id contra la base (no por la
     * relación cargada, que puede haber quedado vieja dentro del mismo pedido).
     *
     * @param \App\Models\PdfColumnProfile $perfil
     * @return bool
     */
    public static function perfil_es_ticket(PdfColumnProfile $perfil)
    {
        if (empty($perfil->sheet_type_id)) {
            return false;
        }

        $sheet_type = SheetType::find($perfil->sheet_type_id);

        return self::es_ticket($sheet_type);
    }

    /**
     * Lo que se fuerza en un perfil de ticket (contrato §3.2).
     *
     * @param \App\Models\SheetType $sheet_type el rollo de comandera.
     * @return array<string, mixed>
     */
    public static function atributos_forzados($sheet_type)
    {
        return [
            'paper_width_mm' => (int) $sheet_type->width,
            'printable_width_mm' => (int) $sheet_type->width,
            'margin_mm' => 0,
            'paper_height_mm' => null,
            'is_default_whatsapp' => false,
            'is_default_whatsapp_afip' => false,
            'is_default_tienda' => false,
            'show_totals_on_each_page' => false,
        ];
    }

    /**
     * La hoja con que queda un ticket que pasa a ser hoja (contrato §3.2: "de ticket a hoja sin
     * datos de hoja en el pedido → 210/210/5").
     *
     * "Sin datos de hoja" incluye que el pedido traiga el MISMO ancho que el rollo que tenía: el
     * formulario genérico del ABM reenvía siempre el modelo entero, así que al pasar de "Ticket
     * 80 mm" a "A4" manda 80/80/0, y eso no es una hoja que alguien eligió.
     *
     * @param \Illuminate\Http\Request      $request
     * @param \App\Models\PdfColumnProfile  $perfil el ticket tal como está guardado.
     * @return array<string, mixed> lo que hay que mezclar en el pedido ([] si trae su propia hoja).
     */
    public static function hoja_al_dejar_de_ser_ticket($request, PdfColumnProfile $perfil)
    {
        $ancho_pedido = $request->input('paper_width_mm');

        $trae_su_hoja = ! is_null($ancho_pedido)
            && $ancho_pedido !== ''
            && (int) $ancho_pedido !== (int) $perfil->paper_width_mm;

        if ($trae_su_hoja) {
            return [];
        }

        return array_merge(self::HOJA_POR_DEFECTO, ['paper_height_mm' => null]);
    }

    /**
     * ¿El pedido cambia el diseño de la hoja? Al cambiar de clase (ticket ↔ hoja) el diseño guardado
     * no sirve en la otra (contrato §3.2): sin page_layout en el pedido se vuelve a null. El
     * formulario genérico reenvía SIEMPRE el page_layout que tenía: un page_layout igual al guardado
     * cuenta como "no vino" (si no, un diseño de hoja quedaba pegado al ticket nuevo).
     *
     * @param bool       $vino_page_layout el pedido trae la clave page_layout.
     * @param array|null $page_layout      el del pedido, ya normalizado (sin fijos).
     * @param array|null $guardado         el que tiene el perfil.
     * @return bool true = el pedido trae un diseño propio para la clase nueva.
     */
    public static function trae_diseno_propio($vino_page_layout, $page_layout, $guardado)
    {
        if (! $vino_page_layout || is_null($page_layout)) {
            return false;
        }

        $guardado_normalizado = null;

        try {
            $guardado_normalizado = DisenoDePaginaPdf::normalizar($guardado);
        } catch (\InvalidArgumentException $e) {
            $guardado_normalizado = null;
        }

        return self::sin_fijos($page_layout) !== self::sin_fijos($guardado_normalizado);
    }

    /**
     * Apaga el "Perfil por defecto" de los hermanos de la MISMA clase (decisión D5): un perfil de
     * hoja apaga el de los demás perfiles de hoja del mismo modelo (como siempre, sin tocar los
     * tickets); un ticket apaga el de los demás tickets con el mismo is_afip_ticket (un ticket
     * remito y un ticket factura por defecto, que son los que usa el atajo "Ticket 2.0").
     *
     * @param int      $owner_id
     * @param string   $model_name
     * @param bool     $es_ticket
     * @param bool     $is_afip_ticket
     * @param int|null $except_id el perfil que queda por defecto (en update).
     * @return void
     */
    public static function apagar_por_defecto_de_la_clase($owner_id, $model_name, $es_ticket, $is_afip_ticket, $except_id = null)
    {
        $query = PdfColumnProfile::where('user_id', $owner_id)
            ->where('model_name', $model_name);

        if ($es_ticket) {
            $query->deTicket()->where('is_afip_ticket', (bool) $is_afip_ticket);
        } else {
            $query->deHoja();
        }

        if (! is_null($except_id)) {
            $query->where('id', '!=', $except_id);
        }

        $query->update(['is_default' => false]);
    }

    /**
     * El ancho útil sobre el que se miden las columnas de la tabla (D9): ancho imprimible menos los
     * dos márgenes; en un ticket, el ancho del rollo (margen 0).
     *
     * @param int $printable_width_mm
     * @param int $margin_mm
     * @return int
     */
    public static function ancho_util_de_columnas($printable_width_mm, $margin_mm)
    {
        return PdfColumnProfileHelper::ancho_disponible_mm($printable_width_mm, $margin_mm);
    }

    /**
     * Medias columnas de la grilla de 24 que suman unas columnas de la tabla con esos anchos en mm
     * (la cuenta del diseñador, D9: max(1, round(mm × 24 / útil)) cada una).
     *
     * El diseñador guarda los mm con round(medias × útil / 24): con una suma de 24 medias, el
     * redondeo puede dejar la suma en mm uno o dos por encima del útil (55 mm: 10/2/6/6 medias dan
     * 23 + 5 + 14 + 14 = 56 mm). Por eso la validación de anchos acepta también una tabla que entra
     * en 24 medias columnas.
     *
     * @param array<int, int> $anchos_mm de las columnas visibles.
     * @param int             $ancho_util
     * @return int
     */
    public static function medias_columnas($anchos_mm, $ancho_util)
    {
        if ($ancho_util <= 0) {
            return 0;
        }

        $total = 0;
        foreach ($anchos_mm as $ancho_mm) {
            $total += max(1, (int) round(((int) $ancho_mm) * CatalogoDeCamposPdf::GRILLA_DE_TABLA / $ancho_util));
        }

        return $total;
    }

    /**
     * Las columnas de la tabla de un perfil que CAMBIA DE CLASE (ticket ↔ hoja), llevadas al ancho
     * útil nuevo conservando sus medias columnas (D9: "cambiar la hoja recalcula los mm"). Sin esto,
     * pasar una A4 (columnas que suman 200 mm) a un rollo de 80 mm daba 422 por la suma de anchos.
     *
     * Si las columnas visibles ya entran en el ancho nuevo (el diseñador ya las recalculó), quedan
     * como vinieron. Si no, cada una (visibles y ocultas) pasa por la cuenta del diseñador:
     * medias = max(1, round(mm × 24 / útil viejo)) y mm = round(medias × útil nuevo / 24).
     *
     * @param array $opciones   columnas en la forma del pedido: [{id, pivot: {visible, width, ...}}].
     * @param int   $util_viejo
     * @param int   $util_nuevo
     * @return array
     */
    public static function columnas_para_otro_ancho($opciones, $util_viejo, $util_nuevo)
    {
        if (! is_array($opciones) || $util_viejo <= 0 || $util_nuevo <= 0) {
            return $opciones;
        }

        $suma_visible = 0;
        foreach ($opciones as $opcion) {
            $pivot = (is_array($opcion) && isset($opcion['pivot']) && is_array($opcion['pivot'])) ? $opcion['pivot'] : [];
            $visible = ! array_key_exists('visible', $pivot) || (bool) $pivot['visible'];
            if ($visible) {
                $suma_visible += (int) (isset($pivot['width']) ? $pivot['width'] : 0);
            }
        }

        if ($suma_visible <= $util_nuevo) {
            return $opciones;
        }

        foreach ($opciones as $i => $opcion) {
            if (! is_array($opcion) || ! isset($opcion['pivot']['width'])) {
                continue;
            }

            $medias = max(1, (int) round(((int) $opcion['pivot']['width']) * CatalogoDeCamposPdf::GRILLA_DE_TABLA / $util_viejo));
            $opciones[$i]['pivot']['width'] = (int) round($medias * $util_nuevo / CatalogoDeCamposPdf::GRILLA_DE_TABLA);
        }

        return $opciones;
    }

    /**
     * Las columnas guardadas de un perfil en la forma del pedido ([{id, pivot}]), para poder
     * recalcularlas y volver a guardarlas como si las hubiera mandado el formulario.
     *
     * @param PdfColumnProfile $perfil
     * @return array
     */
    public static function columnas_guardadas($perfil)
    {
        $perfil->loadMissing('pdf_column_options');

        $opciones = [];
        foreach ($perfil->pdf_column_options as $option) {
            $opciones[] = [
                'id' => $option->id,
                'pivot' => [
                    'visible' => (bool) $option->pivot->visible,
                    'order' => (int) $option->pivot->order,
                    'width' => (int) $option->pivot->width,
                    'wrap_content' => (bool) $option->pivot->wrap_content,
                    'font_size' => $option->pivot->font_size,
                    'text_align' => $option->pivot->text_align,
                ],
            ];
        }

        return $opciones;
    }

    /**
     * Un diseño sin sus bloques fijos (para comparar dos diseños de clases distintas: los fijos los
     * pone asegurar_fijos() según la clase).
     *
     * @param array|null $diseno
     * @return array|null
     */
    private static function sin_fijos($diseno)
    {
        if (! is_array($diseno)) {
            return null;
        }

        foreach (DisenoDePaginaPdf::ZONAS as $zona) {
            $items = isset($diseno[$zona]) && is_array($diseno[$zona]) ? $diseno[$zona] : [];
            $diseno[$zona] = array_values(array_filter($items, function ($item) {
                return ! (isset($item['tipo']) && $item['tipo'] === DisenoDePaginaPdf::TIPO_FIJO);
            }));
        }

        return $diseno;
    }
}
