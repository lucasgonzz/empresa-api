# Certificados de AFIP — instalación y ubicación

> Contexto (grupo 220, prompt 01): antes de este cambio, los certificados y la clave privada de AFIP
> vivían commiteados en `public/afip/` (document root de Laravel), lo que los hacía potencialmente
> descargables por HTTP y, además, quedaban en un repositorio público. Ahora viven fuera del docroot,
> en `storage/app/afip/`, y **no se commitean nunca** (ver `.gitignore`).

## 🔴 Los instala el admin — ya no es un paso manual (20/8/2026)

**Esto cambió.** Hasta el 20/8/2026 este documento decía que los certificados se copiaban a mano a
cada servidor. Ese paso manual no lo hacía nadie, y el resultado era que **todo cliente instalado
después del 26/7/2026 no podía facturar**: el ZIP de instalación sale del clon de git, donde los
certificados están gitignoreados, y el ZIP de actualización excluye `storage/` a propósito, así que
tampoco se reponían solos nunca.

Ahora los instala el admin, desde su propio servidor, en dos momentos:

- **Al instalar** un sistema (`InstallationService::step_finalize_api`). Además, la verificación de
  integridad de la instalación **los exige**: si faltan, la instalación se marca fallida en vez de
  entregarse sin facturación.
- **Al actualizar** un cliente (`DeploymentService::step_run_migrations`), después de arrastrar lo
  que el cliente ya tenía en la carpeta de la versión anterior. Solo repone lo que falte, nunca pisa
  un archivo existente.

La fuente es `admin-api`, y se cargan desde el panel: **Configuración fiscal → Certificados de
AFIP**. Es el mismo certificado de ComercioCity que el admin usa para facturar sus mensualidades.

Para reponer los clientes ya instalados sin esperar a que les toque una actualización, en `admin-api`:

```
php artisan afip:certificados-clientes            # solo informa quién está sin certificados
php artisan afip:certificados-clientes --instalar  # se los repone
```

## Dónde tienen que estar los archivos en el servidor del cliente

| Archivo | Ruta destino | Variable `.env` (opcional, si el nombre real difiere) |
|---|---|---|
| Certificado de producción (`.crt`) | `storage/app/afip/production/cert.crt` | `AFIP_CERT_PATH` |
| Clave privada de producción (`.key`) | `storage/app/afip/production/privada.key` | `AFIP_KEY_PATH` |
| Certificado de testing/homologación | `storage/app/afip/testing/afip_cert.pem` | `AFIP_CERT_PATH_TESTING` |
| Clave privada de testing/homologación | `storage/app/afip/testing/afip_private.key` | `AFIP_KEY_PATH_TESTING` |

Las rutas están definidas en `config/services.php` (bloque `afip`), con esos nombres de archivo como
default. **Esos cuatro nombres son el contrato con `admin-api`**: son exactamente los que instala
`AfipCertificateProvisionService`. Si acá se cambian, hay que cambiarlos también allá — el test
`AfipCertificateProvisionServiceTest::test_las_rutas_destino_son_las_que_espera_empresa_api` está
puesto justamente para que eso no pase en silencio.

Si en un servidor puntual el archivo real tiene otro nombre, no hace falta renombrarlo: basta con
setear la variable de entorno correspondiente apuntando al archivo real. El admin igual va a dejar
los suyos en la ruta default, sin pisar nada; el `.env` del cliente sigue mandando.

## Directorio de trabajo de WSAA

`storage/app/afip/wsaa/` (configurable con `AFIP_WSAA_PATH`) **se crea solo** la primera vez que se
factura (`AfipWSAAHelper::define()` hace `mkdir` si no existe). No hay que crearlo ni copiarle nada a
mano al provisionar un servidor nuevo — ahí se van a ir guardando el TRA y el TA (ticket de acceso) de
cada web service (`wsfe`, `wsfex`, `wsci`, etc.) a medida que se usan.

## Qué se queda en `public/afip/` a propósito (no mover, no tocar)

- `public/afip/wsdl/*.xml` — WSDL cacheados que cargan por `public_path()` los modelos `WSFE`, `WSFEX`,
  `WSSRPadronA13` y `WSMTXCA`. No son secretos, son archivos de definición de servicio públicos de AFIP.
- `public/afip/logo.jpg` — lo usan `AfipQrPdf` y `AfipPdfHelper` para el QR de los comprobantes fiscales.
- `public/afip/logo.png` — referenciado (comentado) en `AfipTicketPdf`.

## Si un servidor igual quedó sin certificados

Pasa si el admin todavía no los tenía cargados cuando se corrió la instalación. Se arregla cargándolos
en el panel del admin y corriendo `php artisan afip:certificados-clientes --instalar` desde `admin-api`.
Copiarlos a mano por SFTP a las rutas de la tabla también funciona: el admin no los pisa.

## Si una clave se filtra

Si un certificado o clave privada de AFIP quedó expuesto (por ejemplo, por haber estado commiteado en
este repositorio antes de este cambio), hay que **revocarlo en AFIP y generar uno nuevo** — sacarlo del
repositorio no invalida un secreto que ya se filtró, y el histórico de git sigue teniendo las versiones
viejas. Esa rotación la hace Lucas a mano; no es algo que resuelva este cambio de código.

Después de rotarlo, se sube el nuevo al panel del admin y se corre
`php artisan afip:certificados-clientes --instalar`. 🔴 Ojo: ese comando **no pisa** lo que el cliente
ya tiene, así que para reemplazar un certificado viejo en todos los clientes hay que borrar el anterior
en cada servidor primero. Es a propósito: el default no destructivo es lo que protege a un cliente con
certificado propio.
