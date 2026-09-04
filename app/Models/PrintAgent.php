<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Una computadora del comercio con el agente de impresion instalado.
 *
 * @property int $id
 * @property int $user_id
 * @property int $owner_id
 * @property string|null $nombre_equipo
 * @property string|null $impresoras
 * @property \Carbon\Carbon|null $last_seen_at
 */
class PrintAgent extends Model
{
    protected $guarded = [];

    protected $dates = ['last_seen_at', 'vinculado_at', 'link_code_expira_at'];

    /**
     * Ni el token ni el codigo salen nunca en una respuesta: el token se muestra UNA sola vez, al
     * canjear el codigo, y de ahi en mas solo lo tiene el agente.
     *
     * @var array
     */
    protected $hidden = ['token_hash', 'link_code_hash'];

    /**
     * Segundos sin sondear despues de los cuales el equipo se considera desconectado.
     *
     * El agente pregunta cada 2 segundos, asi que 30 aguanta un tropiezo de red o un par de
     * reintentos sin marcar como caido un equipo que esta funcionando.
     */
    const SEGUNDOS_PARA_CONSIDERAR_EN_LINEA = 30;

    /**
     * Persona que vinculo el equipo.
     */
    function user() {
        return $this->belongsTo(User::class);
    }

    /**
     * Trabajos mandados a este equipo.
     */
    function print_jobs() {
        return $this->hasMany(PrintJob::class);
    }

    /**
     * Si el equipo esta sondeando ahora mismo.
     *
     * @return bool
     */
    function getEnLineaAttribute() {
        if (is_null($this->last_seen_at)) {
            return false;
        }

        return $this->last_seen_at->gt(Carbon::now()->subSeconds(self::SEGUNDOS_PARA_CONSIDERAR_EN_LINEA));
    }

    /**
     * Impresoras del equipo, ya decodificadas.
     *
     * @return array
     */
    function getImpresorasArrayAttribute() {
        if (empty($this->impresoras)) {
            return [];
        }

        $decoded = json_decode($this->impresoras, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Como se ve un equipo desde el SPA. No incluye ningun secreto.
     *
     * @return array
     */
    function toSpaArray() {
        return [
            'id'            => $this->id,
            'nombre_equipo' => $this->nombre_equipo,
            'impresoras'    => $this->impresoras_array,
            'en_linea'      => $this->en_linea,
            'last_seen_at'  => $this->last_seen_at ? $this->last_seen_at->toDateTimeString() : null,
            'vinculado_at'  => $this->vinculado_at ? $this->vinculado_at->toDateTimeString() : null,
        ];
    }
}
