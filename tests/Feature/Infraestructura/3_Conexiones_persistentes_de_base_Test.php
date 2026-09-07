<?php

namespace Tests\Feature\Infraestructura;

use PDO;
use Tests\EmpresaTestCase;

/**
 * Mision conexiones-persistentes — PDO::ATTR_PERSISTENT detras de DB_PERSISTENT, apagada por defecto.
 *
 * El 7/9/2026 los ~31 clientes del shared hosting de Hostinger se cayeron todos juntos con
 * "SQLSTATE[HY000] [2002] Operation not permitted": el hosting limita las conexiones NUEVAS por
 * segundo de la CUENTA entera (medido en el propio servidor: entran ~18 en rafaga y despues se
 * rechazan todas por varios segundos), y Laravel abre una conexion nueva por request. La
 * mitigacion se aplico a mano editando config/database.php en las 32 instancias, asi que el
 * proximo despliegue la pisaba; este test fija el mismo cambio ya en el repo.
 *
 * Lo que protege son los dos lados del riesgo:
 *
 *   - Que mergear esto NO le cambie nada a nadie. La opcion viaja dentro de un array_filter(), que
 *     borra toda clave falsy: sin DB_PERSISTENT en el .env la clave no llega al PDO y la conexion
 *     se arma igual que siempre. Es lo unico que vuelve seguro mergearlo sin coordinar con las 32
 *     instancias, y es el test 1.
 *
 *   - Que apagarla se pueda apagar de verdad. El .env devuelve strings y en PHP (bool) "false" es
 *     TRUE, asi que un cast pelado dejaria prendida una instancia que escribio DB_PERSISTENT=false
 *     para apagarla. De ahi el filter_var(..., FILTER_VALIDATE_BOOLEAN).
 *
 * ⚠️ Medido el 7/9/2026 y vale la pena saberlo: en este Laravel, env() YA convierte los strings
 * "true"/"false" exactos a booleanos (Illuminate\Support\Env::getOption), asi que el string "false"
 * solo NO distingue filter_var de un cast pelado — con las dos implementaciones da apagado. Los
 * valores que si los distinguen son "off", "no" y " false " (con espacios): ahi env() devuelve el
 * string crudo, un (bool) pelado da TRUE y prenderia la persistencia sin que nadie lo pidiera.
 * Por eso ademas del caso "false" hay un test aparte con esos valores: es el que de verdad se pone
 * en rojo si alguien cambia el filter_var por un cast.
 *
 * Los tests leen la config ejercitando el archivo real (config/database.php se vuelve a evaluar con
 * la variable forzada), no una copia de la logica aca adentro: si el archivo cambia, el test se
 * entera.
 */
class Conexiones_persistentes_de_base_Test extends EmpresaTestCase
{
    /** Valor que tenia DB_PERSISTENT antes del test, para devolver el entorno como estaba. */
    protected $valor_original = null;

    /** ¿La variable existia siquiera antes del test? */
    protected $existia_original = false;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->existia_original = array_key_exists('DB_PERSISTENT', $_SERVER)
            || array_key_exists('DB_PERSISTENT', $_ENV);

        if ($this->existia_original) {
            $this->valor_original = isset($_SERVER['DB_PERSISTENT'])
                ? $_SERVER['DB_PERSISTENT']
                : $_ENV['DB_PERSISTENT'];
        }
    }

    /**
     * Deja el entorno como estaba: si no, el resto de la suite corre con DB_PERSISTENT seteada.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->forzar_variable($this->existia_original ? $this->valor_original : null);

        parent::tearDown();
    }

    /**
     * Fuerza (o borra, con null) DB_PERSISTENT en las tres fuentes que lee el repositorio de
     * variables de Laravel: $_ENV, $_SERVER y putenv.
     *
     * @param  string|null  $valor
     * @return void
     */
    protected function forzar_variable($valor)
    {
        if ($valor === null) {
            unset($_ENV['DB_PERSISTENT'], $_SERVER['DB_PERSISTENT']);
            putenv('DB_PERSISTENT');

            return;
        }

        $_ENV['DB_PERSISTENT'] = $valor;
        $_SERVER['DB_PERSISTENT'] = $valor;
        putenv('DB_PERSISTENT=' . $valor);
    }

    /**
     * Vuelve a evaluar config/database.php con la variable en el valor pedido y devuelve la
     * conexion mysql ya resuelta.
     *
     * Es a proposito que sea el archivo real y no config('database...'): la config se resolvio una
     * sola vez al bootear la app, y leerla de ahi no ejercitaria el env() ni el array_filter() que
     * son justamente lo que este test tiene que proteger.
     *
     * @param  string|null  $valor
     * @return array
     */
    protected function conexion_mysql_con($valor)
    {
        $this->forzar_variable($valor);

        $configuracion = require base_path('config/database.php');

        return $configuracion['connections']['mysql'];
    }

    /**
     * Las options de la conexion mysql con DB_PERSISTENT en el valor pedido.
     *
     * @param  string|null  $valor
     * @return array
     */
    protected function opciones_con($valor)
    {
        $conexion = $this->conexion_mysql_con($valor);

        return $conexion['options'];
    }

    /**
     * Sin pdo_mysql el array de options sale vacio por diseño y no hay nada que medir.
     *
     * @return void
     */
    protected function exigir_pdo_mysql()
    {
        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('Sin la extension pdo_mysql, config/database.php devuelve options vacio a proposito.');
        }
    }

    /**
     * El caso que hace seguro mergear esto: una instancia que nunca declaro la variable arma la
     * conexion exactamente igual que antes del cambio.
     *
     * Si este test se pone en rojo, el cambio dejo de ser transparente y no se puede desplegar sin
     * avisarle a las 32 instancias.
     *
     * @return void
     */
    public function test_sin_la_variable_la_opcion_persistente_no_llega_al_pdo()
    {
        $this->exigir_pdo_mysql();

        $opciones = $this->opciones_con(null);

        $this->assertArrayNotHasKey(
            PDO::ATTR_PERSISTENT,
            $opciones,
            'Sin DB_PERSISTENT en el .env la opcion NO puede llegar al PDO: el array_filter() tiene '
                . 'que borrarla, o mergear esto le cambiaria la conexion a toda instancia que no la declare.'
        );
    }

    /**
     * Prendida, la opcion llega al PDO en true. Es la mitigacion del incidente del 7/9/2026.
     *
     * @return void
     */
    public function test_con_db_persistent_en_true_la_opcion_llega_prendida()
    {
        $this->exigir_pdo_mysql();

        $opciones = $this->opciones_con('true');

        $this->assertArrayHasKey(
            PDO::ATTR_PERSISTENT,
            $opciones,
            'Con DB_PERSISTENT=true la opcion tiene que llegar al PDO: es lo que reusa la conexion '
                . 'y saca a la instancia del limite de conexiones nuevas por segundo del hosting.'
        );

        $this->assertTrue(
            $opciones[PDO::ATTR_PERSISTENT],
            'La opcion tiene que valer true booleano, no el string "true".'
        );
    }

    /**
     * Apagada explicitamente, no llega.
     *
     * @return void
     */
    public function test_con_db_persistent_en_false_la_opcion_no_llega()
    {
        $this->exigir_pdo_mysql();

        $opciones = $this->opciones_con('false');

        $this->assertArrayNotHasKey(
            PDO::ATTR_PERSISTENT,
            $opciones,
            'Con DB_PERSISTENT=false la opcion no puede llegar al PDO.'
        );
    }

    /**
     * El test que de verdad se pone en rojo si alguien cambia el filter_var por un (bool) pelado.
     *
     * Estos son los valores donde env() devuelve el string crudo (no los convierte, como si hace
     * con "true"/"false" exactos) y donde (bool) da TRUE: con un cast pelado, una instancia que
     * escribio "off" o "no" para apagar la persistencia la tendria prendida sin enterarse.
     *
     * @return void
     */
    public function test_los_valores_de_apagado_que_un_cast_pelado_dejaria_prendidos()
    {
        $this->exigir_pdo_mysql();

        $valores_apagados = ['off', 'no', ' false ', '0'];

        foreach ($valores_apagados as $valor) {
            $opciones = $this->opciones_con($valor);

            $this->assertArrayNotHasKey(
                PDO::ATTR_PERSISTENT,
                $opciones,
                'DB_PERSISTENT="' . $valor . '" tiene que dejar la persistencia APAGADA. Si esto '
                    . 'esta en rojo, el filter_var(..., FILTER_VALIDATE_BOOLEAN) se cambio por un '
                    . 'cast a bool, y en PHP (bool) "' . $valor . '" es true.'
            );
        }
    }

    /**
     * Prender la persistencia no puede cambiar ninguna otra cosa de la conexion.
     *
     * Sin este test, un array_filter() mal cerrado o una coma de mas que se llevara puesto el
     * MYSQL_ATTR_SSL_CA (o el charset, o el engine) pasaria los cuatro tests de arriba sin que
     * nadie se entere.
     *
     * @return void
     */
    public function test_prender_la_persistencia_no_toca_nada_mas_de_la_conexion()
    {
        $this->exigir_pdo_mysql();

        $apagada = $this->conexion_mysql_con(null);
        $prendida = $this->conexion_mysql_con('true');

        unset($apagada['options'], $prendida['options']);

        $this->assertEquals(
            $apagada,
            $prendida,
            'DB_PERSISTENT solo puede afectar el array de options. Cualquier otra clave de la '
                . 'conexion mysql tiene que quedar igual este prendida o apagada.'
        );

        $opciones_apagada = $this->opciones_con(null);
        $opciones_prendida = $this->opciones_con('true');

        $this->assertEquals(
            [PDO::ATTR_PERSISTENT],
            array_values(array_diff(array_keys($opciones_prendida), array_keys($opciones_apagada))),
            'Lo unico que puede aparecer en options al prender DB_PERSISTENT es ATTR_PERSISTENT.'
        );

        $this->assertEmpty(
            array_diff(array_keys($opciones_apagada), array_keys($opciones_prendida)),
            'Prender DB_PERSISTENT no puede hacer desaparecer ninguna opcion que ya estaba.'
        );
    }
}
