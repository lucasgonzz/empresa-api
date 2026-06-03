<?php

namespace App\Http\Controllers\Helpers;

use App\Models\PdfColumnProfile;
use Illuminate\Support\Collection;

/**
 * Asigna perfiles PDF predeterminados para enlaces de WhatsApp (remito / factura ARCA).
 */
class PdfColumnProfileWhatsappDefaultHelper
{
    /**
     * Nombres preferidos para remito con precios (venta en negro / comprobante habitual por WhatsApp).
     *
     * @var array<int, string>
     */
    protected static $remito_name_priority = [
        'Remito con precios',
        'Remito',
    ];

    /**
     * Nombres preferidos para factura ARCA.
     *
     * @var array<int, string>
     */
    protected static $factura_name_priority = [
        'Factura comun',
        'Factura común',
        'Factura',
    ];

    /**
     * Opciones de columna que indican perfil remito con precios visibles.
     *
     * @var array<int, string>
     */
    protected static $remito_price_option_names = [
        'Precio unitario',
        'Subtotal línea',
        'Precio final',
        'Precio sin IVA',
    ];

    /**
     * Fragmentos de nombre que excluyen perfiles no aptos para WhatsApp remito.
     *
     * @var array<int, string>
     */
    protected static $remito_excluded_name_fragments = [
        'sin precio',
        'costos',
        'comision',
    ];

    /**
     * Resultado por owner al aplicar defaults de WhatsApp.
     *
     * @param int $user_id Owner (dueño).
     * @param bool $dry_run Si true no persiste cambios.
     * @return array<string, mixed>
     */
    public static function apply_whatsapp_defaults_for_owner($user_id, $dry_run = false)
    {
        $result = [
            'user_id' => (int) $user_id,
            'remito_profile_id' => null,
            'remito_profile_name' => null,
            'factura_profile_id' => null,
            'factura_profile_name' => null,
            'remito_applied' => false,
            'factura_applied' => false,
            'skipped_remito_reason' => null,
            'skipped_factura_reason' => null,
        ];

        $remito_profile = self::resolve_remito_whatsapp_profile($user_id);
        $factura_profile = self::resolve_factura_whatsapp_profile($user_id);

        if ($remito_profile) {
            $result['remito_profile_id'] = $remito_profile->id;
            $result['remito_profile_name'] = $remito_profile->name;
            if (! $dry_run) {
                self::set_remito_whatsapp_default($user_id, $remito_profile->id);
            }
            $result['remito_applied'] = true;
        } else {
            $result['skipped_remito_reason'] = 'sin_perfil_remito_con_precios';
        }

        if ($factura_profile) {
            $result['factura_profile_id'] = $factura_profile->id;
            $result['factura_profile_name'] = $factura_profile->name;
            if (! $dry_run) {
                self::set_factura_whatsapp_default($user_id, $factura_profile->id);
            }
            $result['factura_applied'] = true;
        } else {
            $result['skipped_factura_reason'] = 'sin_perfil_factura_arca';
        }

        return $result;
    }

    /**
     * Resuelve perfil remito/venta en negro con precios para WhatsApp.
     *
     * @param int $user_id
     * @return \App\Models\PdfColumnProfile|null
     */
    public static function resolve_remito_whatsapp_profile($user_id)
    {
        $profiles = self::get_sale_profiles_for_user($user_id, false);

        if ($profiles->isEmpty()) {
            return null;
        }

        $candidates = $profiles->filter(function (PdfColumnProfile $profile) {
            return self::is_remito_candidate_for_whatsapp($profile);
        });

        if ($candidates->isEmpty()) {
            return null;
        }

        return self::pick_by_name_priority($candidates, self::$remito_name_priority)
            ?: self::pick_first_with_visible_price_column($candidates)
            ?: $candidates->first(function (PdfColumnProfile $profile) {
                return (bool) $profile->is_default;
            })
            ?: $candidates->first();
    }

    /**
     * Resuelve perfil factura ARCA para WhatsApp.
     *
     * @param int $user_id
     * @return \App\Models\PdfColumnProfile|null
     */
    public static function resolve_factura_whatsapp_profile($user_id)
    {
        $profiles = self::get_sale_profiles_for_user($user_id, true);

        if ($profiles->isEmpty()) {
            return null;
        }

        return self::pick_by_name_priority($profiles, self::$factura_name_priority)
            ?: $profiles->first(function (PdfColumnProfile $profile) {
                return (bool) $profile->is_default;
            })
            ?: $profiles->first();
    }

    /**
     * Perfiles sale del usuario filtrados por tipo fiscal.
     *
     * @param int $user_id
     * @param bool $is_afip_ticket
     * @return \Illuminate\Support\Collection
     */
    protected static function get_sale_profiles_for_user($user_id, $is_afip_ticket)
    {
        return PdfColumnProfile::where('user_id', $user_id)
            ->where('model_name', 'sale')
            ->where('is_afip_ticket', $is_afip_ticket ? true : false)
            ->with(['pdf_column_options'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Indica si el perfil remito es candidato (no sin precios / costos y con columnas de precio visibles).
     *
     * @param \App\Models\PdfColumnProfile $profile
     * @return bool
     */
    protected static function is_remito_candidate_for_whatsapp(PdfColumnProfile $profile)
    {
        $name_lower = mb_strtolower((string) $profile->name, 'UTF-8');

        foreach (self::$remito_excluded_name_fragments as $fragment) {
            if (strpos($name_lower, $fragment) !== false) {
                return false;
            }
        }

        return self::profile_has_visible_price_column($profile);
    }

    /**
     * Verifica pivots visibles con nombres de precio conocidos.
     *
     * @param \App\Models\PdfColumnProfile $profile
     * @return bool
     */
    protected static function profile_has_visible_price_column(PdfColumnProfile $profile)
    {
        foreach ($profile->pdf_column_options as $option) {
            $pivot_visible = $option->pivot ? (bool) $option->pivot->visible : false;
            if (! $pivot_visible) {
                continue;
            }
            if (in_array($option->name, self::$remito_price_option_names, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Elige perfil cuyo nombre coincide exactamente o contiene alguna prioridad.
     *
     * @param \Illuminate\Support\Collection $profiles
     * @param array<int, string> $name_priority
     * @return \App\Models\PdfColumnProfile|null
     */
    protected static function pick_by_name_priority(Collection $profiles, array $name_priority)
    {
        foreach ($name_priority as $preferred_name) {
            $preferred_lower = mb_strtolower($preferred_name, 'UTF-8');
            $exact = $profiles->first(function (PdfColumnProfile $profile) use ($preferred_lower) {
                return mb_strtolower((string) $profile->name, 'UTF-8') === $preferred_lower;
            });
            if ($exact) {
                return $exact;
            }
        }

        foreach ($name_priority as $preferred_name) {
            $preferred_lower = mb_strtolower($preferred_name, 'UTF-8');
            $partial = $profiles->first(function (PdfColumnProfile $profile) use ($preferred_lower) {
                return strpos(mb_strtolower((string) $profile->name, 'UTF-8'), $preferred_lower) !== false;
            });
            if ($partial) {
                return $partial;
            }
        }

        return null;
    }

    /**
     * Primer candidato con columna de precio visible (por si el nombre no coincide).
     *
     * @param \Illuminate\Support\Collection $profiles
     * @return \App\Models\PdfColumnProfile|null
     */
    protected static function pick_first_with_visible_price_column(Collection $profiles)
    {
        return $profiles->first(function (PdfColumnProfile $profile) {
            return self::profile_has_visible_price_column($profile);
        });
    }

    /**
     * Marca un solo perfil remito como is_default_whatsapp para el owner.
     *
     * @param int $user_id
     * @param int $profile_id
     * @return void
     */
    protected static function set_remito_whatsapp_default($user_id, $profile_id)
    {
        PdfColumnProfile::where('user_id', $user_id)
            ->where('model_name', 'sale')
            ->where('is_afip_ticket', false)
            ->update(['is_default_whatsapp' => false]);

        PdfColumnProfile::where('user_id', $user_id)
            ->where('id', $profile_id)
            ->update(['is_default_whatsapp' => true]);
    }

    /**
     * Marca un solo perfil factura como is_default_whatsapp_afip para el owner.
     *
     * @param int $user_id
     * @param int $profile_id
     * @return void
     */
    protected static function set_factura_whatsapp_default($user_id, $profile_id)
    {
        PdfColumnProfile::where('user_id', $user_id)
            ->where('model_name', 'sale')
            ->where('is_afip_ticket', true)
            ->update(['is_default_whatsapp_afip' => false]);

        PdfColumnProfile::where('user_id', $user_id)
            ->where('id', $profile_id)
            ->update(['is_default_whatsapp_afip' => true]);
    }
}
