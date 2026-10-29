# Diagnóstico portátil de conexión

Herramienta de sólo lectura para el ticket 27 de Innovate. No cambia el ERP.
Compatible con Windows 10/11 y .NET Framework 4.5 o posterior (incluido habitualmente
en esos sistemas). Compilador C# 5 del framework, sin dependencias descargadas.

## Compilar y verificar

```powershell
& '.\build.ps1' -Salida 'C:\cc-worktrees\_cruzado\misiones\20261029-diagnostico-conexion-innovate\entrega'
```

```powershell
& 'C:\cc-worktrees\_cruzado\misiones\20261029-diagnostico-conexion-innovate\entrega\ComercioCity-Diagnostico.exe' --autoprueba
```

```powershell
& 'C:\cc-worktrees\_cruzado\misiones\20261029-diagnostico-conexion-innovate\entrega\ComercioCity-Diagnostico.exe' --diagnostico --sin-navegador --salida 'C:\cc-worktrees\_cruzado\misiones\20261029-diagnostico-conexion-innovate\ensayo'
```

Sin `--sin-navegador` abre una instancia Chromium existente con un perfil temporal
propio y CDP sólo en loopback, puerto efímero. No se conecta al navegador habitual
ni lee su perfil. No se instala un navegador si falta. Observa 16 segundos de
solicitudes públicas sin login; las solicitudes que quedan pendientes se informan
mediante la diferencia entre observadas, finalizadas y fallidas (recibir cabeceras no prueba descarga completa).

## Contrato y límites

`diagnostico.json`: esquema 1, inicio/fin UTC, conclusión, mediciones, hallazgos y
límites. `diagnostico.html`: informe local sin scripts ni recursos externos, con
tabla desplazable en pantallas angostas. Sólo exporta metadatos; elimina query,
fragmentos y todas las rutas dinámicas; sólo muestra host y categoría fija. No exporta direcciones privadas, MAC,
SSID, usuario, cookies, cabeceras, cuerpos, historial ni perfiles.

Se lee el modo de proxy WinHTTP sin guardar su dirección. Las pruebas respetan el proxy de usuario en HTTPS. El sondeo TCP/TLS directo se
omite si hay proxy para ese destino. Se usa TLS 1.2 explícitamente. Nunca se omite la validación de certificados.
Las referencias no prueban que la ruta específica hacia Innovate esté sana.
ICMP sin respuesta se marca orientativo: un firewall puede filtrarlo normalmente.
Los productos de seguridad se enumeran únicamente por nombre; su presencia no
demuestra interferencia. No se desactiva ninguna protección.

La UI ofrece cancelación y conserva resultados parciales. El inventario PAC/WMI se espera hasta 6 s; las consultas de proxy previas a TLS hasta 2,5 s. El proveedor lento puede terminar su lectura de fondo luego, sin bloquear el informe. Cada DNS tiene 5 s,
HTTPS tiene aborto total a 10 s, TCP y TLS hasta 5 s cada uno, ICMP 8 muestras por
destino con 700 ms; inicio de navegador 12 s y observación CDP limitada a 18 s; descubrimiento local 2,5 s y cierre hasta 3,5 s.
El navegador propio se cierra al terminar; el borrado sólo se intenta sobre su
directorio aleatorio con marcador propio, rechazo de reparse points y validación de ruta bajo el temporal
dedicado. Se confirma salida de proceso y ausencia del puerto en la tabla de listeners TCP de Windows (hasta 5 s). Si Windows lo mantiene bloqueado se conserva allí, sin empaquetarlo.

No concluye que la PC deba repararse. La decisión requiere comparar pruebas,
errores del perfil habitual y, cuando corresponda, otra computadora o conexión.
