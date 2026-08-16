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
        //
        // El nombre del anidado es DISTINTO del de la raiz a proposito, y no es un detalle
        // cosmetico: es lo que hace que un_dot_dot_que_vuelve_adentro_del_directorio_si_se_sirve
        // discrimine. Con el mismo nombre en los dos lugares, ese test daba 200 tanto si el ".."
        // llegaba vivo al closure como si el framework lo hubiera colapsado antes, o sea que no
        // probaba nada.
        $this->crear_directorio(storage_path('app/public/'.$this->prefijo.'_dir'));
        $this->crear_directorio(storage_path('app/public/'.$this->prefijo.'_dir/sub'));
        $this->crear_archivo(
            storage_path('app/public/'.$this->prefijo.'_dir/sub/'.$this->prefijo.'_anidado.txt'),
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
        $ruta = '/storage/'.$this->prefijo.'_dir/sub/'.$this->prefijo.'_anidado.txt';

        $this->get($ruta)->assertStatus(200);
    }

    /**
     * Este es el test que le da sentido a todos los de traversal, y por eso va con su propia
     * explicacion.
     *
     * Los tests que piden "../" y esperan 404 son verdes si el confinamiento funciona, PERO TAMBIEN
     * serian verdes si el framework hubiera normalizado el ".." antes de que el path llegara al
     * closure. En ese segundo caso son falsos verdes: no prueban nada, y el dia que alguien saque
     * el confinamiento van a seguir en verde.
     *
     * Este los desambigua, y depende de un detalle del setUp que hay que respetar: el archivo de la
     * raiz se llama "<prefijo>.txt" y el anidado "<prefijo>_anidado.txt", DISTINTO. Entonces, para
     * la URL "<prefijo>_dir/sub/../../<prefijo>.txt":
     *
     *   - Si el ".." llega vivo al closure, realpath() lo resuelve y termina en el archivo de la
     *     raiz, que existe -> 200.
     *   - Si el framework hubiera colapsado los ".." antes, el path pedido seria
     *     "<prefijo>_dir/sub/<prefijo>.txt", que NO existe -> 404.
     *
     * 🔴 Si este test se pone rojo, los tests de traversal dejan de significar algo: hay que cambiar
     * el vector (probar %2e%2e en vez de ".." crudo, o $this->call('GET', $uri)) antes de confiar en
     * ninguno de ellos. Y si alguien "ordena" el setUp poniendole el mismo nombre a los dos
     * archivos, este test sigue verde y deja de discriminar en silencio: por eso el nombre distinto
     * esta comentado alla tambien.
     *
     * @test
     */
    public function un_dot_dot_que_vuelve_adentro_del_directorio_si_se_sirve()
    {
        // Precondicion del test: los dos nombres tienen que ser distintos, si no no discrimina.
        $this->assertFileDoesNotExist(
            storage_path('app/public/'.$this->prefijo.'_dir/sub/'.$this->prefijo.'.txt'),
            'El archivo anidado no puede llamarse igual que el de la raiz: con el mismo nombre este '
            .'test da 200 en los dos escenarios y deja de discriminar.'
        );

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

    /**
     * El caso concreto que motivo la mision. Va con skip explicito si no hay .env: sin el archivo,
     * el 404 llegaria por "no existe" y el test daria verde incluso con el codigo roto. Un verde que
     * no depende del arreglo es peor que un test que falta, porque nadie lo mira.
     *
     * El respaldo que NO depende del entorno es storage_no_sirve_un_archivo_de_afuera, que apunta a
     * un marcador que el propio test crea.
     *
     * @test
     */
    public function storage_no_sirve_el_env()
    {
        if (! is_file(base_path('.env'))) {
            $this->markTestSkipped('No hay .env en este checkout: el test no podria distinguir el arreglo de su ausencia.');
        }

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
        if (! is_file(base_path('.env'))) {
            $this->markTestSkipped('No hay .env en este checkout: el test no podria distinguir el arreglo de su ausencia.');
        }

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
            $codigo = $this->get($ruta)->getStatusCode();

            /*
             * Se compara el codigo a mano en vez de encadenar assertStatus(404) + assertNotEquals(403):
             * con assertStatus primero, la asercion del 403 nunca se evalua (ya fallo la anterior) y
             * queda de adorno. Asi el mensaje nombra el 403 cuando el 403 es lo que efectivamente paso.
             */
            $this->assertEquals(
                404,
                $codigo,
                'La ruta '.$ruta.' respondio '.$codigo.'. Tiene que ser 404: cualquier otro codigo '
                .'sobre un archivo que existe le confirma al atacante que existe.'
            );
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
     * El separador al final del prefijo (StoragePathHelper, "rtrim(...) . DIRECTORY_SEPARATOR") es
     * lo unico que impide que un directorio hermano cuyo nombre empieza igual -- app/publico contra
     * app/public -- pase como si estuviera adentro.
     *
     * 🔴 El motivo se asierta a proposito, y no alcanza con assertNull($resultado['path']). Si el
     * base no existiera, el helper rechazaria por 'base_inexistente' mucho antes de llegar a la
     * comparacion de prefijo, el test daria verde igual y NO estaria probando el separador: alguien
     * podria borrar el DIRECTORY_SEPARATOR de la linea 90 del helper -- que es exactamente el
     * "simplificar" contra el que avisa el comentario de ahi -- y este test seguiria en verde.
     * Por eso se crean los DOS directorios y se exige 'fuera_del_base'.
     *
     * @test
     */
    public function un_directorio_hermano_con_nombre_parecido_no_pasa()
    {
        $base    = storage_path('app/'.$this->prefijo.'_public');
        $hermano = storage_path('app/'.$this->prefijo.'_publico');

        $this->crear_directorio($base);
        $this->crear_directorio($hermano);
        $this->crear_archivo($hermano.'/adentro.txt', 'del hermano');

        $resultado = \App\Http\Controllers\Helpers\StoragePathHelper::inspeccionar(
            $base,
            '../'.$this->prefijo.'_publico/adentro.txt'
        );

        $this->assertNull($resultado['path']);
        $this->assertEquals(
            'fuera_del_base',
            $resultado['motivo'],
            'Tiene que rechazar por la comparacion de prefijo. Si rechaza por otra cosa, este test '
            .'no esta probando el separador final del prefijo.'
        );
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
