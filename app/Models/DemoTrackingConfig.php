<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila unica de configuracion del canal de eventos de una demo (mision 50).
 *
 * Existe solo en las instancias de demo: la escribe DemoSetupHelper::run() con las
 * claves que manda admin-api en el payload del setup. En una instancia de cliente real
 * la tabla existe pero queda vacia, y eso es el interruptor maestro de toda la mision.
 */
class DemoTrackingConfig extends Model
{
    /**
     * Nombre explicito: la tabla es de una sola fila y se llama en singular a proposito
     * (el plural que infiere Eloquent seria demo_tracking_configs).
     *
     * @var string
     */
    protected $table = 'demo_tracking_config';

    /**
     * Sin restriccion de asignacion masiva: DemoTrackingConfigHelper es el unico punto
     * de escritura y arma el array completo a mano.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * `eventos_token` es un secreto compartido con el admin. Va en $hidden para que no
     * pueda filtrarse por serializacion si algun dia este modelo se devuelve en una
     * respuesta: el que quiera el token lo tiene que pedir por la propiedad, a mano.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'eventos_token',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'plan' => 'array',
        'media_urls' => 'array',
    ];

    /**
     * Scope requerido por Controller::fullModel(). No tiene relaciones que cargar.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query;
    }
}
