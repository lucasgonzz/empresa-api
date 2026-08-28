<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Espejo local del horario comercial que empuja admin-api (ClientScheduleSyncService) por
 * PUT api/admin-sync/business-hours. Una fila por owner, con unique('user_id') en el motor.
 *
 * 🔴 TRES COSAS QUE NO HAY QUE "SIMPLIFICAR" DE VUELTA:
 *
 *  1. `semana` y `dias_crudos` se guardan VERBATIM, tal como llegaron. No se re-mapean campo
 *     por campo. Es lo que hace que una subclave nueva del admin (un 'feriado', un 'medio_dia')
 *     sobreviva sin migracion. Solo se ignoran las claves desconocidas de NIVEL 1 del payload.
 *
 *     ⚠️ Con una salvedad medida el 25/8/2026: la columna `json` de MySQL NORMALIZA el orden de
 *     las claves de cada objeto al guardarlo (las ordena por largo de clave). El orden de los
 *     elementos de la lista si se respeta, y ninguna respuesta cambia porque el lector indexa
 *     por `dia_semana` — pero no compares dos payloads con `assertSame` ni los hashees para
 *     detectar cambios: eso no sobrevive al round-trip.
 *
 *  2. `configurado === false` significa "NO HAY DATO", NUNCA "cerrado". Y adentro de `semana`,
 *     `abierto` tiene TRES valores: true, false y null (= sin_configurar, "no se sabe"). Nadie
 *     que lea esta fila puede colapsar null a false: seria decirle a un comprador que el
 *     comercio esta cerrado un martes a las 10.
 *
 *  3. El UNICO escritor de esta tabla es App\Http\Controllers\AdminSync\BusinessHoursController.
 *     La fuente de verdad del horario vive en el admin; acá es un espejo. No hay pantalla en
 *     empresa-spa para editarlo y no se agrega.
 */
class BusinessHoursConfig extends Model
{
    /**
     * Sin restriccion de asignacion masiva: el controller de AdminSync es el unico punto de
     * escritura y arma el array completo a mano.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * `semana` y `dias_crudos` van a 'array' para que el json vuelva como el array que llego.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'configurado' => 'boolean',
        'semana'      => 'array',
        'dias_crudos' => 'array',
        'recibido_at' => 'datetime',
    ];

    /**
     * Scope de carga estandar del repo. Esta tabla no tiene relaciones que precargar: el
     * horario entero vive en las dos columnas json de la propia fila.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $q
     * @return void
     */
    function scopeWithAll($q) {
    }

    /**
     * Owner al que pertenece este horario.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    function user() {
        return $this->belongsTo(User::class);
    }

    /**
     * Owner de la instancia: la cuenta principal de la instalacion (la que no tiene owner_id).
     *
     * ⚠️ Este criterio esta duplicado a proposito con
     * App\Http\Controllers\AdminSync\SistemaQueryController::resolve_owner(), que es el otro
     * call-site. No se centralizo porque centralizar obligaba a editar un controller vivo que
     * esta en produccion y que no es de esta mision. La duplicacion queda acotada a esta query
     * de una linea; si alguna vez cambia el criterio, hay que tocar los dos lugares.
     *
     * @return \App\Models\User|null
     */
    public static function owner_de_la_instancia(): ?User
    {
        return User::whereNull('owner_id')->orderBy('id')->first();
    }
}
