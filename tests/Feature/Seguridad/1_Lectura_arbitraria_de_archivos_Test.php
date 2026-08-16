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
     * 🔴 Este es el test que le da sentido a TODOS los de traversal, y por eso va con su propia
     * explicacion y no se asierta por HTTP.
     *
     * Los tests que piden "../" y esperan 404 son verdes si el confinamiento funciona, pero tambien
     * serian verdes si el framework hubiera colapsado el ".." antes de que el path llegara al
     * closure: en ese caso el closure nunca ve un traversal, no prueban nada, y el dia que alguien
     * saque el confinamiento van a seguir en verde.
     *
     * La premisa que hay que fijar, entonces, es que el ".." llega CRUDO al closure. Y se asierta
     * directamente sobre el path decodificado del Request, que es lo unico que la prueba sin
     * ambiguedad.
     *
     * Se intento antes hacerlo por HTTP, pidiendo "<dir>/sub/../../<x>.txt" y esperando 200: no
     * sirve. Colapsar textualmente esos ".." da el mismo archivo que resolverlos, asi que devuelve
     * 200 en los dos escenarios. Lo marcaron dos chequeos independientes el 16/8/2026 y quedo
     * escrito para que no se vuelva a intentar por ahi.
     *
     * Si este test se pone rojo, los tests de traversal dejan de significar algo hasta que se
     * cambie el vector (probar %2e%2e, o $this->call('GET', $uri) en vez de $this->get()).
     *
     * @test
     */
    public function el_dot_dot_llega_crudo_al_closure_y_no_lo_colapsa_el_framework()
    {
        $uri = '/storage/'.$this->prefijo.'_dir/sub/../../'.$this->prefijo.'.txt';

        $request = \Illuminate\Http\Request::create($uri);

        $this->assertStringContainsString(
            '/../',
            '/'.$request->decodedPath(),
            'El framework colapso los ".." antes del closure. Mientras eso pase, los tests de '
            .'traversal de este archivo son falsos verdes y no protegen nada.'
        );
    }

    /**
     * Un ".." que vuelve a entrar al directorio permitido es legitimo y tiene que seguir sirviendo.
     * Este test NO prueba que el ".." llegue crudo (de eso se ocupa el de arriba): prueba que el
     * confinamiento no rompio un pedido valido.
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

    /**
     * Un archivo que existe adentro del directorio pero que el proceso no puede leer tiene que dar
     * 404 igual que uno inexistente. Sin el is_readable() del helper, BinaryFileResponse tiraba
     * FileException y la ruta respondia 500: la diferencia 500/404 vuelve a ser el oraculo de
     * existencia que todo esto viene a sacar, un escalon mas adentro.
     *
     * Se asierta contra el helper y no por HTTP porque negar el permiso de lectura de forma
     * portable entre Windows y Linux desde un test no es confiable.
     *
     * @test
     */
    public function un_archivo_que_no_se_puede_leer_no_se_sirve()
    {
        $archivo = storage_path('app/public/'.$this->prefijo.'_ilegible.txt');
        $this->crear_archivo($archivo, 'no se deberia poder leer');

        if (! @chmod($archivo, 0000) || is_readable($archivo)) {
            $this->markTestSkipped('Este entorno no permite sacarle el permiso de lectura al archivo.');
        }

        $resultado = \App\Http\Controllers\Helpers\StoragePathHelper::inspeccionar(
            storage_path('app/public'),
            $this->prefijo.'_ilegible.txt'
        );

        @chmod($archivo, 0644);

        $this->assertNull($resultado['path']);
        $this->assertEquals('no_se_puede_leer', $resultado['motivo']);
    }

    /**
     * Si el archivo desaparece entre la verificacion y el servido, la ruta tiene que responder 404
     * y no 500. Es un caso real en /exported-files/, donde los exportados son temporales que una
     * limpieza puede borrar mientras alguien los descarga.
     *
     * @test
     */
    public function si_el_archivo_desaparece_antes_de_servirlo_responde_404_y_no_500()
    {
        $archivo = storage_path('app/exported-files/'.$this->prefijo.'_efimero.xlsx');
        $this->crear_archivo($archivo, 'me van a borrar');

        $real = realpath($archivo);
        unlink($archivo);

        try {
            response()->download($real);
            $this->fail('Se esperaba que response()->download() fallara sobre un archivo borrado.');
        } catch (\Exception $e) {
            // Es justo lo que el try/catch de routes/web.php convierte en 404.
            $this->assertInstanceOf('Exception', $e);
        }

        $this->get('/exported-files/'.$this->prefijo.'_efimero.xlsx')->assertStatus(404);
    }

    /**
     * Un base que es la raiz del filesystem no confina nada: el prefijo quedaria en "/" (o "C:\") y
     * cualquier path absoluto lo matchearia. Es el mismo agujero que el base inexistente, entrando
     * por otra puerta.
     *
     * @test
     */
    public function un_base_que_es_la_raiz_del_filesystem_no_confina_nada_y_se_rechaza()
    {
        $raiz = realpath(DIRECTORY_SEPARATOR === '\\' ? substr(storage_path(), 0, 3) : '/');

        $resultado = \App\Http\Controllers\Helpers\StoragePathHelper::inspeccionar(
            $raiz,
            ltrim(str_replace(base_path().DIRECTORY_SEPARATOR, '', storage_path('app/public/'.$this->prefijo.'.txt')), '/\\')
        );

        $this->assertNull($resultado['path']);
        $this->assertEquals('base_invalido', $resultado['motivo']);
    }

    /**
     * La afirmacion central del diseño del helper: se verifica sobre realpath() y no sobre el string
     * PORQUE un chequeo textual de ".." no ve los symlinks. Sin este test, alguien puede
     * "simplificar" el helper a un strpos('..') y los otros 20 tests siguen en verde.
     *
     * Se saltea si el entorno no deja crear symlinks (en Windows hace falta privilegio). En Linux,
     * que es donde corre produccion, corre siempre.
     *
     * @test
     */
    public function un_symlink_adentro_del_base_que_apunta_afuera_se_rechaza()
    {
        $destino = storage_path('app/'.$this->prefijo.'_marcador.txt');
        $enlace  = storage_path('app/public/'.$this->prefijo.'_link.txt');

        if (! @symlink($destino, $enlace)) {
            $this->markTestSkipped('Este entorno no permite crear symlinks (en Windows hace falta privilegio).');
        }

        $this->archivos_creados[] = $enlace;

        $resultado = \App\Http\Controllers\Helpers\StoragePathHelper::inspeccionar(
            storage_path('app/public'),
            $this->prefijo.'_link.txt'
        );

        $this->assertNull(
            $resultado['path'],
            'Un symlink adentro del directorio permitido que apunta afuera tiene que rechazarse. '
            .'Si esto pasa, el helper dejo de resolver symlinks.'
        );
        $this->assertEquals('fuera_del_base', $resultado['motivo']);

        $this->get('/storage/'.$this->prefijo.'_link.txt')->assertStatus(404);
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
