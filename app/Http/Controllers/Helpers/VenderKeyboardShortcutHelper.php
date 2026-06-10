<?php

namespace App\Http\Controllers\Helpers;

/**
 * Valores por defecto y normalización de atajos F1-F10 del módulo Vender.
 */
class VenderKeyboardShortcutHelper
{
    /**
     * Acciones soportadas y su tecla por defecto (alineado con VenderTopbar).
     *
     * @var array<string, string>
     */
    public const DEFAULT_SHORTCUTS = [
        'barcode' => 'F1',
        'search_article' => 'F2',
        'payment_method' => 'F3',
        'client' => 'F4',
        'save' => 'F5',
        'print' => 'F6',
    ];

    /**
     * Opciones por defecto del atajo Imprimir (Ticket 2.0 para ambos escenarios).
     *
     * @var array<string, mixed>
     */
    public const DEFAULT_PRINT_OPTIONS = [
        'use_ticket_2_for_both' => true,
        'remito' => 'ticket_2',
        'facturado' => 'ticket_2',
    ];

    /**
     * Claves de impresión permitidas (sin perfiles dinámicos).
     *
     * @var array<int, string>
     */
    public const ALLOWED_PRINT_OPTION_KEYS = [
        'ticket_2',
        'ticket_pdf',
        'factura_ticket_pdf',
    ];

    /**
     * Teclas permitidas en la configuración de atajos.
     *
     * @var array<int, string>
     */
    public const ALLOWED_KEYS = [
        'F1', 'F2', 'F3', 'F4', 'F5', 'F6', 'F7', 'F8', 'F9', 'F10',
    ];

    /**
     * Devuelve el mapa por defecto action => tecla.
     *
     * @return array<string, string>
     */
    public static function default_shortcuts(): array
    {
        return self::DEFAULT_SHORTCUTS;
    }

    /**
     * Fusiona shortcuts guardados con defaults y descarta claves inválidas.
     *
     * @param array<string, mixed>|null $shortcuts
     * @return array<string, string>
     */
    public static function normalize_shortcuts($shortcuts): array
    {
        $normalized = self::default_shortcuts();

        if (!is_array($shortcuts)) {
            return $normalized;
        }

        foreach (array_keys($normalized) as $action) {
            if (!isset($shortcuts[$action])) {
                continue;
            }

            $key = strtoupper((string) $shortcuts[$action]);

            if (in_array($key, self::ALLOWED_KEYS, true)) {
                $normalized[$action] = $key;
            }
        }

        return $normalized;
    }

    /**
     * Fusiona print_options guardadas con defaults y valida claves conocidas.
     *
     * @param array<string, mixed>|null $print_options
     * @return array<string, mixed>
     */
    public static function normalize_print_options($print_options): array
    {
        $normalized = self::DEFAULT_PRINT_OPTIONS;

        if (!is_array($print_options)) {
            return $normalized;
        }

        if (isset($print_options['use_ticket_2_for_both'])) {
            $normalized['use_ticket_2_for_both'] = (bool) $print_options['use_ticket_2_for_both'];
        }

        if (isset($print_options['remito'])) {
            $normalized['remito'] = self::sanitize_print_option_key((string) $print_options['remito']);
        }

        if (isset($print_options['facturado'])) {
            $normalized['facturado'] = self::sanitize_print_option_key((string) $print_options['facturado']);
        }

        if ($normalized['use_ticket_2_for_both']) {
            $normalized['remito'] = 'ticket_2';
            $normalized['facturado'] = 'ticket_2';
        }

        return $normalized;
    }

    /**
     * Valida una clave de impresión fija o con prefijo de perfil A4.
     *
     * @param string $option_key
     * @return string
     */
    protected static function sanitize_print_option_key(string $option_key): string
    {
        if (in_array($option_key, self::ALLOWED_PRINT_OPTION_KEYS, true)) {
            return $option_key;
        }

        if (preg_match('/^remito_a4:\d+$/', $option_key)) {
            return $option_key;
        }

        if (preg_match('/^factura_a4:\d+$/', $option_key)) {
            return $option_key;
        }

        return 'ticket_2';
    }

    /**
     * Devuelve print_options por defecto.
     *
     * @return array<string, mixed>
     */
    public static function default_print_options(): array
    {
        return self::DEFAULT_PRINT_OPTIONS;
    }

    /**
     * Indica si el mapa tiene teclas duplicadas entre acciones.
     *
     * @param array<string, string> $shortcuts
     * @return bool
     */
    public static function has_duplicate_keys(array $shortcuts): bool
    {
        $keys = array_values($shortcuts);

        return count($keys) !== count(array_unique($keys));
    }
}
