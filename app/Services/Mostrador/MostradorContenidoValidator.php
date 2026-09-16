<?php

namespace App\Services\Mostrador;

/**
 * Validador estricto del `contenido` que la skill /mostrador deposita en un informe
 * (§1.4 del plan de la misión modulo-ia-mostrador).
 *
 * Es un contrato entre tres partes —la skill que redacta, este API que guarda y la
 * SPA que dibuja— y por eso rechaza TODO lo que no esté en la forma: claves
 * desconocidas, tipos de bloque fuera de la lista, tonos inventados, textos más
 * largos que el tope, listas más largas que el tope. La SPA no interpreta HTML en
 * ningún texto (todo va como nodo de texto), así que acá no se sanea nada: se
 * rechaza o se acepta tal cual.
 *
 * Forma aceptada:
 *
 *   {"version": 1, "bloques": [
 *     {"tipo": "resumen",   "texto"}                                     ← siempre el primero
 *     {"tipo": "cifras",    "items": [{etiqueta, valor, detalle?, variacion?, tono?}]}   ≤ 6
 *     {"tipo": "seccion",   "titulo"}
 *     {"tipo": "parrafo",   "texto"}
 *     {"tipo": "lista",     "titulo?", "items": [{texto, tono?}]}                        ≤ 15
 *     {"tipo": "tabla",     "titulo?", "columnas": [..], "filas": [[..]]}     ≤ 8 × 30
 *     {"tipo": "articulos", "titulo?", "items": [{article_id, nombre, imagen_url?, linea_1?, linea_2?, tono?}]}  ≤ 12
 *     {"tipo": "acciones",  "titulo?", "items": [{texto, tipo, client_id?}]}             ≤ 8
 *   ]}
 *
 * `client_id` (misión mostrador-caja-vencimientos) es opcional y solo va en acciones de
 * tipo "cobrar": es el cliente al que el botón del informe le ofrece mandar el
 * recordatorio de cobro. Es una clave nueva y opcional, así que la versión sigue en 1 y
 * un contenido viejo sigue siendo válido. Que ese cliente sea del negocio del informe
 * no se puede saber acá (el validador no conoce al dueño): lo chequea
 * AdminSync\MostradorController::depositar() con client_ids().
 *
 * validar() devuelve la lista de errores legibles (vacía = válido); el controlador
 * la convierte en el 422 con `errores[]`.
 */
class MostradorContenidoValidator
{
    /** Versión del formato que se acepta. Cambios incompatibles suben este número. */
    const VERSION = 1;

    /** Cantidad de bloques permitida. */
    const MIN_BLOQUES = 1;
    const MAX_BLOQUES = 40;

    /** Tipos de bloque. */
    const TIPOS = ['resumen', 'cifras', 'seccion', 'parrafo', 'lista', 'tabla', 'articulos', 'acciones'];

    /** Tonos. */
    const TONOS = ['ok', 'alerta', 'neutro'];

    /** Tipos de acción. */
    const TIPOS_ACCION = ['cobrar', 'comprar', 'mover', 'ofertar', 'revisar', 'contactar'];

    /** El único tipo de acción que lleva `client_id`. */
    const TIPO_ACCION_COBRAR = 'cobrar';

    /** Topes de texto. */
    const MAX_RESUMEN   = 600;
    const MAX_PARRAFO   = 1200;
    const MAX_ETIQUETA  = 80;
    const MAX_TITULO    = 80;
    const MAX_VALOR     = 40;
    const MAX_DETALLE   = 120;
    const MAX_VARIACION = 120;
    const MAX_ITEM_TEXTO = 300;
    const MAX_CELDA     = 120;
    const MAX_NOMBRE_ARTICULO = 200;
    const MAX_LINEA_ARTICULO  = 120;

    /** Topes de ítems. */
    const MAX_CIFRAS    = 6;
    const MAX_LISTA     = 15;
    const MAX_COLUMNAS  = 8;
    const MAX_FILAS     = 30;
    const MAX_ARTICULOS = 12;
    const MAX_ACCIONES  = 8;

    /** @var array<int, string> Errores acumulados de la validación en curso */
    protected $errores = [];

    /**
     * Valida el contenido y devuelve los errores encontrados (vacío = válido).
     *
     * @param mixed $contenido Lo que llegó en el body (ya decodificado)
     * @return array<int, string>
     */
    public function validar($contenido): array
    {
        $this->errores = [];

        if (!is_array($contenido) || $this->es_lista($contenido)) {
            $this->error('contenido', 'tiene que ser un objeto con "version" y "bloques"');

            return $this->errores;
        }

        $this->solo_claves($contenido, ['version', 'bloques'], 'contenido');

        if (!array_key_exists('version', $contenido) || $contenido['version'] !== self::VERSION) {
            $this->error('contenido.version', 'tiene que ser ' . self::VERSION);
        }

        if (!array_key_exists('bloques', $contenido) || !is_array($contenido['bloques']) || !$this->es_lista($contenido['bloques'])) {
            $this->error('contenido.bloques', 'tiene que ser una lista de bloques');

            return $this->errores;
        }

        $bloques = $contenido['bloques'];
        $cantidad = count($bloques);

        if ($cantidad < self::MIN_BLOQUES || $cantidad > self::MAX_BLOQUES) {
            $this->error('contenido.bloques', 'tiene que tener entre ' . self::MIN_BLOQUES . ' y ' . self::MAX_BLOQUES . ' bloques (tiene ' . $cantidad . ')');
        }

        foreach ($bloques as $indice => $bloque) {
            $this->validar_bloque($bloque, 'bloques[' . $indice . ']', $indice === 0);
        }

        return $this->errores;
    }

    /**
     * Los client_id de todas las acciones del contenido, sin repetidos y en el orden en que
     * aparecen. Solo junta los que tienen la forma válida (enteros positivos): lo demás ya lo
     * rechaza validar().
     *
     * Es lo que usa AdminSync\MostradorController::depositar() para chequear que cada cliente
     * sea del dueño del informe, que es lo que este validador no puede saber.
     *
     * @param array $contenido
     * @return array<int, int>
     */
    public static function client_ids(array $contenido): array
    {
        $ids = [];

        $bloques = isset($contenido['bloques']) && is_array($contenido['bloques']) ? $contenido['bloques'] : [];

        foreach ($bloques as $bloque) {
            if (!is_array($bloque) || !isset($bloque['tipo']) || $bloque['tipo'] !== 'acciones') {
                continue;
            }

            $items = isset($bloque['items']) && is_array($bloque['items']) ? $bloque['items'] : [];

            foreach ($items as $item) {
                if (!is_array($item) || !isset($item['client_id']) || !is_int($item['client_id']) || $item['client_id'] <= 0) {
                    continue;
                }

                if (!in_array($item['client_id'], $ids, true)) {
                    $ids[] = $item['client_id'];
                }
            }
        }

        return $ids;
    }

    /**
     * Un bloque: tipo válido, y la forma de ese tipo.
     *
     * @param mixed $bloque
     * @param string $ruta
     * @param bool $es_el_primero
     * @return void
     */
    protected function validar_bloque($bloque, string $ruta, bool $es_el_primero)
    {
        if (!is_array($bloque) || $this->es_lista($bloque)) {
            $this->error($ruta, 'tiene que ser un objeto con "tipo"');

            return;
        }

        $tipo = isset($bloque['tipo']) ? $bloque['tipo'] : null;

        if (!is_string($tipo) || !in_array($tipo, self::TIPOS, true)) {
            $this->error($ruta . '.tipo', $this->describir($tipo) . ' no es un tipo de bloque válido (' . implode(', ', self::TIPOS) . ')');

            return;
        }

        if ($es_el_primero && $tipo !== 'resumen') {
            $this->error($ruta . '.tipo', 'el primer bloque tiene que ser "resumen" (es "' . $tipo . '")');
        }

        switch ($tipo) {
            case 'resumen':
                $this->solo_claves($bloque, ['tipo', 'texto'], $ruta);
                $this->texto_obligatorio($bloque, 'texto', self::MAX_RESUMEN, $ruta);
                break;

            case 'parrafo':
                $this->solo_claves($bloque, ['tipo', 'texto'], $ruta);
                $this->texto_obligatorio($bloque, 'texto', self::MAX_PARRAFO, $ruta);
                break;

            case 'seccion':
                $this->solo_claves($bloque, ['tipo', 'titulo'], $ruta);
                $this->texto_obligatorio($bloque, 'titulo', self::MAX_TITULO, $ruta);
                break;

            case 'cifras':
                $this->solo_claves($bloque, ['tipo', 'items'], $ruta);
                $this->items($bloque, $ruta, self::MAX_CIFRAS, function ($item, $ruta_item) {
                    $this->solo_claves($item, ['etiqueta', 'valor', 'detalle', 'variacion', 'tono'], $ruta_item);
                    $this->texto_obligatorio($item, 'etiqueta', self::MAX_ETIQUETA, $ruta_item);
                    $this->texto_obligatorio($item, 'valor', self::MAX_VALOR, $ruta_item);
                    $this->texto_opcional($item, 'detalle', self::MAX_DETALLE, $ruta_item);
                    $this->texto_opcional($item, 'variacion', self::MAX_VARIACION, $ruta_item);
                    $this->tono($item, $ruta_item);
                });
                break;

            case 'lista':
                $this->solo_claves($bloque, ['tipo', 'titulo', 'items'], $ruta);
                $this->texto_opcional($bloque, 'titulo', self::MAX_TITULO, $ruta);
                $this->items($bloque, $ruta, self::MAX_LISTA, function ($item, $ruta_item) {
                    $this->solo_claves($item, ['texto', 'tono'], $ruta_item);
                    $this->texto_obligatorio($item, 'texto', self::MAX_ITEM_TEXTO, $ruta_item);
                    $this->tono($item, $ruta_item);
                });
                break;

            case 'tabla':
                $this->solo_claves($bloque, ['tipo', 'titulo', 'columnas', 'filas'], $ruta);
                $this->texto_opcional($bloque, 'titulo', self::MAX_TITULO, $ruta);
                $this->tabla($bloque, $ruta);
                break;

            case 'articulos':
                $this->solo_claves($bloque, ['tipo', 'titulo', 'items'], $ruta);
                $this->texto_opcional($bloque, 'titulo', self::MAX_TITULO, $ruta);
                $this->items($bloque, $ruta, self::MAX_ARTICULOS, function ($item, $ruta_item) {
                    $this->solo_claves($item, ['article_id', 'nombre', 'imagen_url', 'linea_1', 'linea_2', 'tono'], $ruta_item);

                    if (!isset($item['article_id']) || !is_int($item['article_id']) || $item['article_id'] <= 0) {
                        $this->error($ruta_item . '.article_id', 'tiene que ser un entero positivo');
                    }

                    $this->texto_obligatorio($item, 'nombre', self::MAX_NOMBRE_ARTICULO, $ruta_item);
                    $this->imagen_url($item, $ruta_item);
                    $this->texto_opcional($item, 'linea_1', self::MAX_LINEA_ARTICULO, $ruta_item);
                    $this->texto_opcional($item, 'linea_2', self::MAX_LINEA_ARTICULO, $ruta_item);
                    $this->tono($item, $ruta_item);
                });
                break;

            case 'acciones':
                $this->solo_claves($bloque, ['tipo', 'titulo', 'items'], $ruta);
                $this->texto_opcional($bloque, 'titulo', self::MAX_TITULO, $ruta);
                $this->items($bloque, $ruta, self::MAX_ACCIONES, function ($item, $ruta_item) {
                    $this->solo_claves($item, ['texto', 'tipo', 'client_id'], $ruta_item);
                    $this->texto_obligatorio($item, 'texto', self::MAX_ITEM_TEXTO, $ruta_item);

                    $tipo_accion = isset($item['tipo']) ? $item['tipo'] : null;

                    if (!is_string($tipo_accion) || !in_array($tipo_accion, self::TIPOS_ACCION, true)) {
                        $this->error($ruta_item . '.tipo', $this->describir($tipo_accion) . ' no es un tipo de acción válido (' . implode(', ', self::TIPOS_ACCION) . ')');
                    }

                    $this->client_id($item, $tipo_accion, $ruta_item);
                });
                break;
        }
    }

    /**
     * `client_id` opcional de una acción (puede faltar o ser null): si viene, un entero
     * positivo y solo en una acción de tipo "cobrar". En cualquier otro tipo no hay botón que
     * lo use, y aceptarlo dejaría viajar un id que nadie mira.
     *
     * @param array $item
     * @param mixed $tipo_accion
     * @param string $ruta
     * @return void
     */
    protected function client_id(array $item, $tipo_accion, string $ruta)
    {
        if (!array_key_exists('client_id', $item) || is_null($item['client_id'])) {
            return;
        }

        if (!is_int($item['client_id']) || $item['client_id'] <= 0) {
            $this->error($ruta . '.client_id', 'tiene que ser un entero positivo');
        }

        if ($tipo_accion !== self::TIPO_ACCION_COBRAR) {
            $this->error($ruta . '.client_id', 'solo va en acciones de tipo "' . self::TIPO_ACCION_COBRAR . '"');
        }
    }

    /**
     * Los ítems de un bloque: lista no vacía, con tope, y cada uno un objeto que
     * valida el callback.
     *
     * @param array $bloque
     * @param string $ruta
     * @param int $maximo
     * @param callable $validar_item function(array $item, string $ruta_item)
     * @return void
     */
    protected function items(array $bloque, string $ruta, int $maximo, callable $validar_item)
    {
        if (!isset($bloque['items']) || !is_array($bloque['items']) || !$this->es_lista($bloque['items'])) {
            $this->error($ruta . '.items', 'tiene que ser una lista');

            return;
        }

        $cantidad = count($bloque['items']);

        if ($cantidad < 1 || $cantidad > $maximo) {
            $this->error($ruta . '.items', 'tiene que tener entre 1 y ' . $maximo . ' ítems (tiene ' . $cantidad . ')');
        }

        foreach ($bloque['items'] as $indice => $item) {
            $ruta_item = $ruta . '.items[' . $indice . ']';

            if (!is_array($item) || $this->es_lista($item)) {
                $this->error($ruta_item, 'tiene que ser un objeto');
                continue;
            }

            $validar_item($item, $ruta_item);
        }
    }

    /**
     * Una tabla: columnas (1 a 8 textos) y filas (1 a 30 listas de textos, cada una con
     * tantas celdas como columnas).
     *
     * @param array $bloque
     * @param string $ruta
     * @return void
     */
    protected function tabla(array $bloque, string $ruta)
    {
        $columnas = isset($bloque['columnas']) ? $bloque['columnas'] : null;

        if (!is_array($columnas) || !$this->es_lista($columnas) || count($columnas) < 1 || count($columnas) > self::MAX_COLUMNAS) {
            $this->error($ruta . '.columnas', 'tiene que ser una lista de 1 a ' . self::MAX_COLUMNAS . ' textos');

            return;
        }

        foreach ($columnas as $indice => $columna) {
            if (!$this->es_texto($columna, self::MAX_ETIQUETA)) {
                $this->error($ruta . '.columnas[' . $indice . ']', 'tiene que ser un texto de hasta ' . self::MAX_ETIQUETA . ' caracteres');
            }
        }

        $filas = isset($bloque['filas']) ? $bloque['filas'] : null;

        if (!is_array($filas) || !$this->es_lista($filas) || count($filas) < 1 || count($filas) > self::MAX_FILAS) {
            $this->error($ruta . '.filas', 'tiene que ser una lista de 1 a ' . self::MAX_FILAS . ' filas');

            return;
        }

        foreach ($filas as $indice => $fila) {
            $ruta_fila = $ruta . '.filas[' . $indice . ']';

            if (!is_array($fila) || !$this->es_lista($fila) || count($fila) !== count($columnas)) {
                $this->error($ruta_fila, 'tiene que tener ' . count($columnas) . ' celdas, una por columna');
                continue;
            }

            foreach ($fila as $indice_celda => $celda) {
                if (!$this->es_texto($celda, self::MAX_CELDA, true)) {
                    $this->error($ruta_fila . '[' . $indice_celda . ']', 'tiene que ser un texto de hasta ' . self::MAX_CELDA . ' caracteres');
                }
            }
        }
    }

    /**
     * Texto obligatorio, no vacío, con tope.
     *
     * @param array $objeto
     * @param string $clave
     * @param int $maximo
     * @param string $ruta
     * @return void
     */
    protected function texto_obligatorio(array $objeto, string $clave, int $maximo, string $ruta)
    {
        if (!array_key_exists($clave, $objeto) || !$this->es_texto($objeto[$clave], $maximo)) {
            $this->error($ruta . '.' . $clave, 'es obligatorio y tiene que ser un texto de 1 a ' . $maximo . ' caracteres');
        }
    }

    /**
     * Texto opcional (puede faltar o ser null), con tope.
     *
     * @param array $objeto
     * @param string $clave
     * @param int $maximo
     * @param string $ruta
     * @return void
     */
    protected function texto_opcional(array $objeto, string $clave, int $maximo, string $ruta)
    {
        if (!array_key_exists($clave, $objeto) || is_null($objeto[$clave])) {
            return;
        }

        if (!$this->es_texto($objeto[$clave], $maximo, true)) {
            $this->error($ruta . '.' . $clave, 'tiene que ser un texto de hasta ' . $maximo . ' caracteres, o null');
        }
    }

    /**
     * `tono` opcional dentro de la lista de tonos.
     *
     * @param array $objeto
     * @param string $ruta
     * @return void
     */
    protected function tono(array $objeto, string $ruta)
    {
        if (!array_key_exists('tono', $objeto) || is_null($objeto['tono'])) {
            return;
        }

        if (!is_string($objeto['tono']) || !in_array($objeto['tono'], self::TONOS, true)) {
            $this->error($ruta . '.tono', $this->describir($objeto['tono']) . ' no es un tono válido (' . implode(', ', self::TONOS) . ')');
        }
    }

    /**
     * `imagen_url` opcional: null o una URL http(s).
     *
     * @param array $objeto
     * @param string $ruta
     * @return void
     */
    protected function imagen_url(array $objeto, string $ruta)
    {
        if (!array_key_exists('imagen_url', $objeto) || is_null($objeto['imagen_url'])) {
            return;
        }

        $url = $objeto['imagen_url'];

        $es_http = is_string($url)
            && mb_strlen($url) <= 2000
            && preg_match('#^https?://#i', $url) === 1
            && filter_var($url, FILTER_VALIDATE_URL) !== false;

        if (!$es_http) {
            $this->error($ruta . '.imagen_url', 'tiene que ser una URL http(s) o null');
        }
    }

    /**
     * Rechaza toda clave que no esté en la lista permitida del objeto.
     *
     * @param array $objeto
     * @param array $permitidas
     * @param string $ruta
     * @return void
     */
    protected function solo_claves(array $objeto, array $permitidas, string $ruta)
    {
        foreach (array_keys($objeto) as $clave) {
            if (!in_array((string) $clave, $permitidas, true)) {
                $this->error($ruta . '.' . $clave, 'clave desconocida (se aceptan: ' . implode(', ', $permitidas) . ')');
            }
        }
    }

    /**
     * true si el valor es un string con largo dentro del tope (y no vacío, salvo que
     * se permita vacío).
     *
     * @param mixed $valor
     * @param int $maximo
     * @param bool $permitir_vacio
     * @return bool
     */
    protected function es_texto($valor, int $maximo, bool $permitir_vacio = false): bool
    {
        if (!is_string($valor)) {
            return false;
        }

        $largo = mb_strlen($valor);

        if ($largo > $maximo) {
            return false;
        }

        if (!$permitir_vacio && trim($valor) === '') {
            return false;
        }

        return true;
    }

    /**
     * true si el array es una lista (claves 0..n-1), false si es un objeto.
     *
     * @param array $valor
     * @return bool
     */
    protected function es_lista(array $valor): bool
    {
        if (empty($valor)) {
            return true;
        }

        return array_keys($valor) === range(0, count($valor) - 1);
    }

    /**
     * Cómo se nombra un valor inválido en el mensaje de error.
     *
     * @param mixed $valor
     * @return string
     */
    protected function describir($valor): string
    {
        if (is_null($valor)) {
            return '(sin valor)';
        }

        if (is_string($valor)) {
            return '"' . mb_substr($valor, 0, 40) . '"';
        }

        return '(' . gettype($valor) . ')';
    }

    /**
     * Acumula un error legible: "ruta: mensaje".
     *
     * @param string $ruta
     * @param string $mensaje
     * @return void
     */
    protected function error(string $ruta, string $mensaje)
    {
        $this->errores[] = $ruta . ': ' . $mensaje;
    }
}
