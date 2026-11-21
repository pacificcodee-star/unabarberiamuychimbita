# Bot de WhatsApp en este PC (Windows, sin Docker)

El sitio y el bot dependen de cuatro piezas que corren en este equipo:

| Pieza | Puerto | Qué pasa si se apaga |
|---|---|---|
| MySQL (XAMPP) | 3306 | El sitio y el webhook responden error 500; el bot no guarda ni contesta. |
| Laravel (`php artisan serve`) | 8000 | No hay sitio ni webhook. |
| Evolution API (`Desktop\evolution-api-main`) | 8080 | No se envían ni reciben mensajes de WhatsApp. |
| Túnel de Cloudflare | — | El sitio no se ve desde internet y los enlaces del bot llevan a una dirección vieja. |

Ninguna de las cuatro se enciende sola al reiniciar el PC: por eso «se caía» Evolution.

## Scripts

- **`iniciar-barberia.ps1`**: enciende las cuatro piezas en orden (no duplica lo que ya esté encendido).
- **`evolution-vigilante.ps1`**: arranca Evolution y la reinicia sola si se cierra o deja de responder
  2 minutos. Si el WhatsApp queda desconectado, pide reconexión.
- **`tunel-cloudflare.ps1`**: abre el túnel y registra el webhook en Evolution. En modo rápido,
  actualiza `APP_URL` y limpia la caché de Laravel cuando cambia la dirección. En modo permanente,
  usa la URL configurada. Si el túnel se cae, lo reabre.

Registros: `storage\logs\evolution-vigilante.log`, `tunel-cloudflare.log`, `iniciar-barberia.log`
y la salida de Evolution en `evolution-node.log`.

Para usar un túnel permanente, agrega `CLOUDFLARE_TUNNEL_CONFIG` al `.env` con la ruta completa
al archivo YAML del túnel y configura `APP_URL` con el hostname público HTTPS. El YAML debe
redirigir ese hostname a `http://127.0.0.1:8000`. Guarda el archivo de credenciales del túnel
fuera del repositorio. Los cambios se aplican al reiniciar `tunel-cloudflare.ps1`.

Para usar **ngrok Free**, vincula el agente de Windows a tu cuenta y agrega
`TUNNEL_PROVIDER=ngrok` al `.env`. Reinicia el supervisor del túnel para aplicar el cambio.
El agente anuncia el enlace fijo de la cuenta, que el supervisor guarda como `APP_URL`.
La salida del agente queda en `storage\logs\ngrok.log`.

## Encender todo a mano

```powershell
powershell -ExecutionPolicy Bypass -File deploy\windows\iniciar-barberia.ps1
```

## Encender todo al iniciar sesión en Windows

En PowerShell (una sola vez):

```powershell
$accion = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "C:\Users\Admin\Desktop\Unabarberiamuychimbita\deploy\windows\iniciar-barberia.ps1"'
$cuando = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME
Register-ScheduledTask -TaskName 'Barberia - iniciar sitio y WhatsApp' -Action $accion -Trigger $cuando -Description 'MySQL, Laravel, Evolution API y túnel de Cloudflare'
```

Para quitarlo: `Unregister-ScheduledTask -TaskName 'Barberia - iniciar sitio y WhatsApp'`.

Además, en Windows → Energía, evita que el PC se **suspenda** mientras el negocio está abierto:
con el equipo dormido no llegan mensajes.

## El webhook va directo, sin pasar por el túnel

En el `.env`:

```env
EVOLUTION_WEBHOOK_URL=http://127.0.0.1:8000/webhook/evolution
```

Evolution y Laravel están en el mismo PC, así que los mensajes entran por la dirección interna.
Aunque el túnel cambie o se caiga, el bot sigue recibiendo y contestando. Laravel exige en cada
evento la cabecera `apikey`; el panel y `tunel-cloudflare.ps1` la registran junto con el webhook.

## Vincular el número (QR)

Panel → **WhatsApp** → **Conectar / ver QR** y escanéalo desde el teléfono de la barbería
(WhatsApp → Dispositivos vinculados). Mientras no se escanee, el estado es «connecting».

## Ajustes de Evolution (Desktop\evolution-api-main\.env)

Al vincular el número, WhatsApp manda de golpe contactos y chats del teléfono. Guardarlos en la base
de Neon (en la nube) agotaba las conexiones (error P2024) y podía tumbar Evolution. Por eso:

```env
DATABASE_SAVE_DATA_CONTACTS=false   # el bot no los usa
DATABASE_SAVE_DATA_CHATS=false      # la bandeja del panel guarda su propia copia
DATABASE_CONNECTION_URI=...?sslmode=require&connection_limit=10&pool_timeout=30
```

Copia del archivo anterior: `.env.respaldo-2026-11-19`. Tras cambiar ese `.env`, reinicia Evolution
(el vigilante la vuelve a levantar sola); la sesión de WhatsApp se conserva y no pide QR.

Si el QR no vincula: pulsa una sola vez «Conectar / ver QR» y escanea el que se ve (se actualiza
solo cada 15 s). No hace falta pulsar varias veces ni desvincular nada.
