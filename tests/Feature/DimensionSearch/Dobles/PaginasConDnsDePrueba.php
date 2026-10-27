<?php

namespace Tests\Feature\DimensionSearch\Dobles;

use App\Services\DimensionSearch\DimensionPageReader;

/**
 * El lector de páginas de producción (con la guarda SSRF real de BusquedaPorCodigoDeBarrasService) con UNA
 * sola perilla: la resolución DNS. Los hosts de los tests (`tienda.test`, `catalogo.test`) no resuelven de
 * verdad, y sin esto la guarda los rechazaría a todos como «no es un sitio público».
 *
 * Mismo molde que GuardaSsrfConDnsDePrueba de Imágenes inteligentes: un host listado en $internos resuelve a
 * una IP privada, para probar que la guarda lo frena. Todo lo demás es código de producción.
 */
class PaginasConDnsDePrueba extends DimensionPageReader
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
