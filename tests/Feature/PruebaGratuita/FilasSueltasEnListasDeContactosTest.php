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
        // Cuarta pasada (A3): sin tope, pero con los contactos con datos en clara mayoría (2 por cada fila de solo
        // nombre). Cuatro grupos con 7 contactos con datos: no alcanza, entran (cambió a propósito: antes «dos sueltas
        // con 7» entraban por el tope de dos; ahora quedan afuera, ver el test de abajo).
        $this->assertSame([], self::afuera(array_merge([self::sola('Mayoristas'), self::sola('Minoristas')], self::con_datos(7),
            [self::sola('Particulares'), self::sola('Revendedores')])));
        $this->assertSame(['Mayoristas' => 'suelta', 'Minoristas' => 'suelta'],
            self::afuera(array_merge([self::sola('Mayoristas')], self::con_datos(7), [self::sola('Minoristas')])));
    }

    public function test_sin_tope_los_grupos_con_senal_quedan_afuera_y_los_contactos_de_solo_nombre_entran(): void
    {
        // Cuarta pasada (A3; cambió a propósito: con el tope de dos «dudaba» y entraban todas). Los grupos con señal
        // quedan afuera aunque sean varios; los contactos de solo nombre sin señal entran.
        $filas = array_merge([self::sola('Mayoristas')], self::con_datos(30), [self::sola('Pirulo'), self::sola('Particulares')]);
        $this->assertSame(['Mayoristas' => 'suelta', 'Particulares' => 'suelta'], self::afuera($filas));
        $filas = array_merge([self::sola('Mayoristas')], self::con_datos(30), [self::sola('Juan Pérez'), self::sola('Minoristas')]);
        $this->assertSame(['Mayoristas' => 'suelta', 'Minoristas' => 'suelta'], self::afuera($filas));
        // Cuatro grupos con señal (vocabulario, preposición, negrita exclusiva y mayúsculas) y contactos reales de solo
        // nombre mezclados, en una lista de 12 con datos.
        $filas = array_merge([self::sola('clientes del centro')], self::con_datos(4), [self::sola('Gómez'), self::sola('Del barrio')],
            // Quinta pasada (A2): el título en negrita encabeza su grupo (seguido de un contacto con datos).
            self::con_datos(4, 'Medio'), [self::sola('Kiosco'), self::sola('Fiado')], self::con_datos(2, 'Fiado'), [self::sola('DEUDORES VARIOS')],
            self::con_datos(4, 'Fin'));
        $formato = [];
        foreach ($filas as $i => $fila) {
            if ($fila[0] === 'Fiado') {
                $formato[$i + 2] = LocalImportHelper::FORMATO_NEGRITA;
            }
        }
        $con_cabecera = array_merge([self::CABECERA], $filas);
        $descartes = LocalImportHelper::filas_que_no_son_contactos_de($con_cabecera, self::COLUMNAS, 2, count($con_cabecera), $formato);
        $this->assertSame(['clientes del centro', 'Del barrio', 'Fiado', 'DEUDORES VARIOS'], array_values(array_column($descartes, 1)));
    }

    public function test_cuarta_pasada_el_formato_cuenta_solo_la_negrita_exclusiva_y_la_combinada_ancha(): void
    {
        $filas = [self::CABECERA, self::sola('Gómez')];
        for ($i = 1; $i <= 9; $i++) {
            $filas[] = ['Contacto con datos ' . $i, null, '3442 40' . $i, 1000 * $i];
        }
        $afuera = function (array $formato) use ($filas) {
            return LocalImportHelper::filas_que_no_son_contactos_de($filas, self::COLUMNAS, 2, count($filas), $formato);
        };
        // Negrita con algún contacto con datos también en negrita (los morosos en rojo y negrita, filas alternadas):
        // no distingue, «Gómez» entra.
        $this->assertSame([], $afuera([2 => LocalImportHelper::FORMATO_NEGRITA, 5 => LocalImportHelper::FORMATO_NEGRITA]));
        // Negrita exclusiva: afuera. Combinada ancha: afuera aunque haya negritas en los datos.
        $this->assertSame([2 => ['suelta', 'Gómez']], $afuera([2 => LocalImportHelper::FORMATO_NEGRITA]));
        $this->assertSame([2 => ['suelta', 'Gómez']], $afuera([2 => LocalImportHelper::FORMATO_COMBINADA_ANCHA, 5 => LocalImportHelper::FORMATO_NEGRITA]));
    }

    public function test_cuarta_pasada_en_una_lista_en_minuscula_la_minuscula_no_dice_nada(): void
    {
        $filas = [self::CABECERA];
        foreach (['juan gomez', 'ana diaz', 'carlos ruiz', 'marta paz', 'hugo rey', 'elsa sosa', 'luis vega', 'rosa luna', 'pedro gil'] as $i => $nombre) {
            $filas[] = [$nombre, null, '3442 40' . $i, 100 * ($i + 1)];
        }
        foreach (['de la torre', 'del valle', 'de los santos', 'mayoristas del centro'] as $nombre) {
            $lista = $filas;
            array_splice($lista, 4, 0, [self::sola($nombre)]);
            $this->assertSame([], LocalImportHelper::filas_que_no_son_contactos_de($lista, self::COLUMNAS, 2, count($lista)), $nombre);
        }
        // Una palabra sola del vocabulario sigue valiendo.
        $lista = $filas;
        array_splice($lista, 4, 0, [self::sola('mayoristas')]);
        $this->assertSame(['mayoristas'], array_values(array_column(LocalImportHelper::filas_que_no_son_contactos_de($lista, self::COLUMNAS, 2,
            count($lista)), 1)));
        // Y en una lista con mayúsculas, la preposición en minúscula es un grupo.
        $this->assertSame(['de la zona norte' => 'suelta'], self::afuera(array_merge([self::sola('de la zona norte')], self::con_datos(9))));
    }

    public function test_cuarta_pasada_ya_no_son_grupos_nuevo_viejo_contado_activo(): void
    {
        foreach (['Nuevo', 'Viejo', 'Contado', 'Activo', 'Nuevos', 'Viejos'] as $nombre) {
            $this->assertSame([], self::afuera(array_merge([self::sola($nombre)], self::con_datos(9))), $nombre);
        }
    }

    public function test_cuarta_pasada_las_notas_con_forma_de_oracion_y_el_pie_debajo_del_total(): void
    {
        $nota = 'Fulana dejó plata de más, descontarle en la próxima compra';
        // Debajo del total sin nombre (el rótulo en la columna del teléfono) y de una fila vacía: afuera, y el total
        // también se nombra.
        $filas = array_merge([self::CABECERA], self::con_datos(9), [[null, null, 'TOTAL', 45000], [null, null, null, null], self::sola($nota)]);
        $descartes = LocalImportHelper::filas_que_no_son_contactos_de($filas, self::COLUMNAS, 2, count($filas));
        $this->assertSame([11 => ['total', 'TOTAL'], 13 => ['nota', $nota]], $descartes);
        // Una nota de solo nombre debajo del total (sin forma de oración) también.
        $filas = array_merge([self::CABECERA], self::con_datos(9), [[null, null, 'TOTAL', 45000], self::sola('Pedro')]);
        $this->assertSame([11 => ['total', 'TOTAL'], 12 => ['nota', 'Pedro']],
            LocalImportHelper::filas_que_no_son_contactos_de($filas, self::COLUMNAS, 2, count($filas)));
        // Arriba y en el medio: afuera por su forma.
        foreach (['ojo: este paga los viernes, no antes', 'Avisar a Marta que pasó el cheque y todavía no lo cobramos'] as $texto) {
            $this->assertSame([$texto => 'nota'], self::afuera(array_merge([self::sola($texto)], self::con_datos(9))), 'arriba: ' . $texto);
            $this->assertSame([$texto => 'nota'], self::afuera(array_merge(self::con_datos(4), [self::sola($texto)], self::con_datos(5, 'Otro'))),
                'en el medio: ' . $texto);
        }
        // Nombres largos o con coma siguen entrando.
        foreach (['Consorcio Edificio Rivadavia 845', 'Cooperativa de Trabajo La Unión Limitada', 'Gómez, Jorge (albañil)',
            'Pérez, Juan Carlos', 'Asociación de Fomento Villa del Parque'] as $nombre) {
            $this->assertSame([], self::afuera(array_merge(self::con_datos(4), [self::sola($nombre)], self::con_datos(5, 'Otro'))), $nombre);
        }
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

    /**
     * Segunda pasada de A1: una fila de solo nombre ENTRA salvo una señal positiva de grupo. Cada caso, solo, en una
     * lista de 9 contactos con datos (teléfono y saldo), arriba y en el medio.
     */
    public function test_segunda_pasada_entran_los_contactos_y_quedan_afuera_solo_los_grupos(): void
    {
        $entran = ['Gómez', 'Carla', 'Martín', 'juan perez', 'Juan pérez', 'Kiosco', 'Panadería', 'la esquina', 'Pirulo', 'Del Valle',
            'De la Fuente', 'Obras Sanitarias', 'Empresas Reunidas SA', 'Doña Rosa', 'Rotisería El Gallo', 'YPF', 'Taller Ruta 14'];
        $afuera = ['Del barrio', 'Clientes del taller', 'Empresas', 'Mayoristas', 'Minoristas', 'Particulares', 'Otros',
            'Vecinos del barrio', 'Gremio de la construcción', 'Mayoristas y distribuidores', 'Revendedores'];
        $datos = [];
        for ($i = 1; $i <= 9; $i++) {
            $datos[] = ['Contacto con datos ' . $i, null, '3442 40' . str_pad((string) $i, 4, '0', STR_PAD_LEFT), 1000 * $i];
        }
        foreach ($entran as $nombre) {
            $this->assertSame([], self::afuera(array_merge([self::sola($nombre)], $datos)), 'arriba, entra: ' . $nombre);
            $this->assertSame([], self::afuera(array_merge(array_slice($datos, 0, 5), [self::sola($nombre)], array_slice($datos, 5))),
                'en el medio, entra: ' . $nombre);
        }
        foreach ($afuera as $nombre) {
            $this->assertSame([$nombre], array_keys(self::afuera(array_merge([self::sola($nombre)], $datos))), 'arriba, afuera: ' . $nombre);
            $this->assertSame([$nombre], array_keys(self::afuera(array_merge(array_slice($datos, 0, 5), [self::sola($nombre)],
                array_slice($datos, 5)))), 'en el medio, afuera: ' . $nombre);
        }
        // Más casos de la señal: preposición con minúscula afuera; con apellido, adentro; «cuenta» solo con «corriente».
        foreach (['De la zona norte', 'Para cobrar', 'Por zona', 'Cuenta corriente', 'Cta cte', 'Al día'] as $nombre) {
            $this->assertSame([$nombre], array_keys(self::afuera(array_merge([self::sola($nombre)], $datos))), 'afuera: ' . $nombre);
        }
        foreach (['Del Río', 'Cuenta', 'Varios', 'Clientes Unidos SRL', 'De'] as $nombre) {
            $this->assertSame([], self::afuera(array_merge([self::sola($nombre)], $datos)), 'entra: ' . $nombre);
        }
        // Un contacto real de solo nombre y un grupo en la misma lista: el contacto entra y el grupo queda afuera.
        $this->assertSame(['Mayoristas'], array_keys(self::afuera(array_merge([self::sola('Mayoristas')], $datos, [self::sola('Gómez')]))));
    }

    public function test_tercera_pasada_la_senal_de_formato(): void
    {
        $filas = [self::CABECERA, self::sola('Fiado')];
        for ($i = 1; $i <= 9; $i++) {
            $filas[] = ['Contacto con datos ' . $i, null, '3442 40' . $i, 1000 * $i];
        }
        $afuera = function (array $formato) use ($filas) {
            return LocalImportHelper::filas_que_no_son_contactos_de($filas, self::COLUMNAS, 2, count($filas), $formato);
        };
        $this->assertSame([2 => ['suelta', 'Fiado']], $afuera([2 => true]), 'En negrita y los demás no: afuera.');
        $this->assertSame([], $afuera([]), 'Sin formato: entra.');
        $todas = array_fill_keys(range(2, 11), true);
        $this->assertSame([], $afuera($todas), 'Toda la columna en negrita no es señal.');
        // La mitad de los contactos con datos en negrita: tampoco distingue.
        $this->assertSame([], $afuera([2 => true, 3 => true, 4 => true, 5 => true, 6 => true, 7 => true]));
        // Una razón social destacada entra igual; sin las condiciones de lista no se juzga.
        $filas[1] = self::sola('Constructora Norte SRL');
        $this->assertSame([], LocalImportHelper::filas_que_no_son_contactos_de($filas, self::COLUMNAS, 2, count($filas), [2 => true]));
        $cortas = array_slice($filas, 0, 5);
        $cortas[1] = self::sola('Fiado');
        $this->assertSame([], LocalImportHelper::filas_que_no_son_contactos_de($cortas, self::COLUMNAS, 2, count($cortas), [2 => true]));
    }

    // -----------------------------------------------------------------------------------------------------
    // Quinta pasada: ante la duda, entra
    // -----------------------------------------------------------------------------------------------------

    public function test_quinta_pasada_a1_los_clientes_con_sena_en_el_medio_entran_y_las_anotaciones_no(): void
    {
        foreach (['Pérez, el de la esquina', 'juan perez, el plomero de la vuelta', 'Don Pepe, sobrino de la vecina',
            'Taller de chapa y pintura el rulo', 'la casa del tornillo y la herramienta'] as $nombre) {
            $this->assertSame([], self::afuera(array_merge(self::con_datos(4), [self::sola($nombre)], self::con_datos(5, 'Otro'))), $nombre);
        }
        foreach (['ojo: este paga los viernes, no antes', 'Avisar a Marta que pasó el cheque', 'Precios sujetos a cambio',
            'Nota: cobrar a fin de mes', '(ver con Hugo)', '* revisar saldos', 'Pendiente: llamar'] as $nota) {
            $this->assertSame([$nota => 'nota'], self::afuera(array_merge(self::con_datos(4), [self::sola($nota)], self::con_datos(5, 'Otro'))), $nota);
        }
        // «Ver Pérez» o «Falta Gómez» con mayúscula después son nombres.
        foreach (['Ver Pérez', 'Falta Gómez'] as $nombre) {
            $this->assertSame([], self::afuera(array_merge(self::con_datos(4), [self::sola($nombre)], self::con_datos(5, 'Otro'))), $nombre);
        }
        // En la cabecera (arriba del primer contacto con datos) y en el pie, la forma de oración sí vale.
        $nota = 'Fulana dejó plata de más, descontarle en la próxima compra';
        $this->assertSame([$nota => 'nota'], self::afuera(array_merge([self::sola($nota)], self::con_datos(9))));
        $this->assertSame([$nota => 'nota'], self::afuera(array_merge(self::con_datos(9), [self::sola($nota)])));
    }

    public function test_quinta_pasada_a2_la_negrita_sin_estructura_de_grupos_no_es_senal(): void
    {
        // 20 con datos y 8 de solo nombre en negrita, dispersos (el dueño marca así a los que no tienen teléfono).
        $filas = [self::CABECERA];
        $formato = [];
        $datos = self::con_datos(20);
        foreach ($datos as $i => $fila) {
            $filas[] = $fila;
            if ($i % 2 === 1 && $i < 17) {
                $filas[] = self::sola('Cliente sin teléfono ' . chr(65 + $i));
                $formato[count($filas)] = LocalImportHelper::FORMATO_NEGRITA;
            }
        }
        $this->assertCount(8, $formato);
        $this->assertSame([], LocalImportHelper::filas_que_no_son_contactos_de($filas, self::COLUMNAS, 2, count($filas), $formato));
        // Ni seguidos, aunque arranque con uno: dos en negrita juntos rompen la estructura.
        $filas = array_merge([self::CABECERA, self::sola('Fiado'), self::sola('Gómez')], self::con_datos(9));
        $this->assertSame([], LocalImportHelper::filas_que_no_son_contactos_de($filas, self::COLUMNAS, 2, count($filas),
            [2 => LocalImportHelper::FORMATO_NEGRITA, 3 => LocalImportHelper::FORMATO_NEGRITA]));
        // Con estructura (cada título encabeza su grupo): afuera, aunque el texto no esté en el vocabulario.
        $filas = array_merge([self::CABECERA, self::sola('Fiado')], self::con_datos(4), [self::sola('Constructoras')], self::con_datos(4, 'B'),
            [self::sola('Los de siempre')], self::con_datos(4, 'C'));
        $this->assertSame([2 => ['suelta', 'Fiado'], 7 => ['suelta', 'Constructoras'], 12 => ['suelta', 'Los de siempre']],
            LocalImportHelper::filas_que_no_son_contactos_de($filas, self::COLUMNAS, 2, count($filas),
                [2 => LocalImportHelper::FORMATO_NEGRITA, 7 => LocalImportHelper::FORMATO_NEGRITA, 12 => LocalImportHelper::FORMATO_NEGRITA]));
        // Más de MAXIMO_DE_GRUPOS_CON_FORMATO grupos: no es una lista con grupos, entran todos.
        $filas = [self::CABECERA];
        $formato = [];
        for ($g = 1; $g <= LocalImportHelper::MAXIMO_DE_GRUPOS_CON_FORMATO + 1; $g++) {
            $filas[] = self::sola('Grupito ' . chr(64 + $g));
            $formato[count($filas)] = LocalImportHelper::FORMATO_NEGRITA;
            foreach (self::con_datos(2, 'G' . $g) as $fila) {
                $filas[] = $fila;
            }
        }
        $this->assertSame([], LocalImportHelper::filas_que_no_son_contactos_de($filas, self::COLUMNAS, 2, count($filas), $formato));
    }

    public function test_quinta_pasada_a3_un_total_arriba_no_abre_el_pie(): void
    {
        foreach ([[null, null, 'TOTAL', 2100], ['TOTAL', null, null, 2100]] as $total) {
            $filas = array_merge([self::CABECERA, $total], self::con_datos(9), [self::sola('Gómez'), self::sola('Kiosco')]);
            $descartes = LocalImportHelper::filas_que_no_son_contactos_de($filas, self::COLUMNAS, 2, count($filas));
            $this->assertSame([2 => ['total', 'TOTAL']], $descartes, json_encode($total));
        }
        // Un subtotal en el medio tampoco: los de solo nombre de abajo entran.
        $filas = array_merge([self::CABECERA], self::con_datos(4), [['Subtotal', null, null, 1000]], self::con_datos(5, 'B'), [self::sola('Gómez')]);
        $this->assertSame([6 => ['total', 'Subtotal']], LocalImportHelper::filas_que_no_son_contactos_de($filas, self::COLUMNAS, 2, count($filas)));
        // Con el 80 % de los contactos arriba sí abre el pie.
        $filas = array_merge([self::CABECERA], self::con_datos(9), [['TOTAL', null, null, 1000], self::sola('Pedro')]);
        $this->assertSame([11 => ['total', 'TOTAL'], 12 => ['nota', 'Pedro']],
            LocalImportHelper::filas_que_no_son_contactos_de($filas, self::COLUMNAS, 2, count($filas)));
    }

    public function test_quinta_pasada_a4_sin_senal_varias_filas_de_solo_nombre_entran_todas(): void
    {
        $filas = array_merge([self::sola('Gómez')], self::con_datos(3), [self::sola('Kiosco'), self::sola('la esquina')], self::con_datos(3, 'B'),
            [self::sola('Pirulo'), self::sola('Doña Rosa'), self::sola('Martín')], self::con_datos(3, 'C'));
        $this->assertSame([], self::afuera($filas));
    }
}
