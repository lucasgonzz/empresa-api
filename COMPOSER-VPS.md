# Dos perfiles de dependencias

El perfil habitual sigue siendo `composer.json` + `composer.lock`. Conserva PHP 7.4 y OpenSpout 3 para los clientes que todavía lo necesitan. La presencia de los archivos VPS no cambia ese comportamiento.

El perfil **optativo** `composer.vps.json` + `composer.vps.lock` exige PHP `>=8.4.27 <8.5`, fija la plataforma de resolución en `8.4.27` y OpenSpout en `4.32.0`. Respecto del lock normal, sólo cambian OpenSpout, nette/schema (`1.4.0`) y nette/utils (`4.1.6`). El resto de los paquetes y las dependencias de desarrollo permanecen iguales a la base. El classmap agrega los cuatro archivos cuya capitalización necesita un tratamiento explícito en Linux.

## Selección explícita

El identificador es `vps-php84-v1`, en `extra.comerciocity.runtime-profile`. Para un frente que ya fue migrado, el admin conserva fuera del código `/home/api-<vps_path>/.comerciocity-runtime.json`, con contenido exacto `{"schema":1,"profile":"vps-php84-v1"}`. Ese marcador sólo admite el perfil conocido; no recibe una ruta arbitraria a un ejecutable.

Estar alojado en el VPS **no activa** PHP 8.4. Sin selección explícita se mantiene el circuito normal. El hosting compartido no consulta este marcador. Un tag elegido para un frente con perfil VPS debe contener ambos archivos del perfil y cumplir su contrato; si no los contiene, el admin debe detenerse antes de subir código. No se vuelve automáticamente a PHP 7.4 ni se borra el marcador para permitir un tag antiguo.

## Construcción e instalación

Cada perfil se arma en su propio staging: los dos usan `vendor/`, por lo que instalar el perfil VPS sobre una carpeta de trabajo PHP 7.4 reemplazaría sus dependencias. No se mezclan los dos vendor ni se instala el perfil VPS en shared por detectar el tipo de hosting.

Composer selecciona el perfil mediante la variable de proceso `COMPOSER=composer.vps.json`; a ese nombre le corresponde `composer.vps.lock`. El build utiliza el **binario PHP 8.4 explícito** para ejecutar Composer y `--no-scripts`. Antes de dar por válido el paquete, `check-platform-reqs --no-dev` verifica el PHP real, no sólo el `config.platform` emulado.

Los scripts del perfil son los mismos del manifiesto normal. En particular, `@php artisan package:discover --ansi` conserva `@php`: Composer utiliza su propio PHP_BINARY. No se reemplaza por un `php` genérico del PATH ni por PHP 7.4. La fase que ejecuta discovery en el frente también debe usar el binario 8.4 explícito; `--no-scripts` durante el build evita arrancar Laravel sobre un entorno todavía incompleto.

## Regeneración al cambiar la base

`tools/generar-perfil-vps.php` sólo prepara el JSON desde el manifiesto normal vigente y sus huellas. No edita `composer.json`, no modifica ningún lock, no consulta red y no ejecuta Composer ni artisan. **El lock siempre lo resuelve Composer**, nunca se compone copiando paquetes ni calculando su content-hash a mano.

En un staging limpio del tag o rama que se va a publicar:

1. Regenerar el JSON y usar una copia del lock normal actualizado como entrada de resolución.
2. Resolver únicamente los tres paquetes aprobados, con las versiones exactas, `--with-dependencies`, `--minimal-changes`, `--no-install` y `--no-scripts`. Si la nueva base no es compatible, detenerse y revisar el conflicto; no ampliar la actualización a todos los paquetes para que pase.
3. Verificar `ComposerVpsProfileTest`, `composer validate --strict --no-check-all --no-check-publish`, instalación simulada sin scripts y plataforma real en PHP 8.4. Revisar que sólo los tres paquetes permitidos difieran del lock normal. Repetir las pruebas de Excel/OpenSpout en PHP 7.4 y 8.4.
4. Commitear los dos archivos del perfil junto con los cambios de la base. Publicar o aplicar el perfil a un cliente requiere el procedimiento y la autorización de esa operación; regenerar estos archivos no migra a nadie.

Ejemplo de preparación local en PowerShell 5.1, **dentro del staging**, con rutas explícitas configuradas para esa máquina:

```powershell
$php84 = 'C:\ruta\php8.4\php.exe'
```

```powershell
$composerPhar = 'C:\ruta\composer.phar'
```

```powershell
& $php84 tools\generar-perfil-vps.php
```

```powershell
Copy-Item -LiteralPath composer.lock -Destination composer.vps.lock
```

```powershell
$env:COMPOSER = 'composer.vps.json'
```

```powershell
& $php84 $composerPhar update openspout/openspout:4.32.0 nette/schema:1.4.0 nette/utils:4.1.6 --with-dependencies --minimal-changes --no-install --no-scripts --no-interaction
```

```powershell
& $php84 $composerPhar validate --strict --no-check-all --no-check-publish --no-interaction
```

```powershell
& $php84 $composerPhar install --dry-run --no-dev --no-scripts --no-interaction
```

```powershell
Remove-Item Env:COMPOSER
```

## Huellas y controles

`extra.comerciocity.base-manifest-sha256` identifica el JSON normal **completo** y `base-lock-sha256` identifica el lock normal completo, incluidas sus dependencias de desarrollo. Cambiar requisitos, scripts, autoload, configuración o cualquier otra parte de la base exige regenerar y validar el perfil.

Las huellas usan SHA-256 del JSON canónico: decodificar los objetos como objetos, ordenar recursivamente sus propiedades con `SORT_STRING`, conservar el orden de los arrays y serializar sin indentación ni salto final, con `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`. Así no dependen de CRLF/LF ni del orden de propiedades del archivo. El content-hash de `composer.vps.lock` es independiente y lo mantiene Composer. La validación usa `--strict --no-check-all`: Composer considera un pin exacto una advertencia general, pero acá el pin es deliberado por el contrato de OpenSpout. Sólo se desactivan esos avisos de restricciones abiertas/exactas; se mantienen la validación de esquema y lock, y el test exige las versiones exactas aprobadas.

`tests/Unit/ComposerVpsProfileTest.php` comprueba el contrato sin arrancar Laravel ni acceder a una base. Verifica las únicas diferencias permitidas, los tres paquetes fijados, scripts iguales, huellas de la base y capitalización real de los cuatro archivos Linux. El preparador no acepta una plataforma o binario externo configurables: un perfil distinto requiere un contrato y validación nuevos.
