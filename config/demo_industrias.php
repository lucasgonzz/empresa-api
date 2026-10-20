<?php

// Datos propios de las demos; no se consultan proveedores externos durante un setup.
$archivo = __DIR__.'/../database/seeders/data/demo_industrias.json';
$perfiles = is_file($archivo) ? json_decode(file_get_contents($archivo), true) : [];

return is_array($perfiles) ? $perfiles : [];
