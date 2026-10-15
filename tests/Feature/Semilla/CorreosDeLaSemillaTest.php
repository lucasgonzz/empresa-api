<?php

namespace Tests\Feature\Semilla;

use App\Models\Buyer;
use Database\Seeders\BuyerSeeder;
use Tests\EmpresaTestCase;

/**
 * Misión demo-correos-de-ficcion (13/10/2026): ningún correo sembrado puede ser de una persona real.
 *
 * El demo setup (`POST api/admin-sync/demo-setup` -> `DemoSetupHelper`) corre estos seeders en cada
 * instancia de demo, y lo que siembran es la lista de destinatarios de los mails del sistema: hasta
 * la 4.3.9 los compradores 4 a 18 iban con `nombre.apellido@gmail.com`, y "Activar la oferta" en
 * Tienda Online -> Promociones (`ComercioCityMailHelper::nueva_oferta`, que prefiere `buyers.email`)
 * le escribía a esa casilla con el SMTP del comercio. Lo agarró una sesión filmando el T8.30 en
 * demo2 el 12/10/2026.
 *
 * La regla: un correo sembrado es de Lucas (lista de abajo) o es de un dominio reservado (RFC 2606 /
 * RFC 6761), que no tiene casillas y nunca va a tener.
 *
 * @group semilla
 */
class CorreosDeLaSemillaTest extends EmpresaTestCase
{
    /** Las únicas casillas reales que la semilla puede usar: las de Lucas. */
    const CORREOS_DE_LUCAS = [
        'lucasgonzalez5500@gmail.com',
        'lucasgonzalez210200@gmail.com',
    ];

    /**
     * Todo lo que siembra datos de una demo o de local. Se escanea la carpeta de seeders entera
     * (menos `testing/`, que es fixture de la suite y nunca llega a una demo) para que un seeder
     * nuevo quede cubierto sin acordarse de sumarlo acá.
     */
    const ARCHIVOS_DE_SIEMBRA = [
        'app/Http/Controllers/Helpers/DemoSetupHelper.php',
        'app/Console/Commands/SembrarDatosDePrueba.php',
        'app/Console/Commands/SembrarActividadDeClientes.php',
        'app/Console/Commands/sembrar_trazabilidad_demo.php',
        'app/Console/Commands/set_datos_for_demo.php',
    ];

    const CARPETAS_DE_SIEMBRA = [
        'database/seeders',
        'app/Http/Controllers/Helpers/Seeders',
    ];

    public function test_ningun_archivo_de_siembra_tiene_un_correo_que_pueda_ser_de_una_persona_real()
    {
        $archivos = [];
        foreach (self::ARCHIVOS_DE_SIEMBRA as $relativo) {
            $this->assertFileExists(base_path($relativo), "El archivo de siembra $relativo ya no existe: actualizá la lista del test.");
            $archivos[] = base_path($relativo);
        }
        foreach (self::CARPETAS_DE_SIEMBRA as $carpeta) {
            $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($carpeta), \FilesystemIterator::SKIP_DOTS));
            foreach ($iterador as $archivo) {
                $ruta = str_replace('\\', '/', $archivo->getPathname());
                if (substr($ruta, -4) !== '.php' || strpos($ruta, '/database/seeders/testing/') !== false) {
                    continue;
                }
                $archivos[] = $ruta;
            }
        }

        $prohibidos = [];
        $revisados = 0;
        foreach ($archivos as $archivo) {
            preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+/', file_get_contents($archivo), $coincidencias);
            foreach ($coincidencias[0] as $correo) {
                $revisados++;
                if (!$this->es_correo_permitido($correo)) {
                    $prohibidos[] = str_replace(str_replace('\\', '/', base_path()) . '/', '', str_replace('\\', '/', $archivo)) . ': ' . $correo;
                }
            }
        }

        $this->assertGreaterThan(20, $revisados, 'El escaneo no encontró correos: el patrón o las rutas dejaron de apuntar a la semilla.');
        $this->assertSame([], $prohibidos, "Correos sembrados que pueden ser de una persona real. Usá @example.com (RFC 2606):\n" . implode("\n", $prohibidos));
    }

    public function test_buyer_seeder_deja_a_los_compradores_con_correos_de_ficcion_salvo_los_de_lucas()
    {
        $id_maximo = (int) Buyer::max('id');

        (new BuyerSeeder())->run();

        $correos = Buyer::where('id', '>', $id_maximo)->orderBy('id')->pluck('email')->all();

        $this->assertCount(18, $correos, 'BuyerSeeder siembra 18 compradores.');
        $this->assertSame(self::CORREOS_DE_LUCAS, array_slice($correos, 0, 2), 'Los compradores 1 y 2 son los de Lucas.');
        foreach (array_slice($correos, 2) as $correo) {
            $this->assertMatchesRegularExpression('/@example\.com$/', $correo);
        }
        $this->assertContains('martina.acosta@example.com', $correos);
        $this->assertSame(count($correos), count(array_unique($correos)), 'Cada comprador sembrado tiene su propio correo (es su usuario de la tienda).');
    }

    private function es_correo_permitido($correo)
    {
        $correo = strtolower($correo);
        if (in_array($correo, self::CORREOS_DE_LUCAS, true)) {
            return true;
        }
        $dominio = substr($correo, strpos($correo, '@') + 1);
        if (in_array($dominio, ['example.com', 'example.net', 'example.org'], true)) {
            return true;
        }
        foreach (['.example', '.test', '.invalid', '.localhost', '.local'] as $tld) {
            if (substr($dominio, -strlen($tld)) === $tld) {
                return true;
            }
        }
        return false;
    }
}
