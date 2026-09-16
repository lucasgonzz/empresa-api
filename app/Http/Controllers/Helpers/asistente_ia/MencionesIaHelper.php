<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\Article;
use App\Models\Client;

/**
 * Las menciones de una respuesta del asistente: qué clientes y qué artículos nombró, y con qué
 * literal exacto los nombró (misión agente-ia-mano-derecha, §1 del contrato, 16/9/2026).
 *
 * Con eso la SPA pinta ese tramo del texto como clickeable —abre la cuenta corriente del cliente o
 * la ficha del artículo— sin que el mensaje cambie una coma: las menciones ANOTAN, no reemplazan.
 *
 * 🔴 NO SE LE PIDEN AL MODELO. Pedirle que escriba marcas gasta vueltas del techo de tiempo del
 * loop y se equivoca (regla 5 del contrato). Se arman acá, en el servidor, cruzando dos cosas que
 * el loop ya tiene: lo que devolvieron las tools de ESA respuesta (`candidatos_de_tool()`) y el
 * texto final que escribió el modelo (`cruzar()`).
 *
 * 🔴 Y EL CRITERIO ES LA PRECISIÓN, NO LA COBERTURA. Una mención equivocada manda al dueño a la
 * ficha de OTRO artículo o a la cuenta corriente de OTRO cliente, que es peor que no marcar nada:
 * un nombre sin marcar no molesta a nadie. Por eso `cruzar()` descarta ante la menor duda —nombre
 * ambiguo, literal demasiado corto, ocurrencia pegada a otra palabra, id que no es del dueño— y por
 * eso NINGUNA mención sale sin pasar antes por la base: lo que viaja está confirmado contra
 * `articles`/`clients` del dueño, no contra lo que dijo una tool.
 */
class MencionesIaHelper
{
    /** Los dos únicos tipos que la SPA sabe dibujar (contrato §1). Un tipo nuevo se agrega acá. */
    const TIPO_CLIENTE = 'cliente';

    const TIPO_ARTICULO = 'articulo';

    /**
     * Largo mínimo del literal para que se marque.
     *
     * 🔴 No es cosmético: un cliente llamado "JR" o un artículo "A4" caen adentro de media docena
     * de palabras de cualquier mensaje, y cada una de esas sería un link al lugar equivocado. Con 3
     * el riesgo baja a casi cero y lo que se pierde son nombres que igual nadie escribe sueltos.
     *
     * @var int
     */
    const MINIMO_CARACTERES = 3;

    /**
     * Rellenos que las consultas ponen cuando el registro real ya no está (por ejemplo
     * ConsultasSistemaIaHelper::quien_compro_un_articulo(), que manda 'cliente borrado' con el
     * `cliente_id` de la venta). Son nombres que NO identifican a nadie.
     *
     * @var array<int, string>
     */
    const RELLENOS = ['cliente borrado', 'proveedor borrado', 'articulo borrado', 'artículo borrado'];

    /**
     * Pares (clave del id, clave del nombre) que identifican sin ambigüedad a un cliente o a un
     * artículo adentro de CUALQUIER fila devuelta por una tool, a cualquier profundidad.
     *
     * 🔴 Las claves están tipadas a propósito (`cliente_id` + `cliente`, `articulo_id` +
     * `articulo`): un `id` pelado no dice de qué es, y adivinarlo por el contexto es exactamente
     * cómo se fabrica una mención equivocada. Las dos únicas tools que devuelven el id pelado están
     * declaradas una por una en pares_por_tool().
     *
     * @return array<int, array<string, string>>
     */
    public static function pares_etiquetados(): array
    {
        return [
            ['tipo' => self::TIPO_CLIENTE,  'id' => 'cliente_id',  'texto' => 'cliente'],
            ['tipo' => self::TIPO_ARTICULO, 'id' => 'articulo_id', 'texto' => 'articulo'],
        ];
    }

    /**
     * Las tools cuyas filas traen el id en la clave `id`, sin tipar. Son las dos consultas
     * "listame clientes" / "listame artículos", donde toda la lista es de un solo tipo.
     *
     * @return array<string, array<string, string>>
     */
    public static function pares_por_tool(): array
    {
        return [
            'consultar_stock_de_articulos' => ['tipo' => self::TIPO_ARTICULO, 'id' => 'id', 'texto' => 'nombre'],
            'consultar_clientes'           => ['tipo' => self::TIPO_CLIENTE,  'id' => 'id', 'texto' => 'cliente'],
        ];
    }

    /**
     * Claves que traen el NOMBRE de un cliente o de un artículo en filas que no traen su id.
     *
     * 🔴 Existen porque media docena de consultas devuelven el nombre y no el id —y son
     * justamente las más usadas: `clientes_con_saldo_pendiente` ("¿quién me debe?"),
     * `mas_vendidos` ("¿qué es lo que más vendo?"), `ofertas_activas`, `precios_de_proveedores`,
     * `interesados_en_un_articulo`—. Sin esto, el ejemplo del propio contrato ("El cliente que más
     * te debe es Ferreteria Tucumana") no tendría mención.
     *
     * Estos candidatos salen con `id` 0 y se resuelven contra la base por nombre EXACTO Y ÚNICO
     * del dueño (ver resolver_por_nombre()): si hay dos clientes que se llaman igual, no se marca.
     * Es un camino más exigente que el del id, no uno más laxo.
     *
     * `nombre` va solo para la tool que lo usa como nombre de artículo: en otras respuestas esa
     * misma clave es el nombre de una sucursal o de un proveedor.
     *
     * @param  string  $tool_name
     * @return array<string, string>  clave del nombre => tipo
     */
    public static function claves_de_nombre($tool_name): array
    {
        $claves = [
            'cliente'  => self::TIPO_CLIENTE,
            'articulo' => self::TIPO_ARTICULO,
        ];

        if ((string) $tool_name === 'consultar_articulos_mas_vendidos') {
            $claves['nombre'] = self::TIPO_ARTICULO;
        }

        return $claves;
    }

    /**
     * Entidades de la consulta genérica (`consultar_datos`) que son un cliente o un artículo. El
     * resto del catálogo —proveedores, ventas, cheques, cajas— no tiene tipo de mención y no se
     * anota: su fila también trae `id` y `name`, y tratarla como artículo sería mandar al dueño a
     * la ficha de un artículo que no existe.
     *
     * @return array<string, string>
     */
    public static function entidades_del_catalogo(): array
    {
        return [
            'article' => self::TIPO_ARTICULO,
            'client'  => self::TIPO_CLIENTE,
        ];
    }

    /**
     * Los candidatos a mención que deja UNA tool: los pares (tipo, id, texto) que aparecen en los
     * datos crudos que devolvió, a cualquier profundidad.
     *
     * Candidato NO es mención: acá no se mira el texto de la respuesta ni la base. Eso es cruzar().
     *
     * @param  string  $tool_name  Nombre de la tool que produjo los datos.
     * @param  mixed   $datos      Lo que devolvió su handler (array anidado, normalmente).
     * @return array<int, array<string, mixed>>
     */
    public static function candidatos_de_tool($tool_name, $datos): array
    {
        if (!is_array($datos)) {

            return [];
        }

        $candidatos = [];

        self::recolectar((string) $tool_name, $datos, $candidatos);

        return $candidatos;
    }

    /**
     * Recorre el árbol de datos juntando candidatos. Recursiva porque las tools devuelven formas
     * muy distintas: listas planas, un objeto con una lista adentro, y objetos con dos niveles.
     *
     * @param  string  $tool_name
     * @param  array   $nodo
     * @param  array   $candidatos  Se llena por referencia.
     * @return void
     */
    protected static function recolectar($tool_name, array $nodo, array &$candidatos)
    {
        // La consulta genérica dice en su propia respuesta de qué entidad son las filas.
        self::recolectar_del_catalogo($nodo, $candidatos);

        $pares = self::pares_etiquetados();

        $por_tool = self::pares_por_tool();

        if (isset($por_tool[$tool_name])) {
            $pares[] = $por_tool[$tool_name];
        }

        // Tipos que este nodo ya resolvió CON id: su nombre no vuelve a salir como candidato suelto.
        $con_id = [];

        foreach ($pares as $par) {
            if (!array_key_exists($par['id'], $nodo) || !array_key_exists($par['texto'], $nodo)) {
                continue;
            }

            $con_id[$par['tipo']] = true;

            $candidatos[] = [
                'tipo'  => $par['tipo'],
                'id'    => (int) $nodo[$par['id']],
                'texto' => is_string($nodo[$par['texto']]) ? $nodo[$par['texto']] : '',
            ];
        }

        foreach (self::claves_de_nombre($tool_name) as $clave => $tipo) {
            if (isset($con_id[$tipo]) || !array_key_exists($clave, $nodo) || !is_string($nodo[$clave])) {
                continue;
            }

            $candidatos[] = [
                'tipo'  => $tipo,
                'id'    => 0,
                'texto' => $nodo[$clave],
            ];
        }

        foreach ($nodo as $hijo) {
            if (is_array($hijo)) {
                self::recolectar($tool_name, $hijo, $candidatos);
            }
        }
    }

    /**
     * Los candidatos de una respuesta de `consultar_datos`: sus filas traen `id` y `name`, y la
     * propia respuesta declara la `entidad`, así que acá el tipo no se adivina.
     *
     * @param  array  $nodo
     * @param  array  $candidatos  Se llena por referencia.
     * @return void
     */
    protected static function recolectar_del_catalogo(array $nodo, array &$candidatos)
    {
        if (!isset($nodo['entidad']) || !is_string($nodo['entidad']) || !isset($nodo['registros']) || !is_array($nodo['registros'])) {

            return;
        }

        $entidades = self::entidades_del_catalogo();

        if (!isset($entidades[$nodo['entidad']])) {

            return;
        }

        foreach ($nodo['registros'] as $registro) {
            if (!is_array($registro) || !isset($registro['id']) || !isset($registro['name'])) {
                continue;
            }

            $candidatos[] = [
                'tipo'  => $entidades[$nodo['entidad']],
                'id'    => (int) $registro['id'],
                'texto' => is_string($registro['name']) ? $registro['name'] : '',
            ];
        }
    }

    /**
     * Las menciones definitivas: de todos los candidatos que dejaron las tools, los que el modelo
     * efectivamente nombró en el texto final y que además son del dueño.
     *
     * Los filtros, en orden, y cada uno existe por un caso concreto de mención equivocada:
     *
     * 1. **Forma y largo.** Tipo conocido, literal de al menos MINIMO_CARACTERES y que no sea uno
     *    de los RELLENOS.
     * 2. **Aparece en el texto, y como palabra.** El literal tiene que estar en `$contenido` con
     *    los mismos caracteres y la misma capitalización, y la ocurrencia tiene que estar
     *    delimitada: "CABLE" adentro de "CABLEADO" no es una mención de "CABLE".
     * 3. 🔴 **La base manda.** Los candidatos con id se confirman: el id tiene que existir, ser del
     *    dueño y llamarse HOY exactamente como dice el literal. Los candidatos sin id se resuelven
     *    por nombre, y solo si ese nombre es único entre los del dueño.
     * 4. 🔴 **Ambigüedad.** Si el MISMO literal quedó resuelto a más de un (tipo, id) —un artículo y
     *    un cliente que se llaman igual—, se descartan TODOS: elegir uno es tirar una moneda con la
     *    ficha del dueño.
     * 5. 🔴 **Solapamiento, gana la más larga.** "Ferreteria El Tornillo" y "Ferreteria El Tornillo
     *    SA" son dos clientes distintos: si el modelo escribió el largo, el corto matchea adentro y
     *    marcarlo mandaría al cliente equivocado. Se aceptan de mayor a menor largo y la corta
     *    sobrevive solo si le queda alguna ocurrencia libre.
     *
     * @param  array<int, array<string, mixed>>  $candidatos  Lo que juntó candidatos_de_tool().
     * @param  string  $contenido  El texto final de la respuesta, tal como lo va a leer la persona.
     * @param  int     $owner_id   Dueño de la conversación (articles.user_id / clients.user_id).
     * @return array<int, array<string, mixed>>  Menciones en el orden en que aparecen en el texto.
     */
    public static function cruzar(array $candidatos, $contenido, $owner_id): array
    {
        $contenido = (string) $contenido;

        if (trim($contenido) === '') {

            return [];
        }

        $depurados = self::depurar($candidatos, $contenido);

        if (empty($depurados)) {

            return [];
        }

        $confirmados = self::confirmar_contra_la_base($depurados, (int) $owner_id);

        if (empty($confirmados)) {

            return [];
        }

        $sin_ambiguas = self::descartar_ambiguas($confirmados);

        if (empty($sin_ambiguas)) {

            return [];
        }

        return self::resolver_solapamientos($sin_ambiguas, $contenido);
    }

    /**
     * Filtros 1 y 2: forma, largo y presencia en el texto. Devuelve cada candidato una sola vez,
     * con sus ocurrencias ya calculadas.
     *
     * @param  array  $candidatos
     * @param  string $contenido
     * @return array<int, array<string, mixed>>
     */
    protected static function depurar(array $candidatos, $contenido): array
    {
        $tipos = [self::TIPO_CLIENTE, self::TIPO_ARTICULO];

        $vistos = [];

        $depurados = [];

        foreach ($candidatos as $candidato) {
            if (!is_array($candidato) || !isset($candidato['tipo']) || !isset($candidato['id']) || !isset($candidato['texto'])) {
                continue;
            }

            $tipo = (string) $candidato['tipo'];
            $id = (int) $candidato['id'];
            $texto = trim((string) $candidato['texto']);

            if (!in_array($tipo, $tipos, true) || $id < 0) {
                continue;
            }

            if (mb_strlen($texto) < self::MINIMO_CARACTERES) {
                continue;
            }

            if (in_array(mb_strtolower($texto), self::RELLENOS, true)) {
                continue;
            }

            $clave = $tipo . '|' . $id . '|' . $texto;

            if (isset($vistos[$clave])) {
                continue;
            }

            $ocurrencias = self::ocurrencias($contenido, $texto);

            if (empty($ocurrencias)) {
                continue;
            }

            $vistos[$clave] = true;

            $depurados[] = [
                'tipo'        => $tipo,
                'id'          => $id,
                'texto'       => $texto,
                'ocurrencias' => $ocurrencias,
            ];
        }

        return $depurados;
    }

    /**
     * Filtro 3: lo que quedó pasa por la base del DUEÑO. Cuatro consultas como mucho (dos tipos ×
     * confirmar por id / resolver por nombre), y solo sobre lo que el modelo realmente nombró.
     *
     * 🔴 Es la diferencia entre "una tool dijo que el id 45 se llama así" y "el cliente 45 es del
     * dueño de esta conversación y hoy se llama exactamente así". Sin esto, cualquier fila que se
     * colara con un id ajeno —o con un nombre derivado en vez del real— se convertiría en un link a
     * datos de otro comercio.
     *
     * @param  array  $depurados
     * @param  int    $owner_id
     * @return array<int, array<string, mixed>>
     */
    protected static function confirmar_contra_la_base(array $depurados, $owner_id): array
    {
        $ids = [self::TIPO_CLIENTE => [], self::TIPO_ARTICULO => []];

        $nombres_sueltos = [self::TIPO_CLIENTE => [], self::TIPO_ARTICULO => []];

        foreach ($depurados as $mencion) {
            if ($mencion['id'] > 0) {
                $ids[$mencion['tipo']][] = $mencion['id'];
            } else {
                $nombres_sueltos[$mencion['tipo']][] = $mencion['texto'];
            }
        }

        $por_id = [
            self::TIPO_CLIENTE  => self::nombres_por_id(self::TIPO_CLIENTE, $ids[self::TIPO_CLIENTE], $owner_id),
            self::TIPO_ARTICULO => self::nombres_por_id(self::TIPO_ARTICULO, $ids[self::TIPO_ARTICULO], $owner_id),
        ];

        $por_nombre = [
            self::TIPO_CLIENTE  => self::resolver_por_nombre(self::TIPO_CLIENTE, $nombres_sueltos[self::TIPO_CLIENTE], $owner_id),
            self::TIPO_ARTICULO => self::resolver_por_nombre(self::TIPO_ARTICULO, $nombres_sueltos[self::TIPO_ARTICULO], $owner_id),
        ];

        $confirmados = [];

        $vistos = [];

        foreach ($depurados as $mencion) {
            $id = $mencion['id'];

            if ($id > 0) {
                $nombres = $por_id[$mencion['tipo']];

                if (!isset($nombres[$id]) || (string) $nombres[$id] !== $mencion['texto']) {
                    continue;
                }
            } else {
                $resueltos = $por_nombre[$mencion['tipo']];

                if (!isset($resueltos[$mencion['texto']])) {
                    continue;
                }

                $id = (int) $resueltos[$mencion['texto']];
                $mencion['id'] = $id;
            }

            // Un mismo (tipo, id, texto) pudo llegar por dos caminos: con id de una tool y por
            // nombre de otra.
            $clave = $mencion['tipo'] . '|' . $id . '|' . $mencion['texto'];

            if (isset($vistos[$clave])) {
                continue;
            }

            $vistos[$clave] = true;

            $confirmados[] = $mencion;
        }

        return $confirmados;
    }

    /**
     * El nombre actual de cada id que sea del dueño. Los que no existen o son de otro comercio
     * simplemente no aparecen en el resultado.
     *
     * @param  string  $tipo
     * @param  array<int, int>  $ids
     * @param  int  $owner_id
     * @return array<int, string>  id => nombre
     */
    protected static function nombres_por_id($tipo, array $ids, $owner_id): array
    {
        if (empty($ids)) {

            return [];
        }

        return self::query_de($tipo)
            ->where('user_id', $owner_id)
            ->whereIn('id', array_values(array_unique($ids)))
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * El id de cada nombre que sea ÚNICO entre los del dueño.
     *
     * 🔴 Un nombre repetido NO se resuelve. En un catálogo reimportado es normal tener dos
     * artículos con el mismo nombre; marcar uno de los dos es mandar al dueño a la ficha
     * equivocada la mitad de las veces, y no hay dato en el texto que permita desempatar.
     *
     * ⚠️ La comparación final es en PHP y por eso distingue mayúsculas: el `whereIn` de MySQL no
     * las distingue (collation), así que "Juan Perez" y "JUAN PEREZ" vuelven los dos y el
     * agrupado de acá abajo los cuenta como dos nombres distintos —que es lo correcto: el literal
     * del contrato es EXACTO—, pero además deja al que empate exacto como no-único si su gemelo
     * comparte la escritura.
     *
     * @param  string  $tipo
     * @param  array<int, string>  $nombres
     * @param  int  $owner_id
     * @return array<string, int>  nombre => id
     */
    protected static function resolver_por_nombre($tipo, array $nombres, $owner_id): array
    {
        if (empty($nombres)) {

            return [];
        }

        $filas = self::query_de($tipo)
            ->where('user_id', $owner_id)
            ->whereIn('name', array_values(array_unique($nombres)))
            ->get(['id', 'name']);

        $por_nombre = [];

        foreach ($filas as $fila) {
            $nombre = (string) $fila->name;

            if (!isset($por_nombre[$nombre])) {
                $por_nombre[$nombre] = [];
            }

            $por_nombre[$nombre][] = (int) $fila->id;
        }

        $resueltos = [];

        foreach ($por_nombre as $nombre => $ids) {
            if (count($ids) === 1) {
                $resueltos[$nombre] = $ids[0];
            }
        }

        return $resueltos;
    }

    /**
     * El query builder del modelo de cada tipo. Los dos usan SoftDeletes, así que un cliente o un
     * artículo borrado no se puede mencionar: su scope global ya lo deja afuera.
     *
     * @param  string  $tipo
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected static function query_de($tipo)
    {
        return $tipo === self::TIPO_CLIENTE ? Client::query() : Article::query();
    }

    /**
     * Filtro 4: un literal que resuelve a más de un (tipo, id) no se marca.
     *
     * @param  array  $confirmados
     * @return array<int, array<string, mixed>>
     */
    protected static function descartar_ambiguas(array $confirmados): array
    {
        $duenos = [];

        foreach ($confirmados as $mencion) {
            if (!isset($duenos[$mencion['texto']])) {
                $duenos[$mencion['texto']] = [];
            }

            $duenos[$mencion['texto']][$mencion['tipo'] . '|' . $mencion['id']] = true;
        }

        $limpias = [];

        foreach ($confirmados as $mencion) {
            if (count($duenos[$mencion['texto']]) > 1) {
                continue;
            }

            $limpias[] = $mencion;
        }

        return $limpias;
    }

    /**
     * Filtro 5: de mayor a menor largo, una mención entra solo si le queda alguna ocurrencia que no
     * se pise con las ya aceptadas. Devuelve la lista final ordenada por dónde aparece cada una.
     *
     * @param  array  $confirmados
     * @param  string $contenido
     * @return array<int, array<string, mixed>>
     */
    protected static function resolver_solapamientos(array $confirmados, $contenido): array
    {
        // Largo descendente, y a igual largo el id menor primero: el orden no puede depender del
        // orden en que las tools hayan devuelto las filas.
        usort($confirmados, function ($a, $b) {
            $largo_a = mb_strlen($a['texto']);
            $largo_b = mb_strlen($b['texto']);

            if ($largo_a !== $largo_b) {

                return $largo_b - $largo_a;
            }

            return $a['id'] - $b['id'];
        });

        $tomados = [];

        $aceptadas = [];

        foreach ($confirmados as $mencion) {
            $largo = mb_strlen($mencion['texto']);

            $libre = null;

            foreach ($mencion['ocurrencias'] as $desde) {
                if (!self::se_pisa($tomados, $desde, $desde + $largo)) {
                    $libre = $desde;
                    break;
                }
            }

            if (is_null($libre)) {
                continue;
            }

            /*
             * Todas sus ocurrencias quedan tomadas, no solo la libre: la SPA marca TODAS las
             * ocurrencias del literal (regla 3 del contrato), así que una mención más corta no
             * puede quedarse con ninguna de ellas.
             */
            foreach ($mencion['ocurrencias'] as $desde) {
                $tomados[] = [$desde, $desde + $largo];
            }

            $aceptadas[] = [
                'tipo'    => $mencion['tipo'],
                'id'      => $mencion['id'],
                'texto'   => $mencion['texto'],
                'primera' => $libre,
            ];
        }

        usort($aceptadas, function ($a, $b) {

            return $a['primera'] - $b['primera'];
        });

        $menciones = [];

        foreach ($aceptadas as $mencion) {
            $menciones[] = [
                'tipo'  => $mencion['tipo'],
                'id'    => $mencion['id'],
                'texto' => $mencion['texto'],
            ];
        }

        return $menciones;
    }

    /**
     * true si el tramo [$desde, $hasta) se pisa con alguno de los ya tomados.
     *
     * @param  array<int, array<int, int>>  $tomados
     * @param  int  $desde
     * @param  int  $hasta
     * @return bool
     */
    protected static function se_pisa(array $tomados, $desde, $hasta): bool
    {
        foreach ($tomados as $tramo) {
            if ($desde < $tramo[1] && $tramo[0] < $hasta) {

                return true;
            }
        }

        return false;
    }

    /**
     * Dónde aparece el literal en el texto, en posiciones de CARACTER (no de byte: los nombres
     * tienen acentos y ñ).
     *
     * 🔴 Solo cuentan las ocurrencias delimitadas: si el caracter de antes o el de después es una
     * letra o un número, el literal está adentro de otra palabra y NO es una mención. Es lo que
     * evita que un artículo llamado "CABLE" se marque adentro de "CABLEADO".
     *
     * @param  string  $contenido
     * @param  string  $aguja
     * @return array<int, int>  Posiciones de inicio, de la primera a la última.
     */
    public static function ocurrencias($contenido, $aguja): array
    {
        $contenido = (string) $contenido;
        $aguja = (string) $aguja;

        if ($aguja === '') {

            return [];
        }

        $largo_texto = mb_strlen($contenido);
        $largo_aguja = mb_strlen($aguja);

        $posiciones = [];
        $desde = 0;

        while ($desde <= $largo_texto - $largo_aguja) {
            $encontrado = mb_strpos($contenido, $aguja, $desde);

            if ($encontrado === false) {
                break;
            }

            $anterior = $encontrado > 0 ? mb_substr($contenido, $encontrado - 1, 1) : '';
            $siguiente = mb_substr($contenido, $encontrado + $largo_aguja, 1);

            if (!self::es_alfanumerico($anterior) && !self::es_alfanumerico($siguiente)) {
                $posiciones[] = $encontrado;
            }

            $desde = $encontrado + 1;
        }

        return $posiciones;
    }

    /**
     * true si el caracter es letra o número (con acentos y ñ, por eso la clase unicode y no
     * ctype_alnum, que trabaja byte a byte).
     *
     * @param  string  $caracter
     * @return bool
     */
    protected static function es_alfanumerico($caracter): bool
    {
        if ($caracter === '') {

            return false;
        }

        return preg_match('/[\p{L}\p{N}]/u', $caracter) === 1;
    }

    /**
     * Las menciones guardadas de un mensaje, servidas SIEMPRE como lista (contrato §1: si no hay
     * va `[]`, nunca null y nunca ausente). Lo usa el accessor del modelo, así que vale para los
     * tres lugares por donde viaja un mensaje sin que ninguno tenga que acordarse.
     *
     * Defensiva a propósito: la columna puede traer null (todo mensaje anterior a la misión), un
     * JSON inválido o un array ya decodificado según cómo se la haya escrito.
     *
     * @param  mixed  $valor
     * @return array<int, array<string, mixed>>
     */
    public static function normalizar($valor): array
    {
        if (is_string($valor)) {
            $valor = json_decode($valor, true);
        }

        if (!is_array($valor)) {

            return [];
        }

        $menciones = [];

        foreach ($valor as $mencion) {
            if (!is_array($mencion) || !isset($mencion['tipo']) || !isset($mencion['id']) || !isset($mencion['texto'])) {
                continue;
            }

            $menciones[] = [
                'tipo'  => (string) $mencion['tipo'],
                'id'    => (int) $mencion['id'],
                'texto' => (string) $mencion['texto'],
            ];
        }

        return $menciones;
    }
}
