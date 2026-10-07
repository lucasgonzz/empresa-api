<?php

namespace App\Http\Controllers\Helpers\Devoluciones;

/**
 * Una nota de crédito ya guardada no se puede facturar ante ARCA (ya está facturada, no tiene venta
 * facturada de origen, la factura elegida no corresponde, el total no cierra, etc.).
 *
 * Se lanza desde FacturarNotaCreditoExistenteHelper para que el controlador la distinga de un fallo
 * de ARCA o de código: responde 422 con el motivo, en vez del 500 genérico.
 */
class NotaCreditoNoFacturableException extends \Exception {

}
