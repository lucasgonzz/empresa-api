<?php

namespace App\Services\Mostrador;

use App\Models\MostradorReporte;
use App\Models\User;
use Carbon\Carbon;

/**
 * Fachada de los recolectores de hechos del mostrador (misión modulo-ia-mostrador):
 * dado un dueño, un tipo y una fecha, devuelve el JSON determinista que después
 * redacta la skill /mostrador. Un recolector por tipo (RecolectorDia,
 * RecolectorTienda, RecolectorCompras, RecolectorStock), todos con la misma firma
 * y las mismas reglas (ver RecolectorBase).
 *
 * La fecha por defecto de cada tipo: 'dia' y 'tienda' hablan de AYER; 'compras' y
 * 'stock' hablan de HOY (la reposición y los traslados se deciden con el stock de
 * esta mañana).
 */
class RecolectorDeHechos
{
    /**
     * Fecha por defecto del tipo: ayer para dia/tienda, hoy para compras/stock.
     *
     * @param string $tipo
     * @param Carbon|null $hoy Para los tests; null = ahora
     * @return Carbon
     */
    public static function fecha_por_defecto(string $tipo, $hoy = null): Carbon
    {
        $hoy = is_null($hoy) ? now() : $hoy->copy();

        if ($tipo === 'dia' || $tipo === 'tienda') {
            return $hoy->subDay()->startOfDay();
        }

        return $hoy->startOfDay();
    }

    /**
     * Calcula los hechos del tipo pedido. Lanza InvalidArgumentException si el tipo
     * no es uno de los cuatro.
     *
     * @param User $owner Dueño de la cuenta (owner_id null)
     * @param string $tipo 'dia' | 'tienda' | 'compras' | 'stock'
     * @param Carbon $fecha Día del que habla el informe
     * @return array
     */
    public function recolectar(User $owner, string $tipo, Carbon $fecha): array
    {
        return $this->recolector_para($tipo)->recolectar($owner, $fecha->copy()->startOfDay());
    }

    /**
     * El recolector del tipo.
     *
     * @param string $tipo
     * @return RecolectorBase
     */
    public function recolector_para(string $tipo): RecolectorBase
    {
        if (!MostradorReporte::es_tipo_valido($tipo)) {
            throw new \InvalidArgumentException('Tipo de informe desconocido: ' . $tipo);
        }

        switch ($tipo) {
            case 'dia':
                return new RecolectorDia();
            case 'tienda':
                return new RecolectorTienda();
            case 'compras':
                return new RecolectorCompras();
            default:
                return new RecolectorStock();
        }
    }
}
