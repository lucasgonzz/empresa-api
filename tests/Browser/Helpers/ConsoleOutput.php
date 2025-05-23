<?php

namespace Tests\Browser\Helpers;

class ConsoleOutput
{
    public static function ok($msg)
    {
        self::out("✔  {$msg}", "32"); // Verde
    }

    public static function error($msg)
    {
        self::out("✖  {$msg}", "31"); // Rojo
    }

    public static function warning($msg)
    {
        self::out("!  {$msg}", "33"); // Amarillo
    }

    public static function info($msg)
    {
        self::out("»  {$msg}", "36"); // Celeste
    }

    private static function out($msg, $color)
    {
        fwrite(STDOUT, "\033[{$color}m{$msg}\033[0m\n");
    }
}
