<?php

namespace Tests\Feature\Buyer;

use App\Models\Client;
use Carbon\Carbon;
use Tests\EmpresaTestCase;

/**
 * Misión vincular-comprador-desde-pedidos (24/9/2026): `GET api/buyer/{id}/clientes-para-vincular`.
 *
 * El modal de la tabla de Pedidos le pide a este endpoint los clientes del sistema que podrían ser
 * el comprador de la tienda. Lo que estos tests cuidan:
 *
 *  - LAS PISTAS: el nombre en otro orden y con tildes, el mismo email, el mismo teléfono en otro
 *    formato, una palabra en común. Son pistas para que la persona elija, nunca vinculan solas.
 *  - EL ORDEN: por puntaje, y a igual puntaje por nombre (sin que una tilde lo desordene).
 *  - LOS TOPES: `limit` (1 a 50, por defecto 30) y las dos capas de candidatos (una palabra muy
 *    común, "Juan", no puede dejar afuera al "Juan Perez" ni al cliente con el mismo email).
 *  - EL AISLAMIENTO: nunca clientes de otro comercio ni borrados, y un comprador ajeno es un 404.
 *  - EL CONTRATO con la SPA: las claves exactas y sus tipos.
 *
 * 🔴 Cada test crea su dueño (ver `ComercioDePrueba`): las aserciones son por conjunto exacto y el
 * fixture trae clientes propios.
 *
 * PHP 7.4 (sin sintaxis ni funciones de PHP 8).
 */
class ClientesParaVincularTest extends EmpresaTestCase
{
    use ComercioDePrueba;

    /** @var \App\Models\User Dueño de la empresa, el que opera en los tests. */
    protected $dueno;

    /** @var \App\Models\User Otro comercio: nada suyo puede aparecer. */
    protected $otro_dueno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = $this->crear_dueno('coincidencias');
        $this->otro_dueno = $this->crear_dueno('otro comercio');

        $this->actuar_como($this->dueno);
    }

    // -------------------------------------------------------------------------------------------
    //  Ayudas
    // -------------------------------------------------------------------------------------------

    /**
     * Pega al endpoint.
     *
     * @param  \App\Models\Buyer|int|string  $comprador  El comprador o directamente el id de la URL.
     * @param  array  $query
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedir($comprador, $query = [])
    {
        $id = is_object($comprador) ? $comprador->id : $comprador;

        $url = 'api/buyer/'.$id.'/clientes-para-vincular';

        if (count($query) > 0) {
            $url .= '?'.http_build_query($query);
        }

        return $this->getJson($url);
    }

    /**
     * Pega al endpoint, exige 200 y devuelve el JSON.
     *
     * @param  \App\Models\Buyer  $comprador
     * @param  array  $query
     * @return array
     */
    protected function coincidencias($comprador, $query = [])
    {
        $respuesta = $this->pedir($comprador, $query);

        $respuesta->assertStatus(200);

        return $respuesta->json();
    }

    /**
     * Los ids de `models`, en el orden en que salieron.
     *
     * @param  array  $json
     * @return array<int,int>
     */
    protected function ids_de($json)
    {
        return array_column($json['models'], 'id');
    }

    /**
     * Los nombres de `models`, en el orden en que salieron.
     *
     * @param  array  $json
     * @return array<int,string>
     */
    protected function nombres_de($json)
    {
        return array_column($json['models'], 'name');
    }

    /**
     * `models` indexado por id de cliente.
     *
     * @param  array  $json
     * @return array
     */
    protected function por_id($json)
    {
        $indexados = [];

        foreach ($json['models'] as $fila) {
            $indexados[$fila['id']] = $fila;
        }

        return $indexados;
    }

    /**
     * Inserta `$cantidad` clientes del dueño con nombres `"<prefijo> 001"`, `"<prefijo> 002"`...
     * en una sola consulta (con el número relleno, para que el orden por nombre sea el de creación).
     *
     * @param  \App\Models\User  $dueno
     * @param  string  $prefijo
     * @param  int  $cantidad
     * @return void
     */
    protected function sembrar_clientes($dueno, $prefijo, $cantidad)
    {
        $ahora = Carbon::now();
        $filas = [];

        for ($numero = 1; $numero <= $cantidad; $numero++) {

            $filas[] = [
                'name'       => $prefijo.' '.str_pad((string) $numero, 3, '0', STR_PAD_LEFT),
                'user_id'    => $dueno->id,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
        }

        Client::insert($filas);
    }

    // -------------------------------------------------------------------------------------------
    //  Las pistas
    // -------------------------------------------------------------------------------------------

    /**
     * El nombre en otro orden, con tildes y con mayúsculas distintas es `nombre_igual`.
     *
     * @test
     * @return void
     */
    public function el_nombre_en_otro_orden_con_tildes_y_mayusculas_es_nombre_igual()
    {
        $comprador = $this->crear_comprador_de($this->dueno, ['name' => 'gonzalez LUCAS']);

        $igual = $this->crear_cliente_de($this->dueno, ['name' => 'Lucas González']);
        $parecido = $this->crear_cliente_de($this->dueno, ['name' => 'Lucas Perez']);
        $this->crear_cliente_de($this->dueno, ['name' => 'Marta Sosa']);

        $json = $this->coincidencias($comprador);

        $this->assertSame('sugerencias', $json['modo']);
        $this->assertSame('gonzalez LUCAS', $json['texto']);
        $this->assertSame([$igual->id, $parecido->id], $this->ids_de($json), 'Tienen que salir "Lucas González" y "Lucas Perez", en ese orden, y no "Marta Sosa".');

        $filas = $this->por_id($json);

        $this->assertSame(['nombre_igual'], $filas[$igual->id]['motivos']);
        $this->assertSame(90, $filas[$igual->id]['score']);

        $this->assertSame(['nombre_parecido'], $filas[$parecido->id]['motivos']);
        $this->assertSame(10, $filas[$parecido->id]['score']);
    }

    /**
     * El mismo email con el nombre completamente distinto es `email_igual`, sin importar
     * mayúsculas ni espacios de borde.
     *
     * @test
     * @return void
     */
    public function el_email_identico_con_nombre_distinto_es_email_igual()
    {
        $comprador = $this->crear_comprador_de($this->dueno, [
            'name'  => 'Juan Perez',
            'email' => '  Ventas@Ferreteria.COM ',
        ]);

        $mismo_email = $this->crear_cliente_de($this->dueno, ['name' => 'Ferreteria El Tornillo', 'email' => 'VENTAS@ferreteria.com']);
        $this->crear_cliente_de($this->dueno, ['name' => 'Otro Nombre', 'email' => 'otro@x.com']);

        $json = $this->coincidencias($comprador);

        $this->assertSame([$mismo_email->id], $this->ids_de($json));
        $this->assertSame(['email_igual'], $json['models'][0]['motivos']);
        $this->assertSame(100, $json['models'][0]['score']);
    }

    /**
     * El teléfono en formatos distintos, con los mismos últimos 8 dígitos, es `telefono_igual`.
     * Y uno más corto o distinto en el último dígito no cuenta.
     *
     * @test
     * @return void
     */
    public function el_telefono_en_otro_formato_con_los_mismos_ultimos_8_digitos_es_telefono_igual()
    {
        $comprador = $this->crear_comprador_de($this->dueno, [
            'name'  => 'Ramiro Solis',
            'phone' => '+54 9 11 2233-4455',
        ]);

        $mismo_telefono = $this->crear_cliente_de($this->dueno, ['name' => 'Distinto Nombre', 'phone' => '(011) 2233 4455']);
        $this->crear_cliente_de($this->dueno, ['name' => 'Otro Distinto', 'phone' => '11 2233-4456']);
        $this->crear_cliente_de($this->dueno, ['name' => 'Chico Corto', 'phone' => '4455']);
        $this->crear_cliente_de($this->dueno, ['name' => 'Sin Telefono', 'phone' => null]);

        $json = $this->coincidencias($comprador);

        $this->assertSame([$mismo_telefono->id], $this->ids_de($json));
        $this->assertSame(['telefono_igual'], $json['models'][0]['motivos']);
        $this->assertSame(70, $json['models'][0]['score']);
    }

    /**
     * Un teléfono de menos de 8 dígitos no compara nada: ni encuentra clientes por él ni suma el
     * motivo. (Con menos dígitos "los últimos 8" serían el número entero: cualquier interno
     * coincidiría con cualquier otro.)
     *
     * @test
     * @return void
     */
    public function un_telefono_de_menos_de_8_digitos_no_sirve_de_pista()
    {
        $comprador = $this->crear_comprador_de($this->dueno, ['name' => 'Ramiro Solis', 'phone' => '1234567']);

        $this->crear_cliente_de($this->dueno, ['name' => 'Otro Nombre', 'phone' => '1234567']);

        $this->assertSame([], $this->coincidencias($comprador)['models']);
    }

    /**
     * Una palabra en común es `nombre_parecido`: 10 puntos por palabra compartida. Los de igual
     * puntaje van por nombre.
     *
     * @test
     * @return void
     */
    public function una_palabra_suelta_en_comun_es_nombre_parecido()
    {
        $comprador = $this->crear_comprador_de($this->dueno, ['name' => 'Lucas Gonzalez']);

        $dos_palabras = $this->crear_cliente_de($this->dueno, ['name' => 'Lucas Gonzalez Ferreteria']);
        $gonzalez = $this->crear_cliente_de($this->dueno, ['name' => 'Gonzalez Pedro']);
        $lucas = $this->crear_cliente_de($this->dueno, ['name' => 'Lucas Perez']);
        $this->crear_cliente_de($this->dueno, ['name' => 'Marcos Lopez']);

        $json = $this->coincidencias($comprador);

        $this->assertSame([$dos_palabras->id, $gonzalez->id, $lucas->id], $this->ids_de($json));
        $this->assertSame([20, 10, 10], array_column($json['models'], 'score'));

        foreach ($json['models'] as $fila) {
            $this->assertSame(['nombre_parecido'], $fila['motivos']);
        }
    }

    /**
     * `nombre_parecido` suma 10 por palabra compartida con tope de 40: cinco o seis palabras en
     * común valen lo mismo que cuatro. Sin tope, un nombre largo le ganaría al email idéntico.
     *
     * @test
     * @return void
     */
    public function el_nombre_parecido_tiene_tope_de_40_puntos()
    {
        $comprador = $this->crear_comprador_de($this->dueno, ['name' => 'Ana Maria Los Angeles Perez Lopez']);

        $cuatro = $this->crear_cliente_de($this->dueno, ['name' => 'Ana Maria Los Angeles']);
        $cinco = $this->crear_cliente_de($this->dueno, ['name' => 'Ana Maria Los Angeles Perez Ruiz']);
        $seis = $this->crear_cliente_de($this->dueno, ['name' => 'Ana Maria Los Angeles Perez Lopez Extra']);
        $tres = $this->crear_cliente_de($this->dueno, ['name' => 'Ana Maria Los']);

        $json = $this->coincidencias($comprador);

        $filas = $this->por_id($json);

        $this->assertSame(40, $filas[$cuatro->id]['score']);
        $this->assertSame(40, $filas[$cinco->id]['score'], 'Cinco palabras compartidas valen 40, no 50.');
        $this->assertSame(40, $filas[$seis->id]['score'], 'Seis palabras compartidas valen 40, no 60.');
        $this->assertSame(30, $filas[$tres->id]['score']);

        // Los tres de 40 por nombre (el más corto primero); el de 30 al final.
        $this->assertSame([$cuatro->id, $seis->id, $cinco->id, $tres->id], $this->ids_de($json));
    }

    /**
     * Un cliente que coincide por los tres motivos sale UNA sola vez, con los motivos en orden de
     * fuerza y el puntaje sumado (100 + 90 + 70).
     *
     * @test
     * @return void
     */
    public function un_cliente_con_los_tres_motivos_sale_una_vez_y_suma_los_puntajes()
    {
        $comprador = $this->crear_comprador_de($this->dueno, [
            'name'  => 'Lucas Gonzalez',
            'email' => 'lg@empresa.com',
            'phone' => '11 2233-4455',
        ]);

        $cliente = $this->crear_cliente_de($this->dueno, [
            'name'  => 'Lucas González',
            'email' => 'LG@empresa.com',
            'phone' => '(011) 2233-4455',
        ]);

        $json = $this->coincidencias($comprador);

        $this->assertCount(1, $json['models'], 'El cliente entra por las dos capas de candidatos y tiene que salir una sola vez.');
        $this->assertSame($cliente->id, $json['models'][0]['id']);
        $this->assertSame(['email_igual', 'nombre_igual', 'telefono_igual'], $json['models'][0]['motivos']);
        $this->assertSame(260, $json['models'][0]['score']);
    }

    /**
     * El orden: puntaje de mayor a menor y, a igual puntaje, por nombre SIN tildes ("Álvaro" antes
     * que "Beto": comparando bytes quedaría después de "Zeta").
     *
     * @test
     * @return void
     */
    public function ordena_por_puntaje_y_desempata_por_nombre()
    {
        $comprador = $this->crear_comprador_de($this->dueno, [
            'name'  => 'Lucas Gonzalez',
            'email' => 'lg@x.com',
            'phone' => '1122334455',
        ]);

        // Creados en desorden a propósito: el orden no puede salir del orden de inserción.
        $zeta = $this->crear_cliente_de($this->dueno, ['name' => 'Zeta Lucas']);
        $solo_nombre = $this->crear_cliente_de($this->dueno, ['name' => 'Lucas Gonzalez']);
        $perez = $this->crear_cliente_de($this->dueno, ['name' => 'Lucas Perez']);
        $solo_telefono = $this->crear_cliente_de($this->dueno, ['name' => 'Estudio Contable', 'phone' => '11-2233-4455']);
        $beto = $this->crear_cliente_de($this->dueno, ['name' => 'Beto Lucas']);
        $nombre_y_email = $this->crear_cliente_de($this->dueno, ['name' => 'Lucas Gonzalez', 'email' => 'lg@x.com']);
        $alvaro = $this->crear_cliente_de($this->dueno, ['name' => 'Álvaro Lucas']);

        $json = $this->coincidencias($comprador);

        $this->assertSame(
            [$nombre_y_email->id, $solo_nombre->id, $solo_telefono->id, $alvaro->id, $beto->id, $perez->id, $zeta->id],
            $this->ids_de($json)
        );
        $this->assertSame([190, 90, 70, 10, 10, 10, 10], array_column($json['models'], 'score'));
    }

    /**
     * El buscador ve el nombre sin tildes ni mayúsculas: "gonzalez" encuentra a "González" y a
     * "GONZÁLEZ". (Depende de la collation de la columna: un `_bin` lo rompería.)
     *
     * @test
     * @return void
     */
    public function las_tildes_y_las_mayusculas_no_impiden_encontrar_al_cliente()
    {
        $comprador = $this->crear_comprador_de($this->dueno, ['name' => 'Pena Jose']);

        $con_tilde = $this->crear_cliente_de($this->dueno, ['name' => 'José PEÑA']);

        $this->assertSame([$con_tilde->id], $this->ids_de($this->coincidencias($comprador)));
        $this->assertSame([$con_tilde->id], $this->ids_de($this->coincidencias($comprador, ['q' => 'pena'])));
    }

    // -------------------------------------------------------------------------------------------
    //  El apellido del comprador
    // -------------------------------------------------------------------------------------------

    /**
     * (a) La tienda guarda `name` y `surname` separados: "Lucas" + "Gonzalez" tiene que ser
     * `nombre_igual` con el cliente "Lucas González". El bloque `buyer` devuelve los dos crudos.
     *
     * @test
     * @return void
     */
    public function el_nombre_y_el_apellido_separados_dan_nombre_igual()
    {
        $comprador = $this->crear_comprador_de($this->dueno, ['name' => 'Lucas', 'surname' => 'Gonzalez']);

        $cliente = $this->crear_cliente_de($this->dueno, ['name' => 'Lucas González']);

        $json = $this->coincidencias($comprador);

        $this->assertSame('Lucas Gonzalez', $json['texto'], 'El texto de las sugerencias es el nombre completo: nombre y apellido.');
        $this->assertSame([$cliente->id], $this->ids_de($json));
        $this->assertSame(['nombre_igual'], $json['models'][0]['motivos']);
        $this->assertSame(90, $json['models'][0]['score']);

        // El bloque `buyer` es lo que hay guardado: la SPA arma el nombre completo con la misma regla.
        $this->assertSame('Lucas', $json['buyer']['name']);
        $this->assertSame('Gonzalez', $json['buyer']['surname']);
    }

    /**
     * (b) Si el apellido ya está dentro de `name` ("Lucas gonzalez" + "Gonzalez", como dejan los
     * compradores de las semillas), no se repite: sigue siendo `nombre_igual` y el texto no duplica
     * el apellido.
     *
     * @test
     * @return void
     */
    public function el_apellido_repetido_en_el_nombre_no_estropea_el_nombre_igual()
    {
        $comprador = $this->crear_comprador_de($this->dueno, ['name' => 'Lucas gonzalez', 'surname' => 'Gonzalez']);

        $cliente = $this->crear_cliente_de($this->dueno, ['name' => 'Lucas González']);

        $json = $this->coincidencias($comprador);

        $this->assertSame('Lucas gonzalez', $json['texto'], 'El apellido ya está en el nombre: no se agrega de nuevo.');
        $this->assertSame([$cliente->id], $this->ids_de($json));
        $this->assertSame(['nombre_igual'], $json['models'][0]['motivos']);
        $this->assertSame(90, $json['models'][0]['score']);

        $this->assertSame('Lucas gonzalez', $json['buyer']['name']);
        $this->assertSame('Gonzalez', $json['buyer']['surname']);
    }

    /**
     * Un apellido de dos palabras se suma entero, y uno que solo repite PARTE de lo que ya está en
     * el nombre también: la regla es "todas sus palabras ya están", no "alguna". Las palabras
     * repetidas se colapsan al comparar el conjunto.
     *
     * @test
     * @return void
     */
    public function el_apellido_se_suma_salvo_que_todas_sus_palabras_ya_esten_en_el_nombre()
    {
        $cliente = $this->crear_cliente_de($this->dueno, ['name' => 'Lucas Gonzalez Perez']);

        // Apellido de dos palabras, ninguna en el nombre.
        $completo = $this->crear_comprador_de($this->dueno, ['name' => 'Lucas', 'surname' => 'Gonzalez Perez']);
        $json = $this->coincidencias($completo);
        $this->assertSame('Lucas Gonzalez Perez', $json['texto']);
        $this->assertSame(['nombre_igual'], $json['models'][0]['motivos']);

        // Apellido de dos palabras, una ya está en el nombre: se agrega entero, y como el conjunto
        // {lucas, gonzalez, perez} no cambia, sigue siendo `nombre_igual`.
        $parcial = $this->crear_comprador_de($this->dueno, ['name' => 'Lucas Gonzalez', 'surname' => 'Gonzalez Perez']);
        $json = $this->coincidencias($parcial);
        $this->assertSame('Lucas Gonzalez Gonzalez Perez', $json['texto']);
        $this->assertSame([$cliente->id], $this->ids_de($json));
        $this->assertSame(['nombre_igual'], $json['models'][0]['motivos']);
    }

    /**
     * Un apellido vacío, de espacios, null o sin ninguna letra ni número ("-") no cambia el nombre.
     *
     * @test
     * @return void
     */
    public function un_apellido_vacio_o_sin_palabras_no_cambia_el_nombre()
    {
        foreach ([null, '', '   ', '-'] as $apellido) {

            $comprador = $this->crear_comprador_de($this->dueno, ['name' => 'Lucas Gonzalez', 'surname' => $apellido]);

            $this->assertSame('Lucas Gonzalez', $this->coincidencias($comprador)['texto'], 'Apellido: '.var_export($apellido, true));
        }
    }

    // -------------------------------------------------------------------------------------------
    //  Búsqueda con `q`
    // -------------------------------------------------------------------------------------------

    /**
     * Con `q` cada palabra tiene que aparecer (AND) en alguna de las columnas: acá, nombre y email.
     *
     * @test
     * @return void
     */
    public function la_busqueda_exige_todas_las_palabras_sobre_nombre_y_email()
    {
        $comprador = $this->crear_comprador_de($this->dueno, ['name' => 'Alguien Cualquiera']);

        $lucas_gonzalez = $this->crear_cliente_de($this->dueno, ['name' => 'Lucas González', 'email' => 'lucas@empresa.com']);
        $lucas_perez = $this->crear_cliente_de($this->dueno, ['name' => 'Lucas Perez', 'email' => 'lp@otro.com']);
        $marta_gonzalez = $this->crear_cliente_de($this->dueno, ['name' => 'Marta González', 'email' => 'marta@empresa.com']);

        $ambas = $this->coincidencias($comprador, ['q' => 'lucas gonzalez']);
        $this->assertSame('busqueda', $ambas['modo']);
        $this->assertSame('lucas gonzalez', $ambas['texto']);
        $this->assertSame([$lucas_gonzalez->id], $this->ids_de($ambas), 'Con dos palabras tienen que aparecer las dos: AND, no OR.');

        $una = $this->coincidencias($comprador, ['q' => 'gonzalez']);
        $this->assertEqualsCanonicalizing([$lucas_gonzalez->id, $marta_gonzalez->id], $this->ids_de($una));

        // En el email: "empresa.com" son las palabras "empresa" y "com".
        $por_email = $this->coincidencias($comprador, ['q' => 'empresa.com']);
        $this->assertEqualsCanonicalizing([$lucas_gonzalez->id, $marta_gonzalez->id], $this->ids_de($por_email));

        // Una palabra en el nombre y otra en el email.
        $mezcla = $this->coincidencias($comprador, ['q' => 'perez otro']);
        $this->assertSame([$lucas_perez->id], $this->ids_de($mezcla));

        // Sin resultados: 200 con la lista vacía, no un error.
        $this->assertSame([], $this->coincidencias($comprador, ['q' => 'inexistente'])['models']);
    }

    /**
     * Con `q` también se encuentra por razón social, CUIT, DNI, teléfono y número de cliente. El
     * CUIT, el DNI y el teléfono valen tipeados con o sin separadores.
     *
     * @test
     * @return void
     */
    public function la_busqueda_encuentra_por_razon_social_cuit_dni_telefono_y_numero()
    {
        $comprador = $this->crear_comprador_de($this->dueno, ['name' => 'Alguien Cualquiera']);

        $primero = $this->crear_cliente_de($this->dueno, [
            'name' => 'Lucas González',
            'cuit' => '20-11111111-1',
        ]);

        $segundo = $this->crear_cliente_de($this->dueno, [
            'name'         => 'Fantasia',
            'razon_social' => 'Constructora del Sur SA',
            'dni'          => '12.345.678',
            'phone'        => '11 2233-4455',
            'cuit'         => '20-22222222-2',
        ]);

        $tercero = $this->crear_cliente_de($this->dueno, ['name' => 'Cliente con numero', 'num' => 777]);

        // CUIT con guiones: "20", "11111111" y "1" tienen que estar (y solo están en el primero).
        $this->assertSame([$primero->id], $this->ids_de($this->coincidencias($comprador, ['q' => '20-11111111-1'])));

        // CUIT de corrido contra uno guardado con guiones.
        $this->assertSame([$primero->id], $this->ids_de($this->coincidencias($comprador, ['q' => '20111111111'])));

        // Razón social.
        $this->assertSame([$segundo->id], $this->ids_de($this->coincidencias($comprador, ['q' => 'constructora sur'])));

        // DNI de corrido contra uno guardado con puntos.
        $this->assertSame([$segundo->id], $this->ids_de($this->coincidencias($comprador, ['q' => '12345678'])));

        // Teléfono de corrido contra uno guardado con espacio y guion.
        $this->assertSame([$segundo->id], $this->ids_de($this->coincidencias($comprador, ['q' => '1122334455'])));

        // Número de cliente.
        $this->assertSame([$tercero->id], $this->ids_de($this->coincidencias($comprador, ['q' => '777'])));
    }

    /**
     * Los motivos y el puntaje de la búsqueda se calculan contra el COMPRADOR, no contra `q`: un
     * cliente que sale por lo que se escribió pero no se parece al comprador viene sin motivos y
     * con puntaje 0; el que sí se parece, con los suyos y más arriba.
     *
     * @test
     * @return void
     */
    public function la_busqueda_calcula_los_motivos_contra_el_comprador()
    {
        $comprador = $this->crear_comprador_de($this->dueno, [
            'name'  => 'Lucas Gonzalez',
            'email' => 'lucas@empresa.com',
        ]);

        $se_parece = $this->crear_cliente_de($this->dueno, ['name' => 'Lucas González', 'email' => 'lucas@empresa.com']);
        $no_se_parece = $this->crear_cliente_de($this->dueno, ['name' => 'Aaa Constructora', 'email' => 'aaa@empresa.com']);

        $json = $this->coincidencias($comprador, ['q' => 'empresa']);

        $this->assertSame('busqueda', $json['modo']);
        $this->assertSame([$se_parece->id, $no_se_parece->id], $this->ids_de($json), 'El que se parece al comprador va primero aunque "Aaa" gane alfabéticamente.');

        $this->assertSame(['email_igual', 'nombre_igual'], $json['models'][0]['motivos']);
        $this->assertSame(190, $json['models'][0]['score']);

        $this->assertSame([], $json['models'][1]['motivos']);
        $this->assertSame(0, $json['models'][1]['score']);
    }

    /**
     * Un `q` sin ninguna palabra útil ("%%", "-", una letra suelta) no busca nada: lista vacía, y no
     * "todos los clientes". Y un `q` de espacios, o que no es un texto, es "sin `q`": sugerencias.
     *
     * @test
     * @return void
     */
    public function un_q_sin_palabras_utiles_no_trae_nada_y_uno_en_blanco_es_sugerencias()
    {
        $comprador = $this->crear_comprador_de($this->dueno, ['name' => 'Lucas Gonzalez']);
        $this->crear_cliente_de($this->dueno, ['name' => 'Lucas González']);

        foreach (['%%', '-', 'a', '_ %'] as $q) {

            $json = $this->coincidencias($comprador, ['q' => $q]);

            $this->assertSame('busqueda', $json['modo'], 'q = '.$q);
            $this->assertSame([], $json['models'], 'q = '.$q.': un texto sin palabras útiles no puede devolver clientes.');
        }

        foreach ([['q' => '   '], ['q' => ''], ['q' => ['x']]] as $query) {

            $json = $this->coincidencias($comprador, $query);

            $this->assertSame('sugerencias', $json['modo']);
            $this->assertCount(1, $json['models']);
        }
    }

    /**
     * `q` se corta a 100 caracteres en lugar de rechazarse, y el `texto` devuelto es el cortado.
     *
     * @test
     * @return void
     */
    public function el_q_se_corta_a_100_caracteres()
    {
        $comprador = $this->crear_comprador_de($this->dueno, ['name' => 'Lucas Gonzalez']);

        $json = $this->coincidencias($comprador, ['q' => str_repeat('a', 150)]);

        $this->assertSame(100, strlen($json['texto']));
        $this->assertSame('busqueda', $json['modo']);
    }

    // -------------------------------------------------------------------------------------------
    //  Topes
    // -------------------------------------------------------------------------------------------

    /**
     * `limit`: por defecto 30, mínimo 1, techo de 50, y lo que no es un número vale como "no vino".
     * Vale igual en modo búsqueda.
     *
     * @test
     * @return void
     */
    public function el_limit_se_respeta_y_tiene_techo_en_50()
    {
        $comprador = $this->crear_comprador_de($this->dueno, ['name' => 'Zorrilla Tester']);

        $this->sembrar_clientes($this->dueno, 'Zorrilla Tester', 60);

        $this->assertCount(30, $this->coincidencias($comprador)['models'], 'Sin limit son 30.');
        $this->assertCount(5, $this->coincidencias($comprador, ['limit' => 5])['models']);
        $this->assertCount(50, $this->coincidencias($comprador, ['limit' => 500])['models'], 'El techo es 50.');
        $this->assertCount(50, $this->coincidencias($comprador, ['limit' => 50])['models']);
        $this->assertCount(1, $this->coincidencias($comprador, ['limit' => 0])['models'], 'El mínimo es 1.');
        $this->assertCount(1, $this->coincidencias($comprador, ['limit' => -3])['models']);
        $this->assertCount(30, $this->coincidencias($comprador, ['limit' => 'abc'])['models'], 'Un limit que no es número vale como el de por defecto.');
        $this->assertCount(12, $this->coincidencias($comprador, ['limit' => '12.9'])['models']);
        $this->assertCount(7, $this->coincidencias($comprador, ['q' => 'zorrilla', 'limit' => 7])['models'], 'Vale también con q.');
        $this->assertCount(50, $this->coincidencias($comprador, ['q' => 'zorrilla', 'limit' => 900])['models']);
    }

    /**
     * Las dos capas de candidatos: con una palabra muy común ("Juan", 320 veces) el tope de 300
     * filas por consulta no puede dejar afuera a los buenos. Ni al "Juan Perez" (todas las
     * palabras), ni al cliente con el MISMO EMAIL, ni al del MISMO TELÉFONO, que no comparten ni una
     * palabra con el comprador y solo pueden entrar por la capa de fuertes.
     *
     * @test
     * @return void
     */
    public function una_palabra_muy_comun_no_deja_afuera_a_los_candidatos_fuertes()
    {
        $comprador = $this->crear_comprador_de($this->dueno, [
            'name'  => 'Juan Perez',
            'email' => 'jperez@mail.com',
            'phone' => '11 5555-6666',
        ]);

        // 320 "Juan Garcia NNN": más que el tope de 300 filas de la capa de débiles, y todos ordenados
        // ANTES que "Juan Perez" por nombre.
        $this->sembrar_clientes($this->dueno, 'Juan Garcia', 320);

        $juan_perez = $this->crear_cliente_de($this->dueno, ['name' => 'Juan Perez']);
        $mismo_email = $this->crear_cliente_de($this->dueno, ['name' => 'Estudio XYZ', 'email' => 'JPEREZ@mail.com']);
        $mismo_telefono = $this->crear_cliente_de($this->dueno, ['name' => 'Contadora ABC', 'phone' => '(011) 5555 6666']);

        $json = $this->coincidencias($comprador, ['limit' => 50]);

        $this->assertCount(50, $json['models']);
        $this->assertSame(
            [$mismo_email->id, $juan_perez->id, $mismo_telefono->id],
            array_slice($this->ids_de($json), 0, 3),
            'Los tres candidatos fuertes tienen que salir primero, aunque haya 320 "Juan" de por medio.'
        );
        $this->assertSame([100, 90, 70], array_slice(array_column($json['models'], 'score'), 0, 3));

        // El resto son "Juan Garcia": una palabra en común, 10 puntos, por nombre.
        $this->assertSame('Juan Garcia 001', $json['models'][3]['name']);
        $this->assertSame(10, $json['models'][3]['score']);
        $this->assertCount(50, array_unique($this->ids_de($json)), 'No puede haber clientes repetidos.');
    }

    // -------------------------------------------------------------------------------------------
    //  Usuario en la tienda
    // -------------------------------------------------------------------------------------------

    /**
     * `tiene_usuario_en_tienda`: true si OTRO comprador del mismo comercio ya apunta a ese cliente.
     * No cuenta el propio comprador, ni uno de otro comercio.
     *
     * @test
     * @return void
     */
    public function tiene_usuario_en_tienda_solo_cuenta_a_otros_compradores_del_mismo_comercio()
    {
        $comprador = $this->crear_comprador_de($this->dueno, ['name' => 'Nora Vidal']);

        $con_otro_comprador = $this->crear_cliente_de($this->dueno, ['name' => 'Nora Vidal']);
        $con_comprador_ajeno = $this->crear_cliente_de($this->dueno, ['name' => 'Nora Ruiz']);
        $con_el_mismo = $this->crear_cliente_de($this->dueno, ['name' => 'Vidal Nora Sofia']);
        $sin_nadie = $this->crear_cliente_de($this->dueno, ['name' => 'Nora Sosa']);

        // Otro comprador del mismo comercio, ya vinculado.
        $this->crear_comprador_de($this->dueno, ['name' => 'Otro comprador', 'comercio_city_client_id' => $con_otro_comprador->id]);

        // Un comprador de OTRO comercio apuntando a este cliente (no debería existir): no cuenta.
        $this->crear_comprador_de($this->otro_dueno, ['name' => 'Comprador ajeno', 'comercio_city_client_id' => $con_comprador_ajeno->id]);

        // El comprador que se está mirando, ya apuntando a uno: su propio vínculo no es "otro".
        $comprador->comercio_city_client_id = $con_el_mismo->id;
        $comprador->save();

        $filas = $this->por_id($this->coincidencias($comprador));

        $this->assertTrue($filas[$con_otro_comprador->id]['tiene_usuario_en_tienda']);
        $this->assertFalse($filas[$con_comprador_ajeno->id]['tiene_usuario_en_tienda'], 'Un comprador de otro comercio no cuenta.');
        $this->assertFalse($filas[$con_el_mismo->id]['tiene_usuario_en_tienda'], 'El propio vínculo del comprador no es "otro comprador".');
        $this->assertFalse($filas[$sin_nadie->id]['tiene_usuario_en_tienda']);
    }

    // -------------------------------------------------------------------------------------------
    //  Aislamiento
    // -------------------------------------------------------------------------------------------

    /**
     * Nunca clientes de otro comercio ni borrados, ni por sugerencias ni por búsqueda, aunque
     * coincidan por nombre, email y teléfono.
     *
     * @test
     * @return void
     */
    public function no_devuelve_clientes_de_otro_comercio_ni_borrados()
    {
        $comprador = $this->crear_comprador_de($this->dueno, [
            'name'  => 'Lucas Gonzalez',
            'email' => 'lg@x.com',
            'phone' => '1122334455',
        ]);

        $mismos_datos = ['name' => 'Lucas Gonzalez', 'email' => 'lg@x.com', 'phone' => '1122334455'];

        $propio = $this->crear_cliente_de($this->dueno, $mismos_datos);
        $borrado = $this->crear_cliente_de($this->dueno, $mismos_datos);
        $ajeno = $this->crear_cliente_de($this->otro_dueno, $mismos_datos);

        $borrado->delete();

        $sugerencias = $this->coincidencias($comprador);
        $this->assertSame([$propio->id], $this->ids_de($sugerencias), 'Sugerencias: ni el borrado ('.$borrado->id.') ni el del otro comercio ('.$ajeno->id.').');

        $busqueda = $this->coincidencias($comprador, ['q' => 'gonzalez']);
        $this->assertSame([$propio->id], $this->ids_de($busqueda), 'Búsqueda: ni el borrado ni el del otro comercio.');

        $por_email = $this->coincidencias($comprador, ['q' => 'lg x com']);
        $this->assertSame([$propio->id], $this->ids_de($por_email));
    }

    /**
     * Un comprador de otro comercio, uno que no existe y un id que no es un número dan el mismo 404
     * con el mismo mensaje: no se revela que el comprador existe en otro comercio.
     *
     * @test
     * @return void
     */
    public function un_comprador_ajeno_o_inexistente_da_404()
    {
        $ajeno = $this->crear_comprador_de($this->otro_dueno, ['name' => 'Comprador ajeno']);
        $propio = $this->crear_comprador_de($this->dueno, ['name' => 'Comprador propio']);

        $pedidos = [
            'ajeno'                  => $ajeno->id,
            'inexistente'            => 999999999,
            'no numérico'            => 'abc',
            // MySQL compara `id = '12abc'` como 12: sin validar el id, esto devolvería al comprador.
            'entero con basura'      => $propio->id.'abc',
            'cero'                   => 0,
        ];

        foreach ($pedidos as $descripcion => $id) {

            $respuesta = $this->pedir($id);

            $this->assertSame(404, $respuesta->getStatusCode(), 'Comprador '.$descripcion.' ('.$id.'): tiene que ser 404.');

            $respuesta->assertExactJson(['message' => 'Comprador no encontrado.']);
        }
    }

    /**
     * Un empleado ve los compradores y los clientes de su dueño: todo se scopea por el dueño real
     * (`userId()`), no por el usuario que está logueado.
     *
     * @test
     * @return void
     */
    public function un_empleado_ve_lo_del_dueno()
    {
        $comprador = $this->crear_comprador_de($this->dueno, ['name' => 'Lucas Gonzalez']);
        $cliente = $this->crear_cliente_de($this->dueno, ['name' => 'Lucas González']);

        $this->actuar_como($this->crear_empleado($this->dueno));

        $json = $this->coincidencias($comprador);

        $this->assertSame([$cliente->id], $this->ids_de($json));
    }

    // -------------------------------------------------------------------------------------------
    //  El contrato con la SPA
    // -------------------------------------------------------------------------------------------

    /**
     * Las claves exactas y sus tipos. La SPA lee estos nombres tal cual: una clave mal escrita no
     * explota, deja el modal vacío en silencio.
     *
     * @test
     * @return void
     */
    public function el_payload_tiene_las_claves_y_los_tipos_del_contrato()
    {
        $comprador = $this->crear_comprador_de($this->dueno, [
            'name'    => 'Lucas',
            'surname' => 'Gonzalez',
            'email'   => 'x@y.com',
            'phone'   => '1122334455',
        ]);

        $this->crear_cliente_de($this->dueno, [
            'num'          => 12,
            'name'         => 'Lucas González',
            'razon_social' => 'LG SRL',
            'email'        => 'x@y.com',
            'phone'        => '11 2233-4455',
            'address'      => 'Calle 123',
            'cuit'         => '20-11111111-1',
            'dni'          => '11111111',
        ]);

        $json = $this->coincidencias($comprador);

        $this->assertEqualsCanonicalizing(['buyer', 'texto', 'modo', 'models'], array_keys($json));

        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'surname', 'email', 'phone', 'comercio_city_client_id'],
            array_keys($json['buyer'])
        );
        $this->assertSame($comprador->id, $json['buyer']['id']);
        $this->assertNull($json['buyer']['comercio_city_client_id']);

        $this->assertCount(1, $json['models']);

        $fila = $json['models'][0];

        $this->assertEqualsCanonicalizing(
            ['id', 'num', 'name', 'razon_social', 'email', 'phone', 'address', 'cuit', 'dni', 'motivos', 'score', 'tiene_usuario_en_tienda'],
            array_keys($fila)
        );

        $this->assertIsInt($fila['id']);
        $this->assertSame(12, $fila['num']);
        $this->assertSame('LG SRL', $fila['razon_social']);
        $this->assertSame('11 2233-4455', $fila['phone'], 'El teléfono sale como está guardado, no normalizado.');
        $this->assertSame('Calle 123', $fila['address']);
        $this->assertIsArray($fila['motivos']);
        $this->assertIsInt($fila['score']);
        $this->assertIsBool($fila['tiene_usuario_en_tienda']);
    }

    /**
     * Un comprador con casi nada cargado (sin email, sin teléfono, sin apellido) no rompe, y los
     * campos vacíos no se emparejan entre sí: dos emails vacíos NO son `email_igual`.
     *
     * @test
     * @return void
     */
    public function un_comprador_con_datos_vacios_no_rompe_ni_empareja_vacios()
    {
        $comprador = $this->crear_comprador_de($this->dueno, ['name' => 'Zzz Comprador', 'email' => '', 'phone' => '']);

        $otro_zzz = $this->crear_cliente_de($this->dueno, ['name' => 'Zzz Otro', 'email' => '', 'phone' => '']);
        $this->crear_cliente_de($this->dueno, ['name' => 'Yyy Cliente', 'email' => '', 'phone' => '']);

        $json = $this->coincidencias($comprador);

        $this->assertSame([$otro_zzz->id], $this->ids_de($json), '"Yyy Cliente" no comparte nada con el comprador: los emails y teléfonos vacíos no cuentan.');
        $this->assertSame(['nombre_parecido'], $json['models'][0]['motivos']);
    }

    /**
     * Un comprador cuyo nombre no tiene ninguna palabra útil y sin email ni teléfono no tiene con
     * qué buscar: 200 con lista vacía. Sin la guarda, la consulta quedaría sin condiciones y
     * traería a cualquier cliente.
     *
     * @test
     * @return void
     */
    public function un_comprador_sin_nada_para_buscar_no_trae_clientes()
    {
        $comprador = $this->crear_comprador_de($this->dueno, ['name' => '??']);

        $this->crear_cliente_de($this->dueno, ['name' => 'Lucas González']);

        $json = $this->coincidencias($comprador);

        $this->assertSame('sugerencias', $json['modo']);
        $this->assertSame([], $json['models']);
    }
}
