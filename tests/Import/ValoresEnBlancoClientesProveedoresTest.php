<?php

namespace Tests\Import;

use App\Imports\ClientImport;
use App\Imports\ProviderImport;
use App\Models\Client;
use App\Models\Provider;
use ReflectionMethod;

/**
 * El checkbox unico por importacion: "los valores en blanco, ¿se omiten o vacian la
 * propiedad en el sistema?", del lado de CLIENTES y PROVEEDORES.
 *
 * Articulos ya lo tenia resuelto por columna (blank_flags -> ProcessRow::
 * permite_valores_en_blanco()); clientes y proveedores no tenian NADA: una celda vacia
 * siempre se salteaba y no habia forma de vaciar un campo desde el Excel.
 *
 * Los tres modos de falla que cubren estos tests, en orden de lo caro que salen:
 *
 *   1. Con el checkbox prendido, vaciar una propiedad que el usuario NO mapeo. Si el
 *      Excel trae 4 columnas y el sistema tiene 17 propiedades, un bug aca le borra 13
 *      campos a cada cliente de la base. `ImportHelper::getColumnValue()` devuelve null
 *      tanto para "columna sin mapear" como para "celda vacia", asi que los dos casos son
 *      indistinguibles si no se pregunta aparte por el mapeo.
 *   2. Que el default cambie. El 100% de las importaciones de hoy corre sin el checkbox y
 *      espera que una celda vacia no toque nada.
 *   3. Que la firma vieja del constructor deje de andar. AdminSync\AiExcelImportController
 *      construye estas dos clases con la firma corta contra clientes REALES y no se toca:
 *      un parametro nuevo sin default ahi es un ArgumentCountError en produccion.
 *
 * Los importadores se instancian a mano en vez de pegarle al endpoint a proposito: el
 * controlador todavia no manda el booleano (lo hace otra parte de la mision) y lo que hay
 * que medir aca es el comportamiento de las clases, no el cableado del request.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promocion de constructor, readonly, enum ni #[...].
 */
class ValoresEnBlancoClientesProveedoresTest extends ImportTestCase
{
    /* =====================================================================
     * Ayudantes
     * ================================================================== */

    /**
     * Cliente de partida: tiene valor en todos los campos que despues se miran, para que
     * "quedo vacio" y "quedo como estaba" sean distinguibles.
     *
     * @param  array $extra
     * @return \App\Models\Client
     */
    protected function cliente_sembrado(array $extra = [])
    {
        $data = array_merge([
            'user_id'          => $this->tenant->id,
            'num'              => 4001,
            'name'             => 'Cliente Blancos',
            'phone'            => '11-1111-1111',
            'email'            => 'viejo@test.com',
            'address'          => 'Calle Vieja 100',
            'dni'              => '30111222',
            'razon_social'     => 'Razon Vieja SA',
            'iva_condition_id' => 1,
        ], $extra);

        return Client::create($data);
    }

    /**
     * Proveedor de partida, mismo criterio.
     *
     * @param  array $extra
     * @return \App\Models\Provider
     */
    protected function proveedor_sembrado(array $extra = [])
    {
        $data = array_merge([
            'user_id'          => $this->tenant->id,
            'num'              => 777,
            'name'             => 'Proveedor Blancos',
            'phone'            => '22-2222-2222',
            'email'            => 'prov@test.com',
            'address'          => 'Av Vieja 200',
            'observations'     => 'observacion vieja',
            'razon_social'     => 'Proveedor Viejo SRL',
        ], $extra);

        return Provider::create($data);
    }

    /**
     * Corre una importacion de clientes de UNA fila.
     *
     * @param  array     $columns  Mapeo clave de columna => indice 0-based, tal como lo
     *                             arma GeneralHelper::getImportColumns()
     * @param  array     $fila     Celdas de la fila, indexadas 0-based. '' = celda vacia.
     * @param  bool|null $vaciar   null = se construye con la FIRMA VIEJA (4 argumentos),
     *                             que es la que usa AdminSync y la que no se puede romper.
     * @return void
     */
    protected function importar_clientes(array $columns, array $fila, $vaciar = null)
    {
        if (is_null($vaciar)) {
            $import = new ClientImport($columns, true, 1, 1);
        } else {
            $import = new ClientImport($columns, true, 1, 1, 0, null, $vaciar);
        }

        $import->collection(collect([$fila]));
    }

    /**
     * Idem para proveedores. Con $vaciar en null se usa la firma vieja de CINCO
     * argumentos, que es la de AdminSync.
     *
     * @param  array     $columns
     * @param  array     $fila
     * @param  bool|null $vaciar
     * @return void
     */
    protected function importar_proveedores(array $columns, array $fila, $vaciar = null)
    {
        if (is_null($vaciar)) {
            $import = new ProviderImport($columns, true, 1, 1, null);
        } else {
            $import = new ProviderImport($columns, true, 1, 1, null, 0, null, $vaciar);
        }

        $import->collection(collect([$fila]));
    }

    /* =====================================================================
     * A — Clientes
     * ================================================================== */

    /**
     * SIN el checkbox (el default, y el 100% de las importaciones de hoy): una celda vacia
     * de una columna mapeada NO toca el valor que el cliente ya tenia.
     *
     * La fila trae ademas una direccion nueva a proposito. Sin ese cambio, isDataUpdated()
     * devolveria false, no habria update() y el test pasaria por el motivo equivocado: no
     * porque el telefono se haya respetado, sino porque no se escribio nada. Con la
     * direccion cambiada el update SI corre, y el telefono sobrevive igual.
     *
     * @return void
     */
    public function test_sin_el_flag_una_celda_vacia_no_toca_el_valor_existente()
    {
        $cliente = $this->cliente_sembrado();

        $this->importar_clientes(
            ['nombre' => 0, 'telefono' => 1, 'direccion' => 2],
            ['Cliente Blancos', '', 'Calle Nueva 999']
        );

        $cliente = Client::find($cliente->id);

        $this->assertSame(
            'Calle Nueva 999',
            $cliente->address,
            'El update ni siquiera corrio: el test no estaria midiendo lo que dice medir.'
        );

        $this->assertSame(
            '11-1111-1111',
            $cliente->phone,
            'Sin el checkbox, una celda vacia tiene que saltearse y dejar el telefono como estaba.'
        );
    }

    /**
     * CON el checkbox: la celda vacia de una columna mapeada escribe vacio.
     *
     * @return void
     */
    public function test_con_el_flag_una_celda_vacia_de_columna_mapeada_vacia_el_campo()
    {
        $cliente = $this->cliente_sembrado();

        $this->importar_clientes(
            ['nombre' => 0, 'telefono' => 1, 'direccion' => 2],
            ['Cliente Blancos', '', 'Calle Nueva 999'],
            true
        );

        $cliente = Client::find($cliente->id);

        $this->assertNull(
            $cliente->phone,
            'Con el checkbox prendido, la celda vacia de "telefono" tiene que vaciar el campo.'
        );

        $this->assertSame('Calle Nueva 999', $cliente->address);
    }

    /**
     * 🔴 El test caro. Con el checkbox prendido, una propiedad que el usuario NO mapeo
     * queda intacta.
     *
     * El Excel de este test tiene DOS columnas (nombre y telefono). El cliente tiene ademas
     * email, dni, razon social, direccion y condicion de iva. Marcar el checkbox no puede
     * significar "borrame todo lo que no vino en el archivo": el usuario mapeo dos columnas
     * y sobre esas dos decide.
     *
     * @return void
     */
    public function test_con_el_flag_una_propiedad_no_mapeada_queda_intacta()
    {
        $cliente = $this->cliente_sembrado();

        $this->importar_clientes(
            ['nombre' => 0, 'telefono' => 1],
            ['Cliente Blancos', ''],
            true
        );

        $cliente = Client::find($cliente->id);

        /* La mapeada y vacia: si esto no se vacio, el checkbox no hizo nada. */
        $this->assertNull($cliente->phone);

        /* Todas las demas, que el Excel ni menciona. */
        $this->assertSame('viejo@test.com', $cliente->email, 'Se vacio "email", que no estaba mapeado.');
        $this->assertSame('30111222', $cliente->dni, 'Se vacio "dni", que no estaba mapeado.');
        $this->assertSame('Razon Vieja SA', $cliente->razon_social, 'Se vacio "razon_social", que no estaba mapeado.');
        $this->assertSame('Calle Vieja 100', $cliente->address, 'Se vacio "direccion", que no estaba mapeada.');
        $this->assertSame(1, (int) $cliente->iva_condition_id, 'Se vacio "iva_condition_id", que no estaba mapeado.');
    }

    /**
     * La condicion frente al iva no sale del foreach de props_to_set: se resuelve aparte,
     * por alias y contra una tabla. Que el checkbox la alcance hay que probarlo aparte.
     *
     * `clients.iva_condition_id` es nullable y no tiene FK declarada, asi que null es
     * "sin condicion asignada" y no revienta la base.
     *
     * @return void
     */
    public function test_con_el_flag_se_vacia_la_condicion_frente_al_iva_mapeada()
    {
        $cliente = $this->cliente_sembrado();

        $this->importar_clientes(
            ['nombre' => 0, 'condicion_frente_al_iva' => 1],
            ['Cliente Blancos', ''],
            true
        );

        $cliente = Client::find($cliente->id);

        $this->assertNull(
            $cliente->iva_condition_id,
            'La condicion frente al iva estaba mapeada y vacia: con el checkbox tiene que vaciarse.'
        );
    }

    /**
     * Al CREAR un cliente nuevo no hay nada que vaciar, y el checkbox no puede romper la
     * creacion.
     *
     * @return void
     */
    public function test_con_el_flag_la_creacion_de_un_cliente_nuevo_sigue_andando()
    {
        $this->importar_clientes(
            ['nombre' => 0, 'telefono' => 1, 'email' => 2],
            ['Cliente Recien Creado', '', 'nuevo@test.com'],
            true
        );

        $cliente = Client::where('user_id', $this->tenant->id)
                            ->where('name', 'Cliente Recien Creado')
                            ->first();

        $this->assertNotNull($cliente, 'Con el checkbox prendido dejo de crearse el cliente nuevo.');
        $this->assertSame('nuevo@test.com', $cliente->email);
        $this->assertNull($cliente->phone);
    }

    /**
     * El nombre NUNCA se vacia: `clients.name` es NOT NULL y un update con null ahi es un
     * error de SQL en el medio de la importacion.
     *
     * El camino real es que checkRow() saltee la fila entera cuando "nombre" viene vacio,
     * asi que el cliente no se toca ni se crea uno nuevo. Este test congela las dos cosas.
     *
     * @return void
     */
    public function test_con_el_flag_una_fila_sin_nombre_no_vacia_ni_crea_nada()
    {
        $cliente = $this->cliente_sembrado();

        $antes = Client::where('user_id', $this->tenant->id)->count();

        $this->importar_clientes(
            ['nombre' => 0, 'telefono' => 1],
            ['', ''],
            true
        );

        $cliente = Client::find($cliente->id);

        $this->assertSame('Cliente Blancos', $cliente->name);
        $this->assertSame('11-1111-1111', $cliente->phone);

        $this->assertSame(
            $antes,
            Client::where('user_id', $this->tenant->id)->count(),
            'Una fila sin nombre no puede crear un cliente.'
        );
    }

    /**
     * La firma VIEJA de cuatro argumentos —la que usa AdminSync\AiExcelImportController
     * contra clientes reales y que esta mision no toca— sigue construyendo y sigue
     * omitiendo los blancos.
     *
     * @return void
     */
    public function test_la_firma_vieja_de_clientes_sigue_andando_y_omite_los_blancos()
    {
        $cliente = $this->cliente_sembrado();

        /* $vaciar en null => se construye con 4 argumentos, igual que AdminSync. */
        $this->importar_clientes(
            ['nombre' => 0, 'telefono' => 1, 'direccion' => 2],
            ['Cliente Blancos', '', 'Calle Nueva 999'],
            null
        );

        $cliente = Client::find($cliente->id);

        $this->assertSame('Calle Nueva 999', $cliente->address);
        $this->assertSame(
            '11-1111-1111',
            $cliente->phone,
            'La firma vieja tiene que comportarse exactamente como antes de la mision.'
        );
    }

    /* =====================================================================
     * B — Proveedores
     *
     * No son simetricos con clientes y por eso van los mismos tests de nuevo en vez de un
     * data provider: ProviderImport tiene 'num' y 'localidad' DENTRO de props_to_set,
     * cosa que ClientImport no tiene, y no comparte una linea de codigo con el otro.
     * ================================================================== */

    /**
     * @return void
     */
    public function test_sin_el_flag_una_celda_vacia_no_toca_el_valor_del_proveedor()
    {
        $proveedor = $this->proveedor_sembrado();

        $this->importar_proveedores(
            ['nombre' => 0, 'telefono' => 1, 'direccion' => 2],
            ['Proveedor Blancos', '', 'Av Nueva 999']
        );

        $proveedor = Provider::find($proveedor->id);

        $this->assertSame(
            'Av Nueva 999',
            $proveedor->address,
            'El update ni siquiera corrio: el test no estaria midiendo lo que dice medir.'
        );

        $this->assertSame('22-2222-2222', $proveedor->phone);
    }

    /**
     * @return void
     */
    public function test_con_el_flag_una_celda_vacia_de_columna_mapeada_vacia_el_campo_del_proveedor()
    {
        $proveedor = $this->proveedor_sembrado();

        $this->importar_proveedores(
            ['nombre' => 0, 'telefono' => 1, 'direccion' => 2],
            ['Proveedor Blancos', '', 'Av Nueva 999'],
            true
        );

        $proveedor = Provider::find($proveedor->id);

        $this->assertNull($proveedor->phone);
        $this->assertSame('Av Nueva 999', $proveedor->address);
    }

    /**
     * 🔴 El test caro, del lado de proveedores.
     *
     * @return void
     */
    public function test_con_el_flag_una_propiedad_no_mapeada_del_proveedor_queda_intacta()
    {
        $proveedor = $this->proveedor_sembrado();

        $this->importar_proveedores(
            ['nombre' => 0, 'telefono' => 1],
            ['Proveedor Blancos', ''],
            true
        );

        $proveedor = Provider::find($proveedor->id);

        $this->assertNull($proveedor->phone);

        $this->assertSame('prov@test.com', $proveedor->email, 'Se vacio "email", que no estaba mapeado.');
        $this->assertSame('Av Vieja 200', $proveedor->address, 'Se vacio "direccion", que no estaba mapeada.');
        $this->assertSame('observacion vieja', $proveedor->observations, 'Se vacio "observaciones", que no estaba mapeado.');
        $this->assertSame('Proveedor Viejo SRL', $proveedor->razon_social, 'Se vacio "razon_social", que no estaba mapeado.');
    }

    /**
     * 🔴 `num` es la asimetria de ProviderImport contra ClientImport: esta DENTRO de
     * props_to_set, asi que el foreach lo alcanzaria, y ademas es el identificador con el
     * que collection() busca el proveedor.
     *
     * Vaciarlo le borra el numero al proveedor y lo deja imposible de matchear en la
     * proxima importacion. Con la columna "numero" mapeada y vacia, el numero se queda
     * donde esta aunque el checkbox este prendido.
     *
     * @return void
     */
    public function test_con_el_flag_el_numero_del_proveedor_nunca_se_vacia()
    {
        $proveedor = $this->proveedor_sembrado();

        $this->importar_proveedores(
            ['numero' => 0, 'nombre' => 1, 'telefono' => 2],
            ['', 'Proveedor Blancos', ''],
            true
        );

        $proveedor = Provider::find($proveedor->id);

        $this->assertSame(
            777,
            (int) $proveedor->num,
            'Se vacio el numero del proveedor: es el identificador de matcheo y queda afuera del checkbox.'
        );

        /* Y el telefono, que si es vaciable, se vacio: el checkbox estaba prendido de verdad. */
        $this->assertNull($proveedor->phone);
    }

    /**
     * La firma VIEJA de cinco argumentos de proveedores —la de AdminSync— sigue andando.
     *
     * @return void
     */
    public function test_la_firma_vieja_de_proveedores_sigue_andando_y_omite_los_blancos()
    {
        $proveedor = $this->proveedor_sembrado();

        $this->importar_proveedores(
            ['nombre' => 0, 'telefono' => 1, 'direccion' => 2],
            ['Proveedor Blancos', '', 'Av Nueva 999'],
            null
        );

        $proveedor = Provider::find($proveedor->id);

        $this->assertSame('Av Nueva 999', $proveedor->address);
        $this->assertSame('22-2222-2222', $proveedor->phone);
    }

    /* =====================================================================
     * C — El contrato de la firma, congelado
     * ================================================================== */

    /**
     * El parametro nuevo es el ULTIMO y tiene default false, en las dos clases.
     *
     * Va por reflexion y no por comportamiento porque lo que hay que congelar es la forma
     * de la firma: que alguien lo mueva de lugar o le saque el default no se ve en ningun
     * test funcional de este archivo, y se ve —tarde— como un ArgumentCountError en el
     * endpoint que admin-api usa contra clientes reales.
     *
     * @return void
     */
    public function test_el_parametro_nuevo_es_opcional_y_va_al_final_en_las_dos_clases()
    {
        $esperado = [
            ClientImport::class   => 4,
            ProviderImport::class => 5,
        ];

        foreach ($esperado as $clase => $requeridos_por_adminsync) {

            $constructor = new ReflectionMethod($clase, '__construct');
            $parametros  = $constructor->getParameters();
            $ultimo      = $parametros[count($parametros) - 1];

            $this->assertSame(
                'vaciar_valores_en_blanco',
                $ultimo->getName(),
                $clase . ': el parametro nuevo tiene que ser el ULTIMO de la firma.'
            );

            $this->assertTrue(
                $ultimo->isOptional(),
                $clase . ': sin default, AdminSync explota con ArgumentCountError.'
            );

            $this->assertFalse(
                $ultimo->getDefaultValue(),
                $clase . ': el default tiene que ser false (omitir los blancos, como siempre).'
            );

            $this->assertSame(
                $requeridos_por_adminsync,
                $constructor->getNumberOfRequiredParameters(),
                $clase . ': cambio la cantidad de argumentos obligatorios y AdminSync los pasa fijos.'
            );
        }
    }
}
