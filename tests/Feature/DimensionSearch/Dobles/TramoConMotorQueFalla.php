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
 * - `$esperas`: cuántas veces el tramo esperó a la base, y `$maximos_pedidos` el máximo de segundos que se le
 *   permitió esperar cada vez.
 * - `$reloj` y `$segundos_por_espera`: un reloj simulado (el tramo usa ahora_en_segundos()). Cada espera lo
 *   adelanta `min(máximo permitido, $segundos_por_espera)` segundos, así el presupuesto de espera se prueba sin dormir.
 * - `$corte_al_reclamar` ('antes' | 'despues' | null): la próxima vez que el tramo reclame un artículo, la
 *   conexión se corta antes o después de aplicar el UPDATE.
 * - `$fallas_al_devolver`: excepciones que devolver_o_descartar() lanza antes de andar bien, una por llamada.
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

    /** @var int[] El máximo de segundos que se le permitió esperar a la base en cada espera. */
    public $maximos_pedidos = [];

    /** @var float Reloj simulado del tramo, en segundos. */
    public $reloj = 0.0;

    /** @var float Segundos (simulados) que tarda cada espera, hasta el máximo que se le permitió. */
    public $segundos_por_espera = 0.0;

    /** @var string|null 'antes' | 'despues' | null: ver el docblock de la clase. */
    public $corte_al_reclamar = null;

    /** @var \Throwable[] Fallas que devolver_o_descartar() lanza antes de andar bien, una por llamada. */
    public $fallas_al_devolver = [];

    /**
     * Sin dormir y sin reconectar: cuenta la espera, adelanta el reloj simulado y contesta lo que el test decidió.
     *
     * @param  int $maximo
     * @return bool
     */
    protected function esperar_a_la_base($maximo = 150)
    {
        $this->esperas++;
        $this->maximos_pedidos[] = (int) $maximo;
        $this->reloj += min((float) $maximo, $this->segundos_por_espera);

        return $this->base_vuelve;
    }

    /**
     * El reloj simulado.
     *
     * @return float
     */
    protected function ahora_en_segundos()
    {
        return $this->reloj;
    }

    /**
     * Adelanta el reloj simulado.
     *
     * @param  float $segundos
     * @return void
     */
    public function avanzar_reloj($segundos)
    {
        $this->reloj += $segundos;
    }

    /**
     * Arranca el presupuesto de espera (lo que hace handle() al empezar).
     *
     * @return void
     */
    public function iniciar_presupuesto_publico()
    {
        $this->iniciar_presupuesto_de_espera();
    }

    /**
     * Los segundos que se pueden esperar ahora.
     *
     * @return int
     */
    public function permitidos_publico()
    {
        return $this->segundos_de_espera_permitidos();
    }

    /**
     * El reclamo real, con la conexión cortada antes o después de aplicar el UPDATE cuando el test lo pide.
     *
     * @param  \App\Models\DimensionSearchItem $item
     * @return bool
     */
    protected function reclamar(DimensionSearchItem $item)
    {
        if (is_null($this->corte_al_reclamar)) {
            return parent::reclamar($item);
        }

        $cuando                  = $this->corte_al_reclamar;
        $this->corte_al_reclamar = null;

        $corte = new \Illuminate\Database\QueryException('update `dimension_search_items`', [], new \PDOException('SQLSTATE[HY000] [2002] Connection refused', 2002));

        if ($cuando === 'antes') {
            throw $corte;
        }

        // El UPDATE se aplicó, pero la respuesta no llegó.
        parent::reclamar($item);

        throw $corte;
    }

    /**
     * devolver_o_descartar() real, que antes se topa con la base cortada las veces que el test pidió.
     *
     * @param  \App\Models\DimensionSearchItem|null $item
     * @param  string $mensaje
     * @return void
     */
    protected function devolver_o_descartar($item, $mensaje)
    {
        if (!empty($this->fallas_al_devolver)) {
            throw array_shift($this->fallas_al_devolver);
        }

        parent::devolver_o_descartar($item, $mensaje);
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
