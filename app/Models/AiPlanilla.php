<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una planilla de ventas que el dueño le mandó al asistente, ya leída y guardada (misión
 * agente-venta-por-planilla, 02/11/2026).
 *
 * 🔴 ES LA FUENTE DE VERDAD DE LAS VENTAS QUE SALEN DE UNA PLANILLA. El modelo que conversa no la lee ni
 * la reescribe: la consulta y la corrige por herramientas cerradas (HerramientasDeCarga), y las ventas
 * se arman desde acá por código (PlanillaIaHelper, EjecucionDePlanillaIaHelper). Qué es cada columna y
 * por qué existe la tabla está en su migración.
 */
class AiPlanilla extends Model
{
    protected $table = 'ai_planillas';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'ai_conversation_id' => 'integer',
        'user_id'            => 'integer',
        'ai_message_id'      => 'integer',
        'columnas'           => 'array',
        'roles'              => 'array',
        'filas'              => 'array',
        'definiciones'       => 'array',
        'lectura'            => 'array',
    ];

    const ORIGEN_FOTO = 'foto';
    const ORIGEN_TEXTO = 'texto';
    const ORIGEN_ARCHIVO = 'archivo';

    /** Se puede seguir cruzando con fotos nuevas y armar ventas. */
    const ESTADO_ACTIVA = 'activa';

    /** No le queda ningún renglón por cargar. */
    const ESTADO_CERRADA = 'cerrada';

    /** Quedó vencida o la dejó de lado otra planilla. */
    const ESTADO_REEMPLAZADA = 'reemplazada';

    /** El importe de un renglón es el total de esa venta. */
    const IMPORTE_TOTAL = 'total';

    /** El importe de un renglón es el precio de una unidad. */
    const IMPORTE_UNIDAD = 'unidad';

    /** De dónde sale el `importe_es` de un renglón: del título de la columna, que una frase genérica no pisa. */
    const IMPORTE_DE_TITULO = 'titulo';

    /** De lo que dijo el dueño. */
    const IMPORTE_DE_DUENO = 'dueno';

    /** Todavía no se cargó. */
    const FILA_PENDIENTE = 'pendiente';

    /** La venta ya existía antes de esta planilla (o se detectó al reintentar): no se vuelve a cargar. */
    const FILA_YA_REGISTRADA = 'ya_registrada';

    /** La cargó esta planilla: `venta_numero` dice cuál es. */
    const FILA_REGISTRADA = 'registrada';

    /** La persona pidió que no se cargue. */
    const FILA_DESCARTADA = 'descartada';

    /** Falló al ejecutar: `motivo` dice por qué, y la próxima tarjeta la vuelve a intentar. */
    const FILA_BLOQUEADA = 'bloqueada';

    /**
     * Scope requerido por Controller::fullModel(). No tiene relaciones que cargar.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query;
    }
}
