# Certificados de AFIP — instalación y ubicación

> Contexto (grupo 220, prompt 01): antes de este cambio, los certificados y la clave privada de AFIP
> vivían commiteados en `public/afip/` (document root de Laravel), lo que los hacía potencialmente
> descargables por HTTP y, además, quedaban en un repositorio público. Ahora viven fuera del docroot,
> en `storage/app/afip/`, y **no se commitean nunca** (ver `.gitignore`).

## ⚠️ Aviso de deploy — este cambio rompe la facturación si no se hace antes del deploy

Este cambio **rompe la facturación AFIP** en cualquier servidor donde los certificados no hayan sido
copiados a la ruta nueva (`storage/app/afip/...`) **antes o junto con** el deploy de este código. El
código ya no busca los certificados en `public/afip/production` / `public/afip/testing`, así que si el
servidor no tiene los archivos en la ubicación nueva, `AfipWSAAHelper` va a lanzar una excepción
explícita en vez de facturar.

## Qué copiar a mano al provisionar un servidor (o antes de este deploy)

Estos archivos **nunca van al repositorio** — se copian a mano, por fuera de git, a cada servidor:

| Archivo | Ruta destino | Variable `.env` (opcional, si el nombre real difiere) |
|---|---|---|
| Certificado de producción (`.crt`) | `storage/app/afip/production/cert.crt` | `AFIP_CERT_PATH` |
| Clave privada de producción (`.key`) | `storage/app/afip/production/privada.key` | `AFIP_KEY_PATH` |
| Certificado de testing/homologación | `storage/app/afip/testing/afip_cert.pem` | `AFIP_CERT_PATH_TESTING` |
| Clave privada de testing/homologación | `storage/app/afip/testing/afip_private.key` | `AFIP_KEY_PATH_TESTING` |

Las rutas están definidas en `config/services.php` (bloque `afip`), con esos nombres de archivo como
default. Si en un servidor puntual el archivo real tiene otro nombre, no hace falta renombrarlo: basta
con setear la variable de entorno correspondiente apuntando al archivo real.

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

## Si una clave se filtra

Si un certificado o clave privada de AFIP quedó expuesto (por ejemplo, por haber estado commiteado en
este repositorio antes de este cambio), hay que **revocarlo en AFIP y generar uno nuevo** — sacarlo del
repositorio no invalida un secreto que ya se filtró, y el histórico de git sigue teniendo las versiones
viejas. Esa rotación la hace Lucas a mano; no es algo que resuelva este cambio de código.
