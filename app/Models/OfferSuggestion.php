<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cabecera de una corrida del motor de ofertas personalizadas por cliente
 * (ver App\Services\OfertasClientes\OfertaSugeridaService). Mismo rol que
 * PurchaseSuggestion cumple para las sugerencias de compra a proveedores,
 * adaptado al dominio de marketing.
 *
 * Estados (status):
 * - 'pendiente': la corrida está en curso (chunks sin terminar).
 * - 'terminado': todas las líneas calculadas y priorizadas; usable en la vista.
 * - 'error': la corrida falló (ver error_mensaje). Sin este estado, una corrida
 *   caída quedaba 'pendiente' para siempre.
 *
 * origen_generacion: 'manual' (la pidió el usuario desde la pantalla) o
 * 'automatica' (la creó ofertas:generar según users.ofertas_periodicidad).
 *
 * resumen_ia_estado: null (no se pidió — p.ej. sin ANTHROPIC_API_KEY),
 * 'pendiente', 'listo' o 'error' (ver resumen_ia_error). Una falla del resumen
 * NUNCA cambia el status de la sugerencia: son dos contratos independientes.
 *
 * total_clientes / total_lineas / total_clientes_excluidos_por_deuda son
 * informativos y se llenan al cerrar. El tercero cuenta a los que quedaron
 * afuera por tener ventas sin cobrar: al mal pagador no se le ofrece nada
 * (decisión de Lucas del 15/8/2026), y el número es lo que le muestra al
 * comerciante que el sistema tuvo criterio.
 *
 * El detalle columna por columna está en el docblock de la migración
 * 2026_08_17_100000_create_offer_suggestions_table.php.
 */
class OfferSuggestion extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        // withCount y no with: el listado necesita la columna "líneas" (una
        // subquery), no las miles de filas de cada corrida.
        $q->withCount('lines');
        return $q;
    }

    /**
     * Las sugerencias concretas (cliente × artículo) de esta corrida.
     */
    public function lines()
    {
        return $this->hasMany(OfferSuggestionLine::class);
    }
}
