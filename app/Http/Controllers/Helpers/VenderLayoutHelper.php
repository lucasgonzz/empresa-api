<?php

namespace App\Http\Controllers\Helpers;

use App\Models\User;
use App\Models\VenderLayout;
use Illuminate\Support\Facades\DB;

/**
 * Lógica de los "Diseños de Vender" (misión diseno-vender-configurable, 28/9/2026) que comparten el
 * controller (`VenderLayoutController`), el seeder (`VenderLayoutSeeder`) y los setups de sistema
 * nuevo y de demo.
 *
 * Tres responsabilidades:
 *
 *   1. El "Diseño predeterminado": que todo dueño tenga uno, sin duplicarlo nunca.
 *   2. El invariante "exactamente un diseño en uso por dueño": `poner_en_uso()`.
 *   3. La normalización del JSON que manda el SPA: `normalizar_layout()`.
 *
 * -------------------------------------------------------------------------------------------
 * 🔴 EL BACKEND NO CONOCE EL CATÁLOGO DE CAMPOS, Y ES A PROPÓSITO
 * -------------------------------------------------------------------------------------------
 *
 * Qué campos existen, en qué etapa van por defecto y cuáles son obligatorios lo sabe solamente el
 * SPA (`src/components/vender/layout/elementos.js`). Acá se normaliza la FORMA del diseño para que
 * no entre basura en la base (claves con otro formato, anchos fuera de la grilla, listas
 * gigantes), pero NO se valida que cada `key` exista. Así un diseño guardado sigue siendo válido
 * cuando el SPA agregue un campo nuevo o retire uno viejo: el resolver del SPA ignora las keys que
 * no conoce y ubica solo los campos que el diseño no nombra.
 *
 * PHP 7.4 estricto: ninguna sintaxis de PHP 8 en ningún camino de este archivo.
 */
class VenderLayoutHelper
{
    /** Nombre del diseño que se crea para cada dueño que no tiene ninguno. */
    const NOMBRE_PREDETERMINADO = 'Diseño predeterminado';

    /** Nombre del diseño "ágil" que se siembra sin uso junto al predeterminado. */
    const NOMBRE_AGIL = 'Diseño ágil';

    /**
     * Versión del formato del JSON que guarda el editor: la 2 (secciones, disposición y plantilla,
     * misión vender-secciones-personalizables). Es la misma `VERSION_DEL_FORMATO` del SPA. El
     * formato versión 1 (3 etapas fijas) se sigue aceptando y se normaliza como siempre.
     */
    const VERSION_DEL_FORMATO = 2;

    /** Versión del formato viejo (3 etapas fijas, sin `disposicion`). */
    const VERSION_V1 = 1;

    /** Las etapas de Vender del formato versión 1. En el v1 cualquier otra clave de `etapas` se descarta. */
    const ETAPAS = array('etapa_1', 'etapa_2', 'etapa_3');

    /**
     * Patrón del id de una sección del formato v2: las tres etapas de siempre y hasta seis creadas
     * por el usuario (`seccion_4` a `seccion_9`). Lleva `D` por el mismo motivo que los de abajo.
     */
    const PATRON_SECCION = '/^(etapa_[123]|seccion_[4-9])$/D';

    /** La sección que nunca puede faltar en `etapas` (lleva la entrada de artículos y el resumen). */
    const SECCION_FIJA = 'etapa_2';

    /** Topes de la disposición: secciones en total, bandas y columnas por banda. */
    const MAXIMO_DE_SECCIONES = 6;
    const MAXIMO_DE_BANDAS = 6;
    const MAXIMO_DE_COLUMNAS = 4;

    /** Ancho mínimo de una columna de la disposición (el máximo es COLS_MAXIMO). */
    const ANCHO_MINIMO_DE_COLUMNA = 3;

    /** Paleta cerrada de colores de sección. */
    const COLORES = array('azul', 'verde', 'naranja', 'violeta', 'rojo', 'turquesa', 'gris');

    /** Valores válidos de `plegado` de una sección. */
    const PLEGADOS = array('no', 'si_hay_venta', 'arranca_plegada');

    /** Plantillas del sistema que el diseño puede declarar como origen. */
    const PLANTILLAS = array('agil');

    /** Largos máximos (en caracteres) del título y de la descripción de una sección. */
    const LARGO_TITULO = 60;
    const LARGO_DESCRIPCION = 160;

    /** Alto (en doceavos) de una sección cuando el valor no es numérico, y su rango válido. */
    const ALTO_POR_DEFECTO = 4;
    const ALTO_MINIMO = 1;
    const ALTO_MAXIMO = 12;

    /*
     * 🔴 Los dos patrones llevan el modificador `D` (PCRE_DOLLAR_ENDONLY), y no es decoración. Sin él,
     * en PHP el `$` también matchea ANTES de un salto de línea final, así que 'caja' seguido de "\n"
     * pasaría el filtro y quedaría guardado con el salto adentro. En JavaScript el `$` sin la bandera
     * `m` ya se comporta así, o sea que con la `D` el backend valida exactamente lo mismo que
     * `PATRON_ID_SEPARADOR` de resolver_diseno.js.
     */

    /** Patrón de la `key` de un campo (y de cada entrada de `sacados`). */
    const PATRON_KEY = '/^[a-z0-9_]{1,60}$/D';

    /** Patrón del `id` opcional de un ítem (lo usan los separadores, que pueden ser varios). */
    const PATRON_ID = '/^[a-z0-9_]{1,40}$/D';

    /*
     * Topes de tamaño. El catálogo de Vender tiene unos 35 campos, así que ningún diseño legítimo
     * se acerca: los topes están para que un cliente no pueda guardar un JSON arbitrariamente grande.
     */

    /** Máximo de ítems por etapa. Los que sobran se descartan (se conservan los primeros). */
    const MAXIMO_DE_ITEMS_POR_ETAPA = 100;

    /** Máximo de keys en `sacados`, ya sin repetidos. */
    const MAXIMO_DE_SACADOS = 100;

    /** Ancho mínimo y máximo de un ítem, en columnas de la grilla de Bootstrap. */
    const COLS_MINIMO = 1;
    const COLS_MAXIMO = 12;

    /** El ancho que toma un `cols` que no es un número: todo el ancho de la fila. */
    const COLS_SI_NO_ES_NUMERO = 12;

    /*
     * ---------------------------------------------------------------------------------------
     *  El diseño predeterminado
     * ---------------------------------------------------------------------------------------
     */

    /**
     * Si el dueño no tiene NINGÚN diseño, le crea el "Diseño predeterminado" (`layout` null, en uso).
     *
     * Lo llaman el index del ABM (la primera vez que un dueño abre el sistema después del deploy)
     * y `VenderLayoutSeeder` (para las bases existentes y los setups de sistema nuevo y de demo).
     *
     * `layout = null` es "el diseño del sistema": el SPA lo arma en código
     * (`diseno_predeterminado.js`), así el default no se duplica entre PHP y JS y un negocio que no
     * tocó nada sigue viendo Vender exactamente como hasta hoy.
     *
     * -------------------------------------------------------------------------------------------
     * 🔴 POR QUÉ EL CANDADO SOBRE LA FILA DEL DUEÑO EN `users`
     * -------------------------------------------------------------------------------------------
     *
     * "Mirar si tiene y, si no, crear" son dos pasos, y el index lo corren a la vez varias pestañas
     * y varios empleados del mismo negocio al iniciar sesión (todos piden los catálogos juntos por
     * `recursos-iniciales`). Sin serializar, dos pedidos ven "no tiene" al mismo tiempo y el dueño
     * termina con dos predeterminados, los dos en uso.
     *
     * No hay un unique que lo impida (ver la migración), y bloquear filas de `vender_layouts` no
     * alcanza: cuando el dueño todavía no tiene ninguna, no hay fila que bloquear. La fila del dueño
     * en `users` sí existe siempre, así que el `SELECT ... FOR UPDATE` sobre ella hace que el segundo
     * pedido espere a que el primero termine. Es el mismo recurso que usa `Controller::num()` para
     * los correlativos por dueño.
     *
     * El "¿ya tiene alguno?" se pregunta con un `exists()` común y NO con un SELECT bloqueante sobre
     * `vender_layouts`, por dos motivos:
     *   - Alcanza: en REPEATABLE READ la foto de las lecturas comunes se toma en la primera lectura
     *     común de la transacción, que acá es DESPUÉS de conseguir el candado. El segundo pedido ya
     *     ve lo que el primero commiteó.
     *   - Un SELECT bloqueante sobre un rango vacío de `user_id` toma un "gap lock", y dos dueños
     *     DISTINTOS sembrándose a la vez (el seeder en una base compartida, dos negocios entrando al
     *     mismo tiempo) se trabarían entre sí hasta que InnoDB matara a uno por deadlock. El candado
     *     sobre `users` es por clave primaria: no traba a nadie más que al mismo dueño.
     *
     * Si el id no existe o es de un empleado no crea nada: los diseños son siempre del dueño.
     *
     * @param  int  $owner_id  El dueño (`owner_id` null en `users`).
     * @return \App\Models\VenderLayout|null  El diseño creado, o null si no hacía falta crearlo.
     */
    static function crear_predeterminado_si_no_tiene($owner_id)
    {
        return DB::transaction(function () use ($owner_id) {

            $dueno = self::bloquear_dueno($owner_id);

            if (is_null($dueno) || !is_null($dueno->owner_id)) {
                return null;
            }

            if (VenderLayout::where('user_id', $owner_id)->exists()) {
                return null;
            }

            return VenderLayout::create([
                'user_id' => $owner_id,
                'name'    => self::NOMBRE_PREDETERMINADO,
                'layout'  => null,
                'en_uso'  => true,
            ]);
        });
    }

    /**
     * Siembra el "Diseño ágil" (sin uso) del dueño que todavía no tiene ningún diseño con
     * `plantilla = 'agil'` (misión vender-secciones-personalizables).
     *
     * El diseño ágil se arma en código en el SPA: la base sólo guarda `{version: 2, plantilla: 'agil'}`.
     * Mismo candado que `crear_predeterminado_si_no_tiene()` (transacción + `bloquear_dueno`), así el
     * seeder y un dueño que entra al sistema a la vez no le crean dos.
     *
     * Primero asegura el predeterminado: el ágil nunca puede quedar como único diseño y en uso. Si el
     * dueño ya tiene algún diseño de plantilla ágil (aunque lo haya renombrado o editado) no hace
     * nada; el chequeo se hace en PHP sobre sus diseños porque `layout` es un longText. No toca cuál
     * diseño está en uso.
     *
     * @param  int  $owner_id  El dueño (`owner_id` null en `users`).
     * @return \App\Models\VenderLayout|null  El diseño creado, o null si no hacía falta crearlo.
     */
    static function crear_agil_si_no_tiene($owner_id)
    {
        return DB::transaction(function () use ($owner_id) {

            $dueno = self::bloquear_dueno($owner_id);

            // Los diseños son siempre del dueño: un empleado o un id inexistente no recibe nada.
            if (is_null($dueno) || !is_null($dueno->owner_id)) {
                return null;
            }

            // El predeterminado primero (no hace nada si el dueño ya tiene algún diseño).
            self::crear_predeterminado_si_no_tiene($owner_id);

            // ¿Ya tiene un diseño de plantilla ágil, con el nombre que tenga?
            $disenos = VenderLayout::where('user_id', $owner_id)->get(['id', 'layout']);

            foreach ($disenos as $diseno) {
                if (is_array($diseno->layout)
                    && isset($diseno->layout['plantilla'])
                    && $diseno->layout['plantilla'] === 'agil') {
                    return null;
                }
            }

            return VenderLayout::create([
                'user_id' => $owner_id,
                'name'    => self::NOMBRE_AGIL,
                'layout'  => array('version' => self::VERSION_DEL_FORMATO, 'plantilla' => 'agil'),
                'en_uso'  => false,
            ]);
        });
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  El invariante "exactamente uno en uso por dueño"
     * ---------------------------------------------------------------------------------------
     */

    /**
     * Toma el candado de la fila del dueño en `users` (`SELECT ... FOR UPDATE`) y la devuelve.
     *
     * Toda escritura sobre los diseños de un dueño pasa por acá primero, así el alta del
     * predeterminado, el "poner en uso" y el borrado de un mismo dueño quedan en fila y el
     * invariante no se puede romper por dos pedidos cruzados (por ejemplo, borrar un diseño justo
     * mientras otro pedido lo pone en uso). Ver el porqué completo en
     * `crear_predeterminado_si_no_tiene()`.
     *
     * 🔴 Solo sirve ADENTRO de una transacción: fuera de una, el candado se suelta en el mismo
     * instante en que termina la consulta.
     *
     * @param  int  $owner_id
     * @return \App\Models\User|null  El dueño bloqueado, o null si el id no existe.
     */
    static function bloquear_dueno($owner_id)
    {
        return User::where('id', $owner_id)->lockForUpdate()->first();
    }

    /**
     * El diseño pedido, solo si es de este dueño.
     *
     * 🔴 El filtro por `user_id` va en la consulta y no en un `if` después de un `find()`: es lo
     * único que separa los diseños de un negocio de los de otro en una base compartida.
     *
     * @param  mixed  $id
     * @param  int    $owner_id
     * @return \App\Models\VenderLayout|null
     */
    static function diseno_del_dueno($id, $owner_id)
    {
        return VenderLayout::where('id', $id)
                            ->where('user_id', $owner_id)
                            ->first();
    }

    /**
     * Deja el diseño en uso y apaga todos los demás del mismo dueño.
     *
     * Se llama siempre adentro de la MISMA transacción que la escritura del diseño y con el candado
     * del dueño tomado (`bloquear_dueno()`): si el apagado quedara afuera, un error en el medio
     * dejaría dos diseños en uso y cada sesión de Vender podría quedarse con uno distinto.
     *
     * Los que se apagan se buscan primero y se actualizan después por clave primaria, en vez de un
     * único `UPDATE ... WHERE user_id = ?`, por dos motivos: el controller necesita los ids para
     * notificar a las otras sesiones que esos diseños cambiaron, y un UPDATE por rango de `user_id`
     * toma gap locks que pueden trabar a otro dueño que esté guardando al mismo tiempo. Con el
     * candado del dueño tomado, entre la búsqueda y el UPDATE nadie más puede tocar sus diseños.
     *
     * El UPDATE de Eloquent también renueva el `updated_at` de los apagados, que es lo que el SPA
     * usa para desempatar si alguna vez viera dos en uso.
     *
     * @param  \App\Models\VenderLayout  $model
     * @return array  Los ids de los diseños que se apagaron (vacío si no había otro en uso).
     */
    static function poner_en_uso($model)
    {
        if (!$model->en_uso) {
            $model->en_uso = true;
            $model->save();
        }

        $apagados = VenderLayout::where('user_id', $model->user_id)
                                ->where('id', '!=', $model->id)
                                ->where('en_uso', 1)
                                ->pluck('id')
                                ->all();

        if (!empty($apagados)) {
            VenderLayout::whereIn('id', $apagados)->update(['en_uso' => false]);
        }

        return $apagados;
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  Normalización del JSON del diseño
     * ---------------------------------------------------------------------------------------
     */

    /**
     * Normaliza el `layout` que manda el SPA antes de guardarlo.
     *
     * Formato de salida versión 1 (el v2, con secciones y disposición, se arma en `normalizar_v2()`):
     *
     *   [
     *     'version' => 1,
     *     'etapas'  => [
     *       'etapa_1' => [ ['key' => 'metodo_de_pago', 'cols' => 4], ['key' => 'separador', 'id' => 'separador_1', 'cols' => 12] ],
     *       'etapa_2' => [ ... ],
     *       'etapa_3' => [ ... ],
     *     ],
     *     'sacados' => ['combos'],
     *   ]
     *
     * Reglas:
     *   - `null` (o el string JSON `null`) es válido y queda `null`: el diseño del sistema.
     *   - Acepta un array o un string JSON. Lo que no es JSON, no es un objeto o no trae `etapas`
     *     como objeto es INVÁLIDO: el controller responde 422.
     *   - De `etapas` quedan solo `etapa_1`, `etapa_2` y `etapa_3`; las que falten quedan `[]`.
     *   - Cada ítem necesita una `key` que cumpla `PATRON_KEY`; si no, se DESCARTA el ítem (no el
     *     diseño entero). `cols` se acota a 1..12 y, si no es un número, toma 12. El `id` es
     *     opcional: si no cumple `PATRON_ID` se descarta el id, no el ítem (el resolver del SPA le
     *     asigna uno estable al separador que no lo trae). Cualquier otra propiedad se descarta.
     *   - Máximo 100 ítems por etapa y 100 `sacados` (sin repetidos, mismo patrón que la key).
     *   - Sin `disposicion` (lista) se guarda `version: 1`, venga lo que venga; con ella, el v2.
     *   - Sólo `{plantilla: 'agil'}` (sin `etapas`) se guarda como `{version: 2, plantilla: 'agil'}`.
     *
     * Los repetidos DENTRO de las etapas no se sacan acá: los separadores comparten la key
     * `separador` y se distinguen por el id, y deduplicar requiere saber qué es un separador, que es
     * conocimiento del catálogo del SPA. Lo hace `resolver_diseno()`.
     *
     * `cols` se trunca (4.9 -> 4) igual que el `parseInt` de `acotar_cols()` en elementos.js, así un
     * diseño se ve con el mismo ancho recién guardado que después de recargar.
     *
     * Un JSON `{}` y un `[]` llegan iguales a PHP (los dos son `array()`), así que un `etapas` vacío
     * se toma como objeto vacío: las tres etapas quedan vacías y el SPA ubica cada campo en su etapa
     * por defecto. Una lista NO vacía en `etapas`, en cambio, es inválida.
     *
     * @param  mixed  $valor
     * @return array  `[ok (bool), layout (array|null)]`. Con `ok` en false el layout es null y el
     *                controller responde 422.
     */
    static function normalizar_layout($valor)
    {
        if (is_null($valor)) {
            return array(true, null);
        }

        if (is_string($valor)) {
            $valor = json_decode($valor, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return array(false, null);
            }

            // El string era el literal JSON `null`: vale lo mismo que mandar null.
            if (is_null($valor)) {
                return array(true, null);
            }
        }

        if (!is_array($valor)) {
            return array(false, null);
        }

        // Sólo la plantilla (sin `etapas`): "el diseño de la plantilla armado en código".
        if (!array_key_exists('etapas', $valor)
            && array_key_exists('plantilla', $valor)
            && self::es_plantilla_valida($valor['plantilla'])) {
            return array(true, array(
                'version'   => self::VERSION_DEL_FORMATO,
                'plantilla' => $valor['plantilla'],
            ));
        }

        if (!array_key_exists('etapas', $valor) || !self::es_objeto($valor['etapas'])) {
            return array(false, null);
        }

        // Con `disposicion` como lista es el formato v2; sin ella, el v1 de siempre.
        if (array_key_exists('disposicion', $valor) && self::es_lista($valor['disposicion'])) {
            return self::normalizar_v2($valor);
        }

        $etapas = array();

        foreach (self::ETAPAS as $etapa) {
            $items = array_key_exists($etapa, $valor['etapas']) ? $valor['etapas'][$etapa] : array();

            $etapas[$etapa] = self::normalizar_etapa($items);
        }

        $sacados = array_key_exists('sacados', $valor) ? $valor['sacados'] : array();

        return array(true, array(
            'version' => self::VERSION_V1,
            'etapas'  => $etapas,
            'sacados' => self::normalizar_sacados($sacados),
        ));
    }

    /**
     * Normaliza un layout del formato versión 2 (con `disposicion` como lista).
     *
     * Salida, en este orden de claves: `version` (2), `plantilla` (sólo si es válida), `etapas`,
     * `sacados`, `secciones` y `disposicion`. `etapas` y `secciones` traen una entrada por cada id
     * de la disposición (en orden de aparición) y siempre `etapa_2`. Una disposición que queda vacía
     * después de descartar lo inválido es inválida. El backend no completa anchos ni agrega
     * `etapa_2` a la disposición: eso lo resuelve el SPA.
     *
     * @param  array  $valor  El layout ya decodificado, con `etapas` objeto y `disposicion` lista.
     * @return array  `[ok (bool), layout (array|null)]`.
     */
    private static function normalizar_v2($valor)
    {
        // Los ids ya usados en todo el diseño (cada sección aparece una sola vez).
        $vistos = array();

        $disposicion = self::normalizar_disposicion($valor['disposicion'], $vistos);

        if (empty($disposicion)) {
            return array(false, null);
        }

        // Ids de las secciones, en el orden de aparición de la disposición.
        $ids = array_keys($vistos);

        // `etapa_2` no puede faltar aunque la disposición no la nombre.
        if (!isset($vistos[self::SECCION_FIJA])) {
            $ids[] = self::SECCION_FIJA;
        }

        $etapas = array();
        $secciones = array();

        $metadatos = (array_key_exists('secciones', $valor) && self::es_objeto($valor['secciones']))
            ? $valor['secciones']
            : array();

        foreach ($ids as $id) {
            $items = array_key_exists($id, $valor['etapas']) ? $valor['etapas'][$id] : array();

            $etapas[$id] = self::normalizar_etapa($items);
            $secciones[$id] = self::normalizar_seccion(array_key_exists($id, $metadatos) ? $metadatos[$id] : null);
        }

        $sacados = array_key_exists('sacados', $valor) ? $valor['sacados'] : array();

        $normalizado = array('version' => self::VERSION_DEL_FORMATO);

        if (array_key_exists('plantilla', $valor) && self::es_plantilla_valida($valor['plantilla'])) {
            $normalizado['plantilla'] = $valor['plantilla'];
        }

        $normalizado['etapas'] = $etapas;
        $normalizado['sacados'] = self::normalizar_sacados($sacados);
        $normalizado['secciones'] = $secciones;
        $normalizado['disposicion'] = $disposicion;

        return array(true, $normalizado);
    }

    /**
     * Si el valor es el nombre de una plantilla del sistema conocida.
     *
     * @param  mixed  $plantilla
     * @return bool
     */
    private static function es_plantilla_valida($plantilla)
    {
        return is_string($plantilla) && in_array($plantilla, self::PLANTILLAS, true);
    }

    /**
     * La disposición: bandas de arriba abajo, cada una con columnas de izquierda a derecha, cada
     * columna con los ids de sus secciones apiladas.
     *
     * Descarta lo inválido (ids fuera del patrón, repetidos, columnas sin secciones, bandas sin
     * columnas, propiedades desconocidas) y respeta los topes de bandas, columnas por banda y
     * secciones en total. Los anchos se acotan a 3..12 (no numérico, 12) sin redistribuirlos.
     *
     * @param  array  $bandas  Lista de bandas tal como llegó.
     * @param  array  $vistos  Por referencia: ids de sección ya usados (clave = id), en orden.
     * @return array  La disposición normalizada (puede quedar vacía).
     */
    private static function normalizar_disposicion($bandas, array &$vistos)
    {
        $disposicion = array();

        foreach ($bandas as $banda) {

            if (count($disposicion) >= self::MAXIMO_DE_BANDAS) {
                break;
            }

            if (!self::es_objeto($banda)
                || !array_key_exists('columnas', $banda)
                || !self::es_lista($banda['columnas'])) {
                continue;
            }

            $columnas = array();

            foreach ($banda['columnas'] as $columna) {

                if (count($columnas) >= self::MAXIMO_DE_COLUMNAS) {
                    break;
                }

                if (!self::es_objeto($columna)) {
                    continue;
                }

                $ids = array();
                $candidatos = (array_key_exists('secciones', $columna) && self::es_lista($columna['secciones']))
                    ? $columna['secciones']
                    : array();

                foreach ($candidatos as $id) {

                    if (count($vistos) >= self::MAXIMO_DE_SECCIONES) {
                        break;
                    }

                    if (!is_string($id) || !preg_match(self::PATRON_SECCION, $id) || isset($vistos[$id])) {
                        continue;
                    }

                    $vistos[$id] = true;
                    $ids[] = $id;
                }

                // Una columna sin secciones válidas se descarta.
                if (empty($ids)) {
                    continue;
                }

                $columnas[] = array(
                    'ancho'     => self::normalizar_ancho_de_columna(array_key_exists('ancho', $columna) ? $columna['ancho'] : null),
                    'secciones' => $ids,
                );
            }

            // Una banda sin columnas válidas se descarta.
            if (!empty($columnas)) {
                $disposicion[] = array('columnas' => $columnas);
            }
        }

        return $disposicion;
    }

    /**
     * El ancho de una columna de la disposición, acotado a 3..12. Lo que no es un número toma 12.
     *
     * @param  mixed  $ancho
     * @return int
     */
    private static function normalizar_ancho_de_columna($ancho)
    {
        if (!is_numeric($ancho)) {
            return self::COLS_MAXIMO;
        }

        $numero = (float) $ancho;

        if (is_nan($numero)) {
            return self::COLS_MAXIMO;
        }

        if ($numero < self::ANCHO_MINIMO_DE_COLUMNA) {
            return self::ANCHO_MINIMO_DE_COLUMNA;
        }

        if ($numero > self::COLS_MAXIMO) {
            return self::COLS_MAXIMO;
        }

        return (int) $numero;
    }

    /**
     * Los metadatos de una sección normalizados, con claves `titulo`, `descripcion`, `color`,
     * `alto`, `alto_fijo` y `plegado`. Lo que falta o es inválido toma el valor por defecto
     * (título, descripción y color en null = los automáticos; alto 4; alto_fijo false; plegado
     * 'no'). Una sección de alto fijo no se pliega. Cualquier otra propiedad se descarta.
     *
     * @param  mixed  $meta  Lo que mandó el SPA para la sección (puede no ser un objeto).
     * @return array
     */
    private static function normalizar_seccion($meta)
    {
        if (!self::es_objeto($meta)) {
            $meta = array();
        }

        // Color: sólo de la paleta cerrada.
        $color = (array_key_exists('color', $meta) && is_string($meta['color']) && in_array($meta['color'], self::COLORES, true))
            ? $meta['color']
            : null;

        // Alto fijo: sólo true, 1, '1' y 'true'.
        $alto_fijo = array_key_exists('alto_fijo', $meta)
            && ($meta['alto_fijo'] === true || $meta['alto_fijo'] === 1 || $meta['alto_fijo'] === '1' || $meta['alto_fijo'] === 'true');

        // Plegado: de la lista, y 'no' si la sección es de alto fijo.
        $plegado = (array_key_exists('plegado', $meta) && is_string($meta['plegado']) && in_array($meta['plegado'], self::PLEGADOS, true))
            ? $meta['plegado']
            : 'no';

        if ($alto_fijo) {
            $plegado = 'no';
        }

        return array(
            'titulo'      => self::normalizar_texto(array_key_exists('titulo', $meta) ? $meta['titulo'] : null, self::LARGO_TITULO),
            'descripcion' => self::normalizar_texto(array_key_exists('descripcion', $meta) ? $meta['descripcion'] : null, self::LARGO_DESCRIPCION),
            'color'       => $color,
            'alto'        => self::normalizar_alto(array_key_exists('alto', $meta) ? $meta['alto'] : null),
            'alto_fijo'   => $alto_fijo,
            'plegado'     => $plegado,
        );
    }

    /**
     * Un texto libre normalizado: trim, recortado a `$largo` caracteres (multibyte) y null si
     * queda vacío o si no era un texto. El HTML se guarda tal cual: escapar es cosa del SPA.
     *
     * @param  mixed  $texto
     * @param  int    $largo
     * @return string|null
     */
    private static function normalizar_texto($texto, $largo)
    {
        if (!is_string($texto)) {
            return null;
        }

        $texto = trim(mb_substr(trim($texto), 0, $largo));

        return $texto === '' ? null : $texto;
    }

    /**
     * El alto de una sección en doceavos, entero acotado a 1..12 (se trunca, como `cols`). Lo que
     * no es un número toma 4.
     *
     * @param  mixed  $alto
     * @return int
     */
    private static function normalizar_alto($alto)
    {
        if (!is_numeric($alto)) {
            return self::ALTO_POR_DEFECTO;
        }

        $numero = (float) $alto;

        if (is_nan($numero)) {
            return self::ALTO_POR_DEFECTO;
        }

        if ($numero < self::ALTO_MINIMO) {
            return self::ALTO_MINIMO;
        }

        if ($numero > self::ALTO_MAXIMO) {
            return self::ALTO_MAXIMO;
        }

        return (int) $numero;
    }

    /**
     * Los ítems válidos de una etapa, en el orden en que vinieron, hasta `MAXIMO_DE_ITEMS_POR_ETAPA`.
     *
     * Una etapa que no es una lista (un número, un string, un objeto) queda vacía: es lo mismo que
     * hace el resolver del SPA con `Array.isArray()`.
     *
     * @param  mixed  $items
     * @return array
     */
    private static function normalizar_etapa($items)
    {
        $normalizados = array();

        if (!self::es_lista($items)) {
            return $normalizados;
        }

        foreach ($items as $item) {

            // Se corta al llegar al tope en vez de filtrar todo y recortar después: un arreglo
            // enorme no cuesta más que sus primeros ítems válidos.
            if (count($normalizados) >= self::MAXIMO_DE_ITEMS_POR_ETAPA) {
                break;
            }

            $normalizado = self::normalizar_item($item);

            if (!is_null($normalizado)) {
                $normalizados[] = $normalizado;
            }
        }

        return $normalizados;
    }

    /**
     * Un ítem de etapa normalizado a `{key, id?, cols}`, o null si no tiene una key válida.
     *
     * @param  mixed  $item
     * @return array|null
     */
    private static function normalizar_item($item)
    {
        if (!self::es_objeto($item)
            || !array_key_exists('key', $item)
            || !is_string($item['key'])
            || !preg_match(self::PATRON_KEY, $item['key'])) {
            return null;
        }

        $normalizado = array('key' => $item['key']);

        if (array_key_exists('id', $item)
            && is_string($item['id'])
            && preg_match(self::PATRON_ID, $item['id'])) {
            $normalizado['id'] = $item['id'];
        }

        $normalizado['cols'] = self::normalizar_cols(array_key_exists('cols', $item) ? $item['cols'] : null);

        return $normalizado;
    }

    /**
     * El ancho de un ítem acotado a 1..12. Lo que no es un número (null, un texto, un booleano)
     * toma 12.
     *
     * Se compara como float ANTES de pasar a entero: un número gigante (o infinito) pasado directo
     * a int es indefinido en PHP 7 y puede volver negativo. Comparado primero, cae en 12.
     *
     * @param  mixed  $cols
     * @return int
     */
    private static function normalizar_cols($cols)
    {
        if (!is_numeric($cols)) {
            return self::COLS_SI_NO_ES_NUMERO;
        }

        $numero = (float) $cols;

        if (is_nan($numero)) {
            return self::COLS_SI_NO_ES_NUMERO;
        }

        if ($numero < self::COLS_MINIMO) {
            return self::COLS_MINIMO;
        }

        if ($numero > self::COLS_MAXIMO) {
            return self::COLS_MAXIMO;
        }

        return (int) $numero;
    }

    /**
     * Las keys sacadas: strings con el patrón de key, sin repetidos (gana la primera), hasta
     * `MAXIMO_DE_SACADOS`. Si `sacados` no es una lista, queda vacío.
     *
     * @param  mixed  $sacados
     * @return array
     */
    private static function normalizar_sacados($sacados)
    {
        $normalizados = array();

        if (!self::es_lista($sacados)) {
            return $normalizados;
        }

        $vistos = array();

        foreach ($sacados as $key) {

            if (count($normalizados) >= self::MAXIMO_DE_SACADOS) {
                break;
            }

            if (!is_string($key) || !preg_match(self::PATRON_KEY, $key) || isset($vistos[$key])) {
                continue;
            }

            $vistos[$key] = true;
            $normalizados[] = $key;
        }

        return $normalizados;
    }

    /**
     * Si el valor es un array con forma de LISTA de JSON: claves 0, 1, 2... en orden. El array vacío
     * cuenta como lista.
     *
     * @param  mixed  $valor
     * @return bool
     */
    private static function es_lista($valor)
    {
        if (!is_array($valor)) {
            return false;
        }

        $esperada = 0;

        foreach ($valor as $clave => $ignorado) {
            if ($clave !== $esperada) {
                return false;
            }
            $esperada++;
        }

        return true;
    }

    /**
     * Si el valor es un array con forma de OBJETO de JSON: no es una lista, o está vacío (`{}` y `[]`
     * llegan iguales después del `json_decode` del request, así que el vacío vale como objeto).
     *
     * @param  mixed  $valor
     * @return bool
     */
    private static function es_objeto($valor)
    {
        return is_array($valor) && ($valor === array() || !self::es_lista($valor));
    }
}
