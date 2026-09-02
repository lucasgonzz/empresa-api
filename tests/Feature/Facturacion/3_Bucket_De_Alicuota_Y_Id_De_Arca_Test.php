<?php

namespace Tests\Feature\Facturacion;

use App\Http\Controllers\Helpers\Afip\AfipImportesResolver;
use App\Http\Controllers\Helpers\Afip\MakeAfipTicket;
use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\AfipHelper\AfipImportesCalculator;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\Iva;
use App\Models\IvaCondition;
use App\Models\NotaCreditoDescription;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\EmpresaTestCase;

/**
 * Ningun bucket de IVA puede salir con un `Id` de alicuota que ARCA no reconozca, y la tabla de
 * alicuotas tiene que estar escrita en UN solo lugar.
 *
 * Por que existe este archivo. El acumulador de alicuotas es un arreglo indexado por una CLAVE
 * INTERNA (`'10'` es 10,5 %, `'2'` es 2,5 %). Hasta esta mision habia dos formas de crear un bucket:
 *
 *  - `add_iva_bucket()` con una clave conocida, que ponia el Id correcto; y
 *  - `add_iva_bucket()` con una clave desconocida, que creaba el bucket con **`'Id' => 0`**.
 *
 * Y las lineas de descripcion libre entraban SIEMPRE por la segunda: armaban la clave con
 * `(string) (float) $percentage`, o sea `'10.5'` y `'2.5'`, que no estan en la tabla. Ese `Id => 0`
 * viaja tal cual al payload de ARCA (`AfipWsfeHelper`, `AfipNotaCreditoHelper`) y sale como codigo
 * de alicuota `0000` en el TXT que sube el contador. Es el caso de libro de
 * `APRENDER_NO_PARCHEAR.md`: una medicion que falla y devuelve un valor tranquilizador. El
 * comprobante se emite igual, nadie se entera, y el desglose fiscal queda mal para siempre.
 *
 * 🔴 Como se ejercita SIN red: se arma un `AfipTicket` EN MEMORIA (sin `save()`) con la venta y la
 * configuracion fiscal, y se llama `(new AfipHelper(...))->getImportes()`. NUNCA se llama
 * `MakeAfipTicket::make_afip_ticket()`, que dispara `AfipWsController` contra ARCA de verdad.
 *
 * Sobre las aserciones de plata: `assertEqualsWithDelta()`, NUNCA `assertEquals()` con un cuarto
 * argumento — PHPUnit 9.6 lo descarta en silencio y compara con el EPSILON de 1e-10.
 *
 * @group facturacion
 * @group iva-claves-alicuota
 */
class Bucket_De_Alicuota_Y_Id_De_Arca_Test extends EmpresaTestCase
{
    /**
     * Delta de tolerancia para comparaciones de plata (nunca assertSame sobre floats).
     */
    const DELTA = 0.01;

    /**
     * Fecha de lo que siembra esta clase. Marzo de 2014, la misma ventana reservada que usa
     * `Tests\Feature\Iva\Claves_De_Alicuota_Unificadas_Test`: nada mas de la suite cae ahi.
     */
    const FECHA = '2014-03-11 10:00:00';

    /**
     * Ids de las ventas sembradas, para borrarlas en el tearDown.
     *
     * @var array<int, int>
     */
    protected $ventas_sembradas = [];

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->ventas_sembradas as $sale_id) {
            $sale = Sale::find($sale_id);

            if (is_null($sale)) {
                continue;
            }

            $sale->articles()->detach();
            $sale->forceDelete();
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------------------------------
    // Montaje
    // -----------------------------------------------------------------------------------------

    /**
     * Usuario duenio del fixture.
     *
     * @return \App\Models\User
     */
    protected function usuario_de_testing()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail();
    }

    /**
     * Configuracion fiscal EN MEMORIA (no toca la base) con la condicion IVA pedida.
     *
     * Responsable inscripto no es un detalle: `AfipImportesCalculator::calculate()` SOLO desglosa
     * por alicuota si el emisor lo es. Con cualquier otra condicion, todo este archivo mediria la
     * rama equivocada y pasaria por casualidad.
     *
     * @param  string $condicion
     * @return \App\Models\AfipInformation
     */
    protected function afip_information_en_memoria($condicion = 'Responsable inscripto')
    {
        $iva_condition = IvaCondition::where('name', $condicion)->first();

        $this->assertNotNull(
            $iva_condition,
            'Falta la iva_condition "'.$condicion.'" en la base de testing. Sin ella el calculador '.
            'entra por la rama de no responsable inscripto y este archivo no mide nada.'
        );

        $afip_information = new AfipInformation();
        $afip_information->iva_condition_id = $iva_condition->id;
        $afip_information->punto_venta = 97;
        $afip_information->cuit = '20111111112';
        $afip_information->setRelation('iva_condition', $iva_condition);

        return $afip_information;
    }

    /**
     * Crea una venta minima del comercio del fixture.
     *
     * @param  float $total
     * @return \App\Models\Sale
     */
    protected function crear_venta($total = 0)
    {
        $venta = Sale::create([
            'user_id'                    => $this->usuario_de_testing()->id,
            'client_id'                  => null,
            'omitir_en_cuenta_corriente' => 0,
            'save_current_acount'        => 0,
            'terminada'                  => 1,
            'moneda_id'                  => 1,
            'total'                      => $total,
            'created_at'                 => self::FECHA,
        ]);

        $this->ventas_sembradas[] = $venta->id;

        return $venta;
    }

    /**
     * Cuelga de la venta un articulo del fixture con su alicuota historica en el pivot.
     *
     * 🔴 La alicuota va como texto CRUDO ('10.5'): asi la compara
     * `AfipItemCalculator::get_importe_iva()`, con `(string) $pivot->iva_percentage == (string) $iva`.
     *
     * @param  \App\Models\Sale $venta
     * @param  string $nombre_articulo
     * @param  float $price Precio CON IVA incluido.
     * @param  float $amount
     * @param  string $iva_percentage
     * @return void
     */
    protected function agregar_articulo($venta, $nombre_articulo, $price, $amount, $iva_percentage)
    {
        $articulo = $this->articulo($nombre_articulo);

        if (is_null($articulo)) {
            $this->fail('No existe el articulo "'.$nombre_articulo.'" en el fixture de testing.');
        }

        $venta->articles()->attach($articulo->id, [
            'amount'         => $amount,
            'price'          => $price,
            'iva_percentage' => $iva_percentage,
        ]);
    }

    /**
     * Arma EN MEMORIA una linea de descripcion libre con la alicuota pedida.
     *
     * En memoria y no persistida a proposito: lo que se mide es el ruteo a bucket, no la
     * persistencia. `AfipItemCalculator::get_description_iva()` solo lee `price`, y el calculador
     * solo lee `iva->percentage` y `notes`.
     *
     * @param  string $notes
     * @param  float $price Precio CON IVA incluido.
     * @param  string $iva_percentage Valor EXACTO de `ivas.percentage` (puede ser 'Exento').
     * @return \App\Models\NotaCreditoDescription
     */
    protected function descripcion_en_memoria($notes, $price, $iva_percentage)
    {
        $iva = Iva::where('percentage', $iva_percentage)->first();

        if (is_null($iva)) {
            $this->fail(
                'No existe la alicuota "'.$iva_percentage.'" en la tabla ivas del fixture. El seeder '.
                'carga 27, 21, 10.5, 5, 2.5, 0, Exento, No Gravado y 50 — pero la tabla es ABIERTA '.
                '(la importacion de articulos por Excel crea filas nuevas), asi que si esta buscando '.
                'un valor que el seeder no trae, use descripcion_con_percentage_crudo().'
            );
        }

        $description = new NotaCreditoDescription();
        $description->notes = $notes;
        $description->price = $price;
        $description->iva_id = $iva->id;
        $description->setRelation('iva', $iva);

        return $description;
    }

    /**
     * Igual que `descripcion_en_memoria()`, pero con un `ivas.percentage` ARBITRARIO, sin exigir
     * que la fila exista en la base.
     *
     * 🔴 Existe porque la tabla `ivas` es un conjunto ABIERTO: `ProcessRow.php:3530` y
     * `LocalImportHelper.php:459` hacen `Iva::create(['percentage' => $iva])` con el texto CRUDO de
     * una columna de una planilla de importacion de articulos, sin validar nada. O sea que en
     * produccion hay `percentage` con espacios al final y con coma decimal, que el seeder de
     * testing no tiene y por lo tanto ningun test podria ejercitar de otra forma.
     *
     * El `Iva` va en memoria (sin `save()`) a proposito: no se esta midiendo la persistencia, y
     * ensuciar la tabla `ivas` del fixture afectaria a otros tests. El calculador solo lee
     * `iva->percentage`, y `get_description_iva()` solo lee `price`.
     *
     * @param  string $notes
     * @param  float $price Precio CON IVA incluido.
     * @param  string $percentage Texto crudo, tal como podria haber quedado en la base.
     * @return \App\Models\NotaCreditoDescription
     */
    protected function descripcion_con_percentage_crudo($notes, $price, $percentage)
    {
        $iva = new Iva();
        $iva->percentage = $percentage;

        $description = new NotaCreditoDescription();
        $description->notes = $notes;
        $description->price = $price;
        $description->setRelation('iva', $iva);

        return $description;
    }

    /**
     * Corre el calculo real de importes sobre una venta, con articulos y descripciones libres.
     *
     * @param  \App\Models\Sale $venta
     * @param  mixed $articles Coleccion de articulos, o null para tomarlos de la venta.
     * @param  array $descriptions Lineas de descripcion libre.
     * @param  bool $tolerar_alicuota_desconocida `true` = camino de LECTURA (Libro IVA Ventas,
     *                                            TXT, PDF); `false` = camino de EMISION.
     * @return array Resultado de `AfipHelper::getImportes()`.
     */
    protected function importes_de($venta, $articles, $descriptions = [], $tolerar_alicuota_desconocida = false)
    {
        $afip_ticket = new AfipTicket();
        $afip_ticket->cbte_letra = 'A';
        $afip_ticket->cbte_tipo = '1';
        $afip_ticket->punto_venta = 97;
        $afip_ticket->setRelation('afip_information', $this->afip_information_en_memoria());

        $afip_helper = new AfipHelper($afip_ticket, $articles, [], $this->usuario_de_testing(), $venta, $descriptions, null);

        /*
         * 🔴 En el camino de emision se llama SIN argumento, a proposito, y no con un `false`
         * explicito: lo que hay que fijar es el DEFAULT de `getImportes()`, que es lo que usan
         * `AfipWsfeHelper` y `AfipNotaCreditoHelper`. Medido: pasando `false` a mano, invertir el
         * default en produccion dejaba este archivo entero en VERDE.
         */
        if (!$tolerar_alicuota_desconocida) {
            return $afip_helper->getImportes();
        }

        return $afip_helper->getImportes(true);
    }

    /**
     * Devuelve la clave del unico bucket que quedo con plata (base imponible o IVA).
     *
     * @param  array $ivas
     * @return string|null Null si no quedo ninguno, o la clave del primero con plata.
     */
    protected function bucket_con_plata($ivas)
    {
        foreach ($ivas as $key => $bucket) {
            if ((float) $bucket['BaseImp'] != 0 || (float) $bucket['Importe'] != 0) {
                return (string) $key;
            }
        }

        return null;
    }

    // -----------------------------------------------------------------------------------------
    // B1-B4 — el calculador
    // -----------------------------------------------------------------------------------------

    /**
     * B1 — una descripcion libre al 10,5 % cae en el MISMO bucket que un articulo al 10,5 %.
     *
     * Escenario E2, con los numeros hechos a mano (los precios son CON IVA incluido):
     *   - articulo Cuchilla, 1 unidad a 1105,00 -> base 1000,00 / IVA 105,00
     *   - descripcion "Reintegro por flete", 552,50 -> base  500,00 / IVA  52,50
     *
     *   El bucket '10' tiene que quedar en BaseImp 1500,00 / Importe 157,50 / Id 4.
     *
     * Pre-arreglo quedaban DOS buckets: '10' con 1000,00/105,00 (Id 4) y '10.5' con 500,00/52,50 y
     * **Id 0**. La misma alicuota, partida en dos renglones, uno de ellos con un codigo que ARCA no
     * tiene.
     *
     * @group facturacion
     * @test
     */
    public function la_descripcion_libre_al_10_5_cae_en_el_bucket_del_articulo_al_10_5()
    {
        $venta = $this->crear_venta(1657.50);
        $this->agregar_articulo($venta, 'Cuchilla', 1105, 1, '10.5');

        $venta = Sale::find($venta->id);

        $importes = $this->importes_de(
            $venta,
            $venta->articles,
            [$this->descripcion_en_memoria('Reintegro por flete', 552.50, '10.5')]
        );

        $this->assertArrayHasKey(
            '10',
            $importes['ivas'],
            'la clave interna del bucket de 10,5 % es "10". Es un contrato con empresa-spa y hay '.
            'datos persistidos en produccion con ella'
        );

        $this->assertEqualsWithDelta(
            1500.00,
            (float) $importes['ivas']['10']['BaseImp'],
            self::DELTA,
            'base imponible del bucket de 10,5 % (literal: 1000,00 del articulo + 500,00 de la '.
            'descripcion libre)'
        );

        $this->assertEqualsWithDelta(
            157.50,
            (float) $importes['ivas']['10']['Importe'],
            self::DELTA,
            'IVA del bucket de 10,5 % (literal: 105,00 del articulo + 52,50 de la descripcion libre)'
        );

        $this->assertSame(
            4,
            $importes['ivas']['10']['Id'],
            'Id de alicuota de ARCA para 10,5 %. Tiene que ser int 4: es el valor que viaja tal cual '.
            'al payload de ARCA y al codigo de alicuota del TXT'
        );

        $this->assertArrayNotHasKey(
            '10.5',
            $importes['ivas'],
            'EL DEFECTO EN UNA LINEA: un bucket "10.5" es la descripcion libre armando su clave con '.
            '(string) (float) $percentage en vez de traducirla a la clave interna. Es la misma '.
            'alicuota declarada dos veces, y la segunda con Id 0'
        );

        /*
         * La otra mitad: el IVA total del comprobante tiene que estar ENTERO en ese unico bucket.
         * Sin esto, un verde no probaria nada: dos buckets que sumen bien tambien pasarian las
         * aserciones de arriba si alguna se aflojara.
         */
        $this->assertEqualsWithDelta(
            (float) $importes['iva'],
            (float) $importes['ivas']['10']['Importe'],
            self::DELTA,
            'ESCENARIO MAL ARMADO si esto falla: en E2 todo el IVA es de 10,5 %, asi que el bucket '.
            'tiene que contener el IVA COMPLETO del comprobante'
        );

        $this->assertSame(
            '10',
            $this->bucket_con_plata($importes['ivas']),
            'tiene que quedar un unico bucket con plata, y tiene que ser el "10"'
        );
    }

    /**
     * B2 — recorre las nueve alicuotas QUE CARGA EL SEEDER como descripcion libre: ninguna produce
     * un bucket con `Id => 0`, y ninguna produce una clave fuera de la tabla.
     *
     * Las ocho primeras tienen que rutear a un bucket conocido. La novena (`'50'`, que existe en la
     * tabla `ivas` pero ARCA no reconoce) tiene que CORTAR con excepcion en vez de inventar un Id,
     * y eso lo mide B3 en detalle; aca se comprueba que no devuelve un bucket a escondidas.
     *
     * 🔴 Nueve NO es "todas": la tabla `ivas` es un conjunto ABIERTO. `ProcessRow.php:3530` y
     * `LocalImportHelper.php:459` hacen `Iva::create(['percentage' => $iva])` con el texto crudo de
     * una columna de una planilla de importacion de articulos, sin validar nada, asi que en
     * produccion hay valores que el seeder no tiene: con espacios al final, con coma decimal, o
     * directamente cualquier cosa. Este test cubre lo que el seeder trae; el texto sucio y la
     * alicuota genuinamente desconocida los cubren los tests de abajo, que arman el `percentage`
     * a mano con `descripcion_con_percentage_crudo()`.
     *
     * 🔴 El mapa clave-esperada esta escrito A MANO. Si lo leyera de `AfipImportesResolver`, el test
     * no mediria nada: pasaria tambien con la tabla cambiada.
     *
     * @group facturacion
     * @test
     */
    public function ninguna_de_las_nueve_alicuotas_del_seeder_produce_un_bucket_con_id_cero()
    {
        /**
         * @var array<string, string|null> Las nueve del seeder -> bucket esperado. `null` = tiene
         * que tirar excepcion en vez de producir bucket.
         */
        $esperado = [
            '27'         => '27',
            '21'         => '21',
            '10.5'       => '10',
            '5'          => '5',
            '2.5'        => '2',
            '0'          => '0',
            // Exento y No Gravado caen en el bucket '0' A PROPOSITO: hoy se declaran como 0 %
            // gravado y moverlas a ImpOpEx cambiaria el payload de ARCA. Ver B4.
            'Exento'     => '0',
            'No Gravado' => '0',
            '50'         => null,
        ];

        /** @var array<int, int> Ids de ARCA validos, escritos a mano. */
        $ids_validos = [6, 5, 4, 8, 9, 3];
        /** @var array<int, string> Claves internas validas, escritas a mano. */
        $claves_validas = ['27', '21', '10', '5', '2', '0'];

        $venta = $this->crear_venta(1000);

        foreach ($esperado as $percentage => $bucket_esperado) {

            $descripcion = $this->descripcion_en_memoria('Linea al '.$percentage, 1000, (string) $percentage);

            if (is_null($bucket_esperado)) {
                /** @var bool $tiro Si el calculador corto la emision, como tiene que hacer. */
                $tiro = false;

                try {
                    $this->importes_de($venta, [], [$descripcion]);
                } catch (InvalidArgumentException $e) {
                    $tiro = true;
                }

                $this->assertTrue(
                    $tiro,
                    'la alicuota "'.$percentage.'" no la reconoce ARCA: el calculador tiene que CORTAR, '.
                    'no devolver un bucket con un Id inventado'
                );

                continue;
            }

            $importes = $this->importes_de($venta, [], [$descripcion]);

            $this->assertSame(
                (string) $bucket_esperado,
                $this->bucket_con_plata($importes['ivas']),
                'la alicuota "'.$percentage.'" tiene que caer en el bucket "'.$bucket_esperado.'"'
            );

            foreach ($importes['ivas'] as $key => $bucket) {

                $this->assertContains(
                    (string) $key,
                    $claves_validas,
                    'con la alicuota "'.$percentage.'" aparecio el bucket "'.$key.'", que no es una '.
                    'clave interna valida. Las validas son 27, 21, 10, 5, 2 y 0 (ojo: "10" es 10,5 % '.
                    'y "2" es 2,5 %)'
                );

                $this->assertNotSame(
                    0,
                    $bucket['Id'],
                    'EL DEFECTO EN UNA LINEA: con la alicuota "'.$percentage.'" el bucket "'.$key.'" '.
                    'salio con Id 0. Ese cero viaja tal cual al payload de ARCA y sale como codigo de '.
                    'alicuota 0000 en el TXT del contador'
                );

                $this->assertContains(
                    (int) $bucket['Id'],
                    $ids_validos,
                    'con la alicuota "'.$percentage.'" el bucket "'.$key.'" salio con el Id '.
                    $bucket['Id'].', que no es un Id de alicuota de ARCA'
                );
            }
        }

        /*
         * 🔴 Y el guardia de ultima instancia, medido aparte y por reflexion.
         *
         * `add_iva_bucket()` es el UNICO lugar donde se crea un bucket, y desde esta mision tira
         * en vez de poner `'Id' => 0`. Hoy NINGUN camino de produccion le puede pasar una clave
         * desconocida: la traduccion de arriba las normaliza antes. O sea que si se repusiera el
         * `'Id' => 0` sin tocar nada mas, las aserciones de este archivo seguirian TODAS en verde
         * y el guardia se perderia sin que nada lo denunciara.
         *
         * Por eso se lo ejercita directo: es defensa en profundidad para el proximo llamador, y
         * lo que se esta fijando es que el default silencioso no vuelva.
         */
        $add_iva_bucket = new ReflectionMethod(AfipImportesCalculator::class, 'add_iva_bucket');
        $add_iva_bucket->setAccessible(true);

        /** @var \InvalidArgumentException|null $excepcion La que tiene que tirar el guardia. */
        $excepcion = null;

        try {
            $add_iva_bucket->invoke(
                new AfipImportesCalculator(),
                ['10' => ['BaseImp' => 0, 'Importe' => 0, 'Id' => 4]],
                '10.5',
                ['BaseImp' => 500, 'Importe' => 52.50]
            );
        } catch (InvalidArgumentException $e) {
            $excepcion = $e;
        }

        $this->assertNotNull(
            $excepcion,
            'EL DEFECTO EN UNA LINEA: add_iva_bucket() tiene que TIRAR ante una clave que no esta en '.
            'la tabla, nunca crear el bucket con "Id" => 0. Ese cero viaja tal cual al payload de '.
            'ARCA y sale como codigo de alicuota 0000 en el TXT del contador'
        );

        $this->assertStringContainsString(
            '10.5',
            $excepcion->getMessage(),
            'el mensaje tiene que nombrar la clave que no se pudo resolver'
        );
    }

    /**
     * B3 — una descripcion con una alicuota que ARCA no reconoce (50 %) CORTA la emision con un
     * mensaje que la nombra, en vez de devolver un bucket con Id 0.
     *
     * 🔴 Por que tirar y no poner un default: no saber que alicuota es NO se arregla inventando una.
     * Un `Id => 0` se emite igual, nadie se entera, y el desglose fiscal del comercio queda mal para
     * siempre. Un 500 con el nombre de la linea y de la alicuota es peor UX y mejor contabilidad.
     *
     * @group facturacion
     * @test
     */
    public function una_alicuota_que_arca_no_reconoce_corta_la_emision_en_vez_de_inventar_un_id()
    {
        $venta = $this->crear_venta(1000);

        /** @var \InvalidArgumentException|null $excepcion La que tiro el calculador. */
        $excepcion = null;
        /** @var array|null $importes Lo que devolvio, si es que devolvio algo. */
        $importes = null;

        try {
            $importes = $this->importes_de(
                $venta,
                [],
                [$this->descripcion_en_memoria('Servicio raro al 50', 1500, '50')]
            );
        } catch (InvalidArgumentException $e) {
            $excepcion = $e;
        }

        $this->assertNull(
            $importes,
            'con una alicuota desconocida el calculador NO puede devolver importes: devolver algo '.
            'significa que se invento un bucket, y ese bucket sale con Id 0 rumbo a ARCA'
        );

        $this->assertNotNull(
            $excepcion,
            'una alicuota que ARCA no reconoce tiene que tirar InvalidArgumentException'
        );

        $this->assertStringContainsString(
            '50',
            $excepcion->getMessage(),
            'el mensaje tiene que NOMBRAR la alicuota que no se pudo resolver, o el que lo lee no '.
            'sabe que arreglar'
        );

        $this->assertStringContainsString(
            'Servicio raro al 50',
            $excepcion->getMessage(),
            'el mensaje tiene que nombrar tambien la LINEA, que es lo que el operador puede corregir'
        );
    }

    /**
     * B4 — TEST DE CARACTERIZACION: 'Exento' y 'No Gravado' siguen cayendo en el bucket '0' (Id 3),
     * con IVA cero y sumando al GRAVADO.
     *
     * 🔴 Esto NO es lo correcto fiscalmente, y esta puesto a proposito. Una linea exenta deberia
     * irse a `ImpOpEx`, no declararse como 0 % gravado. Hasta esta mision caia en el bucket '0' por
     * accidente del cast (`(float) 'Exento'` da 0.0); ahora cae ahi por un `if` explicito, con el
     * mismo numero exacto.
     *
     * Se preserva porque arreglarlo MUEVE `ImpNeto`/`ImpOpEx`/`ImpTotConc` del payload que se le
     * manda a ARCA, y eso es otra mision y otro riesgo. Este test es el guardian de esa decision:
     * si alguien "arregla" el ruteo sin medir el payload, se pone rojo y tiene que venir a leer esto.
     *
     * @group facturacion
     * @test
     */
    public function exento_y_no_gravado_siguen_declarandose_en_el_bucket_cero_y_sumando_al_gravado()
    {
        $venta = $this->crear_venta(2000);

        $importes = $this->importes_de($venta, [], [
            $this->descripcion_en_memoria('Libro exento', 1000, 'Exento'),
            $this->descripcion_en_memoria('Tasa municipal', 1000, 'No Gravado'),
        ]);

        $this->assertArrayHasKey(
            '0',
            $importes['ivas'],
            'las dos lineas tienen que quedar en el bucket "0"'
        );

        $this->assertSame(
            3,
            $importes['ivas']['0']['Id'],
            'Id de alicuota de ARCA para 0 %. Es el que se les sigue declarando a Exento y No Gravado'
        );

        $this->assertEqualsWithDelta(
            2000.00,
            (float) $importes['ivas']['0']['BaseImp'],
            self::DELTA,
            'las dos lineas enteras (1000,00 + 1000,00) se declaran como base imponible al 0 %'
        );

        $this->assertEqualsWithDelta(
            0.00,
            (float) $importes['ivas']['0']['Importe'],
            self::DELTA,
            'al 0 % no hay IVA que declarar'
        );

        $this->assertEqualsWithDelta(
            2000.00,
            (float) $importes['gravado'],
            self::DELTA,
            'CARACTERIZACION: hoy Exento y No Gravado suman al GRAVADO. Es una divergencia fiscal '.
            'reconocida y preservada a proposito, porque moverlas cambia ImpNeto/ImpOpEx/ImpTotConc '.
            'del payload de ARCA. Si esto se pone rojo, medi el payload ANTES de tocar nada'
        );

        $this->assertEqualsWithDelta(
            0.00,
            (float) $importes['neto_no_gravado'],
            self::DELTA,
            'CARACTERIZACION: la linea No Gravado NO va hoy a ImpTotConc. Se preserva'
        );

        $this->assertEqualsWithDelta(
            0.00,
            (float) $importes['exento'],
            self::DELTA,
            'CARACTERIZACION: la linea Exento NO va hoy a ImpOpEx. Se preserva'
        );

        $this->assertSame(
            '0',
            $this->bucket_con_plata($importes['ivas']),
            'no puede aparecer ningun otro bucket: si "Exento" se colara como numero, terminaria en '.
            'un bucket propio con Id 0'
        );
    }

    // -----------------------------------------------------------------------------------------
    // B5-B6 — el contrato con la SPA y la fuente unica
    // -----------------------------------------------------------------------------------------

    /**
     * B5 — el contrato de API con `empresa-spa` no se movio: las claves validas siguen siendo
     * exactamente `['27','21','10','5','2','0']`, en ese orden y como STRINGS.
     *
     * 🔴 Esto es lo que impide "unificar hacia la otra convencion" por descuido. `empresa-spa` manda
     * `'10'` y `'2'` en el reparto del importe personalizado (documentado en tres archivos del
     * front), y `afip_tickets.importe_personalizado_ivas_json` ya tiene datos en produccion con esas
     * claves. Cambiarlas rompe el front y vuelve ilegibles los comprobantes ya emitidos.
     *
     * El orden tampoco es cosmetico: `repartir_importe_personalizado()` le encaja el descuadre de
     * centavos a la ULTIMA fila viva, que tiene que ser la de alicuota MENOR.
     *
     * `assertSame` y no `assertEquals`: los strings tienen que seguir siendo strings. PHP convierte
     * a entero toda clave de array que sea un string numerico, asi que sin el `(string)` explicito de
     * `AfipImportesResolver::keys()` esto devolveria enteros y el `in_array(..., true)` de
     * `validar_filas_importe_personalizado()` rechazaria TODO el reparto que manda la SPA.
     *
     * @group facturacion
     * @test
     */
    public function el_contrato_de_claves_de_alicuota_con_la_spa_sigue_igual()
    {
        $this->assertSame(
            ['27', '21', '10', '5', '2', '0'],
            MakeAfipTicket::keys_de_alicuota_validas(),
            'claves de alicuota que publica la API. Son claves INTERNAS ("10" es 10,5 % y "2" es '.
            '2,5 %), tienen que ser STRINGS y tienen que venir en ese orden'
        );

        /*
         * La otra mitad del contrato: '10.5' es una clave INVALIDA y tiene que dar error, no
         * descartarse en silencio. Un descarte silencioso fue lo que hizo salir una factura de
         * 100.000 con IVA cero.
         */
        $resultado = MakeAfipTicket::validar_filas_importe_personalizado([
            ['key' => '10.5', 'importe' => 1105],
        ]);

        $this->assertNotNull(
            $resultado['error'],
            'la clave "10.5" no es valida y tiene que dar un 422 con mensaje, nunca un descarte '.
            'silencioso'
        );

        $this->assertSame(
            [],
            $resultado['filas'],
            'todo o nada: si una fila es invalida no se guarda ninguna'
        );

        /*
         * Y la clave valida tiene que seguir pasando, o el test de arriba pasaria tambien con un
         * validador que rechaza todo.
         */
        $resultado_valido = MakeAfipTicket::validar_filas_importe_personalizado([
            ['key' => '10', 'importe' => 1105],
        ]);

        $this->assertNull(
            $resultado_valido['error'],
            'ESCENARIO MAL ARMADO si esto falla: la clave "10" es la valida para 10,5 % y tiene que '.
            'pasar la validacion'
        );
    }

    /**
     * B6 — la tabla de alicuotas, espejada A MANO: clave interna, Id de ARCA y porcentaje real.
     *
     * Es el unico test que fija los Id de ARCA. Si alguien cambia uno, se entera aca y no cuando el
     * contador no puede subir el archivo.
     *
     * 🔴 Las claves del espejo van como ENTEROS y no como strings, y no es un descuido: PHP convierte
     * a entero toda clave de array que sea un string numerico, asi que `'27' => [...]` escrito en el
     * codigo fuente ES `27 => [...]` en memoria. `'10.5'` no es un string numerico entero, asi que
     * ESA si habria quedado como clave string — que es exactamente por que el bug creaba un bucket
     * separado. Escribir el espejo con claves string haria rojo este test por la conversion de PHP y
     * no por un defecto del sistema.
     *
     * @group facturacion
     * @test
     */
    public function la_tabla_de_alicuotas_tiene_los_ids_de_arca_y_los_porcentajes_reales()
    {
        /** @var array Espejo escrito a mano de AfipImportesResolver::$alicuotas. */
        $esperado = [
            27 => ['id' => 6, 'porcentaje' => 27.0],
            21 => ['id' => 5, 'porcentaje' => 21.0],
            10 => ['id' => 4, 'porcentaje' => 10.5],
            5  => ['id' => 8, 'porcentaje' => 5.0],
            2  => ['id' => 9, 'porcentaje' => 2.5],
            0  => ['id' => 3, 'porcentaje' => 0.0],
        ];

        $this->assertSame(
            $esperado,
            AfipImportesResolver::alicuotas(),
            'FUENTE UNICA de la tabla de alicuotas. Ojo con las dos filas que no coinciden con su '.
            'clave: la clave 10 vale 10,5 % (Id 4 de ARCA) y la clave 2 vale 2,5 % (Id 9)'
        );

        /*
         * Y las tres proyecciones que consume el resto del sistema tienen que salir de esa misma
         * tabla. Sin esto, alguien podria dejar la tabla intacta y escribir el mapa de vuelta a mano
         * en otro archivo, que es exactamente el estado del que esta mision viene a sacar al sistema.
         */
        $this->assertSame(4, AfipImportesResolver::id_de_clave('10'), 'Id de ARCA de la clave "10"');
        $this->assertSame(10.5, AfipImportesResolver::porcentaje_de_clave('10'), 'porcentaje real de la clave "10"');
        $this->assertSame('10', AfipImportesResolver::clave_de_id(4), 'clave interna del Id 4 de ARCA');
        $this->assertSame('10', AfipImportesResolver::clave_de_porcentaje('10.5'), 'clave interna del porcentaje 10,5');
        $this->assertSame('10.5', AfipImportesResolver::etiqueta_de_clave('10'), 'etiqueta VISIBLE de la clave "10"');

        $this->assertSame(9, AfipImportesResolver::id_de_clave('2'), 'Id de ARCA de la clave "2"');
        $this->assertSame(2.5, AfipImportesResolver::porcentaje_de_clave('2'), 'porcentaje real de la clave "2"');
        $this->assertSame('2', AfipImportesResolver::clave_de_id(9), 'clave interna del Id 9 de ARCA');
        $this->assertSame('2.5', AfipImportesResolver::etiqueta_de_clave('2'), 'etiqueta VISIBLE de la clave "2"');

        /*
         * 'Exento' y 'No Gravado' NO son numeros: la traduccion por porcentaje tiene que devolver
         * null y dejar que decida quien llama. Si se colaran como 0.0, entrarian al bucket '0' sin
         * que nadie lo haya decidido.
         */
        $this->assertNull(
            AfipImportesResolver::clave_de_porcentaje('Exento'),
            '"Exento" no es un porcentaje: castearlo daria 0.0 y se colaria en el bucket "0" por '.
            'accidente. Que caiga ahi es una decision explicita del calculador, no de la tabla'
        );

        $this->assertNull(
            AfipImportesResolver::clave_de_porcentaje('No Gravado'),
            'idem "No Gravado"'
        );

        $this->assertNull(
            AfipImportesResolver::clave_de_porcentaje('50'),
            '50 % no es una alicuota de ARCA, aunque este en la tabla ivas del sistema'
        );
    }

    // -----------------------------------------------------------------------------------------
    // B7-B11 — la tabla `ivas` es un conjunto ABIERTO: texto sucio y alicuotas desconocidas
    // -----------------------------------------------------------------------------------------

    /**
     * B7 — un `percentage` con un espacio al final ('21 ') sigue cayendo en su bucket.
     *
     * 🔴 Este test es el que fija una REGRESION, no una mejora. Medido con el PHP 7.4.33 de wamp:
     *
     *     is_numeric('21 ')  ->  false          (string) (float) '21 '  ->  '21'
     *
     * O sea que el codigo VIEJO —que armaba la clave con `(string) (float) $percentage`— bucketeaba
     * bien un `'21 '`, y una traduccion que arranque con `is_numeric()` sin normalizar lo dejaria
     * afuera. Y afuera, en la emision, significa cortar la facturacion de un comercio que hoy
     * factura bien.
     *
     * Que un `'21 '` exista no es hipotetico: `ProcessRow.php:3530` y `LocalImportHelper.php:459`
     * crean filas de `ivas` con el texto crudo de una columna de Excel.
     *
     * @group facturacion
     * @test
     */
    public function un_percentage_con_espacio_al_final_cae_igual_en_su_bucket()
    {
        $venta = $this->crear_venta(1210);

        $importes = $this->importes_de(
            $venta,
            [],
            [$this->descripcion_con_percentage_crudo('Linea con percentage sucio', 1210, '21 ')]
        );

        $this->assertSame(
            '21',
            $this->bucket_con_plata($importes['ivas']),
            'un percentage "21 " (con espacio al final, como lo deja la importacion por Excel) tiene '.
            'que caer en el bucket "21". Es la alicuota del 90 % de los comprobantes del sistema'
        );

        $this->assertSame(
            5,
            $importes['ivas']['21']['Id'],
            'Id de alicuota de ARCA para 21 %'
        );

        $this->assertEqualsWithDelta(
            1000.00,
            (float) $importes['ivas']['21']['BaseImp'],
            self::DELTA,
            'base imponible (literal: 1210 / 1,21)'
        );

        $this->assertEqualsWithDelta(
            210.00,
            (float) $importes['ivas']['21']['Importe'],
            self::DELTA,
            'IVA de la linea (literal: 1210 - 1000)'
        );
    }

    /**
     * B8 — un `percentage` con COMA decimal ('10,5') cae en el bucket de 10,5 %.
     *
     * `is_numeric('10,5')` da false en PHP: la coma no es un separador decimal para el lenguaje.
     * Y una planilla de importacion escrita en Argentina la trae con coma la mitad de las veces.
     * Sin normalizar, esa linea no resuelve a ninguna alicuota.
     *
     * @group facturacion
     * @test
     */
    public function un_percentage_con_coma_decimal_cae_en_el_bucket_de_10_5()
    {
        $venta = $this->crear_venta(1105);

        $importes = $this->importes_de(
            $venta,
            [],
            [$this->descripcion_con_percentage_crudo('Linea con coma decimal', 1105, '10,5')]
        );

        $this->assertSame(
            '10',
            $this->bucket_con_plata($importes['ivas']),
            'un percentage "10,5" tiene que caer en el bucket "10", que es la clave interna de '.
            '10,5 % (no es el porcentaje: "10" vale 10,5)'
        );

        $this->assertSame(
            4,
            $importes['ivas']['10']['Id'],
            'Id de alicuota de ARCA para 10,5 %'
        );

        $this->assertEqualsWithDelta(
            105.00,
            (float) $importes['ivas']['10']['Importe'],
            self::DELTA,
            'IVA de la linea, calculado con el porcentaje REAL 10,5 (literal: 1105 - 1105/1,105)'
        );
    }

    /**
     * B9 — 'Exento ' con un espacio al final sigue cayendo en el bucket '0'.
     *
     * La comparacion con 'Exento' es `===` estricta a proposito (no se cae al cast, que es lo que
     * ocultaba el ruteo), y por eso mismo un espacio al final la esquivaba en silencio: la linea se
     * iba a buscar una alicuota numerica, no la encontraba, y cortaba la emision.
     *
     * @group facturacion
     * @test
     */
    public function exento_con_espacio_al_final_cae_igual_en_el_bucket_cero()
    {
        $venta = $this->crear_venta(1000);

        $importes = $this->importes_de(
            $venta,
            [],
            [$this->descripcion_con_percentage_crudo('Linea exenta con espacio', 1000, 'Exento ')]
        );

        $this->assertSame(
            '0',
            $this->bucket_con_plata($importes['ivas']),
            '"Exento " con espacio al final tiene que rutear igual que "Exento": al bucket "0". Que '.
            'caiga ahi (y no en ImpOpEx) es la decision de caracterizacion que fija B4'
        );

        $this->assertSame(
            3,
            $importes['ivas']['0']['Id'],
            'Id de alicuota de ARCA para 0 %'
        );

        $this->assertEqualsWithDelta(
            0.00,
            (float) $importes['ivas']['0']['Importe'],
            self::DELTA,
            'una linea exenta no genera IVA'
        );
    }

    /**
     * B10 — MODO LECTURA: una alicuota que ARCA no reconoce NO rompe el reporte.
     *
     * 🔴 Es el otro lado del guardia de B3, y los dos son necesarios. `getImportes(true)` lo llaman
     * SOLO los caminos de lectura: `AfipImportesResolver::resolve()` —que es el funnel del Libro
     * IVA Ventas, del TXT de comprobantes y del TXT de alicuotas— y los dos PDF de ticket. Ninguno
     * de ellos tiene `try/catch` en ninguna parte, asi que si el calculo tirara, UNA linea vieja de
     * UN comprobante ya autorizado reventaria con 500 el Libro IVA Ventas del MES ENTERO. Romper la
     * impresion no le devuelve el renglon a nadie: es el mismo criterio que ya aplica
     * `resolve_from_snapshot()` ante un Id de ARCA que no conoce.
     *
     * Lo que SI se acepta a proposito: la plata suma a `gravado` y a `iva` pero no genera renglon
     * de alicuota, asi que `suma(BaseImp)` no cierra contra `gravado`. Ese descuadre es
     * PREEXISTENTE del camino de los ARTICULOS —`calculate_from_sale_items()` pide `getImporteIva()`
     * solo para las seis alicuotas conocidas, asi que un articulo al 50 % ya se comporta asi—; esta
     * mision alinea la descripcion libre con el articulo en vez de estrenar una tercera conducta.
     *
     * @group facturacion
     * @test
     */
    public function en_modo_lectura_una_alicuota_desconocida_no_rompe_el_reporte()
    {
        $venta = $this->crear_venta(1500);

        $importes = $this->importes_de(
            $venta,
            [],
            [$this->descripcion_con_percentage_crudo('Servicio raro al 50', 1500, '50')],
            true
        );

        $this->assertIsArray(
            $importes,
            'en modo lectura el calculo tiene que TERMINAR: el Libro IVA Ventas del mes entero no '.
            'puede reventar por una linea vieja de un comprobante ya autorizado'
        );

        $this->assertNull(
            $this->bucket_con_plata($importes['ivas']),
            'la alicuota desconocida NO genera renglon de IVA: no se le puede inventar un Id'
        );

        foreach ($importes['ivas'] as $key => $bucket) {
            $this->assertNotSame(
                0,
                $bucket['Id'],
                'EL DEFECTO EN UNA LINEA: tolerar la alicuota desconocida NO puede volver por la '.
                'ventana como un bucket "'.$key.'" con Id 0. Ese cero viaja tal cual al payload de '.
                'ARCA y sale como codigo de alicuota 0000 en el TXT del contador'
            );
        }

        /*
         * Y la plata no desaparece: sigue en gravado y en iva, exactamente como ya pasa hoy con un
         * ARTICULO a una alicuota desconocida. Si esto fallara, el reporte estaria subdeclarando.
         */
        $this->assertEqualsWithDelta(
            1000.00,
            (float) $importes['gravado'],
            self::DELTA,
            'la base imponible de la linea sigue sumando al gravado (literal: 1500 / 1,5)'
        );

        $this->assertEqualsWithDelta(
            500.00,
            (float) $importes['iva'],
            self::DELTA,
            'el IVA de la linea sigue sumando al total de IVA (literal: 1500 - 1000)'
        );

        $this->assertEqualsWithDelta(
            1500.00,
            (float) $importes['total'],
            self::DELTA,
            'el total del comprobante no cambia: tolerar la alicuota mueve el DESGLOSE, nunca la plata'
        );
    }

    /**
     * B11 — MODO EMISION (el default): la MISMA linea sigue cortando.
     *
     * 🔴 Es el test que garantiza que la tolerancia de B10 no se filtro a la emision. Es la misma
     * descripcion, el mismo importe y la misma alicuota que B10; lo unico que cambia es que
     * `getImportes()` se llama SIN parametro, que es como lo llaman `AfipWsfeHelper` y
     * `AfipNotaCreditoHelper`, los dos unicos que le hablan al webservice de ARCA.
     *
     * Facturar con una alicuota inventada deja el desglose fiscal del comercio mal para siempre y
     * nadie se entera. Leer un comprobante ya autorizado con una alicuota rara, no.
     *
     * @group facturacion
     * @test
     */
    public function en_modo_emision_la_misma_alicuota_desconocida_sigue_cortando()
    {
        $venta = $this->crear_venta(1500);

        /** @var \InvalidArgumentException|null $excepcion La que tiene que tirar la emision. */
        $excepcion = null;
        /** @var array|null $importes Lo que devolvio, si es que devolvio algo. */
        $importes = null;

        try {
            $importes = $this->importes_de(
                $venta,
                [],
                [$this->descripcion_con_percentage_crudo('Servicio raro al 50', 1500, '50')]
            );
        } catch (InvalidArgumentException $e) {
            $excepcion = $e;
        }

        $this->assertNull(
            $importes,
            'EL DEFECTO EN UNA LINEA: si la emision devuelve importes con una alicuota desconocida, '.
            'el modo tolerante se filtro al camino que le habla a ARCA. El default de '.
            'AfipHelper::getImportes() tiene que seguir siendo false'
        );

        $this->assertNotNull(
            $excepcion,
            'la emision tiene que CORTAR ante una alicuota que ARCA no reconoce'
        );

        $this->assertStringContainsString(
            '50',
            $excepcion->getMessage(),
            'el mensaje tiene que nombrar la alicuota que no se pudo resolver'
        );

        $this->assertStringContainsString(
            'Servicio raro al 50',
            $excepcion->getMessage(),
            'y tiene que nombrar la linea, que es lo unico que le sirve a quien tiene que arreglarla'
        );
    }
}
