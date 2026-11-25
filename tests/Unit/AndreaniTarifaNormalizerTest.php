<?php

namespace Tests\Unit;

use App\Services\Andreani\AndreaniTarifaNormalizer;
use Tests\TestCase;

/**
 * Misión envios-andreani (25/11/2026), CA6 — TESTS DE ACEPTACIÓN del cálculo puro de Andreani
 * (`App\Services\Andreani\AndreaniTarifaNormalizer`, archivo ESPEJO entre empresa-api y tienda-api).
 *
 * Qué fija este archivo (PLAN §2.2 y CA6):
 *   1. Los límites de paquetería los aplicamos nosotros, porque el cotizador público de Andreani cotiza
 *      cualquier tamaño (medido el 25/11: 5 m3 y 80 kg dan 200): hasta 50 kg reales, ningún lado > 165 cm y suma
 *      de lados <= 300 cm. 100x100x100 (suma 300) NO excede; 101x100x100 (suma 301) sí.
 *   2. Un ítem de Zipnova (gramos y cm) se convierte en un bulto de Andreani (kilos, cm, volumen en cm3) y el
 *      valor declarado se prorratea por unidad (redondeo a 2 decimales, el resto al último).
 *   3. Tope de 30 bultos por consulta (medido: 30 da 200): con 31 no hay opción Andreani, motivo
 *      `demasiados_bultos`.
 *   4. Las sucursales de retiro son sólo las que entregan envíos Y atienden al cliente, máximo 5, con la forma
 *      de `puntos_de_retiro` de las opciones de Zipnova.
 *
 * 🔴 Hoy estos tests están en ROJO a propósito: la clase `AndreaniTarifaNormalizer` no existe todavía.
 *
 * Supuestos de firma que este test FIJA (el PLAN §2.2 los nombra sin detallar el retorno):
 *   - todos los métodos son estáticos, como en `ZipnovaQuoteNormalizer`;
 *   - `bultos_desde_items(array $items_zipnova, $valor_declarado_total)` devuelve
 *     `['bultos' => array, 'motivo' => null|'demasiados_bultos']`;
 *   - `excede_limites(array $bulto)` recibe un bulto con las claves `kilos`, `alto_cm`, `ancho_cm`, `largo_cm`;
 *   - `puntos_de_retiro(array $sucursales)` recibe la lista cruda de `GET /v2/sucursales`.
 *
 * Sin red, sin base: es cálculo puro.
 */
class AndreaniTarifaNormalizerTest extends TestCase
{
    /** Ruta (desde la raíz del proyecto) de la respuesta real de sucursales de Andreani para el CP 5000. */
    const FIXTURE_SUCURSALES = 'tests/Feature/Integraciones/fixtures/andreani_sucursales.json';

    /**
     * Un bulto en la forma que produce `bultos_desde_items`.
     *
     * @param float $kilos Peso en kilos.
     * @param int   $alto  Alto en cm.
     * @param int   $ancho Ancho en cm.
     * @param int   $largo Largo en cm.
     * @return array<string, mixed>
     */
    protected function bulto($kilos, $alto, $ancho, $largo)
    {
        return [
            'kilos'           => $kilos,
            'alto_cm'         => $alto,
            'ancho_cm'        => $ancho,
            'largo_cm'        => $largo,
            'volumen_cm3'     => $alto * $ancho * $largo,
            'valor_declarado' => 0,
        ];
    }

    /**
     * Un ítem en la forma que arma `ZipnovaPaquetesHelper::items_desde_lineas()` (gramos y cm enteros).
     *
     * @param int $gramos Peso en gramos.
     * @param int $alto   Alto en cm.
     * @param int $ancho  Ancho en cm.
     * @param int $largo  Largo en cm.
     * @return array<string, mixed>
     */
    protected function item($gramos, $alto, $ancho, $largo)
    {
        return ['weight' => $gramos, 'height' => $alto, 'width' => $ancho, 'length' => $largo, 'description' => 'Artículo'];
    }

    /**
     * CA6 — Los tres límites, justo en el borde y justo pasado.
     *
     * @return void
     */
    public function test_excede_limites_en_el_borde_de_peso_lado_y_suma()
    {
        // Un lado de 170 cm pasa el tope de 165.
        $this->assertTrue(AndreaniTarifaNormalizer::excede_limites($this->bulto(5, 170, 10, 10)), '170x10x10 tiene que exceder (lado > 165).');

        // 165 cm de un lado, con la suma muy abajo: entra.
        $this->assertFalse(AndreaniTarifaNormalizer::excede_limites($this->bulto(5, 165, 10, 10)), '165x10x10 no excede.');
        $this->assertTrue(AndreaniTarifaNormalizer::excede_limites($this->bulto(5, 166, 10, 10)), '166 cm de un lado excede.');

        // El paquete de 1 m3 del pedido de Lucas: suma exactamente 300, entra.
        $this->assertFalse(AndreaniTarifaNormalizer::excede_limites($this->bulto(10, 100, 100, 100)), '100x100x100 (suma 300) NO excede.');
        $this->assertTrue(AndreaniTarifaNormalizer::excede_limites($this->bulto(10, 101, 100, 100)), '101x100x100 (suma 301) excede.');

        // 50 kg reales entran; 51 no.
        $this->assertFalse(AndreaniTarifaNormalizer::excede_limites($this->bulto(50, 20, 20, 20)), '50 kg no excede.');
        $this->assertTrue(AndreaniTarifaNormalizer::excede_limites($this->bulto(51, 20, 20, 20)), '51 kg excede.');
    }

    /**
     * CA6 — Un ítem de Zipnova pasa a bulto de Andreani: gramos a kilos, cm tal cual, volumen en cm3.
     *
     * @return void
     */
    public function test_un_item_se_convierte_en_un_bulto_con_kilos_y_volumen()
    {
        $resultado = AndreaniTarifaNormalizer::bultos_desde_items([$this->item(1500, 20, 15, 30)], 1000);

        $this->assertNull($resultado['motivo']);
        $this->assertCount(1, $resultado['bultos']);

        $bulto = $resultado['bultos'][0];

        $this->assertEquals(1.5, $bulto['kilos']);
        $this->assertEquals(20, $bulto['alto_cm']);
        $this->assertEquals(15, $bulto['ancho_cm']);
        $this->assertEquals(30, $bulto['largo_cm']);
        $this->assertEquals(9000, $bulto['volumen_cm3'], 'El volumen es alto x ancho x largo en cm3.');
        $this->assertEquals(1000, $bulto['valor_declarado'], 'Con un solo bulto lleva todo el valor declarado.');
    }

    /**
     * CA6 — El paquete de 1 m3 y 10 kg del pedido de Lucas llega a Andreani con volumen 1.000.000 y 10 kilos.
     *
     * @return void
     */
    public function test_el_paquete_de_un_metro_cubico_da_volumen_un_millon_y_diez_kilos()
    {
        $resultado = AndreaniTarifaNormalizer::bultos_desde_items([$this->item(10000, 100, 100, 100)], 0);

        $this->assertNull($resultado['motivo']);
        $this->assertEquals(1000000, $resultado['bultos'][0]['volumen_cm3']);
        $this->assertEquals(10, $resultado['bultos'][0]['kilos']);
        $this->assertFalse(AndreaniTarifaNormalizer::excede_limites($resultado['bultos'][0]));
    }

    /**
     * CA6 — El valor declarado se reparte por unidad con 2 decimales y el resto cae en el último bulto: la
     * suma da exactamente el total.
     *
     * @return void
     */
    public function test_el_valor_declarado_se_prorratea_y_el_resto_va_al_ultimo()
    {
        $items = [$this->item(500, 10, 10, 10), $this->item(500, 10, 10, 10), $this->item(500, 10, 10, 10)];

        $resultado = AndreaniTarifaNormalizer::bultos_desde_items($items, 100);

        $this->assertCount(3, $resultado['bultos']);
        $this->assertEquals(33.33, $resultado['bultos'][0]['valor_declarado']);
        $this->assertEquals(33.33, $resultado['bultos'][1]['valor_declarado']);
        $this->assertEquals(33.34, $resultado['bultos'][2]['valor_declarado'], 'El resto del redondeo va al último bulto.');

        $suma = 0;
        foreach ($resultado['bultos'] as $bulto) {
            $suma += $bulto['valor_declarado'];
        }
        $this->assertEquals(100, round($suma, 2));
    }

    /**
     * CA6 — Tope de 30 bultos: con 30 se arma la consulta; con 31 no hay opción Andreani.
     *
     * @return void
     */
    public function test_mas_de_treinta_bultos_da_demasiados_bultos()
    {
        $treinta = array_fill(0, 30, $this->item(500, 10, 10, 10));

        $resultado = AndreaniTarifaNormalizer::bultos_desde_items($treinta, 3000);
        $this->assertNull($resultado['motivo'], '30 bultos es el máximo medido y tiene que entrar.');
        $this->assertCount(30, $resultado['bultos']);

        $treinta_y_uno = array_fill(0, 31, $this->item(500, 10, 10, 10));

        $resultado = AndreaniTarifaNormalizer::bultos_desde_items($treinta_y_uno, 3100);
        $this->assertSame('demasiados_bultos', $resultado['motivo']);
    }

    /**
     * CA7 — Sólo sirven para retiro las sucursales que entregan envíos Y atienden al cliente. De las 4 que
     * devuelve Andreani para el CP 5000 (respuesta real) queda una: la 40476 «Córdoba».
     *
     * @return void
     */
    public function test_puntos_de_retiro_filtra_las_sucursales_que_entregan_y_atienden()
    {
        $sucursales = json_decode(file_get_contents(base_path(self::FIXTURE_SUCURSALES)), true);

        $this->assertCount(4, $sucursales, 'La fixture tiene que ser la respuesta real de 4 sucursales.');

        $puntos = AndreaniTarifaNormalizer::puntos_de_retiro($sucursales);

        $this->assertCount(1, $puntos);
        $this->assertSame(40476, $puntos[0]['point_id']);
        $this->assertSame('Córdoba', $puntos[0]['description']);
        $this->assertSame('5000', (string) $puntos[0]['zipcode']);
        $this->assertSame('Córdoba', $puntos[0]['city']);
        $this->assertSame('Córdoba', $puntos[0]['state']);
        $this->assertSame('Au 9 Km 695', $puntos[0]['street']);
        $this->assertSame('L/V 8:00 a 17:00 hs.', $puntos[0]['open_hours']);

        // La misma forma que los puntos de retiro de Zipnova (la tienda los pinta igual).
        foreach (['point_id', 'description', 'open_hours', 'phone', 'street', 'street_number', 'city', 'state', 'zipcode'] as $clave) {
            $this->assertArrayHasKey($clave, $puntos[0], 'Falta la clave "' . $clave . '" en el punto de retiro.');
        }
    }

    /**
     * CA7 — Nunca más de 5 puntos de retiro, aunque haya más sucursales válidas.
     *
     * @return void
     */
    public function test_puntos_de_retiro_devuelve_como_mucho_cinco()
    {
        $base = json_decode(file_get_contents(base_path(self::FIXTURE_SUCURSALES)), true);
        $valida = $base[3];

        $siete = [];
        for ($i = 0; $i < 7; $i++) {
            $copia = $valida;
            $copia['id'] = 50000 + $i;
            $siete[] = $copia;
        }

        $this->assertCount(5, AndreaniTarifaNormalizer::puntos_de_retiro($siete));
        $this->assertSame([], AndreaniTarifaNormalizer::puntos_de_retiro([]), 'Sin sucursales no hay puntos.');
    }
}
