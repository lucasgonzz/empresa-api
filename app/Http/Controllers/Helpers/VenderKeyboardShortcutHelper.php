<?php

namespace App\Http\Controllers\Helpers;

use App\Models\PdfColumnProfile;

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
     * @param int|null                  $owner_id dueño del comercio: con él se valida que un
     *                                            'ticket:{id}' sea un ticket de comandera suyo y de
     *                                            la clase de la lista (misión diseno-ticket-comandera).
     *                                            Sin él (llamadas viejas), la clave se acepta por su forma.
     * @return array<string, mixed>
     */
    public static function normalize_print_options($print_options, $owner_id = null): array
    {
        $normalized = self::DEFAULT_PRINT_OPTIONS;

        if (!is_array($print_options)) {
            return $normalized;
        }

        if (isset($print_options['use_ticket_2_for_both'])) {
            $normalized['use_ticket_2_for_both'] = (bool) $print_options['use_ticket_2_for_both'];
        }

        if (isset($print_options['remito'])) {
            $normalized['remito'] = self::sanitize_print_option_key((string) $print_options['remito'], false, $owner_id);
        }

        if (isset($print_options['facturado'])) {
            $normalized['facturado'] = self::sanitize_print_option_key((string) $print_options['facturado'], true, $owner_id);
        }

        if ($normalized['use_ticket_2_for_both']) {
            $normalized['remito'] = 'ticket_2';
            $normalized['facturado'] = 'ticket_2';
        }

        return $normalized;
    }

    /**
     * Valida una clave de impresión fija, con prefijo de perfil A4 o de ticket de comandera.
     *
     * @param string    $option_key
     * @param bool|null $es_facturado true = lista "facturado" (un ticket tiene que ser de factura),
     *                                false = lista "remito" (un ticket tiene que ser de remito),
     *                                null = sin lista (no se mira la clase del ticket).
     * @param int|null  $owner_id     null = un 'ticket:{id}' se acepta solo por su forma.
     * @return string
     */
    protected static function sanitize_print_option_key(string $option_key, $es_facturado = null, $owner_id = null): string
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

        /**
         * Un diseño de ticket de comandera puntual (misión diseno-ticket-comandera, 9/10/2026, plan
         * §7.4): el atajo imprime ese perfil por el Ticket 2.0. Sin esto, el PUT del atajo lo
         * convertía en silencio en 'ticket_2' y la elección del usuario no quedaba guardada.
         *
         * Con el dueño se valida contra la base: tiene que ser un ticket de comandera SUYO, de venta
         * y de la clase de la lista (remito → no fiscal, facturado → fiscal). Si no (de otro dueño,
         * un diseño de hoja, uno borrado, la clase cruzada), cae a 'ticket_2', igual que cualquier
         * clave que no se reconoce.
         */
        if (preg_match('/^ticket:(\d+)$/', $option_key, $partes)) {
            if (is_null($owner_id) || self::es_ticket_del_dueno((int) $partes[1], $owner_id, $es_facturado)) {
                return 'ticket:'.(int) $partes[1];
            }
        }

        return 'ticket_2';
    }

    /**
     * ¿Ese perfil es un ticket de comandera del dueño, de venta y de la clase pedida?
     *
     * @param int       $profile_id
     * @param int       $owner_id
     * @param bool|null $es_facturado null = cualquier clase.
     * @return bool
     */
    protected static function es_ticket_del_dueno($profile_id, $owner_id, $es_facturado)
    {
        if ($profile_id <= 0) {
            return false;
        }

        $query = PdfColumnProfile::where('id', $profile_id)
            ->where('user_id', (int) $owner_id)
            ->where('model_name', 'sale')
            ->deTicket();

        if (!is_null($es_facturado)) {
            $query->where('is_afip_ticket', (bool) $es_facturado);
        }

        return $query->exists();
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
