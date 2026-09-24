<?php

namespace Tests\Feature\Precios;

use App\Http\Controllers\Helpers\CriterioDeOfertaPorCantidadHelper as Criterio;
use Tests\TestCase;

/**
 * El criterio de las OFERTAS POR CANTIDAD: precio fijo o porcentaje, nunca los dos
 * (mision oferta-por-cantidad-porcentaje, 24/9/2026).
 *
 * ── Por que esto merece una clase entera ─────────────────────────────────────────────────────
 *
 * Es una REGLA DE EXCLUSION MUTUA escrita en CUATRO lugares: este helper (que persiste), el
 * espejo de `empresa-spa/src/utils/criterio_de_oferta_por_cantidad.js` (el ABM y el ERP al
 * vender), `tienda-api/.../ArticlePriceRangeHelper` (el que COBRA en la tienda) y
 * `tienda-spa/src/mixins/generals.js` (el que MUESTRA). Si difieren en un solo borde, el
 * comprador ve un numero y le cobran otro.
 *
 * Y no es una precaucion teorica: la hermana de esta regla —margen de ganancia vs precio manual—
 * dejo articulos SIN NINGUNA FORMA de cambiarles el precio desde la interfaz, porque un
 * `percentage_gain = 0` caia en el hueco entre tres criterios que no coincidian. Toda esa
 * historia esta en `APRENDER_NO_PARCHEAR.md` y en el docblock de `CriterioDePrecioHelper`.
 *
 * O sea que cada caso de esta clase es un borde de un contrato de cuatro puntas, y ponerse rojo
 * es exactamente lo que tiene que pasar si alguien lo mueve de un lado solo.
 *
 * ⚠️ Sin base: el helper es puro. Lo que toca la base se ejercita en la tienda, de punta a punta,
 * en `tienda-api/tests/Feature/CombosYRangos/`.
 *
 * @group ofertas-por-cantidad
 */
class CriterioDeOfertaPorCantidadTest extends TestCase
{
    /** El precio que la linea iba a tener si ninguna oferta aplicara. */
    const PRECIO_BASE = 1000.00;

    /*
    |---------------------------------------------------------------------------------------------
    | Criterio 1 — el precio fijo gana
    |---------------------------------------------------------------------------------------------
    */

    /** Un precio fijo cargado es una oferta de precio fijo, y el precio es ese numero. */
    public function test_precio_fijo_manda()
    {
        $this->assertSame(Criterio::MODO_PRECIO_FIJO, Criterio::resolver(800, null));
        $this->assertSame(800.0, Criterio::precio(800, null, self::PRECIO_BASE));
    }

    /**
     * 🔴 Con los DOS cargados gana el precio fijo. Es el borde que decide la regla entera.
     *
     * No es una preferencia estetica: el precio fijo es el valor explicito y absoluto, el numero
     * que el comercio escribio pensando en un numero, y es lo unico que existia antes de esta
     * mision. Cualquier fila vieja de cualquiera de los ~40 clientes tiene que seguir
     * comportandose exactamente igual que antes, y este caso es el que lo fija.
     */
    public function test_con_los_dos_cargados_gana_el_precio_fijo()
    {
        $this->assertSame(Criterio::MODO_PRECIO_FIJO, Criterio::resolver(800, 15));
        $this->assertSame(800.0, Criterio::precio(800, 15, self::PRECIO_BASE));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Criterio 2 — el porcentaje, y sus dos bordes
    |---------------------------------------------------------------------------------------------
    */

    /** Un porcentaje usable descuenta sobre el precio que la linea iba a tener. */
    public function test_porcentaje_descuenta_sobre_el_precio_base()
    {
        $this->assertSame(Criterio::MODO_PORCENTAJE, Criterio::resolver(null, 15));
        $this->assertSame(850.0, Criterio::precio(null, 15, self::PRECIO_BASE));
    }

    /**
     * 🔴 Y sigue al precio: el MISMO tramo da otro numero cuando cambia el precio del articulo.
     *
     * Es el pedido textual de Lucas ("si coloca el porcentaje, el usuario puede cambiar el precio
     * del producto y el precio de la promocion tambien va a cambiar porque es un porcentaje"), y
     * es lo unico que distingue al porcentaje del precio fijo. Un test que solo mida un precio
     * base no lo probaria.
     */
    public function test_el_porcentaje_sigue_al_precio_del_articulo()
    {
        $this->assertSame(850.0, Criterio::precio(null, 15, 1000));
        $this->assertSame(1700.0, Criterio::precio(null, 15, 2000));
    }

    /** `price = 0` no es un precio fijo: gana el porcentaje. Es el borde del bug hermano. */
    public function test_precio_en_cero_deja_ganar_al_porcentaje()
    {
        $this->assertSame(Criterio::MODO_PORCENTAJE, Criterio::resolver(0, 15));
        $this->assertSame(850.0, Criterio::precio(0, 15, self::PRECIO_BASE));
    }

    /**
     * El 100 queda AFUERA: dejaria el precio en cero. Un articulo regalado no es un descuento por
     * cantidad, es un dato mal cargado, y el lado seguro es no aplicar nada.
     */
    public function test_cien_por_ciento_no_aplica()
    {
        $this->assertSame(Criterio::MODO_NINGUNO, Criterio::resolver(null, 100));
        $this->assertNull(Criterio::precio(null, 100, self::PRECIO_BASE));
    }

    /** Mas de 100 daria un precio NEGATIVO: cobrar plata al reves. Tampoco aplica. */
    public function test_mas_de_cien_por_ciento_no_aplica()
    {
        $this->assertSame(Criterio::MODO_NINGUNO, Criterio::resolver(null, 150));
        $this->assertNull(Criterio::precio(null, 150, self::PRECIO_BASE));
    }

    /** Un porcentaje negativo seria un RECARGO disfrazado de oferta. No aplica. */
    public function test_porcentaje_negativo_no_aplica()
    {
        $this->assertSame(Criterio::MODO_NINGUNO, Criterio::resolver(null, -15));
        $this->assertNull(Criterio::precio(null, -15, self::PRECIO_BASE));
    }

    /** Justo debajo del 100 SI aplica: el limite es estricto y esta de un lado solo. */
    public function test_noventa_y_nueve_con_noventa_y_nueve_si_aplica()
    {
        $this->assertSame(Criterio::MODO_PORCENTAJE, Criterio::resolver(null, 99.99));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Criterio 3 — sin valores usables no pasa nada
    |---------------------------------------------------------------------------------------------
    */

    /**
     * Una oferta vacia no aplica. Nunca un default permisivo: un valor que nadie escribio todavia
     * no puede empezar a descontar plata solo.
     *
     * Las cinco formas del "vacio" van juntas a proposito — el bug hermano nacio justamente de que
     * '0.00' era falsy en un lado y truthy en el otro.
     */
    public function test_las_formas_del_vacio_no_aplican()
    {
        $vacios = array(null, '', '0', '0.00', 0);

        foreach ($vacios as $vacio) {
            $this->assertSame(
                Criterio::MODO_NINGUNO,
                Criterio::resolver($vacio, $vacio),
                'Deberia ser NINGUNO con ['.var_export($vacio, true).']'
            );
        }
    }

    /** Texto no numerico tampoco. */
    public function test_texto_no_numerico_no_aplica()
    {
        $this->assertSame(Criterio::MODO_NINGUNO, Criterio::resolver('quince', 'quince'));
    }

    /**
     * 🔴 Sin precio base, un porcentaje NO devuelve cero: devuelve null.
     *
     * Devolver cero seria regalar el articulo. Null lo manda al precio normal, que es el lado
     * seguro — el mismo criterio con el que el resto del sistema descarta una oferta que no se
     * puede resolver.
     */
    public function test_porcentaje_sin_precio_base_no_regala_el_articulo()
    {
        $this->assertNull(Criterio::precio(null, 15, null));
        $this->assertNull(Criterio::precio(null, 15, 'lo que sea'));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | normalizar_par() — lo que impide que el estado sucio NAZCA
    |---------------------------------------------------------------------------------------------
    */

    /** Con los dos cargados se persiste solo el que gana; el otro queda en null. */
    public function test_normalizar_limpia_el_que_pierde()
    {
        $this->assertSame(
            array('price' => 800, 'porcentaje' => null),
            Criterio::normalizar_par(800, 15)
        );

        $this->assertSame(
            array('price' => null, 'porcentaje' => 15),
            Criterio::normalizar_par(null, 15)
        );
    }

    /** El cero se normaliza a null: es la forma del vacio que originó el bug hermano. */
    public function test_normalizar_convierte_el_cero_en_null()
    {
        $this->assertSame(
            array('price' => null, 'porcentaje' => 15),
            Criterio::normalizar_par('0.00', 15)
        );
    }

    /**
     * Un valor no usable pero distinto de cero se guarda TAL CUAL si es el unico cargado.
     *
     * Descartarlo en silencio seria borrarle un dato al usuario sin avisarle — y ademas dejaria el
     * ABM mostrando un campo vacio donde el tipeo algo. No aplica igual, porque `resolver()` lo
     * manda a MODO_NINGUNO: esa es la separacion entre "que se guarda" y "que descuenta".
     */
    public function test_normalizar_no_descarta_en_silencio_un_porcentaje_invalido()
    {
        $normalizado = Criterio::normalizar_par(null, 150);

        $this->assertSame(150, $normalizado['porcentaje']);
        $this->assertSame(Criterio::MODO_NINGUNO, Criterio::resolver(null, 150));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | porcentaje_legible() — el numero que se anuncia
    |---------------------------------------------------------------------------------------------
    */

    /**
     * La columna es decimal(8,2): sin esto todo cartel diria "15.00% de descuento".
     *
     * El 100 esta en la lista por el rtrim: con un solo `rtrim($texto, '0.')` quedaria en "1".
     */
    public function test_porcentaje_legible()
    {
        $this->assertSame('15', Criterio::porcentaje_legible(15.00));
        $this->assertSame('12,5', Criterio::porcentaje_legible(12.50));
        $this->assertSame('7,25', Criterio::porcentaje_legible(7.25));
        $this->assertSame('100', Criterio::porcentaje_legible(100.00));
        $this->assertSame('', Criterio::porcentaje_legible(null));
    }
}
