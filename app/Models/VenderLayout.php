<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un "Diseño de Vender" de un negocio (misión diseno-vender-configurable, 28/9/2026): en qué
 * etapa de Vender va cada campo, en qué orden y de cuántas columnas.
 *
 * - `user_id` es siempre el DUEÑO (`$this->userId()`): el diseño es uno para todo el negocio.
 * - `layout` es el JSON normalizado por `VenderLayoutHelper::normalizar_layout()`, o `null`, que
 *   significa "el diseño del sistema" (lo arma el SPA en `diseno_predeterminado.js`). Con el cast
 *   `array` viaja al SPA como objeto, o como `null` tal cual.
 * - `en_uso`: exactamente uno por dueño. El invariante NO está en la base (no hay unique): lo
 *   sostienen `VenderLayoutController` y `VenderLayoutHelper::poner_en_uso()` al guardar.
 */
class VenderLayout extends Model
{
    /**
     * Lo único que se escribe desde el controller, el helper y el seeder.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'name',
        'layout',
        'en_uso',
    ];

    /**
     * `layout` se guarda como JSON y se lee como array (null sigue siendo null: Laravel no castea
     * los null de los casts primitivos). `en_uso` viaja como booleano, que es lo que compara el SPA
     * al elegir el diseño en uso.
     *
     * @var array
     */
    protected $casts = [
        'layout' => 'array',
        'en_uso' => 'boolean',
    ];

    /**
     * Scope que piden `fullModel()` y los index de los ABM. Vacío a propósito: el diseño no tiene
     * relaciones que cargar (regla del repo: todo modelo de ABM tiene su `withAll`).
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    function scopeWithAll($query)
    {
    }
}
