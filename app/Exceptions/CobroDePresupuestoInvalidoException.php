<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * El cobro guardado en un presupuesto "de contado" ya no se puede aplicar a la venta que nace al
 * confirmarlo (misión presupuesto-contado-o-cuenta-corriente, 1/10/2026).
 *
 * 🔴 POR QUÉ ES UNA EXCEPCIÓN Y NO UN 422 DEVUELTO. La re-validación corre ADENTRO de
 * `BudgetHelper::saveSale()`, que la llaman tres caminos distintos (`confirmar()`, `store()` con el
 * presupuesto naciendo confirmado y `update()` cuando el estado cambia) y que no tiene cómo
 * devolver una respuesta HTTP. La excepción sube hasta el `catch` del controlador, que hace
 * `DB::rollBack()` y contesta 422 con el cuerpo que trae. Sin rollback, el presupuesto quedaría
 * confirmado sin venta (el `save()` del estado ya corrió).
 *
 * Cuándo pasa, aunque se haya validado al guardar: el presupuesto se pudo guardar hace días y
 * desde entonces la caja se cerró, el método de pago se borró, o el presupuesto se editó por otro
 * camino (el form genérico, que no sabe de cobros) y el reparto quedó con un total viejo.
 *
 * Lleva el CUERPO del 422 completo (`message`, `cobro_invalido`, y la marca que corresponda:
 * `sin_metodo_de_pago` o `caja_cerrada`), el mismo que devuelve la validación al guardar: la SPA
 * lee los mismos campos en los dos casos.
 *
 * Extiende `RuntimeException`: cualquier `catch (\Throwable)` que la atrape antes de que llegue
 * al handler específico la trata como un fallo común (500 con el mensaje), que es el piso seguro.
 *
 * PHP 7.4: sin promoción de propiedades en el constructor.
 */
class CobroDePresupuestoInvalidoException extends RuntimeException
{
    /**
     * El cuerpo del 422.
     *
     * @var array
     */
    protected $cuerpo;

    /**
     * @param  array  $cuerpo  `['message' => ..., 'cobro_invalido' => true, ...marcas]`.
     */
    public function __construct(array $cuerpo)
    {
        $this->cuerpo = $cuerpo;

        parent::__construct(isset($cuerpo['message']) ? (string) $cuerpo['message'] : 'El cobro del presupuesto no es valido.');
    }

    /**
     * El cuerpo JSON de la respuesta 422.
     *
     * @return array
     */
    public function getCuerpo()
    {
        return $this->cuerpo;
    }
}
