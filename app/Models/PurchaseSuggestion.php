<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cabecera de una corrida del motor de sugerencia de compra a proveedores:
 * qué comprar, cuánto y a quién (ver App\Services\PurchaseSuggestion\
 * PurchaseSuggestionService). Mismo rol que StockSuggestion cumple para los
 * traslados entre sucursales, adaptado al dominio de compras.
 *
 * Estados (status):
 * - 'pendiente': la corrida está en curso (chunks sin terminar).
 * - 'terminado': todas las líneas calculadas y priorizadas; usable en la vista.
 * - 'error': la corrida falló (ver error_mensaje). Sin este estado, una corrida
 *   caída quedaba 'pendiente' para siempre.
 *
 * origen_generacion:
 * - 'manual': la pidió el usuario desde la pantalla.
 * - 'automatica': la creó el comando compras:generar según la periodicidad
 *   configurada en users.sugerencias_compras_periodicidad.
 *
 * resumen_ia_estado: null (no se pidió — p.ej. sin ANTHROPIC_API_KEY),
 * 'pendiente', 'listo' o 'error' (ver resumen_ia_error). Una falla del
 * resumen nunca cambia el status de la sugerencia: son dos contratos
 * independientes.
 *
 * total_estimado / contexto_financiero: informativos (deuda con cada
 * proveedor + disponible en cajas), calculados una sola vez al cerrar la
 * corrida. Nunca alimentan dias_punto_pedido/dias_cobertura_objetivo/
 * dias_lead_time/dias_vigencia_oferta ni ninguna cantidad_sugerida: la plata
 * es contexto, no recorte.
 */
class PurchaseSuggestion extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        // withCount y no with: el listado necesita la columna "líneas" (una
        // subquery), no las miles de filas de cada corrida.
        $q->withCount('articles');
        return $q;
    }

    public function articles()
    {
        return $this->hasMany(PurchaseSuggestionArticle::class);
    }

    /**
     * Órdenes de compra generadas desde esta sugerencia (botón "Generar
     * orden de compra"). La retención de automáticas (compras:generar) no
     * borra ninguna sugerencia que tenga órdenes: es la evidencia de por qué
     * existe cada una.
     */
    public function provider_orders()
    {
        return $this->hasMany(ProviderOrder::class);
    }
}
