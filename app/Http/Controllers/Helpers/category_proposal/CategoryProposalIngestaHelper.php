<?php

namespace App\Http\Controllers\Helpers\category_proposal;

use App\Models\CategoryProposal;
use App\Models\CategoryProposalItem;
use App\Models\CategoryProposalRun;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lo que la skill /categorizar ESCRIBE en las tablas de propuestas por `admin-sync/catalogo/*`: crear
 * la corrida con sus sistemas y árboles, cargar las asignaciones de artículos por lotes, decir qué
 * falta, publicar la corrida al dueño y descartarla (misión categorizacion-tres-modelos, 5/10/2026).
 * Contrato A del plan, §5.3 a §5.8.
 *
 * 🔴 NADA de esto toca `categories` ni `articles`: solo las cuatro tablas de propuestas. Los árboles
 * son staging; las categorías reales se crean recién cuando el dueño elige un sistema.
 *
 * 🔴 TODO por dueño. El dueño llega resuelto por el controlador (`AsistenteCanalHelper::dueno()`) y
 * cada consulta lleva su `user_id`. Un `run_id` que llega en la ruta se cruza SIEMPRE con ese dueño:
 * el de otro comercio se contesta igual que uno inexistente (404 `no_encontrado`, nunca un 403 que
 * confirme que existe). Todo `articulo_id` que llega en el cuerpo se verifica contra los artículos
 * del dueño antes de guardarse.
 *
 * Forma de las respuestas: cada método devuelve `['status' => int, 'body' => array]` y el controlador
 * solo lo traduce a HTTP (los controladores no llevan lógica). Los errores de dominio van como
 * `{"error": "<código para la máquina>", "message": "<texto en español>"}` y los de validación como
 * `{"error": "validacion", "message": "...", "detalle": {"campo": ["..."]}}` (armado a mano:
 * `$request->validate()` de Laravel contestaría otra forma, `Handler::invalidJson`).
 *
 * Idempotencia y concurrencia:
 *   - `crear` se serializa con un candado sobre la fila del dueño en `users`: dos pedidos a la vez no
 *     pueden dejar dos corridas vigentes.
 *   - `asignaciones`, `listo` y `descartar` toman `lockForUpdate()` sobre la fila de la corrida y leen
 *     el estado de la fila BLOQUEADA: un `listo` concurrente no se cuela entre la verificación del
 *     estado y la escritura de las asignaciones.
 *   - Reenviar un artículo REEMPLAZA su ítem (`upsert` por `(proposal_id, article_id)`).
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class CategoryProposalIngestaHelper
{
    /** Las claves que puede llevar una propuesta dentro de una corrida (la tarjeta). */
    const CLAVES = ['A', 'B', 'C', CategoryProposal::CLAVE_MANTENER];

    /** Los tipos de propuesta. */
    const TIPOS = [CategoryProposal::TIPO_NUEVA, CategoryProposal::TIPO_MANTENER];

    /** Largos de las columnas de `category_proposals` / `category_proposal_items` que el contrato no puede pasar. */
    const LARGO_NOMBRE_DE_PROPUESTA = 120;
    const LARGO_RESUMEN             = 255;
    const LARGO_MOTIVO              = 255;

    /**
     * Largo máximo de la descripción de una propuesta. La columna es TEXT (65.535 bytes) y un
     * carácter de utf8mb4 pesa hasta 4: con 10.000 caracteres sobra y nada puede romper la fila.
     */
    const LARGO_DESCRIPCION = 10000;

    /** Una propuesta nueva necesita al menos dos nodos (categorías o subcategorías) para ser un sistema. */
    const NODOS_MINIMOS = 2;

    /** De a cuántos nodos se inserta en la base (el INSERT multi-fila no tiene por qué ser gigante). */
    const LOTE_DE_NODOS = 200;

    /** Cuántos artículos devuelve `pendientes` si la skill no pide una cantidad. */
    const PENDIENTES_POR_DEFECTO = 300;

    /** Tope de mensajes de error que devuelve una validación (con 400 nombres repetidos no se manda un JSON enorme). */
    const MAXIMO_DE_ERRORES = 40;

    // ------------------------------------------------------------------------------------------
    // Respuestas
    // ------------------------------------------------------------------------------------------

    /**
     * El resultado que el controlador traduce a HTTP.
     *
     * @param  int   $status
     * @param  array $body
     * @return array  ['status' => int, 'body' => array]
     */
    protected static function respuesta($status, array $body)
    {
        return ['status' => $status, 'body' => $body];
    }

    /**
     * Un error de dominio: `{"error": código, "message": texto, ...extra}`.
     *
     * @param  int    $status
     * @param  string $codigo   Para la máquina (la skill lo lee).
     * @param  string $mensaje  En español, para mostrar.
     * @param  array  $extra    Claves que se suman al cuerpo (`run_id`, `estado`, `faltan`...).
     * @return array
     */
    protected static function error($status, $codigo, $mensaje, array $extra = [])
    {
        return self::respuesta($status, array_merge(['error' => $codigo, 'message' => $mensaje], $extra));
    }

    /**
     * El 422 de validación con la forma del contrato: `{"error":"validacion","message":...,"detalle":{campo:[..]}}`.
     * El `message` lleva el primer error para que la skill, que imprime el cuerpo de todo no-2xx, lo
     * muestre sin tener que abrir el detalle.
     *
     * @param  array $detalle  campo => [mensajes]
     * @return array
     */
    protected static function error_de_validacion(array $detalle)
    {
        // Primer mensaje de error de la lista: va en el `message` para que se lea sin abrir el `detalle`.
        $primero = '';

        foreach ($detalle as $mensajes) {

            $primero = (string) reset($mensajes);

            break;
        }

        return self::error(422, 'validacion', trim('Los datos enviados no son válidos. '.$primero), ['detalle' => $detalle]);
    }

    /**
     * El 404 de una corrida que no existe o es de otro comercio (las dos cosas, la MISMA respuesta).
     * Lleva `error` a propósito: la skill traduce un 404 pelado a "este cliente está en una versión
     * sin categorización", y este no lo es.
     *
     * @return array
     */
    protected static function no_encontrada()
    {
        return self::error(404, 'no_encontrado', 'No se encontró esa propuesta de categorías.');
    }

    /**
     * Suma un mensaje a los errores de validación, con tope: pasado `MAXIMO_DE_ERRORES` se descartan
     * los que siguen.
     *
     * @param  array  &$detalle  campo => [mensajes]
     * @param  string $campo
     * @param  string $mensaje
     * @return void
     */
    protected static function agregar_error(array &$detalle, $campo, $mensaje)
    {
        // Cuántos mensajes de error hay ya anotados (sumando todos los campos).
        $cantidad = 0;

        foreach ($detalle as $mensajes) {

            $cantidad += count($mensajes);
        }

        if ($cantidad >= self::MAXIMO_DE_ERRORES) {

            return;
        }

        $detalle[$campo][] = $mensaje;
    }

    // ------------------------------------------------------------------------------------------
    // Utilidades de texto
    // ------------------------------------------------------------------------------------------

    /**
     * El texto de una clave del cuerpo, sin espacios en los bordes. '' si falta, es null o no es un
     * texto (un número o un arreglo no son un nombre).
     *
     * @param  array  $datos
     * @param  string $clave
     * @return string
     */
    protected static function texto_de($datos, $clave)
    {
        if (!is_array($datos) || !isset($datos[$clave]) || !is_string($datos[$clave])) {

            return '';
        }

        return trim($datos[$clave]);
    }

    /**
     * Un texto para mostrar dentro de un mensaje de error: recortado, para no devolver de vuelta un
     * texto enorme que mandó la skill.
     *
     * @param  string $texto
     * @param  int    $largo
     * @return string
     */
    protected static function recortado($texto, $largo = 40)
    {
        // Siempre texto: el llamador puede pasar un número o null.
        $texto = (string) $texto;

        return mb_strlen($texto) > $largo ? mb_substr($texto, 0, $largo).'…' : $texto;
    }

    /**
     * Un campo de texto OPCIONAL de una propuesta (`resumen`, `descripcion`): null si falta o viene
     * vacío; error si no es un texto o pasa el largo de la columna.
     *
     * @param  array  $datos
     * @param  string $clave
     * @param  int    $largo_maximo
     * @param  string $campo     El nombre del campo para el error (`propuestas.0.resumen`).
     * @param  array  &$detalle
     * @return string|null
     */
    protected static function texto_opcional($datos, $clave, $largo_maximo, $campo, array &$detalle)
    {
        if (!isset($datos[$clave])) {

            return null;
        }

        if (!is_string($datos[$clave])) {

            self::agregar_error($detalle, $campo, 'Tiene que ser un texto.');

            return null;
        }

        // El texto sin espacios en los bordes (vacío es lo mismo que ausente).
        $texto = trim($datos[$clave]);

        if (mb_strlen($texto) > $largo_maximo) {

            self::agregar_error($detalle, $campo, 'Tiene '.mb_strlen($texto)." caracteres y el máximo es {$largo_maximo}.");

            return null;
        }

        return $texto === '' ? null : $texto;
    }

    // ------------------------------------------------------------------------------------------
    // Validación del cuerpo de `crear`
    // ------------------------------------------------------------------------------------------

    /**
     * Valida la FORMA de `POST categorias/propuestas` sin tocar la base y devuelve lo normalizado.
     *
     * Cada propuesta sale como `['clave','tipo','nombre','resumen','descripcion','arbol']` donde
     * `arbol` es la lista de categorías normalizadas (`['nombre','clave','subs' => [['nombre','clave']]]`),
     * vacía en las `mantener` (el servidor arma esos nodos con las categorías existentes).
     *
     * @param  array $cuerpo
     * @return array  ['propuestas' => [...], 'reemplazar' => bool, 'detalle' => campo => [mensajes]]
     */
    protected static function validar_propuestas(array $cuerpo)
    {
        // Los errores que se van juntando, y las propuestas ya normalizadas.
        $detalle    = [];
        $propuestas = [];

        $crudas = isset($cuerpo['propuestas']) ? $cuerpo['propuestas'] : null;
        $maximo = (int) config('catalogo_ia.propuestas_por_corrida_maximo');

        if (!is_array($crudas) || count($crudas) < 1) {

            self::agregar_error($detalle, 'propuestas', "Tiene que haber entre 1 y {$maximo} propuestas.");
        } elseif (count($crudas) > $maximo) {

            self::agregar_error($detalle, 'propuestas', 'Hay '.count($crudas)." propuestas y el máximo es {$maximo}.");
        } else {

            // Las claves ya vistas, para detectar una repetida.
            $claves_vistas = [];

            foreach (array_values($crudas) as $posicion => $cruda) {

                // La propuesta normalizada, o null si ni siquiera es un objeto (el error ya quedó anotado).
                $propuesta = self::validar_propuesta($cruda, $posicion, $claves_vistas, $detalle);

                if (!is_null($propuesta)) {

                    $propuestas[] = $propuesta;
                }
            }
        }

        // `reemplazar` llega como booleano de JSON, pero se aceptan "true" y 1 por si lo manda otra mano.
        $reemplazar = isset($cuerpo['reemplazar']) && filter_var($cuerpo['reemplazar'], FILTER_VALIDATE_BOOLEAN);

        return ['propuestas' => $propuestas, 'reemplazar' => $reemplazar, 'detalle' => $detalle];
    }

    /**
     * Valida una propuesta del cuerpo.
     *
     * @param  mixed $cruda
     * @param  int   $posicion       Lugar en la lista (para el nombre del campo en los errores).
     * @param  array &$claves_vistas
     * @param  array &$detalle
     * @return array|null  null si ni siquiera es un objeto.
     */
    protected static function validar_propuesta($cruda, $posicion, array &$claves_vistas, array &$detalle)
    {
        // Nombre del campo en los errores de esta propuesta (`propuestas.0`, `propuestas.1`...).
        $campo = "propuestas.{$posicion}";

        if (!is_array($cruda)) {

            self::agregar_error($detalle, $campo, 'Cada propuesta tiene que ser un objeto con clave, tipo y nombre.');

            return null;
        }

        // La clave de la tarjeta: A, B, C o mantener, sin repetir dentro del pedido.
        $clave = self::texto_de($cruda, 'clave');

        if (!in_array($clave, self::CLAVES, true)) {

            self::agregar_error($detalle, "{$campo}.clave", 'La clave tiene que ser A, B, C o mantener.');
        } elseif (isset($claves_vistas[$clave])) {

            self::agregar_error($detalle, "{$campo}.clave", "La clave {$clave} está repetida en este pedido.");
        } else {

            $claves_vistas[$clave] = true;
        }

        // El tipo: sin decir nada es `nueva` (el default de la columna).
        $tipo = isset($cruda['tipo']) ? self::texto_de($cruda, 'tipo') : CategoryProposal::TIPO_NUEVA;

        if (!in_array($tipo, self::TIPOS, true)) {

            self::agregar_error($detalle, "{$campo}.tipo", 'El tipo tiene que ser nueva o mantener.');
        } elseif (in_array($clave, self::CLAVES, true)
            && ($tipo === CategoryProposal::TIPO_MANTENER) !== ($clave === CategoryProposal::CLAVE_MANTENER)) {

            // La clave `mantener` es de la tarjeta "Mantener las mías" y de ninguna otra: el SPA y el
            // aplicar reconocen esa tarjeta por la clave.
            self::agregar_error($detalle, "{$campo}.clave", 'La clave "mantener" es solo para las propuestas de tipo mantener, y las de tipo mantener llevan esa clave.');
        }

        // El nombre de la tarjeta (obligatorio, cabe en la columna).
        $nombre = self::texto_de($cruda, 'nombre');

        if ($nombre === '') {

            self::agregar_error($detalle, "{$campo}.nombre", 'El nombre de la propuesta es obligatorio.');
        } elseif (mb_strlen($nombre) > self::LARGO_NOMBRE_DE_PROPUESTA) {

            self::agregar_error($detalle, "{$campo}.nombre", 'El nombre de la propuesta tiene '.mb_strlen($nombre).' caracteres y el máximo es '.self::LARGO_NOMBRE_DE_PROPUESTA.'.');
        }

        // Resumen y descripción son opcionales: null si faltan o vienen vacíos.
        $resumen     = self::texto_opcional($cruda, 'resumen', self::LARGO_RESUMEN, "{$campo}.resumen", $detalle);
        $descripcion = self::texto_opcional($cruda, 'descripcion', self::LARGO_DESCRIPCION, "{$campo}.descripcion", $detalle);

        // El árbol: las propuestas nuevas lo traen; las "mantener" NO (el servidor lo arma).
        $arbol = [];

        if ($tipo === CategoryProposal::TIPO_MANTENER) {

            if (isset($cruda['arbol']) && !(is_array($cruda['arbol']) && count($cruda['arbol']) === 0)) {

                self::agregar_error($detalle, "{$campo}.arbol", 'Una propuesta de tipo mantener no lleva árbol: el servidor lo arma con las categorías que el dueño ya tiene.');
            }
        } elseif ($tipo === CategoryProposal::TIPO_NUEVA) {

            $arbol = self::validar_arbol(isset($cruda['arbol']) ? $cruda['arbol'] : null, "{$campo}.arbol", $detalle);
        }

        return [
            'clave'       => $clave,
            'tipo'        => $tipo,
            'nombre'      => $nombre,
            'resumen'     => $resumen,
            'descripcion' => $descripcion,
            'arbol'       => $arbol,
        ];
    }

    /**
     * Valida el árbol de una propuesta nueva: entre 2 y 400 nodos, cada nombre usable y de a lo
     * sumo 128 caracteres, y sin nombres repetidos (normalizados) dentro de un mismo nivel.
     *
     * Un nodo es una categoría o una subcategoría; las subcategorías se cuentan aparte de su padre.
     *
     * @param  mixed  $arbol
     * @param  string $campo     `propuestas.0.arbol`
     * @param  array  &$detalle
     * @return array  Las categorías normalizadas: [['nombre', 'clave', 'subs' => [['nombre', 'clave']]]].
     */
    protected static function validar_arbol($arbol, $campo, array &$detalle)
    {
        // Tope de nodos (categorías y subcategorías juntas) de una propuesta nueva.
        $maximo_de_nodos = (int) config('catalogo_ia.nodos_por_propuesta_maximo');

        if (!is_array($arbol) || count($arbol) === 0) {

            self::agregar_error($detalle, $campo, 'Una propuesta nueva necesita un árbol: una lista de categorías, cada una con sus subcategorías.');

            return [];
        }

        // Lo que se va a devolver, y cuántos nodos hay en total.
        $normalizado = [];
        $nodos       = 0;

        // Las claves de las categorías ya vistas (nivel 1).
        $claves_de_categorias = [];

        foreach (array_values($arbol) as $j => $categoria) {

            // Nombre del campo de esta categoría en los errores (`propuestas.0.arbol.2`).
            $campo_de_categoria = "{$campo}.{$j}";

            if (!is_array($categoria)) {

                self::agregar_error($detalle, $campo_de_categoria, 'Cada categoría tiene que ser un objeto con nombre y, si tiene, subcategorias.');

                continue;
            }

            $nodos++;

            // El nombre ya validado (`nombre` y `clave` de comparación), o null si no sirve.
            $valido = self::validar_nombre(isset($categoria['nombre']) ? $categoria['nombre'] : null, "{$campo_de_categoria}.nombre", $detalle);

            if (!is_null($valido) && isset($claves_de_categorias[$valido['clave']])) {

                self::agregar_error($detalle, "{$campo_de_categoria}.nombre", "La categoría '".self::recortado($valido['nombre'])."' está repetida (los nombres se comparan sin mayúsculas ni acentos).");
            }

            if (!is_null($valido)) {

                $claves_de_categorias[$valido['clave']] = true;
            }

            // Las subcategorías de esta categoría (opcionales) y sus claves ya vistas (nivel 2).
            $subs                 = isset($categoria['subcategorias']) ? $categoria['subcategorias'] : [];
            $subs_normalizadas    = [];
            $claves_de_subs       = [];

            if (!is_array($subs)) {

                self::agregar_error($detalle, "{$campo_de_categoria}.subcategorias", 'Tiene que ser una lista de nombres.');

                $subs = [];
            }

            foreach (array_values($subs) as $k => $sub) {

                $nodos++;

                // La subcategoría ya validada, o null si no sirve (el error ya quedó anotado).
                $sub_valida = self::validar_nombre($sub, "{$campo_de_categoria}.subcategorias.{$k}", $detalle);

                if (is_null($sub_valida)) {

                    continue;
                }

                if (isset($claves_de_subs[$sub_valida['clave']])) {

                    self::agregar_error($detalle, "{$campo_de_categoria}.subcategorias.{$k}", "La subcategoría '".self::recortado($sub_valida['nombre'])."' está repetida dentro de la misma categoría.");

                    continue;
                }

                $claves_de_subs[$sub_valida['clave']] = true;
                $subs_normalizadas[] = $sub_valida;
            }

            if (!is_null($valido)) {

                $normalizado[] = ['nombre' => $valido['nombre'], 'clave' => $valido['clave'], 'subs' => $subs_normalizadas];
            }

            // Pasado el tope no se sigue mirando: ya hay un error y el resto es trabajo tirado.
            if ($nodos > $maximo_de_nodos) {

                break;
            }
        }

        if ($nodos < self::NODOS_MINIMOS) {

            self::agregar_error($detalle, $campo, 'Una propuesta nueva necesita al menos '.self::NODOS_MINIMOS." nodos (categorías o subcategorías) y trae {$nodos}.");
        }

        if ($nodos > $maximo_de_nodos) {

            self::agregar_error($detalle, $campo, "La propuesta tiene más de {$maximo_de_nodos} nodos (categorías y subcategorías juntas) y ese es el máximo.");
        }

        return $normalizado;
    }

    /**
     * Valida el nombre de una categoría o subcategoría y devuelve `['nombre', 'clave']` (la clave es
     * el nombre normalizado para comparar), o null si no sirve (después de anotar el error).
     *
     * No sirve si no es un texto, queda vacío al normalizar, pasa el largo de la columna o es
     * `La de siempre`: la tienda esconde por nombre una categoría así, y crearla dejaría los
     * artículos sin aparecer.
     *
     * @param  mixed  $valor
     * @param  string $campo
     * @param  array  &$detalle
     * @return array|null
     */
    protected static function validar_nombre($valor, $campo, array &$detalle)
    {
        if (!is_string($valor) || trim($valor) === '') {

            self::agregar_error($detalle, $campo, 'El nombre es obligatorio y tiene que ser un texto.');

            return null;
        }

        // El nombre sin espacios en los bordes.
        $nombre = trim($valor);
        // Largo máximo de un nombre de categoría o subcategoría (la columna `nombre` es de 128).
        $maximo = (int) config('catalogo_ia.largo_maximo_de_nombre');

        if (mb_strlen($nombre) > $maximo) {

            self::agregar_error($detalle, $campo, "El nombre '".self::recortado($nombre)."' tiene ".mb_strlen($nombre)." caracteres y el máximo es {$maximo}.");

            return null;
        }

        if (!CategoryProposalNombreHelper::es_usable($nombre)) {

            self::agregar_error($detalle, $campo, "El nombre '".self::recortado($nombre)."' no se puede usar: queda vacío al normalizarlo o es el que la tienda esconde (La de siempre).");

            return null;
        }

        return ['nombre' => $nombre, 'clave' => CategoryProposalNombreHelper::clave_de($nombre)];
    }

    // ------------------------------------------------------------------------------------------
    // crear
    // ------------------------------------------------------------------------------------------

    /**
     * POST categorias/propuestas — crea la corrida con sus propuestas y árboles (contrato A, §5.3).
     *
     * Orden de los chequeos: forma del cuerpo (422) → tope de artículos (422 `catalogo_muy_grande`) →
     * con el dueño bloqueado, corrida vigente (409) y, para una propuesta `mantener`, que el dueño
     * tenga categorías vivas (422). Todo o nada: la corrida, sus propuestas y sus nodos se escriben
     * en una sola transacción.
     *
     * @param  \App\Models\User $dueno
     * @param  array $cuerpo  El JSON que mandó la skill.
     * @return array  ['status' => 201|409|422, 'body' => ...]
     */
    public static function crear(User $dueno, array $cuerpo)
    {
        // El id del dueño: lo llevan todas las consultas y escrituras de abajo.
        $owner_id = (int) $dueno->id;

        // 1) La forma del cuerpo, sin tocar la base.
        $validado = self::validar_propuestas($cuerpo);

        if (!empty($validado['detalle'])) {

            return self::error_de_validacion($validado['detalle']);
        }

        // 2) El tope: una corrida clasifica cada artículo con Claude por lotes, y un catálogo de
        // cientos de miles no es el caso. Se corta antes de crear nada.
        $articulos_total = CategoryProposalCatalogoHelper::contar_articulos($owner_id);
        $tope            = (int) config('catalogo_ia.tope_articulos');

        if ($articulos_total > $tope) {

            return self::error(422, 'catalogo_muy_grande', "El catálogo tiene {$articulos_total} artículos y el tope para una corrida es {$tope}.", [
                'articulos_total' => $articulos_total,
                'tope_articulos'  => $tope,
            ]);
        }

        // Lo ya validado y normalizado del cuerpo.
        $propuestas = $validado['propuestas'];
        $reemplazar = $validado['reemplazar'];

        return DB::transaction(function () use ($owner_id, $propuestas, $reemplazar, $articulos_total) {

            // 3) El candado del dueño: serializa los `crear` del mismo comercio. Sin esto, dos pedidos
            // simultáneos pasarían los dos la verificación de "no hay corrida vigente" y quedarían dos.
            User::where('id', $owner_id)->lockForUpdate()->first(['id']);

            // Las corridas no descartadas del dueño, la más nueva primero (a lo sumo una, pero se miran todas).
            $vigentes = CategoryProposalRun::where('user_id', $owner_id)
                ->vigentes()
                ->orderBy('id', 'desc')
                ->get(['id', 'estado']);

            // Una corrida elegida (o en pleno aplicar) NUNCA se reemplaza: el dueño ya decidió y tocó
            // su catálogo. Va primero, aunque la skill haya mandado `reemplazar`.
            foreach ($vigentes as $vigente) {

                if (in_array($vigente->estado, [CategoryProposalRun::ESTADO_ELEGIDA, CategoryProposalRun::ESTADO_APLICANDO], true)) {

                    return self::error(409, 'ya_hay_una_elegida', 'Este comercio ya eligió un sistema de categorías: no se arma otra propuesta.', [
                        'run_id' => (int) $vigente->id,
                        'estado' => (string) $vigente->estado,
                    ]);
                }
            }

            // Preparando o lista: sin `reemplazar` se frena y se dice cuál es.
            if ($vigentes->count() > 0 && !$reemplazar) {

                return self::error(409, 'ya_hay_una_propuesta', 'Ya hay una propuesta de categorías en curso para este comercio. Mandá "reemplazar": true para descartarla y armar otra.', [
                    'run_id' => (int) $vigentes->first()->id,
                    'estado' => (string) $vigentes->first()->estado,
                ]);
            }

            // 4) Las categorías que el dueño ya tiene: sin ellas no hay nada que "mantener".
            // 🔴 Se chequea ANTES de descartar la corrida vieja: lo que se devuelve desde adentro de
            // esta transacción se confirma, así que un rechazo posterior a una escritura dejaría la
            // corrida anterior descartada y ninguna nueva en su lugar.
            $existentes = [];

            foreach ($propuestas as $propuesta) {

                if ($propuesta['tipo'] === CategoryProposal::TIPO_MANTENER) {

                    $existentes = self::arbol_de_categorias_existentes($owner_id);

                    if (count($existentes) === 0) {

                        return self::error_de_validacion([
                            'propuestas' => ['Este comercio no tiene categorías: no hay nada que mantener, así que no se acepta una propuesta de tipo mantener.'],
                        ]);
                    }

                    break;
                }
            }

            // Con todo validado, recién ahora se escribe. Con `reemplazar`, la corrida vigente se
            // descarta (la skill rehace la propuesta: lo que el dueño ya podía ver no se edita en vivo).
            if ($vigentes->count() > 0) {

                CategoryProposalRun::where('user_id', $owner_id)
                    ->whereIn('id', $vigentes->pluck('id')->all())
                    ->update([
                        'estado'        => CategoryProposalRun::ESTADO_DESCARTADA,
                        'descartada_at' => Carbon::now(),
                        'updated_at'    => Carbon::now(),
                    ]);
            }

            // 5) La corrida, sus propuestas y sus nodos.
            $run = CategoryProposalRun::create([
                'user_id'         => $owner_id,
                'estado'          => CategoryProposalRun::ESTADO_PREPARANDO,
                'origen'          => CategoryProposalRun::ORIGEN_SKILL,
                'articulos_total' => $articulos_total,
            ]);

            // Las propuestas creadas, con la forma que devuelve la respuesta.
            $creadas = [];

            foreach ($propuestas as $posicion => $propuesta) {

                // La tarjeta de la propuesta.
                $proposal = CategoryProposal::create([
                    'run_id'      => $run->id,
                    'user_id'     => $owner_id,
                    'clave'       => $propuesta['clave'],
                    'tipo'        => $propuesta['tipo'],
                    'nombre'      => $propuesta['nombre'],
                    'resumen'     => $propuesta['resumen'],
                    'descripcion' => $propuesta['descripcion'],
                    'orden'       => $posicion + 1,
                ]);

                // Los nodos de la tarjeta: los que mandó la skill (nueva) o los armados con las categorías existentes (mantener).
                $arbol = $propuesta['tipo'] === CategoryProposal::TIPO_MANTENER ? $existentes : $propuesta['arbol'];

                self::insertar_nodos($proposal, $owner_id, $arbol);

                $creadas[] = [
                    'clave'      => $proposal->clave,
                    'id'         => (int) $proposal->id,
                    'tipo'       => $proposal->tipo,
                    'categorias' => self::arbol_de_respuesta($proposal->id, $owner_id),
                ];
            }

            return self::respuesta(201, [
                'run_id'     => (int) $run->id,
                'estado'     => $run->estado,
                'propuestas' => $creadas,
            ]);
        });
    }

    /**
     * El árbol de "Mantener las mías": las categorías y subcategorías VIVAS del dueño, con el id real
     * al que corresponde cada nodo.
     *
     * Reglas, todas para que el árbol sea algo con lo que la skill pueda trabajar por nombre:
     *   - Se saltean los nombres que no se pueden usar (vacíos o `La de siempre`, que la tienda
     *     esconde: asignarle artículos los haría desaparecer de la tienda) y los de más de 128
     *     caracteres (no entran en `category_proposal_nodes.nombre`).
     *   - El sistema deja crear dos categorías con el mismo nombre. Gana la MÁS VIEJA (la misma regla
     *     con la que el aplicar reutiliza por nombre); la repetida y sus subcategorías no entran. No
     *     se pierde nada: "mantener" no toca los artículos que ya tienen categoría.
     *   - Orden alfabético por nombre normalizado (como las ordena la tienda).
     *
     * Estos nodos NO se acotan al tope de 400 nodos: reflejan lo que el dueño ya tiene, no lo que
     * propone la skill, y un comercio con muchas categorías tiene que poder mantenerlas.
     *
     * @param  int $owner_id
     * @return array  [['nombre', 'clave', 'existing_category_id', 'subs' => [['nombre', 'clave', 'existing_sub_category_id']]]]
     */
    protected static function arbol_de_categorias_existentes($owner_id)
    {
        // Las categorías vivas del dueño, de la más vieja a la más nueva (en un nombre repetido gana la más vieja).
        $categorias = DB::table('categories')
            ->where('user_id', $owner_id)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'name']);

        // Sus subcategorías vivas, también de la más vieja a la más nueva.
        $subcategorias = DB::table('sub_categories')
            ->where('user_id', $owner_id)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'name', 'category_id']);

        // Largo máximo de un nombre de nodo (los que lo pasan no entran en `category_proposal_nodes.nombre`).
        $maximo = (int) config('catalogo_ia.largo_maximo_de_nombre');

        // Las categorías que entran, por clave (la primera, la más vieja, gana) y por id real.
        $por_clave = [];
        $clave_de_id = [];

        foreach ($categorias as $categoria) {

            // El nombre de la categoría sin espacios en los bordes.
            $nombre = trim((string) $categoria->name);

            if (!CategoryProposalNombreHelper::es_usable($nombre) || mb_strlen($nombre) > $maximo) {

                continue;
            }

            // Su clave de comparación (minúsculas, sin acentos, espacios colapsados).
            $clave = CategoryProposalNombreHelper::clave_de($nombre);

            if (isset($por_clave[$clave])) {

                continue;
            }

            $por_clave[$clave] = [
                'nombre'               => $nombre,
                'clave'                => $clave,
                'existing_category_id' => (int) $categoria->id,
                'subs'                 => [],
            ];
            $clave_de_id[(int) $categoria->id] = $clave;
        }

        // Las subcategorías, bajo su categoría real (si esa categoría entró).
        $claves_de_subs = [];

        foreach ($subcategorias as $sub) {

            // El id real de la categoría a la que pertenece esta subcategoría.
            $categoria_id = (int) $sub->category_id;

            if (!isset($clave_de_id[$categoria_id])) {

                continue;
            }

            // El nombre de la subcategoría sin espacios en los bordes.
            $nombre = trim((string) $sub->name);

            if (!CategoryProposalNombreHelper::es_usable($nombre) || mb_strlen($nombre) > $maximo) {

                continue;
            }

            // La clave de la categoría padre y la de la subcategoría (para detectar un nombre repetido).
            $clave_padre = $clave_de_id[$categoria_id];
            $clave       = CategoryProposalNombreHelper::clave_de($nombre);

            if (isset($claves_de_subs[$clave_padre][$clave])) {

                continue;
            }

            $claves_de_subs[$clave_padre][$clave] = true;

            $por_clave[$clave_padre]['subs'][] = [
                'nombre'                   => $nombre,
                'clave'                    => $clave,
                'existing_sub_category_id' => (int) $sub->id,
            ];
        }

        // La lista final de categorías (sin las claves con las que se indexó).
        $arbol = array_values($por_clave);

        // Alfabético por clave (comparación de texto: con la de PHP 7.4 un "2024" y un "30" se
        // compararían como números).
        usort($arbol, function ($a, $b) {
            return strcmp($a['clave'], $b['clave']);
        });

        foreach ($arbol as $indice => $categoria) {

            usort($arbol[$indice]['subs'], function ($a, $b) {
                return strcmp($a['clave'], $b['clave']);
            });
        }

        return $arbol;
    }

    /**
     * Inserta los nodos de una propuesta: primero todas las categorías en un INSERT multi-fila, se
     * relee su id por `orden` y después las subcategorías apuntando a su padre.
     *
     * Las filas de las propuestas `mantener` traen además el id real al que corresponde cada nodo
     * (`existing_category_id` en la categoría; `existing_category_id` del padre y
     * `existing_sub_category_id` en la subcategoría).
     *
     * @param  \App\Models\CategoryProposal $proposal
     * @param  int   $owner_id
     * @param  array $arbol  Categorías normalizadas (de `validar_arbol` o `arbol_de_categorias_existentes`).
     * @return void
     */
    protected static function insertar_nodos(CategoryProposal $proposal, $owner_id, array $arbol)
    {
        // La misma marca de tiempo para todas las filas de la propuesta.
        $ahora = Carbon::now();

        // 1) Las categorías. `orden` es la posición (1, 2, 3...): sirve después para reconocer cuál
        // es cuál al releer los ids.
        $filas = [];

        foreach (array_values($arbol) as $posicion => $categoria) {

            $filas[] = self::fila_de_nodo($proposal->id, $owner_id, null, $categoria['nombre'], $categoria['clave'], $posicion + 1, [
                'existing_category_id' => isset($categoria['existing_category_id']) ? $categoria['existing_category_id'] : null,
            ], $ahora);
        }

        foreach (array_chunk($filas, self::LOTE_DE_NODOS) as $lote) {

            DB::table('category_proposal_nodes')->insert($lote);
        }

        // Los ids que quedaron, por posición.
        $id_por_posicion = DB::table('category_proposal_nodes')
            ->where('proposal_id', $proposal->id)
            ->whereNull('parent_id')
            ->pluck('id', 'orden');

        // 2) Las subcategorías, cada una con el id de su categoría.
        $filas = [];

        foreach (array_values($arbol) as $posicion => $categoria) {

            // El id real del nodo-categoría que se acaba de insertar, buscado por su posición.
            $padre_id = (int) $id_por_posicion[$posicion + 1];

            foreach (array_values($categoria['subs']) as $posicion_de_sub => $sub) {

                $filas[] = self::fila_de_nodo($proposal->id, $owner_id, $padre_id, $sub['nombre'], $sub['clave'], $posicion_de_sub + 1, [
                    'existing_category_id'     => isset($categoria['existing_category_id']) ? $categoria['existing_category_id'] : null,
                    'existing_sub_category_id' => isset($sub['existing_sub_category_id']) ? $sub['existing_sub_category_id'] : null,
                ], $ahora);
            }
        }

        foreach (array_chunk($filas, self::LOTE_DE_NODOS) as $lote) {

            DB::table('category_proposal_nodes')->insert($lote);
        }
    }

    /**
     * Una fila de `category_proposal_nodes`. Todas llevan las mismas columnas (lo exige el INSERT
     * multi-fila).
     *
     * @param  int         $proposal_id
     * @param  int         $owner_id
     * @param  int|null    $parent_id  null = categoría.
     * @param  string      $nombre
     * @param  string      $clave
     * @param  int         $orden
     * @param  array       $existentes  `existing_category_id` / `existing_sub_category_id` (solo "mantener").
     * @param  \Carbon\Carbon $ahora
     * @return array
     */
    protected static function fila_de_nodo($proposal_id, $owner_id, $parent_id, $nombre, $clave, $orden, array $existentes, $ahora)
    {
        return [
            'proposal_id'              => $proposal_id,
            'user_id'                  => $owner_id,
            'parent_id'                => $parent_id,
            'nombre'                   => $nombre,
            'clave_nombre'             => $clave,
            'orden'                    => $orden,
            'existing_category_id'     => isset($existentes['existing_category_id']) ? $existentes['existing_category_id'] : null,
            'existing_sub_category_id' => isset($existentes['existing_sub_category_id']) ? $existentes['existing_sub_category_id'] : null,
            'real_category_id'         => null,
            'real_sub_category_id'     => null,
            'real_creado'              => 0,
            'created_at'               => $ahora,
            'updated_at'               => $ahora,
        ];
    }

    /**
     * El árbol de una propuesta como lo devuelve `crear`: `[{nombre, id, subcategorias: [{nombre, id}]}]`
     * en el orden en que se mandó (el de `orden`).
     *
     * @param  int $proposal_id
     * @param  int $owner_id
     * @return array
     */
    protected static function arbol_de_respuesta($proposal_id, $owner_id)
    {
        // Todos los nodos de la propuesta: de ahí sale el árbol de la respuesta.
        $nodos = DB::table('category_proposal_nodes')
            ->where('proposal_id', $proposal_id)
            ->where('user_id', $owner_id)
            ->orderBy('orden')
            ->orderBy('id')
            ->get(['id', 'parent_id', 'nombre']);

        // Las subcategorías agrupadas por el id de su nodo padre.
        $subs_de = [];

        foreach ($nodos as $nodo) {

            if (!is_null($nodo->parent_id)) {

                $subs_de[(int) $nodo->parent_id][] = ['nombre' => (string) $nodo->nombre, 'id' => (int) $nodo->id];
            }
        }

        // Las categorías con sus subcategorías, en el orden en que se mandaron.
        $categorias = [];

        foreach ($nodos as $nodo) {

            if (is_null($nodo->parent_id)) {

                $categorias[] = [
                    'nombre'        => (string) $nodo->nombre,
                    'id'            => (int) $nodo->id,
                    'subcategorias' => isset($subs_de[(int) $nodo->id]) ? $subs_de[(int) $nodo->id] : [],
                ];
            }
        }

        return $categorias;
    }

    // ------------------------------------------------------------------------------------------
    // asignaciones
    // ------------------------------------------------------------------------------------------

    /**
     * POST categorias/propuestas/{run_id}/asignaciones — guarda un lote (de hasta 500) de a qué
     * categoría cae cada artículo en UNA propuesta (contrato A, §5.4).
     *
     * Solo con la corrida en `preparando`: una propuesta que el dueño ya puede ver no se modifica en
     * vivo. Idempotente: reenviar un artículo reemplaza su ítem. Los renglones que no sirven NO
     * cortan el pedido: se devuelven en `rechazadas` con su motivo y el resto se guarda (200). Un 422
     * es solo para un cuerpo mal armado.
     *
     * @param  \App\Models\User $dueno
     * @param  int   $run_id
     * @param  array $cuerpo  `{propuesta: "A", asignaciones: [{articulo_id, categoria, subcategoria, confianza, motivo}]}`
     * @return array
     */
    public static function asignaciones(User $dueno, $run_id, array $cuerpo)
    {
        // El id del dueño que resolvió admin-sync.
        $owner_id = (int) $dueno->id;

        return DB::transaction(function () use ($owner_id, $run_id, $cuerpo) {

            // La corrida, del dueño y BLOQUEADA: el estado se lee de esta fila, no de una lectura
            // anterior, así un `listo` concurrente no se cuela entre el chequeo y la escritura.
            $run = CategoryProposalRun::where('user_id', $owner_id)
                ->where('id', (int) $run_id)
                ->lockForUpdate()
                ->first();

            if (is_null($run)) {

                return self::no_encontrada();
            }

            if ($run->estado !== CategoryProposalRun::ESTADO_PREPARANDO) {

                return self::error(409, 'corrida_cerrada', "Esa corrida está en estado '{$run->estado}': solo se cargan asignaciones mientras está preparando. Para rehacer la propuesta, descartala y creá otra.", [
                    'run_id' => (int) $run->id,
                    'estado' => (string) $run->estado,
                ]);
            }

            // La forma del cuerpo.
            $detalle = [];
            $clave   = self::texto_de($cuerpo, 'propuesta');
            $lista   = isset($cuerpo['asignaciones']) ? $cuerpo['asignaciones'] : null;
            $maximo  = (int) config('catalogo_ia.asignaciones_por_pedido_maximo');

            if ($clave === '') {

                self::agregar_error($detalle, 'propuesta', 'Falta la clave de la propuesta (A, B, C o mantener).');
            }

            if (!is_array($lista)) {

                self::agregar_error($detalle, 'asignaciones', 'Tiene que ser una lista de asignaciones.');
            } elseif (count($lista) > $maximo) {

                self::agregar_error($detalle, 'asignaciones', 'Hay '.count($lista)." asignaciones y el máximo por pedido es {$maximo}.");
            }

            if (!empty($detalle)) {

                return self::error_de_validacion($detalle);
            }

            // La tarjeta a la que se asignan los artículos (por su clave dentro de la corrida).
            $proposal = CategoryProposal::where('run_id', $run->id)
                ->where('user_id', $owner_id)
                ->where('clave', $clave)
                ->first();

            if (is_null($proposal)) {

                return self::error_de_validacion([
                    'propuesta' => ["La corrida no tiene una propuesta con clave '".self::recortado($clave)."'."],
                ]);
            }

            return self::respuesta(200, self::guardar_asignaciones($owner_id, $run, $proposal, array_values($lista)));
        });
    }

    /**
     * Valida renglón por renglón un lote de asignaciones y guarda los que sirven con un solo
     * `upsert`. Corre adentro de la transacción de `asignaciones`, con la corrida bloqueada.
     *
     * @param  int  $owner_id
     * @param  \App\Models\CategoryProposalRun $run
     * @param  \App\Models\CategoryProposal    $proposal
     * @param  array $lista  Los renglones tal como llegaron (lista de arreglos).
     * @return array  El cuerpo de la respuesta: recibidas, guardadas, rechazadas, progreso.
     */
    protected static function guardar_asignaciones($owner_id, CategoryProposalRun $run, CategoryProposal $proposal, array $lista)
    {
        // En una propuesta `mantener` solo entran artículos sin categoría viva.
        $es_mantener = $proposal->tipo === CategoryProposal::TIPO_MANTENER;

        // Los nodos de la propuesta indexados para resolver nombres: categorías por clave y
        // subcategorías por [id de la categoría][clave].
        $nodos = self::indice_de_nodos($proposal->id, $owner_id);

        // Los ids de artículo que llegaron bien formados, y el LUGAR del último renglón de cada uno:
        // si el mismo artículo viene dos veces en el pedido, gana el último (como un reenvío).
        $ids              = [];
        $ultimo_renglon   = [];

        foreach ($lista as $indice => $renglon) {

            // El id del artículo de este renglón, o null si no es un entero positivo.
            $id = is_array($renglon) && isset($renglon['articulo_id']) ? self::entero_positivo($renglon['articulo_id']) : null;

            if (!is_null($id)) {

                $ids[$id]            = true;
                $ultimo_renglon[$id] = $indice;
            }
        }

        // Cuáles de esos ids son artículos del dueño (activos y sin borrar), y si ya tienen categoría
        // viva (solo importa en "mantener", que únicamente acepta artículos sin categoría).
        $articulos = self::articulos_validos($owner_id, array_keys($ids), $es_mantener);

        $filas      = [];
        $rechazadas = [];
        $ahora      = Carbon::now();

        foreach ($lista as $indice => $renglon) {

            // El id del artículo de este renglón (null si no se puede usar).
            $articulo_id = is_array($renglon) && isset($renglon['articulo_id']) ? self::entero_positivo($renglon['articulo_id']) : null;

            if (!is_array($renglon)) {

                $rechazadas[] = ['articulo_id' => null, 'motivo' => 'asignacion_invalida: cada renglón tiene que ser un objeto'];

                continue;
            }

            if (is_null($articulo_id)) {

                $rechazadas[] = ['articulo_id' => null, 'motivo' => 'articulo_id_invalido'];

                continue;
            }

            if ($ultimo_renglon[$articulo_id] !== $indice) {

                $rechazadas[] = ['articulo_id' => $articulo_id, 'motivo' => 'repetido_en_el_pedido'];

                continue;
            }

            if (!isset($articulos[$articulo_id])) {

                $rechazadas[] = ['articulo_id' => $articulo_id, 'motivo' => 'articulo_no_encontrado'];

                continue;
            }

            if ($es_mantener && $articulos[$articulo_id]) {

                $rechazadas[] = ['articulo_id' => $articulo_id, 'motivo' => 'articulo_con_categoria: "mantener" solo acepta artículos sin categoría'];

                continue;
            }

            // Los valores a guardar del renglón, o el texto con el motivo del rechazo.
            $resuelto = self::resolver_renglon($renglon, $nodos);

            if (is_string($resuelto)) {

                $rechazadas[] = ['articulo_id' => $articulo_id, 'motivo' => $resuelto];

                continue;
            }

            $filas[] = [
                'proposal_id'          => $proposal->id,
                'user_id'              => $owner_id,
                'article_id'           => $articulo_id,
                'node_id'              => $resuelto['node_id'],
                'sub_node_id'          => $resuelto['sub_node_id'],
                'confianza'            => $resuelto['confianza'],
                'motivo'               => $resuelto['motivo'],
                'estado'               => CategoryProposalItem::ESTADO_PROPUESTA,
                'prev_category_id'     => null,
                'prev_sub_category_id' => null,
                'revisado_por'         => null,
                'revisado_at'          => null,
                'created_at'           => $ahora,
                'updated_at'           => $ahora,
            ];
        }

        // UN upsert por el lote entero (hasta 500 filas). Reenviar un artículo reemplaza lo que
        // dijo antes de él; `created_at` y `estado` no se tocan en el reemplazo.
        if (!empty($filas)) {

            DB::table('category_proposal_items')->upsert(
                $filas,
                ['proposal_id', 'article_id'],
                ['node_id', 'sub_node_id', 'confianza', 'motivo', 'updated_at']
            );
        }

        // El avance de la propuesta después de guardar este lote.
        $progreso = self::progreso_de_propuesta($owner_id, $proposal);

        return [
            'recibidas'  => count($lista),
            'guardadas'  => count($filas),
            'rechazadas' => $rechazadas,
            'progreso'   => [
                $proposal->clave => ['asignados' => $progreso['asignados'], 'total' => $progreso['total']],
            ],
        ];
    }

    /**
     * Resuelve un renglón de asignación contra el árbol de la propuesta.
     *
     * Con `confianza = ninguna` ("no pude ubicarlo") no hay categoría: el renglón se guarda sin nodos
     * aunque traiga nombres. Con `segura` o `dudosa` la categoría es obligatoria y tiene que ser una
     * de la propuesta; la subcategoría es opcional y tiene que ser hija de esa categoría. Los nombres
     * se comparan normalizados (sin mayúsculas, acentos ni espacios de más).
     *
     * @param  array $renglon
     * @param  array $nodos  Lo que devuelve `indice_de_nodos`.
     * @return array|string  Los valores a guardar (`node_id`, `sub_node_id`, `confianza`, `motivo`) o el
     *                       texto del motivo del rechazo.
     */
    protected static function resolver_renglon(array $renglon, array $nodos)
    {
        // Lo que dijo la skill de este artículo: segura, dudosa o ninguna.
        $confianza = isset($renglon['confianza']) && is_string($renglon['confianza']) ? trim($renglon['confianza']) : '';

        if (!in_array($confianza, CategoryProposalItem::CONFIANZAS, true)) {

            return 'confianza_invalida: tiene que ser segura, dudosa o ninguna';
        }

        // El motivo es libre; se corta al largo de la columna en vez de rechazar el renglón por una
        // explicación larga.
        $motivo = isset($renglon['motivo']) && is_string($renglon['motivo']) ? trim($renglon['motivo']) : '';
        $motivo = $motivo === '' ? null : mb_substr($motivo, 0, self::LARGO_MOTIVO);

        if ($confianza === CategoryProposalItem::CONFIANZA_NINGUNA) {

            return ['node_id' => null, 'sub_node_id' => null, 'confianza' => $confianza, 'motivo' => $motivo];
        }

        // Los nombres que mandó la skill (vacíos si no mandó).
        $categoria    = self::texto_de($renglon, 'categoria');
        $subcategoria = self::texto_de($renglon, 'subcategoria');

        if ($categoria === '') {

            return 'categoria_requerida: solo se puede omitir con confianza ninguna';
        }

        // La clave de comparación de la categoría.
        $clave_de_categoria = CategoryProposalNombreHelper::clave_de($categoria);

        if (!isset($nodos['categorias'][$clave_de_categoria])) {

            return "categoria_inexistente: '".self::recortado($categoria)."'";
        }

        // Los nodos donde cae el artículo: la categoría y, si mandó, la subcategoría.
        $node_id     = $nodos['categorias'][$clave_de_categoria];
        $sub_node_id = null;

        if ($subcategoria !== '') {

            // La clave de comparación de la subcategoría.
            $clave_de_sub = CategoryProposalNombreHelper::clave_de($subcategoria);

            if (!isset($nodos['subs'][$node_id][$clave_de_sub])) {

                return "subcategoria_inexistente: '".self::recortado($subcategoria)."'";
            }

            $sub_node_id = $nodos['subs'][$node_id][$clave_de_sub];
        }

        return ['node_id' => $node_id, 'sub_node_id' => $sub_node_id, 'confianza' => $confianza, 'motivo' => $motivo];
    }

    /**
     * Un id de artículo bien formado: un entero positivo, o un texto de solo dígitos, o un decimal
     * que en realidad es entero (`12.0`, como lo decodifica JSON). Cualquier otra cosa es null.
     *
     * @param  mixed $valor
     * @return int|null
     */
    protected static function entero_positivo($valor)
    {
        if (is_int($valor)) {

            return $valor > 0 ? $valor : null;
        }

        if (is_string($valor) && preg_match('/^[0-9]{1,18}$/', $valor)) {

            return (int) $valor > 0 ? (int) $valor : null;
        }

        if (is_float($valor) && $valor > 0 && $valor < 9.0E+15 && floor($valor) === $valor) {

            return (int) $valor;
        }

        return null;
    }

    /**
     * Los nodos de una propuesta, indexados para resolver nombres: `categorias` por clave de nombre
     * y `subs` por [id de la categoría][clave de nombre].
     *
     * @param  int $proposal_id
     * @param  int $owner_id
     * @return array  ['categorias' => clave => id, 'subs' => id_categoria => [clave => id]]
     */
    protected static function indice_de_nodos($proposal_id, $owner_id)
    {
        // Todos los nodos de la propuesta (solo las columnas que hacen falta para resolver nombres).
        $nodos = DB::table('category_proposal_nodes')
            ->where('proposal_id', $proposal_id)
            ->where('user_id', $owner_id)
            ->get(['id', 'parent_id', 'clave_nombre']);

        // Los dos índices que se devuelven.
        $categorias = [];
        $subs       = [];

        foreach ($nodos as $nodo) {

            if (is_null($nodo->parent_id)) {

                $categorias[$nodo->clave_nombre] = (int) $nodo->id;
            } else {

                $subs[(int) $nodo->parent_id][$nodo->clave_nombre] = (int) $nodo->id;
            }
        }

        return ['categorias' => $categorias, 'subs' => $subs];
    }

    /**
     * De una lista de ids de artículo, los que son del dueño (activos y sin borrar), con una marca de
     * si ya tienen categoría viva.
     *
     * Un id de otro comercio, borrado o inexistente simplemente no aparece: el llamador lo rechaza
     * con `articulo_no_encontrado`, igual para los tres casos (no se confirma que exista).
     *
     * @param  int   $owner_id
     * @param  array $ids
     * @param  bool  $con_categoria  true = también averigua si tiene categoría viva (propuestas "mantener").
     * @return array  article_id => bool (tiene categoría viva; siempre false si `$con_categoria` es false)
     */
    protected static function articulos_validos($owner_id, array $ids, $con_categoria)
    {
        if (empty($ids)) {

            return [];
        }

        // Los artículos de los ids que llegaron, restringidos al universo del dueño.
        $consulta = CategoryProposalCatalogoHelper::articulos_del_dueno($owner_id)->whereIn('a.id', $ids);

        if ($con_categoria) {

            CategoryProposalCatalogoHelper::unir_categoria_viva($consulta);
        }

        // Una fila por artículo encontrado: los demás no son del dueño, están borrados o están inactivos.
        $filas = $con_categoria ? $consulta->get(['a.id', 'c.id as categoria_viva']) : $consulta->get(['a.id']);

        // id de artículo => si tiene categoría viva.
        $validos = [];

        foreach ($filas as $fila) {

            $validos[(int) $fila->id] = $con_categoria && !is_null($fila->categoria_viva);
        }

        return $validos;
    }

    // ------------------------------------------------------------------------------------------
    // pendientes y progreso
    // ------------------------------------------------------------------------------------------

    /**
     * La consulta de los artículos que todavía no tienen ítem en una propuesta: el universo del
     * dueño sin fila en `category_proposal_items` para esa propuesta (el JOIN por la unique
     * `(proposal_id, article_id)` es una búsqueda por índice). En una propuesta "mantener", solo los
     * que además no tienen categoría viva.
     *
     * @param  int $owner_id
     * @param  \App\Models\CategoryProposal $proposal
     * @return \Illuminate\Database\Query\Builder
     */
    protected static function consulta_de_pendientes($owner_id, CategoryProposal $proposal)
    {
        // Los artículos del dueño que no tienen fila en `category_proposal_items` para esta propuesta.
        $consulta = CategoryProposalCatalogoHelper::articulos_del_dueno($owner_id)
            ->leftJoin('category_proposal_items as i', function ($join) use ($proposal) {
                $join->on('i.article_id', '=', 'a.id')
                    ->where('i.proposal_id', '=', (int) $proposal->id);
            })
            ->whereNull('i.id');

        if ($proposal->tipo === CategoryProposal::TIPO_MANTENER) {

            CategoryProposalCatalogoHelper::unir_categoria_viva($consulta);
            $consulta->whereNull('c.id');
        }

        return $consulta;
    }

    /**
     * Cuántos artículos le faltan a una propuesta.
     *
     * @param  int $owner_id
     * @param  \App\Models\CategoryProposal $proposal
     * @return int
     */
    protected static function contar_pendientes($owner_id, CategoryProposal $proposal)
    {
        return (int) self::consulta_de_pendientes($owner_id, $proposal)->count();
    }

    /**
     * Cuántos ítems hay en cada propuesta, por confianza, en UNA consulta agrupada.
     *
     * @param  array $proposal_ids
     * @param  int   $owner_id
     * @return array  proposal_id => ['seguros' => n, 'dudosos' => n, 'sin_asignar' => n]
     */
    protected static function conteos_por_confianza(array $proposal_ids, $owner_id)
    {
        // Los conteos en cero: una propuesta sin ítems también tiene su fila.
        $conteos = [];

        foreach ($proposal_ids as $id) {

            $conteos[(int) $id] = ['seguros' => 0, 'dudosos' => 0, 'sin_asignar' => 0];
        }

        if (empty($conteos)) {

            return $conteos;
        }

        // Una fila por (propuesta, confianza) con su cantidad.
        $filas = DB::table('category_proposal_items')
            ->whereIn('proposal_id', array_keys($conteos))
            ->where('user_id', $owner_id)
            ->groupBy('proposal_id', 'confianza')
            ->selectRaw('proposal_id, confianza, COUNT(*) AS n')
            ->get();

        // `ninguna` es lo que el contrato llama `sin_asignar`.
        $columna_de = [
            CategoryProposalItem::CONFIANZA_SEGURA  => 'seguros',
            CategoryProposalItem::CONFIANZA_DUDOSA  => 'dudosos',
            CategoryProposalItem::CONFIANZA_NINGUNA => 'sin_asignar',
        ];

        foreach ($filas as $fila) {

            if (isset($columna_de[$fila->confianza])) {

                $conteos[(int) $fila->proposal_id][$columna_de[$fila->confianza]] = (int) $fila->n;
            }
        }

        return $conteos;
    }

    /**
     * El avance de UNA propuesta: cuántos ítems tiene y cuántos artículos le faltan. `total` es lo
     * ya asignado más lo que falta (así sigue cerrando si el catálogo cambia mientras la skill
     * trabaja).
     *
     * @param  int $owner_id
     * @param  \App\Models\CategoryProposal $proposal
     * @return array  ['asignados' => n, 'total' => n, 'quedan' => n]
     */
    protected static function progreso_de_propuesta($owner_id, CategoryProposal $proposal)
    {
        // Cuántos ítems tiene la propuesta.
        $asignados = (int) DB::table('category_proposal_items')
            ->where('proposal_id', $proposal->id)
            ->where('user_id', $owner_id)
            ->count();

        // Cuántos artículos le faltan.
        $quedan = self::contar_pendientes($owner_id, $proposal);

        return ['asignados' => $asignados, 'total' => $asignados + $quedan, 'quedan' => $quedan];
    }

    /**
     * GET categorias/propuestas/{run_id}/pendientes?propuesta=A&limite=300 — los artículos que todavía
     * no tienen ítem en esa propuesta (contrato A, §5.5). Es lo que hace la corrida RETOMABLE: el
     * estado vive en el servidor, y la skill pregunta qué falta en vez de recordarlo.
     *
     * @param  \App\Models\User $dueno
     * @param  int         $run_id
     * @param  string|null $clave   La clave de la propuesta.
     * @param  int         $limite  Se acota a 1..`articulos_por_pagina_maximo` (300 por defecto).
     * @return array
     */
    public static function pendientes(User $dueno, $run_id, $clave, $limite)
    {
        // El id del dueño que resolvió admin-sync.
        $owner_id = (int) $dueno->id;

        // La corrida del dueño (la ajena y la inexistente dan lo mismo: null).
        $run = CategoryProposalRun::where('user_id', $owner_id)->where('id', (int) $run_id)->first();

        if (is_null($run)) {

            return self::no_encontrada();
        }

        // La clave de la propuesta pedida, sin espacios (vacía si no vino).
        $clave = is_string($clave) ? trim($clave) : '';

        // La propuesta de la corrida con esa clave (null si no vino o no existe).
        $proposal = $clave === '' ? null : CategoryProposal::where('run_id', $run->id)
            ->where('user_id', $owner_id)
            ->where('clave', $clave)
            ->first();

        if (is_null($proposal)) {

            return self::error_de_validacion([
                'propuesta' => [$clave === ''
                    ? 'Falta la clave de la propuesta (A, B, C o mantener).'
                    : "La corrida no tiene una propuesta con clave '".self::recortado($clave)."'."],
            ]);
        }

        // El tope de artículos por página (sale de la configuración).
        $maximo = max(1, (int) config('catalogo_ia.articulos_por_pagina_maximo'));
        // Cuántos pidió la skill (si pidió cero, negativo o no es número: los de por defecto).
        $limite = (int) $limite;

        if ($limite < 1) {

            $limite = self::PENDIENTES_POR_DEFECTO;
        }

        $limite = min($limite, $maximo);

        // La página y el total que falta. El orden por id es estable: lo que la skill guarda deja de
        // ser pendiente y el pedido siguiente sigue por donde corresponde.
        $consulta = self::consulta_de_pendientes($owner_id, $proposal);
        $quedan   = (int) (clone $consulta)->count();

        CategoryProposalCatalogoHelper::unir_marca($consulta);

        // La página, por id ascendente.
        $filas = $consulta
            ->orderBy('a.id')
            ->limit($limite)
            ->get(['a.id', 'a.name as nombre', 'a.bar_code as codigo_de_barras', 'a.provider_code as codigo_de_proveedor', 'b.name as marca']);

        // Los pendientes con la forma del contrato.
        $pendientes = [];

        foreach ($filas as $fila) {

            $pendientes[] = [
                'id'                  => (int) $fila->id,
                'nombre'              => is_null($fila->nombre) ? '' : (string) $fila->nombre,
                'codigo_de_barras'    => CategoryProposalCatalogoHelper::texto_o_null($fila->codigo_de_barras),
                'codigo_de_proveedor' => CategoryProposalCatalogoHelper::texto_o_null($fila->codigo_de_proveedor),
                'marca'               => CategoryProposalCatalogoHelper::texto_o_null($fila->marca),
            ];
        }

        return self::respuesta(200, ['pendientes' => $pendientes, 'quedan' => $quedan]);
    }

    /**
     * El estado y el avance de una corrida (contrato A, §5.7): lo que devuelven
     * `GET categorias/propuestas/{run_id}` y `GET categorias/propuestas/actual`.
     *
     * @param  \App\Models\CategoryProposalRun $run
     * @return array  El cuerpo: run_id, estado, articulos_total y las propuestas con su avance.
     */
    public static function progreso_de_corrida(CategoryProposalRun $run)
    {
        // El id del dueño de la corrida.
        $owner_id = (int) $run->user_id;

        // Las propuestas de la corrida, en su orden.
        $propuestas = CategoryProposal::where('run_id', $run->id)
            ->where('user_id', $owner_id)
            ->orderBy('orden')
            ->orderBy('id')
            ->get(['id', 'clave', 'tipo']);

        // Los conteos por confianza de todas las propuestas, en una sola consulta.
        $conteos = self::conteos_por_confianza($propuestas->pluck('id')->all(), $owner_id);

        // El avance de cada propuesta.
        $payload = [];

        foreach ($propuestas as $proposal) {

            // Los conteos de esta propuesta.
            $c = $conteos[(int) $proposal->id];

            // Los ítems que ya tiene (de cualquier confianza).
            $asignados = $c['seguros'] + $c['dudosos'] + $c['sin_asignar'];

            $payload[] = [
                'clave'       => $proposal->clave,
                'id'          => (int) $proposal->id,
                'tipo'        => $proposal->tipo,
                'asignados'   => $asignados,
                'total'       => $asignados + self::contar_pendientes($owner_id, $proposal),
                'seguros'     => $c['seguros'],
                'dudosos'     => $c['dudosos'],
                'sin_asignar' => $c['sin_asignar'],
            ];
        }

        return [
            'run_id'          => (int) $run->id,
            'estado'          => (string) $run->estado,
            'articulos_total' => (int) $run->articulos_total,
            'propuestas'      => $payload,
        ];
    }

    /**
     * GET categorias/propuestas/{run_id} — el estado y el avance de una corrida del dueño.
     *
     * @param  \App\Models\User $dueno
     * @param  int $run_id
     * @return array
     */
    public static function mostrar(User $dueno, $run_id)
    {
        // La corrida del dueño (la ajena o la inexistente: null).
        $run = CategoryProposalRun::where('user_id', (int) $dueno->id)->where('id', (int) $run_id)->first();

        if (is_null($run)) {

            return self::no_encontrada();
        }

        return self::respuesta(200, self::progreso_de_corrida($run));
    }

    /**
     * GET categorias/propuestas/actual — lo mismo para la última corrida no descartada, o 404
     * `sin_propuesta` si no hay ninguna.
     *
     * @param  \App\Models\User $dueno
     * @return array
     */
    public static function actual(User $dueno)
    {
        // La corrida más nueva del dueño que no está descartada.
        $run = CategoryProposalRun::where('user_id', (int) $dueno->id)
            ->vigentes()
            ->orderBy('id', 'desc')
            ->first();

        if (is_null($run)) {

            return self::error(404, 'sin_propuesta', 'Este comercio no tiene una propuesta de categorías en curso.');
        }

        return self::respuesta(200, self::progreso_de_corrida($run));
    }

    // ------------------------------------------------------------------------------------------
    // listo y descartar
    // ------------------------------------------------------------------------------------------

    /**
     * POST categorias/propuestas/{run_id}/listo — pasa la corrida a `lista`: desde ese momento el
     * dueño la ve en Alertas (contrato A, §5.6).
     *
     * Verifica que cada propuesta tenga ítem para todos sus artículos. Si faltan, 422 `incompleto`
     * con `faltan: {"A": 10}`, salvo `{"forzar": true}` (los que falten quedan sin ítem y el aplicar
     * no los toca). Idempotente: una corrida que ya está `lista` contesta 200 con los mismos números.
     *
     * @param  \App\Models\User $dueno
     * @param  int   $run_id
     * @param  array $cuerpo  `{"forzar": true}` opcional.
     * @return array
     */
    public static function listo(User $dueno, $run_id, array $cuerpo)
    {
        // El id del dueño que resolvió admin-sync.
        $owner_id = (int) $dueno->id;
        // `forzar` se entiende como booleano (true, "true" o 1).
        $forzar   = isset($cuerpo['forzar']) && filter_var($cuerpo['forzar'], FILTER_VALIDATE_BOOLEAN);

        return DB::transaction(function () use ($owner_id, $run_id, $forzar) {

            // Bloqueada: un `asignaciones` concurrente espera a que termine esto.
            $run = CategoryProposalRun::where('user_id', $owner_id)
                ->where('id', (int) $run_id)
                ->lockForUpdate()
                ->first();

            if (is_null($run)) {

                return self::no_encontrada();
            }

            if ($run->estado !== CategoryProposalRun::ESTADO_LISTA) {

                if ($run->estado !== CategoryProposalRun::ESTADO_PREPARANDO) {

                    return self::error(409, 'corrida_cerrada', "Esa corrida está en estado '{$run->estado}': solo una corrida en preparación se puede pasar a lista.", [
                        'run_id' => (int) $run->id,
                        'estado' => (string) $run->estado,
                    ]);
                }

                // Las propuestas de la corrida, en su orden.
                $propuestas = CategoryProposal::where('run_id', $run->id)
                    ->where('user_id', $owner_id)
                    ->orderBy('orden')
                    ->orderBy('id')
                    ->get();

                // Cuántos artículos le faltan a cada propuesta (solo las que tienen faltantes).
                $faltan = [];

                foreach ($propuestas as $proposal) {

                    // Cuántos artículos le faltan a esta propuesta.
                    $cantidad = self::contar_pendientes($owner_id, $proposal);

                    if ($cantidad > 0) {

                        $faltan[$proposal->clave] = $cantidad;
                    }
                }

                if (!empty($faltan) && !$forzar) {

                    return self::error(422, 'incompleto', 'Hay propuestas con artículos sin asignar. Terminá de cargarlas o mandá "forzar": true para publicarlas así (el aplicar no toca a los que falten).', [
                        'faltan' => $faltan,
                    ]);
                }

                $run->estado = CategoryProposalRun::ESTADO_LISTA;
                $run->save();
            }

            // Los números finales de cada propuesta (también en la repetición idempotente).
            // Las propuestas de nuevo, para armar los números finales (también en la repetición idempotente).
            $propuestas = CategoryProposal::where('run_id', $run->id)
                ->where('user_id', $owner_id)
                ->orderBy('orden')
                ->orderBy('id')
                ->get(['id', 'clave']);

            // Los conteos por confianza de todas las propuestas, en una sola consulta.
            $conteos = self::conteos_por_confianza($propuestas->pluck('id')->all(), $owner_id);

            // Los números finales de cada propuesta.
            $payload = [];

            foreach ($propuestas as $proposal) {

                // Los conteos de esta propuesta.
                $c = $conteos[(int) $proposal->id];

                $payload[] = [
                    'clave'       => $proposal->clave,
                    'seguros'     => $c['seguros'],
                    'dudosos'     => $c['dudosos'],
                    'sin_asignar' => $c['sin_asignar'],
                ];
            }

            return self::respuesta(200, ['estado' => (string) $run->estado, 'propuestas' => $payload]);
        });
    }

    /**
     * POST categorias/propuestas/{run_id}/descartar — da de baja la corrida (contrato A, §5.8).
     * 409 `corrida_elegida` si el dueño ya eligió (o se está aplicando): eso no se descarta, se
     * deshace con "volver atrás" desde la SPA. Descartar una ya descartada contesta 200.
     *
     * @param  \App\Models\User $dueno
     * @param  int $run_id
     * @return array
     */
    public static function descartar(User $dueno, $run_id)
    {
        // El id del dueño que resolvió admin-sync.
        $owner_id = (int) $dueno->id;

        return DB::transaction(function () use ($owner_id, $run_id) {

            // La corrida del dueño, bloqueada hasta que termine la transacción.
            $run = CategoryProposalRun::where('user_id', $owner_id)
                ->where('id', (int) $run_id)
                ->lockForUpdate()
                ->first();

            if (is_null($run)) {

                return self::no_encontrada();
            }

            if (in_array($run->estado, [CategoryProposalRun::ESTADO_ELEGIDA, CategoryProposalRun::ESTADO_APLICANDO], true)) {

                return self::error(409, 'corrida_elegida', 'Esa corrida ya la eligió el dueño: no se puede descartar.', [
                    'run_id' => (int) $run->id,
                    'estado' => (string) $run->estado,
                ]);
            }

            if ($run->estado !== CategoryProposalRun::ESTADO_DESCARTADA) {

                $run->estado        = CategoryProposalRun::ESTADO_DESCARTADA;
                $run->descartada_at = Carbon::now();
                $run->save();
            }

            return self::respuesta(200, ['run_id' => (int) $run->id, 'estado' => (string) $run->estado]);
        });
    }
}
