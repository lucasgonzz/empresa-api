<?php

namespace Tests\Import\RelacionesParecidas;

use App\Http\Controllers\Helpers\import\relaciones\RelacionesParecidasChequeo;

/**
 * El orden de las consultas a la IA antes de cortar en TOPE_ITEMS_IA (misión
 * importacion-relaciones-parecidas, tanda 4).
 *
 * Con `padre_ref` cada hijo se analiza una vez por cada resolución posible de su padre: un padre
 * con tres candidatos multiplica sus hijos por cuatro. Ordenando solo por filas, los hijos de las
 * resoluciones alternativas (las que casi nunca se eligen) se comían el tope y se recortaban
 * consultas que importan. El orden es: valores sin padre, hijos bajo la resolución preseleccionada
 * del padre, hijos bajo las alternativas; dentro de cada tramo, más filas primero.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class PrioridadDeConsultasTest extends RelacionesParecidasTestCase
{
    public function test_el_tope_se_llena_con_los_valores_sin_padre_y_los_hijos_de_la_preseleccionada()
    {
        $sin_padre = [];

        for ($i = 1; $i <= 5; $i++) {
            $sin_padre['Categoria ' . $i] = $this->info_a_consultar('Categoria ' . $i, 1, 10 + $i);
        }

        /* Alternativas: muchas filas, pero el padre casi nunca termina resuelto así. */
        $alternativas = ['Compartido' => $this->info_a_consultar('Compartido', 1, 999)];

        for ($i = 1; $i <= 100; $i++) {
            $alternativas['Alt ' . $i] = $this->info_a_consultar('Alt ' . $i, 50, 1000 + $i);
        }

        $preseleccionada = ['Compartido' => $this->info_a_consultar('Compartido', 1, 999)];

        for ($i = 1; $i <= 144; $i++) {
            $preseleccionada['Pre ' . $i] = $this->info_a_consultar('Pre ' . $i, $i, 2000 + $i);
        }

        $resultado = $this->armar_consultas([
            ['tipo' => 'categoria', 'es_hijo' => false, 'prioridad' => 0, 'analisis' => ['valores' => $sin_padre]],
            ['tipo' => 'sub_categoria', 'es_hijo' => true, 'prioridad' => 2, 'ref' => 'id:7', 'padres' => ['P'], 'analisis' => ['valores' => $alternativas]],
            ['tipo' => 'sub_categoria', 'es_hijo' => true, 'prioridad' => 1, 'ref' => 'id:8', 'padres' => ['P'], 'analisis' => ['valores' => $preseleccionada]],
        ]);

        $this->assertTrue($resultado['recortado'], 'Hay 251 consultas distintas y entran 150.');
        $this->assertCount(150, $resultado['consultas']);

        $valores = array_map(function ($consulta) {
            return $consulta['valor'];
        }, $resultado['consultas']);

        /* Primero los cinco sin padre, aunque tengan una sola fila. */
        $this->assertSame(['Categoria 1', 'Categoria 2', 'Categoria 3', 'Categoria 4', 'Categoria 5'], array_slice($valores, 0, 5));

        /* Después los de la preseleccionada, por filas descendente. */
        $this->assertSame('Pre 144', $valores[5]);
        $this->assertSame('Pre 143', $valores[6]);
        $this->assertContains('Compartido', $valores, 'La consulta compartida con una alternativa vale como de la preseleccionada.');

        foreach ($valores as $valor) {
            $this->assertStringStartsNotWith('Alt ', $valor, 'Ninguna consulta de una resolución alternativa entra antes que las de la preseleccionada.');
        }

        /* La compartida se pregunta una vez y la respuesta vuelve a los dos grupos. */
        $n = array_search('Compartido', $valores, true) + 1;
        $this->assertCount(2, $resultado['mapa'][$n]);
    }

    public function test_los_hijos_de_la_resolucion_preseleccionada_del_padre_se_consultan_antes()
    {
        /*
         * "Pintureria Sur" se parece a dos categorías cargadas; la primera candidata (la más
         * parecida) es la que queda preseleccionada si la IA la confirma. Cada una tiene una
         * subcategoría parecida a un hijo distinto del archivo, y el de la alternativa tiene más
         * filas: por filas iría primero.
         */
        $primera = $this->crear_categoria('Pinturerias Sur');
        $segunda = $this->crear_categoria('Pintureria del Sur Oeste');

        $this->crear_sub('Rodillo', $primera);
        $this->crear_sub('Pinceles', $segunda);

        $this->falsear_ia(function (array $consulta) {
            return ['elegido' => null];
        });

        $chequear = new \ReflectionMethod(RelacionesParecidasChequeo::class, 'chequear');
        $chequear->setAccessible(true);

        $chequear->invoke(
            null,
            $this->tenant->id,
            ['categoria' => ['Pintureria Sur' => 6]],
            'categoria',
            'sub_categoria',
            ['Pintureria Sur' => ['Rodilo' => 1, 'Pinseles' => 5]]
        );

        $this->assertCount(1, $this->llamadas_relaciones);

        $consultas = $this->llamadas_relaciones[0];

        $this->assertSame('Pintureria Sur', $consultas[0]['valor']);
        $this->assertSame('Pinturerias Sur', $consultas[0]['candidatos'][0]['nombre'], 'Precondición: la primera candidata del padre.');

        $orden = array_map(function ($consulta) {
            return $consulta['tipo'] . '|' . $consulta['valor'];
        }, $consultas);

        $rodilo = array_search('subcategoría de artículos|Rodilo', $orden, true);
        $pinseles = array_search('subcategoría de artículos|Pinseles', $orden, true);

        $this->assertNotFalse($rodilo, json_encode($orden));
        $this->assertNotFalse($pinseles, json_encode($orden));
        $this->assertLessThan($pinseles, $rodilo, 'El hijo bajo la preseleccionada va antes que el de la alternativa, aunque tenga menos filas.');
    }

    /**
     * Un valor en estado "consultar" con un candidato cargado.
     *
     * @param  string $valor
     * @param  int    $filas
     * @param  int    $id_candidato
     * @return array
     */
    protected function info_a_consultar($valor, $filas, $id_candidato)
    {
        return [
            'valor'              => $valor,
            'filas'              => $filas,
            'estado'             => 'consultar',
            'id'                 => null,
            'opciones'           => [],
            'candidatos'         => [['id' => $id_candidato, 'nombre' => 'Candidato ' . $id_candidato, 'puntaje' => 0.7]],
            'candidatos_archivo' => [],
        ];
    }

    /**
     * @param  array $grupos
     * @return array
     */
    protected function armar_consultas(array $grupos)
    {
        $metodo = new \ReflectionMethod(RelacionesParecidasChequeo::class, 'armar_consultas');
        $metodo->setAccessible(true);

        return $metodo->invoke(null, $grupos);
    }
}
