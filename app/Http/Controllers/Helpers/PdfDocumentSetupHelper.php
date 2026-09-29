<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\Seeders\PdfColumnProfileSeederHelper;
use App\Models\PdfColumnProfile;
use App\Models\SheetType;
use App\Models\User;
use App\Services\PdfColumnService;

/**
 * Diseños de PDF por defecto de los presupuestos y de los pedidos online.
 *
 * Cada dueño tiene que tener sembrados cuatro diseños: "Pedido online" (`order`) y "Presupuesto",
 * "Presupuesto sin precios" y "Presupuesto con imágenes" (`budget`). Es la contraparte de
 * `PdfColumnArticleSetupHelper` (que hace lo mismo con el listado de artículos): el seeder de
 * producción (`PdfColumnProfileDocumentosSeeder`) itera los dueños y llama a `apply_for_owner()`.
 *
 * 🔴 IDEMPOTENCIA (no negociable): la clave de un diseño es (user_id, model_name, name), y si YA
 * EXISTE no se le toca nada, ni las columnas ni los flags. El dueño pudo haberlo personalizado, y
 * `PdfColumnProfileSeederHelper::assign_profile_options()` hace un `sync()` sobre los pivots que
 * PISA las columnas: por eso solo se llama al CREAR. Es la lección de
 * `informes/20260828-seeder-pdf-y-aviso-imagenes.md`, donde un seeder que reaplicaba columnas
 * le borró al cliente lo que había armado.
 */
class PdfDocumentSetupHelper
{
    /**
     * Definición de los cuatro diseños. Todos son A4 con las columnas sumando 200mm exactos (210 de
     * hoja menos 5mm de margen de cada lado: es lo que valida el controlador al editar).
     *
     * `columns` usa el `name` de cada opción del catálogo (`PdfColumnService::default_options()`),
     * porque así asigna `PdfColumnProfileSeederHelper`.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function default_profiles_definition()
    {
        /** Encabezado del presupuesto: el default del remito + el empleado que lo cargó (el `BudgetPdf` de antes lo imprimía). */
        $budget_header_layout = PdfColumnProfile::default_header_layout(false);
        $budget_header_layout['receptor']['izquierda'][] = 'empleado';

        return [
            [
                'model_name' => 'order',
                'name' => 'Pedido online',
                'is_default' => true,
                'show_total_in_footer' => true,
                'header_layout' => PdfColumnProfile::default_header_layout(false),
                'columns' => [
                    ['name' => 'Índice de fila', 'width' => 8],
                    ['name' => 'Código de barras', 'width' => 30],
                    ['name' => 'Nombre del artículo', 'width' => 62, 'wrap_content' => true],
                    ['name' => 'Cantidad', 'width' => 15],
                    ['name' => 'Precio unitario', 'width' => 27],
                    ['name' => 'Notas del renglón', 'width' => 28, 'wrap_content' => true],
                    ['name' => 'Subtotal línea', 'width' => 30],
                ],
            ],
            [
                'model_name' => 'budget',
                'name' => 'Presupuesto',
                'is_default' => true,
                'show_total_in_footer' => true,
                'header_layout' => $budget_header_layout,
                'columns' => [
                    ['name' => 'Índice de fila', 'width' => 8],
                    ['name' => 'Código de barras', 'width' => 30],
                    ['name' => 'Nombre del artículo', 'width' => 78, 'wrap_content' => true],
                    ['name' => 'Cantidad', 'width' => 15],
                    ['name' => 'Precio unitario', 'width' => 28],
                    ['name' => 'Bonificación porcentaje', 'width' => 14],
                    ['name' => 'Subtotal línea', 'width' => 27],
                ],
            ],
            [
                /** Sin columnas de precio y SIN caja de totales: ni el Total ni los descuentos se imprimen. */
                'model_name' => 'budget',
                'name' => 'Presupuesto sin precios',
                'is_default' => false,
                'show_total_in_footer' => false,
                'header_layout' => $budget_header_layout,
                'columns' => [
                    ['name' => 'Índice de fila', 'width' => 8],
                    ['name' => 'Código de barras', 'width' => 30],
                    ['name' => 'Nombre del artículo', 'width' => 132, 'wrap_content' => true],
                    ['name' => 'Cantidad', 'width' => 30],
                ],
            ],
            [
                'model_name' => 'budget',
                'name' => 'Presupuesto con imágenes',
                'is_default' => false,
                'show_total_in_footer' => true,
                'header_layout' => $budget_header_layout,
                'columns' => [
                    ['name' => 'Imágenes', 'width' => 40],
                    ['name' => 'Nombre del artículo', 'width' => 70, 'wrap_content' => true],
                    ['name' => 'Precio unitario', 'width' => 30],
                    ['name' => 'Cantidad', 'width' => 15],
                    ['name' => 'Bonificación porcentaje', 'width' => 15],
                    ['name' => 'Subtotal línea', 'width' => 30],
                ],
            ],
        ];
    }

    /**
     * Sincroniza el catálogo de columnas de presupuesto y pedido desde código. Sin esto,
     * `assign_profile_options()` no encuentra las opciones y deja el diseño sin ninguna columna.
     *
     * @return void
     */
    public static function sync_catalog_options()
    {
        PdfColumnService::sync_catalog_options('budget');
        PdfColumnService::sync_catalog_options('order');
    }

    /**
     * Crea, para un dueño, los diseños por defecto que le falten. Los que ya tiene no se tocan.
     *
     * @param int  $owner_id users.id con owner_id null (un empleado no tiene diseños propios).
     * @param bool $dry_run  true = solo informa qué crearía.
     * @return array<string, mixed> user_id, created (nombres), skipped (nombres que ya existían), skipped_reason.
     */
    public static function apply_for_owner($owner_id, $dry_run = false)
    {
        $result = [
            'user_id' => (int) $owner_id,
            'created' => [],
            'skipped' => [],
            'skipped_reason' => null,
        ];

        $owner = User::query()
            ->whereNull('owner_id')
            ->where('id', $owner_id)
            ->first();

        if (! $owner) {
            $result['skipped_reason'] = 'no_es_owner';
            return $result;
        }

        $a4_sheet_type = SheetType::where('name', 'A4')->first();
        $catalog_synced = false;

        foreach (self::default_profiles_definition() as $definition) {
            $exists = PdfColumnProfile::query()
                ->where('user_id', $owner_id)
                ->where('model_name', $definition['model_name'])
                ->where('name', $definition['name'])
                ->exists();

            if ($exists) {
                $result['skipped'][] = $definition['name'];
                continue;
            }

            $result['created'][] = $definition['name'];

            if ($dry_run) {
                continue;
            }

            /** El catálogo se sincroniza recién cuando hay algo para crear: una corrida que no crea nada no toca nada. */
            if (! $catalog_synced) {
                self::sync_catalog_options();
                $catalog_synced = true;
            }

            /**
             * Un solo default por dueño y modelo: si ya tiene uno (lo armó él), el nuestro nace sin
             * la marca. Dos defaults harían que `get_profile_for_print()` eligiera uno al azar.
             */
            $has_default = PdfColumnProfile::query()
                ->where('user_id', $owner_id)
                ->where('model_name', $definition['model_name'])
                ->where('is_default', true)
                ->exists();

            $profile = PdfColumnProfile::create([
                'user_id' => $owner_id,
                'model_name' => $definition['model_name'],
                'name' => $definition['name'],
                /** `columns` es json NOT NULL sin default y la app ya no lo usa: las columnas viven en el pivot. */
                'columns' => [],
                'is_default' => $definition['is_default'] && ! $has_default,
                'is_default_whatsapp' => false,
                'is_default_whatsapp_afip' => false,
                'is_default_tienda' => false,
                'paper_width_mm' => 210,
                'printable_width_mm' => 210,
                'margin_mm' => 5,
                'logo_size_mm' => null,
                'sheet_type_id' => $a4_sheet_type ? $a4_sheet_type->id : null,
                'is_afip_ticket' => false,
                'show_totals_on_each_page' => false,
                'show_comissions' => false,
                'show_total_costs' => false,
                'show_client_description' => true,
                'use_current_date' => false,
                'footer_text' => null,
                'show_total_in_footer' => $definition['show_total_in_footer'],
                'show_subtotal_in_footer' => true,
                'discount_display_mode' => 'descriptivo',
                'header_layout' => $definition['header_layout'],
            ]);

            PdfColumnProfileSeederHelper::assign_profile_options(
                $profile,
                $definition['model_name'],
                $definition['columns']
            );
        }

        return $result;
    }
}
