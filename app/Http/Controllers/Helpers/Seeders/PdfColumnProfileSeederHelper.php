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
        $visible_option_width_by_name = self::get_visible_option_width_map($visible_options_definition);

        $options = PdfColumnOption::where('model_name', $model_name)
            ->orderBy('order')
            ->get();

        $sync = [];
        $columns = [];
        foreach ($options as $index => $option) {
            $is_visible = array_key_exists($option->name, $visible_option_width_by_name);

            $option_width = (int) $option->default_width;
            if ($is_visible && $visible_option_width_by_name[$option->name] !== null) {
                $option_width = (int) $visible_option_width_by_name[$option->name];
            }

            $sync[$option->id] = [
                'visible' => $is_visible,
                'order' => $index,
                'width' => $option_width,
                'wrap_content' => false,
            ];

            $columns[] = [
                'option_id' => $option->id,
                'name' => $option->name,
                'label' => $option->label,
                'value_resolver' => $option->value_resolver,
                'visible' => $is_visible,
                'order' => $index,
                'width' => $option_width,
                'wrap_content' => false,
            ];
        }

        $profile->pdf_column_options()->sync($sync);
        $profile->columns = $columns;
        $profile->save();
    }

    /**
     * Arma mapa name => width opcional para marcar visibilidad.
     *
     * @param array $visible_options_definition
     * @return array<string, int|null>
     */
    protected static function get_visible_option_width_map(array $visible_options_definition): array
    {
        $visible_option_width_by_name = [];
        foreach ($visible_options_definition as $visible_option_definition) {
            if (is_string($visible_option_definition)) {
                $visible_option_width_by_name[$visible_option_definition] = null;
                continue;
            }

            if (is_array($visible_option_definition) && isset($visible_option_definition['name'])) {
                $visible_option_name = $visible_option_definition['name'];
                $visible_option_width_by_name[$visible_option_name] = isset($visible_option_definition['width'])
                    ? (int) $visible_option_definition['width']
                    : null;
            }
        }

        return $visible_option_width_by_name;
    }
}
