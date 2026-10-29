<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Los archivos (PDF, Excel, CSV) que el dueño le manda al asistente desde la app (misión
 * app-ia-y-memoria, 29/10/2026, §4 E1.3 del plan): validarlos, guardarlos, sacarles el texto y
 * pasárselo al modelo.
 *
 * El modelo no lee PDF ni planillas: lee TEXTO. El texto se saca una sola vez, en el job de respuesta
 * antes de llamar al modelo (extraer_pendientes()), y queda guardado con el archivo: el turno siguiente
 * no vuelve a abrir el PDF. Entra al turno del modelo como «Archivo adjunto: nombre — contenido: …»
 * (texto_para_el_modelo()).
 *
 * 🔴 POR QUÉ EN EL JOB Y NO EN EL POST. Un PDF de 10 MB puede tardar varios segundos y comerse memoria;
 * en el POST eso le pega al `max_execution_time` del hosting compartido (30 s) y un corte ahí pierde el
 * mensaje. En el job, una falla deja el archivo como "no lo pude leer" y el asistente contesta igual.
 *
 * Topes (§4 E1.3): planillas hasta 3 hojas × 200 filas × 30 columnas, y en total ≤ 30.000 caracteres por
 * mensaje entre todos sus archivos. Lo que no entra se corta y se avisa ("[recortado]").
 *
 * Los datos van en la columna `ai_messages.archivos` (json), una entrada por archivo: `{orden, nombre,
 * tipo, mime, tamano, path, estado, texto, recortado, error}`. Hacia afuera sale SOLO `{orden, nombre,
 * tipo, tamano, url}` (para_la_respuesta()). El binario va al disco `local` (privado): un PDF de un
 * proveedor es información del negocio. Ruta: `asistente_archivos/{owner_id}/{ai_message_id}/{orden}.{ext}`.
 */
class ArchivosAdjuntosIaHelper
{
    /** Archivos por mensaje (contrato C5). */
    const MAX_ARCHIVOS = 3;

    /** Peso máximo de cada uno, en MB (contrato C5). */
    const MAX_MB = 10;

    /** Formatos aceptados (contrato C5). */
    const FORMATOS = ['pdf', 'xlsx', 'xls', 'csv'];

    /** Topes de lectura de una planilla (§4 E1.3). */
    const MAX_HOJAS = 3;

    const MAX_FILAS = 200;

    const MAX_COLUMNAS = 30;

    /** Techo del texto de TODOS los archivos de un mensaje juntos (§4 E1.3). */
    const MAX_CARACTERES_POR_MENSAJE = 30000;

    /**
     * Cuánto del texto viaja cuando el archivo NO es del último mensaje del dueño. El completo viaja en
     * el turno en que se mandó; después, un extracto: si no, cada pregunta siguiente pagaría de nuevo los
     * 30.000 caracteres y el recorte por caracteres del historial (AsistenteIaService::
     * MAX_CARACTERES_HISTORIAL = 24.000) dejaría afuera todo lo anterior al archivo.
     */
    const MAX_CARACTERES_EN_EL_HISTORIAL = 3000;

    /** Clase del lector de PDF (smalot/pdfparser). Por nombre: sin el paquete, el PDF se informa como no legible. */
    const CLASE_DEL_LECTOR_DE_PDF = 'Smalot\\PdfParser\\Parser';

    const ESTADO_PENDIENTE = 'pendiente';

    const ESTADO_LISTO = 'listo';

    const ESTADO_FALLIDO = 'fallido';

    /** Ruta del binario, relativa a la base del API (como FotosDelMensajeIaHelper::RUTA). */
    const RUTA = 'api/ai-mensajes';

    /**
     * Las subidas del request como lista (la app puede mandar uno solo sin corchetes).
     *
     * @param  mixed  $archivos
     * @return array
     */
    public static function como_lista($archivos): array
    {
        if (is_null($archivos)) {

            return [];
        }

        return is_array($archivos) ? array_values($archivos) : [$archivos];
    }

    /**
     * El motivo del rechazo del lote (texto para el 422), o null si sirve. Se valida ANTES de crear el
     * mensaje, igual que las fotos.
     *
     * @param  array  $archivos  UploadedFile[]
     * @return string|null
     */
    public static function motivo_de_rechazo(array $archivos)
    {
        if (count($archivos) > self::MAX_ARCHIVOS) {

            return 'Se pueden mandar hasta ' . self::MAX_ARCHIVOS . ' archivos por mensaje.';
        }

        foreach ($archivos as $archivo) {

            if (is_null($archivo) || !is_object($archivo) || !method_exists($archivo, 'isValid') || !$archivo->isValid()) {

                return 'Uno de los archivos no llegó completo.';
            }

            $nombre = self::nombre_de($archivo);

            if ($archivo->getSize() > self::MAX_MB * 1024 * 1024) {

                return 'El archivo "' . $nombre . '" pesa más de ' . self::MAX_MB . ' MB.';
            }

            if (is_null(self::tipo_de($archivo))) {

                return 'El archivo "' . $nombre . '" no es de un tipo que pueda leer. Se aceptan: ' . implode(', ', self::FORMATOS) . '.';
            }
        }

        return null;
    }

    /**
     * El tipo del archivo entre los aceptados (por la extensión del nombre y, si no, la que deduce
     * Symfony de los bytes), o null.
     *
     * @param  mixed  $archivo
     * @return string|null
     */
    public static function tipo_de($archivo)
    {
        $candidatas = [
            strtolower((string) $archivo->getClientOriginalExtension()),
            strtolower((string) $archivo->guessExtension()),
        ];

        foreach ($candidatas as $extension) {

            if (in_array($extension, self::FORMATOS, true)) {

                return $extension;
            }

            /* Un CSV suele detectarse como texto plano. */
            if ($extension === 'txt' && strtolower((string) $archivo->getClientOriginalExtension()) === 'csv') {

                return 'csv';
            }
        }

        return null;
    }

    /**
     * El nombre con que se muestra un archivo.
     *
     * @param  mixed  $archivo
     * @return string
     */
    protected static function nombre_de($archivo)
    {
        $nombre = trim((string) $archivo->getClientOriginalName());

        return $nombre === '' ? 'archivo' : mb_substr($nombre, 0, 150);
    }

    /**
     * Guarda los archivos de un mensaje ya creado y devuelve las entradas de la columna `archivos`. Un
     * archivo que no se puede escribir se saltea (el resto del mensaje vale igual).
     *
     * @param  \App\Models\AiMessage  $mensaje
     * @param  array  $archivos  UploadedFile[], ya validados por motivo_de_rechazo().
     * @param  int  $owner_id
     * @return array<int, array<string, mixed>>
     */
    public static function guardar(AiMessage $mensaje, array $archivos, $owner_id): array
    {
        $carpeta  = 'asistente_archivos/' . (int) $owner_id . '/' . (int) $mensaje->id;
        $entradas = [];
        $orden    = 1;

        foreach ($archivos as $archivo) {

            $tipo = self::tipo_de($archivo);
            $path = $carpeta . '/' . $orden . '.' . $tipo;

            try {

                Storage::disk('local')->put($path, (string) file_get_contents($archivo->getRealPath()));

            } catch (\Throwable $e) {

                Log::warning('ArchivosAdjuntosIaHelper: no se pudo guardar un archivo del mensaje', [
                    'ai_message_id' => $mensaje->id,
                    'error'         => $e->getMessage(),
                ]);

                continue;
            }

            $entradas[] = [
                'orden'     => $orden,
                'nombre'    => self::nombre_de($archivo),
                'tipo'      => $tipo,
                'mime'      => (string) $archivo->getMimeType(),
                'tamano'    => (int) $archivo->getSize(),
                'path'      => $path,
                'estado'    => self::ESTADO_PENDIENTE,
                'texto'     => null,
                'recortado' => false,
                'error'     => null,
            ];

            $orden++;
        }

        return $entradas;
    }

    /**
     * Las entradas de la columna, o [] si el mensaje no trae archivos.
     *
     * @param  \App\Models\AiMessage  $mensaje
     * @return array<int, array<string, mixed>>
     */
    public static function datos(AiMessage $mensaje): array
    {
        $archivos = $mensaje->archivos;

        return is_array($archivos) ? array_values(array_filter($archivos, 'is_array')) : [];
    }

    /**
     * La entrada de un archivo por su orden, o null.
     *
     * @param  \App\Models\AiMessage  $mensaje
     * @param  int  $orden
     * @return array<string, mixed>|null
     */
    public static function archivo(AiMessage $mensaje, $orden)
    {
        foreach (self::datos($mensaje) as $entrada) {

            if ((int) ($entrada['orden'] ?? 0) === (int) $orden) {

                return $entrada;
            }
        }

        return null;
    }

    /**
     * Saca el texto de los archivos que todavía no se leyeron y guarda el mensaje. Respeta el techo de
     * caracteres del MENSAJE (lo que ya ocupan los leídos cuenta). Nunca lanza: un archivo que falla
     * queda `fallido` con su motivo y el asistente lo cuenta.
     *
     * @param  \App\Models\AiMessage  $mensaje
     * @return void
     */
    public static function extraer_pendientes(AiMessage $mensaje)
    {
        $entradas = self::datos($mensaje);

        if (!count($entradas)) {

            return;
        }

        $usados   = 0;
        $cambiado = false;

        foreach ($entradas as $entrada) {
            $usados += mb_strlen((string) ($entrada['texto'] ?? ''));
        }

        foreach ($entradas as $i => $entrada) {

            if ((string) ($entrada['estado'] ?? '') !== self::ESTADO_PENDIENTE) {
                continue;
            }

            $cambiado = true;
            $quedan   = self::MAX_CARACTERES_POR_MENSAJE - $usados;

            if ($quedan <= 0) {
                $entradas[$i]['estado']    = self::ESTADO_LISTO;
                $entradas[$i]['texto']     = '';
                $entradas[$i]['recortado'] = true;
                continue;
            }

            try {

                $texto = self::texto_del_archivo($entrada);

            } catch (\Throwable $e) {

                Log::info('ArchivosAdjuntosIaHelper: no se pudo leer un archivo', [
                    'ai_message_id' => $mensaje->id,
                    'orden'         => $entrada['orden'] ?? null,
                    'error'         => $e->getMessage(),
                ]);

                $entradas[$i]['estado'] = self::ESTADO_FALLIDO;
                $entradas[$i]['error']  = self::motivo_legible($e);
                continue;
            }

            $texto = self::limpiar_texto($texto);

            if ($texto === '') {
                $entradas[$i]['estado'] = self::ESTADO_FALLIDO;
                $entradas[$i]['error']  = 'no tiene texto que se pueda leer (puede ser un escaneo o una foto)';
                continue;
            }

            if (mb_strlen($texto) > $quedan) {
                $texto = mb_substr($texto, 0, $quedan);
                $entradas[$i]['recortado'] = true;
            }

            $usados += mb_strlen($texto);

            $entradas[$i]['estado'] = self::ESTADO_LISTO;
            $entradas[$i]['texto']  = $texto;
        }

        if ($cambiado) {
            $mensaje->archivos = $entradas;
            $mensaje->save();
        }
    }

    /**
     * El texto de un archivo guardado, según su tipo.
     *
     * @param  array<string, mixed>  $entrada
     * @return string
     *
     * @throws \RuntimeException
     */
    protected static function texto_del_archivo(array $entrada)
    {
        $path = (string) ($entrada['path'] ?? '');

        if ($path === '' || !Storage::disk('local')->exists($path)) {

            throw new \RuntimeException('el archivo ya no está guardado');
        }

        $ruta = Storage::disk('local')->path($path);
        $tipo = (string) ($entrada['tipo'] ?? '');

        if ($tipo === 'pdf') {

            return self::texto_de_pdf($ruta);
        }

        if ($tipo === 'csv') {

            return self::texto_de_csv($ruta);
        }

        return self::texto_de_planilla($ruta, $tipo);
    }

    /**
     * El texto de un PDF con smalot/pdfparser.
     *
     * @param  string  $ruta
     * @return string
     *
     * @throws \RuntimeException
     */
    protected static function texto_de_pdf($ruta)
    {
        $clase = self::CLASE_DEL_LECTOR_DE_PDF;

        if (!class_exists($clase)) {

            throw new \RuntimeException('este sistema todavía no puede leer PDF');
        }

        $parser = new $clase();

        $documento = $parser->parseFile($ruta);

        return (string) $documento->getText();
    }

    /**
     * El texto de un CSV, como renglones separados por " | ". Detecta el separador (`;`, `,` o
     * tabulador, lo que más aparezca en la primera línea) y pasa a UTF-8 un archivo que viene en
     * Latin-1 (el Excel en español lo guarda así).
     *
     * @param  string  $ruta
     * @return string
     */
    protected static function texto_de_csv($ruta)
    {
        $contenido = (string) file_get_contents($ruta);

        if (!mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'ISO-8859-1');
        }

        /* Sin el BOM que deja Excel al principio. */
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);

        $primera = strtok($contenido, "\n");
        $primera = $primera === false ? '' : $primera;

        $separador = ',';
        $mas       = -1;

        foreach ([';', ',', "\t"] as $candidato) {

            $veces = substr_count($primera, $candidato);

            if ($veces > $mas) {
                $mas       = $veces;
                $separador = $candidato;
            }
        }

        $flujo = fopen('php://temp', 'r+');
        fwrite($flujo, $contenido);
        rewind($flujo);

        $renglones = [];
        $filas     = 0;

        while (($fila = fgetcsv($flujo, 0, $separador)) !== false && $filas < self::MAX_FILAS) {

            $celdas = array_slice(array_map(function ($celda) {
                return trim((string) $celda);
            }, $fila), 0, self::MAX_COLUMNAS);

            if (implode('', $celdas) === '') {
                continue;
            }

            $renglones[] = implode(' | ', $celdas);
            $filas++;
        }

        $mas_filas = $fila !== false;

        fclose($flujo);

        return implode("\n", $renglones) . ($mas_filas ? "\n[El archivo tiene más filas: se leyeron las primeras " . self::MAX_FILAS . '.]' : '');
    }

    /**
     * El texto de una planilla (xlsx / xls) con PhpSpreadsheet, hoja por hoja: hasta MAX_HOJAS hojas,
     * MAX_FILAS filas y MAX_COLUMNAS columnas cada una, con los VALORES calculados (no las fórmulas).
     *
     * @param  string  $ruta
     * @param  string  $tipo
     * @return string
     */
    protected static function texto_de_planilla($ruta, $tipo)
    {
        $lector = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($tipo === 'xls' ? 'Xls' : 'Xlsx');

        /*
         * Con los formatos (no `setReadDataOnly(true)`): sin ellos una fecha sale como el número de
         * serie de Excel (45234) y el modelo no sabe que es una fecha. El filtro de abajo acota la
         * memoria igual.
         */
        $lector->setReadDataOnly(false);

        /*
         * 🔴 Solo se CARGA lo que se va a leer: las primeras hojas y, de cada una, las primeras filas y
         * columnas (una fila de más, para saber si la hoja sigue). Una planilla de 50.000 renglones
         * cargada entera se come la memoria del worker antes de leer la primera celda.
         */
        $nombres_de_hojas = $lector->listWorksheetNames($ruta);
        $hay_mas_hojas    = count($nombres_de_hojas) > self::MAX_HOJAS;

        $lector->setLoadSheetsOnly(array_slice($nombres_de_hojas, 0, self::MAX_HOJAS));
        $lector->setReadFilter(new class(self::MAX_FILAS + 1, self::MAX_COLUMNAS) implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
            /** @var int */
            private $filas;

            /** @var int */
            private $columnas;

            public function __construct($filas, $columnas)
            {
                $this->filas    = (int) $filas;
                $this->columnas = (int) $columnas;
            }

            public function readCell($columnAddress, $row, $worksheetName = '')
            {
                return (int) $row <= $this->filas
                    && \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($columnAddress) <= $this->columnas;
            }
        });

        $planilla = $lector->load($ruta);

        $partes = [];

        foreach ($planilla->getWorksheetIterator() as $hoja) {

            $filas_de_la_hoja = (int) $hoja->getHighestDataRow();
            $ultima_fila      = min($filas_de_la_hoja, self::MAX_FILAS);
            $ultima_columna   = min(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($hoja->getHighestDataColumn()),
                self::MAX_COLUMNAS
            );

            $renglones = [];

            for ($fila = 1; $fila <= $ultima_fila; $fila++) {

                $celdas = [];

                for ($columna = 1; $columna <= $ultima_columna; $columna++) {

                    /* cellExists antes de getCell: getCell CREA la celda que no existe y gasta memoria. */
                    if (!$hoja->cellExistsByColumnAndRow($columna, $fila)) {
                        $celdas[] = '';
                        continue;
                    }

                    $celdas[] = self::valor_de_celda($hoja->getCellByColumnAndRow($columna, $fila));
                }

                if (implode('', $celdas) === '') {
                    continue;
                }

                $renglones[] = implode(' | ', $celdas);
            }

            $titulo = 'Hoja «' . $hoja->getTitle() . '»';

            if ($filas_de_la_hoja > self::MAX_FILAS) {
                $titulo .= ' (se leyeron las primeras ' . self::MAX_FILAS . ' filas)';
            }

            $partes[] = $titulo . ":\n" . implode("\n", $renglones);
        }

        if ($hay_mas_hojas) {
            $partes[] = '[La planilla tiene más hojas: se leyeron las primeras ' . self::MAX_HOJAS . '.]';
        }

        $planilla->disconnectWorksheets();

        return implode("\n\n", $partes);
    }

    /**
     * El valor de una celda como lo ve la persona en Excel (con su formato: fechas, porcentajes).
     *
     * 🔴 Una fórmula se lee con el valor que dejó GUARDADO Excel (getOldCalculatedValue) y no se
     * recalcula: con el filtro de lectura las celdas de más abajo no están cargadas, y un SUM
     * recalculado sobre lo cargado daría un total chico y creíble que no es el de la planilla. Solo si
     * el archivo no trae ese valor (planillas armadas por un programa) se calcula.
     *
     * @param  \PhpOffice\PhpSpreadsheet\Cell\Cell  $celda
     * @return string
     */
    protected static function valor_de_celda($celda)
    {
        try {

            if (!$celda->isFormula()) {

                return trim((string) $celda->getFormattedValue());
            }

            $valor = $celda->getOldCalculatedValue();

            if (is_null($valor)) {
                $valor = $celda->getCalculatedValue();
            }

            if (!is_scalar($valor)) {

                return '';
            }

            $formato = (string) $celda->getStyle()->getNumberFormat()->getFormatCode();

            return trim((string) \PhpOffice\PhpSpreadsheet\Style\NumberFormat::toFormattedString($valor, $formato));

        } catch (\Throwable $e) {

            return '';
        }
    }

    /**
     * Saca caracteres de control y espacios de más, y deja UTF-8 válido.
     *
     * @param  string  $texto
     * @return string
     */
    protected static function limpiar_texto($texto)
    {
        $texto = (string) $texto;

        if (!mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'UTF-8');
        }

        $texto = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $texto);
        $texto = preg_replace("/[ \t]+/u", ' ', (string) $texto);
        $texto = preg_replace("/\n{3,}/u", "\n\n", (string) $texto);

        return trim((string) $texto);
    }

    /**
     * El motivo de una falla de lectura, para que el asistente lo cuente sin el detalle técnico.
     *
     * @param  \Throwable  $e
     * @return string
     */
    protected static function motivo_legible(\Throwable $e)
    {
        $mensaje = (string) $e->getMessage();

        if (strpos($mensaje, 'este sistema todavía no puede leer PDF') !== false) {

            return 'este sistema todavía no puede leer PDF';
        }

        if (stripos($mensaje, 'password') !== false || stripos($mensaje, 'encrypt') !== false) {

            return 'está protegido con contraseña';
        }

        return 'no se pudo abrir (puede estar dañado)';
    }

    /**
     * Lo que suma el mensaje del dueño al texto que lee el modelo: un bloque por archivo, con todo su
     * texto si es el último mensaje del dueño y con un extracto si es uno anterior. '' si no trae
     * archivos.
     *
     * @param  \App\Models\AiMessage  $mensaje
     * @param  bool  $completo
     * @return string
     */
    public static function texto_para_el_modelo(AiMessage $mensaje, $completo = true)
    {
        $bloques = [];

        foreach (self::datos($mensaje) as $entrada) {

            $nombre = (string) ($entrada['nombre'] ?? 'archivo');
            $estado = (string) ($entrada['estado'] ?? '');

            if ($estado === self::ESTADO_FALLIDO) {
                $bloques[] = '[Archivo adjunto: ' . $nombre . ' — no lo pude leer: ' . (string) ($entrada['error'] ?? 'no se pudo abrir') . '.]';
                continue;
            }

            if ($estado !== self::ESTADO_LISTO) {
                $bloques[] = '[Archivo adjunto: ' . $nombre . ' — todavía sin leer.]';
                continue;
            }

            $texto     = (string) ($entrada['texto'] ?? '');
            $recortado = !empty($entrada['recortado']);

            if (!$completo && mb_strlen($texto) > self::MAX_CARACTERES_EN_EL_HISTORIAL) {
                $texto     = mb_substr($texto, 0, self::MAX_CARACTERES_EN_EL_HISTORIAL);
                $recortado = true;
            }

            $bloques[] = 'Archivo adjunto: ' . $nombre . ' — contenido:' . "\n" . $texto
                . ($recortado ? "\n[recortado: el archivo sigue más allá de esto]" : '')
                . "\n[fin del archivo " . $nombre . ']';
        }

        return implode("\n\n", $bloques);
    }

    /**
     * Lo que viaja en el mensaje (contrato C5): `[{orden, nombre, tipo, tamano, url}]` o null.
     *
     * @param  \App\Models\AiMessage  $mensaje
     * @return array<int, array<string, mixed>>|null
     */
    public static function para_la_respuesta(AiMessage $mensaje)
    {
        $entradas = self::datos($mensaje);

        if (!count($entradas) || (int) $mensaje->id <= 0) {

            return null;
        }

        $publicos = [];

        foreach ($entradas as $entrada) {

            $orden = (int) ($entrada['orden'] ?? 0);

            $publicos[] = [
                'orden'  => $orden,
                'nombre' => (string) ($entrada['nombre'] ?? 'archivo'),
                'tipo'   => (string) ($entrada['tipo'] ?? ''),
                'tamano' => (int) ($entrada['tamano'] ?? 0),
                'url'    => url(self::RUTA . '/' . (int) $mensaje->id . '/archivo/' . $orden),
            ];
        }

        return $publicos;
    }
}
