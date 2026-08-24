<?php

namespace Tests\Feature\Busqueda;

use Tests\EmpresaTestCase;

/**
 * Clase base de los tests de busqueda (Grupo 273, Prompt 02).
 *
 * No agrega guards propios: los guards de entorno (base de testing, motor InnoDB, fixture
 * sembrado) y los helpers genericos de fixture (`articulo`, `proveedor`) ya viven en
 * `Tests\EmpresaTestCase`. Solo aporta el helper para armar el body de
 * `POST api/global-search/{model}` con sus defaults.
 */
abstract class BusquedaTestCase extends EmpresaTestCase
{
    /**
     * Arma el body de `POST api/global-search/{model}` con los defaults del endpoint (sin
     * criterio de texto, sin ningun filtro) y permite pisar cualquier clave via $overrides.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    protected function payload_global_search($overrides = [])
    {
        $defaults = [
            'query_value'    => '',
            'props'          => [],
            'relation_props' => [],
            'extra_filters'  => [],
            'filters'        => [],
            'conector'       => 'or',
        ];

        return array_merge($defaults, $overrides);
    }
}
