<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plantilla de WhatsApp de una empresa (grupo 137, Prompt 04): el único camino permitido
 * por Meta para iniciar/retomar una conversación una vez cerrada la ventana de 24 h
 * (`WhatsappChat::is_within_service_window()` en false). `is_system` marca las 5
 * plantillas estándar `cc_cli_*` que precarga ComercioCity (no editables ni borrables
 * por el usuario); las que arma cada empresa a mano nacen `borrador` y pasan a
 * `pendiente_meta`/`aprobada` a medida que el equipo de ComercioCity las carga en Meta.
 */
class WhatsappTemplate extends Model
{
    protected $guarded = [];

    protected $casts = [
        // Array de labels de las variables en el orden en que aparecen en el body ({{1}}, {{2}}...).
        'variables' => 'array',
        'is_system' => 'boolean',
    ];

    /**
     * Relaciones a precargar cuando el controller pide el modelo completo vía
     * `Controller::fullModel()`. Sin este scope, `fullModel()` rompe.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query)
    {
        $query->with(['user']);
    }

    /**
     * Empresa (dueño) a la que pertenece la plantilla.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Reemplaza los placeholders `{{1}}`, `{{2}}`... del `body_preview` por los valores
     * recibidos, en el mismo orden. Se usa para guardar el texto final del mensaje
     * enviado (`WhatsappChatMessage::body`) cuando se manda la plantilla.
     *
     * @param  array  $variable_values  Valores en el mismo orden que `variables`.
     * @return string
     */
    public function render_body(array $variable_values)
    {
        $rendered = (string) $this->body_preview;

        foreach ($variable_values as $index => $value) {
            // Los placeholders de Meta son 1-based ({{1}}, {{2}}...).
            $placeholder = '{{'.($index + 1).'}}';
            $rendered = str_replace($placeholder, (string) $value, $rendered);
        }

        return $rendered;
    }
}
