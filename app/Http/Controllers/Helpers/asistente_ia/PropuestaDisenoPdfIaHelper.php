<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\PdfColumnProfileHelper;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\PdfColumnOption;
use App\Models\PdfColumnProfile;
use Carbon\Carbon;

/**
 * Cambiar las columnas de un diseño de PDF desde el asistente (misión
 * asistente-masivas-imagenes-y-remito, 19/9/2026, contrato §1.6 y §1.7).
 *
 * "Quiero que en los remitos sin precios aparezca la columna categoría" → `consultar()` le muestra
 * a la IA los diseños con sus columnas y anchos; `proponer()` resuelve las columnas por nombre,
 * corre PdfColumnProfileHelper::aplicar_cambios() SIN persistir y arma la tarjeta contando qué
 * se agrega, qué se saca y qué se achica; `ejecutar()` relee el perfil, corta con 422 si alguien
 * lo editó en el medio, recalcula y persiste por el mismo pivot que el ABM.
 *
 * Es una carga inocua y reversible (se vuelve a cambiar desde ABM > Impresión), así que en modo
 * "resuelto" se auto-confirma. El tipo es el literal 'diseno_pdf': la constante
 * AiMessageAction::TIPO_DISENO_PDF con ese valor la declara el constructor A de esta misión.
 */
class PropuestaDisenoPdfIaHelper
{
    /** Tipo de la tarjeta (= AiMessageAction::TIPO_DISENO_PDF, que escribe A). */
    const TIPO = 'diseno_pdf';

    const MENSAJE_SIN_PERMISO = 'Solo el dueño puede cambiar los diseños de PDF.';

    const MENSAJE_DISENO_CAMBIADO = 'El diseño se modificó desde que te lo propuse. Pedímelo de nuevo.';

    const AVISO = 'Se puede volver a cambiar desde ABM > Impresión.';

    /** tipo de la IA => model_name del perfil. */
    const TIPOS = ['venta' => 'sale', 'articulos' => 'article'];

    /**
     * Herramienta consultar_disenos_de_pdf (contrato §1.6).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed              $tipo  'venta' | 'articulos' | null (los dos).
     * @return array
     */
    public static function consultar(ContextoDeCargaIa $contexto, $tipo = null)
    {
        /*
         * Sin gate de dueño a propósito: leer qué columnas tiene un remito no cambia nada (el
         * ABM lo muestra a cualquiera que entre), y la persona puede estar preguntando "¿qué
         * columnas tiene el remito?" sin querer tocarlo. Lo que sí exige dueño es proponer() y
         * ejecutar(), que escriben.
         */
        $tipo = is_null($tipo) ? '' : trim((string) $tipo);

        if ($tipo !== '' && !isset(self::TIPOS[$tipo])) {
            return RespuestaDeCargaIa::error('El tipo tiene que ser "venta" (remitos, facturas) o "articulos" (catálogo).');
        }

        $query = PdfColumnProfile::where('user_id', $contexto->owner_id)
            ->with(['sheet_type', 'pdf_column_options'])
            ->orderBy('model_name')
            ->orderBy('name');

        if ($tipo !== '') {
            $query->where('model_name', self::TIPOS[$tipo]);
        }

        $disenos = [];

        foreach ($query->get() as $profile) {
            $columnas = [];

            foreach (PdfColumnProfileHelper::columnas_visibles($profile) as $fila) {
                $columnas[] = [
                    'nombre'       => $fila['nombre'],
                    'ancho_mm'     => (int) $fila['ancho_mm'],
                    'ajusta_texto' => (bool) $fila['ajusta_texto'],
                ];
            }

            $disenos[] = [
                'id'                  => (int) $profile->id,
                'nombre'              => (string) $profile->name,
                'tipo'                => self::tipo_de($profile),
                'predeterminado'      => (bool) $profile->is_default,
                'hoja'                => !is_null($profile->sheet_type) ? (string) $profile->sheet_type->name : (int) $profile->paper_width_mm.' mm',
                'ancho_disponible_mm' => PdfColumnProfileHelper::ancho_disponible_mm($profile->printable_width_mm, $profile->margin_mm),
                'suma_de_anchos_mm'   => PdfColumnProfileHelper::suma_de_anchos_mm($profile),
                'columnas'            => $columnas,
            ];
        }

        $disponibles = [];

        foreach (self::TIPOS as $nombre_tipo => $model_name) {
            if ($tipo !== '' && $tipo !== $nombre_tipo) {
                continue;
            }

            $disponibles[$nombre_tipo] = [];

            foreach (PdfColumnProfileHelper::catalogo($model_name) as $option) {
                $disponibles[$nombre_tipo][] = [
                    'nombre'                => (string) $option->name,
                    'ancho_por_defecto_mm'  => (int) $option->default_width,
                ];
            }
        }

        return [
            'ok'                   => true,
            'disenos'              => $disenos,
            'columnas_disponibles' => $disponibles,
        ];
    }

    /**
     * Herramienta proponer_cambio_en_diseno_pdf (contrato §1.7).
     *
     * @param  ContextoDeCargaIa      $contexto
     * @param  \App\Models\AiMessage  $mensaje
     * @param  array                  $input  diseno_id, agregar, quitar, anchos, reemplaza_a.
     * @return array
     */
    public static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input)
    {
        if (!PermisosIaHelper::es_admin($contexto->persona)) {
            return RespuestaDeCargaIa::error(self::MENSAJE_SIN_PERMISO);
        }

        $profile = self::perfil_del_dueno($contexto, EntradaDeCargaIa::valor($input, 'diseno_id'));

        if (is_null($profile)) {
            return RespuestaDeCargaIa::error('No encontré ese diseño de PDF. Mirá consultar_disenos_de_pdf y pasame el id.');
        }

        $cambios = self::resolver_cambios($profile, $input);

        if (RespuestaDeCargaIa::es_negativa($cambios)) {
            return $cambios;
        }

        $aplicado = PdfColumnProfileHelper::aplicar_cambios($profile, $cambios['agregar'], $cambios['quitar'], $cambios['anchos']);

        if (!is_null($aplicado['error'])) {
            return RespuestaDeCargaIa::error($aplicado['error']);
        }

        $renglones = self::renglones($cambios, $aplicado);

        $referencia = is_null($profile->updated_at) ? null : $profile->updated_at->format('Y-m-d H:i:s');

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            self::TIPO,
            self::clave($profile->id),
            [
                'profile_id' => (int) $profile->id,
                'agregar'    => $cambios['agregar'],
                'quitar'     => $cambios['quitar'],
                'anchos'     => $cambios['anchos'],
            ],
            ['titulo' => 'Diseño de PDF: '.$profile->name, 'renglones' => $renglones, 'aviso' => self::AVISO],
            EntradaDeCargaIa::valor($input, 'reemplaza_a'),
            $referencia
        );

        return AccionesIaHelper::respuesta_de_propuesta(
            $creada,
            'Diseño '.$profile->name.': '.self::resumen_corto($cambios, $aplicado),
            ['ajustes' => $aplicado['ajustes'], 'columnas' => PdfColumnProfileHelper::resumen_de_columnas($aplicado['visibles'], $aplicado['suma'], $aplicado['disponible'])]
        );
    }

    /**
     * @param  int  $profile_id
     * @return string
     */
    public static function clave($profile_id)
    {
        return 'diseno_pdf:'.(int) $profile_id;
    }

    /**
     * Aplica el cambio sobre el pivot. Corre adentro de la transacción de EjecutorAccionesIaHelper.
     *
     * 🔴 Si el perfil cambió desde la propuesta (`updated_at` ≠ `referencia_updated_at`), 422: la
     * tarjeta se calculó sobre columnas que ya no son las que hay, y aplicarla igual pisaría lo
     * que alguien editó en el ABM. Se recalcula con el helper (el catálogo pudo cambiar) y se
     * persiste por el mismo pivot que el ABM.
     *
     * @param  ContextoDeCargaIa            $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array  resultado {texto, ruta}
     *
     * @throws AccionIaException  422 si la carga ya no se puede hacer.
     */
    public static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion)
    {
        if (!PermisosIaHelper::es_admin($contexto->persona)) {
            throw new AccionIaException(422, self::MENSAJE_SIN_PERMISO);
        }

        $datos = is_array($accion->datos) ? $accion->datos : [];

        $profile = self::perfil_del_dueno($contexto, isset($datos['profile_id']) ? $datos['profile_id'] : null);

        if (is_null($profile)) {
            throw new AccionIaException(422, 'Ese diseño de PDF ya no existe. Pedímelo de nuevo.');
        }

        $actual     = is_null($profile->updated_at) ? null : $profile->updated_at->format('Y-m-d H:i:s');
        $referencia = $accion->referencia_updated_at;

        if (!is_null($referencia)) {
            $referencia = Carbon::parse($referencia)->format('Y-m-d H:i:s');
        }

        if ($actual !== $referencia) {
            throw new AccionIaException(422, self::MENSAJE_DISENO_CAMBIADO);
        }

        $agregar = isset($datos['agregar']) && is_array($datos['agregar']) ? $datos['agregar'] : [];
        $quitar  = isset($datos['quitar']) && is_array($datos['quitar']) ? $datos['quitar'] : [];
        $anchos  = isset($datos['anchos']) && is_array($datos['anchos']) ? $datos['anchos'] : [];

        $aplicado = PdfColumnProfileHelper::aplicar_cambios($profile, $agregar, $quitar, $anchos);

        if (!is_null($aplicado['error'])) {
            throw new AccionIaException(422, $aplicado['error']);
        }

        PdfColumnProfileHelper::persistir($profile, $aplicado['pivots']);

        return [
            'texto' => 'Listo: '.self::resumen_corto(['agregar' => $agregar, 'quitar' => $quitar, 'anchos' => $anchos], $aplicado).' en el diseño '.$profile->name.'.',
            'ruta'  => self::ruta_a_disenos(),
        ];
    }

    /**
     * ABM > Impresión > Diseños de PDF. `sub_view` es routeString(plural('pdf_column_profile')) del SPA
     * (`Diseño de PDF` → `diseño-de-pdf`), que es lo que Abm.vue resuelve desde /abm/:view/:sub_view.
     *
     * @return array
     */
    public static function ruta_a_disenos()
    {
        return [
            'name'   => 'abm',
            'params' => ['view' => 'impresion', 'sub_view' => 'diseño-de-pdf'],
            'texto'  => 'Ver diseños de PDF',
        ];
    }

    /**
     * @param  ContextoDeCargaIa $contexto
     * @param  mixed             $profile_id
     * @return \App\Models\PdfColumnProfile|null
     */
    protected static function perfil_del_dueno(ContextoDeCargaIa $contexto, $profile_id)
    {
        $profile_id = (int) $profile_id;

        if ($profile_id <= 0) {
            return null;
        }

        return PdfColumnProfile::where('user_id', $contexto->owner_id)
            ->where('id', $profile_id)
            ->with('pdf_column_options')
            ->first();
    }

    /**
     * 'venta' | 'articulos' | el model_name crudo si no es ninguno de los dos.
     *
     * @param  \App\Models\PdfColumnProfile $profile
     * @return string
     */
    protected static function tipo_de(PdfColumnProfile $profile)
    {
        foreach (self::TIPOS as $tipo => $model_name) {
            if ($profile->model_name === $model_name) {
                return $tipo;
            }
        }

        return (string) $profile->model_name;
    }

    /**
     * Lee `agregar`, `quitar` y `anchos` del input y resuelve cada columna por nombre contra el
     * catálogo del tipo del perfil. Deja todo en términos de `option_id`, con el nombre al lado
     * para los textos.
     *
     * @param  \App\Models\PdfColumnProfile $profile
     * @param  array                        $input
     * @return array  ['agregar' => [...], 'quitar' => [...], 'anchos' => [...]] o la respuesta negativa.
     */
    protected static function resolver_cambios(PdfColumnProfile $profile, array $input)
    {
        $agregar = [];
        $quitar  = [];
        $anchos  = [];

        foreach (self::lista($input, 'agregar') as $pedido) {
            if (!is_array($pedido)) {
                continue;
            }

            $columna = PdfColumnProfileHelper::resolver_columna($profile->model_name, EntradaDeCargaIa::texto($pedido, 'columna'));

            if (RespuestaDeCargaIa::es_negativa($columna)) {
                return $columna;
            }

            $posicion = EntradaDeCargaIa::texto($pedido, 'posicion');

            /*
             * 🔴 Sin posición NO se asume "al final": se pregunta. Lucas lo pidió así ("el agente
             * le preguntaría ¿en qué lugar querés que te aparezca?"), y el prompt lo dice, pero
             * si la IA se lo saltea el sistema tiene que frenarla: acá es donde se frena.
             */
            if ($posicion === '') {
                return RespuestaDeCargaIa::faltan(['dónde va '.$columna->name.': al final, al principio, antes o después de cuál columna']);
            }

            if (!in_array($posicion, PdfColumnProfileHelper::POSICIONES, true)) {
                return RespuestaDeCargaIa::error('La posición tiene que ser al_final, al_principio, despues_de o antes_de.');
            }

            $referencia = null;

            if ($posicion === 'despues_de' || $posicion === 'antes_de') {
                $nombre_referencia = EntradaDeCargaIa::texto($pedido, 'columna_de_referencia');

                if ($nombre_referencia === '') {
                    return RespuestaDeCargaIa::faltan(['después o antes de qué columna va '.$columna->name]);
                }

                $referencia = PdfColumnProfileHelper::resolver_columna($profile->model_name, $nombre_referencia);

                if (RespuestaDeCargaIa::es_negativa($referencia)) {
                    return $referencia;
                }
            }

            $ancho = EntradaDeCargaIa::valor($pedido, 'ancho_mm');

            if (!EntradaDeCargaIa::vacio($ancho) && (!is_numeric($ancho) || (int) $ancho <= 0)) {
                return RespuestaDeCargaIa::error('El ancho de '.$columna->name.' tiene que ser un número de milímetros mayor a cero.');
            }

            $agregar[] = [
                'option_id'            => (int) $columna->id,
                'nombre'               => (string) $columna->name,
                'posicion'             => $posicion,
                'referencia_option_id' => is_null($referencia) ? null : (int) $referencia->id,
                'referencia_nombre'    => is_null($referencia) ? null : (string) $referencia->name,
                'ancho_mm'             => EntradaDeCargaIa::vacio($ancho) ? null : (int) $ancho,
            ];
        }

        foreach (self::lista($input, 'quitar') as $nombre) {
            $nombre = is_array($nombre) ? EntradaDeCargaIa::texto($nombre, 'columna') : trim((string) $nombre);

            if ($nombre === '') {
                continue;
            }

            $columna = PdfColumnProfileHelper::resolver_columna($profile->model_name, $nombre);

            if (RespuestaDeCargaIa::es_negativa($columna)) {
                return $columna;
            }

            $quitar[] = (int) $columna->id;
        }

        foreach (self::lista($input, 'anchos') as $pedido) {
            if (!is_array($pedido)) {
                continue;
            }

            $columna = PdfColumnProfileHelper::resolver_columna($profile->model_name, EntradaDeCargaIa::texto($pedido, 'columna'));

            if (RespuestaDeCargaIa::es_negativa($columna)) {
                return $columna;
            }

            $ancho = EntradaDeCargaIa::valor($pedido, 'ancho_mm');

            if (!is_numeric($ancho) || (int) $ancho <= 0) {
                return RespuestaDeCargaIa::error('El ancho de '.$columna->name.' tiene que ser un número de milímetros mayor a cero.');
            }

            $anchos[] = ['option_id' => (int) $columna->id, 'nombre' => (string) $columna->name, 'ancho_mm' => (int) $ancho];
        }

        if (!count($agregar) && !count($quitar) && !count($anchos)) {
            return RespuestaDeCargaIa::faltan(['qué columna agregar, sacar o achicar en el diseño '.$profile->name]);
        }

        return ['agregar' => $agregar, 'quitar' => $quitar, 'anchos' => $anchos];
    }

    /**
     * @param  array  $input
     * @param  string $clave
     * @return array
     */
    protected static function lista(array $input, $clave)
    {
        $valor = EntradaDeCargaIa::valor($input, $clave);

        return is_array($valor) ? $valor : [];
    }

    /**
     * Los renglones de la tarjeta: "Se agrega", "Se saca", "Se achica" (uno por ajuste) y "Columnas".
     *
     * @param  array $cambios
     * @param  array $aplicado
     * @return array
     */
    protected static function renglones(array $cambios, array $aplicado)
    {
        $renglones = [];

        foreach ($cambios['agregar'] as $pedido) {
            $renglones[] = ['etiqueta' => 'Se agrega', 'valor' => self::texto_de_agregado($pedido, $aplicado)];
        }

        foreach ($cambios['quitar'] as $option_id) {
            $renglones[] = ['etiqueta' => 'Se saca', 'valor' => self::nombre_de_opcion($option_id, $cambios)];
        }

        foreach ($cambios['anchos'] as $pedido) {
            $renglones[] = ['etiqueta' => 'Ancho', 'valor' => $pedido['nombre'].': '.(int) $pedido['ancho_mm'].' mm'];
        }

        foreach ($aplicado['ajustes'] as $ajuste) {
            $renglones[] = ['etiqueta' => 'Se achica', 'valor' => $ajuste['columna'].' de '.(int) $ajuste['de'].' a '.(int) $ajuste['a'].' mm'];
        }

        $renglones[] = ['etiqueta' => 'Columnas', 'valor' => PdfColumnProfileHelper::resumen_de_columnas($aplicado['visibles'], $aplicado['suma'], $aplicado['disponible'])];

        return $renglones;
    }

    /**
     * "Categoria del articulo (35 mm) después de Cantidad".
     *
     * @param  array $pedido
     * @param  array $aplicado
     * @return string
     */
    protected static function texto_de_agregado(array $pedido, array $aplicado)
    {
        $ancho = null;

        foreach ($aplicado['visibles'] as $fila) {
            if ((int) $fila['option_id'] === (int) $pedido['option_id']) {
                $ancho = (int) $fila['ancho_mm'];
            }
        }

        $texto = $pedido['nombre'].(is_null($ancho) ? '' : ' ('.$ancho.' mm)');

        $posicion = isset($pedido['posicion']) ? $pedido['posicion'] : 'al_final';

        if ($posicion === 'al_principio') {
            return $texto.' al principio';
        }

        if (($posicion === 'despues_de' || $posicion === 'antes_de') && !empty($pedido['referencia_nombre'])) {
            return $texto.' '.($posicion === 'despues_de' ? 'después' : 'antes').' de '.$pedido['referencia_nombre'];
        }

        return $texto.' al final';
    }

    /**
     * "agregué Categoria del articulo después de Cantidad y achiqué Nombre del artículo a 97 mm".
     *
     * @param  array $cambios
     * @param  array $aplicado
     * @return string
     */
    protected static function resumen_corto(array $cambios, array $aplicado)
    {
        $partes = [];

        foreach ($cambios['agregar'] as $pedido) {
            $posicion = isset($pedido['posicion']) ? $pedido['posicion'] : 'al_final';

            $donde = ' al final';

            if ($posicion === 'al_principio') {
                $donde = ' al principio';
            } elseif (($posicion === 'despues_de' || $posicion === 'antes_de') && !empty($pedido['referencia_nombre'])) {
                $donde = ' '.($posicion === 'despues_de' ? 'después' : 'antes').' de '.$pedido['referencia_nombre'];
            }

            $partes[] = 'agregué '.$pedido['nombre'].$donde;
        }

        foreach ($cambios['quitar'] as $option_id) {
            $partes[] = 'saqué '.self::nombre_de_opcion($option_id, $cambios);
        }

        foreach ($cambios['anchos'] as $pedido) {
            $partes[] = 'dejé '.$pedido['nombre'].' en '.(int) $pedido['ancho_mm'].' mm';
        }

        foreach ($aplicado['ajustes'] as $ajuste) {
            $partes[] = 'achiqué '.$ajuste['columna'].' a '.(int) $ajuste['a'].' mm';
        }

        if (count($partes) <= 1) {
            return implode('', $partes);
        }

        $ultima = array_pop($partes);

        return implode(', ', $partes).' y '.$ultima;
    }

    /**
     * El nombre de una columna quitada (en `quitar` viajan solo ids).
     *
     * @param  int   $option_id
     * @param  array $cambios
     * @return string
     */
    protected static function nombre_de_opcion($option_id, array $cambios)
    {
        $option = PdfColumnOption::find((int) $option_id);

        return is_null($option) ? 'columna #'.(int) $option_id : (string) $option->name;
    }
}
