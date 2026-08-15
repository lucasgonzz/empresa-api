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
 *
 * 🔴 Arreglo post-chequeo (A16): este modelo tenía un scopeVigentes() cuyo
 * comentario decía "la regla de cuál es la fila vigente vive acá, no en cada
 * consumidor" -- y no era cierto: ni registrar_lote() ni
 * mejores_ofertas_para() (los dos en OfertasDeProveedorService, arriba) lo
 * llamaban -- las dos hacen su propio DB::table() con su propio ORDER BY
 * fecha DESC/id DESC -- y tampoco lo llamaba
 * Helpers\ConsultasSistemaIaHelper::precios_de_proveedores() (tercera
 * reimplementación de la misma idea, para la tool del chat de IA). Un scope
 * sin un solo caller, prometiendo una centralización que en los hechos está
 * reimplementada a mano en TRES lugares, es peor que no tener scope: alguien
 * lo iba a encontrar y confiar en el comentario. Se saca acá. Unificar los
 * tres lugares de verdad no entra en esta pasada: el tercero
 * (ConsultasSistemaIaHelper) no está en la lista cerrada de archivos de este
 * arreglo puntual, y tocarlo igual sería ampliar el alcance sin pedirlo.
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

    function article()
    {
        return $this->belongsTo(Article::class);
    }

    function provider()
    {
        return $this->belongsTo(Provider::class);
    }
}
