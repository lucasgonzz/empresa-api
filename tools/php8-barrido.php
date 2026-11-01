<?php
/*
 * Barrido de la clase "código escrito para PHP 7.4 que rompe en PHP 8" sobre empresa-api.
 *
 * Las APIs de clientes del VPS corren PHP-FPM 8.4; el CLI y el shared, 7.4. Lo que en 7.4 era un
 * deprecated o un warning silencioso en 8 es un Error. Este escáner recorre el código con
 * nikic/php-parser (ya está en vendor) y marca, sin ejecutar nada:
 *
 *  - static-a-metodo-de-instancia: `Clase::metodo()` / `Self::metodo()` sobre un método sin `static`.
 *  - this-en-static: `$this` dentro de un método `static`.
 *  - aritmetica-con-string / aritmetica-con-number_format: cuentas con un string no numérico
 *    («1.234,50» de number_format, literales, strings armados): TypeError en PHP 8.
 *  - funcion-removida / implode-orden-viejo: funciones que ya no existen y el orden legado de implode().
 *  - array-func-con-request-sin-default: count()/in_array()/array_*() sobre un dato del request sin
 *    default (si falta llega null y en PHP 8 es TypeError). Candidatos: se revisan a mano.
 *  - deprecada (info): utf8_encode, strftime… siguen andando en 8.4.
 *
 * Uso (con el PHP más alto que haya instalado):
 *   php tools/php8-barrido.php [carpetas separadas por coma]   # por defecto app,routes,database,config
 *   php tools/php8-barrido.php app/Http,app/Services --json
 *
 * Salta los archivos de más de 1 MB (hay uno de datos de 41 MB en app/Http/Controllers/articles.php).
 * Un hallazgo no es una condena: `Clase::metodo()` es válido dentro de un método de instancia de una
 * subclase (el escáner ya lo descarta) y `$this` dentro de un closure no estático también; revisá
 * cada línea. Sale con código 1 si hay hallazgos de severidad alta.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('memory_limit', '2G');

$args = array_values(array_filter(array_slice($argv, 1), function ($a) { return $a !== '--json'; }));
$como_json = in_array('--json', $argv, true);
$root = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
require $root . '/vendor/autoload.php';

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

$dirs = isset($args[0]) ? explode(',', $args[0]) : ['app', 'routes', 'database', 'config'];
$files = [];
foreach ($dirs as $d) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$d", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (substr($f->getFilename(), -4) === '.php' && $f->getSize() < 1000000) {
            $files[] = str_replace('\\', '/', $f->getPathname());
        }
    }
}
sort($files);

$parser = (new ParserFactory())->createForNewestSupportedVersion();

/* ---------- pasada 1: índice de clases ---------- */
class Indexador extends NodeVisitorAbstract {
    public $clases = [];
    public $file;
    public function enterNode(Node $n) {
        if ($n instanceof Stmt\Class_ || $n instanceof Stmt\Trait_ || $n instanceof Stmt\Interface_) {
            if (!$n->name) return null;
            $fq = $n->namespacedName ? $n->namespacedName->toString() : $n->name->toString();
            $info = ['file' => $this->file, 'methods' => [], 'parent' => null, 'traits' => [], 'callStatic' => false, 'interfaces' => []];
            if ($n instanceof Stmt\Class_ && $n->extends) $info['parent'] = $n->extends->toString();
            if ($n instanceof Stmt\Class_) foreach ($n->implements as $i) $info['interfaces'][] = $i->toString();
            foreach ($n->stmts as $s) {
                if ($s instanceof Stmt\ClassMethod) {
                    $info['methods'][strtolower($s->name->toString())] = $s->isStatic();
                    if (strtolower($s->name->toString()) === '__callstatic') $info['callStatic'] = true;
                } elseif ($s instanceof Stmt\TraitUse) {
                    foreach ($s->traits as $t) $info['traits'][] = $t->toString();
                }
            }
            $this->clases[$fq] = $info;
        }
        return null;
    }
}

$ast = [];
$indice = [];
$errores_parseo = [];
foreach ($files as $f) {
    try {
        $stmts = $parser->parse(file_get_contents($f));
    } catch (\Throwable $e) {
        $errores_parseo[] = [$f, $e->getMessage()];
        continue;
    }
    $t = new NodeTraverser();
    $t->addVisitor(new NameResolver());
    $stmts = $t->traverse($stmts);
    $ix = new Indexador();
    $ix->file = $f;
    $t2 = new NodeTraverser();
    $t2->addVisitor($ix);
    $t2->traverse($stmts);
    $indice += $ix->clases;
    $ast[$f] = $stmts;
}

/* Resolución de un método en la jerarquía: devuelve [encontrado(bool), esStatic|null, hayCallStatic] */
function buscar_metodo(string $clase, string $metodo, array &$indice, int $prof = 0): array {
    $metodo = strtolower($metodo);
    if ($prof > 12) return [false, null, false];
    if (isset($indice[$clase])) {
        $c = $indice[$clase];
        if (isset($c['methods'][$metodo])) return [true, $c['methods'][$metodo], $c['callStatic']];
        $cs = $c['callStatic'];
        foreach ($c['traits'] as $t) {
            [$ok, $st, $cs2] = buscar_metodo($t, $metodo, $indice, $prof + 1);
            if ($ok) return [true, $st, $cs || $cs2];
            $cs = $cs || $cs2;
        }
        if ($c['parent']) {
            [$ok, $st, $cs2] = buscar_metodo($c['parent'], $metodo, $indice, $prof + 1);
            return [$ok, $st, $cs || $cs2];
        }
        return [false, null, $cs];
    }
    // clase de vendor / del núcleo: reflexión
    if (class_exists($clase) || trait_exists($clase)) {
        try {
            $rc = new ReflectionClass($clase);
            $cs = $rc->hasMethod('__callStatic');
            if ($rc->hasMethod($metodo)) {
                return [true, $rc->getMethod($metodo)->isStatic(), $cs];
            }
            return [false, null, $cs];
        } catch (\Throwable $e) {
            return [false, null, false];
        }
    }
    return [false, null, false];
}

function es_subclase(string $hijo, string $padre, array &$indice, int $prof = 0): bool {
    if ($hijo === $padre) return true;
    if ($prof > 12) return false;
    if (isset($indice[$hijo])) {
        $p = $indice[$hijo]['parent'];
        return $p ? es_subclase($p, $padre, $indice, $prof + 1) : false;
    }
    if (class_exists($hijo)) return is_subclass_of($hijo, $padre);
    return false;
}

/* ---------- pasada 2: hallazgos ---------- */
$hallazgos = [];

class Detector extends NodeVisitorAbstract {
    public $file;
    public $indice;
    public $out;
    private $clase = [];     // pila de clase actual
    private $metodo = [];    // pila de [nombre, static]
    private $fvars = [];     // pila: variables asignadas desde number_format en la función actual

    const ARITH = [
        Expr\BinaryOp\Plus::class, Expr\BinaryOp\Minus::class, Expr\BinaryOp\Mul::class,
        Expr\BinaryOp\Div::class, Expr\BinaryOp\Mod::class, Expr\BinaryOp\Pow::class,
        Expr\AssignOp\Plus::class, Expr\AssignOp\Minus::class, Expr\AssignOp\Mul::class,
        Expr\AssignOp\Div::class, Expr\AssignOp\Mod::class, Expr\AssignOp\Pow::class,
    ];
    // funciones cuyo primer argumento ya NO tolera null/no-array en PHP 8 (TypeError) -> candidatas
    const ARRAY_FUNCS = ['count', 'sizeof', 'implode', 'join', 'in_array', 'array_merge', 'array_map', 'array_filter', 'array_keys',
        'array_values', 'array_sum', 'array_unique', 'array_flip', 'array_slice', 'array_search', 'array_column', 'array_diff',
        'array_intersect', 'array_reverse', 'array_key_exists', 'array_combine', 'array_chunk', 'array_pluck', 'array_walk',
        'array_reduce', 'usort', 'uasort', 'uksort', 'sort', 'rsort', 'ksort', 'asort', 'arsort', 'end', 'reset', 'array_shift',
        'array_pop', 'array_push', 'array_unshift', 'array_splice', 'array_product', 'array_diff_key', 'array_intersect_key', 'array_fill_keys', 'array_count_values', 'array_rand', 'shuffle', 'max', 'min'];
    const REMOVIDAS = ['each', 'create_function', 'money_format', 'get_magic_quotes_gpc', 'get_magic_quotes_runtime', 'hebrevc', 'convert_cyr_string',
        'ezmlm_hash', 'restore_include_path', 'is_real', 'fgetss', 'image2wbmp', 'png2wbmp', 'jpeg2wbmp', 'ldap_sort', 'read_exif_data',
        'mbregex_encoding', 'mbereg', 'ereg', 'eregi', 'split', 'mysql_connect', 'curl_close_dummy', '__autoload'];
    const DEPRECADAS = ['strftime', 'gmstrftime', 'utf8_encode', 'utf8_decode', 'date_sunrise', 'date_sunset', 'strptime', 'mhash', 'odbc_result_all', 'mysqli_ping', 'key_exists_dummy'];

    private function p(Node $n, string $tipo, string $msg, string $sev = 'alta') {
        $this->out[] = ['tipo' => $tipo, 'sev' => $sev, 'file' => str_replace($GLOBALS['root'] . '/', '', $this->file), 'line' => $n->getStartLine(), 'msg' => $msg];
    }

    public function enterNode(Node $n) {
        if ($n instanceof Stmt\Class_ || $n instanceof Stmt\Trait_ || $n instanceof Stmt\Enum_) {
            $this->clase[] = $n->name && $n->namespacedName ? $n->namespacedName->toString() : null;
        }
        if ($n instanceof Stmt\ClassMethod) {
            $this->metodo[] = [$n->name->toString(), $n->isStatic()];
            $this->fvars[] = [];
        } elseif ($n instanceof Stmt\Function_) {
            $this->metodo[] = [$n->name->toString(), true];
            $this->fvars[] = [];
        }

        // $this dentro de método static
        if ($n instanceof Expr\Variable && $n->name === 'this' && $this->metodo) {
            [$m, $st] = end($this->metodo);
            if ($st && $this->clase) {
                $this->p($n, 'this-en-static', "Se usa \$this dentro del método static $m()");
            }
        }

        // llamadas estáticas
        if ($n instanceof Expr\StaticCall && $n->class instanceof Node\Name && $n->name instanceof Node\Identifier) {
            $cls = $n->class->toString();
            $mname = $n->name->toString();
            $lc = strtolower($cls);
            $actual = $this->clase ? end($this->clase) : null;
            $en_static = $this->metodo ? end($this->metodo)[1] : true;
            $enMetodo = $this->metodo ? end($this->metodo)[0] : '(fuera de método)';
            if (in_array($lc, ['self', 'static', 'parent'], true)) {
                if ($actual) {
                    $buscarEn = $lc === 'parent' ? ($this->indice[$actual]['parent'] ?? null) : $actual;
                    if ($buscarEn) {
                        [$ok, $st, $cs] = buscar_metodo($buscarEn, $mname, $this->indice);
                        if ($ok && $st === false && $en_static) {
                            $this->p($n, 'static-a-metodo-de-instancia', "$cls::$mname() es de instancia y se llama desde el método static $enMetodo()");
                        }
                    }
                }
            } else {
                [$ok, $st, $cs] = buscar_metodo($cls, $mname, $this->indice);
                if ($ok && $st === false) {
                    // legítimo si estamos en un método de instancia de una subclase (o la misma clase) de $cls
                    $legit = (!$en_static) && $actual && es_subclase($actual, $cls, $this->indice);
                    if (!$legit) {
                        $this->p($n, 'static-a-metodo-de-instancia', "$cls::$mname() es de instancia y se llama estático (desde $enMetodo())");
                    }
                }
            }
        }

        // number_format asignado a variable
        if ($n instanceof Expr\Assign && $n->var instanceof Expr\Variable && is_string($n->var->name) && $this->fvars) {
            $v = $n->expr;
            $es_fmt = ($v instanceof Expr\FuncCall && $v->name instanceof Node\Name && strtolower($v->name->toString()) === 'number_format')
                || ($v instanceof Expr\StaticCall && $v->class instanceof Node\Name && $v->name instanceof Node\Identifier
                    && substr($v->class->toString(), -7) === 'Numbers' && $v->name->toString() === 'price');
            $i = count($this->fvars) - 1;
            if ($es_fmt) $this->fvars[$i][$n->var->name] = $n->getStartLine();
            else unset($this->fvars[$i][$n->var->name]);
        }

        // aritmética con strings
        foreach (self::ARITH as $k) {
            if ($n instanceof $k) {
                $ops = $n instanceof Expr\AssignOp ? [$n->expr] : [$n->left, $n->right];
                foreach ($ops as $o) {
                    $this->revisar_operando($n, $o);
                }
                if ($n instanceof Expr\AssignOp) {
                    // $x += "...": x suele ser string
                }
                break;
            }
        }

        // funciones
        if ($n instanceof Expr\FuncCall && $n->name instanceof Node\Name) {
            $f = strtolower($n->name->getLast());
            if (in_array($f, self::REMOVIDAS, true)) {
                $this->p($n, 'funcion-removida', "$f() no existe en PHP 8");
            }
            if (in_array($f, self::DEPRECADAS, true)) {
                $this->p($n, 'deprecada', "$f() está deprecada (8.1/8.2); sigue andando", 'info');
            }
            if (($f === 'implode' || $f === 'join') && count($n->args) === 2 && !$n->args[0]->unpack) {
                $a0 = $n->args[0]->value; $a1 = $n->args[1]->value;
                if ($a0 instanceof Expr\Variable && ($a1 instanceof Node\Scalar\String_)) {
                    $this->p($n, 'implode-orden-viejo', "implode(\$var, '...') con el orden legado: TypeError en PHP 8", 'alta');
                }
            }
            if (in_array($f, self::ARRAY_FUNCS, true) && $n->args && !$n->args[0]->unpack) {
                $idx = ($f === 'implode' || $f === 'join') ? 1 : (in_array($f, ['in_array', 'array_search']) ? 1 : (in_array($f, ['array_map', 'array_walk']) ? 1 : (in_array($f, ['array_filter', 'array_reduce']) ? 0 : (($f === 'array_key_exists') ? 1 : 0))));
                if (isset($n->args[$idx]) && !$n->args[$idx]->unpack) {
                    $a = $n->args[$idx]->value;
                    $esReq = false;
                    $d = $a;
                    // $request->x, $request->input('x'), request('x'), request()->x, $data['x'] sin ??
                    if ($d instanceof Expr\PropertyFetch && $d->var instanceof Expr\Variable && $d->var->name === 'request') $esReq = true;
                    if ($d instanceof Expr\MethodCall && $d->var instanceof Expr\Variable && $d->var->name === 'request' && $d->name instanceof Node\Identifier
                        && in_array($d->name->toString(), ['input', 'get', 'query', 'post'], true) && count($d->args) < 2) $esReq = true;
                    if ($d instanceof Expr\FuncCall && $d->name instanceof Node\Name && strtolower($d->name->toString()) === 'request' && count($d->args) === 1) $esReq = true;
                    if ($esReq) {
                        $this->p($n, 'array-func-con-request-sin-default', "$f() con un dato del request sin default: si falta llega null y es TypeError en PHP 8", 'media');
                    }
                }
            }
        }

        return null;
    }

    private function revisar_operando(Node $op, Node $o) {
        if ($o instanceof Node\Scalar\String_) {
            if (!is_numeric($o->value)) {
                $this->p($op, 'aritmetica-con-string', "Aritmética con el literal no numérico '" . substr($o->value, 0, 30) . "': TypeError en PHP 8");
            }
        } elseif ($o instanceof Node\Scalar\InterpolatedString || $o instanceof Expr\BinaryOp\Concat) {
            $this->p($op, 'aritmetica-con-string', 'Aritmética con un string armado por concatenación/interpolación: TypeError si el resultado no es numérico', 'media');
        } elseif ($o instanceof Expr\FuncCall && $o->name instanceof Node\Name && strtolower($o->name->toString()) === 'number_format') {
            $this->p($op, 'aritmetica-con-number_format', 'Aritmética sobre number_format(): "1,234.50" no es numérico, TypeError en PHP 8', 'alta');
        } elseif ($o instanceof Expr\Variable && is_string($o->name) && $this->fvars && isset(end($this->fvars)[$o->name])) {
            $l = end($this->fvars)[$o->name];
            $this->p($op, 'aritmetica-con-number_format', "Aritmética sobre \${$o->name}, que vino de number_format()/Numbers::price() (línea $l): TypeError si pasa de mil", 'alta');
        } elseif ($o instanceof Expr\Array_ && !($op instanceof Expr\BinaryOp\Plus)) {
            $this->p($op, 'aritmetica-con-array', 'Aritmética con un array literal: TypeError', 'alta');
        }
    }

    public function leaveNode(Node $n) {
        if ($n instanceof Stmt\Class_ || $n instanceof Stmt\Trait_ || $n instanceof Stmt\Enum_) {
            array_pop($this->clase);
        }
        if ($n instanceof Stmt\ClassMethod || $n instanceof Stmt\Function_) {
            array_pop($this->metodo);
            array_pop($this->fvars);
        }
        return null;
    }
}

foreach ($ast as $f => $stmts) {
    $d = new Detector();
    $d->file = $f;
    $d->indice = &$indice;
    $d->out = &$hallazgos;
    $t = new NodeTraverser();
    $t->addVisitor($d);
    $t->traverse($stmts);
}

if ($como_json) {
    echo json_encode(['archivos' => count($files), 'clases' => count($indice), 'errores_parseo' => $errores_parseo, 'hallazgos' => $hallazgos], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
} else {
    echo "Archivos: " . count($files) . " | clases: " . count($indice) . "\n";
    foreach ($errores_parseo as $e) {
        echo "NO PARSEA: " . str_replace($root . '/', '', $e[0]) . " ({$e[1]})\n";
    }
    foreach ($hallazgos as $h) {
        echo "[{$h['sev']}] {$h['tipo']} {$h['file']}:{$h['line']} {$h['msg']}\n";
    }
    echo count($hallazgos) . " hallazgos\n";
}
exit(count(array_filter($hallazgos, function ($h) { return $h['sev'] === 'alta'; })) ? 1 : 0);
