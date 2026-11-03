<?php

namespace Tests\Feature\ListasSiempre;

use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Models\ArticleTicketDesign;
use App\Models\Category;
use App\Models\PriceType;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * D7 de la misión prueba-listas-siempre: `listas:migrar-a-precio-final`, la herramienta que migra un
 * comercio de a uno. Cada test usa un dueño PROPIO y una carpeta de respaldo propia, que se borra al
 * terminar.
 */
class Comando_migrar_a_precio_final_Test extends ListasSiempreTestCase
{
    /** @var string */
    protected $salida;

    /** @var string */
    protected $texto = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->salida = storage_path('framework/testing/listas-precio-final-'.uniqid());
    }

    protected function tearDown(): void
    {
        $this->borrar($this->salida);

        parent::tearDown();
    }

    protected function borrar($ruta)
    {
        if (!file_exists($ruta)) {
            return;
        }

        if (is_dir($ruta)) {
            foreach (scandir($ruta) as $hijo) {
                if ($hijo !== '.' && $hijo !== '..') {
                    $this->borrar($ruta.DIRECTORY_SEPARATOR.$hijo);
                }
            }

            @rmdir($ruta);

            return;
        }

        @unlink($ruta);
    }

    protected function correr($dueno, array $opciones = array())
    {
        $codigo = Artisan::call('listas:migrar-a-precio-final', array_merge(array(
            'user_id'  => $dueno->id,
            '--salida' => $this->salida,
        ), $opciones));

        $this->texto = Artisan::output();

        return $codigo;
    }

    protected function lista_precio_final_de($dueno)
    {
        return PriceType::where('user_id', $dueno->id)->where('es_precio_final', 1)->first();
    }

    protected function flag_de($dueno)
    {
        return (int) DB::table('users')->where('id', $dueno->id)->value('listas_de_precio');
    }

    /**
     * Un comercio de hoy: sin listas, con un poco de todo, con sus precios ya calculados.
     *
     * @return array
     */
    protected function escenario()
    {
        $dueno = $this->dueno_nuevo('migrar');

        $proveedor = Provider::create(array('name' => 'zz Proveedor migrar '.uniqid(), 'user_id' => $dueno->id, 'percentage_gain' => 30));
        $categoria = Category::create(array('name' => 'zz Categoría migrar '.uniqid(), 'user_id' => $dueno->id, 'percentage_gain' => 25));

        $articulos = array(
            'margen'     => $this->articulo($dueno, 'margen', array('cost' => 1000, 'percentage_gain' => 50)),
            'proveedor'  => $this->articulo($dueno, 'proveedor', array('cost' => 1000, 'percentage_gain' => 10, 'provider_id' => $proveedor->id, 'apply_provider_percentage_gain' => 1)),
            'categoria'  => $this->articulo($dueno, 'categoría', array('cost' => 1000, 'category_id' => $categoria->id)),
            'manual'     => $this->articulo($dueno, 'manual', array('cost' => 1000, 'price' => 1234.56)),
            'dolares'    => $this->articulo($dueno, 'dólares', array('cost' => 10, 'cost_in_dollars' => 1, 'percentage_gain' => 20)),
            'sin_nada'   => $this->articulo($dueno, 'sin costo ni precio', array()),
            'papelera'   => $this->articulo($dueno, 'papelera', array('cost' => 1000, 'percentage_gain' => 15)),
        );

        $precios = array();

        foreach ($articulos as $clave => $articulo) {
            if ($clave === 'sin_nada') {
                DB::table('articles')->where('id', $articulo->id)->update(array('final_price' => 777));
            } else {
                $this->recalcular($articulo, $dueno);
            }

            $precios[$clave] = $this->final_price_de($articulo);
        }

        $articulos['papelera']->delete();

        // Su etiqueta de góndola genérica, como la tiene cualquier dueño sin listas.
        ArticleTicketDesign::create(array('user_id' => $dueno->id, 'name' => 'Etiqueta de góndola', 'price_type_id' => null, 'position' => 0, 'diseno' => array('elementos' => array())));

        return compact('dueno', 'articulos', 'precios');
    }

    /** @test */
    public function ver_no_escribe_nada()
    {
        $e = $this->escenario();

        $this->assertSame(0, $this->correr($e['dueno']), $this->texto);

        $this->assertStringContainsString('NO tiene la «Precio final»', $this->texto);
        $this->assertStringContainsString('apagado → se prende', $this->texto);
        $this->assertStringContainsString('Artículos sin pivote', $this->texto);
        $this->assertNull($this->lista_precio_final_de($e['dueno']));
        $this->assertSame(0, $this->flag_de($e['dueno']));
        $this->assertSame(0, DB::table('article_price_type')->where('article_id', $e['articulos']['margen']->id)->count());
        $this->assertFalse(file_exists($this->salida), '--ver no escribe archivos.');
    }

    /** @test */
    public function aplicar_crea_la_lista_prende_el_flag_y_deja_cada_precio_identico()
    {
        $e = $this->escenario();

        $disenos_antes = ArticleTicketDesign::where('user_id', $e['dueno']->id)->count();

        $this->assertSame(0, $this->correr($e['dueno'], array('--aplicar' => true)), $this->texto);

        $lista = $this->lista_precio_final_de($e['dueno']);

        $this->assertNotNull($lista);
        $this->assertSame(PriceTypeHelper::NOMBRE_PRECIO_FINAL, $lista->name);
        $this->assertSame(1, (int) $lista->position);
        $this->assertSame(1, $this->flag_de($e['dueno']));

        $dueno = User::find($e['dueno']->id);
        foreach (array('articulo_margen_de_ganancia_segun_lista_de_precios', 'cambiar_price_type_en_vender', 'cambiar_price_type_en_vender_item_por_item') as $slug) {
            $this->assertTrue($dueno->extencions->contains('slug', $slug), 'Falta la extensión '.$slug);
        }

        foreach ($e['articulos'] as $clave => $articulo) {
            $this->assertMismoPrecio($e['precios'][$clave], $this->final_price_de($articulo), 'El precio final del caso "'.$clave.'" tiene que quedar idéntico.');

            $pivote = $this->pivote($articulo, $lista);
            $this->assertNotNull($pivote, 'Falta el pivote del caso "'.$clave.'".');
            $this->assertMismoPrecio($e['precios'][$clave], is_null($pivote->final_price) ? null : (float) $pivote->final_price, 'El pivote del caso "'.$clave.'" tiene que ser el precio final.');
        }

        $this->assertSame(1, (int) $this->pivote($e['articulos']['manual'], $lista)->setear_precio_final);
        $this->assertEqualsWithDelta(15, (float) $this->pivote($e['articulos']['papelera'], $lista)->percentage, 0.001, 'La papelera recibe el pivote directo.');

        // Sin diseño de etiquetas nuevo: con una sola lista vale el genérico que ya tenía.
        $this->assertSame($disenos_antes, ArticleTicketDesign::where('user_id', $e['dueno']->id)->count());

        // Respaldo y reversión.
        $carpetas = glob($this->salida.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR);
        $this->assertCount(1, $carpetas);
        $this->assertFileExists($carpetas[0].DIRECTORY_SEPARATOR.'respaldo.jsonl');

        $respaldos = array_map(function ($linea) { return json_decode($linea, true); }, file($carpetas[0].DIRECTORY_SEPARATOR.'respaldo.jsonl', FILE_IGNORE_NEW_LINES));
        $tipos = array_count_values(array_column($respaldos, 'tipo'));
        $this->assertSame(1, $tipos['comercio']);
        $this->assertSame(1, $tipos['cuenta']);
        $this->assertSame(count($e['articulos']), $tipos['articulo']);
        $this->assertArrayNotHasKey('revertido', $tipos);

        $sql = file_get_contents($carpetas[0].DIRECTORY_SEPARATOR.'reversion.sql');
        $this->assertStringContainsString('DELETE FROM price_types WHERE id = '.$lista->id, $sql);
        $this->assertStringContainsString('UPDATE users SET listas_de_precio = 0 WHERE id = '.$e['dueno']->id, $sql);
        $this->assertStringContainsString('-- Artículo '.$e['articulos']['margen']->id, $sql);
        $this->assertStringContainsString("price = '1234.56'", $sql);
        $this->assertStringContainsString('INMEDIATAMENTE después de migrar', $sql);
        $this->assertStringContainsString('FUERA del horario del negocio', $this->texto);
    }

    /** @test */
    public function la_reversion_deja_todo_como_estaba()
    {
        $e = $this->escenario();

        $this->assertSame(0, $this->correr($e['dueno'], array('--aplicar' => true)), $this->texto);

        $lista = $this->lista_precio_final_de($e['dueno']);

        $carpetas = glob($this->salida.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR);
        $sql = file_get_contents($carpetas[0].DIRECTORY_SEPARATOR.'reversion.sql');

        /*
         * Se ejecuta sentencia por sentencia, sin los START TRANSACTION / COMMIT del archivo: el test
         * ya corre adentro de una transacción (DatabaseTransactions) y un COMMIT la cerraría. Y los
         * bloques de artículo antes que el de la cuenta, como dice el encabezado del archivo.
         */
        $bloques = preg_split('/\n(?=-- Artículo |-- Cuenta)/', $sql);
        $cuenta = array();
        $articulos = array();

        foreach ($bloques as $bloque) {
            if (strpos($bloque, '-- Cuenta') === 0) {
                $cuenta[] = $bloque;
            } elseif (strpos($bloque, '-- Artículo') === 0) {
                $articulos[] = $bloque;
            }
        }

        $this->assertCount(1, $cuenta);
        $this->assertCount(count($e['articulos']), $articulos);

        foreach (array_merge($articulos, $cuenta) as $bloque) {
            foreach (explode("\n", $bloque) as $linea) {
                $linea = trim($linea);
                if ($linea === '' || strpos($linea, '--') === 0 || $linea === 'START TRANSACTION;' || $linea === 'COMMIT;') {
                    continue;
                }
                DB::unprepared($linea);
            }
        }

        $this->assertNull(PriceType::find($lista->id), 'La lista se borró.');
        $this->assertSame(0, $this->flag_de($e['dueno']), 'El flag volvió a 0.');
        $this->assertSame(0, DB::table('article_price_type')->where('price_type_id', $lista->id)->count());

        foreach ($e['articulos'] as $clave => $articulo) {
            $this->assertMismoPrecio($e['precios'][$clave], $this->final_price_de($articulo), 'El precio del caso "'.$clave.'" sigue como estaba.');
        }

        $this->assertEqualsWithDelta(1234.56, (float) DB::table('articles')->where('id', $e['articulos']['manual']->id)->value('price'), self::DELTA);
    }

    /** @test */
    public function la_segunda_corrida_no_hace_nada()
    {
        $e = $this->escenario();

        $this->assertSame(0, $this->correr($e['dueno'], array('--aplicar' => true)), $this->texto);

        $lista = $this->lista_precio_final_de($e['dueno']);
        $pivotes = DB::table('article_price_type')->where('price_type_id', $lista->id)->count();

        $this->assertSame(0, $this->correr($e['dueno'], array('--aplicar' => true)), $this->texto);
        $this->assertStringContainsString('No hay nada que migrar', $this->texto);

        $this->assertSame(1, PriceType::where('user_id', $e['dueno']->id)->count(), 'No se duplica la lista.');
        $this->assertSame($pivotes, DB::table('article_price_type')->where('price_type_id', $lista->id)->count());
        $this->assertCount(1, glob($this->salida.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR), 'La segunda corrida no abre otro respaldo.');
    }

    /** @test */
    public function un_articulo_nuevo_despues_de_migrar_tambien_recibe_el_pivote()
    {
        $e = $this->escenario();

        $this->assertSame(0, $this->correr($e['dueno'], array('--aplicar' => true)), $this->texto);

        $nuevo = $this->articulo($e['dueno'], 'nuevo después de migrar', array('cost' => 500, 'percentage_gain' => 40));

        $this->assertSame(0, $this->correr($e['dueno'], array('--aplicar' => true)), $this->texto);

        $lista = $this->lista_precio_final_de($e['dueno']);
        $this->assertNotNull($this->pivote($nuevo, $lista));
        $this->assertMismoPrecio($this->final_price_de($nuevo), (float) $this->pivote($nuevo, $lista)->final_price);
    }

    /** @test */
    public function con_otro_modo_de_listas_frena()
    {
        $dueno = $this->dueno_nuevo('por-categoria');
        $dueno = $this->con_extension($dueno, 'lista_de_precios_por_categoria');
        $this->articulo($dueno, 'por categoría', array('cost' => 1000, 'percentage_gain' => 50));

        $this->assertSame(1, $this->correr($dueno, array('--aplicar' => true)));
        $this->assertStringContainsString('otro modo de listas', $this->texto);
        $this->assertNull($this->lista_precio_final_de($dueno));
        $this->assertSame(0, $this->flag_de($dueno));
    }

    /** @test */
    public function un_dueno_que_ya_trabaja_con_listas_no_se_migra()
    {
        $dueno = $this->dueno_nuevo('con-listas', array('listas_de_precio' => 1));
        $this->otra_lista($dueno);
        $this->articulo($dueno, 'con listas', array('cost' => 1000));

        $this->assertSame(0, $this->correr($dueno, array('--aplicar' => true)), $this->texto);
        $this->assertStringContainsString('ya trabaja con listas', $this->texto);
        $this->assertNull($this->lista_precio_final_de($dueno));
        $this->assertFalse(file_exists($this->salida));
    }

    /** @test */
    public function listas_con_el_flag_apagado_frenan_salvo_que_se_incluyan()
    {
        $dueno = $this->dueno_nuevo('listas-apagadas');
        $mayorista = $this->otra_lista($dueno, 'zz Mayorista apagada', 100, 3);
        $articulo = $this->articulo($dueno, 'con listas apagadas', array('cost' => 1000, 'percentage_gain' => 50));
        $this->recalcular($articulo, $dueno);
        $precio = $this->final_price_de($articulo);

        $this->assertSame(1, $this->correr($dueno, array('--aplicar' => true)));
        $this->assertStringContainsString('--incluir-listas-existentes', $this->texto);
        $this->assertNull($this->lista_precio_final_de($dueno));
        $this->assertSame(0, $this->flag_de($dueno));

        $this->assertSame(0, $this->correr($dueno, array('--aplicar' => true, '--incluir-listas-existentes' => true)), $this->texto);

        $lista = $this->lista_precio_final_de($dueno);
        $this->assertNotNull($lista);
        $this->assertSame(4, (int) $lista->position, 'Por encima de la lista existente: queda como lista por defecto.');
        $this->assertSame(1, $this->flag_de($dueno));
        $this->assertMismoPrecio($precio, $this->final_price_de($articulo));
        $this->assertMismoPrecio($precio, (float) $this->pivote($articulo, $lista)->final_price);
        $this->assertNotNull($this->pivote($articulo, $mayorista), 'La lista existente también quedó calculada.');
    }

    /** @test */
    public function a_un_empleado_no_lo_migra()
    {
        $dueno = $this->dueno_nuevo('con-empleado');
        $empleado = User::create(array(
            'name'     => 'zz Empleado listas-siempre',
            'email'    => 'zz-empleado-listas-'.uniqid().'@test.local',
            'password' => bcrypt(uniqid('', true)),
            'owner_id' => $dueno->id,
        ));

        $this->assertSame(1, $this->correr($empleado, array('--aplicar' => true)));
        $this->assertStringContainsString('es empleado', $this->texto);
    }
}
