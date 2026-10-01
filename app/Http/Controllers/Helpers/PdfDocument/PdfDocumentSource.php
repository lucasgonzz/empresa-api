<?php

namespace App\Http\Controllers\Helpers\PdfDocument;

/**
 * Contrato entre un comprobante (presupuesto, pedido online) y `ProfileDocumentPdf`, la clase que
 * lo dibuja con un diseño de PDF (`PdfColumnProfile`).
 *
 * POR QUÉ EXISTE. `NewSalePdf` (remito y factura) está atado a `Sale`: lee de ella los renglones,
 * los totales, las observaciones y hasta el encabezado. Un presupuesto y un pedido online tienen
 * la plata en lugares distintos (ver los dos adaptadores), así que el dibujante no puede leer
 * directamente ninguno de los dos: le pide todo a una implementación de esta interfaz.
 *
 * La plata vive entera del lado de la implementación, que es una clase común y corriente (sin
 * FPDF): se puede probar sin `Output(); exit;`, que es lo que mata el proceso de PHPUnit.
 *
 * Implementaciones: `BudgetPdfDocument` y `OrderPdfDocument`.
 */
interface PdfDocumentSource
{
    /**
     * Modelo de los perfiles de PDF que le corresponden: 'budget' o 'order'.
     *
     * @return string
     */
    public function model_name();

    /**
     * Id del dueño del comprobante (`users.id`). De ahí salen el perfil, el logo y los datos del
     * emisor: NUNCA del usuario logueado, porque las rutas de PDF son públicas por id.
     *
     * @return int
     */
    public function owner_id();

    /**
     * Título del comprobante, que el encabezado imprime a la derecha ('Presupuesto', 'Pedido online').
     *
     * @return string
     */
    public function title();

    /**
     * Objeto que `AfipPdfHelper::header_comercial()` sabe leer como si fuera una venta: solo lee
     * `created_at`, `address` (relación con sucursal o null), `num`, `client`, `seller` y `employee`.
     *
     * @return object
     */
    public function header_document();

    /**
     * Renglones del comprobante, en el orden en que se imprimen. Cada uno trae su `pivot`.
     *
     * @return array
     */
    public function items();

    /**
     * Precarga las relaciones que usan las columnas del diseño, para no salir a la base renglón
     * por renglón. Solo se pide lo que el diseño va a usar.
     *
     * @param bool $with_images    El diseño tiene la columna de imagen.
     * @param bool $with_relations El diseño tiene marca, categoría, subcategoría o proveedor.
     * @return void
     */
    public function preload_item_relations($with_images, $with_relations);

    /**
     * Nombre con el que se imprime el renglón (cada comprobante arma el suyo).
     *
     * @param object $item Renglón de items().
     * @return string
     */
    public function item_name($item);

    /**
     * Importe del renglón (precio por cantidad, con lo que le aplique cada comprobante).
     *
     * @param object $item Renglón de items().
     * @return float
     */
    public function item_subtotal($item);

    /**
     * Observaciones o notas del comprobante, o null si no tiene.
     *
     * @return string|null
     */
    public function observations();

    /**
     * Título del recuadro de observaciones ('OBSERVACIONES', 'NOTAS DEL PEDIDO').
     *
     * @return string
     */
    public function observations_title();

    /**
     * Renglones de la caja de totales, en orden. Cada uno es ['text' => string, 'bold' => bool].
     * Con `show_total_in_footer` apagado devuelve un array vacío: no se imprime ningún importe.
     *
     * @param array $flags ['show_total_in_footer' => bool, 'show_subtotal_in_footer' => bool].
     * @return array
     */
    public function totals_rows($flags);
}
