<?php

namespace App\Http\Controllers\Helpers\Seeders;

use App\Models\PdfColumnOption;
use App\Models\PdfColumnProfile;

class PdfColumnProfileSeederHelper
{
    /**
     * Asigna pivots/columns al perfil en base a opciones visibles y anchos opcionales.
     *
     * Formatos aceptados en $visible_options_definition:
     * - string: 'Nombre de option' (usa default_width)
     * - array: ['name' => 'Nombre de option', 'width' => 70]
     *
     * @param \App\Models\PdfColumnProfile $profile
     * @param string $model_name
     * @param array $visible_options_definition
     * @return void
     */
    public static function assign_profile_options(PdfColumnProfile $profile, string $model_name, array $visible_options_definition): void
    {
        $visible_option_settings_by_name = self::get_visible_option_settings_map($visible_options_definition);

        $options = PdfColumnOption::where('model_name', $model_name)
            ->orderBy('order')
            ->get();

        /** Cantidad de columnas visibles para ubicar el resto después en el orden del PDF. */
        $visible_columns_count = count($visible_option_settings_by_name);

        /** Orden explícito según la definición (p. ej. Imágenes primero). */
        $visible_order_by_name = [];
        $visible_position = 0;
        foreach ($visible_options_definition as $visible_option_definition) {
            $visible_name = self::resolve_visible_option_name($visible_option_definition);
            if ($visible_name === null || isset($visible_order_by_name[$visible_name])) {
                continue;
            }
            $visible_order_by_name[$visible_name] = $visible_position;
            $visible_position++;
        }

        $sync = [];
        $columns = [];
        foreach ($options as $catalog_index => $option) {
            $is_visible = array_key_exists($option->name, $visible_option_settings_by_name);
            $visible_settings = $is_visible
                ? $visible_option_settings_by_name[$option->name]
                : null;

            $option_width = (int) $option->default_width;
            if ($is_visible && $visible_settings['width'] !== null) {
                $option_width = (int) $visible_settings['width'];
            }

            $wrap_content = $is_visible && ! empty($visible_settings['wrap_content']);

            if ($is_visible && isset($visible_order_by_name[$option->name])) {
                $pivot_order = (int) $visible_order_by_name[$option->name];
            } else {
                $pivot_order = $visible_columns_count + $catalog_index;
            }

            $sync[$option->id] = [
                'visible' => $is_visible,
                'order' => $pivot_order,
                'width' => $option_width,
                'wrap_content' => $wrap_content,
            ];

            $columns[] = [
                'option_id' => $option->id,
                'name' => $option->name,
                'label' => $option->label,
                'value_resolver' => $option->value_resolver,
                'visible' => $is_visible,
                'order' => $pivot_order,
                'width' => $option_width,
                'wrap_content' => $wrap_content,
            ];
        }

        $profile->pdf_column_options()->sync($sync);
        $profile->columns = $columns;
        $profile->save();
    }

    /**
     * Arma mapa name => ajustes (width, wrap_content) para columnas visibles.
     *
     * @param array $visible_options_definition
     * @return array<string, array{width: int|null, wrap_content: bool}>
     */
    protected static function get_visible_option_settings_map(array $visible_options_definition): array
    {
        $visible_option_settings_by_name = [];
        foreach ($visible_options_definition as $visible_option_definition) {
            $visible_option_name = self::resolve_visible_option_name($visible_option_definition);
            if ($visible_option_name === null) {
                continue;
            }

            $visible_option_width = null;
            $wrap_content = false;

            if (is_array($visible_option_definition)) {
                if (isset($visible_option_definition['width'])) {
                    $visible_option_width = (int) $visible_option_definition['width'];
                }
                if (isset($visible_option_definition['wrap_content'])) {
                    $wrap_content = (bool) $visible_option_definition['wrap_content'];
                }
            }

            $visible_option_settings_by_name[$visible_option_name] = [
                'width' => $visible_option_width,
                'wrap_content' => $wrap_content,
            ];
        }

        return $visible_option_settings_by_name;
    }

    /**
     * Extrae el nombre de opción desde string o array de definición.
     *
     * @param string|array $visible_option_definition
     * @return string|null
     */
    protected static function resolve_visible_option_name($visible_option_definition)
    {
        if (is_string($visible_option_definition)) {
            return $visible_option_definition;
        }

        if (is_array($visible_option_definition) && isset($visible_option_definition['name'])) {
            return (string) $visible_option_definition['name'];
        }

        return null;
    }
}
