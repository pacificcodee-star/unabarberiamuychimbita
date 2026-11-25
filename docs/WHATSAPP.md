# WhatsApp con Evolution API

Una Barbería Muy Chimbita · Laravel 12 · Evolution API (alojada en Northflank)

Esta guía deja funcionando los cuatro canales de WhatsApp del negocio:

| Canal | Qué hace | Dónde vive |
|---|---|---|
| **Avisos automáticos** | Confirmación, recordatorio, "te estamos llamando", cambios y cancelaciones de citas y turnos. | `ClientNotifier` → `WhatsAppService` → Evolution API |
| **Bot automático** | Confirma y cancela citas/turnos, informa la fila y responde precios, horarios, ubicación y reservas. | `POST /webhook/evolution` → `EvolutionWebhookController` → `WhatsappBotService` |
| **Bandeja de chat** | El equipo ve las conversaciones y responde a mano desde el panel. | Menú **WhatsApp → Bandeja de chat** |
| **Panel de conexión** | Crea la instancia, muestra el QR, consulta el estado y da de alta el webhook. | Menú **WhatsApp** (solo administrador) |

Evolution API **reemplaza** a la Cloud API de Meta: se usa el WhatsApp normal del negocio
con QR, sin plantillas aprobadas, sin ventana de 24 h y sin cuota mensual.

---

## 1. Configuración (una sola vez)

### 1.1 En Northflank (tu servidor de Evolution API)

1. Abre tu proyecto en Northflank y entra al servicio de **Evolution API**.
2. Copia la **URL pública** del servicio (algo como `https://evolution-api-xxxx.northflank.app`).
3. En las variables de entorno del servicio busca `AUTHENTICATION_API_KEY` (es la API key global).
   Si no existe, créala con un valor largo y aleatorio y reinicia el servicio.
4. Verifica que responda: abre `https://tu-url/instance/fetchInstances` en el navegador con la
   cabecera `apikey: <tu-api-key>`; debe devolver `[]` y no `401`.

### 1.2 En tu sitio (`.env`)

```env
WHATSAPP_DRIVER=evolution
EVOLUTION_URL=https://tu-servicio-en-northflank...
EVOLUTION_APIKEY=la-misma-AUTHENTICATION_API_KEY
EVOLUTION_INSTANCE=barberia
EVOLUTION_WEBHOOK_SECRET=          # opcional: otro secreto distinto para el webhook
WHATSAPP_BOT_ENABLED=true
WHATSAPP_DEFAULT_COUNTRY=57
```

Luego:

```
php artisan config:clear
php artisan migrate        # crea whatsapp_conversations y whatsapp_messages
```

### 1.3 Conectar el número (QR)

1. Entra al panel → menú **WhatsApp**.
2. Pulsa **Conectar / ver QR** y escanea con el teléfono de la barbería
   (WhatsApp → Dispositivos vinculados → Vincular dispositivo).
3. Cuando el badge diga **Conectado**, ya está el número enlazado.

Si el QR caducó, basta con pulsar otra vez **Conectar / ver QR**: genera uno nuevo.
Para cambiar de número, cierra la sesión desde el teléfono (WhatsApp → Dispositivos vinculados →
tocar la sesión → Cerrar sesión) y luego pulsa **Conectar / ver QR** para escanear con el número nuevo.
(El botón «Desvincular número» se quitó del panel.)

### 1.4 Dar de alta el webhook

Pulsa **Conectar webhook automático** en el mismo panel. Eso le pide a Evolution API que envíe
los eventos a:

```
https://tu-dominio.com/webhook/evolution
```

- La URL se genera con `APP_URL`, así que en producción debe estar bien escrito en el `.env`.
- El webhook es `POST` sin sesión y sin CSRF: se valida la apikey que Evolution incluye en cada
  evento (campo `apikey` del cuerpo o cabecera). Se acepta si coincide con
  `EVOLUTION_WEBHOOK_SECRET` (secreto propio, opcional) o con `EVOLUTION_APIKEY`.
  Sin ninguno de los dos configurado **no se acepta nada** (401).
- Eventos dados de alta: `MESSAGES_UPSERT`, `MESSAGES_UPDATE`, `CONNECTION_UPDATE`,
  `QRCODE_UPDATED`, `LOGOUT_INSTANCE`, `REMOVE_INSTANCE`.

---

## 2. Cómo fluye un mensaje

```
Cliente escribe al negocio
        │
        ▼
Evolution API (Northflank) ──POST /webhook/evolution──► EvolutionWebhookController
        │                                                   │
        │                                     ┌─────────────┴─────────────┐
        │                                     ▼                           ▼
        │                          WhatsAppInbox (guarda)        WhatsappBotService (decide)
        │                                     │                           │
        │                                     ▼                           ▼
        │                          whatsapp_messages /       confirma · cancela · estado ·
        │                          whatsapp_conversations     precios · horarios · ubicación ·
        │                                                    reservar · menú
        │                                                                │
        ◄────────────── POST /message/sendText/{instancia} ◄─────────────┘
```

Con varios clientes escribiendo a la vez:

- El webhook contesta `200` de inmediato (`dispatchAfterResponse`): el bot trabaja después y
  Evolution no reintenta el evento ni duplica respuestas.
- Cada conversación se atiende de a un mensaje (`Cache::lock` por conversación); conversaciones
  distintas siguen en paralelo.
- La respuesta del bot sale **antes** que los avisos, y entre dos mensajes al mismo número se deja
  `EVOLUTION_SEND_GAP_MS` de espacio. Así no aparecen dos mensajes en el mismo segundo (lo que
  provoca el "Esperando el mensaje" en el teléfono del cliente).

En el otro sentido, los avisos salen solos cuando el equipo mueve la agenda:

- `barberia:tick` (cada minuto) → `ClientNotifier::processDue()` → `WhatsAppService::sendText()`
  → Evolution API. Mismo camino que seguía la Cloud API de Meta: **no hubo que tocar** los
  controladores ni las vistas de turnos y citas.

---

## 3. Comandos del bot

Cualquier cliente que escriba recibe respuesta. Se comparan sin importar mayúsculas ni acentos:

| Escribe el cliente | Qué hace el bot |
|---|---|
| `confirmar`, `confirmo`, `asisto` | Pone la cita en **Confirmada** (igual que el panel) y le avisa al cliente. Si tiene turno, le informa su posición. |
| `cancelar`, `anulo`, `no podré` | Cancela la cita futura o anula el turno en espera y **reorganiza la fila**. Avisa al equipo en la campana. |
| `estado`, `cómo voy`, `cuánto falta` | Posición en la fila, hora estimada de llamado y enlace de seguimiento. |
| `reservar`, `cita`, `turno` | Manda el enlace de reserva en línea. |
| `precios`, `cuánto cuesta` | Lista de servicios con precio (desde la base de datos). |
| `horarios`, `abren` | Horario de hoy y de la semana (desde Configuración del negocio). |
| `ubicación`, `dónde` | Dirección + enlace de Google Maps. |
| `teléfono`, `contacto` | Datos de contacto del negocio. |
| `hola`, `menú`, `ayuda` o cualquier otra cosa | Menú con todas las opciones. |

Reglas del bot:

- Solo actúa sobre **mensajes entrantes** de clientes (1 a 1). Ignora lo que manda el negocio
  (evita bucles) y los grupos de WhatsApp.
- Solo responde a mensajes recientes (menos de 5 min): un reintento del webhook no duplica nada.
- Cada mensaje se guarda una sola vez por su id (`evolution_id` es único).
- Se puede **pausar chat por chat** desde la bandeja cuando responde una persona del equipo.
- Se activa o desactiva con `WHATSAPP_BOT_ENABLED=true|false`.

---

## 4. La bandeja del panel

Menú **WhatsApp → Bandeja de chat**:

- Lista de conversaciones con nombre, último mensaje y contador de sin leer.
- Hilo de mensajes con refresco automático cada 5 s (no hay que recargar la página).
- El equipo escribe y envía: el mensaje queda guardado con su estado (enviado, entregado, leído).
- Botón **Pausar bot / Reanudar bot** por conversación.
- El menú lateral muestra el total de mensajes sin leer.

---

## 5. Desarrollo y pruebas

```
php artisan test --filter=WhatsAppBotTest
```

Las pruebas cubren: rechazo sin apikey, entrada en la bandeja, confirmación y cancelación de
citas por el bot, estado de turnos, precios/horarios, reservas, ignoro de grupos y mensajes
propios, no duplicados en reintentos y envío manual del equipo. Salen a internet cero peticiones:
todo queda simulado con `Http::fake`.

Para probar en local con tu teléfono, tu página debe ser visible para Northflank (HTTPS):
usa un túnel (`cloudflared tunnel --url http://127.0.0.1:8000`) y pon esa URL en `APP_URL`
antes de pulsar **Conectar webhook automático**.

---

## 6. Problemas frecuentes

| Síntoma | Causa probable | Solución |
|---|---|---|
| `401` en los logs del webhook | Falta o cambió la apikey | Revisa `EVOLUTION_APIKEY` y `EVOLUTION_WEBHOOK_SECRET`; pulsa **Conectar webhook automático** de nuevo. |
| El QR no aparece | `EVOLUTION_URL` mal escrita o Evolution caído | Abre `EVOLUTION_URL/instance/fetchInstances` con la cabecera `apikey`. |
| Entra el mensaje pero no responde el bot | `WHATSAPP_BOT_ENABLED=false` o bot pausado en ese chat | Actívalo en `.env` o en el botón del hilo. |
| Los avisos salen como "simulados" en el log | Falta URL o apikey | `php artisan config:clear` y revisa el `.env`. |
| Llega todo bien pero nada automático | Sin cron / scheduler | `* * * * * php artisan schedule:run` (ver `docs/NOTIFICACIONES.md`). |
| El webhook no recibe nada | `APP_URL` incorrecta en producción | Corrige `APP_URL` y vuelve a dar de alta el webhook. |
| El cliente ve "Esperando el mensaje. Esto puede tomar tiempo." | Salieron dos mensajes al mismo número en el mismo segundo, o el webhook tardó y Evolution repitió el evento | Deja `EVOLUTION_SEND_GAP_MS=1200` (espacio entre mensajes) y no pongas un `throttle` bajo en `/webhook/evolution`: la app debe contestar 200 enseguida. |

Todo lo que falla queda en `storage/logs/laravel.log` (buscar `Evolution API` o `WhatsApp`).

---

## 7. Volver a la Cloud API de Meta (opcional)

Si algún día prefieres la API oficial de Meta:

```env
WHATSAPP_DRIVER=cloud
WHATSAPP_CLOUD_TOKEN=...
WHATSAPP_PHONE_NUMBER_ID=...
```

Los avisos volverán a salir por Meta y se usarán las plantillas `WHATSAPP_TPL_*`.
La bandeja y el bot dejan de funcionar (son exclusivos de Evolution API).
