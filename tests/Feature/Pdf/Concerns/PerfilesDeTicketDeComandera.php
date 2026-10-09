<?php

namespace Tests\Feature\Pdf\Concerns;

use App\Http\Controllers\Helpers\SheetTypeHelper;
use App\Models\PdfColumnOption;
use App\Models\PdfColumnProfile;
use App\Models\SheetType;
use App\Services\PdfColumnService;

/**
 * Armadores de los tests del ticket de comandera diseñado (misión diseno-ticket-comandera,
 * 9/10/2026): los tipos de hoja (rollos del sistema, A4, un rollo propio de otro dueño) y perfiles
 * de ticket y de hoja con columnas conocidas.
 *
 * Todo se crea adentro de la transacción del test; los dos rollos del sistema se aseguran con el
 * mismo helper que usa producción (SheetTypeHelper::asegurar_tickets_del_sistema()).
 */
trait PerfilesDeTicketDeComandera
{
    /**
     * El rollo del sistema de ese ancho (55 u 80).
     *
     * @param int $ancho_mm
     * @return \App\Models\SheetType
     */
    protected function rollo_del_sistema($ancho_mm = 80)
    {
        $rollos = SheetTypeHelper::asegurar_tickets_del_sistema();

        return $rollos[$ancho_mm];
    }

    /**
     * La A4 del sistema (con alto: es hoja). La crea si la base de testing no la tiene.
     *
     * @return \App\Models\SheetType
     */
    protected function hoja_a4()
    {
        return SheetType::firstOrCreate(
            ['name' => 'A4', 'user_id' => null],
            ['width' => 297, 'height' => 210]
        );
    }

    /**
     * Un rollo propio de un dueño (de ancho raro, para no chocar con los del sistema).
     *
     * @param int $owner_id
     * @param int $ancho_mm
     * @return \App\Models\SheetType
     */
    protected function rollo_propio($owner_id, $ancho_mm = 72)
    {
        return SheetType::create([
            'name' => 'Ticket '.$ancho_mm.' mm',
            'width' => $ancho_mm,
            'height' => null,
            'user_id' => $owner_id,
        ]);
    }

    /**
     * Perfil de TICKET de comandera (venta) con las columnas Nombre (con salto de línea), Cant,
     * Precio y Sub total en 10/2/6/6 medias columnas sobre el ancho del rollo (las de los perfiles
     * por defecto), salvo que se pasen otras.
     *
     * @param int        $owner_id
     * @param bool       $es_factura
     * @param array      $atributos
     * @param int        $ancho_mm
     * @param array|null $columnas  [value_resolver => [ancho_mm, wrap]] en orden; null = las de siempre.
     * @return \App\Models\PdfColumnProfile
     */
    protected function perfil_de_ticket($owner_id, $es_factura = false, array $atributos = [], $ancho_mm = 80, $columnas = null)
    {
        $rollo = $this->rollo_del_sistema($ancho_mm);

        $perfil = PdfColumnProfile::create(array_merge([
            'user_id' => $owner_id,
            'model_name' => 'sale',
            'name' => ($es_factura ? 'zz Ticket factura ' : 'zz Ticket remito ').uniqid(),
            'paper_width_mm' => $ancho_mm,
            'printable_width_mm' => $ancho_mm,
            'margin_mm' => 0,
            'sheet_type_id' => $rollo->id,
            'columns' => [],
            'is_afip_ticket' => $es_factura,
            'is_default' => false,
            'page_layout' => null,
        ], $atributos));

        if (is_null($columnas)) {
            $columnas = [
                'item_name' => [(int) round(10 * $ancho_mm / 24), true],
                'item_amount' => [(int) round(2 * $ancho_mm / 24), false],
                'item_price' => [(int) round(6 * $ancho_mm / 24), false],
                'item_subtotal' => [(int) round(6 * $ancho_mm / 24), false],
            ];
        }

        $this->adjuntar_columnas($perfil, $columnas);

        return $perfil->fresh();
    }

    /**
     * Perfil de HOJA (A4) de venta con columnas.
     *
     * @param int   $owner_id
     * @param bool  $es_factura
     * @param array $atributos
     * @return \App\Models\PdfColumnProfile
     */
    protected function perfil_de_hoja($owner_id, $es_factura = false, array $atributos = [])
    {
        $perfil = PdfColumnProfile::create(array_merge([
            'user_id' => $owner_id,
            'model_name' => 'sale',
            'name' => ($es_factura ? 'zz Factura A4 ' : 'zz Remito A4 ').uniqid(),
            'paper_width_mm' => 210,
            'printable_width_mm' => 210,
            'margin_mm' => 5,
            'sheet_type_id' => $this->hoja_a4()->id,
            'columns' => [],
            'is_afip_ticket' => $es_factura,
            'is_default' => false,
        ], $atributos));

        $this->adjuntar_columnas($perfil, [
            'item_name' => [100, true],
            'item_amount' => [20, false],
            'item_price' => [40, false],
            'item_subtotal' => [40, false],
        ]);

        return $perfil->fresh();
    }

    /**
     * Le pega columnas visibles a un perfil, en el orden dado.
     *
     * @param \App\Models\PdfColumnProfile $perfil
     * @param array                        $columnas [value_resolver => [ancho_mm, wrap]]
     * @return void
     */
    protected function adjuntar_columnas(PdfColumnProfile $perfil, array $columnas)
    {
        PdfColumnService::sync_catalog_options('sale');

        $orden = 0;
        foreach ($columnas as $resolver => $definicion) {
            $opcion = PdfColumnOption::where('model_name', 'sale')->where('value_resolver', $resolver)->first();
            $this->assertNotNull($opcion, 'Falta la opción de columna de venta '.$resolver.' en la base de testing.');

            $perfil->pdf_column_options()->attach($opcion->id, [
                'visible' => true,
                'order' => $orden++,
                'width' => $definicion[0],
                'wrap_content' => $definicion[1],
            ]);
        }
    }
}
