<?php

namespace Tests\Import;

use App\Models\Article;

/**
 * Filas repetidas DENTRO del archivo cuando ademas se permiten codigos de proveedor
 * repetidos contra la BASE (mision fix-ultima-gana-con-actualizar-todos, 2/9/2026).
 *
 * Es la celda de la matriz que nunca habia tenido test, y era justo donde vivia el defecto
 * que encontro la exploracion de importacion de Excel de ese dia:
 *
 *   El paso 3 del modal de importacion con IA hace DOS preguntas distintas.
 *     - "¿Que representan esas filas repetidas [del archivo]?"  -> filas_repetidas_del_archivo
 *     - "Si el codigo ya existe en el sistema, ¿que hago?"      -> permitir_provider_code_repetido
 *   Elegir "Es el mismo producto, cargado mas de una vez" (ultima_gana) junto con
 *   "Actualizar todos los articulos que tengan ese codigo" (permitir = 1) NO fusionaba las
 *   filas: creaba una por fila, al reves de lo que la pantalla promete. ProcessRow::esta_repetido()
 *   apagaba su escalon provider_code con el flag de la BASE, y la deteccion intra-archivo
 *   nunca corria (matching_counts_json medido: creado_nuevo=2, merge_fila_repetida=0).
 *
 * Lo que custodia esta clase es que las CUATRO combinaciones den bien, no solo la que fallaba:
 *
 *   | permitir | filas_repetidas_del_archivo | resultado esperado                    |
 *   |----------|-----------------------------|---------------------------------------|
 *   |    1     | ultima_gana                 | UN articulo, con los datos de la ultima |
 *   |    1     | productos_distintos         | UNO POR FILA                            |
 *   |    0     | ultima_gana                 | UN articulo (ya andaba, RepetidosEnElArchivoTest) |
 *   |    0     | productos_distintos         | UNO POR FILA (ya andaba)                |
 *
 * 🔴 Los tests con 18_pc_repetido_mismo_nombre.xlsx (nombres IGUALES) no son un caso de borde
 * decorativo: son la red que atrapa la correccion tentadora del defecto. Si alguien "arregla"
 * esto condicionando el escalon 4 por filas_repetidas_del_archivo === 'ultima_gana' en vez de
 * sacarle la condicion, con 'productos_distintos' la fila cae al escalon name, procesar() la
 * DESCARTA y queda un solo articulo -- lo contrario de lo elegido. Con 07 (nombres distintos)
 * esa variante equivocada pasa igual, por suerte del fixture.
 */
class RepetidosConPermitirRepetidoTest extends ImportTestCase
{
    /** Fixture con tres filas de PC-R-Z, mismo provider_code y nombres DISTINTOS. */
    const ARCHIVO_NOMBRES_DISTINTOS = '07_repetidos_en_el_archivo.xlsx';

    /** Fixture con tres filas de PC-MN-1, mismo provider_code y el MISMO nombre. */
    const ARCHIVO_MISMO_NOMBRE = '18_pc_repetido_mismo_nombre.xlsx';

    /**
     * Articulos del tenant que tienen ese provider_code.
     *
     * @param  string $provider_code
     * @return \Illuminate\Support\Collection
     */
    protected function articulos_con_provider_code($provider_code)
    {
        return Article::where('user_id', $this->tenant->id)
                        ->where('provider_code', $provider_code)
                        ->orderBy('id')
                        ->get();
    }

    /* ------------------------------------------------------------------
     * permitir = 1 + ultima_gana  (la celda del defecto)
     * ------------------------------------------------------------------ */

    /**
     * EL TEST DEL DEFECTO. Tres filas con el mismo provider_code nuevo y
     * permitir_provider_code_repetido = 1: con 'ultima_gana' tiene que quedar UN
     * articulo, con los datos de la ultima fila.
     *
     * Antes del fix del 2/9/2026 quedaban TRES.
     *
     * @return void
     */
    public function test_ultima_gana_con_permitir_repetido_deja_un_solo_articulo()
    {
        $this->importar(self::ARCHIVO_NOMBRES_DISTINTOS, [
            'provider_id'                     => $this->providers['A']->id,
            'permitir_provider_code_repetido' => true,
            'filas_repetidas_del_archivo'     => 'ultima_gana',
        ]);

        $articulos = $this->articulos_con_provider_code('PC-R-Z');

        $this->assertCount(
            1,
            $articulos,
            'Con ultima_gana, tres filas del mismo codigo tienen que dejar UN articulo, '
            . 'aunque se permitan codigos repetidos contra la base.'
        );

        $articulo = $articulos->first();

        $this->assertSame('Solo pc v3', $articulo->name, 'Tiene que ganar la ULTIMA fila.');
        $this->assertDecimal(900, $articulo->cost);
        $this->assertDecimal(90, $articulo->stock);
    }

    /**
     * La huella del defecto en los contadores.
     *
     * 🔴 El numero tiene que ser EXACTO, y este test es la razon: la primera version asertaba
     * `>= 2` con el argumento de que "antes del fix el bucket daba 0", y eso era falso --
     * pasaba igual con el codigo viejo. El bucket es GLOBAL y este fixture trae otras dos
     * repeticiones que no dependen del flag: F2/F3 comparten bar_code y F4/F5 comparten sku,
     * y los escalones 2 y 3 de esta_repetido() nunca miraron permitir_provider_code_repetido.
     * O sea que el bucket ya valia 2 con el defecto puesto, y el unico test que llevaba el
     * nombre del defecto era el unico que no lo detectaba. Lo encontro el chequeo
     * independiente de la mision.
     *
     * De donde salen los 4 merges con el fix aplicado:
     *   F2 <- F3    (bar_code 7799100)      -- no depende del flag
     *   F4 <- F5    (sku SKU-R-1)           -- no depende del flag
     *   F8 <- F9    (provider_code PC-R-Z)  -- estos dos son los que el defecto perdia
     *   F9 <- F10   (provider_code PC-R-Z)
     *
     * @return void
     */
    public function test_ultima_gana_con_permitir_repetido_cuenta_los_merges()
    {
        $import = $this->importar(self::ARCHIVO_NOMBRES_DISTINTOS, [
            'provider_id'                     => $this->providers['A']->id,
            'permitir_provider_code_repetido' => true,
            'filas_repetidas_del_archivo'     => 'ultima_gana',
        ]);

        $conteo = $this->conteo($import);

        $this->assertSame(
            4,
            (int) $conteo['merge_fila_repetida'],
            'Con el defecto puesto este bucket valia 2 (solo bar_code y sku): las dos repeticiones '
            . 'de PC-R-Z no se contaban porque la deteccion no corria.'
        );
    }

    /* ------------------------------------------------------------------
     * permitir = 1 + productos_distintos
     * ------------------------------------------------------------------ */

    /**
     * Con 'productos_distintos' y permitir = 1, cada fila crea su articulo.
     *
     * @return void
     */
    public function test_productos_distintos_con_permitir_repetido_deja_uno_por_fila()
    {
        $this->importar(self::ARCHIVO_NOMBRES_DISTINTOS, [
            'provider_id'                     => $this->providers['A']->id,
            'permitir_provider_code_repetido' => true,
            'filas_repetidas_del_archivo'     => 'productos_distintos',
        ]);

        $this->assertCount(
            3,
            $this->articulos_con_provider_code('PC-R-Z'),
            "Con 'productos_distintos' cada fila repetida tiene que crear su propio articulo."
        );
    }

    /* ------------------------------------------------------------------
     * Nombres IGUALES: la red contra la correccion tentadora
     * ------------------------------------------------------------------ */

    /**
     * Tres filas con el mismo provider_code Y el mismo nombre, con permitir = 1 y
     * 'ultima_gana': un articulo, con el costo de la ultima (300).
     *
     * Antes del fix quedaba uno con el costo de la PRIMERA (100): la deteccion salia por
     * el escalon name, y ese escalon no mergea -- procesar() descarta la fila y gana la
     * primera. O sea que el defecto no era solo "crea de mas": tambien elegia mal el
     * ganador cuando los nombres coincidian.
     *
     * @return void
     */
    public function test_mismo_nombre_y_mismo_codigo_gana_la_ultima_fila()
    {
        $this->importar(self::ARCHIVO_MISMO_NOMBRE, [
            'provider_id'                     => $this->providers['A']->id,
            'permitir_provider_code_repetido' => true,
            'filas_repetidas_del_archivo'     => 'ultima_gana',
        ]);

        $articulos = $this->articulos_con_provider_code('PC-MN-1');

        $this->assertCount(1, $articulos, 'Con ultima_gana tiene que quedar un solo articulo.');
        $this->assertDecimal(300, $articulos->first()->cost, 'Tiene que ganar la ULTIMA fila, no la primera.');
    }

    /**
     * 🔴 EL TEST QUE ATRAPA LA CORRECCION TENTADORA.
     *
     * Con 'productos_distintos', permitir = 1 y los tres nombres iguales, tienen que
     * quedar TRES articulos. Si alguien condiciona el escalon provider_code de
     * esta_repetido() por filas_repetidas_del_archivo (en vez de dejarlo siempre
     * prendido), la fila cae al escalon name, procesar() la descarta y queda UNO.
     *
     * @return void
     */
    public function test_productos_distintos_con_nombres_iguales_no_fusiona()
    {
        $this->importar(self::ARCHIVO_MISMO_NOMBRE, [
            'provider_id'                     => $this->providers['A']->id,
            'permitir_provider_code_repetido' => true,
            'filas_repetidas_del_archivo'     => 'productos_distintos',
        ]);

        $this->assertCount(
            3,
            $this->articulos_con_provider_code('PC-MN-1'),
            "Con 'productos_distintos' cada fila es un producto, aunque compartan nombre Y codigo."
        );
    }

    /**
     * La misma red, con permitir = 0: tambien tienen que quedar TRES.
     *
     * Es la celda que hoy ya funciona y que la correccion tentadora rompería sin que
     * ningun test existente lo denuncie (las filas repetidas de 07 tienen nombres
     * distintos, asi que ahi la variante equivocada queda verde).
     *
     * @return void
     */
    public function test_productos_distintos_sin_permitir_y_nombres_iguales_no_fusiona()
    {
        $this->importar(self::ARCHIVO_MISMO_NOMBRE, [
            'provider_id'                     => $this->providers['A']->id,
            'permitir_provider_code_repetido' => false,
            'filas_repetidas_del_archivo'     => 'productos_distintos',
        ]);

        $this->assertCount(
            3,
            $this->articulos_con_provider_code('PC-MN-1'),
            "Con 'productos_distintos' y permitir = 0 tambien tiene que crear uno por fila."
        );
    }

    /**
     * Dos filas con el MISMO nombre, pero solo la segunda trae provider_code: son DOS
     * articulos.
     *
     * Este caso CAMBIO con el fix del 2/9/2026 y se fija acá para que no vuelva a cambiar
     * sin que nadie se entere. Antes, con permitir = 1, la fila 2 caia al escalon name, que
     * en esa configuracion marcaba "repetido por seguridad" cuando faltaba un provider_code
     * con el cual contrastar -- y procesar() la descartaba: quedaba UN articulo con los datos
     * de la primera.
     *
     * El comportamiento nuevo es el que la configuracion permitir = 0 ya daba (el escalon 4
     * corta con `!empty($art['provider_code'])` falso), asi que el fix alinea las dos en vez
     * de crear una inconsistencia. Y es defendible de por si: una fila trae codigo y la otra
     * no, no hay evidencia de que sean el mismo producto. Pero es un articulo mas en el
     * catalogo del cliente, asi que va con test.
     *
     * @return void
     */
    public function test_mismo_nombre_pero_solo_una_fila_con_codigo_no_fusiona()
    {
        $this->importar('20_mismo_nombre_solo_una_con_codigo.xlsx', [
            'provider_id'                     => $this->providers['A']->id,
            'permitir_provider_code_repetido' => true,
            'filas_repetidas_del_archivo'     => 'ultima_gana',
        ]);

        $con_ese_nombre = Article::where('user_id', $this->tenant->id)
                                    ->where('name', 'Mismo nombre sin codigo')
                                    ->get();

        $this->assertCount(
            2,
            $con_ese_nombre,
            'Una fila con codigo y otra sin codigo son dos productos distintos, aunque compartan el nombre.'
        );
    }

    /* ------------------------------------------------------------------
     * Repetido en el archivo Y repetido en la BASE
     * ------------------------------------------------------------------ */

    /**
     * La celda que ningun test cubria, y que es la razon de ser de "Actualizar todos los
     * articulos que tengan ese codigo": el codigo del Excel matchea DOS articulos que ya
     * existen (A3 y A4, ambos con PC-DUP) y ademas viene repetido en el archivo.
     *
     * Las dos opciones elegidas prometen, juntas: "actualiza TODOS los que tengan ese
     * codigo" + "queda la informacion de la ULTIMA aparicion". O sea que A3 y A4 tienen
     * que quedar los dos con el costo de la segunda fila (1200).
     *
     * @return void
     */
    public function test_repetido_en_archivo_y_en_base_actualiza_los_dos_con_la_ultima_fila()
    {
        $this->importar('19_pc_repetido_en_archivo_y_base.xlsx', [
            'provider_id'                     => $this->providers['A']->id,
            'permitir_provider_code_repetido' => true,
            'filas_repetidas_del_archivo'     => 'ultima_gana',
        ]);

        $this->assertDecimal(
            1200,
            $this->recargar('A3')->cost,
            'A3 tiene que quedar con el costo de la ULTIMA fila: se eligio "actualizar todos" + "ultima gana".'
        );

        $this->assertDecimal(
            1200,
            $this->recargar('A4')->cost,
            'A4 tambien: "actualizar todos" promete que ningun articulo con ese codigo queda afuera.'
        );
    }

    /* ------------------------------------------------------------------
     * Filas repetidas que traen propiedades de variante
     * ------------------------------------------------------------------ */

    /**
     * Prende la extension de variantes para el tenant del fixture (mismo patron que
     * ListasDePrecioPorDefectoTest::prender_ventas_en_dolares()).
     *
     * @return void
     */
    protected function prender_article_variants()
    {
        $extencion = \App\Models\ExtencionEmpresa::where('slug', 'article_variants')->first();

        if (is_null($extencion)) {
            $extencion = new \App\Models\ExtencionEmpresa();
            $extencion->name = 'Variantes de articulo';
            $extencion->slug = 'article_variants';
            $extencion->save();
        }

        $this->tenant->extencions()->syncWithoutDetaching($extencion->id);
        $this->tenant->load('extencions');
    }

    /**
     * Con una columna de propiedad mapeada (el caso de una lista de indumentaria: un codigo
     * de catalogo, una fila por color) y la extension `article_variants` prendida, las filas
     * repetidas se absorben como VARIANTES del articulo de la primera. Un articulo, dos
     * variantes.
     *
     * Ese camino esta ANTES del merge en procesar() (~:983) y es el diseno original del
     * sistema: si dos filas son el mismo producto y traen propiedades distintas, la
     * representacion correcta es un articulo con variantes, no dos articulos ni un merge que
     * pise el color de la primera con el de la segunda.
     *
     * Lo que cambio el 2/9/2026 es que ese camino ahora se ALCANZA tambien con
     * permitir_provider_code_repetido = 1: antes el escalon 4 apagado hacia que las filas ni
     * siquiera se detectaran como repetidas y cada una creaba su articulo. Con permitir = 0 ya
     * pasaba desde siempre. Lo encontro el revisor de merge de la mision.
     *
     * ⚠️ Dos cosas que hay que sembrar a mano y que explican por que ningun otro test de esta
     * suite ejercita variantes: `article_property_types` es una tabla GLOBAL que viene vacia en
     * la base de testing, y la persistencia esta detras de la extension `article_variants`
     * (ActualizarBBDD::guardar_variantes_desde_cache_simple(), ~:1865).
     *
     * @return void
     */
    public function test_filas_repetidas_con_propiedades_quedan_como_variantes()
    {
        $this->prender_article_variants();

        $tipo = \App\Models\ArticlePropertyType::firstOrCreate(['name' => 'Color']);

        $this->importar('21_pc_repetido_con_variantes.xlsx', [
            'provider_id'                     => $this->providers['A']->id,
            'permitir_provider_code_repetido' => true,
            'filas_repetidas_del_archivo'     => 'ultima_gana',
            /* La columna 9 del fixture. La clave del mapeo es el nombre del tipo en minusculas. */
            'prop_color'                      => 9,
        ]);

        $articulos = $this->articulos_con_provider_code('PC-VAR');

        $this->assertCount(
            1,
            $articulos,
            'Las dos filas son el mismo producto: tiene que quedar UN articulo.'
        );

        $variantes = \App\Models\ArticleVariant::where('article_id', $articulos->first()->id)->get();

        /*
         * UNA variante, no dos, y esto es el comportamiento real medido -- no una expectativa
         * rebajada para que el test pase. La fila 1 no pasa por el camino de variantes (todavia
         * no hay ninguna repeticion que detectar): crea el articulo base con SUS datos, color
         * incluido como valor de la fila. Solo las filas repetidas 2..N se convierten en
         * variantes. O sea que el color de la primera fila no queda como una variante mas.
         *
         * Es discutible como diseno -lo natural seria que las N filas fueran N variantes de un
         * articulo padre- pero es lo que el sistema hace desde que existe el camino, y cambiarlo
         * es una decision de producto, no parte de este fix. Se fija acá para que quede medido:
         * hasta hoy nadie lo habia ejercitado (`grep variante_de_fila_previa tests/Import/` no
         * devolvia nada).
         */
        $this->assertCount(
            1,
            $variantes,
            'La fila repetida queda como variante del articulo de la primera; la primera es el articulo base.'
        );

        $tipo->delete();
    }

    /**
     * 🔴 DEFECTO ABIERTO, fijado a proposito: la MISMA planilla, en una cuenta SIN la extension
     * `article_variants`, pierde la segunda fila.
     *
     * Medido el 2/9/2026: queda UN articulo y CERO variantes. La fila 2 se detecta como
     * repetida, procesar() la manda al camino de variantes (build_variant_payload() devuelve
     * payload porque la columna esta mapeada), attach_variant_to_existing_article() le cuelga
     * el payload a la entrada en cola... y ActualizarBBDD nunca lo persiste, porque
     * guardar_variantes_desde_cache_simple() esta entera detras de la extension. La fila no se
     * mergea (el camino de variantes retorna antes) y no se guarda: desaparece, contada en el
     * bucket `variante_de_fila_previa`, que ni siquiera tiene tarjeta en el modal de resultado.
     *
     * Por que NO se arregla en esta mision, y por que no bloquea:
     *   - Es PREEXISTENTE: con permitir_provider_code_repetido = 0 pasa exactamente igual
     *     desde siempre. El fix del 2/9 solo lo hace alcanzable tambien con permitir = 1.
     *   - Hoy es INALCANZABLE desde la interfaz: el select de mapeo del modal de importacion
     *     con IA no ofrece propiedades de variante (`system_property_options` no las incluye),
     *     y el import clasico de articulos ya no se monta en ninguna pantalla. Queda expuesto
     *     solo para un cliente de API directo.
     *   - Arreglarlo bien es decidir que hace el import con una propiedad de variante cuando la
     *     cuenta no tiene variantes: ignorarla y mergear normal, o avisar. Es una decision de
     *     producto, no un ajuste.
     *
     * El dia que se arregle, este test se pone rojo y hay que actualizarlo.
     *
     * @return void
     */
    public function test_sin_la_extension_de_variantes_la_fila_repetida_se_pierde()
    {
        $tipo = \App\Models\ArticlePropertyType::firstOrCreate(['name' => 'Color']);

        $this->importar('21_pc_repetido_con_variantes.xlsx', [
            'provider_id'                     => $this->providers['A']->id,
            'permitir_provider_code_repetido' => true,
            'filas_repetidas_del_archivo'     => 'ultima_gana',
            'prop_color'                      => 9,
        ]);

        $articulos = $this->articulos_con_provider_code('PC-VAR');

        $this->assertCount(1, $articulos, 'Queda un solo articulo, el de la primera fila.');

        $this->assertCount(
            0,
            \App\Models\ArticleVariant::where('article_id', $articulos->first()->id)->get(),
            'DEFECTO ABIERTO fijado: sin la extension, la segunda fila no se guarda ni como '
            . 'variante ni como merge. Cuando se corrija, este numero cambia.'
        );

        $this->assertDecimal(
            100,
            $articulos->first()->cost,
            'Y el articulo se queda con el costo de la PRIMERA fila: la segunda se perdio.'
        );

        $tipo->delete();
    }

    /**
     * La contrapartida: con 'productos_distintos' el camino de variantes NO se toma (procesar()
     * lo saltea junto con todo el bloque de repeticion) y cada fila crea su articulo.
     *
     * Es lo que le da sentido a la eleccion del usuario: la misma planilla da un articulo con
     * variantes o dos articulos segun lo que haya respondido.
     *
     * @return void
     */
    public function test_productos_distintos_no_convierte_las_filas_en_variantes()
    {
        $tipo = \App\Models\ArticlePropertyType::firstOrCreate(['name' => 'Color']);

        $this->importar('21_pc_repetido_con_variantes.xlsx', [
            'provider_id'                     => $this->providers['A']->id,
            'permitir_provider_code_repetido' => true,
            'filas_repetidas_del_archivo'     => 'productos_distintos',
            'prop_color'                      => 9,
        ]);

        $this->assertCount(
            2,
            $this->articulos_con_provider_code('PC-VAR'),
            "Con 'productos_distintos' cada fila es su propio articulo, no una variante."
        );

        $tipo->delete();
    }

    /* ------------------------------------------------------------------
     * Sin proveedor elegido (riesgo R1 del plan)
     * ------------------------------------------------------------------ */

    /**
     * Importar SIN proveedor elegido, con 'ultima_gana': las filas repetidas se fusionan
     * igual (UN articulo), que es lo que este fix vino a conseguir.
     *
     * 🔴 PERO el articulo queda con los datos de la PRIMERA fila (costo 700), no de la
     * ultima. Es un DEFECTO SEPARADO, preexistente y fuera del alcance de esta mision, y
     * este test lo FIJA a proposito para que el dia que se corrija se ponga rojo.
     *
     * El mecanismo, medido el 2/9/2026: merge_fila_duplicada() resuelve el articulo destino
     * por $article_index['provider_codes'][$provider_id][$valor] (ProcessRow ~:2350), y
     * ArticleIndexCache::add() (~:1245) solo indexa el provider_code cuando hay provider_id.
     * Sin proveedor elegido el articulo pendiente nunca entra al indice, el merge no encuentra
     * destino, loguea un WARNING y retorna sin escribir -- la fila se cuenta como merge y se
     * pierde. Arreglarlo es tocar el indice que gobierna TODO el matching contra la base, asi
     * que va en mision propia.
     *
     * Que NO es de este fix lo prueba la segunda mitad del test: con permitir = 0, que es el
     * camino que ya existia antes del 2/9/2026 y que este cambio no toca, pasa exactamente lo
     * mismo. Antes del fix esta configuracion (permitir = 1) ni siquiera fusionaba: dejaba
     * tres articulos, o sea que el resultado de hoy es estrictamente mejor.
     *
     * @return void
     */
    public function test_sin_proveedor_fusiona_pero_gana_la_primera_fila()
    {
        $this->importar(self::ARCHIVO_NOMBRES_DISTINTOS, [
            'provider_id'                     => null,
            'permitir_provider_code_repetido' => true,
            'filas_repetidas_del_archivo'     => 'ultima_gana',
        ]);

        $articulos = $this->articulos_con_provider_code('PC-R-Z');

        $this->assertCount(
            1,
            $articulos,
            'Sin proveedor elegido tambien tiene que fusionar: un solo articulo (antes del fix quedaban tres).'
        );

        $this->assertDecimal(
            700,
            $articulos->first()->cost,
            'DEFECTO ABIERTO fijado a proposito: sin proveedor el merge no encuentra destino en el indice '
            . 'y gana la PRIMERA fila. Cuando se corrija, este numero pasa a 900 (la ultima) y hay que '
            . 'actualizar este test.'
        );
    }

    /**
     * El defecto de arriba no lo introdujo este fix: con permitir = 0 -- el camino que ya
     * existia y que este cambio no toca -- el resultado sin proveedor es identico.
     *
     * Este test es la evidencia que sostiene esa afirmacion en el informe, para que nadie
     * tenga que volver a medirlo a mano.
     *
     * @return void
     */
    public function test_sin_proveedor_el_ganador_no_depende_de_permitir_repetido()
    {
        $this->importar(self::ARCHIVO_NOMBRES_DISTINTOS, [
            'provider_id'                     => null,
            'permitir_provider_code_repetido' => false,
            'filas_repetidas_del_archivo'     => 'ultima_gana',
        ]);

        $articulos = $this->articulos_con_provider_code('PC-R-Z');

        $this->assertCount(1, $articulos, 'Con permitir = 0 tambien fusiona en un solo articulo.');
        $this->assertDecimal(
            700,
            $articulos->first()->cost,
            'Con permitir = 0 gana la primera igual que con permitir = 1: el defecto del indice sin '
            . 'proveedor es anterior a este fix y no depende de esta configuracion.'
        );
    }
}
