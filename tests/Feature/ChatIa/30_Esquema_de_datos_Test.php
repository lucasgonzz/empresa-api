<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\CatalogoDeDatosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\EsquemaDeDatosIaHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Misión asistente-omnisciente — bloque A1: el catálogo de lectura derivado del esquema.
 *
 * Lo que este archivo protege, en orden de importancia:
 *
 * 🔴 QUE NINGUNA COLUMNA SENSIBLE SALGA. La lista negra es un regex sobre el nombre; si alguien lo
 * afloja o una tabla nueva trae una columna con un nombre raro, esto es lo que lo denuncia antes
 * de que un `buyers.password` viaje a un tool_result.
 *
 * 🔴 QUE LO DECLARADO EXISTA. Cada entidad es una tabla real, cada campo una columna real, cada
 * relación una tabla y una columna reales, cada hija un padre con `user_id`. Una declaración que
 * no corre es una declaración que miente, y acá se ve recién cuando el comerciante pregunta.
 *
 * 🔴 QUE LAS DIECISIETE CURADAS SIGAN SIENDO LAS DE SIEMPRE. Descripción, etiqueta, campos por
 * defecto y relaciones: son el contrato que el modelo ya conoce.
 */
class Esquema_de_datos_Test extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // El catálogo se deriva del esquema de ESTA base, no de un caché de otra corrida.
        EsquemaDeDatosIaHelper::olvidar();
    }

    /**
     * @group chat-ia
     * @test
     */
    public function cada_entidad_declarada_existe_como_tabla_con_sus_columnas_y_relaciones()
    {
        $catalogo = EsquemaDeDatosIaHelper::catalogo();

        $this->assertGreaterThan(100, count($catalogo), 'La base de testing tiene ciento y pico de tablas con user_id: el catálogo no puede ser una whitelist corta.');

        foreach ($catalogo as $entidad => $declaracion) {
            $this->assertTrue(Schema::hasTable($declaracion['tabla']), $entidad . ': la tabla ' . $declaracion['tabla'] . ' no existe.');
            $this->assertNotEmpty($declaracion['campos'], $entidad . ': sin campos.');
            $this->assertNotEmpty($declaracion['campos_por_defecto'], $entidad . ': sin campos por defecto.');
            $this->assertContains($declaracion['modulo'], array_merge(array_keys(EsquemaDeDatosIaHelper::MODULOS), ['otros']), $entidad);

            foreach ($declaracion['campos'] as $campo => $definicion) {
                $this->assertContains($definicion['tipo'], ['text', 'textarea', 'number', 'date', 'checkbox', 'search'], $entidad . '.' . $campo);

                /*
                 * Un campo es una de dos cosas, y ninguna otra: una columna real calificada, o un
                 * campo CALCULADO con su expresión SQL declarada (misión asistente-omnisciente,
                 * 21/9/2026: `importe` y compañía, que son la plata de un renglón).
                 */
                if (isset($definicion['calculado'])) {
                    $this->assertTrue($definicion['calculado'], $entidad . '.' . $campo . ': `calculado` va en true o no va.');
                    $this->assertArrayNotHasKey('columna', $definicion, $entidad . '.' . $campo . ': un calculado no tiene columna.');
                    $this->assertNotEmpty($definicion['expresion'], $entidad . '.' . $campo . ': un calculado sin expresión.');
                    $this->assertEquals('number', $definicion['tipo'], $entidad . '.' . $campo . ': los calculados son numéricos.');

                    // Y su expresión habla de columnas reales de tablas reales, no de nombres sueltos.
                    preg_match_all('/([a-z_]+)\.([a-z_]+)/', $definicion['expresion'], $usadas, PREG_SET_ORDER);

                    $this->assertNotEmpty($usadas, $entidad . '.' . $campo . ': la expresión no nombra ninguna columna calificada.');

                    foreach ($usadas as $usada) {
                        $this->assertTrue(
                            Schema::hasColumn($usada[1], $usada[2]),
                            $entidad . '.' . $campo . ': ' . $usada[0] . ' de la expresión no es una columna real.'
                        );
                    }

                    continue;
                }

                $partes = explode('.', $definicion['columna']);

                $this->assertCount(2, $partes, $entidad . '.' . $campo . ': la columna tiene que ir calificada.');
                $this->assertTrue(
                    Schema::hasColumn($partes[0], $partes[1]),
                    $entidad . '.' . $campo . ': ' . $definicion['columna'] . ' no es una columna real.'
                );
            }

            foreach ($declaracion['relaciones'] as $clave => $relacion) {
                $this->assertArrayHasKey($relacion['columna_id'], $declaracion['campos'], $entidad . '.' . $clave . ': la columna con la que se filtra tiene que ser un campo.');
                $this->assertEquals('search', $declaracion['campos'][$relacion['columna_id']]['tipo'], $entidad . '.' . $clave);

                if (isset($relacion['etiquetas_fijas'])) {
                    continue;
                }

                $this->assertTrue(Schema::hasTable($relacion['tabla']), $entidad . '.' . $clave . ': la tabla ' . $relacion['tabla'] . ' no existe.');
                $this->assertTrue(Schema::hasColumn($relacion['tabla'], $relacion['campo']), $entidad . '.' . $clave . ': ' . $relacion['tabla'] . ' no tiene ' . $relacion['campo'] . '.');
            }

            // Los campos por defecto y los omitidos son exactamente los campos, sin repetir.
            $this->assertEquals(
                array_keys($declaracion['campos']),
                array_merge($declaracion['campos_por_defecto'], $declaracion['campos_omitidos']),
                $entidad . ': por_defecto + omitidos tiene que ser la lista de campos, en orden.'
            );
        }
    }

    /**
     * 🔴 LA ASERCIÓN DEL ARCHIVO: nada sensible sale, en ninguna entidad.
     *
     * @group chat-ia
     * @test
     */
    public function ninguna_columna_cae_en_la_lista_negra_ni_es_user_id()
    {
        foreach (EsquemaDeDatosIaHelper::catalogo() as $entidad => $declaracion) {
            foreach ($declaracion['campos'] as $campo => $definicion) {
                $this->assertNotEquals('user_id', $campo, $entidad . ': user_id es el scope, no un campo.');
                $this->assertNotEquals('id', $campo, $entidad . ': id viaja siempre, no se declara.');
                $this->assertEquals(
                    0,
                    preg_match(EsquemaDeDatosIaHelper::COLUMNAS_SENSIBLES, $campo),
                    $entidad . '.' . $campo . ' cae en la lista negra de columnas y sin embargo está declarado.'
                );
            }
        }

        // Los casos concretos que motivaron el regex.
        $this->assertArrayNotHasKey('password', EsquemaDeDatosIaHelper::declaracion('buyer')['campos']);
        $this->assertArrayNotHasKey('remember_token', EsquemaDeDatosIaHelper::declaracion('buyer')['campos']);
        $this->assertArrayNotHasKey('verification_code', EsquemaDeDatosIaHelper::declaracion('buyer')['campos']);
        $this->assertArrayNotHasKey('embedding', EsquemaDeDatosIaHelper::declaracion('article')['campos']);
        $this->assertArrayNotHasKey('access_token', EsquemaDeDatosIaHelper::declaracion('payment_method')['campos']);
        $this->assertArrayNotHasKey('public_key', EsquemaDeDatosIaHelper::declaracion('payment_method')['campos']);

        // Y el empleado, que sale de `users`, solo con sus cinco columnas.
        $this->assertEquals(
            ['name', 'email', 'phone', 'admin_access', 'created_at'],
            array_keys(EsquemaDeDatosIaHelper::declaracion('empleado')['campos'])
        );
    }

    /**
     * Cada tabla de la lista negra existe (un nombre mal escrito excluiría nada) y ninguna entra.
     *
     * @group chat-ia
     * @test
     */
    public function ninguna_tabla_excluida_entra_y_todas_existen()
    {
        $tablas_del_catalogo = array_column(EsquemaDeDatosIaHelper::catalogo(), 'tabla');

        foreach (EsquemaDeDatosIaHelper::TABLAS_EXCLUIDAS as $tabla => $motivo) {
            $this->assertTrue(Schema::hasTable($tabla), 'La tabla excluida ' . $tabla . ' no existe: ¿nombre mal escrito?');
            $this->assertNotEmpty($motivo, $tabla . ': toda exclusión lleva su motivo.');
            $this->assertNotContains($tabla, $tablas_del_catalogo, $tabla . ' está excluida y sin embargo entró.');
            $this->assertFalse(EsquemaDeDatosIaHelper::existe(Str::singular($tabla)), $tabla);
        }

        // Lo que la misión nombró explícitamente como afuera.
        foreach (['ai_conversation', 'mercado_libre_token', 'user_configuration', 'company_performance', 'whatsapp_bot_config'] as $afuera) {
            $this->assertFalse(EsquemaDeDatosIaHelper::existe($afuera), $afuera);
        }

        /*
         * `online_configuration` estaba en esta lista y pasó a la de abajo el 21/9/2026. Se había
         * excluido entera por "credenciales SMTP y de plataformas mezcladas con flags de pantalla",
         * pero las credenciales ya las filtra COLUMNAS_SENSIBLES y los flags son la pantalla que el
         * dueño abre para decidir cómo vende. Lo que se revisó columna por columna está en
         * COLUMNAS_EXCLUIDAS_POR_TABLA; acá abajo se verifica que ni una credencial salga.
         */
        // Y lo que la misión nombró como adentro, con su módulo.
        foreach (['cheque' => 'caja y tesoreria', 'payment_plan' => 'ventas', 'movimiento_entre_caja' => 'caja y tesoreria', 'stock_movement' => 'stock', 'whatsapp_chat' => 'clientes', 'payment_method' => 'tienda online', 'current_acount' => 'clientes', 'online_configuration' => 'tienda online'] as $adentro => $modulo) {
            $this->assertTrue(EsquemaDeDatosIaHelper::existe($adentro), $adentro);
            $this->assertEquals($modulo, EsquemaDeDatosIaHelper::declaracion($adentro)['modulo'], $adentro);
        }
    }

    /**
     * 🔴 LA CONFIGURACIÓN DE LA TIENDA ENTRA, PERO NINGUNA CREDENCIAL SALE.
     *
     * `online_configurations` tiene mezcladas las credenciales de Mercado Pago, Zipnova, Google y
     * el SMTP con los flags que el dueño maneja. Entró como entidad normal el 21/9/2026 y esta es
     * la aserción que lo sostiene: se listan las columnas reales de la tabla y se verifica, una por
     * una, que ninguna que huela a credencial haya llegado a la declaración.
     *
     * @group chat-ia
     * @test
     */
    public function la_configuracion_de_la_tienda_entra_sin_una_sola_credencial()
    {
        $declaracion = EsquemaDeDatosIaHelper::declaracion('online_configuration');

        $this->assertNotNull($declaracion);
        $this->assertTrue($declaracion['curada']);

        // Lo que sí tiene que estar: lo que el dueño pregunta.
        foreach (['pausar_tienda_online', 'register_to_buy', 'has_delivery', 'mp_enabled', 'zippin_enabled'] as $campo) {
            $this->assertArrayHasKey($campo, $declaracion['campos'], $campo . ': es la pantalla que el dueño toca.');
            $this->assertContains($campo, $declaracion['campos_por_defecto'], $campo);
        }

        // Y lo que NO puede estar, nombrado una por una (no por regex: el regex es lo que se está
        // verificando).
        $prohibidas = [
            'mail_password', 'mail_username', 'google_client_id', 'google_client_secret',
            'mp_user_id', 'mp_public_key', 'mp_access_token', 'mp_refresh_token', 'mp_token_expires_at',
            'zippin_account_id', 'zippin_access_token', 'zippin_refresh_token', 'zippin_token_expires_at',
        ];

        foreach ($prohibidas as $campo) {
            $this->assertArrayNotHasKey($campo, $declaracion['campos'], $campo . ': es una credencial (o la mitad de una) y no sale.');
        }

        /*
         * Y la red de seguridad: CUALQUIER columna real de la tabla cuyo nombre huela a credencial
         * tiene que estar afuera, incluidas las que agregue una migración futura. Si mañana alguien
         * suma `tiendanube_api_key` o `correo_argentino_user`, esto lo denuncia.
         */
        $columnas = DB::select('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?', ['online_configurations']);

        foreach ($columnas as $columna) {
            $nombre = isset($columna->column_name) ? $columna->column_name : $columna->COLUMN_NAME;

            if (preg_match('/pass|token|secret|_key|credential|client_id|account_id|username|_user_id$/i', $nombre) !== 1) {
                continue;
            }

            $this->assertArrayNotHasKey(
                $nombre,
                $declaracion['campos'],
                $nombre . ': huele a credencial y llegó a la declaración. Agregala a COLUMNAS_EXCLUIDAS_POR_TABLA, no ablandes el regex global.'
            );
        }
    }

    /**
     * Las dos entidades hijas que se sumaron el 21/9/2026: los arqueos de caja y las facturas.
     *
     * @group chat-ia
     * @test
     */
    public function los_arqueos_de_caja_y_las_facturas_emitidas_se_pueden_leer()
    {
        $apertura = EsquemaDeDatosIaHelper::declaracion('apertura_caja');

        $this->assertNotNull($apertura, 'apertura_cajas no tiene user_id: va como hija de cajas.');
        $this->assertEquals('apertura_cajas', $apertura['tabla']);
        $this->assertEquals('cajas', $apertura['padre']['tabla']);
        $this->assertEquals('caja y tesoreria', $apertura['modulo']);
        $this->assertEquals('cajas.name', $apertura['campos']['nombre_caja']['columna']);
        $this->assertTrue(EsquemaDeDatosIaHelper::tiene_moneda($apertura), 'La moneda sale de la caja.');

        foreach (['saldo_apertura', 'saldo_cierre', 'cerrada_at', 'total_ingresos', 'total_egresos'] as $campo) {
            $this->assertArrayHasKey($campo, $apertura['campos'], $campo);
        }

        $factura = EsquemaDeDatosIaHelper::declaracion('factura');

        $this->assertNotNull($factura, 'afip_tickets no tiene user_id: va como hija de sales.');
        $this->assertEquals('afip_tickets', $factura['tabla']);
        $this->assertEquals('sales', $factura['padre']['tabla']);
        $this->assertEquals('facturacion', $factura['modulo']);

        foreach (['cae', 'resultado', 'importe_total', 'punto_venta', 'cbte_numero', 'afip_fecha_emision'] as $campo) {
            $this->assertArrayHasKey($campo, $factura['campos'], $campo);
        }

        /*
         * 🔴 El XML crudo de ARCA NO sale: `request` y `response` son un text por fila que se come
         * la página entera, y los dos json serializados tampoco le contestan nada a nadie.
         */
        foreach (['request', 'response', 'iva_detalle_enviado_json', 'importe_personalizado_ivas_json'] as $campo) {
            $this->assertArrayNotHasKey($campo, $factura['campos'], $campo . ': es ruido de la integración, no un dato del negocio.');
        }

        /*
         * Y `moneda_id` NO se declara: en esta tabla es un varchar con el código de AFIP, no el
         * moneda_id del sistema. Si entrara, `tiene_moneda()` daría true y el aviso de otra moneda
         * compararía texto contra ids de moneda.
         */
        $this->assertArrayNotHasKey('moneda_id', $factura['campos'], 'En afip_tickets moneda_id es el código de AFIP, no el del sistema.');
        $this->assertFalse(EsquemaDeDatosIaHelper::tiene_moneda($factura));
        $this->assertArrayHasKey('moneda', $factura['campos'], 'La moneda legible es `moneda`.');

        // La descripción avisa que las notas de crédito emitidas no cuelgan de una venta y por eso
        // no están acá: es lo que impide que el modelo conteste "no emitiste ninguna".
        $this->assertStringContainsString('notas de credito', $factura['descripcion']);
    }

    /**
     * Las diecisiete curadas siguen con su descripción, su etiqueta, sus campos por defecto (en su
     * orden) y sus relaciones, y lo derivado solo les suma campos que no viajan por defecto.
     *
     * @group chat-ia
     * @test
     */
    public function las_17_curadas_siguen_con_su_descripcion_y_sus_campos_por_defecto()
    {
        $this->assertCount(17, CatalogoDeDatosIaHelper::ENTIDADES);

        foreach (CatalogoDeDatosIaHelper::ENTIDADES as $entidad => $curada) {
            $declaracion = EsquemaDeDatosIaHelper::declaracion($entidad);

            $this->assertNotNull($declaracion, $entidad . ' desapareció del catálogo.');
            $this->assertTrue($declaracion['curada'], $entidad);
            $this->assertEquals($curada['descripcion'], $declaracion['descripcion'], $entidad);
            $this->assertEquals($curada['etiqueta'], $declaracion['etiqueta'], $entidad);
            $this->assertEquals(array_keys($curada['campos']), $declaracion['campos_por_defecto'], $entidad . ': los campos por defecto son los curados, en su orden.');

            foreach ($curada['campos'] as $campo => $definicion) {
                $this->assertEquals($definicion['tipo'], $declaracion['campos'][$campo]['tipo'], $entidad . '.' . $campo . ': el tipo curado manda.');
                $this->assertEquals($definicion['etiqueta'], $declaracion['campos'][$campo]['etiqueta'], $entidad . '.' . $campo . ': la etiqueta curada manda.');
            }

            foreach ($curada['relaciones'] as $clave => $relacion) {
                $this->assertArrayHasKey($clave, $declaracion['relaciones'], $entidad . '.' . $clave);
                $this->assertEquals($relacion['tabla'], $declaracion['relaciones'][$clave]['tabla'], $entidad . '.' . $clave);
                $this->assertEquals($relacion['columna_id'], $declaracion['relaciones'][$clave]['columna_id'], $entidad . '.' . $clave);
            }

            // Lo derivado aporta campos: article tiene noventa y pico de columnas, no trece.
            $this->assertGreaterThan(count($curada['campos']), count($declaracion['campos']), $entidad . ': la derivada tiene que sumar campos.');
        }

        // La descripción de `sale` dejó de decir que incluye consolidaciones (cambió el comportamiento).
        $this->assertStringContainsString('SIN las ventas contenedoras', CatalogoDeDatosIaHelper::ENTIDADES['sale']['descripcion']);
        $this->assertStringNotContainsString('OJO: incluye', CatalogoDeDatosIaHelper::ENTIDADES['sale']['descripcion']);

        $condiciones = array_column(EsquemaDeDatosIaHelper::declaracion('sale')['condiciones_fijas'], 'sql');

        $this->assertContains('(sales.is_consolidacion_facturacion IS NULL OR sales.is_consolidacion_facturacion = 0)', $condiciones);
        $this->assertContains('sales.deleted_at IS NULL', $condiciones);
        $this->assertContains("articles.status = 'active'", array_column(EsquemaDeDatosIaHelper::declaracion('article')['condiciones_fijas'], 'sql'));

        // current_acount entra como curada adicional, con la aclaración de que el saldo es por cuenta.
        $cuenta = EsquemaDeDatosIaHelper::declaracion('current_acount');

        $this->assertTrue($cuenta['curada']);
        $this->assertStringContainsString('consultar_ventas_impagas_de_un_cliente', $cuenta['descripcion']);
    }

    /**
     * Las hijas: padre real, columnas del join reales, el padre con user_id, y los campos del padre
     * expuestos con su columna calificada. `empleado` scopea por owner_id.
     *
     * @group chat-ia
     * @test
     */
    public function las_hijas_tienen_padre_valido()
    {
        foreach (EsquemaDeDatosIaHelper::HIJAS as $entidad => $hija) {
            $declaracion = EsquemaDeDatosIaHelper::declaracion($entidad);

            $this->assertNotNull($declaracion, $entidad);
            $this->assertTrue($declaracion['curada'], $entidad . ': una hija se declara a mano, es curada.');

            if (! isset($hija['padre'])) {
                $this->assertEquals('empleado', $entidad, 'La única hija sin padre es empleado.');
                $this->assertEquals('owner_id', $declaracion['columna_dueno']);
                $this->assertTrue(Schema::hasColumn('users', 'owner_id'));
                continue;
            }

            $padre = $declaracion['padre'];

            $this->assertTrue(Schema::hasTable($padre['tabla']), $entidad);
            $this->assertTrue(Schema::hasColumn($padre['tabla'], 'user_id'), $entidad . ': el padre tiene que tener user_id, es el scope.');
            $this->assertTrue(Schema::hasColumn($padre['tabla'], $padre['columna_padre']), $entidad);
            $this->assertTrue(Schema::hasColumn($declaracion['tabla'], $padre['columna_local']), $entidad);
            $this->assertTrue(EsquemaDeDatosIaHelper::existe($padre['entidad']), $entidad . ': la entidad padre tiene que existir en el catálogo.');

            foreach ($hija['campos_del_padre'] as $campo => $definicion) {
                $this->assertArrayHasKey($campo, $declaracion['campos'], $entidad . '.' . $campo);
                $this->assertEquals($definicion['columna'], $declaracion['campos'][$campo]['columna'], $entidad . '.' . $campo);
                $this->assertStringStartsWith($padre['tabla'] . '.', $declaracion['campos'][$campo]['columna'], $entidad . '.' . $campo . ': el campo del padre va calificado con la tabla del padre.');
            }
        }

        // Los renglones de venta heredan las dos condiciones de la venta y tienen su moneda.
        $renglon = EsquemaDeDatosIaHelper::declaracion('renglon_de_venta');

        $this->assertCount(2, $renglon['condiciones_fijas']);
        $this->assertTrue(EsquemaDeDatosIaHelper::tiene_moneda($renglon));
        $this->assertEquals('sales.moneda_id', $renglon['campos']['moneda_id']['columna']);
        $this->assertArrayHasKey('article', $renglon['relaciones'], 'article_id resuelve a articles.name por convención.');
        $this->assertArrayHasKey('client', $renglon['relaciones'], 'client_id del padre resuelve a clients.name por convención.');

        // Y el movimiento de caja toma la moneda de su caja.
        $this->assertEquals('cajas.moneda_id', EsquemaDeDatosIaHelper::declaracion('movimiento_de_caja')['campos']['moneda_id']['columna']);
    }

    /**
     * Los tipos salen de DATA_TYPE / COLUMN_TYPE, las relaciones de la convención `x_id → xs.name`
     * y sus overrides, y la moneda de etiquetas fijas.
     *
     * @group chat-ia
     * @test
     */
    public function los_tipos_y_las_relaciones_se_derivan_del_esquema()
    {
        $this->assertEquals('checkbox', EsquemaDeDatosIaHelper::tipo_de('tinyint', 'tinyint(1)'));
        $this->assertEquals('number', EsquemaDeDatosIaHelper::tipo_de('tinyint', 'tinyint'));
        $this->assertEquals('number', EsquemaDeDatosIaHelper::tipo_de('decimal', 'decimal(12,2)'));
        $this->assertEquals('number', EsquemaDeDatosIaHelper::tipo_de('bigint', 'bigint unsigned'));
        $this->assertEquals('date', EsquemaDeDatosIaHelper::tipo_de('timestamp', 'timestamp'));
        $this->assertEquals('date', EsquemaDeDatosIaHelper::tipo_de('date', 'date'));
        $this->assertEquals('textarea', EsquemaDeDatosIaHelper::tipo_de('longtext', 'longtext'));
        $this->assertEquals('textarea', EsquemaDeDatosIaHelper::tipo_de('json', 'json'));
        $this->assertEquals('text', EsquemaDeDatosIaHelper::tipo_de('varchar', 'varchar(191)'));
        $this->assertEquals('text', EsquemaDeDatosIaHelper::tipo_de('enum', "enum('a','b')"));

        $sale = EsquemaDeDatosIaHelper::declaracion('sale');

        // Un campo derivado (no curado) con su tipo del esquema y sin viajar por defecto.
        $this->assertEquals('date', $sale['campos']['terminada_at']['tipo']);
        $this->assertContains('terminada_at', $sale['campos_omitidos']);

        // moneda_id: etiquetas fijas, 0/1 pesos y 2 dólares.
        $this->assertEquals('search', $sale['campos']['moneda_id']['tipo']);
        $this->assertEquals(EsquemaDeDatosIaHelper::ETIQUETAS_DE_MONEDA, $sale['relaciones']['moneda']['etiquetas_fijas']);

        // employee_id → users.name (override de convención) y caja_id → cajas.name (convención pura).
        $this->assertEquals('users', $sale['relaciones']['employee']['tabla']);
        $this->assertEquals('cajas', $sale['relaciones']['caja']['tabla']);
        $this->assertTrue($sale['relaciones']['caja']['con_user_id'], 'cajas tiene user_id: el filtro por nombre se scopea.');
        $this->assertFalse($sale['relaciones']['employee']['con_user_id'], 'users no tiene user_id: se scopea por owner_id aparte.');

        // iva_id → ivas.percentage porque ivas no tiene name.
        $this->assertEquals(['tabla' => 'ivas', 'campo' => 'percentage'], array_intersect_key(EsquemaDeDatosIaHelper::declaracion('article')['relaciones']['iva'], ['tabla' => 1, 'campo' => 1]));

        // Un enum expone sus valores.
        $this->assertEquals(['unconfirmed', 'confirmed'], EsquemaDeDatosIaHelper::declaracion('budget')['campos']['status']['valores']);

        // Una columna de relación cuya tabla no existe queda como número (no inventa relaciones).
        $this->assertEquals('number', $sale['campos']['consolidacion_facturacion_id']['tipo']);
    }

    /**
     * La lista para el modelo va agrupada por módulo, con descripción solo en las curadas, y
     * `buscar` la acota sin acentos. Y el caché se puede olvidar y rederivar.
     *
     * @group chat-ia
     * @test
     */
    public function la_lista_para_el_modelo_agrupa_por_modulo_y_el_cache_se_olvida()
    {
        $lista = EsquemaDeDatosIaHelper::lista_para_el_modelo();

        $this->assertCount(count(EsquemaDeDatosIaHelper::entidades()), $lista);

        $modulos_vistos = [];

        foreach ($lista as $fila) {
            $this->assertArrayHasKey('entidad', $fila);
            $this->assertArrayHasKey('etiqueta', $fila);
            $this->assertArrayHasKey('modulo', $fila);

            $curada = EsquemaDeDatosIaHelper::declaracion($fila['entidad'])['curada'];

            $this->assertEquals($curada, isset($fila['descripcion']), $fila['entidad'] . ': la descripción viaja solo en las curadas.');

            if (! in_array($fila['modulo'], $modulos_vistos, true)) {
                $modulos_vistos[] = $fila['modulo'];
            }
        }

        // Agrupada: los módulos aparecen en bloques contiguos, en el orden de MODULOS.
        $esperado = array_values(array_filter(array_merge(array_keys(EsquemaDeDatosIaHelper::MODULOS), ['otros']), function ($modulo) use ($modulos_vistos) {
            return in_array($modulo, $modulos_vistos, true);
        }));

        $this->assertEquals($esperado, $modulos_vistos);
        $this->assertEquals('articulos', $lista[0]['modulo']);

        $acotada = array_column(EsquemaDeDatosIaHelper::lista_para_el_modelo('CHÉQUE'), 'entidad');

        $this->assertContains('cheque', $acotada, 'Sin acentos y sin mayúsculas.');
        $this->assertNotContains('article', $acotada);

        $this->assertEquals([], EsquemaDeDatosIaHelper::lista_para_el_modelo('zzz-nada-que-ver'));

        // El caché: olvidar y rederivar da lo mismo.
        $antes = EsquemaDeDatosIaHelper::catalogo();

        EsquemaDeDatosIaHelper::olvidar();

        $this->assertEquals($antes, EsquemaDeDatosIaHelper::catalogo());
    }
}
