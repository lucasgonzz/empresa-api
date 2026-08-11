<?php

namespace Tests\Feature\Import;

use App\Models\Address;
use App\Models\Client;
use App\Models\User;
use App\Notifications\GlobalNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Feature tests de la columna `sucursal` en la importacion de clientes (tarea 15).
 *
 * Cubren los criterios de aceptacion 1 a 7 de la mision, contra el endpoint real
 * (POST api/client/excel/import) y con un Excel de verdad de por medio.
 *
 * Los dos importantes son:
 *  - que un nombre de sucursal inexistente NO cree una sucursal (una sucursal arrastra
 *    depositos, cajas e identidad fiscal: crearla por un error de tipeo del Excel deja
 *    basura que despues hay que limpiar a mano);
 *  - que un cliente cuyo unico cambio es la sucursal se actualice igual, que es lo que
 *    falla en silencio si `isDataUpdated()` no mira `address_id`.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing del slot esta sembrada de
 * antes y un refresh la vaciaria. Mismo patron que el resto de los tests de la rama.
 */
class ClientSucursalImportTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Rutas de los Excel temporales creados durante el test, para borrarlos al terminar.
     *
     * @var array
     */
    protected $archivos_temporales = [];

    protected function tearDown(): void
    {
        foreach ($this->archivos_temporales as $ruta) {
            if (is_file($ruta)) {
                @unlink($ruta);
            }
        }

        $this->archivos_temporales = [];

        parent::tearDown();
    }

    /**
     * Usuario autenticado de los tests de esta rama (mismo patron que Catalogos/Sales/Stock).
     * Null si la base de testing no lo tiene sembrado.
     *
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * Deja el usuario 500 autenticado, o saltea el test si la base no lo tiene.
     *
     * @return \App\Models\User
     */
    protected function autenticar()
    {
        $user = $this->usuario_de_testing();

        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($user, 'web');

        return $user;
    }

    /**
     * Crea una sucursal del usuario 500 con el nombre indicado.
     *
     * @param  string $nombre  Nombre de la sucursal (columna `street`)
     * @return \App\Models\Address
     */
    protected function crear_sucursal($nombre)
    {
        return Address::create([
            'street'  => $nombre,
            'user_id' => 500,
        ]);
    }

    /**
     * Genera un Excel temporal con la cabecera y las filas indicadas.
     *
     * @param  array $cabecera  Nombres de las columnas de la primera fila
     * @param  array $filas     Filas de datos
     * @return \Illuminate\Http\UploadedFile
     */
    protected function excel(array $cabecera, array $filas)
    {
        $spreadsheet = new Spreadsheet();
        $hoja        = $spreadsheet->getActiveSheet();

        $hoja->fromArray(array_merge([$cabecera], $filas), null, 'A1');

        $ruta = tempnam(sys_get_temp_dir(), 'zz_import_sucursal_') . '.xlsx';

        $writer = new Xlsx($spreadsheet);
        $writer->save($ruta);

        $this->archivos_temporales[] = $ruta;

        return new UploadedFile($ruta, 'clientes.xlsx', null, null, true);
    }

    /**
     * Dispara la importacion contra el endpoint real.
     *
     * @param  \Illuminate\Http\UploadedFile $archivo  Excel a importar
     * @param  array $props  Mapeo de columnas en formato prop_<clave> => numero de columna (1-based)
     * @return \Illuminate\Testing\TestResponse
     */
    protected function importar($archivo, array $props)
    {
        $payload = array_merge([
            'models'          => $archivo,
            'create_and_edit' => 1,
            'start_row'       => 2,
            'finish_row'      => '',
        ], $props);

        return $this->post('api/client/excel/import', $payload);
    }

    /**
     * Criterio 1: los valores que coinciden con el nombre de una sucursal existente
     * dejan al cliente con el address_id correcto.
     *
     * @group import_sucursal
     * @test
     */
    public function asigna_la_sucursal_cuando_el_nombre_coincide()
    {
        $this->autenticar();

        $centro = $this->crear_sucursal('zz Sucursal Centro');
        $norte  = $this->crear_sucursal('zz Sucursal Norte');

        $archivo = $this->excel(['Nombre', 'Sucursal'], [
            ['zz Cliente uno', 'zz Sucursal Centro'],
            ['zz Cliente dos', 'zz Sucursal Norte'],
        ]);

        $this->importar($archivo, [
            'prop_nombre'   => 1,
            'prop_sucursal' => 2,
        ]);

        $uno = Client::where('user_id', 500)->where('name', 'zz Cliente uno')->first();
        $dos = Client::where('user_id', 500)->where('name', 'zz Cliente dos')->first();

        $this->assertNotNull($uno, 'El cliente uno no se creo durante la importacion.');
        $this->assertNotNull($dos, 'El cliente dos no se creo durante la importacion.');

        $this->assertEquals($centro->id, $uno->address_id);
        $this->assertEquals($norte->id, $dos->address_id);
    }

    /**
     * Criterio 2: el match tolera mayusculas, espacios sobrantes y acentos.
     *
     * @group import_sucursal
     * @test
     */
    public function el_match_tolera_mayusculas_espacios_y_acentos()
    {
        $this->autenticar();

        $centro = $this->crear_sucursal('zz Sucursal Céntro');

        $archivo = $this->excel(['Nombre', 'Sucursal'], [
            ['zz Cliente mayusculas', 'ZZ SUCURSAL CENTRO'],
            ['zz Cliente espacios',   '  zz sucursal centro  '],
            ['zz Cliente acentos',    'zz Sucursal Céntro'],
        ]);

        $this->importar($archivo, [
            'prop_nombre'   => 1,
            'prop_sucursal' => 2,
        ]);

        $nombres = ['zz Cliente mayusculas', 'zz Cliente espacios', 'zz Cliente acentos'];

        foreach ($nombres as $nombre) {
            $cliente = Client::where('user_id', 500)->where('name', $nombre)->first();

            $this->assertNotNull($cliente, 'No se creo el cliente ' . $nombre . '.');
            $this->assertEquals(
                $centro->id,
                $cliente->address_id,
                'El cliente ' . $nombre . ' no quedo en la sucursal esperada.'
            );
        }
    }

    /**
     * Criterio 3: un nombre que no corresponde a ninguna sucursal no crea nada y deja
     * el address_id que el cliente ya tenia.
     *
     * @group import_sucursal
     * @test
     */
    public function una_sucursal_inexistente_no_se_crea_y_no_pisa_la_asignacion_previa()
    {
        $this->autenticar();

        $centro = $this->crear_sucursal('zz Sucursal Centro');

        $existente = Client::create([
            'name'       => 'zz Cliente con sucursal previa',
            'user_id'    => 500,
            'num'        => 990001,
            'address_id' => $centro->id,
        ]);

        $cantidad_antes = Address::where('user_id', 500)->count();

        $archivo = $this->excel(['Nombre', 'Sucursal'], [
            ['zz Cliente con sucursal previa', 'zz Sucursal Que No Existe'],
            ['zz Cliente nuevo sin sucursal',  'zz Sucursal Que No Existe'],
        ]);

        $this->importar($archivo, [
            'prop_nombre'   => 1,
            'prop_sucursal' => 2,
        ]);

        $this->assertEquals(
            $cantidad_antes,
            Address::where('user_id', 500)->count(),
            'La importacion creo una sucursal nueva, y no deberia crear ninguna.'
        );

        $existente->refresh();
        $this->assertEquals(
            $centro->id,
            $existente->address_id,
            'La sucursal inexistente piso la asignacion previa del cliente.'
        );

        $nuevo = Client::where('user_id', 500)->where('name', 'zz Cliente nuevo sin sucursal')->first();
        $this->assertNotNull($nuevo, 'El cliente nuevo no se creo durante la importacion.');
        $this->assertNull($nuevo->address_id, 'El cliente nuevo quedo con una sucursal que no existe.');
    }

    /**
     * Criterio 4: la notificacion final informa los nombres no encontrados, sin repetidos
     * y con el texto original del Excel.
     *
     * @group import_sucursal
     * @test
     */
    public function informa_las_sucursales_que_no_existen_sin_repetidos()
    {
        $this->autenticar();

        $this->crear_sucursal('zz Sucursal Centro');

        Notification::fake();

        $archivo = $this->excel(['Nombre', 'Sucursal'], [
            ['zz Cliente a', 'zz Sucursal Fantasma'],
            ['zz Cliente b', '  ZZ SUCURSAL FANTASMA '],
            ['zz Cliente c', 'zz Otra Que No Existe'],
            ['zz Cliente d', 'zz Sucursal Centro'],
        ]);

        $this->importar($archivo, [
            'prop_nombre'   => 1,
            'prop_sucursal' => 2,
        ]);

        Notification::assertSentTo(
            $this->usuario_de_testing(),
            GlobalNotification::class,
            function ($notification) {
                $info = $notification->info_to_show;

                if (count($info) !== 1) {
                    return false;
                }

                if ($info[0]['title'] !== 'Sucursales que no existen en el sistema') {
                    return false;
                }

                $parrafos = $info[0]['parrafos'];

                /* Dos nombres distintos (el repetido se deduplica) mas el parrafo aclaratorio. */
                if (count($parrafos) !== 3) {
                    return false;
                }

                /* Se conserva el texto original del Excel, no el normalizado. */
                if ($parrafos[0] !== 'zz Sucursal Fantasma' || $parrafos[1] !== 'zz Otra Que No Existe') {
                    return false;
                }

                return strpos($parrafos[2], 'sin sucursal asignada') !== false;
            }
        );
    }

    /**
     * Criterio 4 (contracara): si todas las sucursales del Excel existen, no se agrega
     * ningun bloque informativo.
     *
     * @group import_sucursal
     * @test
     */
    public function no_informa_nada_cuando_todas_las_sucursales_existen()
    {
        $this->autenticar();

        $this->crear_sucursal('zz Sucursal Centro');

        Notification::fake();

        $archivo = $this->excel(['Nombre', 'Sucursal'], [
            ['zz Cliente sin problemas', 'zz Sucursal Centro'],
        ]);

        $this->importar($archivo, [
            'prop_nombre'   => 1,
            'prop_sucursal' => 2,
        ]);

        Notification::assertSentTo(
            $this->usuario_de_testing(),
            GlobalNotification::class,
            function ($notification) {
                return $notification->info_to_show === [];
            }
        );
    }

    /**
     * Criterio 5: un cliente ya existente cuyo unico cambio en el Excel es la sucursal
     * queda actualizado. Es el criterio que falla si isDataUpdated() no mira address_id.
     *
     * @group import_sucursal
     * @test
     */
    public function actualiza_un_cliente_cuyo_unico_cambio_es_la_sucursal()
    {
        $this->autenticar();

        $centro = $this->crear_sucursal('zz Sucursal Centro');

        $cliente = Client::create([
            'name'    => 'zz Cliente solo cambia sucursal',
            'user_id' => 500,
            'num'     => 990002,
            'phone'   => '3510000000',
        ]);

        $this->assertNull($cliente->address_id);

        $archivo = $this->excel(['Nombre', 'Telefono', 'Sucursal'], [
            ['zz Cliente solo cambia sucursal', '3510000000', 'zz Sucursal Centro'],
        ]);

        $this->importar($archivo, [
            'prop_nombre'   => 1,
            'prop_telefono' => 2,
            'prop_sucursal' => 3,
        ]);

        $cliente->refresh();

        $this->assertEquals(
            $centro->id,
            $cliente->address_id,
            'El cliente no se actualizo: isDataUpdated() no esta mirando address_id.'
        );
    }

    /**
     * Criterio 6: un Excel sin columna de sucursal no toca el address_id de nadie.
     *
     * @group import_sucursal
     * @test
     */
    public function un_excel_sin_columna_de_sucursal_no_desasigna_a_nadie()
    {
        $this->autenticar();

        $centro = $this->crear_sucursal('zz Sucursal Centro');

        $cliente = Client::create([
            'name'       => 'zz Cliente que conserva su sucursal',
            'user_id'    => 500,
            'num'        => 990003,
            'phone'      => '3510000001',
            'address_id' => $centro->id,
        ]);

        $archivo = $this->excel(['Nombre', 'Telefono'], [
            ['zz Cliente que conserva su sucursal', '3519999999'],
        ]);

        $this->importar($archivo, [
            'prop_nombre'   => 1,
            'prop_telefono' => 2,
        ]);

        $cliente->refresh();

        $this->assertEquals('3519999999', $cliente->phone, 'La importacion no actualizo el telefono.');
        $this->assertEquals(
            $centro->id,
            $cliente->address_id,
            'Una importacion sin columna de sucursal desasigno la sucursal del cliente.'
        );
    }

    /**
     * Criterio 7: la columna `direccion` sigue escribiendo el domicilio de texto libre
     * (clients.address) y no tiene nada que ver con la sucursal.
     *
     * @group import_sucursal
     * @test
     */
    public function la_columna_direccion_sigue_siendo_el_domicilio_de_texto_libre()
    {
        $this->autenticar();

        $centro = $this->crear_sucursal('zz Sucursal Centro');

        $archivo = $this->excel(['Nombre', 'Direccion', 'Sucursal'], [
            ['zz Cliente con domicilio', 'Av. Colon 1234', 'zz Sucursal Centro'],
        ]);

        $this->importar($archivo, [
            'prop_nombre'    => 1,
            'prop_direccion' => 2,
            'prop_sucursal'  => 3,
        ]);

        $cliente = Client::where('user_id', 500)->where('name', 'zz Cliente con domicilio')->first();

        $this->assertNotNull($cliente, 'El cliente no se creo durante la importacion.');
        $this->assertEquals('Av. Colon 1234', $cliente->address, 'La columna direccion dejo de escribir el domicilio.');
        $this->assertEquals($centro->id, $cliente->address_id, 'La sucursal no quedo asignada.');
    }
}
