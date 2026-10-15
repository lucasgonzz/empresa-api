<?php

namespace Tests\Feature\Tienda;

use App\Http\Controllers\Helpers\CuotasTiendaEsquemaHelper;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodType;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use Tests\EmpresaTestCase;

/**
 * Misión "tienda-cuotas-mercado-pago" (15/10/2026), parte de `empresa-api`: el vínculo entre el
 * método de pago de la TIENDA (tipo MercadoPago) y un método de pago de CUENTA CORRIENTE.
 *
 * El vínculo vive en `payment_methods.current_acount_payment_method_id` (migración
 * `2026_10_15_100000`) y `tienda-api` lo lee de la base compartida: las filas de `cuotas` cargadas
 * para ese método de cuenta corriente son las cuotas que se le ofrecen al comprador.
 *
 * Lo que fija este archivo son las dos cosas que no se pueden romper:
 *
 * 1. 🔴 Una edición que NO trae el campo (la SPA vieja, o cualquier otro cliente de la API) no
 *    puede borrar el vínculo: la tienda perdería sus cuotas sin que nadie toque nada. Es el mismo
 *    patrón que `access_token` en `PaymentMethodController@update`.
 * 2. 🔴 Sin la columna (un deploy sube los archivos ANTES de migrar) el controlador se comporta
 *    EXACTAMENTE como antes: no escribe el campo y no lo menciona en ninguna consulta. Si no, el
 *    alta y la edición de métodos de pago de todos los clientes mueren con `Unknown column`.
 *
 * ⚠️ NO hay DDL en este archivo: un ALTER/DROP COLUMN hace commit implícito en MySQL y rompe el
 * aislamiento de `DatabaseTransactions`. El "sin columna" se simula forzando la memoización del
 * helper de esquema (ver `sin_la_columna()`) y se verifica mirando el SQL que sale.
 *
 * @group tienda
 * @group tienda_cuotas
 */
class VinculoMetodoDePagoConCuentaCorrienteTest extends EmpresaTestCase
{
    /** Ruta del ABM de métodos de pago de la tienda. */
    const RUTA = '/api/payment-method';

    /** La columna del vínculo, tal cual está en la base. */
    const COLUMNA = 'current_acount_payment_method_id';

    /**
     * Deja la memoización de la guarda limpia antes y después de cada test: el memo es estático y
     * sin esto un test que la fuerza a "sin columna" se le filtraría a los que corren después en el
     * mismo proceso de PHPUnit.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        CuotasTiendaEsquemaHelper::olvidar();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        CuotasTiendaEsquemaHelper::olvidar();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------
    // Alta
    // ---------------------------------------------------------------------------------------------

    /**
     * El alta con el vínculo lo guarda y la respuesta lo devuelve (la SPA lo muestra en el select).
     */
    public function test_el_alta_guarda_el_vinculo_y_la_respuesta_lo_devuelve()
    {
        $cuenta_corriente = $this->metodo_de_cuenta_corriente('Mercado Pago (cta cte)');

        $respuesta = $this->postJson(self::RUTA, $this->payload_de_alta([
            self::COLUMNA => $cuenta_corriente->id,
        ]));

        $respuesta->assertStatus(201);

        $id = $respuesta->json('model.id');

        $this->assertSame(
            $cuenta_corriente->id,
            (int) $respuesta->json('model.'.self::COLUMNA),
            'La respuesta del alta no devuelve el vínculo: la SPA no podría mostrar qué método quedó elegido.'
        );
        $this->assertSame($cuenta_corriente->id, $this->vinculo_guardado($id));
    }

    /**
     * Un alta que no manda el campo (la SPA vieja) deja el vínculo en NULL: sin vínculo no hay
     * cuotas en la tienda, todo como siempre.
     */
    public function test_el_alta_sin_el_campo_deja_el_vinculo_en_null()
    {
        $respuesta = $this->postJson(self::RUTA, $this->payload_de_alta());

        $respuesta->assertStatus(201);

        $this->assertNull($this->vinculo_guardado($respuesta->json('model.id')));
    }

    /**
     * El select de la SPA ofrece «Seleccione ...» con valor 0: el alta con 0 no guarda un 0 (que
     * apuntaría a un método que no existe), guarda NULL.
     */
    public function test_el_alta_con_cero_del_select_vacio_deja_null_y_no_un_cero()
    {
        $respuesta = $this->postJson(self::RUTA, $this->payload_de_alta([self::COLUMNA => 0]));

        $respuesta->assertStatus(201);

        $this->assertNull($this->vinculo_guardado($respuesta->json('model.id')));
    }

    // ---------------------------------------------------------------------------------------------
    // Edición
    // ---------------------------------------------------------------------------------------------

    /**
     * La edición con el campo cambia el vínculo.
     */
    public function test_la_edicion_con_el_campo_cambia_el_vinculo()
    {
        $primero  = $this->metodo_de_cuenta_corriente('Cta cte A');
        $segundo  = $this->metodo_de_cuenta_corriente('Cta cte B');
        $metodo   = $this->metodo_de_tienda($primero->id);

        $this->putJson(self::RUTA.'/'.$metodo->id, $this->payload_de_edicion($metodo, [
            self::COLUMNA => $segundo->id,
        ]))->assertStatus(200)->assertJsonPath('model.'.self::COLUMNA, $segundo->id);

        $this->assertSame($segundo->id, $this->vinculo_guardado($metodo->id));
    }

    /**
     * 🔴 EL TEST DE LA MISIÓN EN ESTE LADO. Editar sin mandar el campo —exactamente lo que hace una
     * SPA vieja que no conoce el vínculo— NO lo borra.
     *
     * Si `PaymentMethodController@update` asignara el campo sin `has()`, cambiarle el nombre o el
     * descuento a un método de pago ya vinculado dejaría `current_acount_payment_method_id` en NULL y
     * la tienda dejaría de ofrecer sus cuotas, en producción, sin que nadie toque nada de cuotas.
     */
    public function test_editar_con_un_payload_viejo_sin_el_campo_no_borra_el_vinculo()
    {
        $cuenta_corriente = $this->metodo_de_cuenta_corriente('Cta cte que no se puede perder');
        $metodo           = $this->metodo_de_tienda($cuenta_corriente->id);

        // Lo que manda la SPA vieja: los campos comerciales y nada del vínculo.
        $respuesta = $this->putJson(self::RUTA.'/'.$metodo->id, $this->payload_de_edicion($metodo, [
            'name'     => 'MercadoPago renombrado',
            'discount' => 7,
        ]));

        $respuesta->assertStatus(200);

        $metodo->refresh();

        $this->assertSame('MercadoPago renombrado', $metodo->name, 'La edición no guardó el nombre.');
        $this->assertSame(
            $cuenta_corriente->id,
            (int) $metodo->{self::COLUMNA},
            'Editar sin mandar el campo BORRÓ el vínculo: la asignación de PaymentMethodController@update '.
            'tiene que ser condicional (has) o una SPA vieja deja a la tienda sin cuotas al primer cambio de nombre.'
        );
        $this->assertSame(
            $cuenta_corriente->id,
            (int) $respuesta->json('model.'.self::COLUMNA),
            'La respuesta de la edición tiene que seguir devolviendo el vínculo que no se tocó.'
        );
    }

    /**
     * Mandar el campo vacío o en 0 a propósito SÍ desvincula (es lo que hace el dueño al elegir
     * «Seleccione ...» en el select): `has()` y no `filled()`, igual que con `access_token`.
     */
    public function test_mandar_el_campo_en_cero_o_vacio_a_proposito_si_desvincula()
    {
        $cuenta_corriente = $this->metodo_de_cuenta_corriente('Cta cte a desvincular');

        foreach ([0, '', null, '0'] as $valor_de_desvinculo) {

            $metodo = $this->metodo_de_tienda($cuenta_corriente->id);

            $this->putJson(self::RUTA.'/'.$metodo->id, $this->payload_de_edicion($metodo, [
                self::COLUMNA => $valor_de_desvinculo,
            ]))->assertStatus(200);

            $this->assertNull(
                $this->vinculo_guardado($metodo->id),
                'Mandar '.var_export($valor_de_desvinculo, true).' no desvinculó el método.'
            );
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Un vínculo que no existe
    // ---------------------------------------------------------------------------------------------

    /**
     * Un id que no es ningún método de pago de cuenta corriente es 422 y no se escribe nada, ni en
     * el alta ni en la edición. En la edición NO se pisa el vínculo bueno que ya había.
     */
    public function test_un_vinculo_a_un_metodo_que_no_existe_es_422_y_no_escribe()
    {
        $existente = $this->metodo_de_cuenta_corriente('Cta cte buena');
        $inexistente = (int) CurrentAcountPaymentMethod::max('id') + 1000;

        $cantidad_antes = PaymentMethod::count();

        $this->postJson(self::RUTA, $this->payload_de_alta([self::COLUMNA => $inexistente]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(self::COLUMNA);

        $this->assertSame($cantidad_antes, PaymentMethod::count(), 'El alta rechazada igual creó la fila.');

        $metodo = $this->metodo_de_tienda($existente->id);

        $this->putJson(self::RUTA.'/'.$metodo->id, $this->payload_de_edicion($metodo, [
            'name'        => 'No tiene que guardarse',
            self::COLUMNA => $inexistente,
        ]))->assertStatus(422);

        $metodo->refresh();

        $this->assertNotSame('No tiene que guardarse', $metodo->name, 'La edición rechazada guardó el nombre igual.');
        $this->assertSame($existente->id, (int) $metodo->{self::COLUMNA}, 'La edición rechazada pisó el vínculo bueno.');
    }

    // ---------------------------------------------------------------------------------------------
    // Lectura
    // ---------------------------------------------------------------------------------------------

    /**
     * El listado de la SPA trae el vínculo: sin eso, abrir un método ya vinculado mostraría el select
     * vacío y el primer «Guardar» lo desvincularía.
     */
    public function test_el_listado_devuelve_el_vinculo()
    {
        $cuenta_corriente = $this->metodo_de_cuenta_corriente('Cta cte del listado');
        $metodo           = $this->metodo_de_tienda($cuenta_corriente->id);

        $fila = collect($this->getJson(self::RUTA)->assertStatus(200)->json('models'))
                    ->firstWhere('id', $metodo->id);

        $this->assertNotNull($fila, 'El método no aparece en el listado.');
        $this->assertSame($cuenta_corriente->id, (int) $fila[self::COLUMNA]);
    }

    /**
     * La relación del modelo resuelve al método de cuenta corriente vinculado, y a null si no hay.
     */
    public function test_la_relacion_del_modelo_resuelve_el_metodo_vinculado()
    {
        $cuenta_corriente = $this->metodo_de_cuenta_corriente('Cta cte de la relación');

        $vinculado = $this->metodo_de_tienda($cuenta_corriente->id);
        $suelto    = $this->metodo_de_tienda(null);

        $this->assertTrue(CuotasTiendaEsquemaHelper::hay_columna(), 'La base de testing tiene que traer la migración de la misión.');
        $this->assertSame($cuenta_corriente->id, $vinculado->current_acount_payment_method->id);
        $this->assertNull($suelto->current_acount_payment_method);
    }

    // ---------------------------------------------------------------------------------------------
    // La guarda de esquema
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 Sin la columna, el alta y la edición son las de siempre: ni escriben el campo ni lo nombran
     * en ninguna consulta. Es la ventana de un deploy que sube los archivos antes de migrar.
     *
     * Se mira el SQL que sale (si el controlador escribiera la columna con la columna ausente, el
     * INSERT/UPDATE la nombraría y MySQL contestaría `Unknown column`), y que el campo se ignore
     * entero: tampoco se valida, así que un id inexistente no da 422 en una base que no lo guarda.
     */
    public function test_sin_la_columna_el_alta_y_la_edicion_son_las_de_siempre()
    {
        $cuenta_corriente = $this->metodo_de_cuenta_corriente('Cta cte con la columna ausente');

        // El método ya existe CON vínculo (la columna sí está en la base de testing).
        $metodo = $this->metodo_de_tienda($cuenta_corriente->id);

        $this->sin_la_columna();

        $consultas = [];

        DB::listen(function ($consulta) use (&$consultas) {
            $consultas[] = $consulta->sql;
        });

        // Alta: con el campo en el request, que se ignora (y un id inexistente no da 422).
        $alta = $this->postJson(self::RUTA, $this->payload_de_alta([
            self::COLUMNA => (int) CurrentAcountPaymentMethod::max('id') + 1000,
        ]));

        $alta->assertStatus(201);

        // Edición: el nombre se guarda como siempre y el vínculo ni se toca.
        $edicion = $this->putJson(self::RUTA.'/'.$metodo->id, $this->payload_de_edicion($metodo, [
            'name'        => 'Editado sin la columna',
            self::COLUMNA => 0,
        ]));

        $edicion->assertStatus(200);

        // Otra edición con el campo apuntando a algo que no existe: tampoco se valida.
        $this->putJson(self::RUTA.'/'.$metodo->id, $this->payload_de_edicion($metodo, [
            'name'        => 'Editado sin la columna',
            self::COLUMNA => (int) CurrentAcountPaymentMethod::max('id') + 1000,
        ]))->assertStatus(200);

        $con_la_columna = array_filter($consultas, function ($sql) {
            return strpos($sql, self::COLUMNA) !== false;
        });

        $this->assertSame(
            [],
            array_values($con_la_columna),
            'Con la guarda en "sin columna" el controlador nombró current_acount_payment_method_id en una '.
            'consulta: en un cliente sin la migración eso es `Unknown column` y el ABM de métodos de pago muere.'
        );

        // Lo que SÍ se guardó, se guardó como antes de la misión.
        $this->assertSame('Editado sin la columna', PaymentMethod::find($metodo->id)->name);

        // Y el vínculo que ya había (la columna existe de verdad en esta base) no se tocó.
        $this->assertSame(
            $cuenta_corriente->id,
            $this->vinculo_guardado($metodo->id),
            'Sin la columna el controlador no debe escribirla: el vínculo guardado quedó distinto.'
        );

        $this->assertNull(
            $this->vinculo_guardado($alta->json('model.id')),
            'Sin la columna el alta no debe escribir el vínculo.'
        );
    }

    /**
     * La guarda responde lo que dice el esquema, memoiza la respuesta y `olvidar()` la vuelve a
     * preguntar.
     */
    public function test_la_guarda_memoiza_y_olvidar_vuelve_a_preguntar()
    {
        $this->assertTrue(CuotasTiendaEsquemaHelper::hay_columna());

        $this->sin_la_columna();

        $this->assertFalse(CuotasTiendaEsquemaHelper::hay_columna(), 'La guarda no respetó la respuesta memoizada.');

        CuotasTiendaEsquemaHelper::olvidar();

        $this->assertTrue(CuotasTiendaEsquemaHelper::hay_columna(), 'Después de olvidar() la guarda tiene que volver a preguntarle al esquema.');
    }

    /**
     * El normalizador es ESTRICTO: "sin vínculo" (null, '', 0, '0', false) da null, un entero
     * positivo (int o string de dígitos) es ese id, y todo lo demás da null para que
     * `es_un_vinculo_invalido()` lo rechace.
     */
    public function test_el_normalizador_entiende_lo_que_manda_la_spa()
    {
        // Sin vínculo.
        $this->assertNull(CuotasTiendaEsquemaHelper::normalizar(null));
        $this->assertNull(CuotasTiendaEsquemaHelper::normalizar(''));
        $this->assertNull(CuotasTiendaEsquemaHelper::normalizar(0));
        $this->assertNull(CuotasTiendaEsquemaHelper::normalizar('0'));
        $this->assertNull(CuotasTiendaEsquemaHelper::normalizar(false));

        // Un id: entero positivo, pelado o en texto.
        $this->assertSame(12, CuotasTiendaEsquemaHelper::normalizar(12));
        $this->assertSame(12, CuotasTiendaEsquemaHelper::normalizar('12'));
        $this->assertSame(7, CuotasTiendaEsquemaHelper::normalizar('7'));

        // Lo que NO es un id entero: da null (y el controlador lo rechaza con 422).
        foreach ($this->valores_que_no_son_un_id_entero() as $descripcion => $valor) {
            $this->assertNull(
                CuotasTiendaEsquemaHelper::normalizar($valor),
                'normalizar() aceptó '.$descripcion.' ('.var_export($valor, true).') como un id: tiene que ser estricto.'
            );
        }
    }

    /**
     * Lo que NO es "sin vínculo" y tampoco un entero estricto es un vínculo inválido: la guarda lo
     * marca para el 422.
     */
    public function test_los_valores_que_no_son_un_id_entero_son_vinculos_invalidos()
    {
        $this->assertTrue(CuotasTiendaEsquemaHelper::hay_columna());

        foreach ($this->valores_que_no_son_un_id_entero() as $descripcion => $valor) {
            $this->assertTrue(
                CuotasTiendaEsquemaHelper::es_un_vinculo_invalido($valor),
                $descripcion.' ('.var_export($valor, true).') tendría que ser un vínculo inválido.'
            );
        }

        // Y "sin vínculo" no lo es: es desvincular.
        foreach ([null, '', 0, '0', false] as $sin_vinculo) {
            $this->assertFalse(
                CuotasTiendaEsquemaHelper::es_un_vinculo_invalido($sin_vinculo),
                var_export($sin_vinculo, true).' es "sin vínculo", no un vínculo inválido.'
            );
        }
    }

    /**
     * 🔴 Por HTTP: un valor que un `(int)` de PHP leería como OTRO id ('<id>.5' como <id>, '<id>e0'
     * como <id>) es 422 en el alta y en la edición, y no escribe nada ni pisa el vínculo bueno. Se
     * prueba con el id de un método que EXISTE, así que si el controlador truncara con `(int)` a
     * secas el test vincularía a ese método en vez de rechazar.
     *
     * Los espacios alrededor (' 7 ') los saca el middleware TrimStrings de Laravel ANTES de llegar al
     * controlador, así que por HTTP llegan como '7' y SON un id válido; el rechazo de ' 7 ' es del
     * helper y lo fija el test del normalizador.
     */
    public function test_un_vinculo_con_decimales_exponente_o_ceros_es_422_y_no_escribe()
    {
        $bueno  = $this->metodo_de_cuenta_corriente('Cta cte buena');
        $metodo = $this->metodo_de_tienda($bueno->id);

        $cantidad_antes = PaymentMethod::count();

        $invalidos = [
            $bueno->id.'.5',
            $bueno->id.'e0',
            '0'.$bueno->id,
            '+'.$bueno->id,
            (float) $bueno->id + 0.5,
            -$bueno->id,
            true,
            'abc',
        ];

        foreach ($invalidos as $invalido) {

            $this->postJson(self::RUTA, $this->payload_de_alta([self::COLUMNA => $invalido]))
                ->assertStatus(422);

            $this->putJson(self::RUTA.'/'.$metodo->id, $this->payload_de_edicion($metodo, [
                'name'        => 'No tiene que guardarse',
                self::COLUMNA => $invalido,
            ]))->assertStatus(422);

            $this->assertSame(
                $bueno->id,
                $this->vinculo_guardado($metodo->id),
                var_export($invalido, true).' pisó el vínculo bueno.'
            );
            $this->assertNotSame('No tiene que guardarse', PaymentMethod::find($metodo->id)->name);
        }

        $this->assertSame($cantidad_antes, PaymentMethod::count(), 'Un alta rechazada igual creó la fila.');

        // Un id entero en texto, sí.
        $otro = $this->metodo_de_cuenta_corriente('Cta cte otra');

        $this->putJson(self::RUTA.'/'.$metodo->id, $this->payload_de_edicion($metodo, [
            self::COLUMNA => (string) $otro->id,
        ]))->assertStatus(200);

        $this->assertSame($otro->id, $this->vinculo_guardado($metodo->id));

        // Con espacios alrededor, TrimStrings lo deja en el id y se acepta.
        $this->putJson(self::RUTA.'/'.$metodo->id, $this->payload_de_edicion($metodo, [
            self::COLUMNA => ' '.$bueno->id.' ',
        ]))->assertStatus(200);

        $this->assertSame($bueno->id, $this->vinculo_guardado($metodo->id));
    }

    // ---------------------------------------------------------------------------------------------
    // Ayudantes
    // ---------------------------------------------------------------------------------------------

    /**
     * Valores que NO son un id entero y tampoco "sin vínculo": la guarda tiene que rechazarlos.
     *
     * Son los que un `is_numeric()` + `(int)` dejaría pasar leyéndolos como otro id ('1.5' como 1,
     * '1e3' como 1000), los que traen espacios, ceros a la izquierda o signo, los negativos, los
     * booleanos y los números que desbordan un entero.
     *
     * @return array<string, mixed>
     */
    protected function valores_que_no_son_un_id_entero()
    {
        return [
            'un decimal en texto'         => '1.5',
            'un decimal pelado'           => 7.0,
            'un decimal pelado con resto' => 1.5,
            'notación científica'         => '1e3',
            'notación científica chica'   => '7e0',
            'con espacios alrededor'      => ' 7 ',
            'con espacio adelante'        => ' 7',
            'con ceros a la izquierda'    => '07',
            'con signo más'               => '+7',
            'negativo en texto'           => '-4',
            'negativo'                    => -4,
            'texto'                       => 'abc',
            'hexadecimal'                 => '0x1A',
            'verdadero'                   => true,
            'que desborda un entero'      => '99999999999999999999',
            'un arreglo'                  => [7],
        ];
    }

    /**
     * Fuerza la memoización de la guarda a "esta base no tiene la columna".
     *
     * Es "la propia forma de forzar la caché del helper": no hay DDL (que haría commit implícito y
     * rompería el aislamiento) y el helper responde exactamente lo que respondería en un cliente que
     * todavía no corrió la migración. Cada test la limpia en `setUp()` y en `tearDown()`.
     *
     * @return void
     */
    protected function sin_la_columna()
    {
        $memo = new ReflectionProperty(CuotasTiendaEsquemaHelper::class, 'existe_en_payment_methods');
        $memo->setAccessible(true);
        $memo->setValue(null, false);
    }

    /**
     * Id del comercio del fixture, que es el que queda autenticado en `EmpresaTestCase::setUp()`.
     *
     * @return int
     */
    protected function user_id()
    {
        return (int) User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail()->id;
    }

    /**
     * Un método de pago de cuenta corriente (los del mostrador).
     *
     * @param string $nombre
     * @return CurrentAcountPaymentMethod
     */
    protected function metodo_de_cuenta_corriente($nombre)
    {
        return CurrentAcountPaymentMethod::create(['name' => $nombre.' '.uniqid()]);
    }

    /**
     * Un método de pago de la tienda de tipo MercadoPago, vinculado o no.
     *
     * @param int|null $current_acount_payment_method_id
     * @return PaymentMethod
     */
    protected function metodo_de_tienda($current_acount_payment_method_id)
    {
        $metodo = PaymentMethod::create([
            'name'                   => 'MercadoPago',
            'description'            => 'Pago online',
            'payment_method_type_id' => $this->tipo_mercado_pago()->id,
            'user_id'                => $this->user_id(),
        ]);

        if (!is_null($current_acount_payment_method_id)) {
            $metodo->{self::COLUMNA} = $current_acount_payment_method_id;
            $metodo->save();
        }

        return $metodo->fresh();
    }

    /**
     * El tipo MercadoPago de `payment_method_types`, creándolo si el fixture no lo trae.
     *
     * @return PaymentMethodType
     */
    protected function tipo_mercado_pago()
    {
        $tipo = PaymentMethodType::where('name', 'MercadoPago')->first();

        if (!$tipo) {
            $tipo = PaymentMethodType::create(['name' => 'MercadoPago']);
        }

        return $tipo;
    }

    /**
     * El payload de un alta tal como lo arma la SPA, con lo que se le pase por encima.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function payload_de_alta(array $extra = [])
    {
        return array_merge([
            'name'                   => 'MercadoPago',
            'description'            => 'Pago online',
            'discount'               => null,
            'payment_method_type_id' => $this->tipo_mercado_pago()->id,
        ], $extra);
    }

    /**
     * El payload de una edición: los campos comerciales del método, SIN el vínculo y SIN credenciales
     * (la SPA vieja no los conoce), más lo que se le pase por encima.
     *
     * @param PaymentMethod        $metodo
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function payload_de_edicion(PaymentMethod $metodo, array $extra = [])
    {
        return array_merge([
            'name'                   => $metodo->name,
            'description'            => $metodo->description,
            'discount'               => $metodo->discount,
            'payment_method_type_id' => $metodo->payment_method_type_id,
        ], $extra);
    }

    /**
     * El vínculo tal como quedó escrito en la base (lectura cruda, sin pasar por el modelo).
     *
     * @param int $id
     * @return int|null
     */
    protected function vinculo_guardado($id)
    {
        $valor = DB::table('payment_methods')->where('id', $id)->value(self::COLUMNA);

        return is_null($valor) ? null : (int) $valor;
    }
}
