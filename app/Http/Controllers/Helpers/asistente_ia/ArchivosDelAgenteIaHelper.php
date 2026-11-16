<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Exports\TablaDelAgenteExport;
use App\Models\AiArchivo;
use App\Models\AiConversation;
use App\Models\AiMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel as FormatoDeExcel;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;

/**
 * Los archivos que el agente personal le arma al dueño para bajar (misión agente-mejoras-uso-real,
 * 29/10/2026, §4 E3.1 del plan). Hoy, el Excel de la herramienta exportar_excel.
 *
 * EL CASO REAL (CONV 9 de Ferre Total, 28/10/2026): el dueño pidió el ranking de ventas de los últimos
 * 90 días y después *"¿me lo podés volcar en un archivo Excel?"*. El agente contestó *"No puedo generar
 * archivos Excel desde acá"* y le ofreció un texto separado por punto y coma para que lo pegue. Ahora
 * arma el .xlsx y devuelve un link que lo baja, por WhatsApp, en el sistema y en la app.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * 🔴 LA SEGURIDAD DEL LINK (el archivo trae datos del negocio: ventas, deudas, costos)
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 *   1. EL ARCHIVO VIVE EN EL DISCO `local`, que es PRIVADO (`storage/app`, que ninguna ruta sirve
 *      entera: `/storage/{path}` sirve solo `storage/app/public`). Nunca en `public`.
 *   2. LA RUTA PÚBLICA EXIGE EL TOKEN: `GET asistente/archivo/{uuid}?t=<token>`. El token es aleatorio
 *      (LARGO_DEL_TOKEN caracteres, ~285 bits) y en la base se guarda SOLO su sha256: se compara con
 *      hash_equals contra el sha256 de lo que llega. Con la base en la mano no se arma un link.
 *   3. VENCE: a los DIAS_DE_VIDA días el link da 404 aunque el token sea el correcto, y el comando
 *      diario `asistente:limpiar-archivos` borra el archivo y la fila.
 *   4. NUNCA UN ARCHIVO AJENO: la ruta del archivo sale de la FILA (nunca del pedido), y se exige que
 *      esté adentro de la carpeta del dueño de esa fila (`asistente_archivos/{user_id}/`). Un uuid
 *      de otro dueño sin su token es un 404, igual que uno que no existe: no se distingue.
 *   5. El link sale de LinkDePdfIaHelper::base_publica() (users.api_url normalizada → la de la
 *      config): la misma base que los PDF del agente. Sin base, se dice y no se arma un link a medias.
 *
 * PHP 7.4: sin match, sin nullsafe, sin argumentos nombrados, sin str_contains.
 */
class ArchivosDelAgenteIaHelper
{
    /** Techo de filas de un Excel del agente (la tabla de la app tiene 300; un Excel aguanta más). */
    const MAX_FILAS_DEL_EXCEL = 1000;

    /** Cuántos días vale el link. */
    const DIAS_DE_VIDA = 7;

    /** Largo del token del link (Str::random: letras y números). */
    const LARGO_DEL_TOKEN = 48;

    /** La carpeta de los archivos en el disco privado. */
    const CARPETA = 'asistente_archivos';

    /** El disco: PRIVADO. Ver el 🔴 1 del docblock. */
    const DISCO = 'local';

    /** La ruta pública de descarga (routes/web.php), sin la base. */
    const RUTA = 'asistente/archivo';

    /** Largo máximo del nombre del archivo, sin la extensión. */
    const MAX_NOMBRE = 80;

    /** El Content-Type de un .xlsx. */
    const MIME_XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * Herramienta exportar_excel: valida la tabla, arma el .xlsx en el disco privado, guarda la fila y
     * devuelve el link con su token. Nunca lanza: cualquier falla vuelve como `{ok: false, error}` para
     * que el modelo la cuente tal cual (es una herramienta de lectura: el contenido es lo que devuelve).
     *
     * @param  int  $owner_id  El dueño de la cuenta (conversación.user_id).
     * @param  \App\Models\AiConversation|null  $conversation  Para saber quién lo pidió.
     * @param  \App\Models\AiMessage|null  $assistant_message  El mensaje del agente en curso (null desde el MCP).
     * @param  array  $input  titulo*, columnas*, filas*, nombre_archivo
     * @return array<string, mixed>
     */
    public static function exportar_excel($owner_id, $conversation, $assistant_message, array $input): array
    {
        /*
         * La ventana entre copiar el código y correr las migraciones de un upgrade: sin la tabla no hay
         * dónde guardar el token y el link no podría funcionar.
         */
        if (!self::hay_tabla()) {

            return self::no_se_puede('Esta instalación todavía no tiene lista la parte de archivos del agente (falta actualizar el sistema). Por ahora no puedo armar el Excel: decíselo a la persona y ofrecele los datos en el texto.');
        }

        // La MISMA validación que mostrar_tabla (una sola definición de tabla válida), con el techo del Excel.
        $validado = BloquesIaHelper::tabla($input, self::MAX_FILAS_DEL_EXCEL);

        if (is_null($validado['bloque'])) {

            return self::no_se_puede('No se pudo armar el Excel: ' . $validado['error'] . '. Corregilo y volvé a llamar.');
        }

        $tabla = $validado['bloque']['tabla'];

        // Un Excel con solo el encabezado no le sirve a nadie: los datos salen de las consultas.
        if (!count($tabla['filas'])) {

            return self::no_se_puede('La tabla no tiene filas. Primero consultá los datos con las herramientas de consulta y después mandá las filas acá.');
        }

        return self::guardar_tabla_en_excel($owner_id, $conversation, $assistant_message, $tabla, isset($input['nombre_archivo']) ? $input['nombre_archivo'] : null);
    }

    /**
     * Arma el .xlsx de una tabla YA NORMALIZADA ({titulo, columnas: [{clave, etiqueta, formato}], filas}),
     * lo deja en el disco privado, guarda la fila y devuelve el link con su token. Es lo que hacía
     * exportar_excel() después de validar; separado en la misión asistente-mejoras-4-dias (16/11/2026, P5)
     * para que el Excel que arma el SERVIDOR (ValorizacionDeStockIaHelper::exportar_excel, sin el techo de
     * filas que escribe el modelo) salga por el mismo canal, con la misma seguridad del link.
     *
     * @param  int  $owner_id
     * @param  \App\Models\AiConversation|null  $conversation
     * @param  \App\Models\AiMessage|null  $assistant_message
     * @param  array  $tabla
     * @param  mixed  $nombre_pedido  El nombre_archivo que pidió el modelo, o null.
     * @return array<string, mixed>
     */
    public static function guardar_tabla_en_excel($owner_id, $conversation, $assistant_message, array $tabla, $nombre_pedido = null): array
    {
        if (!self::hay_tabla()) {

            return self::no_se_puede('Esta instalación todavía no tiene lista la parte de archivos del agente (falta actualizar el sistema). Por ahora no puedo armar el Excel: decíselo a la persona y ofrecele los datos en el texto.');
        }

        $base = LinkDePdfIaHelper::base_publica((int) $owner_id);

        if ($base === '') {

            return self::no_se_puede(
                'Este sistema no tiene cargada la dirección pública de su API, así que el link del Excel saldría sin dominio y no le abriría a nadie. '
                . 'Decile a la persona que lo avise a ComercioCity (se corrige con el comando normalizar_api_url) y, mientras, pasale los datos en el texto.'
            );
        }

        $uuid = (string) Str::uuid();
        $token = Str::random(self::LARGO_DEL_TOKEN);
        $path = self::CARPETA . '/' . (int) $owner_id . '/' . $uuid . '.xlsx';
        $nombre = self::nombre_de_archivo($nombre_pedido, $tabla['titulo']);
        $vence = Carbon::now()->addDays(self::DIAS_DE_VIDA);

        try {

            // El binario en memoria (Excel::raw) y después al disco privado con Storage: así se escribe por
            // el MISMO disco que lee la descarga.
            $binario = self::armar_xlsx($tabla);

            Storage::disk(self::DISCO)->put($path, $binario);

            AiArchivo::create([
                'uuid'          => $uuid,
                'user_id'       => (int) $owner_id,
                'auth_user_id'  => ($conversation instanceof AiConversation && !is_null($conversation->auth_user_id)) ? (int) $conversation->auth_user_id : null,
                'ai_message_id' => ($assistant_message instanceof AiMessage) ? (int) $assistant_message->id : null,
                'tipo'          => AiArchivo::TIPO_EXCEL,
                'path'          => $path,
                'nombre'        => $nombre,
                'token_hash'    => hash('sha256', $token),
                'filas'         => count($tabla['filas']),
                'expires_at'    => $vence,
            ]);

        } catch (\Throwable $e) {

            // Si el archivo llegó a escribirse y la fila no, no queda huérfano en el disco.
            try {
                Storage::disk(self::DISCO)->delete($path);
            } catch (\Throwable $ignorada) {
                // Nada: lo importante es contestarle al modelo.
            }

            Log::warning('ArchivosDelAgenteIaHelper: no se pudo armar el Excel.', [
                'owner_id' => (int) $owner_id,
                'error'    => $e->getMessage(),
            ]);

            return self::no_se_puede('No pude armar el Excel por una falla del sistema. Decíselo a la persona tal cual y ofrecele los datos en el texto; no inventes otro motivo.');
        }

        return [
            'ok'             => true,
            'link'           => $base . '/' . self::RUTA . '/' . $uuid . '?t=' . $token,
            'nombre_archivo' => $nombre,
            'filas'          => count($tabla['filas']),
            'columnas'       => count($tabla['columnas']),
            'vence'          => $vence->format('d/m/Y'),
            'nota'           => 'El Excel ya está armado. Pasale el link TAL CUAL a la persona, sin acortarlo ni cambiarlo: al tocarlo se baja el archivo '
                . $nombre . '. Vale hasta el ' . $vence->format('d/m/Y') . ' (' . self::DIAS_DE_VIDA . ' días); después hay que pedirlo de nuevo. '
                . 'No repitas la tabla en el texto: contá en una oración qué tiene. Nunca digas que no podés generar archivos.',
        ];
    }

    /**
     * El archivo que corresponde a un link, o null si el link no vale (no existe, el token no coincide,
     * venció, la ruta guardada no está en la carpeta de su dueño o el archivo ya no está en el disco).
     * La ruta pública contesta 404 en todos esos casos por igual.
     *
     * @param  mixed  $uuid  El {uuid} de la URL.
     * @param  mixed  $token  El `?t=` de la URL (puede venir cualquier cosa: un array, null).
     * @return AiArchivo|null
     */
    public static function para_descargar($uuid, $token)
    {
        if (!is_string($uuid) || !preg_match('/^[0-9a-f\-]{36}$/i', $uuid)) {

            return null;
        }

        // Un token que no es texto o que no tiene el largo de los nuestros no se compara siquiera.
        if (!is_string($token) || strlen($token) !== self::LARGO_DEL_TOKEN) {

            return null;
        }

        if (!self::hay_tabla()) {

            return null;
        }

        $archivo = AiArchivo::where('uuid', $uuid)->first();

        if (is_null($archivo)) {

            return null;
        }

        // 🔴 El token, en tiempo constante contra el hash guardado (el token en claro no está en la base).
        if (!hash_equals((string) $archivo->token_hash, hash('sha256', $token))) {

            return null;
        }

        if ($archivo->vencido()) {

            return null;
        }

        // 🔴 La ruta sale de la fila y tiene que estar adentro de la carpeta de SU dueño, sin "..".
        $carpeta_del_dueno = self::CARPETA . '/' . (int) $archivo->user_id . '/';

        $path = (string) $archivo->path;

        if (strpos($path, $carpeta_del_dueno) !== 0 || strpos($path, '..') !== false) {

            return null;
        }

        if (!Storage::disk(self::DISCO)->exists($path)) {

            return null;
        }

        return $archivo;
    }

    /**
     * Borra los archivos vencidos (el binario y la fila). Lo corre el comando diario
     * `asistente:limpiar-archivos`. Un archivo que ya no está en el disco se borra igual de la base.
     *
     * @return array{archivos: int, fallidos: int}
     */
    public static function limpiar_vencidos(): array
    {
        $borrados = 0;
        $fallidos = 0;

        if (!self::hay_tabla()) {

            return ['archivos' => 0, 'fallidos' => 0];
        }

        AiArchivo::where(function ($query) {
            $query->where('expires_at', '<', Carbon::now())->orWhereNull('expires_at');
        })->chunkById(200, function ($archivos) use (&$borrados, &$fallidos) {

            foreach ($archivos as $archivo) {

                try {

                    $path = (string) $archivo->path;

                    // Solo se borra adentro de la carpeta de los archivos del agente.
                    if ($path !== '' && strpos($path, self::CARPETA . '/') === 0 && strpos($path, '..') === false) {
                        Storage::disk(self::DISCO)->delete($path);
                    }

                    $archivo->delete();

                    $borrados++;

                } catch (\Throwable $e) {

                    $fallidos++;

                    Log::warning('ArchivosDelAgenteIaHelper: no se pudo borrar un archivo vencido.', [
                        'ai_archivo_id' => (int) $archivo->id,
                        'error'         => $e->getMessage(),
                    ]);
                }
            }
        });

        return ['archivos' => $borrados, 'fallidos' => $fallidos];
    }

    /**
     * El .xlsx de la tabla, en memoria.
     *
     * 🔴 EL BINDER ESTÁTICO SE DEVUELVE COMO ESTABA. maatwebsite instala el value binder de la export
     * (TablaDelAgenteExport::bindValue, el que nunca escribe una fórmula) con Cell::setValueBinder(), que
     * es ESTÁTICO de PhpSpreadsheet, y no lo saca al terminar. En un worker de la cola el proceso sigue
     * vivo: el próximo Excel que se arme o se lea en ese proceso (una importación de artículos, otro
     * export) pasaría por este binder sin saberlo. Medido el 29/10/2026: después del export, leer el
     * mismo archivo con IOFactory devolvía los números como texto. Por eso se guarda el de antes y se
     * repone en el finally, salga bien o mal.
     *
     * @param  array  $tabla  El `tabla` normalizado de BloquesIaHelper::tabla().
     * @return string
     */
    protected static function armar_xlsx(array $tabla): string
    {
        // P5 (16/11/2026): las tablas grandes que arma el servidor van por el camino directo (ver armar_xlsx_grande()).
        if (count($tabla['filas']) > self::MAX_FILAS_DEL_EXCEL) {

            return self::armar_xlsx_grande($tabla);
        }

        $binder_anterior = Cell::getValueBinder();

        try {

            return (string) Excel::raw(new TablaDelAgenteExport($tabla), FormatoDeExcel::XLSX);

        } finally {

            Cell::setValueBinder($binder_anterior);
        }
    }

    /**
     * El .xlsx de una tabla GRANDE (más de MAX_FILAS_DEL_EXCEL filas: solo las que arma el servidor, como
     * ValorizacionDeStockIaHelper::exportar_excel), escrito directo con PhpSpreadsheet.
     *
     * Misión asistente-mejoras-4-dias (16/11/2026, P5). Medido en s3 con 10.000 artículos: por maatwebsite
     * (con ShouldAutoSize, que mide cada celda) 12,6 s; directo, 5 s. El turno del agente tiene techo de
     * tiempo y el Excel de un catálogo entero no puede comérselo. El contenido es el MISMO: las filas, el
     * encabezado, el título y los formatos salen de TablaDelAgenteExport (array(), headings(), title(),
     * columnFormats()), y cada celda se escribe con su tipo EXPLÍCITO como en su bindValue(): los números
     * como número y todo lo demás como texto, 🔴 nunca como fórmula. El ancho de cada columna se estima
     * con las primeras filas en vez de medir todas.
     *
     * No pasa por el binder estático, así que no hay nada que devolver como estaba (ver armar_xlsx()).
     *
     * @param  array  $tabla
     * @return string
     */
    protected static function armar_xlsx_grande(array $tabla): string
    {
        $export = new TablaDelAgenteExport($tabla);

        $libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle($export->title());

        $encabezado = $export->headings();
        $anchos = [];

        foreach ($encabezado as $i => $etiqueta) {

            $hoja->setCellValueExplicitByColumnAndRow($i + 1, 1, (string) $etiqueta, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $anchos[$i] = mb_strlen((string) $etiqueta);
        }

        $hoja->getStyle('1:1')->getFont()->setBold(true);

        $fila = 1;

        foreach ($export->array() as $renglon) {

            $fila++;

            foreach ($renglon as $i => $valor) {

                if (is_null($valor)) {
                    continue;
                }

                if (is_int($valor) || is_float($valor)) {

                    $hoja->setCellValueExplicitByColumnAndRow($i + 1, $fila, $valor, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);

                } else {

                    $texto = is_bool($valor) ? ($valor ? 'Sí' : 'No') : (string) $valor;

                    $hoja->setCellValueExplicitByColumnAndRow($i + 1, $fila, $texto, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

                    if ($fila <= 200) {
                        $anchos[$i] = max($anchos[$i], mb_strlen($texto));
                    }
                }
            }
        }

        foreach ($export->columnFormats() as $letra => $formato) {

            $hoja->getStyle($letra . '2:' . $letra . max(2, $fila))->getNumberFormat()->setFormatCode($formato);
        }

        foreach ($anchos as $i => $ancho) {

            $hoja->getColumnDimensionByColumn($i + 1)->setWidth(min(60, max(10, $ancho + 2)));
        }

        $escritor = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro);

        ob_start();

        try {

            $escritor->save('php://output');

            return (string) ob_get_contents();

        } finally {

            ob_end_clean();

            $libro->disconnectWorksheets();
        }
    }

    /**
     * El nombre con el que se baja el archivo: el que pidió el modelo o el título de la tabla, pasado a
     * "ranking-de-ventas" (sin tildes ni espacios, que en un adjunto de WhatsApp se ven mal), con la
     * extensión .xlsx. Sin nada que quede, "datos.xlsx".
     *
     * @param  mixed  $pedido  El `nombre_archivo` del input (opcional).
     * @param  string  $titulo  El título de la tabla.
     * @return string
     */
    protected static function nombre_de_archivo($pedido, $titulo): string
    {
        $base = is_string($pedido) && trim($pedido) !== '' ? $pedido : (string) $titulo;

        // Si el modelo ya le puso la extensión, se saca para no terminar en .xlsx.xlsx.
        $base = preg_replace('/\.xlsx?$/i', '', trim($base));

        $slug = Str::slug((string) $base);

        $slug = trim(mb_substr($slug, 0, self::MAX_NOMBRE), '-');

        return ($slug === '' ? 'datos' : $slug) . '.xlsx';
    }

    /**
     * true si la tabla `ai_archivos` existe. Protegido: si la base no contesta, se toma como que no.
     *
     * @return bool
     */
    protected static function hay_tabla(): bool
    {
        try {
            return Schema::hasTable('ai_archivos');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * La respuesta cuando no hay Excel. `link` va en null a propósito: el modelo tiene que poder
     * distinguir "no hay" de "está vacío", y la regla es contar el `error` tal cual.
     *
     * @param  string  $motivo
     * @return array<string, mixed>
     */
    protected static function no_se_puede($motivo): array
    {
        return [
            'ok'    => false,
            'link'  => null,
            'error' => (string) $motivo,
        ];
    }
}
