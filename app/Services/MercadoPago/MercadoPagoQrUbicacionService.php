<?php

namespace App\Services\MercadoPago;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Catálogo de ubicación del proveedor: una localidad de barrio no es una ciudad de cobro. */
class MercadoPagoQrUbicacionService
{
    public function provincias()
    {
        return Cache::remember('mp_qr_ubicaciones_ar_provincias_v1', 86400, function () {
            $datos = $this->consultar('/countries/AR');
            if (($datos['id'] ?? null) !== 'AR') {
                $this->no_disponible();
            }
            return $this->opciones($datos['states'] ?? null);
        });
    }

    public function localidades($provincia_id)
    {
        // La URL siempre se arma con un id del catálogo argentino, nunca con texto del request.
        $provincia = $this->buscar($this->provincias(), $provincia_id);
        if (is_null($provincia)) {
            throw new MercadoPagoQrException('Elegí una provincia del listado.');
        }

        return Cache::remember('mp_qr_ubicaciones_ar_' . $provincia['id'] . '_v1', 86400, function () use ($provincia) {
            $datos = $this->consultar('/states/' . rawurlencode($provincia['id']));
            if (($datos['id'] ?? null) !== $provincia['id'] || ($datos['country']['id'] ?? null) !== 'AR') {
                $this->no_disponible();
            }
            return ['provincia' => $provincia, 'localidades' => $this->opciones($datos['cities'] ?? null)];
        });
    }

    public function nombres($provincia_id, $localidad_id)
    {
        if (!is_string($provincia_id) || !is_string($localidad_id) || $provincia_id === '' || $localidad_id === '') {
            throw new MercadoPagoQrException('Elegí la provincia y la localidad del listado.');
        }
        $catalogo = $this->localidades($provincia_id);
        $localidad = $this->buscar($catalogo['localidades'], $localidad_id);
        if (is_null($localidad)) {
            throw new MercadoPagoQrException('Elegí una localidad de la provincia seleccionada.');
        }
        return ['state_name' => $catalogo['provincia']['name'], 'city_name' => $localidad['name']];
    }

    protected function consultar($ruta)
    {
        try {
            $respuesta = Http::acceptJson()->timeout(10)->get('https://api.mercadolibre.com' . $ruta);
            if (!$respuesta->successful() || !is_array($respuesta->json())) {
                $this->no_disponible();
            }
            return $respuesta->json();
        } catch (\Throwable $e) {
            $this->no_disponible();
        }
    }

    protected function opciones($datos)
    {
        if (!is_array($datos) || count($datos) === 0) {
            $this->no_disponible();
        }
        $opciones = [];
        foreach ($datos as $dato) {
            if (!is_array($dato) || !isset($dato['id'], $dato['name']) || !is_string($dato['id']) || !is_string($dato['name']) || $dato['id'] === '' || trim($dato['name']) === '') {
                $this->no_disponible();
            }
            $opciones[] = ['id' => $dato['id'], 'name' => $dato['name']];
        }
        usort($opciones, function ($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
        return $opciones;
    }

    protected function buscar(array $lista, $id)
    {
        foreach ($lista as $opcion) {
            if ($opcion['id'] === $id) {
                return $opcion;
            }
        }
        return null;
    }

    protected function no_disponible()
    {
        throw new MercadoPagoQrException('No pudimos cargar las provincias y localidades. Volvé a intentar en un rato.', 503);
    }
}
