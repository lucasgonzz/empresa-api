<?php

namespace Tests\Feature\SucursalSiempre;

use App\Console\Commands\MigrarASucursalPrincipal;
use App\Models\Address;
use App\Models\Article;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * D13 de la misión sucursal-siempre: `sucursales:migrar-a-principal`, la herramienta que migra un
 * comercio de a uno. Cada test usa un dueño PROPIO (el del fixture tiene cientos de miles de
 * artículos) y una carpeta de respaldo propia, que se borra al terminar.
 */
class Comando_migrar_a_principal_Test extends SucursalSiempreTestCase
{
    /** @var string */
    protected $salida;

    /** @var string */
    protected $texto = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->salida = storage_path('framework/testing/sucursal-principal-'.uniqid());
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

    protected function correr($dueno, array $opciones = [])
    {
        $codigo = Artisan::call('sucursales:migrar-a-principal', array_merge([
            'user_id'   => $dueno->id,
            '--salida'  => $this->salida,
        ], $opciones));

        $this->texto = Artisan::output();

        return $codigo;
    }

    protected function articulo_propio($dueno, $nombre, $stock)
    {
        return Article::create(['name' => 'zz '.$nombre.' '.uniqid(), 'user_id' => $dueno->id, 'stock' => $stock]);
    }

    protected function filas($articulo)
    {
        return DB::table('address_article')->where('article_id', $articulo->id)->get();
    }

    protected function stock_crudo($articulo)
    {
        return DB::table('articles')->where('id', $articulo->id)->value('stock');
    }

    /**
     * Un comercio sin sucursales con un poco de todo.
     *
     * @return array
     */
    protected function escenario()
    {
        $dueno = $this->dueno_nuevo('migrar');
        DB::table('users')->where('id', $dueno->id)->update(['address_company' => 'Belgrano 450']);

        $empleado = User::create([
            'name'     => 'zz Empleado migrar',
            'email'    => 'zz-empleado-migrar-'.uniqid().'@test.local',
            'password' => Hash::make(uniqid('', true)),
            'owner_id' => $dueno->id,
        ]);

        $con_stock = $this->articulo_propio($dueno, 'con stock', 12);
        $negativo  = $this->articulo_propio($dueno, 'negativo', -3);
        $cero      = $this->articulo_propio($dueno, 'cero', 0);
        $nulo      = $this->articulo_propio($dueno, 'nulo', null);
        $papelera  = $this->articulo_propio($dueno, 'papelera', 4);
        $papelera->delete();

        $con_variantes = $this->articulo_propio($dueno, 'con variantes', 9);
        $v1 = $this->variante($con_variantes, 5);
        $v2 = $this->variante($con_variantes, 4);
        $v3 = $this->variante($con_variantes, 0);

        return compact('dueno', 'empleado', 'con_stock', 'negativo', 'cero', 'nulo', 'papelera', 'con_variantes', 'v1', 'v2', 'v3');
    }

    /**
     * @test
     */
    public function ver_no_escribe_nada()
    {
        $e = $this->escenario();

        $movimientos = StockMovement::count();

        $this->assertSame(0, $this->correr($e['dueno']));

        $this->assertStringContainsString('NO tiene sucursales', $this->texto);
        $this->assertStringContainsString('Belgrano 450', $this->texto);
        $this->assertSame(0, Address::sucursales_de($e['dueno']->id)->count());
        $this->assertSame(0, $this->filas($e['con_stock'])->count());
        $this->assertSame($movimientos, StockMovement::count());
        $this->assertFalse(file_exists($this->salida), '--ver no escribe archivos.');
    }

    /**
     * @test
     */
    public function aplicar_deja_todo_articulo_con_fila_y_el_global_identico()
    {
        $e = $this->escenario();

        $this->assertSame(0, $this->correr($e['dueno'], ['--aplicar' => true]), $this->texto);

        $principal = Address::principal_de($e['dueno']->id);

        $this->assertNotNull($principal);
        $this->assertSame('Principal', $principal->street);
        $this->assertSame('Belgrano 450', $principal->street_number, 'D8: el domicilio sale de address_company.');
        $this->assertSame(1, (int) $principal->default_address);

        // c. Empleados (0 → 1): dueño y empleado con la principal.
        $this->assertSame($principal->id, (int) DB::table('users')->where('id', $e['dueno']->id)->value('address_id'));
        $this->assertSame($principal->id, (int) DB::table('users')->where('id', $e['empleado']->id)->value('address_id'));

        // d/e. Cada artículo con su fila en la principal y el global idéntico.
        $esperado = ['con_stock' => 12, 'negativo' => -3, 'cero' => 0, 'nulo' => 0, 'papelera' => 4];

        foreach ($esperado as $clave => $stock) {
            $filas = $this->filas($e[$clave]);
            $this->assertCount(1, $filas, 'El artículo "'.$clave.'" tiene que tener UNA fila.');
            $this->assertEquals($principal->id, $filas[0]->address_id);
            $this->assertEquals($stock, (float) $filas[0]->amount, 'Fila de "'.$clave.'".');
            $this->assertEquals($stock, (float) $this->stock_crudo($e[$clave]), 'Global de "'.$clave.'".');
        }

        $this->assertNull($this->stock_crudo($e['nulo']), 'Repartir el stock no activa control de cantidad.');

        // Movimiento por el motor solo para los que tienen stock y no están en la papelera.
        $this->assertSame(1, StockMovement::where('article_id', $e['con_stock']->id)->count());
        $this->assertSame(1, StockMovement::where('article_id', $e['negativo']->id)->count());
        $this->assertSame(0, StockMovement::where('article_id', $e['cero']->id)->count());
        $this->assertSame(0, StockMovement::where('article_id', $e['papelera']->id)->count(), 'La papelera no deja movimiento.');

        $movimiento = StockMovement::where('article_id', $e['con_stock']->id)->first();
        $this->assertStringStartsWith(MigrarASucursalPrincipal::OBSERVACION, (string) $movimiento->observations);
        $this->assertEquals($principal->id, $movimiento->to_address_id);

        // Variantes: cada una con su fila, y el artículo con el mismo total.
        foreach (['v1' => 5, 'v2' => 4, 'v3' => 0] as $clave => $stock) {
            $this->assertEquals($stock, $this->stock_de_variante_en($e[$clave], $principal->id), 'Fila de la variante '.$clave.'.');
            $this->assertEquals($stock, $this->stock_de_variante($e[$clave]));
        }

        $this->assertEquals(9.0, (float) $this->stock_crudo($e['con_variantes']));

        // g. Respaldo y reversión.
        $carpetas = glob($this->salida.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR);
        $this->assertCount(1, $carpetas);
        $this->assertFileExists($carpetas[0].DIRECTORY_SEPARATOR.'respaldo.jsonl');
        $sql = file_get_contents($carpetas[0].DIRECTORY_SEPARATOR.'reversion.sql');
        $this->assertStringContainsString('DELETE FROM addresses WHERE id = '.$principal->id, $sql);
        $this->assertStringContainsString('-- Artículo '.$e['con_stock']->id, $sql);
    }

    /**
     * f. Idempotente: la segunda corrida no hace nada.
     *
     * @test
     */
    public function la_segunda_corrida_no_duplica()
    {
        $e = $this->escenario();

        $this->correr($e['dueno'], ['--aplicar' => true]);

        $sucursales = Address::sucursales_de($e['dueno']->id)->count();
        $filas = DB::table('address_article')->whereIn('article_id', [$e['con_stock']->id, $e['cero']->id])->count();
        $movimientos = StockMovement::count();

        $this->assertSame(0, $this->correr($e['dueno'], ['--aplicar' => true]));
        $this->assertStringContainsString('No hay nada que migrar', $this->texto);

        $this->assertSame($sucursales, Address::sucursales_de($e['dueno']->id)->count());
        $this->assertSame($filas, DB::table('address_article')->whereIn('article_id', [$e['con_stock']->id, $e['cero']->id])->count());
        $this->assertSame($movimientos, StockMovement::count());
    }

    /**
     * a. Con filas fantasma frena y no escribe nada.
     *
     * @test
     */
    public function con_filas_fantasma_frena()
    {
        $dueno = $this->dueno_nuevo('fantasma');

        $muerta = Address::create(['street' => 'zz Muerta', 'user_id' => $dueno->id]);
        $articulo = $this->articulo_propio($dueno, 'con fantasma', 3);
        $articulo->addresses()->attach($muerta->id, ['amount' => 3]);
        DB::table('addresses')->where('id', $muerta->id)->delete();

        $this->assertSame(1, $this->correr($dueno, ['--aplicar' => true]));
        $this->assertStringContainsString('stock:sanear-sucursales-borradas', $this->texto);
        $this->assertSame(0, Address::sucursales_de($dueno->id)->count(), 'No creó nada.');
    }

    /**
     * Un comercio que YA tiene sucursales (estado mixto, como 2R): no crea otra, marca la principal,
     * no toca empleados y reparte solo los artículos sin fila.
     *
     * @test
     */
    public function con_sucursales_reparte_solo_los_que_no_tienen_fila()
    {
        $dueno = $this->dueno_nuevo('mixto');

        $salon = Address::create(['street' => 'zz Salón', 'user_id' => $dueno->id]);
        $deposito = Address::create(['street' => 'zz Depósito', 'user_id' => $dueno->id]);

        $empleado = User::create([
            'name'     => 'zz Empleado mixto',
            'email'    => 'zz-empleado-mixto-'.uniqid().'@test.local',
            'password' => Hash::make(uniqid('', true)),
            'owner_id' => $dueno->id,
        ]);

        $repartido = $this->articulo_propio($dueno, 'repartido', 0);
        $repartido->addresses()->attach($deposito->id, ['amount' => 6]);
        DB::table('articles')->where('id', $repartido->id)->update(['stock' => 6]);

        $global = $this->articulo_propio($dueno, 'global', 15);

        $this->assertSame(0, $this->correr($dueno, ['--aplicar' => true]), $this->texto);

        $this->assertSame(2, Address::sucursales_de($dueno->id)->count(), 'No crea otra sucursal.');
        $this->assertSame(1, (int) $salon->fresh()->default_address, 'Marca la de menor id.');
        $this->assertNull(DB::table('users')->where('id', $empleado->id)->value('address_id'), 'Con sucursales, los empleados no se tocan.');

        $this->assertEquals(15.0, (float) DB::table('address_article')->where('article_id', $global->id)->where('address_id', $salon->id)->value('amount'));
        $this->assertEquals(15.0, (float) $this->stock_crudo($global));

        $this->assertCount(1, $this->filas($repartido), 'El que ya repartía no se toca.');
        $this->assertEquals(6.0, (float) $this->stock_crudo($repartido));
    }

    /**
     * e. Un artículo cuyo global no coincide con la suma de sus variantes (dato roto): la verificación
     * lo detecta y lo revierte, sin frenar al resto.
     *
     * @test
     */
    public function el_articulo_que_no_quedaria_identico_se_revierte()
    {
        $dueno = $this->dueno_nuevo('revierte');

        $roto = $this->articulo_propio($dueno, 'roto', 50);
        $v = $this->variante($roto, 0);

        $sano = $this->articulo_propio($dueno, 'sano', 2);

        $this->assertSame(1, $this->correr($dueno, ['--aplicar' => true]), 'Termina con 1 porque hubo un artículo revertido.');
        $this->assertStringContainsString('revertidos', $this->texto);

        $this->assertNull($this->stock_de_variante_en($v, Address::principal_de($dueno->id)->id), 'El roto quedó como estaba.');
        $this->assertEquals(50.0, (float) $this->stock_crudo($roto));

        $this->assertEquals(2.0, (float) DB::table('address_article')->where('article_id', $sano->id)->value('amount'), 'El sano se migró.');
    }

    /**
     * Un artículo cuya única fila es la de un domicilio de COMPRADOR cuenta como sin fila: el informe lo
     * dice, y la principal recibe el stock MENOS esa fila (el global queda idéntico).
     *
     * @test
     */
    public function la_fila_de_un_comprador_no_cuenta_como_sucursal()
    {
        $dueno = $this->dueno_nuevo('comprador');
        Address::create(['street' => 'zz Unica comprador', 'user_id' => $dueno->id]);

        $comprador = Address::create(['street' => 'zz Domicilio de envio', 'user_id' => null, 'buyer_id' => 555]);

        $articulo = $this->articulo_propio($dueno, 'con fila de comprador', 0);
        $articulo->addresses()->attach($comprador->id, ['amount' => -2]);
        DB::table('articles')->where('id', $articulo->id)->update(['stock' => 7]);

        $this->assertSame(0, $this->correr($dueno));
        $this->assertStringContainsString('con fila solo en un domicilio de comprador (cuentan como sin fila; la principal recibe el stock menos esa fila): 1', $this->texto);

        $this->assertSame(0, $this->correr($dueno, ['--aplicar' => true]), $this->texto);

        $principal = Address::principal_de($dueno->id);

        $this->assertEquals(9.0, (float) DB::table('address_article')->where('article_id', $articulo->id)->where('address_id', $principal->id)->value('amount'), 'La principal recibe 7 − (−2).');
        $this->assertEquals(-2.0, (float) DB::table('address_article')->where('article_id', $articulo->id)->where('address_id', $comprador->id)->value('amount'));
        $this->assertEquals(7.0, (float) $this->stock_crudo($articulo), 'El global queda idéntico.');
    }

    /**
     * La migración no pisa `articles.stock_updated_at` (el stock no cambia), y el valor queda en la
     * reversión.
     *
     * @test
     */
    public function no_pisa_stock_updated_at_y_lo_guarda_en_la_reversion()
    {
        $dueno = $this->dueno_nuevo('fecha');

        $articulo = $this->articulo_propio($dueno, 'con fecha', 12);
        DB::table('articles')->where('id', $articulo->id)->update(['stock_updated_at' => '2021-03-04 05:06:07']);

        $this->assertSame(0, $this->correr($dueno, ['--aplicar' => true]), $this->texto);

        $this->assertSame(1, StockMovement::where('article_id', $articulo->id)->count(), 'Hubo movimiento por el motor.');
        $this->assertSame('2021-03-04 05:06:07', (string) DB::table('articles')->where('id', $articulo->id)->value('stock_updated_at'));

        $carpetas = glob($this->salida.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR);
        $sql = file_get_contents($carpetas[0].DIRECTORY_SEPARATOR.'reversion.sql');
        $this->assertStringContainsString("stock_updated_at = '2021-03-04 05:06:07' WHERE id = ".$articulo->id, $sql);
        $this->assertStringContainsString('INMEDIATAMENTE después de migrar', $sql);
        $this->assertStringContainsString('FUERA del horario del negocio', $this->texto);
    }

    /** @test */
    public function conserva_stock_sin_control_en_la_copia_vinculada_y_en_la_papelera()
    {
        $dueno = $this->dueno_nuevo('sin-control');
        $proveedor = $this->articulo_propio($this->usuario(), 'fuente disponible', 9);
        $copia = $this->articulo_propio($dueno, 'copia disponible sin cantidad propia', null);
        DB::table('articles')->where('id', $copia->id)->update([
            'provider_article_id' => $proveedor->id,
            'created_at' => '2024-01-02 03:04:05',
            'stock_updated_at' => '2024-02-03 04:05:06',
        ]);
        $papelera = $this->articulo_propio($dueno, 'papelera sin control', null);
        $papelera->delete();

        $this->assertSame(0, $this->correr($dueno, ['--aplicar' => true]), $this->texto);

        foreach ([$copia, $papelera] as $articulo) {
            $this->assertNull($this->stock_crudo($articulo), 'Sin control de stock no significa agotado.');
            $this->assertCount(1, $this->filas($articulo));
            $this->assertEquals(0, $this->filas($articulo)[0]->amount);
            $this->assertSame(0, StockMovement::where('article_id', $articulo->id)->count());
        }
        $this->assertEquals(9, $this->stock_crudo($proveedor), 'No toca la cantidad del proveedor.');
        $this->assertSame('2024-01-02 03:04:05', DB::table('articles')->where('id', $copia->id)->value('created_at'));
        $this->assertSame('2024-02-03 04:05:06', DB::table('articles')->where('id', $copia->id)->value('stock_updated_at'));
        $carpetas = glob($this->salida.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR);
        $respaldos = array_map(function ($linea) { return json_decode($linea, true); }, file($carpetas[0].DIRECTORY_SEPARATOR.'respaldo.jsonl', FILE_IGNORE_NEW_LINES));
        $respaldo_copia = array_values(array_filter($respaldos, function ($fila) use ($copia) {
            return $fila['tipo'] === 'articulo' && $fila['article_id'] === $copia->id;
        }));
        $this->assertCount(1, $respaldo_copia);
        $this->assertNull($respaldo_copia[0]['stock'], 'El respaldo conserva la diferencia entre null y cero.');
        $this->assertSame(0, $this->correr($dueno, ['--aplicar' => true]), $this->texto);
        $this->assertNull($this->stock_crudo($copia), 'La segunda corrida tampoco lo vuelve agotado.');
        $this->assertCount(1, $this->filas($copia));
    }

    /** @test */
    public function conserva_stock_sin_control_en_el_articulo_y_sus_variantes()
    {
        $dueno = $this->dueno_nuevo('variantes-sin-control');
        $articulo = $this->articulo_propio($dueno, 'variantes sin control', null);
        $v1 = $this->variante($articulo, null);
        $v2 = $this->variante($articulo, null);

        $this->assertSame(0, $this->correr($dueno, ['--aplicar' => true]), $this->texto);

        $principal = Address::principal_de($dueno->id);
        $this->assertNull($this->stock_crudo($articulo));
        foreach ([$v1, $v2] as $variante) {
            $this->assertNull($this->stock_de_variante($variante));
            $this->assertSame(0.0, $this->stock_de_variante_en($variante, $principal->id));
        }
        $this->assertSame(0, StockMovement::where('article_id', $articulo->id)->count());
        $this->assertSame(0, $this->correr($dueno, ['--aplicar' => true]), $this->texto);
        $this->assertNull($this->stock_crudo($articulo));
        $this->assertNull($this->stock_de_variante($v1));
    }

    /** @test */
    public function conserva_la_variante_sin_control_al_migrar_otra_con_cantidad()
    {
        $dueno = $this->dueno_nuevo('variantes-mixtas');
        $articulo = $this->articulo_propio($dueno, 'variantes mixtas', 5);
        $sin_control = $this->variante($articulo, null);
        $con_cantidad = $this->variante($articulo, 5);

        $this->assertSame(0, $this->correr($dueno, ['--aplicar' => true]), $this->texto);

        $principal = Address::principal_de($dueno->id);
        $this->assertNull($this->stock_de_variante($sin_control));
        $this->assertSame(0.0, $this->stock_de_variante_en($sin_control, $principal->id));
        $this->assertSame(5.0, $this->stock_de_variante($con_cantidad));
        $this->assertSame(5.0, $this->stock_de_variante_en($con_cantidad, $principal->id));
        $this->assertEquals(5, $this->stock_crudo($articulo));
    }

    /** @test */
    public function conserva_stock_sin_control_con_filas_previas_de_comprador()
    {
        $dueno = $this->dueno_nuevo('sin-control-comprador');
        $comprador = Address::create(['street' => 'zz Envio anterior', 'user_id' => null, 'buyer_id' => 555]);
        $articulo = $this->articulo_propio($dueno, 'sin control con envio anterior', null);
        $articulo->addresses()->attach($comprador->id, ['amount' => -2]);

        $this->assertSame(0, $this->correr($dueno, ['--aplicar' => true]), $this->texto);

        $principal = Address::principal_de($dueno->id);
        $this->assertNull($this->stock_crudo($articulo));
        $this->assertEquals(2, DB::table('address_article')->where('article_id', $articulo->id)->where('address_id', $principal->id)->value('amount'));
        $this->assertEquals(-2, DB::table('address_article')->where('article_id', $articulo->id)->where('address_id', $comprador->id)->value('amount'));
        $this->assertEquals(0, DB::table('address_article')->where('article_id', $articulo->id)->sum('amount'));
    }

    /** @test */
    public function preservar_null_no_tapa_un_total_de_variantes_inconsistente()
    {
        $dueno = $this->dueno_nuevo('null-inconsistente');
        $articulo = $this->articulo_propio($dueno, 'total null con cantidad en variante', null);
        $variante = $this->variante($articulo, 5);

        $this->assertSame(1, $this->correr($dueno, ['--aplicar' => true]), $this->texto);

        $this->assertStringContainsString('1 revertidos', $this->texto);
        $this->assertNull($this->stock_crudo($articulo));
        $this->assertSame(5.0, $this->stock_de_variante($variante));
        $this->assertSame(0, DB::table('address_article_variant')->where('article_variant_id', $variante->id)->count());
        $this->assertCount(0, $this->filas($articulo));
        $this->assertSame(0, StockMovement::where('article_id', $articulo->id)->count());
    }
}
