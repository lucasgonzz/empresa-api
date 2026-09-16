<?php

namespace Tests\Unit\Database;

use App\Database\Connectors\RetryingMySqlConnector;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Misión reintento-conexion-mysql (15/9/2026) — RetryingMySqlConnector reintenta la conexión a
 * MySQL, con espera creciente, sólo ante los tres mensajes conocidos de saturación TRANSITORIA de
 * la cuenta compartida de Hostinger, y nunca ante un error real (credenciales, host, base
 * inexistente). Ver el docblock de la clase para el contexto completo.
 *
 * Unitario puro (PHPUnit\Framework\TestCase, no el TestCase de Laravel): no hace falta bootear la
 * aplicación ni tocar ninguna base — ConectorDePruebaConReintento reemplaza tanto la conexión real
 * (intentar_conexion()) como la espera (dormir()) por dobles controlados.
 */
class RetryingMySqlConnectorTest extends TestCase
{
    /**
     * Una PDOException con el mensaje que produciría MySQL/PDO para el fragmento dado.
     *
     * @param  string  $fragmento
     * @return PDOException
     */
    protected function excepcion($fragmento)
    {
        return new PDOException("SQLSTATE[HY000] [1040] {$fragmento} (SQL: select 1)");
    }

    public function test_conecta_a_la_primera_sin_ningun_reintento()
    {
        $conector = new ConectorDePruebaConReintento();
        $conector->comportamiento = ['pdo-simulado'];

        $resultado = $conector->connect([]);

        $this->assertSame('pdo-simulado', $resultado);
        $this->assertSame(1, $conector->intentos);
        $this->assertSame([], $conector->intentos_de_espera, 'Sin fallos, no tiene que dormir nunca.');
    }

    /**
     * @dataProvider mensajes_de_saturacion_transitoria
     */
    public function test_reintenta_y_conecta_ante_cada_forma_de_saturacion_transitoria($fragmento)
    {
        $conector = new ConectorDePruebaConReintento();
        $conector->comportamiento = [
            $this->excepcion($fragmento),
            'pdo-simulado',
        ];

        $resultado = $conector->connect([]);

        $this->assertSame('pdo-simulado', $resultado);
        $this->assertSame(2, $conector->intentos);
        $this->assertSame([1], $conector->intentos_de_espera);
    }

    public function mensajes_de_saturacion_transitoria()
    {
        return [
            'pool lleno del servidor compartido con otros inquilinos' => ['Too many connections'],
            'throttle propio de Hostinger'                            => ['Operation not permitted'],
            'mismo throttle, mensaje distinto'                        => ['Connection refused'],
        ];
    }

    public function test_agota_los_intentos_y_tira_la_ultima_excepcion()
    {
        $conector = new ConectorDePruebaConReintento(3, 1000);
        $ultima = $this->excepcion('Too many connections');
        $conector->comportamiento = [
            $this->excepcion('Too many connections'),
            $this->excepcion('Too many connections'),
            $ultima,
        ];

        try {
            $conector->connect([]);
            $this->fail('Tenía que tirar la excepción del último intento.');
        } catch (PDOException $e) {
            $this->assertSame($ultima, $e);
        }

        $this->assertSame(3, $conector->intentos);
        $this->assertSame(
            [1, 2],
            $conector->intentos_de_espera,
            'Dos esperas entre tres intentos: nunca se duerme después del último fallo.'
        );
    }

    public function test_no_reintenta_un_error_que_no_es_de_saturacion_transitoria()
    {
        $conector = new ConectorDePruebaConReintento();
        $error_real = new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'x'@'y'");
        $conector->comportamiento = [$error_real, 'pdo-simulado'];

        try {
            $conector->connect([]);
            $this->fail('Tenía que tirar el error real sin reintentar.');
        } catch (PDOException $e) {
            $this->assertSame($error_real, $e);
        }

        $this->assertSame(1, $conector->intentos, 'Un error real no dispara ningún reintento.');
        $this->assertSame([], $conector->intentos_de_espera);
    }

    public function test_max_intentos_configurable_por_el_constructor()
    {
        $conector = new ConectorDePruebaConReintento(1); // sin margen para reintentar nunca
        $error = $this->excepcion('Too many connections');
        $conector->comportamiento = [$error, 'pdo-simulado'];

        try {
            $conector->connect([]);
            $this->fail('Con max_intentos=1 no tiene que reintentar.');
        } catch (PDOException $e) {
            $this->assertSame($error, $e);
        }

        $this->assertSame(1, $conector->intentos);
        $this->assertSame([], $conector->intentos_de_espera);
    }

    public function test_el_numero_de_intento_pasado_a_dormir_crece_en_cada_fallo()
    {
        $conector = new ConectorDePruebaConReintento(4, 1000);
        $conector->comportamiento = [
            $this->excepcion('Too many connections'),
            $this->excepcion('Too many connections'),
            $this->excepcion('Too many connections'),
            'pdo-simulado',
        ];

        $conector->connect([]);

        $this->assertSame([1, 2, 3], $conector->intentos_de_espera);
    }

    public function test_es_saturacion_transitoria_clasifica_sin_instanciar_nada()
    {
        $this->assertTrue(RetryingMySqlConnector::es_saturacion_transitoria($this->excepcion('Too many connections')));
        $this->assertTrue(RetryingMySqlConnector::es_saturacion_transitoria($this->excepcion('Operation not permitted')));
        $this->assertTrue(RetryingMySqlConnector::es_saturacion_transitoria($this->excepcion('Connection refused')));
        $this->assertFalse(RetryingMySqlConnector::es_saturacion_transitoria(new PDOException('cualquier otro error')));
    }
}
