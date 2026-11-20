<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Genera bloques sin DDL ni procedimientos persistentes. Todos los nombres son internos. */
class ReversionMigracionSql
{
    public $uuid;
    private $owner;
    private $operacion;
    private $nombres;

    public function __construct($owner, $operacion = 'listas:migrar-a-precio-final', array $nombres = [])
    {
        $this->owner = (int) $owner;
        $this->operacion = $operacion;
        $this->nombres = $nombres;
        $this->uuid = (string) \Illuminate\Support\Str::uuid();
    }

    public static function escribir($stream, $texto)
    {
        if (!is_resource($stream) || @fwrite($stream, $texto) !== strlen($texto) || !@fflush($stream)) {
            throw new \RuntimeException('No se pudo persistir el respaldo completo; se aborta el bloque.', 9002);
        }
    }

    public static function json($valor)
    {
        $json = json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw new \RuntimeException('No se pudo codificar el respaldo.', 9002);
        }
        return $json;
    }

    public function foto(array $ambitos)
    {
        $foto = [];
        foreach ($ambitos as $tabla => $where) {
            $foto[$tabla] = array_map(function ($fila) { return (array) $fila; },
                DB::table($tabla)->whereRaw($where)->orderBy('id')->get()->all());
        }
        return $foto;
    }

    private function literal($valor)
    {
        if ($valor === null) { return 'NULL'; }
        if (is_string($valor) && (strpos($valor, "\n") !== false || strpos($valor, "\r") !== false)) {
            return 'CONVERT(0x'.bin2hex($valor).' USING utf8mb4)';
        }
        return is_int($valor) || is_float($valor) ? (string) $valor : DB::connection()->getPdo()->quote((string) $valor);
    }

    private function igualdad($tabla, $where, array $filas)
    {
        // Cada subconsulta usa current read: un candado previo no actualiza la foto RR.
        $condiciones = ['(SELECT COUNT(*) FROM `'.$tabla.'` WHERE '.$where.' FOR UPDATE) = '.count($filas)];
        foreach ($filas as $fila) {
            $columnas = [];
            foreach ($fila as $col => $valor) {
                $columnas[] = 'BINARY `'.$col.'` <=> BINARY '.$this->literal($valor);
            }
            $condiciones[] = 'EXISTS (SELECT 1 FROM `'.$tabla.'` WHERE '.implode(' AND ', $columnas).' FOR UPDATE)';
        }
        return implode(' AND ', $condiciones);
    }

    /** $orden es explícitamente hijos antes de padres, no el orden de la captura. */
    public function bloque($titulo, $clave, $tipo, $id, array $ambitos, array $antes, array $despues, array $orden, array $logs, $extra = '1')
    {
        $uuid = $this->literal($this->uuid);
        $sql = $titulo."\nSET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ;\nSTART TRANSACTION;\nIF TRUE THEN BEGIN DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;\nSELECT id INTO @rollback_dueno FROM users WHERE id = ".$this->owner." FOR UPDATE;\n";
        $sql .= 'SET @rollback_aplicar = NOT EXISTS (SELECT 1 FROM audit_logs WHERE batch_uuid = '.$uuid
            ." AND event = 'rollback' AND JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.bloque')) = ".$this->literal($clave)." FOR UPDATE);\n";
        // Lecturas bloqueantes ANTES de validar: filas y huecos del predicado completo.
        // RR es explícito: ni un cambio de aislamiento del cliente permite inserts fantasma.
        // Orden común con los productores: dueño, artículo, variantes/historial, hijos.
        $candados = $ambitos;
        $prioridad = ['users' => 0, 'articles' => 1, 'article_variants' => 2, 'price_changes' => 3];
        uksort($candados, function ($a, $b) use ($prioridad) {
            $pa = $prioridad[$a] ?? 4; $pb = $prioridad[$b] ?? 4;
            return $pa === $pb ? strcmp($a, $b) : $pa <=> $pb;
        });
        foreach ($candados as $tabla => $where) {
            // Cursor sin resultsets adicionales; cada fila se visita bajo FOR UPDATE.
            // El scan completo conserva también next-key locks de rangos VACÍOS.
            $sql .= 'BEGIN DECLARE fin INT DEFAULT 0; DECLARE fila BIGINT; DECLARE filas CURSOR FOR SELECT id FROM `'
                .$tabla.'` WHERE '.$where.' ORDER BY id FOR UPDATE; DECLARE CONTINUE HANDLER FOR NOT FOUND SET fin = 1; '
                .'OPEN filas; candados: LOOP FETCH filas INTO fila; IF fin = 1 THEN LEAVE candados; END IF; END LOOP; CLOSE filas; END;'."\n";
        }
        $checks = [$extra];
        foreach ($ambitos as $tabla => $where) {
            $checks[] = $this->igualdad($tabla, $where, $despues[$tabla]);
        }
        // Una subconsulta escalar con dos filas aborta ANTES de compensar si la foto cambió.
        $sql .= 'SET @rollback_precondicion = (SELECT 1 UNION ALL SELECT 2 WHERE @rollback_aplicar = 1 AND NOT ('
            .implode(' AND ', $checks)."));\n";
        foreach ($orden as $tabla) {
            $previos = array_column($antes[$tabla], null, 'id');
            foreach ($despues[$tabla] as $fila) {
                if (!isset($previos[$fila['id']])) {
                    $sql .= 'DELETE FROM '.$tabla.' WHERE id = '.(int) $fila['id']." AND @rollback_aplicar = 1;\n";
                }
            }
            $aplicados = array_column($despues[$tabla], null, 'id');
            foreach ($previos as $pk => $fila) {
                if (!isset($aplicados[$pk])) {
                    throw new \RuntimeException('La migración eliminó una fila previa: no se puede respaldar.', 9002);
                }
                if ($fila === $aplicados[$pk] && $tabla !== 'articles') { continue; }
                // Mantener la fecha de stock como último campo restaurado (contrato SQL legado).
                if ($tabla === 'articles' && array_key_exists('stock_updated_at', $fila)) {
                    $fecha_stock = $fila['stock_updated_at'];
                    unset($fila['stock_updated_at']);
                    $fila['stock_updated_at'] = $fecha_stock;
                }
                $sets = [];
                foreach ($fila as $col => $valor) {
                    if ($col !== 'id' && ($tabla !== 'users' || $valor !== $aplicados[$pk][$col])) {
                        $nombre = in_array($col, ['price', 'listas_de_precio', 'stock_updated_at'], true) ? $col : '`'.$col.'`';
                        $sets[] = $nombre.' = '.$this->literal($valor);
                    }
                }
                $sql .= 'UPDATE '.$tabla.' SET '.implode(', ', $sets).' WHERE id = '.(int) $pk." AND @rollback_aplicar = 1;\n";
            }
        }
        $estado_antes = []; $estado_despues = [];
        foreach ($antes as $tabla => $filas) {
            $nombre = $this->nombres[$tabla] ?? $tabla;
            $estado_antes[$nombre] = $filas;
            $estado_despues[$nombre] = $despues[$tabla];
        }
        $old = $this->literal(self::json(['estado' => $estado_despues]));
        $new = $this->literal(self::json(['respaldo_uuid' => $this->uuid, 'bloque' => $clave,
            'operacion' => $this->operacion, 'logs_originales' => array_map('intval', $logs), 'estado' => $estado_antes]));
        $sql .= 'INSERT INTO audit_logs (user_id, actor_id, auditable_type, auditable_id, event, source, origin, batch_uuid, old_values, new_values, created_at) SELECT '
            .$this->owner.', NULL, '.$this->literal($tipo).', '.(int) $id.", 'rollback', 'console', ".$this->literal($this->operacion.' / reversion').", "
            .$uuid.', '.$old.', '.$new.", CURRENT_TIMESTAMP WHERE @rollback_aplicar = 1;\nEND; END IF;\nCOMMIT;\n\n";
        // El cuerpo compuesto es una única sentencia SQL (también en una sola línea física).
        $inicio = strpos($sql, 'IF TRUE');
        $fin = strrpos($sql, "\nCOMMIT;");
        return substr($sql, 0, $inicio).str_replace("\n", ' ', substr($sql, $inicio, $fin - $inicio)).substr($sql, $fin);
    }
}
