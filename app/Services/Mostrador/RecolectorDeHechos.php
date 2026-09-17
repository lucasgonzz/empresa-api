<?php

namespace App\Services\Mostrador;

use App\Models\MostradorReporte;
use App\Models\User;
use Carbon\Carbon;

/**
 * Fachada de los recolectores de hechos del mostrador (misiones modulo-ia-mostrador y
 * mostrador-caja-vencimientos): dado un dueño, un tipo y una fecha, devuelve el JSON
 * determinista que después redacta la skill /mostrador. Un recolector por tipo
 * (RecolectorDia, RecolectorCaja, RecolectorTienda, RecolectorCompras, RecolectorStock), todos
 * con la misma firma y las mismas reglas (ver RecolectorBase).
 *
 * La fecha por defecto de cada tipo: 'dia' y 'tienda' hablan de AYER; 'caja', 'compras' y
 * 'stock' hablan de HOY (la plata y los vencimientos se miran con el saldo de esta mañana; la
 * reposición y los traslados, con el stock de esta mañana).
 */
class RecolectorDeHechos
{
    /**
     * Los tipos que hablan SIEMPRE de hoy: la caja y los vencimientos se miran con el saldo de
     * esta mañana, y la reposición y los traslados con el stock de esta mañana. No tiene sentido
     * pedirlos para otra fecha (el saldo y el stock de ayer ya no existen): POST hechos ignora la
     * fecha del body para estos tres.
     */
    const TIPOS_DE_HOY = ['caja', 'compras', 'stock'];

    /**
     * true si el tipo habla siempre de hoy (caja, compras, stock); false si habla de un día
     * cerrado (dia, tienda).
     *
     * @param string $tipo
     * @return bool
     */
    public static function es_de_hoy(string $tipo): bool
    {
        return in_array($tipo, self::TIPOS_DE_HOY, true);
    }

    /**
     * Fecha por defecto del tipo: ayer para dia/tienda, hoy para caja/compras/stock.
     *
     * @param string $tipo
     * @param Carbon|null $hoy Para los tests; null = ahora
     * @return Carbon
     */
    public static function fecha_por_defecto(string $tipo, $hoy = null): Carbon
    {
        $hoy = is_null($hoy) ? now() : $hoy->copy();

        if (self::es_de_hoy($tipo)) {
            return $hoy->startOfDay();
        }

        return $hoy->subDay()->startOfDay();
    }

    /**
     * Calcula los hechos del tipo pedido. Lanza InvalidArgumentException si el tipo no es uno
     * de MostradorReporte::TIPOS.
     *
     * @param User $owner Dueño de la cuenta (owner_id null)
     * @param string $tipo 'dia' | 'caja' | 'tienda' | 'compras' | 'stock'
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
            case 'caja':
                return new RecolectorCaja();
            case 'tienda':
                return new RecolectorTienda();
            case 'compras':
                return new RecolectorCompras();
            case 'stock':
                return new RecolectorStock();
            default:
                // 🔴 Un tipo válido sin su case NO puede caer en el recolector de otro. Hasta el
                // 15/9/2026 el default devolvía RecolectorStock: el día que se sumara un tipo a
                // MostradorReporte::TIPOS sin sumarlo acá, ese informe se iba a calcular como
                // stock sin que nada lo avisara (clase de error "switch con default que absorbe
                // un valor nuevo"). Mejor que reviente nombrando el tipo que falta.
                throw new \InvalidArgumentException('El tipo de informe "' . $tipo . '" no tiene recolector.');
        }
    }
}
