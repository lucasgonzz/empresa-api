<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Article;
use App\Models\Balanza;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tickets de balanza (misión balanzas-configurables, 3/10/2026).
 *
 * Toda la lógica de negocio de las balanzas vive acá: VenderController, BalanzaController y el
 * comando `balanzas:migrar-desde-extensiones` solo deciden y responden.
 *
 * Hay dos dinámicas, que elige el dueño en `users.tickets_de_balanza`
 * (UserHelper::modo_tickets_de_balanza()):
 *
 *   'plu'       -> el código trae el PLU del artículo y el peso (La Martina). Es la lectura de la
 *                  extensión vieja `plu_balanza_bar_code`, movida acá SIN CAMBIARLE NADA
 *                  (leer_ticket_por_plu()).
 *   'balanzas'  -> el código empieza con el prefijo de una balanza del ABM y trae un importe o un
 *                  peso (Panchito). Reemplaza a la extensión vieja `balanza_bar_code`, que leía
 *                  solo el prefijo '22' y se lo imputaba a un artículo HARDCODEADO
 *                  (leer_ticket_por_balanzas()).
 *
 * 🔴 La regla de leer_ticket_por_balanzas() la repite la SPA (`src/utils/balanzas.js`) para leer
 * tickets sin conexión o con `usar_articles_cache`. Si se cambia acá, se cambia allá: el mismo
 * ticket no puede leerse distinto online y offline.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class BalanzaHelper
{
    /** `users.tickets_de_balanza`: el dueño eligió no usar balanzas (para la lectura, igual que NULL). */
    const MODO_NINGUNO = 'ninguno';

    /** `users.tickets_de_balanza`: lectura PLU (tipo + PLU + peso). */
    const MODO_PLU = 'plu';

    /** `users.tickets_de_balanza`: lectura por las balanzas del ABM. */
    const MODO_BALANZAS = 'balanzas';

    /**
     * Lista blanca de lo que acepta UserController::update() para `tickets_de_balanza`. Cualquier
     * otro valor se ignora (no se guarda): un valor desconocido en la columna dejaría al comercio
     * sin lectura de balanzas sin ningún error a la vista.
     */
    const MODOS = array(self::MODO_NINGUNO, self::MODO_PLU, self::MODO_BALANZAS);

    /** `balanzas.tipo_dato`: el código trae el precio del renglón. */
    const TIPO_IMPORTE = 'importe';

    /** `balanzas.tipo_dato`: el código trae la cantidad (peso) del renglón. */
    const TIPO_PESO = 'peso';

    /**
     * Dígitos de un importe cuando la balanza no dice otra cosa. Es EXACTAMENTE la lectura de la
     * extensión vieja (`substr($codigo, -8)` y de eso los primeros 7), que coincide al peso con los
     * renglones guardados en Panchito: `2202000085805` -> $8.580, `2203000099557` -> $9.955.
     */
    const DIGITOS_IMPORTE_POR_DEFECTO = 7;

    /** Dígitos de un peso cuando la balanza no dice otra cosa: los mismos 5 del ticket PLU. */
    const DIGITOS_PESO_POR_DEFECTO = 5;

    /** Largo máximo del prefijo de una balanza (el plan dice de 1 a 6 dígitos). */
    const PREFIJO_LARGO_MAXIMO = 6;

    /**
     * Tope de `digitos` configurables. Un EAN-13 no da para más y, sobre todo, el valor se
     * convierte a entero: con 19 dígitos o más PHP se pasa del entero de 64 bits.
     */
    const DIGITOS_MAXIMO = 12;

    /** Id de "Gramo" en `unidad_medidas`: con esa unidad el peso NO se divide por 1000. */
    const UNIDAD_MEDIDA_GRAMO = 2;

    /** El prefijo que leía la extensión vieja `balanza_bar_code`. */
    const PREFIJO_EXTENSION_VIEJA = '22';

    /** Las dos extensiones que esta misión deja sin efecto (sus filas NO se borran). */
    const EXTENCION_PLU = 'plu_balanza_bar_code';
    const EXTENCION_IMPORTE = 'balanza_bar_code';

    // ─────────────────────────────────────────────────────────────────────────────
    //  Lectura de un ticket en VENDER
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Lee un código como ticket de alguna balanza del dueño (modo 'balanzas').
     *
     * La regla, la misma que repite la SPA para leer sin conexión:
     *
     *   1. El código tiene que ser solo dígitos.
     *   2. Candidatas: las balanzas del dueño cuyo prefijo es el comienzo del código. Gana el
     *      prefijo MÁS LARGO; si empatan, la de id más chico (elegir_balanza()).
     *   3. Dígitos del dato: los de la balanza, o 7 si es importe / 5 si es peso (digitos_de()).
     *   4. Si el código es más corto que prefijo + dígitos + verificador, no es un ticket de esa
     *      balanza y no se lee (devuelve null). No se prueba con otra balanza de prefijo más corto:
     *      eso haría que el resultado dependiera de qué balanzas hay cargadas de una forma que el
     *      operador no ve, y la SPA tendría que replicar el mismo desempate de segundo nivel.
     *   5. El dato son los `digitos` caracteres ANTERIORES al último (valor_del_ticket()).
     *   6. Importe -> `price_vender`. Peso -> `amount`, dividido por 1000 salvo que el artículo se
     *      venda en Gramos (la misma regla que el PLU).
     *
     * Si la balanza se encontró pero su artículo no sirve (sin asignar, inexistente, borrado o de
     * otro dueño), devuelve el resultado con `article` en null: el controller responde
     * `balanza_sin_articulo` para que VENDER avise en vez de decir "no se encontró artículo".
     *
     * @param  string  $codigo    Lo que escaneó el lector.
     * @param  int     $owner_id  Dueño del comercio (las balanzas y el artículo tienen que ser suyos).
     * @return array|null  null si el código no es un ticket de ninguna balanza. Si lo es:
     *                     ['balanza' => Balanza, 'tipo_dato' => 'importe'|'peso',
     *                      'article' => Article|null, 'price_vender' => int|null,
     *                      'amount' => float|null]
     */
    static function leer_ticket_por_balanzas($codigo, $owner_id)
    {
        $codigo = (string) $codigo;

        /*
         * 1. Solo dígitos: un código con letras no es un ticket de balanza.
         *
         * 🔴 ctype_digit y NO preg_match('/^[0-9]+$/'): en PCRE el `$` también acepta un "\n" al
         * final, así que `2201000027143\n` pasaba y se leía corrido un lugar (el "\n" hacía de
         * "último caracter") -> $27.143 en vez de $2.714. La SPA lo rechaza (`/^\d+$/` de
         * JavaScript), y la regla tiene que ser la misma de los dos lados. ctype_digit sobre un
         * string exige que TODOS los caracteres sean dígitos, y con '' devuelve false. Va sobre
         * el string ya casteado: con un int, ctype_digit lo interpreta como un código ASCII.
         * Existe siempre: si falta la extensión, la cubre symfony/polyfill-ctype de vendor/.
         */
        if (!ctype_digit($codigo)) {
            return null;
        }

        // Liviano: son pocas filas por dueño y acá no hace falta el artículo de cada una.
        $balanzas = Balanza::where('user_id', $owner_id)
                            ->orderBy('id', 'ASC')
                            ->get();

        // 2. La balanza que se queda con el código.
        $balanza = self::elegir_balanza($codigo, $balanzas);

        if (is_null($balanza)) {
            return null;
        }

        $prefijo   = self::normalizar_prefijo($balanza->prefijo);
        $tipo_dato = self::normalizar_tipo_dato($balanza->tipo_dato);

        // 3. Cuántos dígitos son el dato.
        $digitos = self::digitos_de($balanza);

        // 4. Prefijo + dato + verificador: si no entra, no es un ticket de esta balanza.
        if (strlen($codigo) < strlen($prefijo) + $digitos + 1) {
            return null;
        }

        // 5. El dato, como entero.
        $valor = self::valor_del_ticket($codigo, $digitos);

        Log::info('Ticket de balanza '.$codigo.': balanza '.$balanza->id.' (prefijo '.$prefijo.', '.$tipo_dato.', '.$digitos.' digitos), valor '.$valor);

        $resultado = array(
            'balanza'      => $balanza,
            'tipo_dato'    => $tipo_dato,
            'article'      => null,
            'price_vender' => null,
            'amount'       => null,
        );

        // El artículo tiene que existir, no estar borrado (el scope de SoftDeletes lo excluye) y
        // ser de ESTE dueño: en las bases compartidas viejas un id puede ser de otro comercio.
        // withAllSinAcopio y no withAll: es el arreglo de performance del escaneo del 1/9/2026
        // (ver Sales/15_Indices_De_Venta_Y_Vender_Test).
        if (!is_null($balanza->article_id)) {
            $resultado['article'] = Article::where('id', $balanza->article_id)
                                            ->where('user_id', $owner_id)
                                            ->withAllSinAcopio()
                                            ->first();
        }

        if (is_null($resultado['article'])) {
            return $resultado;
        }

        // 6. Importe o peso.
        if ($tipo_dato == self::TIPO_PESO) {

            $amount = (float) $valor;

            if ($resultado['article']->unidad_medida_id != self::UNIDAD_MEDIDA_GRAMO) {
                $amount = $amount / 1000;
            }

            $resultado['amount'] = $amount;

        } else {

            $resultado['price_vender'] = $valor;
        }

        return $resultado;
    }

    /**
     * Lee un código como ticket PLU (modo 'plu'): 2 dígitos de tipo de balanza + 5 de PLU + 5 de
     * peso (+ verificador).
     *
     * 🔴 ES LA LECTURA DE `VenderController::check_balanza_plu()` MOVIDA SIN CAMBIARLE UNA COMA
     * DE COMPORTAMIENTO. La Martina vende así todos los días (3.397 renglones con PLU en 30 días) y
     * el pedido fue que funcione "igual que hoy". Por eso se conservan también sus rarezas, a
     * propósito: cualquier código de 12 dígitos o más que no se encontró se intenta leer como PLU
     * (un EAN `77…` se parsea como "tipo de balanza 77" y simplemente no encuentra PLU), y con dos
     * artículos con el mismo PLU gana el que devuelve primero MySQL. No "arreglar" acá sin una
     * misión que lo decida.
     *
     * @param  string  $barcode   Lo que escaneó el lector.
     * @param  int     $owner_id  Dueño del comercio.
     * @return array  ['article' => Article|null, 'amount' => float|int] (sin `amount` si el código
     *                es más corto que 12 caracteres, igual que antes).
     */
    static function leer_ticket_por_plu($barcode, $owner_id)
    {
        if (mb_strlen($barcode) < 12) {
            return [
                'article'   => null,
            ];
        }


        // 2
        $tipo_balanza = mb_substr($barcode, 0, 2);

        // 5 (quita ceros iniciales)
        $plu = ltrim(mb_substr($barcode, 2, 5), '0');

        // 6 (quita ceros iniciales)
        $amount = ltrim(mb_substr($barcode, 7, 5), '0');

        // Si queda vacío (ej: "00000"), lo llevamos a 0
        $amount = $amount === '' ? 0 : (float) $amount;

        Log::info('tipo_balanza: '.$tipo_balanza);
        Log::info('plu: '.$plu);
        Log::info('amount: '.$amount);

        $article = Article::where('user_id', $owner_id)
                            ->where('plu', $plu)
                            ->withAllSinAcopio()
                            ->first();

        if ($article && $article->unidad_medida_id != self::UNIDAD_MEDIDA_GRAMO) {
            $amount /= 1000;
        }

        return [
            'article'     => $article,
            'amount'      => $amount,
        ];
    }

    /**
     * Elige, entre las balanzas del dueño, la que se queda con el código.
     *
     * 🔴 GANA EL PREFIJO MÁS LARGO, y no "la primera que matchea". Es lo que permite tener una
     * balanza general y otras más específicas que se pisan: en Panchito los tickets `2201…`,
     * `2202…` y `2203…` van hoy todos a Carniceria, y la migración los deja así con una sola balanza
     * '22'. Si 02 y 03 resultan ser otras secciones, se cargan las balanzas '2202' y '2203' en el
     * ABM y se llevan esos tickets sin tocar la '22'. Con "la primera que matchea", el resultado
     * dependería del orden en que se cargaron.
     *
     * Empate (mismo largo, o sea el mismo prefijo: el ABM no lo deja guardar, pero puede venir de
     * otro lado) -> la de id más chico, para que la elección sea siempre la misma.
     *
     * @param  string  $codigo
     * @param  \Illuminate\Support\Collection|array  $balanzas  Ordenadas por id ascendente.
     * @return \App\Models\Balanza|null
     */
    static function elegir_balanza($codigo, $balanzas)
    {
        $elegida = null;
        $largo_elegido = 0;

        foreach ($balanzas as $balanza) {

            $prefijo = self::normalizar_prefijo($balanza->prefijo);

            // Una balanza sin prefijo se quedaría con TODOS los códigos que no se encontraron: se
            // ignora (la API no deja guardarla, pero la fila podría venir de otro lado).
            if ($prefijo === '') {
                continue;
            }

            if (strpos($codigo, $prefijo) !== 0) {
                continue;
            }

            // Estrictamente más largo: a igual largo se queda la anterior, que tiene id más chico.
            if (strlen($prefijo) > $largo_elegido
                || (strlen($prefijo) == $largo_elegido && !is_null($elegida) && $balanza->id < $elegida->id)) {

                $elegida = $balanza;
                $largo_elegido = strlen($prefijo);
            }
        }

        return $elegida;
    }

    /**
     * El dato del ticket: los `$digitos` caracteres ANTERIORES al último, como entero.
     *
     * 🔴 El último dígito es el verificador del EAN-13 y NO es parte del dato: tomar "los últimos
     * N" correría todo un lugar y multiplicaría el importe por 10. Con 7 dígitos esto es
     * exactamente `substr($codigo, -8)` y de eso los primeros 7, la lectura de la extensión vieja
     * que coincide con los renglones reales de Panchito (`2201000027143` -> 2714).
     *
     * @param  string  $codigo   Solo dígitos, ya validado como suficientemente largo.
     * @param  int     $digitos
     * @return int
     */
    static function valor_del_ticket($codigo, $digitos)
    {
        return (int) substr($codigo, -($digitos + 1), $digitos);
    }

    /**
     * Cuántos dígitos del código son el dato para esta balanza: los suyos, o el default por tipo.
     *
     * @param  \App\Models\Balanza  $balanza
     * @return int
     */
    static function digitos_de($balanza)
    {
        $digitos = self::normalizar_digitos($balanza->digitos);

        if (!is_null($digitos)) {
            return $digitos;
        }

        if (self::normalizar_tipo_dato($balanza->tipo_dato) == self::TIPO_PESO) {
            return self::DIGITOS_PESO_POR_DEFECTO;
        }

        return self::DIGITOS_IMPORTE_POR_DEFECTO;
    }

    /**
     * Nombre de la balanza para el aviso de VENDER ("La balanza «X» no tiene un artículo
     * válido"). Nunca vacío: si la balanza no tiene nombre, se muestra su prefijo.
     *
     * @param  \App\Models\Balanza  $balanza
     * @return string
     */
    static function nombre_para_mostrar($balanza)
    {
        $nombre = trim((string) $balanza->nombre);

        if ($nombre !== '') {
            return $nombre;
        }

        return self::normalizar_prefijo($balanza->prefijo);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  ABM (BalanzaController)
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Los campos de una balanza, normalizados, tal como se guardan.
     *
     * No se usa `$request->validate()` (regla del repo: la validación va en el front); lo que sí se
     * hace acá es dejar cada valor en la forma que la lectura espera, para que un dato raro no
     * produzca un ticket mal leído.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array  ['nombre', 'prefijo', 'article_id', 'tipo_dato', 'digitos']
     */
    static function datos_desde_request($request)
    {
        $nombre = $request->nombre;
        $nombre = is_scalar($nombre) ? trim((string) $nombre) : '';

        $article_id = $request->article_id;
        $article_id = (is_numeric($article_id) && (int) $article_id > 0) ? (int) $article_id : null;

        return array(
            // La columna es de 80: se recorta acá para que un nombre largo no sea un error de SQL.
            'nombre'     => $nombre === '' ? null : mb_substr($nombre, 0, 80),
            'prefijo'    => self::normalizar_prefijo($request->prefijo),
            'article_id' => $article_id,
            'tipo_dato'  => self::normalizar_tipo_dato($request->tipo_dato),
            'digitos'    => self::normalizar_digitos($request->digitos),
        );
    }

    /**
     * Lo que impide guardar una balanza, o null si se puede.
     *
     * Son tres "razones fuertes" para validar del lado de la API (regla del repo: el back no valida
     * salvo razón fuerte documentada):
     *
     *   - Prefijo vacío o de más de 6 dígitos: una balanza sin prefijo se quedaría con TODOS los
     *     códigos que no se encontraron y le cobraría cualquier cosa al artículo de la balanza.
     *   - Prefijo repetido para el mismo dueño: dos balanzas con el mismo código dejan el escaneo
     *     ambiguo para quien las carga (cuál de los dos artículos se lleva el ticket). La lectura
     *     igual desempata en forma determinista (id más chico), pero eso el operador no lo ve.
     *   - Artículo que no es del dueño (inexistente, borrado o de otro comercio): en una base
     *     compartida, un POST/PUT armado a mano con el `article_id` de otro comercio se guardaba, y
     *     como el ABM trae la relación `article` sin filtrar por dueño, le mostraba el artículo
     *     ajeno. La lectura del ticket ya lo descartaba (`balanza_sin_articulo`), pero la balanza
     *     no puede quedar guardada apuntando ahí. Sin artículo (null) se sigue aceptando: es una
     *     balanza a medio configurar y VENDER avisa al escanear. Consecuencia buscada: si el
     *     artículo de una balanza se borra, para volver a guardarla hay que elegirle otro.
     *
     * Prefijos que se pisan ('22' y '2203') SÍ están permitidos: gana el más largo, a propósito.
     *
     * @param  array     $datos            Lo que devolvió datos_desde_request().
     * @param  int       $owner_id
     * @param  int|null  $balanza_id_propia  Al editar, la balanza que se edita (no choca consigo misma).
     * @return string|null
     */
    static function error_de_validacion($datos, $owner_id, $balanza_id_propia = null)
    {
        $prefijo = $datos['prefijo'];

        if ($prefijo === '' || strlen($prefijo) > self::PREFIJO_LARGO_MAXIMO) {
            return 'El código de la balanza tiene que tener entre 1 y '.self::PREFIJO_LARGO_MAXIMO.' números.';
        }

        $repetida = Balanza::where('user_id', $owner_id)
                            ->where('prefijo', $prefijo);

        if (!is_null($balanza_id_propia)) {
            $repetida->where('id', '!=', $balanza_id_propia);
        }

        if ($repetida->exists()) {
            return 'Ya tenés otra balanza con el código '.$prefijo;
        }

        // El artículo, si viene, con la MISMA definición de "válido" que usa la lectura del ticket
        // (leer_ticket_por_balanzas): que exista, que no esté borrado (lo excluye el scope de
        // SoftDeletes) y que sea de este dueño.
        if (!is_null($datos['article_id'])) {

            $articulo_del_dueno = Article::where('id', $datos['article_id'])
                                            ->where('user_id', $owner_id)
                                            ->exists();

            if (!$articulo_del_dueno) {
                return 'El artículo elegido no existe o no es de este comercio.';
            }
        }

        return null;
    }

    /**
     * Deja solo los dígitos del prefijo (" 22-03 " -> "2203").
     *
     * @param  mixed  $prefijo
     * @return string
     */
    static function normalizar_prefijo($prefijo)
    {
        if (!is_scalar($prefijo)) {
            return '';
        }

        return preg_replace('/[^0-9]/', '', (string) $prefijo);
    }

    /**
     * 'peso' o 'importe'. Cualquier otra cosa es 'importe', que es el default de la columna y lo
     * que hacía la extensión vieja.
     *
     * @param  mixed  $tipo_dato
     * @return string
     */
    static function normalizar_tipo_dato($tipo_dato)
    {
        if (is_scalar($tipo_dato) && strtolower(trim((string) $tipo_dato)) === self::TIPO_PESO) {
            return self::TIPO_PESO;
        }

        return self::TIPO_IMPORTE;
    }

    /**
     * Los dígitos configurados, o null (= el default por tipo) si vienen vacíos o fuera de rango
     * (de 1 a DIGITOS_MAXIMO). Fuera de rango se toma como vacío y no como error: un 0 en el input
     * numérico quiere decir "no sé", y el default es la lectura que ya funciona.
     *
     * @param  mixed  $digitos
     * @return int|null
     */
    static function normalizar_digitos($digitos)
    {
        if (is_null($digitos) || $digitos === '' || !is_numeric($digitos)) {
            return null;
        }

        $entero = (int) $digitos;

        if ((float) $digitos != $entero || $entero < 1 || $entero > self::DIGITOS_MAXIMO) {
            return null;
        }

        return $entero;
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Migración desde las extensiones viejas (comando balanzas:migrar-desde-extensiones)
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * El artículo al que la extensión vieja `balanza_bar_code` le imputaba los tickets '22':
     * estaba hardcodeado en `VenderController::check_balanza()` ("Id de la carniceria", Panchito).
     * Se conserva la misma condición para que la migración apunte exactamente a lo que hoy se usa.
     *
     * @return int
     */
    static function articulo_de_la_extension_vieja_id()
    {
        return config('app.APP_ENV') == 'local' ? 60 : 6346;
    }

    /**
     * Pasa UN dueño de las extensiones viejas a `users.tickets_de_balanza`.
     *
     *   1. Ya tiene `tickets_de_balanza` (no null, no vacío) -> no se toca. Idempotente y respeta
     *      lo que el dueño eligió (incluido 'ninguno').
     *   2. Tiene `plu_balanza_bar_code` -> 'plu', AUNQUE también tenga `balanza_bar_code` (caso La
     *      Martina: ahí la extensión de importe apuntaba a un artículo que en su base es una
     *      hamburguesa, y un código '22…' desconocido se le cobraba como hamburguesa).
     *   3. Solo `balanza_bar_code` -> 'balanzas' + la balanza '22' (importe, dígitos por defecto)
     *      apuntando al artículo hardcodeado, SOLO si ese artículo existe, no está borrado y es de
     *      ESTE dueño (en la base compartida vieja el 6346 es de un solo comercio). Si no, queda en
     *      'balanzas' sin balanzas y la salida lo dice. Si ya tiene una balanza '22', no se duplica.
     *   4. Ninguna de las dos -> no se toca.
     *
     * 🔴 NO BORRA LAS FILAS DE LAS EXTENSIONES. El pipeline del admin corre los comandos ANTES de
     * rotar el frente (`step_run_commands` -> … -> `step_update_default_version`): mientras tanto
     * atiende el código viejo sobre la misma base, y ese código lee las extensiones. Borrarlas acá
     * rompería la balanza en el frente activo durante el despliegue. Quedan como basura inofensiva
     * (el código nuevo no las lee); la limpieza es otra misión, cuando todos estén en esta versión.
     *
     * Escribe con el query builder y no con `$owner->save()`: no tiene por qué disparar los
     * observers de User (auditoría, etiquetas) ni tocar `updated_at` por una migración de datos.
     *
     * No atrapa excepciones a propósito: si algo inesperado falla, el comando tiene que salir con
     * exit distinto de 0 para que el despliegue frene antes de rotar y el cliente siga con el
     * código viejo, que todavía lee sus extensiones.
     *
     * @param  \App\Models\User  $owner     Dueño, con la relación `extencions` cargada (o se carga).
     * @param  bool              $simular   true = solo informa lo que haría, no escribe nada.
     * @return array  ['accion' => 'ya_configurado'|'sin_extensiones'|'plu'|'balanzas', 'modo' => string|null,
     *                 'tambien_importe' => bool, 'balanza' => 'creada'|'existente'|'sin_articulo'|null,
     *                 'balanza_id' => int|null, 'article_id' => int|null, 'article_name' => string|null,
     *                 'motivo' => string|null]
     */
    static function migrar_dueno_desde_extensiones($owner, $simular)
    {
        $resultado = array(
            'accion'          => null,
            'modo'            => null,
            'tambien_importe' => false,
            'balanza'         => null,
            'balanza_id'      => null,
            'article_id'      => null,
            'article_name'    => null,
            'motivo'          => null,
        );

        // 1. Ya configurado: no se toca.
        $actual = $owner->tickets_de_balanza;

        if (!is_null($actual) && trim((string) $actual) !== '') {
            $resultado['accion'] = 'ya_configurado';
            $resultado['modo'] = (string) $actual;
            return $resultado;
        }

        $slugs = array();

        foreach ($owner->extencions as $extencion) {
            $slugs[] = $extencion->slug;
        }

        $tiene_plu     = in_array(self::EXTENCION_PLU, $slugs, true);
        $tiene_importe = in_array(self::EXTENCION_IMPORTE, $slugs, true);

        // 4. Sin ninguna de las dos: no se toca.
        if (!$tiene_plu && !$tiene_importe) {
            $resultado['accion'] = 'sin_extensiones';
            return $resultado;
        }

        // 2. PLU, aunque también tenga la de importe.
        if ($tiene_plu) {

            $resultado['accion'] = 'plu';
            $resultado['modo'] = self::MODO_PLU;
            $resultado['tambien_importe'] = $tiene_importe;

            if (!$simular) {
                DB::table('users')
                    ->where('id', $owner->id)
                    ->update(array('tickets_de_balanza' => self::MODO_PLU));
            }

            return $resultado;
        }

        // 3. Solo importe.
        $resultado['accion'] = 'balanzas';
        $resultado['modo'] = self::MODO_BALANZAS;

        $article_id = self::articulo_de_la_extension_vieja_id();
        $resultado['article_id'] = $article_id;

        // Por query builder: tiene que ver también un artículo borrado (para decir POR QUÉ no se
        // usa) y no necesita nada del modelo.
        $articulo = DB::table('articles')
                        ->where('id', $article_id)
                        ->first(array('id', 'user_id', 'name', 'deleted_at'));

        if (is_null($articulo)) {
            $resultado['motivo'] = 'el artículo '.$article_id.' no existe en esta base';
        } else if (!is_null($articulo->deleted_at)) {
            $resultado['motivo'] = 'el artículo '.$article_id.' ("'.$articulo->name.'") está borrado';
        } else if ((int) $articulo->user_id !== (int) $owner->id) {
            $resultado['motivo'] = 'el artículo '.$article_id.' ("'.$articulo->name.'") es de otro comercio (user_id '.$articulo->user_id.')';
        }

        if (!is_null($articulo)) {
            $resultado['article_name'] = $articulo->name;
        }

        $existente = Balanza::where('user_id', $owner->id)
                            ->where('prefijo', self::PREFIJO_EXTENSION_VIEJA)
                            ->orderBy('id', 'ASC')
                            ->first();

        if (!is_null($existente)) {
            $resultado['balanza'] = 'existente';
            $resultado['balanza_id'] = $existente->id;
        } else if (is_null($resultado['motivo'])) {
            $resultado['balanza'] = 'creada';
        } else {
            $resultado['balanza'] = 'sin_articulo';
        }

        if ($simular) {
            return $resultado;
        }

        // El modo y la balanza van juntos: un dueño en 'balanzas' con la balanza a medio crear
        // dejaría de leer sus tickets. Si algo falla adentro, la excepción sigue de largo (ver el
        // docblock) y una segunda corrida lo retoma, porque el modo no quedó escrito.
        DB::transaction(function () use ($owner, &$resultado) {

            DB::table('users')
                ->where('id', $owner->id)
                ->update(array('tickets_de_balanza' => self::MODO_BALANZAS));

            if ($resultado['balanza'] === 'creada') {

                $balanza = Balanza::create(array(
                    'user_id'    => $owner->id,
                    'nombre'     => 'Balanza (tickets que empiezan con '.self::PREFIJO_EXTENSION_VIEJA.')',
                    'prefijo'    => self::PREFIJO_EXTENSION_VIEJA,
                    'article_id' => $resultado['article_id'],
                    'tipo_dato'  => self::TIPO_IMPORTE,
                    'digitos'    => null,
                ));

                $resultado['balanza_id'] = $balanza->id;
            }
        });

        return $resultado;
    }
}
