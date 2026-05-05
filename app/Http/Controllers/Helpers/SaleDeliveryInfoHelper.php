<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Client;
use App\Models\Sale;
use App\Models\SaleDeliveryInfo;

/**
 * Resuelve los textos de la etiqueta de envío combinando overrides (SaleDeliveryInfo) con datos del Client.
 */
class SaleDeliveryInfoHelper
{
    /**
     * Devuelve los valores finales para las celdas del PDF de etiqueta de envío.
     *
     * Prioridad: campo no vacío en sale_delivery_info; si no hay override, se usa el cliente y su ubicación.
     *
     * @param Sale $sale Venta con relaciones cargadas: client (opcional), client.location.provincia, sale_delivery_info.
     * @return array<string, string> Claves: first_name, last_name, phone, document, locality, province, postal_code, email.
     */
    public static function resolved_for_etiqueta_pdf(Sale $sale)
    {
        $info = $sale->sale_delivery_info;
        $client = $sale->client;

        list($default_first, $default_last) = self::split_client_name($client);

        $default_phone = '';
        $default_document = '';
        $default_locality = '';
        $default_province = '';
        $default_postal = '';
        $default_email = '';

        if (!is_null($client)) {
            $default_phone = (string) ($client->phone ?? '');
            $default_document = (string) (($client->dni ?? null) ?? ($client->cuit ?? null) ?? '');
            $default_email = (string) ($client->email ?? '');

            $loc = $client->location;
            if (!is_null($loc)) {
                $default_locality = (string) ($loc->name ?? '');
                $default_postal = (string) ($loc->codigo_postal ?? '');
                if (!is_null($loc->provincia)) {
                    $default_province = (string) ($loc->provincia->name ?? '');
                }
            }
        }

        return [
            'first_name' => self::overlay_string($info, 'first_name', $default_first),
            'last_name' => self::overlay_string($info, 'last_name', $default_last),
            'phone' => self::overlay_string($info, 'phone', $default_phone),
            'document' => self::overlay_document($info, $default_document),
            'locality' => self::overlay_string($info, 'locality', $default_locality),
            'province' => self::overlay_string($info, 'province', $default_province),
            'postal_code' => self::overlay_string($info, 'postal_code', $default_postal),
            'email' => self::overlay_string($info, 'email', $default_email),
        ];
    }

    /**
     * Separa nombre del cliente en nombre y apellido (primer token / resto), como uso típico en etiquetas.
     *
     * @param Client|null $client Cliente de la venta.
     * @return array{0: string, 1: string} Tupla [first_name, last_name].
     */
    protected static function split_client_name($client)
    {
        if (is_null($client) || $client->name === null) {
            return ['', ''];
        }

        $trimmed = trim(preg_replace('/\s+/u', ' ', (string) $client->name));
        if ($trimmed === '') {
            return ['', ''];
        }

        $pos = strpos($trimmed, ' ');
        if ($pos === false) {
            return [$trimmed, ''];
        }

        return [
            substr($trimmed, 0, $pos),
            trim(substr($trimmed, $pos + 1)),
        ];
    }

    /**
     * Usa el valor persistido en SaleDeliveryInfo si viene con texto no vacío; si no, el fallback.
     *
     * @param SaleDeliveryInfo|null $info Registro de overrides.
     * @param string $attribute Nombre del atributo en el modelo.
     * @param string $fallback Valor por defecto (desde cliente).
     * @return string Texto final para el PDF.
     */
    protected static function overlay_string($info, $attribute, $fallback)
    {
        if (is_null($info)) {
            return $fallback;
        }

        $raw = $info->{$attribute};
        if ($raw === null) {
            return $fallback;
        }

        $trimmed = trim((string) $raw);

        return $trimmed !== '' ? $trimmed : $fallback;
    }

    /**
     * Documento para la línea "DNI:" del PDF: prioriza DNI sobre CUIT en overrides; si no hay override de documento, usa el fallback del cliente.
     *
     * @param SaleDeliveryInfo|null $info Overrides de envío.
     * @param string $fallback_document Valor por defecto (dni o cuit del cliente ya resuelto).
     * @return string Texto a mostrar tras el prefijo "DNI: ".
     */
    protected static function overlay_document($info, $fallback_document)
    {
        if (is_null($info)) {
            return $fallback_document;
        }

        $dni = $info->dni;
        $cuit = $info->cuit;

        $dni_trimmed = $dni === null ? '' : trim((string) $dni);
        if ($dni_trimmed !== '') {
            return $dni_trimmed;
        }

        $cuit_trimmed = $cuit === null ? '' : trim((string) $cuit);
        if ($cuit_trimmed !== '') {
            return $cuit_trimmed;
        }

        return $fallback_document;
    }
}
