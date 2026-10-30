<?php

namespace Tests\Feature\DimensionSearch\Dobles;

use App\Models\DimensionSearchItem;
use App\Models\DimensionSearchRun;
use App\Models\User;
use App\Services\DimensionSearch\ArticleDimensionSearchEngine;

/**
 * El tramo de prueba (TramoDePrueba) con un motor que falla a pedido y una base que "vuelve" o no, para probar
 * el corte de la conexión sin tocar la conexión real (reconectarla adentro de la transacción del test la
 * rompería, y nadie quiere dormir 150 segundos en una suite).
 *
 * - `$fallas`: excepciones que el motor lanza, una por llamada y en orden, ANTES de procesar el artículo; cuando
 *   se acaban, el motor trabaja de verdad (el real).
 * - `$falla_siempre`: una excepción que el motor lanza en todas las llamadas.
 * - `$base_vuelve`: lo que contesta esperar_a_la_base() (true = la base volvió).
 * - `$esperas`: cuántas veces el tramo esperó a la base.
 * - `$intentos_vistos`: el valor de `intentos` del artículo en cada llamada al motor.
 */
class TramoConMotorQueFalla extends TramoDePrueba
{
    /** @var \Throwable[] Fallas del motor, en orden, una por llamada. */
    public $fallas = [];

    /** @var \Throwable|null Falla que el motor lanza en todas las llamadas. */
    public $falla_siempre = null;

    /** @var bool Lo que contesta esperar_a_la_base(). */
    public $base_vuelve = true;

    /** @var int Veces que el tramo esperó a la base. */
    public $esperas = 0;

    /** @var int[] Los `intentos` del artículo cada vez que el motor lo recibió. */
    public $intentos_vistos = [];

    /**
     * Sin dormir y sin reconectar: solo cuenta la espera y contesta lo que el test decidió.
     *
     * @return bool
     */
    protected function esperar_a_la_base()
    {
        $this->esperas++;

        return $this->base_vuelve;
    }

    /**
     * El motor real, pero que falla antes de procesar cuando el test lo pide.
     *
     * @param  \App\Models\DimensionSearchRun $run
     * @param  \App\Models\User $owner
     * @return \App\Services\DimensionSearch\ArticleDimensionSearchEngine
     */
    protected function crear_motor(DimensionSearchRun $run, User $owner)
    {
        return new class($run, $owner, $this) extends ArticleDimensionSearchEngine {
            /** @var \Tests\Feature\DimensionSearch\Dobles\TramoConMotorQueFalla */
            protected $doble_del_tramo;

            public function __construct(DimensionSearchRun $run, User $owner, $doble_del_tramo)
            {
                parent::__construct($run, $owner);

                $this->doble_del_tramo = $doble_del_tramo;
            }

            public function procesar(DimensionSearchItem $item)
            {
                $this->doble_del_tramo->intentos_vistos[] = (int) $item->intentos;

                if (!is_null($this->doble_del_tramo->falla_siempre)) {
                    throw $this->doble_del_tramo->falla_siempre;
                }

                if (!empty($this->doble_del_tramo->fallas)) {
                    throw array_shift($this->doble_del_tramo->fallas);
                }

                return parent::procesar($item);
            }
        };
    }
}
