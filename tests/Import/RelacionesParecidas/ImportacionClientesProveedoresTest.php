<?php

namespace Tests\Import\RelacionesParecidas;

use App\Http\Controllers\Helpers\import\relaciones\EquivalenciasResolver;
use App\Imports\ClientImport;
use App\Imports\ProviderImport;
use App\Models\Client;
use App\Models\ImportEquivalencia;
use App\Models\Location;
use App\Models\PriceType;
use App\Models\Provider;
use App\Models\Provincia;
use App\Models\Seller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use ReflectionMethod;

/**
 * La importación de clientes y proveedores con las decisiones del paso 3 sobre nombres parecidos
 * (misión importacion-relaciones-parecidas, 8/10/2026), por /api/ai-excel-import/import con
 * model=client|provider (sincrónica, dentro del request).
 *
 * Orden de resolución: decisión → equivalencia recordada → nombre exacto (binario) → el
 * LocalImportHelper de siempre, cuyo WHERE de MySQL ignora mayúsculas y tildes.
 *
 * Fixture 35 (clientes):
 *   Cliente RP 1  Buenos Aires / La Plata / Juan Perez / Mayorista
 *   Cliente RP 2  BUENOS AIRES / LA PLATA / JUAN PEREZ / MAYORISTA
 *   Cliente RP 3  Bs As / Quilmes / Juan Pérez / Minorista
 *   Cliente RP 4  (sin provincia) / Rosario
 * Fixture 36 (proveedores): Proveedor RP 1 Rosario, RP 2 ROSARIO, RP 3 Funes.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class ImportacionClientesProveedoresTest extends RelacionesParecidasTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    protected function provincia($nombre)
    {
        return Provincia::create(['name' => $nombre, 'user_id' => $this->tenant->id]);
    }

    protected function localidad($nombre, $provincia = null)
    {
        return Location::create([
            'num'          => 1,
            'name'         => $nombre,
            'provincia_id' => is_null($provincia) ? null : $provincia->id,
            'user_id'      => $this->tenant->id,
        ]);
    }

    protected function vendedor($nombre)
    {
        return Seller::create(['num' => 1, 'name' => $nombre, 'user_id' => $this->tenant->id]);
    }

    protected function lista($nombre)
    {
        return PriceType::create(['name' => $nombre, 'user_id' => $this->tenant->id]);
    }

    protected function cliente($nombre)
    {
        $cliente = Client::where('user_id', $this->tenant->id)->where('name', $nombre)->first();

        $this->assertNotNull($cliente, 'No se importó ' . $nombre);

        return $cliente;
    }

    protected function proveedor_importado($nombre)
    {
        $proveedor = Provider::where('user_id', $this->tenant->id)->where('name', $nombre)->first();

        $this->assertNotNull($proveedor, 'No se importó ' . $nombre);

        return $proveedor;
    }

    protected function importar_clientes($equivalencias = null)
    {
        $this->importar_modelo('client', self::CLIENTES, $this->columnas_clientes(), $equivalencias);
    }

    /* ------------------------------------------------------------------
     * Clientes
     * ------------------------------------------------------------------ */

    public function test_usar_en_provincia_localidad_vendedor_y_lista()
    {
        /* Nombres distintos a propósito: el camino de siempre nunca llegaría a estos. */
        $provincia = $this->provincia('Provincia de Buenos Aires');
        $localidad = $this->localidad('Ciudad de La Plata', $provincia);
        $vendedor  = $this->vendedor('Juan P.');
        $lista     = $this->lista('Lista Mayorista');

        $this->importar_clientes([
            ['tipo' => 'provincia',      'valor' => 'Buenos Aires', 'accion' => 'usar', 'id' => $provincia->id],
            ['tipo' => 'localidad',      'valor' => 'La Plata', 'padre' => 'Buenos Aires', 'accion' => 'usar', 'id' => $localidad->id],
            ['tipo' => 'vendedor',       'valor' => 'Juan Perez',   'accion' => 'usar', 'id' => $vendedor->id],
            ['tipo' => 'tipo_de_precio', 'valor' => 'Mayorista',    'accion' => 'usar', 'id' => $lista->id],
        ]);

        $cliente = $this->cliente('Cliente RP 1');

        $this->assertSame((int) $provincia->id, (int) $cliente->provincia_id, 'provincia');
        $this->assertSame((int) $localidad->id, (int) $cliente->location_id, 'localidad');
        $this->assertSame((int) $vendedor->id, (int) $cliente->seller_id, 'vendedor');
        $this->assertSame((int) $lista->id, (int) $cliente->price_type_id, 'lista de precios');

        $this->assertSame((int) $localidad->id, (int) ImportEquivalencia::where('user_id', $this->tenant->id)
            ->where('tipo', 'localidad')->where('contexto_id', $provincia->id)->where('valor_clave', 'La Plata')->value('model_id'),
            'La localidad con provincia se recuerda en el contexto de su provincia.');
    }

    public function test_crear_una_variante_que_solo_cambia_mayusculas_crea_una_nueva()
    {
        $viejo = $this->vendedor('JUAN PEREZ');

        $this->importar_clientes([
            ['tipo' => 'vendedor', 'valor' => 'Juan Perez', 'accion' => 'crear'],
        ]);

        $this->assertSame(1, $this->cuantos_exactos('sellers', 'Juan Perez'));

        $nuevo = Seller::where('user_id', $this->tenant->id)->whereRaw('BINARY name = ?', ['Juan Perez'])->first();

        $this->assertNotNull($nuevo->num, 'Se crea con num, como saveSeller().');
        $this->assertSame((int) $nuevo->id, (int) $this->cliente('Cliente RP 1')->seller_id);
        $this->assertSame((int) $viejo->id, (int) $this->cliente('Cliente RP 2')->seller_id, '"JUAN PEREZ" sigue yendo al viejo (nombre exacto).');
    }

    /**
     * "Crear aparte" entre dos escrituras NUEVAS no depende del orden de las filas ("Juan Perez" en
     * la fila 2, "JUAN PEREZ" en la 3). Antes, con el crear en "Juan Perez", la fila 3 caía por el
     * WHERE de MySQL (que ignora mayúsculas) en el vendedor recién creado.
     */
    public function test_crear_entre_nuevos_no_depende_del_orden_de_las_filas()
    {
        foreach (['Juan Perez', 'JUAN PEREZ'] as $con_crear) {
            DB::table('clients')->where('user_id', $this->tenant->id)->delete();
            DB::table('sellers')->where('user_id', $this->tenant->id)->delete();

            $this->importar_clientes([
                ['tipo' => 'vendedor', 'valor' => $con_crear, 'accion' => 'crear'],
            ]);

            $this->assertSame(1, $this->cuantos_exactos('sellers', 'Juan Perez'), $con_crear);
            $this->assertSame(1, $this->cuantos_exactos('sellers', 'JUAN PEREZ'), $con_crear);

            $this->assertSame('Juan Perez', Seller::find($this->cliente('Cliente RP 1')->seller_id)->name, $con_crear);
            $this->assertSame('JUAN PEREZ', Seller::find($this->cliente('Cliente RP 2')->seller_id)->name, $con_crear);
        }
    }

    /**
     * Una decisión de localidad SIN padre no se aplica a una fila que trae provincia: son dos
     * preguntas distintas del paso 3.
     */
    public function test_una_decision_sin_padre_no_se_aplica_a_una_fila_con_provincia()
    {
        $provincia = $this->provincia('BUENOS AIRES');
        $la_plata  = $this->localidad('LA PLATA', $provincia);

        $this->importar_clientes([
            ['tipo' => 'localidad', 'valor' => 'La Plata', 'accion' => 'crear'],
        ]);

        $this->assertSame((int) $la_plata->id, (int) $this->cliente('Cliente RP 1')->location_id);
        $this->assertSame(0, $this->cuantos_exactos('locations', 'La Plata'), 'No se creó nada: la decisión era para filas sin provincia.');
    }

    /**
     * Un `unir` de clientes sigue la decisión que el usuario tomó sobre el DESTINO ("Bs As" se une a
     * "Buenos Aires", y a "Buenos Aires" le eligió una provincia existente), sin depender de que la
     * equivalencia recordada exista: se prueba con la tabla de equivalencias AUSENTE (la consulta
     * apunta a una tabla que no existe) y con un camino de siempre que no debería usarse.
     */
    public function test_unir_sigue_la_decision_del_destino_sin_la_tabla_de_equivalencias()
    {
        $provincia = $this->provincia('Provincia de Buenos Aires');

        $resolver = new class($this->tenant->id, [
            ['tipo' => 'provincia', 'valor' => 'Bs As', 'accion' => 'unir', 'con' => 'Buenos Aires'],
            ['tipo' => 'provincia', 'valor' => 'Buenos Aires', 'accion' => 'usar', 'id' => $provincia->id],
        ], EquivalenciasResolver::TIPOS_CLIENTES) extends EquivalenciasResolver {
            protected static function consulta_equivalencias()
            {
                return DB::table('import_equivalencias_que_no_existe');
            }
        };

        $camino_de_hoy = function ($valor) {
            return -1;
        };

        $crear = function ($valor) {
            return -2;
        };

        $this->assertSame((int) $provincia->id, $resolver->resolver_con_camino_de_hoy('provincia', 'Bs As', 0, null, $camino_de_hoy, $crear));
    }

    public function test_unir_resuelve_el_canonico_y_lo_recuerda()
    {
        $this->importar_clientes([
            ['tipo' => 'provincia', 'valor' => 'Bs As', 'accion' => 'unir', 'con' => 'Buenos Aires'],
        ]);

        $id = (int) $this->cliente('Cliente RP 1')->provincia_id;

        $this->assertGreaterThan(0, $id);
        $this->assertSame($id, (int) $this->cliente('Cliente RP 2')->provincia_id);
        $this->assertSame($id, (int) $this->cliente('Cliente RP 3')->provincia_id, '"Bs As" se unió a "Buenos Aires".');
        $this->assertSame(0, $this->cuantos_exactos('provincias', 'Bs As'));
        $this->assertSame(1, Provincia::where('user_id', $this->tenant->id)->count());

        $this->assertSame($id, (int) ImportEquivalencia::where('user_id', $this->tenant->id)
            ->where('tipo', 'provincia')->where('valor_clave', 'Bs As')->value('model_id'));
    }

    public function test_localidad_usar_con_otra_provincia_resuelta_se_ignora()
    {
        $buenos_aires = $this->provincia('BUENOS AIRES');
        $la_plata     = $this->localidad('LA PLATA', $buenos_aires);
        $santa_fe     = $this->provincia('Santa Fe');
        $ajena        = $this->localidad('La Plata', $santa_fe);

        $this->importar_clientes([
            ['tipo' => 'localidad', 'valor' => 'La Plata', 'padre' => 'Buenos Aires', 'accion' => 'usar', 'id' => $ajena->id],
        ]);

        $cliente = $this->cliente('Cliente RP 1');

        $this->assertSame((int) $buenos_aires->id, (int) $cliente->provincia_id);
        $this->assertSame((int) $la_plata->id, (int) $cliente->location_id, 'Una localidad de otra provincia no se aplica.');
    }

    public function test_sin_equivalencias_es_igual_que_hoy()
    {
        $provincia = $this->provincia('BUENOS AIRES');
        $localidad = $this->localidad('LA PLATA', $provincia);
        $vendedor  = $this->vendedor('JUAN PEREZ');
        $lista     = $this->lista('MAYORISTA');

        $this->importar_clientes();

        foreach (['Cliente RP 1', 'Cliente RP 2'] as $nombre) {
            $cliente = $this->cliente($nombre);

            $this->assertSame((int) $provincia->id, (int) $cliente->provincia_id, $nombre);
            $this->assertSame((int) $localidad->id, (int) $cliente->location_id, $nombre);
            $this->assertSame((int) $vendedor->id, (int) $cliente->seller_id, $nombre);
            $this->assertSame((int) $lista->id, (int) $cliente->price_type_id, $nombre);
        }

        $rosario = Location::find($this->cliente('Cliente RP 4')->location_id);
        $this->assertSame('Rosario', $rosario->name);
        $this->assertNull($rosario->provincia_id, 'Sin provincia en la fila, la localidad se crea sin provincia, como siempre.');

        $this->assertSame(0, ImportEquivalencia::where('user_id', $this->tenant->id)->count());
    }

    public function test_la_equivalencia_recordada_se_aplica_en_la_segunda_importacion()
    {
        $this->vendedor('JUAN PEREZ');
        $elegido = $this->vendedor('Juan P.');

        $this->importar_clientes([
            ['tipo' => 'vendedor', 'valor' => 'Juan Perez', 'accion' => 'usar', 'id' => $elegido->id],
        ]);

        $this->assertSame((int) $elegido->id, (int) $this->cliente('Cliente RP 1')->seller_id);

        /* Se le saca el vendedor y se vuelve a importar el mismo archivo SIN decisiones. */
        DB::table('clients')->where('user_id', $this->tenant->id)->update(['seller_id' => null]);

        $this->importar_clientes();

        $this->assertSame((int) $elegido->id, (int) $this->cliente('Cliente RP 1')->seller_id);
    }

    /**
     * Lo mismo que "Látex"/"Latex" en artículos, para localidad dentro de provincia: con las dos
     * decisiones que propone el paso 3 queda UNA provincia con UNA localidad. Sin la de la
     * localidad, "Villa Gdor Galvez" se crearía aparte (el WHERE de MySQL no ignora el punto).
     */
    public function test_unir_la_provincia_y_la_localidad_deja_una_sola_de_cada_una()
    {
        $this->importar_modelo('client', self::LOCALIDADES, ['nombre' => 0, 'provincia' => 1, 'localidad' => 2], [
            ['tipo' => 'provincia', 'valor' => 'Santa Fe.', 'accion' => 'unir', 'con' => 'Santa Fe'],
            ['tipo' => 'localidad', 'valor' => 'Villa Gdor Galvez', 'padre' => 'Santa Fe.', 'accion' => 'unir', 'con' => 'Villa Gdor. Galvez'],
        ]);

        $this->assertSame(1, Provincia::where('user_id', $this->tenant->id)->count());

        $provincia = Provincia::where('user_id', $this->tenant->id)->first();
        $this->assertSame('Santa Fe', $provincia->name);

        $localidades = Location::where('user_id', $this->tenant->id)->get();
        $this->assertCount(1, $localidades, 'Una sola localidad.');
        $this->assertSame('Villa Gdor. Galvez', $localidades[0]->name);
        $this->assertSame((int) $provincia->id, (int) $localidades[0]->provincia_id);

        foreach (['Cliente LP 1', 'Cliente LP 2', 'Cliente LP 3'] as $nombre) {
            $this->assertSame((int) $localidades[0]->id, (int) $this->cliente($nombre)->location_id, $nombre);
            $this->assertSame((int) $provincia->id, (int) $this->cliente($nombre)->provincia_id, $nombre);
        }
    }

    /* ------------------------------------------------------------------
     * Proveedores
     * ------------------------------------------------------------------ */

    public function test_proveedores_usar_en_localidad()
    {
        $rosario = $this->localidad('Ciudad de Rosario');

        $this->importar_modelo('provider', self::PROVEEDORES, $this->columnas_proveedores(), [
            ['tipo' => 'localidad', 'valor' => 'Rosario', 'accion' => 'usar', 'id' => $rosario->id],
            ['tipo' => 'provincia', 'valor' => 'Rosario', 'accion' => 'usar', 'id' => 999999],
        ]);

        $this->assertSame((int) $rosario->id, (int) $this->proveedor_importado('Proveedor RP 1')->location_id);

        /*
         * "ROSARIO" no tiene decisión, y la equivalencia recordada de "Rosario" NO se le aplica: la
         * clave respeta mayúsculas (lo parecido lo vuelve a preguntar el paso 3). Va por el camino
         * de siempre, que no la confunde con "Ciudad de Rosario".
         */
        $this->assertNotSame((int) $rosario->id, (int) $this->proveedor_importado('Proveedor RP 2')->location_id);
        $this->assertSame((int) $rosario->id, (int) ImportEquivalencia::where('user_id', $this->tenant->id)
            ->where('tipo', 'localidad')->where('valor_clave', 'Rosario')->value('model_id'));

        $funes = Location::find($this->proveedor_importado('Proveedor RP 3')->location_id);
        $this->assertSame('Funes', $funes->name);

        $this->assertSame(0, ImportEquivalencia::where('user_id', $this->tenant->id)->where('tipo', 'provincia')->count(), 'Un tipo que no es de proveedores se ignora.');
    }

    public function test_proveedores_crear_una_variante_de_mayusculas()
    {
        $viejo = $this->localidad('ROSARIO');

        $this->importar_modelo('provider', self::PROVEEDORES, $this->columnas_proveedores(), [
            ['tipo' => 'localidad', 'valor' => 'Rosario', 'accion' => 'crear'],
        ]);

        $this->assertSame(1, $this->cuantos_exactos('locations', 'Rosario'));
        $this->assertNotSame((int) $viejo->id, (int) $this->proveedor_importado('Proveedor RP 1')->location_id);
        $this->assertSame((int) $viejo->id, (int) $this->proveedor_importado('Proveedor RP 2')->location_id);
    }

    /* ------------------------------------------------------------------
     * La firma congelada
     * ------------------------------------------------------------------ */

    /**
     * Las decisiones entran por un setter encadenable: el constructor sigue terminando en
     * vaciar_valores_en_blanco (lo congela ValoresEnBlancoClientesProveedoresTest, por AdminSync).
     */
    public function test_el_setter_es_encadenable_y_la_firma_no_cambia()
    {
        $cliente = new ClientImport([], true, 1, 1);
        $this->assertSame($cliente, $cliente->con_equivalencias([]));

        $proveedor = new ProviderImport([], true, 1, 1, null);
        $this->assertSame($proveedor, $proveedor->con_equivalencias([]));

        foreach ([ClientImport::class, ProviderImport::class] as $clase) {
            $parametros = (new ReflectionMethod($clase, '__construct'))->getParameters();
            $this->assertSame('vaciar_valores_en_blanco', $parametros[count($parametros) - 1]->getName(), $clase);
        }
    }
}
