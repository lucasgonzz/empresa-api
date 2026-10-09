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
     * `address` (misión etiqueta-envio-direccion, 9/10/2026): la calle y número cargada en
     * sale_delivery_info si no está vacía; si no, el domicilio del cliente, que es la COLUMNA DE
     * TEXTO `clients.address` (ojo: `Client::address()` es la relación con la sucursal, no el
     * domicilio). En los dos casos con los espacios y saltos de línea colapsados a uno: la etiqueta
     * la imprime en renglones de una sola línea.
     *
     * `document_label`: el rótulo del documento resuelto en `document` — 'DNI', 'CUIT', o
     * 'DNI/CUIT' si no hay ninguno (el renglón se imprime igual, para completarlo a mano).
     *
     * @param Sale $sale Venta con relaciones cargadas: client (opcional), client.location.provincia, sale_delivery_info.
     * @return array<string, string> Claves: first_name, last_name, phone, document, locality, province, postal_code, email, address, document_label.
     */
    public static function resolved_for_etiqueta_pdf(Sale $sale)
    {
        $info = $sale->sale_delivery_info;
        $client = $sale->client;

        list($default_first, $default_last) = self::split_client_name($client);

        $default_phone = '';
        $default_locality = '';
        $default_province = '';
        $default_postal = '';
        $default_email = '';

        if (!is_null($client)) {
            $default_phone = (string) ($client->phone ?? '');
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

        /** Documento del cliente (DNI, si no CUIT) y su rótulo; lo pisa el override si hay. */
        list($default_document, $default_document_label) = self::client_document($client);
        list($document, $document_label) = self::overlay_document($info, $default_document, $default_document_label);

        return [
            'first_name' => self::overlay_string($info, 'first_name', $default_first),
            'last_name' => self::overlay_string($info, 'last_name', $default_last),
            'phone' => self::overlay_string($info, 'phone', $default_phone),
            'document' => $document,
            'locality' => self::overlay_string($info, 'locality', $default_locality),
            'province' => self::overlay_string($info, 'province', $default_province),
            'postal_code' => self::overlay_string($info, 'postal_code', $default_postal),
            'email' => self::overlay_string($info, 'email', $default_email),
            'address' => self::overlay_address($info, self::client_address($client)),
            'document_label' => $document_label !== '' ? $document_label : 'DNI/CUIT',
        ];
    }

    /**
     * Domicilio del cliente para la etiqueta: la columna de texto `clients.address`.
     *
     * Se lee con getAttribute() y solo si es un string: `Client::address()` es una RELACIÓN (la
     * sucursal, `address_id` -> `addresses`) y, si el atributo de texto no vino en el select,
     * Eloquent devolvería la relación en su lugar. Una sucursal no es el domicilio del cliente.
     *
     * @param Client|null $client Cliente de la venta.
     * @return string Domicilio con los espacios colapsados, o '' si no tiene.
     */
    protected static function client_address($client)
    {
        if (is_null($client)) {
            return '';
        }

        $raw = $client->getAttribute('address');
        if (!is_string($raw)) {
            return '';
        }

        return self::collapse_whitespace($raw);
    }

    /**
     * Dirección final: la de sale_delivery_info si tiene texto; si no, la del cliente.
     *
     * @param SaleDeliveryInfo|null $info Overrides de envío.
     * @param string $fallback Domicilio del cliente ya colapsado.
     * @return string Dirección para el renglón "Dirección:" de la etiqueta.
     */
    protected static function overlay_address($info, $fallback)
    {
        if (is_null($info)) {
            return $fallback;
        }

        $raw = $info->address;
        if (!is_string($raw)) {
            return $fallback;
        }

        $collapsed = self::collapse_whitespace($raw);

        return $collapsed !== '' ? $collapsed : $fallback;
    }

    /**
     * Colapsa espacios, tabulaciones y saltos de línea seguidos a un solo espacio, y recorta.
     *
     * @param string $value Texto crudo.
     * @return string Texto en una sola línea.
     */
    protected static function collapse_whitespace($value)
    {
        $collapsed = preg_replace('/\s+/u', ' ', $value);
        if (is_null($collapsed)) {
            /** UTF-8 inválido: preg_replace con /u devuelve null; se colapsa sin /u. */
            $collapsed = preg_replace('/\s+/', ' ', $value);
        }

        return trim((string) $collapsed);
    }

    /**
     * Documento del cliente: el DNI si tiene texto, si no el CUIT. Un DNI '' o de solo espacios
     * cuenta como vacío y cae al CUIT (antes, `dni ?? cuit` dejaba pasar el '').
     *
     * @param Client|null $client Cliente de la venta.
     * @return array{0: string, 1: string} Tupla [documento, rótulo ('DNI', 'CUIT' o '' si no hay)].
     */
    protected static function client_document($client)
    {
        if (is_null($client)) {
            return ['', ''];
        }

        $dni = self::trimmed_or_empty($client->dni);
        if ($dni !== '') {
            return [$dni, 'DNI'];
        }

        $cuit = self::trimmed_or_empty($client->cuit);
        if ($cuit !== '') {
            return [$cuit, 'CUIT'];
        }

        return ['', ''];
    }

    /**
     * Texto recortado, o '' si es null (o no es un escalar).
     *
     * @param mixed $value Valor crudo del modelo.
     * @return string
     */
    protected static function trimmed_or_empty($value)
    {
        if (is_null($value) || is_array($value) || is_object($value)) {
            return '';
        }

        return trim((string) $value);
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
     * Documento del renglón de la etiqueta y su rótulo: prioriza DNI sobre CUIT en overrides; si no
     * hay override de documento, usa el del cliente (con su rótulo).
     *
     * @param SaleDeliveryInfo|null $info Overrides de envío.
     * @param string $fallback_document Documento del cliente ya resuelto (DNI o CUIT).
     * @param string $fallback_label Rótulo del documento del cliente ('DNI', 'CUIT' o '').
     * @return array{0: string, 1: string} Tupla [documento, rótulo ('DNI', 'CUIT' o '' si no hay)].
     */
    protected static function overlay_document($info, $fallback_document, $fallback_label)
    {
        if (is_null($info)) {
            return [$fallback_document, $fallback_label];
        }

        $dni = $info->dni;
        $cuit = $info->cuit;

        $dni_trimmed = $dni === null ? '' : trim((string) $dni);
        if ($dni_trimmed !== '') {
            return [$dni_trimmed, 'DNI'];
        }

        $cuit_trimmed = $cuit === null ? '' : trim((string) $cuit);
        if ($cuit_trimmed !== '') {
            return [$cuit_trimmed, 'CUIT'];
        }

        return [$fallback_document, $fallback_label];
    }
}
