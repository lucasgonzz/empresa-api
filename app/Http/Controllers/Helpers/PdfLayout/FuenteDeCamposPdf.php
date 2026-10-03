<?php

namespace App\Http\Controllers\Helpers\PdfLayout;

/**
 * Contrato entre el dibujante de cajas (`MotorDeCajasPdf`) y el comprobante que se imprime con un
 * diseño de página (misión diseno-pdf-configurable, 1/10/2026).
 *
 * POR QUÉ EXISTE. El motor de cajas es el mismo para la venta, el presupuesto y el pedido online:
 * recorre las cajas del diseño y, por cada campo, le pide el VALOR a una fuente. Cada comprobante
 * guarda sus datos en lugares distintos (la venta tiene cuenta corriente y cajas, el pedido tiene
 * comprador en vez de cliente), así que el motor no lee ningún modelo: le pregunta a una
 * implementación de esta interfaz.
 *
 * El RÓTULO no lo pone la fuente: lo pone el motor según la etiqueta del diseño (la del catálogo,
 * una propia o ninguna). La fuente devuelve el valor ya formateado, con las MISMAS funciones de
 * formato que usa el PDF de siempre de ese comprobante (ver cada implementación).
 *
 * Excepción a esa regla: los campos que el catálogo define con etiqueta '' (descuentos, recargos,
 * canje de puntos, ajuste del total, puntos) devuelven el renglón ENTERO tal cual lo imprime el
 * PDF de siempre ("Menos $1.390 (10% Efectivo) = $12.510"), porque ese renglón no tiene la forma
 * "Rótulo: valor".
 *
 * Implementaciones: `CamposDeVentaPdf`, `CamposDePresupuestoPdf` y `CamposDePedidoPdf`.
 */
interface FuenteDeCamposPdf
{
    /**
     * Modelo de perfil al que pertenece el comprobante ('sale', 'budget' u 'order'): el motor lo
     * usa para leer del catálogo la etiqueta y el estilo por defecto de cada campo.
     *
     * @return string
     */
    public function model_name();

    /**
     * Valor de un campo del catálogo para este comprobante.
     *
     * - null: el campo no tiene valor y NO se imprime (ni siquiera su rótulo).
     * - string: un renglón (puede ocupar varias líneas si es largo o trae saltos de línea).
     * - array<string>: una lista (un renglón por elemento: métodos de pago, descuentos, etc.).
     *
     * 🔴 Una key que el catálogo ofrece y la fuente no sabe resolver tira
     * `\InvalidArgumentException`: es un campo agregado al catálogo sin su resolver, y el test
     * "cada campo del catálogo se resuelve" tiene que ponerse rojo. El motor nunca le pregunta por
     * una key que el catálogo no tiene (esas las saltea sin error).
     *
     * @param string $key   Key del catálogo (CatalogoDeCamposPdf).
     * @param array  $campo El campo tal como está en el diseño (key, etiqueta, estilo y, en el
     *                      texto libre, `id` y `texto`).
     * @return string|array|null
     */
    public function valor($key, $campo);
}
