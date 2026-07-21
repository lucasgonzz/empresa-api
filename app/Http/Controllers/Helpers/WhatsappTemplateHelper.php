<?php

namespace App\Http\Controllers\Helpers;

use App\Models\WhatsappTemplate;
use Illuminate\Http\Request;

/**
 * Lógica de negocio de las plantillas de WhatsApp (grupo 137, Prompt 04): armado de
 * datos al crear/actualizar, bloqueo de las plantillas `is_system` (estándar de
 * ComercioCity, no editables ni borrables desde el ERP) y validación del envío por
 * `WhatsappChatController::send_template`. Los controllers solo orquestan; toda la
 * lógica vive acá.
 */
class WhatsappTemplateHelper
{
    /**
     * Arma el array de columnas para crear una plantilla nueva a partir del request.
     * Las plantillas creadas por el usuario siempre nacen `borrador` y `is_system` = false
     * (las `is_system` solo las crea `WhatsappTemplateStandardHelper`).
     *
     * @param  Request  $request
     * @param  int  $user_id  Owner dueño de la plantilla.
     * @return array<string, mixed>
     */
    public static function build_create_data(Request $request, $user_id)
    {
        $meta_template_name = trim((string) $request->meta_template_name);
        if ($meta_template_name === '') {
            // Si no la mandó el front, se arma un slug técnico a partir del nombre visible.
            $meta_template_name = self::slugify_meta_template_name($request->name);
        }

        return [
            'user_id' => $user_id,
            'name' => $request->name,
            'meta_template_name' => $meta_template_name,
            'category' => $request->category ?: 'utility',
            'language' => $request->language ?: 'es_AR',
            'body_preview' => $request->body_preview,
            'variables' => $request->variables ?: [],
            'status' => 'borrador',
            'is_system' => false,
        ];
    }

    /**
     * Arma el array de columnas editables al actualizar una plantilla (no toca
     * `is_system` ni `status`: el estado avanza solo por `mark_pendiente_meta` o por un
     * update explícito del equipo de ComercioCity, ej. para pasarla a `aprobada`).
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public static function build_update_data(Request $request)
    {
        $data = [
            'name' => $request->name,
            'category' => $request->category,
            'language' => $request->language,
            'body_preview' => $request->body_preview,
            'variables' => $request->variables ?: [],
        ];

        // `status` solo se pisa si vino explícito (ej: el equipo de ComercioCity la marca `aprobada`/`rechazada`).
        if (! is_null($request->status)) {
            $data['status'] = $request->status;
        }

        return $data;
    }

    /**
     * Indica si la plantilla puede editarse/borrarse desde el ERP. Las `is_system`
     * (estándar de ComercioCity) están bloqueadas.
     *
     * @param  WhatsappTemplate  $template
     * @return bool
     */
    public static function is_editable($template)
    {
        return ! $template->is_system;
    }

    /**
     * Valida que una plantilla pueda usarse para `send_template`: debe estar `aprobada`
     * y la cantidad de variables recibidas debe coincidir con las que declara la
     * plantilla. Devuelve el mensaje de error (para responder 422) o null si es válida.
     *
     * @param  WhatsappTemplate  $template
     * @param  array  $variables
     * @return string|null
     */
    public static function validate_for_send(WhatsappTemplate $template, array $variables)
    {
        if ($template->status !== 'aprobada') {
            return 'La plantilla todavía no está aprobada en Meta.';
        }

        $expected_count = is_array($template->variables) ? count($template->variables) : 0;
        if (count($variables) !== $expected_count) {
            return 'La cantidad de variables no coincide con la plantilla (se esperaban '.$expected_count.').';
        }

        return null;
    }

    /**
     * Genera un nombre técnico razonable a partir del nombre visible, para cuando el
     * usuario no cargó `meta_template_name` a mano (igual deberá coordinarlo con
     * ComercioCity antes de darla de alta en Meta).
     *
     * @param  string  $name
     * @return string
     */
    private static function slugify_meta_template_name($name)
    {
        // Sin acentos ni caracteres especiales, todo en minúsculas y separado por guiones bajos.
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', (string) $name) ?: (string) $name;
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $ascii), '_'));

        return $slug !== '' ? $slug : 'plantilla_'.time();
    }
}
