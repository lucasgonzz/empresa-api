<?php

namespace Tests\Feature\ImagenesInteligentes\Dobles;

use App\Jobs\ProcessImageAssignmentRunJob;

/**
 * El job por tramos de producción con dos perillas para los tests:
 *
 * - `$presupuesto`: los segundos del tramo sin el piso de 20 de producción (plan §13, B13). Con 0,
 *   el tramo hace UN artículo y se re-encola, que es como se prueban los cortes entre tramos sin
 *   esperar de verdad.
 * - failed() no deshace la transacción del test: DatabaseTransactions corre todo el test adentro de
 *   una (nivel 1), y esa no es del tramo (plan §13, B8).
 *
 * La continuación que despacha (`self::dispatch`) es el job de producción, no este.
 */
class TramoDePrueba extends ProcessImageAssignmentRunJob
{
    /** @var int|null Segundos del tramo; null = los de producción. */
    public $presupuesto = null;

    /**
     * @return int
     */
    protected function segundos_por_tramo()
    {
        return is_null($this->presupuesto) ? parent::segundos_por_tramo() : (int) $this->presupuesto;
    }

    /**
     * @return int
     */
    protected function niveles_ajenos_de_transaccion()
    {
        return 1;
    }
}
