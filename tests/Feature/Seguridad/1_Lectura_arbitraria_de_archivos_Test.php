<?php

namespace Tests\Feature\Seguridad;

use Tests\TestCase;

/**
 * Feature tests del confinamiento de paths en las rutas publicas que sirven archivos.
 * Estrena la suite `seguridad-paths`.
 *
 * Protege una lectura arbitraria de archivos que estuvo viva en produccion: /storage/{path},
 * /imported-files/{path} y /exported-files/{path} concatenaban el parametro del usuario directo a
 * storage_path() y servian el resultado, sin autenticacion y con ->where('path', '.*'), o sea que
 * un "../" alcanzaba para bajarse el .env con las credenciales de la base, la API key de Anthropic
 * y las claves fiscales.
 *
 * Las tres rutas son publicas a proposito y tienen que seguir siendolo: por eso ningun test usa
 * actingAs(). Que sirvan sin sesion es parte del contrato; lo que no puede pasar es que sirvan algo
 * de afuera de su directorio.
 *
 * No toca la base. Crea y borra archivos reales en storage, con un prefijo unico por corrida.
 *
 * @group seguridad-paths
 */
class Lectura_arbitraria_de_archivos_Test extends TestCase
{
    /** Prefijo unico de esta corrida, para no pisar nada del storage de trabajo. */
    protected $prefijo;

    /** Archivos creados por el test, para borrarlos en tearDown(). */
    protected $archivos_creados = [];

    /** Directorios creados por el test, para borrarlos en tearDown(). */
    protected $directorios_creados = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefijo = 'test_secpath_'.uniqid();
        $this->archivos_creados = [];
        $this->directorios_creados = [];

        // Archivo legitimo en la raiz de app/public.
        $this->crear_archivo(storage_path('app/public/'.$this->prefijo.'.txt'), 'contenido legitimo');

        // Archivo legitimo dos niveles adentro: es el caso real de articles_images/zip/<x>/<x>.jpg
        // y de support_messages/<ticket_id>/<hash>.ext. Si esto se rompiera, se caerian las
        // imagenes de articulos y los adjuntos del chat de soporte.
        $this->crear_directorio(storage_path('app/public/'.$this->prefijo.'_dir'));
        $this->crear_directorio(storage_path('app/public/'.$this->prefijo.'_dir/sub'));
        $this->crear_archivo(
            storage_path('app/public/'.$this->prefijo.'_dir/sub/'.$this->prefijo.'.txt'),
            'contenido anidado legitimo'
        );

        // El objetivo del ataque: un archivo que EXISTE y esta un nivel arriba del directorio
        // permitido. Se usa en lugar del .env real para no depender de que el .env exista ni de
        // cuantos niveles hay que subir en cada entorno.
        $this->crear_archivo(storage_path('app/'.$this->prefijo.'_marcador.txt'), 'SECRETO');

        // Archivo legitimo de /imported-files/.
        $this->crear_directorio(storage_path('app/imported_files'));
        $this->crear_archivo(storage_path('app/imported_files/'.$this->prefijo.'.xlsx'), 'excel falso');

        // Archivo legitimo de /exported-files/. El directorio puede no existir en un checkout
        // limpio; crear_directorio() solo lo anota para borrar si lo creo el test.
        $this->crear_directorio(storage_path('app/exported-files'));
        $this->crear_archivo(storage_path('app/exported-files/'.$this->prefijo.'.xlsx'), 'excel falso');
    }

    protected function tearDown(): void
    {
        foreach ($this->archivos_creados as $archivo) {
            if (is_file($archivo)) {
                @unlink($archivo);
            }
        }

        // En orden inverso: los hijos antes que los padres.
        foreach (array_reverse($this->directorios_creados) as $directorio) {
            if (is_dir($directorio)) {
                @rmdir($directorio);
            }
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------
    // Lo legitimo tiene que seguir andando
    // ---------------------------------------------------------------------------------------

    /** @test */
    public function storage_sirve_un_archivo_legitimo()
    {
        $this->get('/storage/'.$this->prefijo.'.txt')->assertStatus(200);
    }

    /** @test */
    public function storage_sirve_un_archivo_en_subcarpeta_anidada()
    {
        $ruta = '/storage/'.$this->prefijo.'_dir/sub/'.$this->prefijo.'.txt';

        $this->get($ruta)->assertStatus(200);
    }

    /**
     * Este es el test que le da sentido a todos los de traversal, y por eso va con su propia
     * explicacion.
     *
     * Los tests que piden "../" y esperan 404 son verdes si el confinamiento funciona, PERO TAMBIEN
     * son verdes si el framework normalizo el ".." antes de que el path llegara al closure. En ese
     * segundo caso son falsos verdes: no estan probando nada y el dia que alguien saque el
     * confinamiento van a seguir en verde.
     *
     * Este los desambigua. Un ".." que vuelve a entrar al directorio permitido es legitimo y tiene
     * que devolver 200. Si devuelve 200, es porque el ".." llego vivo hasta realpath() y realpath()
     * lo resolvio -- que es justo lo que los otros tests necesitan que sea cierto.
     *
     * 🔴 Si este test se pone rojo, los de traversal dejan de significar algo: hay que cambiar el
     * vector (probar %2e%2e en vez de ".." crudo, o $this->call('GET', $uri)) antes de confiar en
     * ninguno de ellos.
     *
     * @test
     */
    public function un_dot_dot_que_vuelve_adentro_del_directorio_si_se_sirve()
    {
        $ruta = '/storage/'.$this->prefijo.'_dir/sub/../../'.$this->prefijo.'.txt';

        $this->get($ruta)->assertStatus(200);
    }

    /** @test */
    public function imported_files_sirve_un_archivo_legitimo()
    {
        $this->get('/imported-files/'.$this->prefijo.'.xlsx')->assertStatus(200);
    }

    /** @test */
    public function exported_files_sirve_un_archivo_legitimo()
    {
        $this->get('/exported-files/'.$this->prefijo.'.xlsx')->assertStatus(200);
    }

    // ---------------------------------------------------------------------------------------
    // Lo que no puede salir
    // ---------------------------------------------------------------------------------------

    /** @test */
    public function storage_no_sirve_un_archivo_de_afuera()
    {
        $this->get('/storage/../'.$this->prefijo.'_marcador.txt')->assertStatus(404);
    }

    /** @test */
    public function storage_no_sirve_el_env()
    {
        $this->get('/storage/../../../.env')->assertStatus(404);
    }

    /** @test */
    public function imported_files_no_sirve_un_archivo_de_afuera()
    {
        $this->get('/imported-files/../'.$this->prefijo.'_marcador.txt')->assertStatus(404);
    }

    /** @test */
    public function exported_files_no_sirve_el_env()
    {
        $this->get('/exported-files/../../../.env')->assertStatus(404);
    }

    /**
     * Un directorio pasa file_exists() -- que era el unico chequeo que habia antes -- y
     * response()->file() sobre una carpeta tira 500. Ademas, poder preguntar por carpetas es
     * enumeracion gratis del layout del servidor.
     *
     * @test
     */
    public function storage_no_sirve_un_directorio()
    {
        $this->get('/storage/'.$this->prefijo.'_dir')->assertStatus(404);
    }

    /**
     * El byte nulo trunca el string en las llamadas al filesystem, que son de C. Tiene que dar 404
     * limpio y no un 500 por warning de PHP.
     *
     * @test
     */
    public function storage_no_sirve_con_byte_nulo()
    {
        $this->get('/storage/'.$this->prefijo.".txt\0.jpg")->assertStatus(404);
    }

    /**
     * El requisito explicito de Lucas: el rechazo no puede distinguirse de un archivo que no existe.
     * Un 403 sobre un archivo que esta afuera le confirma al atacante que ese archivo existe, y esa
     * señal es la mitad del trabajo de enumerar un servidor.
     *
     * Por eso se pide un archivo que EXISTE de verdad (el marcador) y se exige 404 en los tres
     * endpoints.
     *
     * @test
     */
    public function el_rechazo_es_404_y_nunca_403()
    {
        $rutas = [
            '/storage/../'.$this->prefijo.'_marcador.txt',
            '/imported-files/../'.$this->prefijo.'_marcador.txt',
            '/exported-files/../'.$this->prefijo.'_marcador.txt',
        ];

        foreach ($rutas as $ruta) {
            $respuesta = $this->get($ruta);

            $respuesta->assertStatus(404);
            $this->assertNotEquals(403, $respuesta->getStatusCode(), 'La ruta '.$ruta.' respondio 403.');
        }
    }

    // ---------------------------------------------------------------------------------------
    // El helper, en los casos que no se pueden pedir por HTTP
    // ---------------------------------------------------------------------------------------

    /**
     * El caso que convierte el arreglo en un no-arreglo si se omite: cuando el directorio base no
     * existe, realpath() devuelve false. Si eso se dejara pasar, el prefijo de comparacion quedaria
     * vacio y CUALQUIER path del disco pasaria la verificacion.
     *
     * @test
     */
    public function con_el_directorio_base_inexistente_no_resuelve_nada()
    {
        $base_inexistente = storage_path('app/'.$this->prefijo.'_no_existe');

        $resultado = \App\Http\Controllers\Helpers\StoragePathHelper::inspeccionar(
            $base_inexistente,
            $this->prefijo.'_marcador.txt'
        );

        $this->assertNull($resultado['path']);
        $this->assertEquals('base_inexistente', $resultado['motivo']);
    }

    /**
     * El separador al final del prefijo es lo unico que impide que un directorio hermano cuyo nombre
     * empieza igual (app/public vs app/publico) pase como si estuviera adentro.
     *
     * @test
     */
    public function un_directorio_hermano_con_nombre_parecido_no_pasa()
    {
        $hermano = storage_path('app/'.$this->prefijo.'_publico');
        $this->crear_directorio($hermano);
        $this->crear_archivo($hermano.'/adentro.txt', 'del hermano');

        $resultado = \App\Http\Controllers\Helpers\StoragePathHelper::inspeccionar(
            storage_path('app/'.$this->prefijo.'_public'),
            'adentro.txt'
        );

        $this->assertNull($resultado['path']);
    }

    /** @test */
    public function el_path_vacio_no_resuelve_nada()
    {
        $resultado = \App\Http\Controllers\Helpers\StoragePathHelper::inspeccionar(
            storage_path('app/public'),
            ''
        );

        $this->assertNull($resultado['path']);
        $this->assertEquals('path_vacio', $resultado['motivo']);
    }

    /**
     * Un path absoluto no puede saltar el base: se relativiza antes de resolver.
     *
     * @test
     */
    public function un_path_absoluto_no_escapa_del_base()
    {
        $resultado = \App\Http\Controllers\Helpers\StoragePathHelper::inspeccionar(
            storage_path('app/public'),
            storage_path('app/'.$this->prefijo.'_marcador.txt')
        );

        $this->assertNull($resultado['path']);
    }

    // ---------------------------------------------------------------------------------------
    // Auxiliares
    // ---------------------------------------------------------------------------------------

    /**
     * Crea un archivo y lo anota para borrarlo en tearDown().
     *
     * @param  string  $ruta
     * @param  string  $contenido
     * @return void
     */
    protected function crear_archivo($ruta, $contenido)
    {
        file_put_contents($ruta, $contenido);

        $this->archivos_creados[] = $ruta;
    }

    /**
     * Crea un directorio SOLO si no existe, y lo anota para borrarlo en tearDown() SOLO en ese caso.
     *
     * La distincion no es un detalle: storage/app/imported_files y storage/app/public tienen
     * contenido real de la maquina. Si el test los anotara siempre, el @rmdir de tearDown() no los
     * borraria (no estan vacios) pero seria una bomba esperando a que alguien lo cambie por un
     * borrado recursivo.
     *
     * @param  string  $ruta
     * @return void
     */
    protected function crear_directorio($ruta)
    {
        if (is_dir($ruta)) {
            return;
        }

        mkdir($ruta, 0755, true);

        $this->directorios_creados[] = $ruta;
    }
}
