<?php

namespace Tests\Feature\ImagenesInteligentes\Dobles;

use App\Services\BusquedaPorCodigoDeBarrasService;

/**
 * La guarda SSRF real (BusquedaPorCodigoDeBarrasService::url_permitida / ip_publica) con UNA sola
 * perilla: la resolución DNS. Los hosts de los tests (`imagenes.test`, `tienda.test`) no resuelven
 * de verdad, y sin esto la guarda los rechazaría a todos como "no es un sitio público".
 *
 * Mismo molde que ServicioDeBusquedaConDnsDePrueba en Busqueda_por_codigo_de_barras_Test: todo lo
 * demás —el esquema, el puerto, la validación de IP— es el código de producción. Un host listado
 * en $internos resuelve a una IP privada, para probar que la guarda lo frena.
 */
class GuardaSsrfConDnsDePrueba extends BusquedaPorCodigoDeBarrasService
{
    /** @var array<int, string> Hosts que resuelven a una IP interna (10.0.0.5). */
    public static $internos = ['interno.test'];

    /**
     * @param  string $host
     * @return array<int, string>
     */
    protected function resolver_host($host)
    {
        if (in_array($host, self::$internos, true)) {
            return ['10.0.0.5'];
        }

        // Una IP pública de documentación: la guarda la acepta y, con Http::fake, nadie se conecta.
        return ['93.184.216.34'];
    }
}
