<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Histórico de precios ofertados por proveedor: "en esta fecha, este
 * proveedor ofrecía este artículo a este costo". Cada fila es un hecho de
 * precio fechado, no un estado actual (el estado actual sigue viviendo en
 * `article_provider`).
 *
 * Único punto de escritura y lectura masiva: App\Services\Compras\
 * OfertasDeProveedorService. No escribir filas sueltas acá afuera: se
 * rompería la regla de deduplicación temporal (una fila por combinación
 * artículo/proveedor/fecha/origen, ver la migración de esta tabla).
 */
class ProviderPriceOffer extends Model
{
    protected $guarded = [];

    /** El proveedor mandó una lista de precios en Excel */
    const ORIGEN_IMPORTACION = 'importacion';

    /** Se le compró de verdad a ese precio (el dato más confiable) */
    const ORIGEN_COMPRA = 'compra';

    /** Carga a mano. Hoy sin UI: existe para no tener que cambiar el esquema el día que la haya */
    const ORIGEN_MANUAL = 'manual';

    /** Fila rescatada de un duplicado del pivot article_provider por la migración de dedupe */
    const ORIGEN_PIVOT_DEDUPE = 'pivot_dedupe';

    function scopeWithAll($q)
    {
        return $q;
    }

    /**
     * Acota a ofertas vigentes desde hace `$dias_vigencia` días hasta hoy,
     * ordenadas de la más reciente a la más vieja (mayor fecha primero,
     * desempate por id DESC): encadenado a un filtro por article_id (y
     * opcionalmente provider_id), ->first() da la oferta vigente de ese par.
     *
     * Misma idea de lectura que CreditAccountSnapshot::scopeSaldoAlDia: la
     * regla de "cuál es la fila vigente" vive acá, no en cada consumidor.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $q
     * @param  int  $dias_vigencia
     * @return \Illuminate\Database\Eloquent\Builder
     */
    function scopeVigentes($q, $dias_vigencia)
    {
        return $q->where('fecha', '>=', now()->subDays($dias_vigencia)->toDateString())
                  ->orderBy('fecha', 'desc')
                  ->orderBy('id', 'desc');
    }

    function article()
    {
        return $this->belongsTo(Article::class);
    }

    function provider()
    {
        return $this->belongsTo(Provider::class);
    }
}
