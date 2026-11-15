<?php

namespace Tests\Feature\PruebaGratuita;

use App\Imports\ClientImport;
use App\Imports\ProviderImport;
use App\Models\Client;
use App\Models\Provider;
use Tests\EmpresaTestCase;

/**
 * Títulos de sección, cabecera repetida, notas al pie y el pie que cuenta en una lista de clientes o proveedores
 * de la prueba (misión prueba-e2e-campana-imperio, ronda 6, ítem E8.2, 14/11/2026).
 *
 * E8.1 dejó afuera el total y los títulos decorados. Faltaba la misma clase sin decorar: un rótulo que separa «los
 * que deben» de «los que están al día» entraba como un cliente sin datos y con saldo 0. Las planillas se arman acá.
 * Se prueba ClientImport/ProviderImport con el interruptor que prenden el modal y la importación clásica cuando el
 * negocio está en la prueba (descartando_filas_que_no_son_contactos()); sin él, todo entra como hoy.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class ImportacionDePruebaTitulosEnListasDeContactosTest extends EmpresaTestCase
{
    const COLUMNAS = ['nombre' => 0, 'cuit' => 1, 'telefono' => 2, 'saldo_actual' => 3];
    const CABECERA = ['Cliente', 'CUIT', 'Tel', 'Debe'];

    /** Ocho clientes con datos (nombres en minúscula y mayúscula, como se escriben), con un prefijo para no chocar. */
    private function con_datos(string $prefijo, int $cuantos = 8): array
    {
        $filas = [];
        for ($i = 1; $i <= $cuantos; $i++) {
            $filas[] = [$prefijo . ' Cliente número ' . $i, null, '3442 40' . str_pad((string) $i, 4, '0', STR_PAD_LEFT), $i * 1000];
        }

        return $filas;
    }

    /**
     * Corre la importación de clientes sobre las filas (la primera, la cabecera) y devuelve [nombres creados, filas
     * que dejó afuera].
     */
    private function importar(array $filas, bool $en_la_prueba = true, string $clase = ClientImport::class): array
    {
        $modelo = $clase === ClientImport::class ? Client::class : Provider::class;
        $antes = (int) $modelo::withTrashed()->max('id');
        $import = $clase === ClientImport::class
            ? new ClientImport(self::COLUMNAS, true, 2, null)
            : new ProviderImport(self::COLUMNAS, true, 2, null, null);
        if ($en_la_prueba) {
            $import->descartando_filas_que_no_son_contactos();
        }
        $import->collection(collect($filas));

        return [
            $modelo::where('user_id', auth()->user()->id)->where('id', '>', $antes)->orderBy('id')->pluck('name')->all(),
            array_column($import->filas_que_no_son_contactos(), 'nombre'),
        ];
    }

    public function test_un_titulo_de_seccion_en_el_medio_se_deja_afuera(): void
    {
        foreach (['LOS QUE ESTÁN AL DÍA', 'Zona norte:', 'Morosos', 'Clientes del interior', 'DEUDORES'] as $titulo) {
            $prefijo = 'T' . substr(md5($titulo), 0, 6);
            $datos = $this->con_datos($prefijo);
            $filas = array_merge([self::CABECERA], array_slice($datos, 0, 4), [[$titulo, null, null, null]], array_slice($datos, 4));
            [$creados, $afuera] = $this->importar($filas);
            $this->assertSame([$titulo], $afuera, $titulo);
            $this->assertCount(8, $creados, $titulo);
            $this->assertNotContains($titulo, $creados, $titulo);
        }
    }

    public function test_un_cliente_que_solo_trae_el_nombre_entra_si_no_parece_un_titulo(): void
    {
        $datos = $this->con_datos('J');
        $filas = array_merge([self::CABECERA], array_slice($datos, 0, 4), [['Juan Pérez', null, null, null]], array_slice($datos, 4));
        [$creados, $afuera] = $this->importar($filas);
        $this->assertSame([], $afuera);
        $this->assertContains('Juan Pérez', $creados);
        $this->assertCount(9, $creados);
    }

    public function test_en_una_lista_donde_muchos_solo_traen_el_nombre_no_se_descarta_ninguno(): void
    {
        $filas = [self::CABECERA];
        foreach (['Ana Gómez', 'Bruno Díaz', 'Carla Ruiz', 'Dario Paz', 'Elsa Rey', 'Fabián Sosa'] as $nombre) {
            $filas[] = [$nombre, null, null, null];
        }
        $filas[] = ['ZONA SUR', null, null, null];
        $filas[] = ['Gustavo Leiva', '20-11111111-2', '3442 401111', 5000];
        $filas[] = ['Hilda Mena', null, '3442 402222', 1000];
        [$creados, $afuera] = $this->importar($filas);
        $this->assertSame([], $afuera);
        $this->assertCount(9, $creados);
        $this->assertContains('ZONA SUR', $creados);
    }

    public function test_en_una_lista_que_escribe_los_nombres_en_mayusculas_las_mayusculas_no_alcanzan(): void
    {
        $filas = [self::CABECERA];
        for ($i = 1; $i <= 8; $i++) {
            $filas[] = ['FERRETERIA NUMERO ' . $i, null, '3442 40000' . $i, 100 * $i];
        }
        $filas[] = ['MAYORISTAS', null, null, null];
        $filas[] = ['Mayoristas:', null, null, null];
        [$creados, $afuera] = $this->importar($filas);
        // «MAYORISTAS» entra (sin otra señal); «Mayoristas:» termina en dos puntos y queda afuera.
        $this->assertSame(['Mayoristas:'], $afuera);
        $this->assertContains('MAYORISTAS', $creados);
    }

    public function test_la_cabecera_repetida_en_el_medio_se_deja_afuera(): void
    {
        $datos = $this->con_datos('C');
        $filas = array_merge([self::CABECERA], array_slice($datos, 0, 4), [self::CABECERA], array_slice($datos, 4));
        [$creados, $afuera] = $this->importar($filas);
        $this->assertSame(['Cliente'], $afuera);
        $this->assertCount(8, $creados);
        $this->assertNotContains('Cliente', $creados);
    }

    public function test_las_notas_al_pie_despues_del_total_se_dejan_afuera(): void
    {
        $filas = array_merge([self::CABECERA], $this->con_datos('N'), [
            ['TOTAL', null, null, 36000], ['Actualizado el 14/11 por Marta', null, null, null], ['Pedir a los morosos que pasen', null, null, null],
        ]);
        [$creados, $afuera] = $this->importar($filas);
        $this->assertSame(['TOTAL', 'Actualizado el 14/11 por Marta', 'Pedir a los morosos que pasen'], $afuera);
        $this->assertCount(8, $creados);
    }

    public function test_el_pie_que_cuenta_en_su_rotulo_se_deja_afuera(): void
    {
        foreach (['Total: 8 clientes', 'Total clientes: 8', 'TOTAL 8', 'Cantidad de clientes: 8', 'Son 8 clientes'] as $pie) {
            $filas = array_merge([self::CABECERA], $this->con_datos('P' . substr(md5($pie), 0, 6)), [[$pie, null, null, 36000]]);
            [$creados, $afuera] = $this->importar($filas);
            $this->assertSame([$pie], $afuera, $pie);
            $this->assertCount(8, $creados, $pie);
        }
        // Una razón social con número no es un conteo.
        [$creados, $afuera] = $this->importar(array_merge([self::CABECERA], $this->con_datos('R'), [['Total 2000 SA', null, null, 500]]));
        $this->assertSame([], $afuera);
        $this->assertContains('Total 2000 SA', $creados);
    }

    public function test_titulos_y_nota_en_una_lista_chica_con_clientes_de_solo_nombre_y_saldo(): void
    {
        // E8.3: 8 clientes (6 con teléfono o CUIT y 2 con solo nombre y saldo), dos títulos con señal fuerte y una nota
        // al pie. En E8.2 los títulos contaban en la proporción y entraban como clientes.
        $filas = [self::CABECERA, ['Zona centro', null, null, null]];
        for ($i = 1; $i <= 3; $i++) {
            $filas[] = ['Ferretería número ' . $i, '20-1111111' . $i . '-2', '3442 40000' . $i, 1000 * $i];
        }
        $filas[] = ['Sandra Ojeda', null, null, 1500];
        $filas[] = ['Zona norte:', null, null, null];
        for ($i = 4; $i <= 6; $i++) {
            $filas[] = ['Ferretería número ' . $i, null, '3442 40000' . $i, 1000 * $i];
        }
        $filas[] = ['Hugo Benítez', null, null, -300];
        $filas[] = ['TOTAL', null, null, 22200];
        $filas[] = ['Actualizado el 14/11', null, null, null];
        [$creados, $afuera] = $this->importar($filas);

        $this->assertSame(['Zona centro', 'Zona norte:', 'TOTAL', 'Actualizado el 14/11'], $afuera);
        $this->assertCount(8, $creados);
        // El saldo es un dato: con solo nombre y saldo es un cliente.
        $this->assertContains('Sandra Ojeda', $creados);
        $this->assertContains('Hugo Benítez', $creados);
    }

    public function test_la_lista_de_proveedores_tambien(): void
    {
        $datos = $this->con_datos('V');
        $filas = array_merge([self::CABECERA], array_slice($datos, 0, 4), [['Zona norte:', null, null, null]], array_slice($datos, 4), [['Son 8 proveedores', null, null, null]]);
        [$creados, $afuera] = $this->importar($filas, true, ProviderImport::class);
        $this->assertSame(['Zona norte:', 'Son 8 proveedores'], $afuera);
        $this->assertCount(8, $creados);
    }

    public function test_sin_el_interruptor_entra_todo_como_hoy(): void
    {
        $datos = $this->con_datos('S');
        $filas = array_merge([self::CABECERA], array_slice($datos, 0, 4), [['LOS QUE ESTÁN AL DÍA', null, null, null], self::CABECERA],
            array_slice($datos, 4), [['TOTAL', null, null, 36000], ['Actualizado el 14/11', null, null, null]]);
        [$creados, $afuera] = $this->importar($filas, false);
        $this->assertSame([], $afuera);
        // Los 8, el título, la cabecera repetida («Cliente»), el total y la nota: lo de siempre.
        $this->assertCount(12, $creados);
        foreach (['LOS QUE ESTÁN AL DÍA', 'Cliente', 'TOTAL', 'Actualizado el 14/11'] as $nombre) {
            $this->assertContains($nombre, $creados);
        }
    }
}
