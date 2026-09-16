<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Acceso por link a un informe del mostrador (misión asistente-por-whatsapp, 16/9/2026).
 *
 * Lo emite MostradorAccesoHelper cuando el admin pide los informes del día para mandarlos por
 * WhatsApp: el dueño toca el link desde el teléfono y lee el informe sin loguearse.
 *
 * 🔴 El token en claro NO vive acá: la fila guarda solo `token_hash`. La ruta pública hashea lo
 * que viene en la URL y busca por ese hash; nada en la base alcanza para abrir un informe.
 */
class MostradorAcceso extends Model
{
    /** Nombre de la tabla: el plural automático de Eloquent daría `mostrador_accesos` igual, pero se fija. */
    protected $table = 'mostrador_accesos';

    protected $guarded = [];

    /**
     * @var array<string,string>
     */
    protected $casts = [
        'mostrador_reporte_id' => 'integer',
        'user_id'              => 'integer',
        'expira_at'            => 'datetime',
        'usado_at'             => 'datetime',
    ];

    /**
     * El hash con el que se guarda y se busca un token. Es sha256 en hexa: 64 caracteres, que es
     * justo el largo de la columna.
     *
     * @param  string  $token  Token en claro, tal como viaja en la URL.
     * @return string
     */
    public static function hashear($token)
    {
        return hash('sha256', (string) $token);
    }

    /**
     * true si el acceso ya venció.
     *
     * @return bool
     */
    public function vencio()
    {
        return is_null($this->expira_at) || $this->expira_at->lt(Carbon::now());
    }

    /**
     * Informe que abre este acceso.
     */
    public function reporte()
    {
        return $this->belongsTo(MostradorReporte::class, 'mostrador_reporte_id');
    }

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
