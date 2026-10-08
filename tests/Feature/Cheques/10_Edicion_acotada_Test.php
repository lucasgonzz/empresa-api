<?php

namespace Tests\Feature\Cheques;

use App\Models\Caja;
use App\Models\Cheque;
use App\Models\ChequeBanco;
use App\Models\CurrentAcount;
use App\Models\EtiquetaMedida;
use App\Models\MovimientoCaja;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Misión cheque-edicion-acotada (8/10/2026) — `PUT cheque/{id}`: al editar un cheque solo se pueden
 * cambiar el número, el banco, las notas, la fecha de emisión y la fecha de pago. Todo lo demás
 * (cliente, proveedor, monto, cuenta corriente, caja, estado, endoso...) mueve plata o cuentas, y el
 * endpoint tiene que ignorarlo aunque lo manden.
 *
 * Los pedidos copian lo que manda la SPA: el modelo ENTERO del cheque, tal como lo devuelve
 * `GET cheque` (con sus relaciones), con los cambios del usuario encima. La API lee solo sus cinco
 * claves por nombre.
 *
 * 🔴 El test del centinela (`ninguna_columna_fuera_de_las_cinco_cambia_aunque_la_manden`) es el que
 * sostiene la regla en el tiempo: pide TODAS las columnas de `cheques` con un valor hostil y compara
 * la fila completa antes y después. Una columna nueva en la tabla que nadie declare ahí lo pone rojo.
 *
 * Lo AJENO se crea a mano con el `user_id` de otro dueño (es la combinación real en una base
 * compartida); lo PROPIO, por los endpoints reales.
 *
 * @group cheques
 */
class Edicion_acotada_Test extends ChequesTestCase
{
    /** El 404 de un cheque de la ruta ajeno, inexistente o que no es un id (el mismo de destroy). */
    const MENSAJE_404 = 'El cheque no existe o no es de tu cuenta.';

    /** El 422 de un banco que no es del catálogo de esta cuenta. */
    const MENSAJE_BANCO = 'El banco elegido no existe o no es de tu cuenta.';

    /** Un id que no existe en ninguna tabla de la base de testing (entra en un int con signo). */
    const CENTINELA = 2000000001;

    /**
     * Las columnas que la edición puede escribir: las cinco del pedido, más `banco` (el texto, que se
     * deriva del banco del catálogo) y `updated_at`. Todo lo que NO está acá tiene que quedar idéntico.
     */
    const COLUMNAS_QUE_PUEDEN_CAMBIAR = ['numero', 'banco', 'cheque_banco_id', 'notes', 'fecha_emision', 'fecha_pago', 'updated_at'];

    /** Lo que se replica al otro papel de un endoso: lo mismo, sin las notas. */
    const COLUMNAS_QUE_SE_REPLICAN = ['numero', 'banco', 'cheque_banco_id', 'fecha_emision', 'fecha_pago', 'updated_at'];

    /** @var User|null El otro comercio: un dueño (sin owner_id) que vive en la misma base. */
    protected $otro_dueno = null;

    /** @var array<int, int> Usuarios creados a mano por este test. */
    protected $usuarios_creados = [];

    protected function tearDown(): void
    {
        // El guard de sanctum cachea el usuario que resolvió: se olvida para no arrastrar nada.
        Auth::forgetGuards();

        if (count($this->usuarios_creados)) {
            // El alta de un dueño siembra sus medidas de etiqueta (UserEtiquetaMedidaObserver).
            EtiquetaMedida::whereIn('user_id', $this->usuarios_creados)->delete();
            User::whereIn('id', $this->usuarios_creados)->delete();
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------
    // Los casos (numerados como en el plan, §6)
    // ---------------------------------------------------------------------------------------------

    /**
     * 1. Los cinco campos juntos, con el modelo entero que manda la SPA.
     *
     * @test
     */
    public function edita_los_cinco_campos_juntos()
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta('Cliente edición ' . uniqid());
        $recibido = $this->cobrar_con_cheque($cliente, $cuenta, ['numero' => '1111', 'notes' => 'nota vieja']);
        $banco = $this->banco_propio('Banco Patagonia ' . uniqid());

        $payload = $this->payload_de_la_spa($recibido->id, [
            'numero'          => '  9988  ',
            'cheque_banco_id' => $banco->id,
            'notes'           => 'nota nueva',
            'fecha_emision'   => '2026-03-01',
            'fecha_pago'      => '2026-04-15',
        ]);

        $response = $this->putJson('api/cheque/' . $recibido->id, $payload);

        $this->assertSame(200, $response->getStatusCode(), $this->resumen($response));
        $this->assertSame($recibido->id, $response->json('model.id'));
        $this->assertSame('9988', $response->json('model.numero'));
        $this->assertSame($banco->name, $response->json('model.cheque_banco.name'), 'La respuesta trae el cheque con sus relaciones (withAll).');

        $fila = $this->fila($recibido->id);

        $this->assertSame('9988', $fila['numero'], 'El número se recorta con trim.');
        $this->assertSame((int) $banco->id, (int) $fila['cheque_banco_id']);
        $this->assertSame($banco->name, $fila['banco'], 'El texto legacy queda en sincronía con el banco elegido.');
        $this->assertSame('nota nueva', $fila['notes']);
        $this->assertSame('2026-03-01', $fila['fecha_emision']);
        $this->assertSame('2026-04-15', $fila['fecha_pago']);
    }

    /**
     * 2. EL CENTINELA. El mismo PUT con TODAS las columnas de `cheques` cambiadas a un valor hostil:
     * ninguna fuera de las cinco puede cambiar, y se compara la fila COMPLETA antes y después —la del
     * cheque editado y la de su par endosado—, en los dos sentidos (editando el recibido y editando
     * la copia emitida). Si alguien abre una columna más en el helper, o vuelca el pedido al modelo,
     * esto se pone rojo.
     *
     * @test
     */
    public function ninguna_columna_fuera_de_las_cinco_cambia_aunque_la_manden()
    {
        list($recibido, $copia) = $this->cheque_endosado_con_su_copia();

        // Estados que ensucian más columnas: el recibido endosado ya tiene `endosado_a_provider_id` y
        // `fecha_endoso`; se le suma una marca de rechazo y observaciones para que haya con qué comparar.
        DB::table('cheques')->where('id', $recibido->id)->update([
            'rechazado_en'            => '2026-01-02 03:04:05',
            'rechazado_por_id'        => $this->dueno->id,
            'rechazado_observaciones' => 7,
            'cobrado_en'              => '2026-01-03 03:04:05',
            'cobrado_por_id'          => $this->dueno->id,
        ]);

        $banco = $this->banco_propio('Banco centinela ' . uniqid());

        foreach ([[$recibido, $copia], [$copia, $recibido]] as $i => $par) {

            list($editado, $otro) = $par;

            $antes = $this->fila($editado->id);
            $antes_del_par = $this->fila($otro->id);

            $hostiles = $this->valores_hostiles($antes);

            // Todas las columnas están declaradas: una columna nueva en `cheques` rompe acá a propósito.
            $sin_declarar = array_values(array_diff(Schema::getColumnListing('cheques'), self::COLUMNAS_QUE_PUEDEN_CAMBIAR, array_keys($hostiles)));
            $this->assertSame([], $sin_declarar, 'Hay columnas de `cheques` que este test no pide con un valor hostil: ' . implode(', ', $sin_declarar) . '. Declaralas en valores_hostiles() (y decidí si la edición las puede tocar: casi seguro que no).');

            $legitimos = [
                'numero'          => 'EDITADO-' . $i,
                'cheque_banco_id' => $banco->id,
                'notes'           => 'notas editadas ' . $i,
                'fecha_emision'   => '2026-02-10',
                'fecha_pago'      => '2026-05-20',
            ];

            $payload = array_merge($this->payload_de_la_spa($editado->id, []), $hostiles, $legitimos);

            $response = $this->putJson('api/cheque/' . $editado->id, $payload);

            $this->assertSame(200, $response->getStatusCode(), 'vuelta ' . $i . ': ' . $this->resumen($response));

            $despues = $this->fila($editado->id);

            // Lo legítimo se aplicó (si el PUT no hiciera nada, el resto del test pasaría en falso).
            $this->assertSame('EDITADO-' . $i, $despues['numero'], 'vuelta ' . $i);
            $this->assertSame('notas editadas ' . $i, $despues['notes'], 'vuelta ' . $i);

            $this->assertSame(
                $this->sin($antes, self::COLUMNAS_QUE_PUEDEN_CAMBIAR),
                $this->sin($despues, self::COLUMNAS_QUE_PUEDEN_CAMBIAR),
                'vuelta ' . $i . ': el PUT cambió una columna de `cheques` que no es una de las cinco.'
            );

            // El par recibe número, banco y fechas, y nada más (tampoco las notas).
            $despues_del_par = $this->fila($otro->id);

            $this->assertSame('EDITADO-' . $i, $despues_del_par['numero'], 'vuelta ' . $i . ': el par tenía que recibir el número.');
            $this->assertSame($antes_del_par['notes'], $despues_del_par['notes'], 'vuelta ' . $i . ': las notas no se replican.');
            $this->assertSame(
                $this->sin($antes_del_par, self::COLUMNAS_QUE_SE_REPLICAN),
                $this->sin($despues_del_par, self::COLUMNAS_QUE_SE_REPLICAN),
                'vuelta ' . $i . ': el PUT cambió, en el par endosado, una columna que no es de las que se replican.'
            );
        }
    }

    /**
     * 3. Editar no mueve plata ni cuentas: la cuenta corriente del cliente, su saldo, las cajas, los
     * movimientos de caja, los otros cheques del dueño y los del otro comercio quedan idénticos
     * —aunque el pedido traiga un monto, un cliente o una caja distintos.
     *
     * @test
     */
    public function editar_no_toca_cuentas_corrientes_saldos_cajas_ni_otros_cheques()
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta('Cliente plata ' . uniqid());
        $cobrado = $this->cobrar_con_cheque($cliente, $cuenta, ['numero' => '3030']);
        $hermano = $this->cobrar_con_cheque($cliente, $cuenta, ['numero' => '3031']);
        $ajeno = $this->cheque_a_mano(['user_id' => $this->otro_dueno()->id, 'numero' => 'AJENO-' . substr(uniqid(), -6)]);

        $foto = function () use ($cuenta, $cliente, $ajeno, $hermano) {
            return [
                'cuenta_corriente' => CurrentAcount::where('credit_account_id', $cuenta->id)->orderBy('id')->get()->toArray(),
                'saldo_cuenta'     => round((float) DB::table('credit_accounts')->where('id', $cuenta->id)->value('saldo'), 2),
                'cliente'          => DB::table('clients')->where('id', $cliente->id)->first(),
                'cajas'            => Caja::orderBy('id')->get()->toArray(),
                'movimientos'      => MovimientoCaja::orderBy('id')->count(),
                'hermano'          => $this->fila($hermano->id),
                'ajeno'            => $this->fila($ajeno->id),
            ];
        };

        $antes = $foto();

        $payload = array_merge($this->payload_de_la_spa($cobrado->id, [
            'numero' => '3030-B',
            'notes'  => 'solo cambia el texto',
        ]), [
            'amount'      => 1,
            'client_id'   => $this->id_que_no_existe_en('clients'),
            'caja_id'     => Caja::value('id'),
            'tipo'        => 'emitido',
            'provider_id' => $this->id_que_no_existe_en('providers'),
        ]);

        $response = $this->putJson('api/cheque/' . $cobrado->id, $payload);

        $this->assertSame(200, $response->getStatusCode(), $this->resumen($response));
        $this->assertSame('3030-B', $this->fila($cobrado->id)['numero']);
        $this->assertEquals($antes, $foto(), 'Editar el cheque no puede mover cuentas corrientes, saldos, cajas ni otros cheques.');
    }

    /**
     * 4. Un cheque de otro dueño, uno inexistente o un id que no es un id: 404 con el mismo cuerpo
     * que destroy, y no se escribe nada en ninguna fila de la tabla.
     *
     * @test
     */
    public function un_cheque_ajeno_inexistente_o_basura_es_404_y_no_toca_nada()
    {
        $ajeno = $this->cheque_a_mano([
            'user_id' => $this->otro_dueno()->id,
            'numero'  => 'AJENO-' . substr(uniqid(), -6),
            'banco'   => 'Banco del otro comercio',
        ]);

        list($cliente, $cuenta) = $this->cliente_con_cuenta('Cliente 404 ' . uniqid());
        $propio = $this->cobrar_con_cheque($cliente, $cuenta, ['numero' => 'PROPIO-' . substr(uniqid(), -6)]);

        $foto = $this->foto_de_cheques();

        $cuerpo = ['numero' => 'PISADO', 'notes' => 'PISADO', 'fecha_pago' => '2030-01-01', 'cheque_banco_id' => 0];

        $ids = [
            'el cheque de otro comercio' => (string) $ajeno->id,
            'uno que no existe'          => (string) ($this->id_que_no_existe_en('cheques')),
            'un id con letras'           => $propio->id . 'abc',
            'un decimal'                 => $propio->id . '.5',
            'texto'                      => 'abc',
            'un negativo'                => '-1',
            'cero'                       => '0',
        ];

        foreach ($ids as $nombre => $id) {

            $response = $this->putJson('api/cheque/' . $id, $cuerpo);

            $this->assertSame(404, $response->getStatusCode(), $nombre . ': ' . $this->resumen($response));
            $this->assertSame(self::MENSAJE_404, $response->json('message'), $nombre);
            $this->assertStringNotContainsString($ajeno->numero, $response->getContent(), $nombre . ': la respuesta no puede devolver datos del cheque ajeno.');
        }

        $this->assertSame($foto, $this->foto_de_cheques(), 'Ningún cheque de la base cambió: ni el ajeno ni el "12abc" que parece el 12.');
    }

    /**
     * 5a. El banco del dueño: el id y el texto legacy quedan en sincronía, y el `banco` que mande el
     * formulario (el texto viejo, que viaja al lado) no se lee.
     *
     * @test
     */
    public function el_banco_del_dueno_actualiza_el_id_y_el_texto()
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta('Cliente banco ' . uniqid());
        $recibido = $this->cobrar_con_cheque($cliente, $cuenta, ['banco' => 'Banco Nación']);
        $banco = $this->banco_propio('Banco Provincia ' . uniqid());

        $response = $this->putJson('api/cheque/' . $recibido->id, $this->payload_de_la_spa($recibido->id, [
            'cheque_banco_id' => (string) $banco->id,
            'banco'           => 'TEXTO QUE EL FORMULARIO NO PUEDE ELEGIR',
        ]));

        $this->assertSame(200, $response->getStatusCode(), $this->resumen($response));

        $fila = $this->fila($recibido->id);

        $this->assertSame((int) $banco->id, (int) $fila['cheque_banco_id']);
        $this->assertSame($banco->name, $fila['banco']);
        $this->assertSame($banco->name, $response->json('model.banco'));
    }

    /**
     * 5b. Un banco ajeno, uno que no existe o un valor que no es un id: 422 y NO se escribe nada, ni
     * siquiera los otros campos del mismo pedido que eran válidos.
     *
     * @test
     */
    public function un_banco_ajeno_o_inexistente_es_422_y_no_escribe_nada()
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta('Cliente banco ajeno ' . uniqid());
        $recibido = $this->cobrar_con_cheque($cliente, $cuenta, ['banco' => 'Banco Nación']);
        $ajeno = ChequeBanco::create(['name' => 'Banco ajeno ' . uniqid(), 'user_id' => $this->otro_dueno()->id]);

        $antes = $this->fila($recibido->id);
        $foto = $this->foto_de_cheques();

        $valores = [
            'el banco de otro comercio' => $ajeno->id,
            'uno que no existe'         => $this->id_que_no_existe_en('cheque_bancos'),
            'un id con letras'          => $ajeno->id . 'abc',
            'true'                      => true,
            'un array'                  => [1],
        ];

        foreach ($valores as $nombre => $valor) {

            $response = $this->putJson('api/cheque/' . $recibido->id, $this->payload_de_la_spa($recibido->id, [
                'cheque_banco_id' => $valor,
                'numero'          => 'NO-DEBE-ESCRIBIRSE',
                'notes'           => 'NO-DEBE-ESCRIBIRSE',
                'fecha_pago'      => '2031-01-01',
            ]));

            $this->assertSame(422, $response->getStatusCode(), $nombre . ': ' . $this->resumen($response));
            $this->assertSame(self::MENSAJE_BANCO, $response->json('message'), $nombre);
        }

        $this->assertSame($antes, $this->fila($recibido->id), 'Un banco inválido no deja escribir nada, ni los campos válidos.');
        $this->assertSame($foto, $this->foto_de_cheques());
        $this->assertSame('Banco Nación', $this->fila($recibido->id)['banco']);
    }

    /**
     * 5c. Vacío con banco previo del catálogo = "sin banco": el id y el texto quedan en null.
     *
     * @test
     */
    public function banco_vacio_con_banco_previo_deja_el_cheque_sin_banco()
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta('Cliente sin banco ' . uniqid());
        $banco = $this->banco_propio('Banco Credicoop ' . uniqid());
        $recibido = $this->cobrar_con_cheque($cliente, $cuenta, ['cheque_banco_id' => $banco->id, 'banco' => $banco->name]);

        $this->assertSame((int) $banco->id, (int) $this->fila($recibido->id)['cheque_banco_id'], 'El escenario arranca con banco del catálogo.');

        foreach (['null' => null, 'texto vacío' => '', 'cero' => 0, 'cero como texto' => '0'] as $nombre => $vacio) {

            DB::table('cheques')->where('id', $recibido->id)->update(['cheque_banco_id' => $banco->id, 'banco' => $banco->name]);

            $response = $this->putJson('api/cheque/' . $recibido->id, $this->payload_de_la_spa($recibido->id, ['cheque_banco_id' => $vacio]));

            $this->assertSame(200, $response->getStatusCode(), $nombre . ': ' . $this->resumen($response));

            $fila = $this->fila($recibido->id);

            $this->assertNull($fila['cheque_banco_id'], $nombre);
            $this->assertNull($fila['banco'], $nombre . ': el texto también se vacía.');
        }
    }

    /**
     * 5d. Vacío SIN banco del catálogo previo (un cheque viejo que solo tiene el texto): el formulario
     * manda lo mismo que ya había y el texto legacy no se pierde.
     *
     * @test
     */
    public function banco_vacio_sin_banco_previo_conserva_el_texto_legacy()
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta('Cliente banco viejo ' . uniqid());
        $recibido = $this->cobrar_con_cheque($cliente, $cuenta, ['banco' => 'Banco Nación']);

        $this->assertNull($this->fila($recibido->id)['cheque_banco_id'], 'El escenario arranca sin banco del catálogo.');

        foreach ([0, null, '', '0'] as $vacio) {

            $response = $this->putJson('api/cheque/' . $recibido->id, $this->payload_de_la_spa($recibido->id, [
                'cheque_banco_id' => $vacio,
                'numero'          => 'VIEJO-1',
            ]));

            $this->assertSame(200, $response->getStatusCode(), $this->resumen($response));

            $fila = $this->fila($recibido->id);

            $this->assertSame('Banco Nación', $fila['banco'], 'El texto legacy no se pierde.');
            $this->assertNull($fila['cheque_banco_id']);
            $this->assertSame('VIEJO-1', $fila['numero'], 'Lo demás del pedido sí se aplica.');
        }
    }

    /**
     * 6a. Las fechas se toman por sus primeros 10 caracteres, sin zona horaria: un ISO datetime guarda
     * el día que dice, no el que sale de convertirlo a otra zona.
     *
     * @test
     */
    public function una_fecha_iso_con_hora_guarda_el_dia_que_dice()
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta('Cliente fechas ' . uniqid());
        $recibido = $this->cobrar_con_cheque($cliente, $cuenta);

        $casos = [
            ['2026-05-10T23:30:00-03:00', '2026-05-10'],
            ['2026-05-11T02:30:00.000Z',  '2026-05-11'],
            ['2026-05-12 14:00:00',       '2026-05-12'],
            ['2026-05-13',                '2026-05-13'],
            ['  2026-05-14  ',            '2026-05-14'],
        ];

        foreach ($casos as $caso) {

            list($entrada, $esperada) = $caso;

            $response = $this->putJson('api/cheque/' . $recibido->id, $this->payload_de_la_spa($recibido->id, [
                'fecha_emision' => $entrada,
                'fecha_pago'    => $entrada,
            ]));

            $this->assertSame(200, $response->getStatusCode(), $entrada . ': ' . $this->resumen($response));

            $fila = $this->fila($recibido->id);

            $this->assertSame($esperada, $fila['fecha_emision'], $entrada);
            $this->assertSame($esperada, $fila['fecha_pago'], $entrada);
        }
    }

    /**
     * 6b. Una fecha inválida es 422 y no se escribe NADA (ni los otros campos del pedido).
     *
     * @test
     */
    public function una_fecha_invalida_es_422_y_no_escribe_nada()
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta('Cliente fecha mala ' . uniqid());
        $recibido = $this->cobrar_con_cheque($cliente, $cuenta);

        $antes = $this->fila($recibido->id);

        $invalidas = [
            '2026-02-31',
            '2026-13-01',
            '2026-00-10',
            'abc',
            '10/05/2026',
            '2026-5-1',
            '2026-05-10garbage',
            '0001-01-01',
            12345,
            true,
            ['2026-05-10'],
        ];

        foreach (['fecha_emision', 'fecha_pago'] as $clave) {

            foreach ($invalidas as $invalida) {

                $response = $this->putJson('api/cheque/' . $recibido->id, $this->payload_de_la_spa($recibido->id, [
                    $clave   => $invalida,
                    'numero' => 'NO-DEBE-ESCRIBIRSE',
                    'notes'  => 'NO-DEBE-ESCRIBIRSE',
                ]));

                $etiqueta = $clave . ' = ' . json_encode($invalida);

                $this->assertSame(422, $response->getStatusCode(), $etiqueta . ': ' . $this->resumen($response));
                $this->assertStringContainsString('fecha', mb_strtolower($response->json('message')), $etiqueta);
                $this->assertSame($antes, $this->fila($recibido->id), $etiqueta . ': no se escribe nada.');
            }
        }
    }

    /**
     * 6c. Una fecha null (o vacía) vacía el campo.
     *
     * @test
     */
    public function una_fecha_null_o_vacia_vacia_el_campo()
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta('Cliente fecha null ' . uniqid());
        $recibido = $this->cobrar_con_cheque($cliente, $cuenta);

        $response = $this->putJson('api/cheque/' . $recibido->id, $this->payload_de_la_spa($recibido->id, ['fecha_emision' => null, 'fecha_pago' => '']));

        $this->assertSame(200, $response->getStatusCode(), $this->resumen($response));

        $fila = $this->fila($recibido->id);

        $this->assertNull($fila['fecha_emision']);
        $this->assertNull($fila['fecha_pago']);
    }

    /**
     * 7. Una clave AUSENTE del pedido no se toca: un PUT de un solo campo deja los otros cuatro (y
     * todo lo demás) como estaban, y un PUT vacío no escribe nada. Una clave presente con null SÍ
     * vacía el campo.
     *
     * @test
     */
    public function una_clave_ausente_no_se_toca()
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta('Cliente claves ' . uniqid());
        $banco = $this->banco_propio('Banco claves ' . uniqid());
        $recibido = $this->cobrar_con_cheque($cliente, $cuenta, [
            'numero'          => 'CLAVES-1',
            'cheque_banco_id' => $banco->id,
            'banco'           => $banco->name,
            'notes'           => 'notas de partida',
        ]);

        $otro_banco = $this->banco_propio('Otro banco claves ' . uniqid());

        $de_a_uno = [
            'numero'          => ['CLAVES-2', 'numero'],
            'notes'           => ['notas nuevas', 'notes'],
            'fecha_emision'   => ['2026-06-01', 'fecha_emision'],
            'fecha_pago'      => ['2026-07-01', 'fecha_pago'],
            'cheque_banco_id' => [$otro_banco->id, 'cheque_banco_id'],
        ];

        foreach ($de_a_uno as $clave => $dato) {

            $antes = $this->fila($recibido->id);

            $response = $this->putJson('api/cheque/' . $recibido->id, [$clave => $dato[0]]);

            $this->assertSame(200, $response->getStatusCode(), $clave . ': ' . $this->resumen($response));

            $despues = $this->fila($recibido->id);

            $cambiables = $clave === 'cheque_banco_id' ? ['cheque_banco_id', 'banco', 'updated_at'] : [$clave, 'updated_at'];

            $this->assertSame($this->sin($antes, $cambiables), $this->sin($despues, $cambiables), $clave . ': un PUT de un solo campo tocó otra columna.');
            $this->assertEquals($dato[0], $despues[$dato[1]], $clave . ': el campo pedido sí cambia.');
        }

        // Un PUT sin ninguna de las cinco claves (ni siquiera el cuerpo) no escribe nada.
        $antes = $this->fila($recibido->id);

        $this->assertSame(200, $this->putJson('api/cheque/' . $recibido->id, [])->getStatusCode());
        $this->assertSame(200, $this->putJson('api/cheque/' . $recibido->id, ['amount' => 5, 'client_id' => 3])->getStatusCode());
        $this->assertSame($antes, $this->fila($recibido->id), 'Sin ninguna de las cinco claves no se escribe nada (ni updated_at).');

        // Presente y null: vacía.
        $response = $this->putJson('api/cheque/' . $recibido->id, ['numero' => null, 'notes' => null]);

        $this->assertSame(200, $response->getStatusCode(), $this->resumen($response));
        $this->assertNull($this->fila($recibido->id)['numero']);
        $this->assertNull($this->fila($recibido->id)['notes']);

        // Un número de más de 191 caracteres (el largo real de la columna) es 422 y no escribe nada.
        $antes = $this->fila($recibido->id);

        $response = $this->putJson('api/cheque/' . $recibido->id, ['numero' => str_repeat('9', 192), 'notes' => 'NO-DEBE-ESCRIBIRSE']);

        $this->assertSame(422, $response->getStatusCode(), $this->resumen($response));
        $this->assertSame($antes, $this->fila($recibido->id));

        $response = $this->putJson('api/cheque/' . $recibido->id, ['numero' => str_repeat('9', 191)]);

        $this->assertSame(200, $response->getStatusCode(), $this->resumen($response));
    }

    /**
     * 8. Un recibido endosado y su copia emitida son el mismo papel: número, banco y fechas se
     * replican al otro en los dos sentidos, las notas NO, la copia de otro dueño no se toca y
     * tampoco ningún cheque sin relación.
     *
     * @test
     */
    public function un_cheque_endosado_replica_al_otro_papel_todo_menos_las_notas()
    {
        list($recibido, $copia) = $this->cheque_endosado_con_su_copia(['notes' => 'nota original']);

        $this->assertSame('nota original', $this->fila($copia->id)['notes'], 'El endoso copia las notas al nacer: de ahí en más cada lado anota lo suyo.');

        // Una copia "emitida" de otro comercio atada al mismo recibido, y un cheque propio sin relación.
        $copia_ajena = $this->cheque_a_mano([
            'user_id'                  => $this->otro_dueno()->id,
            'tipo'                     => 'emitido',
            'numero'                   => 'COPIA-AJENA',
            'endosado_desde_cheque_id' => $recibido->id,
        ]);
        $suelto = $this->cheque_a_mano(['tipo' => 'emitido', 'numero' => 'SUELTO']);

        $banco = $this->banco_propio('Banco endoso ' . uniqid());

        // --- Editando el RECIBIDO ------------------------------------------------------------------
        $ajeno_antes = $this->fila($copia_ajena->id);
        $suelto_antes = $this->fila($suelto->id);

        $response = $this->putJson('api/cheque/' . $recibido->id, $this->payload_de_la_spa($recibido->id, [
            'numero'          => 'DESDE-RECIBIDO',
            'cheque_banco_id' => $banco->id,
            'fecha_emision'   => '2026-08-01',
            'fecha_pago'      => '2026-09-01',
            'notes'           => 'nota del recibido',
        ]));

        $this->assertSame(200, $response->getStatusCode(), $this->resumen($response));

        $fila_copia = $this->fila($copia->id);

        $this->assertSame('DESDE-RECIBIDO', $fila_copia['numero']);
        $this->assertSame((int) $banco->id, (int) $fila_copia['cheque_banco_id']);
        $this->assertSame($banco->name, $fila_copia['banco']);
        $this->assertSame('2026-08-01', $fila_copia['fecha_emision']);
        $this->assertSame('2026-09-01', $fila_copia['fecha_pago']);
        $this->assertSame('nota original', $fila_copia['notes'], 'Las notas no se replican al par.');
        $this->assertSame('nota del recibido', $this->fila($recibido->id)['notes']);

        $this->assertSame($ajeno_antes, $this->fila($copia_ajena->id), 'La copia de otro dueño no se toca.');
        $this->assertSame($suelto_antes, $this->fila($suelto->id), 'Un cheque sin relación no se toca.');

        // --- Editando la COPIA EMITIDA (al revés) ---------------------------------------------------
        $response = $this->putJson('api/cheque/' . $copia->id, $this->payload_de_la_spa($copia->id, [
            'numero'        => 'DESDE-COPIA',
            'fecha_pago'    => '2026-10-02',
            'notes'         => 'nota de la copia',
        ]));

        $this->assertSame(200, $response->getStatusCode(), $this->resumen($response));

        $fila_recibido = $this->fila($recibido->id);

        $this->assertSame('DESDE-COPIA', $fila_recibido['numero']);
        $this->assertSame('2026-10-02', $fila_recibido['fecha_pago']);
        $this->assertSame('nota del recibido', $fila_recibido['notes'], 'Las notas de la copia no suben al recibido.');
        $this->assertSame('nota de la copia', $this->fila($copia->id)['notes']);

        $this->assertSame($ajeno_antes, $this->fila($copia_ajena->id));
        $this->assertSame($suelto_antes, $this->fila($suelto->id));

        // --- Y "sin banco" también viaja al par ------------------------------------------------------
        $response = $this->putJson('api/cheque/' . $recibido->id, $this->payload_de_la_spa($recibido->id, ['cheque_banco_id' => 0]));

        $this->assertSame(200, $response->getStatusCode(), $this->resumen($response));
        $this->assertNull($this->fila($recibido->id)['cheque_banco_id']);
        $this->assertNull($this->fila($copia->id)['cheque_banco_id']);
        $this->assertNull($this->fila($copia->id)['banco']);
    }

    /**
     * 9. Se edita en cualquier estado: cobrado, rechazado, endosado y emitido pagado. El estado y sus
     * marcas no cambian.
     *
     * @test
     */
    public function se_puede_editar_un_cheque_cobrado_rechazado_o_pagado()
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta('Cliente estados ' . uniqid());

        $cobrado = $this->cobrar_con_cheque($cliente, $cuenta, ['numero' => 'EST-COBRADO']);
        $this->assertSame(200, $this->putJson('api/cheque/cobrar', ['cheque_id' => $cobrado->id, 'caja_id' => 0])->getStatusCode());

        $rechazado = $this->cobrar_con_cheque($cliente, $cuenta, ['numero' => 'EST-RECHAZADO']);
        $this->assertSame(200, $this->putJson('api/cheque/rechazar', ['cheque_id' => $rechazado->id, 'notas' => 'Sin fondos'])->getStatusCode());

        $emitido = $this->cheque_a_mano(['tipo' => 'emitido', 'numero' => 'EST-PAGADO']);
        $this->assertSame(200, $this->putJson('api/cheque/pagar', ['cheque_id' => $emitido->id, 'caja_id' => 0])->getStatusCode());

        list($endosado, $copia) = $this->cheque_endosado_con_su_copia();

        $casos = [
            'cobrado'   => [$cobrado, 'cobrado'],
            'rechazado' => [$rechazado, 'rechazado'],
            'pagado'    => [$emitido, 'cobrado'],
            'endosado'  => [$endosado, null],
        ];

        foreach ($casos as $nombre => $caso) {

            list($cheque, $estado) = $caso;

            $antes = $this->fila($cheque->id);

            $response = $this->putJson('api/cheque/' . $cheque->id, $this->payload_de_la_spa($cheque->id, [
                'numero' => 'EDITADO-' . $nombre,
                'notes'  => 'editado en estado ' . $nombre,
            ]));

            $this->assertSame(200, $response->getStatusCode(), $nombre . ': ' . $this->resumen($response));

            $despues = $this->fila($cheque->id);

            $this->assertSame('EDITADO-' . $nombre, $despues['numero'], $nombre);
            $this->assertSame($estado, $despues['estado_manual'], $nombre . ': el estado no cambia.');
            $this->assertSame($this->sin($antes, self::COLUMNAS_QUE_PUEDEN_CAMBIAR), $this->sin($despues, self::COLUMNAS_QUE_PUEDEN_CAMBIAR), $nombre);
        }
    }

    /**
     * 10. Cambiar `fecha_pago` mueve el cheque de solapa en `GET cheque`: es lo esperado.
     *
     * @test
     */
    public function cambiar_la_fecha_de_pago_cambia_la_solapa_del_listado()
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta('Cliente solapas ' . uniqid());
        $recibido = $this->cobrar_con_cheque($cliente, $cuenta, [
            'fecha_emision' => Carbon::today()->format('Y-m-d'),
            'fecha_pago'    => Carbon::today()->addDays(20)->format('Y-m-d'),
        ]);

        $this->assertSame('recibido.pendientes', $this->solapa_de($recibido->id), 'El escenario arranca pendiente.');

        // [días respecto de hoy, solapa]: la ventana de cobro es fecha_pago + 30 días y "pronto a
        // vencerse" son los 3 últimos (ChequeController::index()).
        $casos = [
            [-10, 'recibido.disponibles_para_cobrar'],
            [-29, 'recibido.pronto_a_vencerse'],
            [-60, 'recibido.vencidos'],
            [15,  'recibido.pendientes'],
        ];

        foreach ($casos as $caso) {

            list($dias, $solapa) = $caso;

            $response = $this->putJson('api/cheque/' . $recibido->id, $this->payload_de_la_spa($recibido->id, [
                'fecha_pago' => Carbon::today()->addDays($dias)->format('Y-m-d'),
            ]));

            $this->assertSame(200, $response->getStatusCode(), $this->resumen($response));
            $this->assertSame($solapa, $this->solapa_de($recibido->id), 'fecha_pago a ' . $dias . ' días de hoy.');
        }
    }

    /**
     * 11. La ruta nueva no se come a las fijas: `PUT cheque/{id}` va DESPUÉS de cobrar, pagar,
     * rechazar y endosar, y `{id}` no las captura. Se mira el router, y se prueba una de punta a punta.
     *
     * @test
     */
    public function la_ruta_nueva_no_rompe_a_las_fijas()
    {
        $router = app('router');

        foreach (['cobrar', 'pagar', 'rechazar', 'endosar'] as $accion) {

            $ruta = $router->getRoutes()->match(Request::create('/api/cheque/' . $accion, 'PUT'));

            $this->assertSame('App\Http\Controllers\ChequeController@' . $accion, $ruta->getActionName(), 'PUT cheque/' . $accion . ' tiene que seguir yendo a ' . $accion . '().');
        }

        $ruta = $router->getRoutes()->match(Request::create('/api/cheque/15', 'PUT'));
        $this->assertSame('App\Http\Controllers\ChequeController@update', $ruta->getActionName());

        $ruta = $router->getRoutes()->match(Request::create('/api/cheque/15', 'DELETE'));
        $this->assertSame('App\Http\Controllers\ChequeController@destroy', $ruta->getActionName());

        // De punta a punta: cobrar sigue marcando el cheque (un update() daría 404: 'cobrar' no es un id).
        list($cliente, $cuenta) = $this->cliente_con_cuenta('Cliente rutas ' . uniqid());
        $recibido = $this->cobrar_con_cheque($cliente, $cuenta);

        $response = $this->putJson('api/cheque/cobrar', ['cheque_id' => $recibido->id, 'caja_id' => 0]);

        $this->assertSame(200, $response->getStatusCode(), $this->resumen($response));
        $this->assertSame('cobrado', $response->json('model.estado_manual'));
        $this->assertSame('cobrado', $this->fila($recibido->id)['estado_manual']);
    }

    // ---------------------------------------------------------------------------------------------
    // Ayudantes
    // ---------------------------------------------------------------------------------------------

    /**
     * El modelo ENTERO del cheque tal como lo tiene la SPA en `this.model` (lo que devuelve
     * `GET cheque`, con sus relaciones), con los cambios del usuario encima.
     *
     * @param int $cheque_id
     * @param array $cambios
     * @return array
     */
    protected function payload_de_la_spa($cheque_id, array $cambios)
    {
        $modelo = $this->cheque_del_listado($cheque_id);

        $this->assertNotNull($modelo, 'El cheque ' . $cheque_id . ' tenía que estar en GET cheque.');

        return array_merge($modelo, $cambios);
    }

    /**
     * Un recibido cobrado a un cliente y endosado a un proveedor por el endpoint real: devuelve el
     * recibido (ya marcado como endosado) y su copia emitida.
     *
     * @param array $cheque Claves del cheque a pisar al cobrarlo (numero, notes...).
     * @return array{0: Cheque, 1: Cheque}
     */
    protected function cheque_endosado_con_su_copia(array $cheque = [])
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente endoso ' . uniqid());
        list($proveedor) = $this->proveedor_con_cuenta('Proveedor endoso ' . uniqid(), self::DEUDA_PROVEEDOR);

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, array_merge(['numero' => 'ENDO-' . substr(uniqid(), -6)], $cheque));

        $response = $this->putJson('api/cheque/endosar', ['cheque_id' => $recibido->id, 'provider_id' => $proveedor->id]);

        $this->assertSame(200, $response->getStatusCode(), 'El endoso del escenario: ' . $this->resumen($response));

        $copia = $this->copias_de($recibido)->first();

        $this->assertNotNull($copia, 'El endoso tenía que dejar la copia emitida.');

        $this->cobros_cc_creados_por_escenarios[] = (int) $copia->current_acount_id;

        return [$recibido->fresh(), $copia];
    }

    /**
     * Un valor hostil para CADA columna de `cheques` que la edición NO puede tocar, distinto del que
     * tiene la fila. Si una columna cambia después del PUT, este valor es el que se coló.
     *
     * @param array $fila La fila actual del cheque que se va a editar.
     * @return array<string, mixed>
     */
    protected function valores_hostiles(array $fila)
    {
        return [
            'id'                       => (int) $fila['id'] + 5000,
            'amount'                   => 987654.32,
            'tipo'                     => $fila['tipo'] === 'recibido' ? 'emitido' : 'recibido',
            'client_id'                => self::CENTINELA,
            'provider_id'              => self::CENTINELA,
            'current_acount_id'        => self::CENTINELA,
            'expense_id'               => self::CENTINELA,
            'employee_id'              => self::CENTINELA,
            'user_id'                  => $this->otro_dueno()->id,
            'caja_id'                  => self::CENTINELA,
            'endosado_a_provider_id'   => self::CENTINELA,
            'endosado_en_expense_id'   => self::CENTINELA,
            'fecha_endoso'             => '2001-01-01 00:00:00',
            'estado_manual'            => $fila['estado_manual'] === 'rechazado' ? 'cobrado' : 'rechazado',
            'cobrado_en'               => '2001-01-01 00:00:00',
            'rechazado_en'             => '2001-01-01 00:00:00',
            'cobrado_por_id'           => self::CENTINELA,
            'rechazado_por_id'         => self::CENTINELA,
            'rechazado_observaciones'  => self::CENTINELA,
            'es_echeq'                 => (int) $fila['es_echeq'] === 1 ? 0 : 1,
            'created_at'               => '2001-01-01 00:00:00',
            'endosado_desde_client_id' => self::CENTINELA,
            'endosado_desde_cheque_id' => self::CENTINELA,
        ];
    }

    /**
     * La fila cruda de un cheque (sin modelo ni casts: las fechas como texto), o null.
     *
     * @param int $id
     * @return array|null
     */
    protected function fila($id)
    {
        $fila = DB::table('cheques')->where('id', $id)->first();

        return is_null($fila) ? null : (array) $fila;
    }

    /**
     * La fila sin las columnas dadas.
     *
     * @param array $fila
     * @param array<int, string> $columnas
     * @return array
     */
    protected function sin(array $fila, array $columnas)
    {
        return array_diff_key($fila, array_flip($columnas));
    }

    /**
     * Todas las filas de `cheques`, para comparar antes y después.
     *
     * @return array
     */
    protected function foto_de_cheques()
    {
        return DB::table('cheques')->orderBy('id')->get()->map(function ($fila) {
            return (array) $fila;
        })->all();
    }

    /**
     * Un id más allá del máximo de la tabla (contando los borrados lógicos): no existe.
     *
     * @param string $tabla
     * @return int
     */
    protected function id_que_no_existe_en($tabla)
    {
        return (int) DB::table($tabla)->max('id') + 1000;
    }

    /**
     * Un banco del catálogo del dueño.
     *
     * @param string $nombre
     * @return ChequeBanco
     */
    protected function banco_propio($nombre)
    {
        return ChequeBanco::create(['name' => $nombre, 'user_id' => $this->dueno->id]);
    }

    /**
     * El otro comercio: un dueño sin owner_id, creado una vez por test.
     *
     * @return User
     */
    protected function otro_dueno()
    {
        if (is_null($this->otro_dueno)) {

            $this->otro_dueno = User::create([
                'name'     => 'Otro comercio edición acotada',
                'email'    => 'cheques-edicion-otro-' . uniqid() . '@test.local',
                'password' => Hash::make('secret'),
                'owner_id' => null,
            ]);

            $this->usuarios_creados[] = $this->otro_dueno->id;
        }

        return $this->otro_dueno;
    }

    /**
     * Estado y comienzo del cuerpo de una respuesta, para los mensajes de falla.
     *
     * @param \Illuminate\Testing\TestResponse $response
     * @return string
     */
    protected function resumen($response)
    {
        return $response->getStatusCode() . ' ' . mb_substr((string) $response->getContent(), 0, 300);
    }
}
