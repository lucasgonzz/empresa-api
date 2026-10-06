<?php

namespace Tests\Feature\Saldos;

use App\Exports\ProviderExport;
use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Provider;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Misión saldo-vivo-lectores-columna-vieja (6/10/2026) — el Excel de proveedores exporta el saldo VIVO.
 *
 * Desde las cuentas por moneda el saldo de un proveedor vive en `credit_accounts.saldo` (una fila por
 * moneda) y `CurrentAcountHelper::set_model_saldo()` lo espeja en `providers.saldo_pesos` /
 * `saldo_dolares` en cada movimiento. La columna `providers.saldo` es la de antes de las cuentas por
 * moneda: ningún movimiento la actualiza, así que en una base real quedó con un valor congelado. El
 * export leía justamente esa y mostraba una cifra distinta de la que se ve en la lista de Proveedores.
 *
 * Se prueba con la clase que usa `ProcessProviderExportJob` (`new ProviderExport(null, $owner_user_id)`):
 * `collection()` + `map()` + `headings()`, o sea lo que termina en el .xlsx sin pasar por la cola ni
 * por el disco.
 *
 * 🔴 El saldo vivo se produce SIEMPRE por el camino real —el endpoint de saldo inicial, que crea el
 * movimiento y llama a `CurrentAcountHelper::checkSaldos()`— y nunca escribiendo `saldo_pesos` a mano:
 * un fixture armado a mano puede dar verde sin probar nada (el espejo podría no existir en producción
 * con esa forma). La columna vieja, en cambio, sí se escribe a mano: ningún movimiento de cuenta
 * corriente la mantiene, así que dejarla puesta con un valor congelado es exactamente lo que hay en las
 * bases reales.
 *
 * Qué protege cada caso (medido con el export leyendo la columna vieja `saldo`):
 *  - A y B son los que exige el arreglo: el vivo gana sobre el valor congelado, y un proveedor sin
 *    movimientos no exporta lo que quedó en la columna vieja. Los dos fallan con la columna vieja.
 *  - C y D (bis) también llevan un saldo vivo y también fallan con la columna vieja: C verifica que la
 *    forma del Excel no cambie (11 columnas, "Saldo actual" en el mismo lugar) y D (bis) que, con saldo
 *    en las dos monedas, la celda sea solo el de pesos.
 *  - D (solo dólares) pasa con la columna vieja y con el arreglo correcto: es la guarda contra un
 *    arreglo equivocado (leer `saldo_dolares` o sumar las dos monedas en una columna que es en pesos,
 *    como la del Excel de clientes).
 *
 * Las aserciones van siempre sobre los proveedores que crea el propio test: la base del slot trae
 * proveedores sembrados (dos de ellos con la columna vieja cargada) y el total global no es de nadie.
 *
 * @group saldos
 */
class Export_de_proveedores_saldo_vivo_Test extends EmpresaTestCase
{
    /**
     * El valor "congelado" de la columna vieja `providers.saldo`. Es distinto de cualquier saldo vivo
     * que arman los tests, así que una celda que lo traiga viene de la columna equivocada.
     */
    const SALDO_VIEJO = 7777.77;

    /** El saldo vivo en pesos que se carga por el endpoint. */
    const VIVO_EN_PESOS = 4321.50;

    /** El saldo vivo en dólares que se carga por el endpoint. */
    const VIVO_EN_DOLARES = 900.25;

    /** @var int Dueño de la sesión (el usuario del fixture). */
    protected $user_id;

    protected function setUp(): void
    {
        parent::setUp();

        // Se guarda ahora, antes de pegarle a ningún endpoint: después de un request el guard por
        // defecto deja de ser el `web` con el que se autenticó el fixture.
        $this->user_id = (int) auth()->id();
    }

    /**
     * Un proveedor del dueño de la sesión con sus dos cuentas corrientes (pesos y dólares), igual que
     * lo deja el alta real, y -si se pide- con la columna vieja `saldo` cargada.
     *
     * La columna vieja va con un UPDATE directo y no por el modelo, para que quede claro que es un
     * valor puesto a mano, ajeno a cualquier camino que mantenga saldos.
     *
     * @param  string      $nombre
     * @param  float|null  $saldo_viejo  null = la columna vieja queda como nace: en NULL.
     * @param  array       $campos       Campos extra del proveedor.
     * @return \App\Models\Provider
     */
    protected function proveedor_con_cuentas($nombre, $saldo_viejo = null, $campos = [])
    {
        $proveedor = Provider::create(array_merge([
            'num'       => (int) Provider::where('user_id', $this->user_id)->max('num') + 1,
            'name'      => 'zz '.$nombre.' '.uniqid(),
            'user_id'   => $this->user_id,
        ], $campos));

        CreditAccountHelper::crear_credit_accounts('provider', $proveedor->id, $this->user_id);

        if (!is_null($saldo_viejo)) {
            DB::table('providers')->where('id', $proveedor->id)->update(['saldo' => $saldo_viejo]);
        }

        return $proveedor->fresh();
    }

    /**
     * La cuenta corriente de un proveedor en una moneda.
     *
     * @param  \App\Models\Provider  $proveedor
     * @param  int                   $moneda_id  1 = pesos, 2 = dólares.
     * @return \App\Models\CreditAccount
     */
    protected function cuenta_de($proveedor, $moneda_id)
    {
        return CreditAccount::where('model_name', 'provider')
                            ->where('model_id', $proveedor->id)
                            ->where('moneda_id', $moneda_id)
                            ->first();
    }

    /**
     * Carga un saldo inicial en el debe de una cuenta por el endpoint real, el del modal de la cuenta
     * corriente. Crea el movimiento y llama a `CurrentAcountHelper::checkSaldos()`, que recalcula la
     * cadena, el saldo de la cuenta y el espejo `saldo_pesos` / `saldo_dolares` del proveedor.
     *
     * @param  \App\Models\CreditAccount  $cuenta
     * @param  float                      $monto
     * @return void
     */
    protected function cargar_saldo_inicial($cuenta, $monto)
    {
        $this->postJson('api/current-acount/saldo-inicial', [
            'credit_account_id' => $cuenta->id,
            'model_name'        => $cuenta->model_name,
            'model_id'          => $cuenta->model_id,
            'is_for_debe'       => true,
            'saldo_inicial'     => $monto,
        ])->assertStatus(201);
    }

    /**
     * Los headings y la fila de Excel de un proveedor, armados por la misma clase y de la misma
     * manera que en `ProcessProviderExportJob`: el proveedor sale de `collection()` (la consulta por
     * dueño) y pasa por `map()`. Se elige por id porque la colección trae a todos los del dueño.
     *
     * @param  \App\Models\Provider  $proveedor
     * @return array  [headings, fila], del mismo largo.
     */
    protected function exportar($proveedor)
    {
        $export = new ProviderExport(null, $this->user_id);

        $modelo = $export->collection()->firstWhere('id', $proveedor->id);

        $this->assertNotNull($modelo, 'El proveedor del test no salió en la colección del export.');

        $headings = $export->headings();
        $fila     = $export->map($modelo);

        $this->assertCount(count($headings), $fila, 'La fila exportada no tiene tantas celdas como headings.');

        return [$headings, $fila];
    }

    /**
     * El contenido de la celda "Saldo actual" de un proveedor, buscada por su heading (la posición se
     * verifica aparte, en el caso C).
     *
     * @param  \App\Models\Provider  $proveedor
     * @return mixed  Lo que `map()` le pone a la celda: un decimal como string, o null.
     */
    protected function saldo_actual_exportado($proveedor)
    {
        list($headings, $fila) = $this->exportar($proveedor);

        $celdas = array_combine($headings, $fila);

        $this->assertArrayHasKey('Saldo actual', $celdas, 'El Excel de proveedores no tiene la columna "Saldo actual".');

        return $celdas['Saldo actual'];
    }

    /**
     * Una celda "vacía o en cero": lo que corresponde cuando el proveedor no tiene saldo en pesos. Se
     * acepta NULL o 0 a propósito: lo que no puede ser es un valor que venga de otra columna.
     *
     * @param  mixed  $celda
     * @return bool
     */
    protected function es_vacia_o_cero($celda)
    {
        return is_null($celda) || $celda === '' || abs((float) $celda) < 0.005;
    }

    /**
     * A. El caso que se arregla. La columna vieja quedó congelada en un valor y el saldo vivo —el de
     * la cuenta en pesos, cargado por el camino real— es otro: el Excel tiene que mostrar el vivo.
     *
     * @test
     */
    public function el_saldo_actual_es_el_vivo_y_no_el_de_la_columna_vieja()
    {
        $proveedor = $this->proveedor_con_cuentas('Export saldo vivo', self::SALDO_VIEJO);

        $this->cargar_saldo_inicial($this->cuenta_de($proveedor, 1), self::VIVO_EN_PESOS);

        $proveedor = $proveedor->fresh();

        // Primero, que el saldo vivo existe y que el espejo lo recibió: sin esto el test no prueba nada.
        $this->assertEqualsWithDelta(self::VIVO_EN_PESOS, (float) $this->cuenta_de($proveedor, 1)->saldo, 0.01, 'La cuenta en pesos no quedó con el saldo inicial.');
        $this->assertNotNull($proveedor->saldo_pesos, 'El espejo saldo_pesos no quedó escrito por el camino real.');
        $this->assertEqualsWithDelta(self::VIVO_EN_PESOS, (float) $proveedor->saldo_pesos, 0.01, 'El espejo saldo_pesos no es el saldo de la cuenta.');

        // Y que la columna vieja sigue congelada: ningún movimiento la mantiene.
        $this->assertEqualsWithDelta(self::SALDO_VIEJO, (float) $proveedor->saldo, 0.01, 'La columna vieja `saldo` se movió: el test ya no reproduce una base real.');

        $celda = $this->saldo_actual_exportado($proveedor);

        $this->assertNotNull($celda, 'La celda "Saldo actual" salió vacía con un saldo vivo en pesos.');
        $this->assertEqualsWithDelta(self::VIVO_EN_PESOS, (float) $celda, 0.01, 'La celda "Saldo actual" no es el saldo vivo del proveedor.');
        $this->assertGreaterThan(0.01, abs((float) $celda - self::SALDO_VIEJO), 'La celda "Saldo actual" trae el valor congelado de la columna vieja `saldo`.');
    }

    /**
     * B. Un proveedor con la columna vieja cargada y sin un solo movimiento: no tiene saldo vivo (el
     * espejo está en NULL) y la celda no puede traer lo que quedó en la columna vieja.
     *
     * @test
     */
    public function un_proveedor_sin_movimientos_no_exporta_el_valor_de_la_columna_vieja()
    {
        $proveedor = $this->proveedor_con_cuentas('Export sin movimientos', self::SALDO_VIEJO);

        // La precondición: no hay saldo vivo en ningún lado.
        $this->assertEquals(0, CurrentAcount::where('provider_id', $proveedor->id)->count(), 'El proveedor del test no debería tener movimientos.');
        $this->assertNull($proveedor->saldo_pesos, 'El espejo saldo_pesos no está en NULL.');
        $this->assertNull($proveedor->saldo_dolares, 'El espejo saldo_dolares no está en NULL.');
        $this->assertEqualsWithDelta(0, (float) CreditAccount::where('model_name', 'provider')->where('model_id', $proveedor->id)->sum('saldo'), 0.001, 'Las cuentas del proveedor no están en cero.');
        $this->assertEqualsWithDelta(self::SALDO_VIEJO, (float) $proveedor->saldo, 0.01, 'La columna vieja `saldo` no quedó cargada.');

        $celda = $this->saldo_actual_exportado($proveedor);

        $this->assertTrue(
            $this->es_vacia_o_cero($celda),
            'La celda "Saldo actual" de un proveedor sin movimientos tiene que salir vacía o en cero, y salió '.var_export($celda, true).': trae el valor de la columna vieja `saldo`.'
        );
    }

    /**
     * C. La forma del Excel no cambia: once columnas, "Saldo actual" es el último heading y la celda
     * que está en esa misma posición es la del saldo. El resto de la fila sigue alineado con sus
     * headings: leer otra columna no puede correr nada de lugar.
     *
     * @test
     */
    public function la_forma_del_excel_no_cambia_y_el_saldo_actual_sigue_siendo_la_ultima_columna()
    {
        $proveedor = $this->proveedor_con_cuentas('Export forma', self::SALDO_VIEJO, [
            'phone'         => '011-5555-0101',
            'address'       => 'Calle Falsa 123',
            'email'         => 'export-forma@test.local',
            'razon_social'  => 'Export Forma SRL',
            'cuit'          => '30-71234567-8',
            'observations'  => 'Observaciones de prueba',
        ]);

        $this->cargar_saldo_inicial($this->cuenta_de($proveedor, 1), self::VIVO_EN_PESOS);

        list($headings, $fila) = $this->exportar($proveedor->fresh());

        $this->assertCount(11, $headings, 'El Excel de proveedores dejó de tener once columnas.');
        $this->assertCount(11, $fila, 'La fila de un proveedor dejó de tener once celdas.');

        $ultimo = count($headings) - 1;

        $this->assertSame('Saldo actual', $headings[$ultimo], '"Saldo actual" dejó de ser el último heading.');
        $this->assertSame($ultimo, array_search('Saldo actual', $headings, true));

        // La celda de esa misma posición es la del saldo vivo.
        $this->assertEqualsWithDelta(self::VIVO_EN_PESOS, (float) $fila[$ultimo], 0.01, 'La última celda de la fila no es el saldo vivo.');

        // Y lo demás sigue en su lugar.
        $celdas = array_combine($headings, $fila);

        $this->assertSame($proveedor->name, $celdas['Nombre']);
        $this->assertSame('011-5555-0101', $celdas['Telefono']);
        $this->assertSame('Calle Falsa 123', $celdas['Direccion']);
        $this->assertSame('export-forma@test.local', $celdas['Email']);
        $this->assertSame('Export Forma SRL', $celdas['Razon social']);
        $this->assertSame('30-71234567-8', $celdas['Cuit']);
        $this->assertSame('Observaciones de prueba', $celdas['Observaciones']);
    }

    /**
     * D. Las monedas no se mezclan. El Excel es en pesos, como el de clientes: un proveedor al que
     * solo se le debe en dólares no tiene saldo en pesos, y la celda no puede traer el de dólares.
     *
     * @test
     */
    public function un_saldo_solo_en_dolares_no_se_cuela_en_la_columna_en_pesos()
    {
        $proveedor = $this->proveedor_con_cuentas('Export solo dolares');

        $this->cargar_saldo_inicial($this->cuenta_de($proveedor, 2), self::VIVO_EN_DOLARES);

        $proveedor = $proveedor->fresh();

        // El saldo vivo existe, pero en la cuenta en dólares y espejado en `saldo_dolares`.
        $this->assertEqualsWithDelta(self::VIVO_EN_DOLARES, (float) $this->cuenta_de($proveedor, 2)->saldo, 0.01, 'La cuenta en dólares no quedó con el saldo inicial.');
        $this->assertNotNull($proveedor->saldo_dolares, 'El espejo saldo_dolares no quedó escrito por el camino real.');
        $this->assertEqualsWithDelta(self::VIVO_EN_DOLARES, (float) $proveedor->saldo_dolares, 0.01);
        $this->assertTrue($this->es_vacia_o_cero($proveedor->saldo_pesos), 'El saldo en pesos del proveedor no debería tener nada.');

        $celda = $this->saldo_actual_exportado($proveedor);

        $this->assertTrue(
            $this->es_vacia_o_cero($celda),
            'La celda "Saldo actual" (en pesos) salió '.var_export($celda, true).' para un proveedor con saldo solo en dólares.'
        );
    }

    /**
     * D (bis). Con saldo en las dos monedas, la celda es el de pesos y nada más: ni el de dólares ni
     * la suma de los dos, que serían números sin unidad.
     *
     * @test
     */
    public function con_saldo_en_las_dos_monedas_la_celda_es_solo_el_de_pesos()
    {
        $proveedor = $this->proveedor_con_cuentas('Export las dos monedas', self::SALDO_VIEJO);

        $this->cargar_saldo_inicial($this->cuenta_de($proveedor, 1), self::VIVO_EN_PESOS);
        $this->cargar_saldo_inicial($this->cuenta_de($proveedor, 2), self::VIVO_EN_DOLARES);

        $proveedor = $proveedor->fresh();

        $this->assertEqualsWithDelta(self::VIVO_EN_PESOS, (float) $proveedor->saldo_pesos, 0.01, 'El espejo en pesos no quedó escrito.');
        $this->assertEqualsWithDelta(self::VIVO_EN_DOLARES, (float) $proveedor->saldo_dolares, 0.01, 'El espejo en dólares no quedó escrito.');

        $celda = (float) $this->saldo_actual_exportado($proveedor);

        $this->assertEqualsWithDelta(self::VIVO_EN_PESOS, $celda, 0.01, 'La celda "Saldo actual" no es el saldo en pesos.');
        $this->assertGreaterThan(0.01, abs($celda - self::VIVO_EN_DOLARES), 'La celda "Saldo actual" trae el saldo en dólares.');
        $this->assertGreaterThan(0.01, abs($celda - (self::VIVO_EN_PESOS + self::VIVO_EN_DOLARES)), 'La celda "Saldo actual" suma pesos con dólares.');
    }
}
