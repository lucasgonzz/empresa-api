<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\PdfLayout\CatalogoDeCamposPdf;
use App\Http\Controllers\Helpers\PdfLayout\DisenoDePaginaPdf;
use App\Http\Controllers\Helpers\PdfLayout\DisenoDerivadoPdf;
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
     * ¿La suma de unas columnas entra en el ancho útil, con el margen del redondeo de la grilla de
     * 24 medias columnas (D9)?
     *
     * El diseñador guarda cada ancho como round(medias × útil / 24): cada columna puede subir hasta
     * medio milímetro al redondear, así que una tabla llena puede pasarse del útil como mucho
     * ceil(columnas / 2) mm. Esa es toda la tolerancia: una tabla que de verdad no entra (12 columnas
     * de 20 mm en los 200 de una A4) sigue sin entrar. Ajuste del 9/10/2026 a pedido del revisor: la
     * primera versión aceptaba cualquier tabla que entrara en 24 medias columnas, que era demasiado.
     *
     * 🔴 Y SOLO PARA UNA TABLA DE LA GRILLA: cada ancho tiene que estar a 1 mm o menos de un múltiplo
     * de media columna (round, y el mm que el diseñador le saca a la que más subió). Una tabla armada
     * a mano en mm (el formulario de siempre, el asistente) sigue con la regla exacta de siempre: la
     * suma no pasa del útil. Lo exige ChatIa/29 (`el_put_del_abm_sigue_validando_la_suma_de_anchos_
     * con_la_misma_regla`): 8+15+30+133+15 = 201 en 200 útiles es 422, y con la tolerancia sola
     * (5 columnas → hasta 203) pasaba.
     *
     * @param array<int, int> $anchos_mm de las columnas visibles.
     * @param int             $ancho_util
     * @return bool
     */
    public static function suma_dentro_del_redondeo($anchos_mm, $ancho_util)
    {
        if ((int) $ancho_util <= 0) {
            return false;
        }

        $suma = 0;
        $de_la_grilla = true;

        foreach ($anchos_mm as $ancho_mm) {
            $ancho_mm = (int) $ancho_mm;
            $suma += $ancho_mm;

            /** ¿Es un ancho de la grilla? El múltiplo de media columna más cercano, a 1 mm o menos. */
            $medias = max(1, (int) round($ancho_mm * CatalogoDeCamposPdf::GRILLA_DE_TABLA / $ancho_util));
            $multiplo = $medias * $ancho_util / CatalogoDeCamposPdf::GRILLA_DE_TABLA;

            if (abs($ancho_mm - $multiplo) > 1.000001) {
                $de_la_grilla = false;
            }
        }

        /** Fuera de la grilla, la regla exacta de siempre; en la grilla, el margen del redondeo. */
        $tolerancia = $de_la_grilla ? (int) ceil(count($anchos_mm) / 2) : 0;

        return $suma <= (int) $ancho_util + $tolerancia;
    }

    /**
     * Pasa anchos exactos (con decimales) a mm enteros sin que la suma se pase del útil: la regla
     * del diseñador (SPA). Cada uno se redondea y, si la suma queda por encima del útil, se le saca
     * 1 mm a la columna que más subió al redondear (en un empate, a la más ancha; después, a la
     * primera), de a uno, hasta entrar. Ninguna baja de 1 mm.
     *
     * @param array<int|string, float> $exactos
     * @param int                      $ancho_util
     * @return array<int|string, int> con las mismas claves.
     */
    public static function repartir_mm($exactos, $ancho_util)
    {
        $redondeados = [];
        $suma = 0;
        foreach ($exactos as $clave => $exacto) {
            $redondeados[$clave] = max(1, (int) round($exacto));
            $suma += $redondeados[$clave];
        }

        while ($suma > (int) $ancho_util) {
            $elegida = null;

            foreach ($redondeados as $clave => $mm) {
                if ($mm <= 1) {
                    continue;
                }

                if (is_null($elegida)) {
                    $elegida = $clave;
                    continue;
                }

                $subio = $mm - $exactos[$clave];
                $subio_elegida = $redondeados[$elegida] - $exactos[$elegida];

                if ($subio > $subio_elegida + 0.000001
                    || (abs($subio - $subio_elegida) <= 0.000001 && $mm > $redondeados[$elegida])) {
                    $elegida = $clave;
                }
            }

            /** Todas en 1 mm: no hay a quién sacarle. */
            if (is_null($elegida)) {
                break;
            }

            $redondeados[$elegida]--;
            $suma--;
        }

        return $redondeados;
    }

    /**
     * Los mm de unas columnas a partir de sus medias columnas de 24 sobre un ancho útil, con la
     * regla del diseñador (repartir_mm()): la suma nunca pasa del útil.
     *
     * @param array<int|string, int> $medias
     * @param int                    $ancho_util
     * @return array<int|string, int>
     */
    public static function mm_de_medias($medias, $ancho_util)
    {
        $exactos = [];
        foreach ($medias as $clave => $cantidad) {
            $exactos[$clave] = $cantidad * $ancho_util / CatalogoDeCamposPdf::GRILLA_DE_TABLA;
        }

        return self::repartir_mm($exactos, $ancho_util);
    }

    /**
     * Las columnas de la tabla llevadas de un ancho útil a otro conservando sus medias columnas
     * (D9: "cambiar la hoja recalcula los mm"): medias = max(1, round(mm × 24 / útil viejo)) y, sobre
     * el útil nuevo, las visibles repartidas con la regla del diseñador (repartir_mm(): la suma no
     * pasa del útil nuevo) y las ocultas con su redondeo (no suman: guardan sus medias para cuando se
     * muestren). Siempre reescala: decidir SI hay que hacerlo es de columnas_al_cambiar_el_papel().
     *
     * @param array $opciones   columnas en la forma del pedido: [{id, pivot: {visible, width, ...}}].
     * @param int   $util_viejo
     * @param int   $util_nuevo
     * @return array
     */
    public static function reescalar_columnas($opciones, $util_viejo, $util_nuevo)
    {
        if (! is_array($opciones) || $util_viejo <= 0 || $util_nuevo <= 0) {
            return $opciones;
        }

        $exactos_visibles = [];

        foreach ($opciones as $i => $opcion) {
            if (! is_array($opcion) || ! isset($opcion['pivot']) || ! is_array($opcion['pivot']) || ! isset($opcion['pivot']['width'])) {
                continue;
            }

            $pivot = $opcion['pivot'];
            $medias = max(1, (int) round(((int) $pivot['width']) * CatalogoDeCamposPdf::GRILLA_DE_TABLA / $util_viejo));
            $exacto = $medias * $util_nuevo / CatalogoDeCamposPdf::GRILLA_DE_TABLA;

            if (self::pivot_visible($pivot)) {
                $exactos_visibles[$i] = $exacto;
            } else {
                $opciones[$i]['pivot']['width'] = max(1, (int) round($exacto));
            }
        }

        foreach (self::repartir_mm($exactos_visibles, $util_nuevo) as $i => $mm) {
            $opciones[$i]['pivot']['width'] = $mm;
        }

        return $opciones;
    }

    /**
     * Las columnas que tiene que llevar el PUT de un perfil que cambia de PAPEL: de clase (ticket ↔
     * hoja) o de ancho de rollo dentro de los tickets (Ticket 80 → Ticket 55 o uno propio).
     *
     * Regla (desfasaje D-1 del chequeo del contrato, 9/10/2026):
     * - si el pedido TRAE columnas y sus visibles entran en el útil NUEVO (con el margen del
     *   redondeo, suma_dentro_del_redondeo()), se guardan tal cual: el diseñador ya las convirtió.
     *   Antes se las volvía a escalar como si estuvieran en el útil viejo (ticket 80 → A4 dejaba
     *   "Cant" en 1 mm; A4 → 80 dejaba la tabla en 34 mm);
     * - si NO las trae (SPA viejo, un PUT parcial) o no entran en el útil nuevo, se reescalan desde
     *   el útil viejo (reescalar_columnas()): las del pedido si vienen, si no las guardadas, que se
     *   reescriben como si las mandara el formulario. Sin esto, cambiar el rollo sin mandar columnas
     *   daba 422 por la suma de anchos.
     *
     * El útil nuevo es el que queda después del pedido (ya con el papel forzado de un ticket o la
     * hoja por defecto mezclados).
     *
     * @param \Illuminate\Http\Request $request
     * @param PdfColumnProfile         $perfil tal como está guardado.
     * @return array
     */
    public static function columnas_al_cambiar_el_papel($request, PdfColumnProfile $perfil)
    {
        $util_viejo = self::ancho_util_de_columnas($perfil->printable_width_mm, $perfil->margin_mm);
        $util_nuevo = self::ancho_util_de_columnas(
            $request->has('printable_width_mm') ? $request->input('printable_width_mm') : $perfil->printable_width_mm,
            $request->has('margin_mm') ? $request->input('margin_mm') : $perfil->margin_mm
        );

        if ($request->has('pdf_column_options')) {
            $opciones = $request->input('pdf_column_options');

            if (self::suma_dentro_del_redondeo(self::anchos_visibles($opciones), $util_nuevo)) {
                return $opciones;
            }
        } else {
            $opciones = self::columnas_guardadas($perfil);
        }

        return self::reescalar_columnas($opciones, $util_viejo, $util_nuevo);
    }

    /**
     * ¿El PUT cambia el ancho del rollo de un ticket que sigue siendo ticket (Ticket 80 → Ticket 55
     * o uno propio)? Lo dice el ancho imprimible guardado (en un ticket es el del rollo) contra el
     * del tipo de hoja que queda.
     *
     * @param PdfColumnProfile           $perfil
     * @param bool                       $era_ticket
     * @param bool                       $es_ticket
     * @param \App\Models\SheetType|null $tipo_de_hoja el que queda.
     * @return bool
     */
    public static function cambia_el_rollo(PdfColumnProfile $perfil, $era_ticket, $es_ticket, $tipo_de_hoja)
    {
        return $era_ticket
            && $es_ticket
            && ! is_null($tipo_de_hoja)
            && (int) $perfil->printable_width_mm !== (int) $tipo_de_hoja->width;
    }

    /**
     * El diseño de la hoja que queda cuando un PUT cambia el papel. Devuelve ['tocar' => false] si se
     * queda lo que ya resolvió el PUT (lo que trae el pedido, o lo guardado si no lo trae), o
     * ['tocar' => true, 'page_layout' => ...] con lo que hay que guardar.
     *
     * - Ticket → hoja sin un diseño propio en el pedido: null (el PDF de siempre).
     * - Hoja → ticket sin un diseño propio: el DERIVADO del ticket, no null (ajuste del 9/10/2026 a
     *   pedido del revisor): con null el ticket imprime el Ticket 2.0 de siempre al ancho del PUESTO,
     *   así que un "Ticket 58 mm" salía en 80.
     * - Cambio de ancho del rollo: un diseño que trae el pedido (o el que ya tenía el perfil, si el
     *   pedido no menciona page_layout) se queda: las cajas van en columnas de 12 y valen en
     *   cualquier rollo. Un perfil sin diseño (o un page_layout null en el pedido) pasa al derivado,
     *   por lo mismo de arriba.
     *
     * "Diseño propio" ignora el eco del formulario genérico (trae_diseno_propio()).
     *
     * @param bool             $vino_page_layout el pedido trae la clave page_layout.
     * @param array|null       $page_layout      el del pedido, ya normalizado para la clase nueva.
     * @param PdfColumnProfile $perfil           tal como está guardado.
     * @param bool             $era_ticket
     * @param bool             $es_ticket
     * @param bool             $cambia_el_rollo
     * @param bool             $es_fiscal        el perfil queda como factura de ARCA.
     * @return array{tocar: bool, page_layout?: array|null}
     */
    public static function diseno_al_cambiar_el_papel($vino_page_layout, $page_layout, PdfColumnProfile $perfil, $era_ticket, $es_ticket, $cambia_el_rollo, $es_fiscal)
    {
        if ($era_ticket !== $es_ticket) {
            if (self::trae_diseno_propio($vino_page_layout, $page_layout, $perfil->page_layout)) {
                return ['tocar' => false];
            }

            return ['tocar' => true, 'page_layout' => $es_ticket ? self::derivado_del_ticket($es_fiscal) : null];
        }

        if ($cambia_el_rollo) {
            if ($vino_page_layout && ! is_null($page_layout)) {
                return ['tocar' => false];
            }

            if (! $vino_page_layout && DisenoDePaginaPdf::tiene_diseno($perfil)) {
                return ['tocar' => false];
            }

            return ['tocar' => true, 'page_layout' => self::derivado_del_ticket($es_fiscal)];
        }

        return ['tocar' => false];
    }

    /**
     * El diseño derivado del ticket (el equivalente al Ticket 2.0 de siempre, §5 del plan), ya
     * normalizado y con los fijos de ARCA si es una factura. Es con el que nace un ticket que crea
     * el usuario: así imprime con SU ancho de rollo y no con el del puesto. No depende del perfil ni
     * del dueño (el derivado del ticket no lee flags).
     *
     * @param bool $es_fiscal
     * @return array
     */
    public static function derivado_del_ticket($es_fiscal)
    {
        return DisenoDerivadoPdf::para(CatalogoDeCamposPdf::MODELO_DE_TICKET, null, (bool) $es_fiscal, null, true);
    }

    /**
     * Los anchos (mm) de las columnas visibles de una lista en la forma del pedido.
     *
     * @param mixed $opciones
     * @return array<int, int>
     */
    public static function anchos_visibles($opciones)
    {
        $anchos = [];

        if (! is_array($opciones)) {
            return $anchos;
        }

        foreach ($opciones as $opcion) {
            if (! is_array($opcion) || ! isset($opcion['pivot']) || ! is_array($opcion['pivot'])) {
                continue;
            }

            if (self::pivot_visible($opcion['pivot'])) {
                $anchos[] = (int) (isset($opcion['pivot']['width']) ? $opcion['pivot']['width'] : 0);
            }
        }

        return $anchos;
    }

    /**
     * ¿El pivot del pedido está visible? Sin la clave, sí (como el default de la base); "0", 0,
     * false y '' son no.
     *
     * @param array $pivot
     * @return bool
     */
    private static function pivot_visible($pivot)
    {
        if (! array_key_exists('visible', $pivot)) {
            return true;
        }

        $visible = $pivot['visible'];

        return ! ($visible === false || $visible === 0 || $visible === '0' || $visible === '' || is_null($visible));
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
