<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiMessage;
use App\Models\Provider;
use Carbon\Carbon;

/**
 * A QUÉ PROVEEDOR VA UNA COMPRA CON FACTURA, sin duplicar proveedores ni creárselos al emisor de la
 * factura cuando el dueño dijo otro (misión asistente-fotos-barras-y-compras, correcciones del
 * 24/9/2026, después de 17 conversaciones reales contra Anthropic).
 *
 * 🔴 LOS DOS CASOS REALES QUE ESTO CIERRA:
 *
 *   1. DUPLICADOS. El modelo lee en la factura "Distribuidora Sur S.R.L." y el dueño lo tiene
 *      guardado como "Distribuidora Sur". El LIKE de antes iba en una sola dirección (lo guardado
 *      tenía que CONTENER lo leído), así que no lo encontraba, y en "resuelto" daba de alta un
 *      proveedor duplicado sin preguntar. Ahora se compara NORMALIZADO (minúsculas, sin tildes ni
 *      puntuación, sin sufijos societarios) y en las DOS direcciones, contra `name` y
 *      `razon_social`, siempre dentro de los proveedores del dueño.
 *   2. EL EMISOR EN VEZ DEL QUE DIJO LA PERSONA. El dueño dijo "Perez Hnos Mayorista" y el modelo
 *      rápido llamó proponer_compra_con_factura con "Global Sources S.A." (el emisor que leyó en la
 *      factura); en "resuelto" se ejecutó y quedó un proveedor que nadie pidió. Ahora un proveedor
 *      NUEVO se crea sin confirmar sólo si su nombre aparece en lo que ESCRIBIÓ la persona
 *      (lo_dijo_la_persona()). Si no, la compra queda como tarjeta aunque el modo sea "resuelto".
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class ProveedorDeLaFacturaIaHelper
{
    /** Tope de candidatos que se ofrecen cuando el nombre encaja con varios. */
    const TOPE_CANDIDATOS = 10;

    /**
     * Sufijos societarios que no distinguen a un proveedor de otro: "Distribuidora Sur S.R.L." y
     * "Distribuidora Sur" son el mismo.
     *
     * ⚠️ "hnos" y "hermanos" NO están acá (segundo chequeo adversarial, 24/9/2026): sacarlos hacía
     * que "Perez" fuera igual a "Perez Hnos", y "Los Hermanos" quedara en "los". Se llevan a un
     * token canónico (CANONICOS): "Pérez Hnos." = "Perez Hermanos", pero "Perez" ≠ "Perez Hnos".
     */
    const SUFIJOS = ['sa', 'srl', 'sas', 'sh', 'sac', 'saic', 'saci', 'sca', 'scs', 'sau', 'ltda', 'cia'];

    /** Palabras que se escriben de varias formas y son la misma: se llevan a una sola. */
    const CANONICOS = ['hermanos' => 'hnos', 'hnos' => 'hnos', 'hno' => 'hnos', 'hermano' => 'hnos'];

    /** Palabras que no alcanzan para decir que la persona nombró al proveedor. */
    const PALABRAS_VACIAS = ['de', 'del', 'la', 'las', 'los', 'el', 'y', 'e', 'con', 'para', 'por', 'en'];

    /**
     * Cuántas palabras significativas (de 3 letras o más, sin PALABRAS_VACIAS) tiene que tener un
     * nombre GUARDADO para reconocerlo adentro de uno más largo leído en la factura. Con una sola, un
     * nombre corto matcheaba todo: "Mayorista", "Juan S.A." ("juan"), "Los Hermanos", "Sa-Ra" ("ra")
     * aparecían adentro de cualquier razón social (segundo chequeo adversarial, 24/9/2026).
     */
    const PALABRAS_PARA_CONTENERSE = 2;

    /** Cuántos mensajes de la persona se miran para saber si nombró al proveedor. */
    const MENSAJES_DE_LA_PERSONA = 10;

    /** Cuántos dígitos tiene un CUIT. */
    const DIGITOS_CUIT = 11;

    /**
     * El proveedor del dueño que corresponde a `$nombre` (y a `$cuit` si vino); `['nuevo' => nombre]`
     * si no hay ninguno; o la respuesta de negocio que pregunta cuál.
     *
     * El orden:
     *   1. CUIT: si vino y coincide con uno, es ése — salvo que el nombre no tenga nada que ver con
     *      ese proveedor Y lo haya dicho la persona. El CUIT lo lee el modelo de la factura, y la
     *      factura puede ser de otro emisor que el que nombró el dueño: ahí manda lo que dijo el
     *      dueño. Pero si el nombre también salió de la factura (el dueño no nombró a nadie), el CUIT
     *      es la mejor pista: el dueño lo tiene guardado como "El Turco" y la factura dice
     *      "Distribuidora Anatolia SA".
     *   2. Nombre normalizado IGUAL al `name` o a la `razon_social`. Si hay varios, gana el que
     *      coincide escrito tal cual; si no, se pregunta.
     *   3. Nombre normalizado CONTENIDO en el guardado o el guardado contenido en el nombre (las dos
     *      direcciones: ver el caso 1 del docblock de la clase). Uno solo: ése. Varios: se pregunta.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $nombre
     * @param  string  $cuit
     * @param  \App\Models\AiMessage|null  $mensaje  El assistant que propone (para saber qué dijo la persona).
     * @return \App\Models\Provider|array
     */
    public static function resolver(ContextoDeCargaIa $contexto, $nombre, $cuit = '', $mensaje = null)
    {
        $nombre = trim((string) $nombre);
        $buscado = self::normalizar($nombre);
        $cuit = self::solo_digitos($cuit);

        if ($buscado === '' && strlen($cuit) !== self::DIGITOS_CUIT) {

            return RespuestaDeCargaIa::faltan(
                ['de qué proveedor es la factura (el que dijo la persona; si no dijo ninguno, el emisor que leés en la factura)'],
                ['proveedores' => self::como_opciones(self::proveedores_del_dueno($contexto->owner_id)->take(self::TOPE_CANDIDATOS))]
            );
        }

        $proveedores = self::proveedores_del_dueno($contexto->owner_id);

        if (strlen($cuit) === self::DIGITOS_CUIT) {

            foreach ($proveedores as $proveedor) {

                if (self::solo_digitos($proveedor->cuit) !== $cuit) {

                    continue;
                }

                if ($buscado === '' || self::relacionado($proveedor, $buscado)) {

                    return $proveedor;
                }

                $lo_dijo = $mensaje instanceof AiMessage && self::lo_dijo_la_persona($contexto, $mensaje, $nombre);

                if (!$lo_dijo) {

                    return $proveedor;
                }
            }
        }

        if ($buscado === '') {

            return RespuestaDeCargaIa::faltan(['de qué proveedor es la factura']);
        }

        $iguales = [];
        $contenidos = [];

        /* Los que entraron SÓLO porque lo guardado está adentro de lo leído: ver más abajo. */
        $solo_por_lo_guardado = [];

        foreach ($proveedores as $proveedor) {

            $nombres = self::nombres_normalizados($proveedor);

            if (in_array($buscado, $nombres, true)) {

                $iguales[] = $proveedor;

                continue;
            }

            $leido_en_guardado = false;
            $guardado_en_leido = false;

            foreach ($nombres as $guardado) {

                if (self::contiene($guardado, $buscado)) {

                    $leido_en_guardado = true;
                }

                if (self::contiene($buscado, $guardado) && count(self::palabras_significativas_de($guardado)) >= self::PALABRAS_PARA_CONTENERSE) {

                    $guardado_en_leido = true;
                }
            }

            if ($leido_en_guardado || $guardado_en_leido) {

                $contenidos[] = $proveedor;

                if (!$leido_en_guardado) {

                    $solo_por_lo_guardado[] = (int) $proveedor->id;
                }
            }
        }

        if (count($iguales) === 1) {

            return $iguales[0];
        }

        if (count($iguales) > 1) {

            /* "Distribuidora Sur" y "Distribuidora Sur SRL" normalizan igual: gana el escrito tal cual. */
            $tal_cual = [];

            foreach ($iguales as $proveedor) {

                if (mb_strtolower(trim((string) $proveedor->name)) === mb_strtolower($nombre)
                    || mb_strtolower(trim((string) $proveedor->razon_social)) === mb_strtolower($nombre)) {

                    $tal_cual[] = $proveedor;
                }
            }

            if (count($tal_cual) === 1) {

                return $tal_cual[0];
            }

            return self::preguntar_cual($iguales);
        }

        if (count($contenidos) === 1) {

            /*
             * ⚠️ Si el único candidato salió porque su nombre guardado está ADENTRO del leído
             * ("Distribuidora Anatolia" adentro de "Distribuidora Anatolia Mayorista del Sur"), no se
             * autoejecuta: se pregunta si es ése. Es la dirección más floja de las dos, y en
             * "resuelto" una compra no confirmada iría a un proveedor que nadie eligió.
             */
            if (in_array((int) $contenidos[0]->id, $solo_por_lo_guardado, true)) {

                return RespuestaDeCargaIa::faltan(
                    ['si la factura es de "' . $contenidos[0]->name . '" (lo encontré porque su nombre está adentro de "' . $nombre . '"): preguntale a la persona si es ése'],
                    ['proveedores' => self::como_opciones([$contenidos[0]])]
                );
            }

            return $contenidos[0];
        }

        if (count($contenidos) > 1) {

            return self::preguntar_cual($contenidos);
        }

        return ['nuevo' => $nombre];
    }

    /**
     * El proveedor del dueño que ya se llama así (por `name` o `razon_social`, normalizado), o null.
     * Lo usa la ejecución de la compra para no duplicar un proveedor que alguien dio de alta entre
     * la propuesta y el sí.
     *
     * @param  int  $owner_id
     * @param  string  $nombre
     * @return \App\Models\Provider|null
     */
    public static function existente($owner_id, $nombre)
    {
        $buscado = self::normalizar($nombre);

        if ($buscado === '') {

            return null;
        }

        foreach (self::proveedores_del_dueno($owner_id) as $proveedor) {

            if (in_array($buscado, self::nombres_normalizados($proveedor), true)) {

                return $proveedor;
            }
        }

        return null;
    }

    /**
     * true si el nombre del proveedor aparece en lo que ESCRIBIÓ la persona en esta conversación:
     * todas sus palabras significativas, normalizadas, están en los últimos mensajes `user`
     * anteriores al assistant que propone. Ver el caso 2 del docblock de la clase: es lo que separa
     * "lo dijo el dueño" de "lo leyó el modelo en la factura".
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  string  $nombre
     * @return bool
     */
    public static function lo_dijo_la_persona(ContextoDeCargaIa $contexto, AiMessage $mensaje, $nombre)
    {
        $palabras = self::palabras_significativas($nombre);

        if (!count($palabras)) {

            return false;
        }

        $textos = AiMessage::where('ai_conversation_id', $contexto->conversation->id)
                            ->where('rol', 'user')
                            ->where('id', '<', (int) $mensaje->id)
                            ->where('created_at', '>=', Carbon::now()->subHours(FotosDeLaConversacionIaHelper::HORAS))
                            ->orderBy('id', 'DESC')
                            ->limit(self::MENSAJES_DE_LA_PERSONA)
                            ->pluck('contenido')
                            ->all();

        $dicho = ' ' . self::normalizar(implode(' ', $textos), false) . ' ';

        foreach ($palabras as $palabra) {

            if (mb_strpos($dicho, ' ' . $palabra . ' ') === false) {

                return false;
            }
        }

        return true;
    }

    /**
     * Minúsculas, sin tildes, sin puntuación y, con `$sin_sufijos`, sin los sufijos societarios.
     * "Distribuidora Sur S.R.L." → "distribuidora sur"; "Pérez Hnos." → "perez".
     *
     * @param  string  $texto
     * @param  bool  $sin_sufijos
     * @return string
     */
    public static function normalizar($texto, $sin_sufijos = true)
    {
        $texto = mb_strtolower(trim((string) $texto));

        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);

        /* El punto se saca sin dejar espacio, así "S.R.L." queda "srl" y no "s r l". */
        $texto = str_replace('.', '', $texto);

        $palabras = preg_split('/[^a-z0-9]+/', $texto, -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($palabras)) {

            return '';
        }

        /* "S. A." sin puntos queda "s a": las letras sueltas seguidas se juntan en una palabra. */
        $juntas = [];
        $letras = '';

        /* "Hermanos", "Hno." y "Hnos." son la misma palabra (ver CANONICOS). */
        foreach ($palabras as $i => $palabra) {

            if (isset(self::CANONICOS[$palabra])) {

                $palabras[$i] = self::CANONICOS[$palabra];
            }
        }

        foreach ($palabras as $palabra) {

            if (strlen($palabra) === 1 && !ctype_digit($palabra)) {

                $letras .= $palabra;

                continue;
            }

            if ($letras !== '') {

                $juntas[] = $letras;
                $letras = '';
            }

            $juntas[] = $palabra;
        }

        if ($letras !== '') {

            $juntas[] = $letras;
        }

        if ($sin_sufijos) {

            $juntas = array_values(array_filter($juntas, function ($palabra) {
                return !in_array($palabra, self::SUFIJOS, true);
            }));

            /* "Perez y Cía." sin el sufijo deja una "y" colgando al final. */
            while (count($juntas) && in_array(end($juntas), ['y', 'e'], true)) {

                array_pop($juntas);
            }
        }

        return implode(' ', $juntas);
    }

    /**
     * Las palabras significativas de un nombre YA normalizado: de tres letras o más y sin
     * PALABRAS_VACIAS. Sin el "si no hay ninguna, todas" de palabras_significativas(): acá se
     * cuentan para decidir si un nombre guardado alcanza para reconocerse adentro de otro.
     *
     * @param  string  $normalizado
     * @return array<int, string>
     */
    protected static function palabras_significativas_de($normalizado)
    {
        if ((string) $normalizado === '') {

            return [];
        }

        return array_values(array_filter(explode(' ', (string) $normalizado), function ($palabra) {
            return mb_strlen($palabra) >= 3 && !in_array($palabra, self::PALABRAS_VACIAS, true);
        }));
    }

    /**
     * Las palabras del nombre que alcanzan para reconocerlo en un texto: sin sufijos ni palabras
     * vacías, de tres letras o más (con ninguna, todas las que tenga).
     *
     * @param  string  $nombre
     * @return array<int, string>
     */
    protected static function palabras_significativas($nombre)
    {
        $normalizado = self::normalizar($nombre);

        if ($normalizado === '') {

            return [];
        }

        $todas = explode(' ', $normalizado);

        $significativas = array_values(array_filter($todas, function ($palabra) {
            return mb_strlen($palabra) >= 3 && !in_array($palabra, self::PALABRAS_VACIAS, true);
        }));

        return count($significativas) ? $significativas : $todas;
    }

    /**
     * true si el proveedor tiene algo que ver con el nombre buscado (igual o contenido, en las dos
     * direcciones).
     *
     * @param  \App\Models\Provider  $proveedor
     * @param  string  $buscado  Ya normalizado.
     * @return bool
     */
    protected static function relacionado(Provider $proveedor, $buscado)
    {
        foreach (self::nombres_normalizados($proveedor) as $guardado) {

            if ($guardado === $buscado || self::contiene($guardado, $buscado) || self::contiene($buscado, $guardado)) {

                return true;
            }
        }

        return false;
    }

    /**
     * true si `$aguja` aparece en `$pajar` como palabras enteras ("sur" en "distribuidora sur", pero
     * no "sur" en "surtidora").
     *
     * @param  string  $pajar
     * @param  string  $aguja
     * @return bool
     */
    protected static function contiene($pajar, $aguja)
    {
        if ($pajar === '' || $aguja === '') {

            return false;
        }

        return mb_strpos(' ' . $pajar . ' ', ' ' . $aguja . ' ') !== false;
    }

    /**
     * El `name` y la `razon_social` del proveedor, normalizados y sin vacíos.
     *
     * @param  \App\Models\Provider  $proveedor
     * @return array<int, string>
     */
    protected static function nombres_normalizados(Provider $proveedor)
    {
        $nombres = [];

        foreach ([$proveedor->name, $proveedor->razon_social] as $valor) {

            $normalizado = self::normalizar($valor);

            if ($normalizado !== '') {

                $nombres[] = $normalizado;
            }
        }

        return $nombres;
    }

    /**
     * Los proveedores del dueño, sólo con las columnas que hacen falta para reconocerlos. Se traen
     * todos a propósito: la normalización (sufijos, puntos, letras sueltas) no se puede hacer en un
     * LIKE, y un negocio tiene decenas o cientos de proveedores, no cientos de miles.
     *
     * @param  int  $owner_id
     * @return \Illuminate\Support\Collection
     */
    protected static function proveedores_del_dueno($owner_id)
    {
        return Provider::where('user_id', (int) $owner_id)
                        ->orderBy('name')
                        ->get(['id', 'user_id', 'name', 'razon_social', 'cuit', 'precios_incluyen_iva']);
    }

    /**
     * @param  array<int, \App\Models\Provider>  $candidatos
     * @return array
     */
    protected static function preguntar_cual(array $candidatos)
    {
        return RespuestaDeCargaIa::faltan(
            ['cuál de estos proveedores es'],
            ['proveedores' => self::como_opciones(array_slice($candidatos, 0, self::TOPE_CANDIDATOS))]
        );
    }

    /**
     * @param  iterable  $proveedores
     * @return array
     */
    protected static function como_opciones($proveedores)
    {
        $opciones = [];

        foreach ($proveedores as $proveedor) {

            $opciones[] = ['id' => (int) $proveedor->id, 'proveedor' => (string) $proveedor->name];
        }

        return $opciones;
    }

    /**
     * @param  mixed  $valor
     * @return string
     */
    protected static function solo_digitos($valor)
    {
        return (string) preg_replace('/\D+/', '', (string) $valor);
    }
}
