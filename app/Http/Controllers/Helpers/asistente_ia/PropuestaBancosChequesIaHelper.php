<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Cheque;
use App\Models\ChequeBanco;
use Illuminate\Support\Str;

/**
 * Unificar los bancos de los cheques desde el asistente (misión cheques-endoso-y-bancos,
 * 21/9/2026): el catálogo `cheque_bancos` arranca vacío y los cheques viejos tienen el banco como
 * texto libre ("Bco Nacion", "banco nación", "BNA"). `consultar()` le muestra a la IA los bancos
 * que ya hay y los textos distintos que siguen sin banco, con cuántos cheques tiene cada uno;
 * `proponer()` recibe los grupos que la IA armó (un nombre prolijo por banco y los textos que son
 * ese banco) y deja la tarjeta; `ejecutar()` crea los bancos que falten y asigna
 * `cheque_banco_id` a los cheques de cada texto.
 *
 * 🔴 SIEMPRE DEJA TARJETA, esté como esté la confianza del dueño. Es masiva (toca N cheques de un
 * saque) y decide a qué banco pertenece cada texto, que es exactamente lo que la persona tiene que
 * mirar antes. No está en HerramientasDeCarga::AUTO_CONFIRMABLES y su `case` no pasa por
 * quizas_auto_confirmar(), igual que la actualización masiva.
 *
 * 🔴 EL TEXTO `cheques.banco` NO SE TOCA NUNCA. Es el dato histórico del cheque y lo que sigue
 * leyendo la SPA anterior; lo único que se escribe es `cheque_banco_id`.
 *
 * Los textos se agrupan en PHP por su clave (minúsculas, sin espacios de más) y los cheques se
 * actualizan por id, no con un `WHERE LOWER(TRIM(banco)) IN (...)`: con la collation `_ci` de la
 * base, "nación" y "nacion" son iguales para MySQL y distintos para PHP, y un grupo se llevaría
 * los cheques de otro sin que nadie lo vea.
 */
class PropuestaBancosChequesIaHelper
{
    /**
     * Espejo del módulo de cheques: el empleado que puede verlo puede unificar sus bancos. El dueño
     * y el `admin_access` pasan siempre (PermisosIaHelper::puede).
     */
    const PERMISO = 'reportes.cheques';

    /** Hasta cuántos textos distintos devuelve consultar(). */
    const TOPE_TEXTOS = 200;

    const TITULO = 'Unificar bancos de cheques';

    const AVISO = 'No se escribe nada hasta que confirmes. Cada cheque queda con su banco del catálogo; el texto que tenía escrito no se borra.';

    /**
     * Herramienta consultar_bancos_de_cheques.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @return array  {ok, bancos: [{nombre, cheques}], textos_sin_banco: [{texto, cheques}],
     *                cheques_sin_texto, total_textos, nota?}
     */
    public static function consultar(ContextoDeCargaIa $contexto)
    {
        $bancos = [];

        $por_banco = Cheque::where('user_id', $contexto->owner_id)
                            ->whereNotNull('cheque_banco_id')
                            ->selectRaw('cheque_banco_id, COUNT(*) as cantidad')
                            ->groupBy('cheque_banco_id')
                            ->pluck('cantidad', 'cheque_banco_id');

        foreach (ChequeBanco::where('user_id', $contexto->owner_id)->orderBy('name')->get() as $banco) {

            $bancos[] = [
                'nombre'  => (string) $banco->name,
                'cheques' => isset($por_banco[$banco->id]) ? (int) $por_banco[$banco->id] : 0,
            ];
        }

        $grupos = self::textos_sin_banco($contexto->owner_id);

        $textos = [];

        foreach (array_slice($grupos, 0, self::TOPE_TEXTOS) as $grupo) {

            $textos[] = [
                'texto'   => $grupo['texto'],
                'cheques' => count($grupo['ids']),
            ];
        }

        $respuesta = [
            'ok'               => true,
            'bancos'           => $bancos,
            'textos_sin_banco' => $textos,
            'cheques_sin_texto' => self::cheques_sin_texto($contexto->owner_id),
            'total_textos'     => count($grupos),
        ];

        if (count($grupos) > self::TOPE_TEXTOS) {

            $respuesta['nota'] = 'Hay ' . count($grupos) . ' textos distintos y acá van los ' . self::TOPE_TEXTOS . ' con más cheques. Unificá estos primero y volvé a consultar para ver el resto.';
        }

        return $respuesta;
    }

    /**
     * Herramienta proponer_unificar_bancos_de_cheques.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input  grupos* ([{banco, textos: [...]}]), reemplaza_a
     * @return array
     */
    public static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input)
    {
        if (!PermisosIaHelper::puede($contexto->persona, self::PERMISO)) {

            return RespuestaDeCargaIa::error(self::mensaje_sin_permiso());
        }

        $grupos = EntradaDeCargaIa::valor($input, 'grupos');
        $grupos = is_array($grupos) ? array_values($grupos) : [];

        if (!count($grupos)) {

            return RespuestaDeCargaIa::faltan(['qué bancos hay que crear y qué textos de los cheques van en cada uno']);
        }

        $sin_banco = self::textos_sin_banco($contexto->owner_id);
        $por_clave = [];

        foreach ($sin_banco as $grupo) {

            $por_clave[$grupo['clave']] = $grupo;
        }

        $existentes = self::bancos_por_nombre_normalizado($contexto->owner_id);

        $traducidos = [];
        $faltan = [];
        $vistos = [];
        $total_cheques = 0;
        $bancos_nuevos = 0;

        foreach ($grupos as $indice => $grupo) {

            if (!is_array($grupo)) {

                return RespuestaDeCargaIa::error('Cada grupo tiene que venir como {banco, textos}.');
            }

            $banco = EntradaDeCargaIa::texto($grupo, 'banco');

            if ($banco === '') {

                return RespuestaDeCargaIa::error('El grupo ' . ($indice + 1) . ' no tiene el nombre del banco.');
            }

            $textos = EntradaDeCargaIa::valor($grupo, 'textos');
            $textos = is_array($textos) ? array_values($textos) : [];

            if (!count($textos)) {

                return RespuestaDeCargaIa::error('El banco "' . $banco . '" no tiene ningún texto de cheque asignado.');
            }

            $claves = [];
            $legibles = [];
            $cheques = 0;

            foreach ($textos as $texto) {

                if (!is_scalar($texto) || is_bool($texto)) {

                    return RespuestaDeCargaIa::error('Los textos del banco "' . $banco . '" tienen que ser strings.');
                }

                $clave = self::clave_de_texto((string) $texto);

                if ($clave === '') {

                    continue;
                }

                if (!isset($por_clave[$clave])) {

                    $faltan[] = trim((string) $texto);

                    continue;
                }

                if (isset($vistos[$clave])) {

                    return RespuestaDeCargaIa::error('El texto "' . $por_clave[$clave]['texto'] . '" está en dos grupos: un texto va a un solo banco.');
                }

                $vistos[$clave] = true;
                $claves[] = $clave;
                $legibles[] = $por_clave[$clave]['texto'];
                $cheques += count($por_clave[$clave]['ids']);
            }

            if (!count($claves) && !count($faltan)) {

                return RespuestaDeCargaIa::error('El banco "' . $banco . '" no tiene ningún texto de cheque asignado.');
            }

            $nombre_normalizado = self::nombre_normalizado($banco);
            $existente = isset($existentes[$nombre_normalizado]) ? $existentes[$nombre_normalizado] : null;

            if (is_null($existente)) {

                $bancos_nuevos++;
            }

            $total_cheques += $cheques;

            $traducidos[] = [
                'banco'        => is_null($existente) ? $banco : (string) $existente->name,
                'existente_id' => is_null($existente) ? null : (int) $existente->id,
                'claves'       => $claves,
                'textos'       => $legibles,
                'cheques'      => $cheques,
            ];
        }

        if (count($faltan)) {

            $opciones = [];

            foreach (array_slice($sin_banco, 0, self::TOPE_TEXTOS) as $grupo) {

                $opciones[] = ['texto' => $grupo['texto'], 'cheques' => count($grupo['ids'])];
            }

            $mensajes = [];

            foreach (array_unique($faltan) as $texto) {

                $mensajes[] = 'el texto "' . $texto . '" no está entre los textos de banco sin unificar: usá los de consultar_bancos_de_cheques tal cual';
            }

            return RespuestaDeCargaIa::faltan($mensajes, ['textos_sin_banco' => $opciones]);
        }

        $renglones = [
            ['etiqueta' => 'Qué va a pasar', 'valor' => self::resumen($bancos_nuevos, $total_cheques)],
        ];

        foreach ($traducidos as $traducido) {

            $renglones[] = [
                'etiqueta' => $traducido['banco'] . (is_null($traducido['existente_id']) ? ' (nuevo)' : ' (existente)'),
                'valor'    => self::cheques_legibles($traducido['cheques']) . ': ' . implode(', ', $traducido['textos']),
            ];
        }

        $datos = [
            'grupos'         => $traducidos,
            'total_estimado' => $total_cheques,
            'bancos_nuevos'  => $bancos_nuevos,
        ];

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_UNIFICAR_BANCOS,
            self::clave($traducidos),
            $datos,
            ['titulo' => self::TITULO, 'renglones' => $renglones, 'aviso' => self::AVISO],
            EntradaDeCargaIa::valor($input, 'reemplaza_a')
        );

        $bancos_legibles = [];

        foreach ($renglones as $indice => $renglon) {

            if ($indice === 0) {

                continue;
            }

            $bancos_legibles[] = $renglon['etiqueta'] . ' → ' . $renglon['valor'];
        }

        return AccionesIaHelper::respuesta_de_propuesta(
            $creada,
            self::TITULO . ': ' . self::resumen($bancos_nuevos, $total_cheques),
            [
                'bancos'             => $bancos_legibles,
                'cheques_alcanzados' => $total_cheques,
                'bancos_nuevos'      => $bancos_nuevos,
            ]
        );
    }

    /**
     * Identidad de la carga para el reemplazo: los mismos grupos (bancos y textos) son la misma
     * unificación; otro reparto, otra tarjeta.
     *
     * @param  array  $grupos  Los traducidos (banco + claves).
     * @return string
     */
    public static function clave(array $grupos)
    {
        $identidad = [];

        foreach ($grupos as $grupo) {

            $claves = $grupo['claves'];
            sort($claves);
            $identidad[] = [self::nombre_normalizado($grupo['banco']), $claves];
        }

        return 'unificar_bancos_cheques:' . sha1((string) json_encode($identidad, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Crea los bancos que falten y asigna `cheque_banco_id` a los cheques de cada texto. Corre
     * adentro de la transacción de EjecutorAccionesIaHelper.
     *
     * 🔴 TODO SE RE-VERIFICA: el permiso sobre quien confirma, los bancos existentes (uno con ese
     * nombre pudo aparecer entre la tarjeta y el clic: se reusa, no se duplica) y la cantidad de
     * cheques (mismo criterio que la actualización masiva: más de un 10 % y más de 3 de diferencia
     * con lo que la persona vio, se corta y se pide de nuevo).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array  resultado {texto, ruta}
     *
     * @throws AccionIaException  422 si la carga ya no se puede hacer.
     */
    public static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion)
    {
        if (!PermisosIaHelper::puede($contexto->persona, self::PERMISO)) {

            throw new AccionIaException(422, self::mensaje_sin_permiso());
        }

        $datos = is_array($accion->datos) ? $accion->datos : [];
        $grupos = isset($datos['grupos']) && is_array($datos['grupos']) ? $datos['grupos'] : [];

        if (!count($grupos)) {

            throw new AccionIaException(422, 'Esta tarjeta no tiene ningún banco para unificar. Pedímelo de nuevo.');
        }

        $sin_banco = self::textos_sin_banco($contexto->owner_id);
        $ids_por_clave = [];

        foreach ($sin_banco as $grupo) {

            $ids_por_clave[$grupo['clave']] = $grupo['ids'];
        }

        $fresco = 0;

        foreach ($grupos as $grupo) {

            foreach ($grupo['claves'] as $clave) {

                $fresco += isset($ids_por_clave[$clave]) ? count($ids_por_clave[$clave]) : 0;
            }
        }

        $estimado = isset($datos['total_estimado']) ? (int) $datos['total_estimado'] : 0;

        if ($estimado > 0 && abs($fresco - $estimado) > max(3, (int) ceil($estimado * 0.10))) {

            throw new AccionIaException(422, 'Cambió la cantidad de cheques sin banco (eran ' . $estimado . ', ahora son ' . $fresco . '): pedímelo de nuevo para ver la cantidad actual.');
        }

        $existentes = self::bancos_por_nombre_normalizado($contexto->owner_id);

        $creados = 0;
        $asignados = 0;

        foreach ($grupos as $grupo) {

            $nombre = (string) $grupo['banco'];
            $normalizado = self::nombre_normalizado($nombre);

            if (isset($existentes[$normalizado])) {

                $banco = $existentes[$normalizado];

            } else {

                $banco = ChequeBanco::create([
                    'name'    => $nombre,
                    'user_id' => $contexto->owner_id,
                ]);

                $existentes[$normalizado] = $banco;
                $creados++;
            }

            $ids = [];

            foreach ($grupo['claves'] as $clave) {

                if (isset($ids_por_clave[$clave])) {

                    foreach ($ids_por_clave[$clave] as $id) {

                        $ids[] = $id;
                    }
                }
            }

            foreach (array_chunk($ids, 500) as $tanda) {

                $asignados += Cheque::where('user_id', $contexto->owner_id)
                                    ->whereNull('cheque_banco_id')
                                    ->whereIn('id', $tanda)
                                    ->update(['cheque_banco_id' => $banco->id]);
            }
        }

        return [
            'texto' => 'Se ' . ($creados === 1 ? 'creó 1 banco' : 'crearon ' . $creados . ' bancos') . ' y se ' . ($asignados === 1 ? 'asignó 1 cheque' : 'asignaron ' . $asignados . ' cheques') . '.',
            'ruta'  => [
                'name'   => 'cheque',
                'params' => ['sub_view' => 'recibido', 'sub_sub_view' => 'pendientes'],
                'texto'  => 'Ver los cheques',
            ],
        ];
    }

    /**
     * Los textos distintos de `cheques.banco` de los cheques del dueño que siguen SIN banco del
     * catálogo, agrupados por clave (minúsculas, sin espacios de más), con la variante más
     * frecuente como texto visible y los ids de los cheques de cada grupo. Ordenados por cantidad
     * de cheques, de mayor a menor, y por texto.
     *
     * @param  int  $owner_id
     * @return array<int, array{clave: string, texto: string, ids: array<int, int>}>
     */
    public static function textos_sin_banco($owner_id)
    {
        $filas = Cheque::where('user_id', $owner_id)
                        ->whereNull('cheque_banco_id')
                        ->whereNotNull('banco')
                        ->orderBy('id')
                        ->get(['id', 'banco']);

        $grupos = [];

        foreach ($filas as $fila) {

            $texto = trim(preg_replace('/\s+/u', ' ', (string) $fila->banco));

            if ($texto === '') {

                continue;
            }

            $clave = self::clave_de_texto($texto);

            if (!isset($grupos[$clave])) {

                $grupos[$clave] = ['clave' => $clave, 'variantes' => [], 'ids' => []];
            }

            if (!isset($grupos[$clave]['variantes'][$texto])) {

                $grupos[$clave]['variantes'][$texto] = 0;
            }

            $grupos[$clave]['variantes'][$texto]++;
            $grupos[$clave]['ids'][] = (int) $fila->id;
        }

        $resultado = [];

        foreach ($grupos as $grupo) {

            arsort($grupo['variantes']);

            $resultado[] = [
                'clave' => $grupo['clave'],
                'texto' => (string) array_key_first($grupo['variantes']),
                'ids'   => $grupo['ids'],
            ];
        }

        usort($resultado, function ($a, $b) {

            $diferencia = count($b['ids']) - count($a['ids']);

            return $diferencia !== 0 ? $diferencia : strcmp($a['clave'], $b['clave']);
        });

        return $resultado;
    }

    /**
     * Cuántos cheques del dueño no tienen ni banco del catálogo ni texto: esos no se pueden
     * unificar desde acá (se les pone el banco desde la ficha).
     *
     * @param  int  $owner_id
     * @return int
     */
    protected static function cheques_sin_texto($owner_id)
    {
        return Cheque::where('user_id', $owner_id)
                        ->whereNull('cheque_banco_id')
                        ->where(function ($q) {
                            $q->whereNull('banco')->orWhereRaw("TRIM(banco) = ''");
                        })
                        ->count();
    }

    /**
     * La clave de un texto de banco: minúsculas y espacios normalizados. Con tildes, a propósito:
     * "nación" y "nacion" son dos textos distintos que la IA agrupa en el mismo banco.
     *
     * @param  string  $texto
     * @return string
     */
    public static function clave_de_texto($texto)
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $texto)));
    }

    /**
     * El nombre de un banco para compararlo con los del catálogo: minúsculas, sin tildes y sin
     * espacios de más ("Banco Nación" = "banco nacion").
     *
     * @param  string  $nombre
     * @return string
     */
    public static function nombre_normalizado($nombre)
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', Str::ascii((string) $nombre))));
    }

    /**
     * Los bancos del catálogo del dueño, indexados por su nombre normalizado.
     *
     * @param  int  $owner_id
     * @return array<string, \App\Models\ChequeBanco>
     */
    protected static function bancos_por_nombre_normalizado($owner_id)
    {
        $bancos = [];

        foreach (ChequeBanco::where('user_id', $owner_id)->orderBy('id')->get() as $banco) {

            $normalizado = self::nombre_normalizado($banco->name);

            if (!isset($bancos[$normalizado])) {

                $bancos[$normalizado] = $banco;
            }
        }

        return $bancos;
    }

    /**
     * "Crear 2 bancos, asignar 12 cheques".
     *
     * @param  int  $bancos_nuevos
     * @param  int  $cheques
     * @return string
     */
    protected static function resumen($bancos_nuevos, $cheques)
    {
        $bancos = $bancos_nuevos === 1 ? 'Crear 1 banco' : 'Crear ' . $bancos_nuevos . ' bancos';

        return $bancos . ', asignar ' . self::cheques_legibles($cheques);
    }

    /**
     * @param  int  $cheques
     * @return string
     */
    protected static function cheques_legibles($cheques)
    {
        return $cheques === 1 ? '1 cheque' : $cheques . ' cheques';
    }

    /**
     * @return string
     */
    protected static function mensaje_sin_permiso()
    {
        return PermisosIaHelper::mensaje_sin_permiso('bancos de cheques');
    }
}
