<?php

namespace Tests\Feature\PruebaGratuita;

use App\Http\Controllers\Helpers\LocalImportHelper;
use Tests\TestCase;

/**
 * Las filas «sueltas» de una lista de clientes o proveedores de la prueba (misión prueba-e2e-campana-imperio, ronda 8,
 * A1): un título de grupo sin dos puntos, sin mayúsculas sostenidas y sin palabra de sección ni de total («Mayoristas»),
 * que se creaba como cliente. Regla en LocalImportHelper::es_fila_suelta(); acá, la forma, sin base de datos.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class FilasSueltasEnListasDeContactosTest extends TestCase
{
    const COLUMNAS = ['nombre' => 0, 'cuit' => 1, 'telefono' => 2, 'saldo_actual' => 3];
    const CABECERA = ['Cliente', 'CUIT', 'Tel.', 'Saldo'];

    /** Contactos con algún dato además del nombre (teléfono o solo el saldo, alternados). */
    private static function con_datos(int $cuantos, string $prefijo = 'Cliente'): array
    {
        $filas = [];
        for ($i = 1; $i <= $cuantos; $i++) {
            $filas[] = $i % 3 === 0 ? [$prefijo . ' número ' . $i, null, null, 1000 * $i]
                : [$prefijo . ' número ' . $i, null, '3442 40' . str_pad((string) $i, 4, '0', STR_PAD_LEFT), $i * 100];
        }

        return $filas;
    }

    /** Las filas que quedan afuera: texto => motivo. La cabecera va en la fila 1 y los datos desde la 2. */
    private static function afuera(array $filas): array
    {
        $filas = array_merge([self::CABECERA], $filas);
        $descartes = LocalImportHelper::filas_que_no_son_contactos_de($filas, self::COLUMNAS, 2, count($filas));
        $afuera = [];
        foreach ($descartes as $motivo) {
            $afuera[$motivo[1]] = $motivo[0];
        }

        return $afuera;
    }

    private static function sola(string $nombre): array
    {
        return [$nombre, null, null, null];
    }

    public function test_un_titulo_de_grupo_sin_senal_queda_afuera_arriba_en_el_medio_y_abajo(): void
    {
        foreach (['Mayoristas', 'Gremio de la construcción', 'Particulares', 'Otros', 'Revendedores y gremio'] as $titulo) {
            $datos = self::con_datos(9);
            $this->assertSame([$titulo => 'suelta'], self::afuera(array_merge([self::sola($titulo)], $datos)), 'arriba: ' . $titulo);
            $this->assertSame([$titulo => 'suelta'], self::afuera(array_merge(array_slice($datos, 0, 4), [self::sola($titulo)],
                array_slice($datos, 4))), 'en el medio: ' . $titulo);
        }
        // Dos sueltas en una lista con 8 contactos con datos (4 por cada una): las dos afuera.
        $this->assertSame(['Mayoristas' => 'suelta', 'Minoristas' => 'suelta'],
            self::afuera(array_merge([self::sola('Mayoristas')], self::con_datos(4), [self::sola('Minoristas')], self::con_datos(4, 'Otro'))));
    }

    public function test_los_casos_de_la_planilla_de_la_medicion(): void
    {
        // Título arriba, clientes (tres con solo nombre y saldo), otro título con «:», más clientes y el total.
        $filas = [self::sola('Mayoristas'), ['Ferretería Uno', '20-11111111-1', '1', 15000], ['Corralón Dos', null, '2', 8200.5],
            ['Rosa Suelta', null, null, 3000], ['Pinturería Tres', '20-2', '3', 0], self::sola('Minoristas:'),
            ['Hugo Suelta', null, null, 1200], ['Marta Suelta', null, null, -500], ['Electricidad Cuatro', null, '4', 42000],
            ['Taller Cinco', null, '5', 7300], ['TOTAL', null, null, 77100.5]];
        $this->assertSame(['Mayoristas' => 'suelta', 'Minoristas:' => 'titulo', 'TOTAL' => 'total'], self::afuera($filas));
    }

    public function test_una_lista_de_solo_nombres_entra_entera(): void
    {
        $nombres = array_map(function ($nombre) {
            return self::sola($nombre);
        }, ['Mayoristas', 'Ana Gómez', 'Pirulo', 'Bruno Díaz', 'Otros', 'Carla', 'Dario Paz']);
        $this->assertSame([], self::afuera($nombres));
    }

    public function test_si_la_lista_trae_pocos_contactos_con_datos_no_se_juzga(): void
    {
        // Cuatro con datos: menos que CONTACTOS_CON_DATOS_PARA_SUELTAS.
        $this->assertSame([], self::afuera(array_merge([self::sola('Mayoristas')], self::con_datos(LocalImportHelper::CONTACTOS_CON_DATOS_PARA_SUELTAS - 1))));
        // Dos sueltas con solo 7 contactos con datos (hacen falta 4 por cada una): no se descarta ninguna.
        $this->assertSame([], self::afuera(array_merge([self::sola('Mayoristas')], self::con_datos(7), [self::sola('Minoristas')])));
    }

    public function test_con_mas_de_dos_filas_de_solo_nombre_la_lista_carga_contactos_asi_y_entran_todas(): void
    {
        $filas = array_merge([self::sola('Mayoristas')], self::con_datos(30), [self::sola('Pirulo'), self::sola('Particulares')]);
        $this->assertSame([], self::afuera($filas));
        // Un contacto de solo nombre con forma de nombre propio cuenta para dudar: con él y dos sueltas, entran las tres.
        $filas = array_merge([self::sola('Mayoristas')], self::con_datos(30), [self::sola('Juan Pérez'), self::sola('Minoristas')]);
        $this->assertSame([], self::afuera($filas));
    }

    public function test_los_contactos_de_solo_nombre_con_forma_de_contacto_entran(): void
    {
        // Nombres propios, razones sociales, genéricos, siglas, con números y en mayúsculas (lo juzga la señal débil).
        foreach (['Juan Pérez', 'Zona Franca Repuestos', 'Electricidad Total SRL', 'Consumidor final', 'Mostrador', 'YPF', 'ACA',
            'Taller Ruta 14', 'Gómez y Cía', 'Pérez Hnos', 'Grupo Lopez', 'Sucursal Centro'] as $nombre) {
            $datos = self::con_datos(9);
            $this->assertSame([], self::afuera(array_merge([self::sola($nombre)], $datos)), 'arriba: ' . $nombre);
            $this->assertSame([], self::afuera(array_merge(array_slice($datos, 0, 4), [self::sola($nombre)], array_slice($datos, 4))),
                'en el medio: ' . $nombre);
        }
        // Con solo nombre y saldo es un contacto con datos, no una suelta.
        $this->assertSame([], self::afuera(array_merge([['Mayoristas', null, null, 1500]], self::con_datos(9))));
    }

    public function test_fuera_de_la_prueba_no_hay_contexto_y_no_se_descarta(): void
    {
        // Sin contexto (solo totales y decorados), una fila de solo nombre siempre es un contacto.
        $this->assertNull(LocalImportHelper::motivo_de_fila_que_no_es_un_contacto(self::sola('Mayoristas'), self::COLUMNAS));
    }
}
