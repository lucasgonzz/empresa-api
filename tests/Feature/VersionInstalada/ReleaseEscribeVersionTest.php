<?php

namespace Tests\Feature\VersionInstalada;

use Tests\TestCase;

/**
 * CA8 de la misión version-instalada-historial: el workflow de release escribe el número de versión en un archivo
 * VERSION dentro del paquete (antes de armar el zip) y verifica que el zip lo lleve. Sin ese archivo el código del
 * cliente no sabe qué versión es. Lee el YAML como texto: el workflow no se puede correr local.
 *
 * @group version-instalada
 */
class ReleaseEscribeVersionTest extends TestCase
{
    /** Contenido del workflow de release. */
    protected function release_yml()
    {
        return file_get_contents(base_path('.github/workflows/release.yml'));
    }

    /** CA8: un paso escribe VERSION con la versión extraída del commit, antes de empaquetar, y se verifica en el zip. */
    public function test_el_release_escribe_version_antes_de_empaquetar()
    {
        $yaml = $this->release_yml();

        $posicion_empaquetado = strpos($yaml, 'name: Empaquetar la API con vendor adentro');
        $this->assertNotFalse($posicion_empaquetado, 'No se encontró el paso de empaquetado.');

        // Escritura del archivo con la salida del paso `version`.
        $escritura = preg_match('/\$\{\{\s*steps\.version\.outputs\.VERSION\s*\}\}"?\s*>\s*VERSION\b/', $yaml, $m, PREG_OFFSET_CAPTURE);
        $this->assertSame(1, $escritura, 'Ningún paso escribe steps.version.outputs.VERSION en el archivo VERSION.');
        $this->assertLessThan($posicion_empaquetado, $m[0][1], 'VERSION se escribe después de armar el zip.');

        // El paquete se verifica: el zip tiene que llevar VERSION.
        $this->assertStringContainsString("grep -qE ' VERSION\$'", $yaml);
    }

    /** CA8: el archivo VERSION generado en el release no se commitea. */
    public function test_version_esta_en_gitignore()
    {
        $lineas = preg_split('/\R/', file_get_contents(base_path('.gitignore')));

        $this->assertContains('/VERSION', array_map('trim', $lineas));
    }
}
