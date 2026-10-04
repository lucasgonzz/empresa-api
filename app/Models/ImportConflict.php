<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila de un Excel de importación que quedó reportada en el resultado de la
 * importación: identificador ambiguo, placeholder descartado, fila sin
 * identificador, fila sobrescrita por otra posterior con el mismo
 * identificador ("última fila gana"), valor numérico inválido o fuera de rango,
 * identificador único que no se pudo asignar, o columna de precio ignorada.
 *
 * `fila_ganadora` (prompt 03, grupo 265) solo se usa con `tipo = 'fila_sobrescrita'`:
 * `fila` es el número de la fila que PERDIÓ (la que quedó pisada), `fila_ganadora`
 * es la fila que GANÓ (la que prevalece). Para el resto de los tipos queda null.
 *
 * Tipos vigentes:
 *   ambiguo, placeholder_descartado, sin_identificador, numero_invalido,
 *   numero_fuera_de_rango, identificador_sin_asignar, fila_sobrescrita,
 *   columna_de_precio_ignorada, desempate_por_nombre_sin_resolver.
 *
 * `desempate_por_nombre_sin_resolver` (misión 9/9/2026): el usuario pidió desempatar
 * por nombre los artículos que comparten `provider_code` y para esa fila el nombre no
 * alcanzó — o no coincide con ninguno (el proveedor cambió la redacción entre listas),
 * o coincide con varios (mismo código y mismo nombre). La fila NO se saltea: se aplica
 * el comportamiento de siempre (actualizar todos los candidatos) y queda esta marca.
 * `campo` = 'provider_code', `valor` = el código, `article_ids` = los que empataron.
 *
 * ⚠️ Ese tipo es por fila, con UNA excepción: si el import no mapeó la columna de
 * nombre, el desempate no puede aplicar en ninguna fila y eso no es un problema de
 * datos sino una configuración — se registra UNA sola vez por chunk
 * (`ProcessRow::registrar_desempate_sin_resolver()`). Sin esa excepción, una
 * actualización de precios que no mapea el nombre sobre una base con códigos
 * duplicados deja cientos de conflictos idénticos que no le sirven a nadie.
 *
 * QUÉ LE PASA A LA FILA SEGÚN EL TIPO (misión importacion-mensaje-de-problemas,
 * 4/10/2026, leído de ProcessRow::procesar() y ArticleIndexCache::find_with_index()):
 *
 *   - 'ambiguo' es el ÚNICO tipo que SEGURO deja la fila afuera: no se crea ni se
 *     actualiza nada (ver TIPOS_QUE_SALTEAN_LA_FILA). No siempre es "el código coincide
 *     con más de un artículo": también sale por NOMBRE en una fila sin código que
 *     coincide con varios artículos, y cuando la fila coincide con UN solo artículo que
 *     creó esta misma importación en otro lote (incidente Servian). Lo que tienen en
 *     común es que no se pudo saber a qué artículo corresponde la fila. Una fila ambigua
 *     puede traer además conflictos anteriores al match (un numero_invalido, un
 *     placeholder_descartado, un desempate_por_nombre_sin_resolver): igual cuenta como
 *     fila que no se importó.
 *   - Con el resto de los tipos que cuentan, el DATO se descarta y la fila SIGUE:
 *     'numero_invalido' y 'numero_fuera_de_rango' (ese campo no se toca),
 *     'placeholder_descartado' (ese código se anula), 'sin_identificador' (sin códigos,
 *     se busca por nombre), 'identificador_sin_asignar' (ese código único no se asigna)
 *     y 'desempate_por_nombre_sin_resolver' (se aplica a todos los candidatos). Después
 *     la fila se crea, se actualiza o NO, según el resto de la importación: "Solo
 *     actualizar" sin match, artículo de otro proveedor, fila repetida por nombre o por
 *     id en el mismo Excel. Ese destino NO queda registrado en import_conflicts, así que
 *     de una fila con estos tipos no se puede afirmar que "se importó": tiene datos para
 *     revisar, y nada más.
 *   - INFORMATIVOS, no hay nada que corregir: 'fila_sobrescrita' (quedó la última fila
 *     con ese código) y 'columna_de_precio_ignorada' (misión 44: al actualizar se aplicó
 *     todo menos la columna de precio que el artículo no usa, porque se maneja por la
 *     otra). Ver TIPOS_QUE_NO_CUENTAN.
 *
 * `conflicts_count` (ImportHistory y ArticleImportResult) suma TODOS los tipos menos
 * los informativos: son "problemas para revisar", no "filas que no se pudieron
 * procesar". Cuenta problemas, no filas (una fila con costo y precio inválidos suma 2).
 * El mensaje del resultado, en cambio, cuenta FILAS: las que no se importaron (los
 * tipos que saltean la fila) y las que tienen datos para revisar (el resto), sin
 * afirmar qué pasó después con estas últimas:
 * ArticleImportHelper::contar_filas_con_problemas(). Ver
 * ActualizarBBDD::persistir_conflictos().
 *
 * Se persiste en bloque (insert masivo) al cerrar cada chunk de importación
 * desde ActualizarBBDD::persistir_conflictos(). Ver ProcessRow::get_conflictos().
 */
class ImportConflict extends Model
{
    /**
     * Tipos informativos: se persisten para el detalle del historial, pero la fila se
     * resolvió bien y por eso NO suman a `conflicts_count` (ver el docblock de la clase y
     * ActualizarBBDD::persistir_conflictos()). El SPA tiene el espejo de esta lista en
     * ImportHistory.vue (`tipos_que_no_cuentan`): si se agrega un tipo acá, va allá también.
     */
    public const TIPOS_QUE_NO_CUENTAN = ['fila_sobrescrita', 'columna_de_precio_ignorada'];

    /**
     * Tipos con los que ProcessRow::procesar() saltea la fila entera: no crea ni actualiza
     * nada (el `return` antes de crear o actualizar). Hoy es solo 'ambiguo'. Es lo único
     * que seguro deja la fila afuera, y el mensaje del resultado cuenta estas filas como
     * "no se importó porque no se pudo saber a qué artículo corresponde"
     * (ArticleImportHelper::contar_filas_con_problemas()).
     */
    public const TIPOS_QUE_SALTEAN_LA_FILA = ['ambiguo'];

    /* Sin restricciones de asignación masiva: se inserta vía array desde ActualizarBBDD. */
    protected $guarded = [];

    /* article_ids viaja como JSON en la columna; se expone como array PHP. */
    protected $casts = [
        'article_ids' => 'array',
    ];

    /**
     * Scope requerido por Controller::fullModel() para poder traer el modelo
     * con sus relaciones "completas". Este modelo no expone relaciones propias
     * por ahora, así que queda vacío (se completa si se agregan relaciones).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query;
    }

    /**
     * Historial de importación al que pertenece este conflicto.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function import_history()
    {
        return $this->belongsTo(ImportHistory::class, 'import_history_id');
    }
}
