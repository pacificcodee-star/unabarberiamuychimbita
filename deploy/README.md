# Despliegue 24/7 en VPS gratis (Google Cloud e2-micro)

> **Modo local (Docker Desktop):** si quieres probarlo todo en tu PC sin VPS, usa
> `deploy/local.sh` y luego `deploy/local-webhook.sh`. Panel en `http://localhost:8010`,
> Evolution directa en `http://localhost:8080`. Usa los puertos 8010/8080 para no chocar con
> `php artisan serve` (8000) ni con el MySQL local (3306). En local no hay HTTPS; el webhook se
> registra a mano con la URL interna `http://web:80/webhook/evolution`, porque el botón del panel
> genera `http://localhost:8010` y desde el contenedor de Evolution eso apunta a sí mismo.

Esta carpeta deja corriendo **los dos proyectos enlazados** en un solo servidor:

```
Internet
   │
   ├─ https://panel.IP.sslip.io ──► Caddy ──► PHP-FPM (Laravel 12)
   │                                            ├─ panel, web pública, reservas
   │                                            ├─ scheduler (barberia:tick cada minuto)
   │                                            └─ MySQL
   │
   └─ https://wa.IP.sslip.io ─────► Caddy ──► Evolution API v2.3.8
                                                ├─ PostgreSQL (datos)
                                                └─ Redis (caché / sesión WhatsApp)

Laravel ──HTTP (apikey)──► Evolution API     Evolution API ──webhook──► Laravel
```

El enlace ya está en el código: `WHATSAPP_DRIVER=evolution`, `EVOLUTION_URL` interna,
webhook `POST /webhook/evolution` validado con la misma `EVOLUTION_APIKEY`, bot automático,
bandeja de chat y avisos de citas/turnos.

> **No hace falta subir `evolution-api-main` al servidor.** El compose usa la imagen oficial
> `evoapicloud/evolution-api:v2.3.8`. Esa carpeta queda solo para desarrollo/referencia.

> **Aviso e2-micro:** el plan gratis tiene 1 GB de RAM. El stack está ajustado para eso
> (MySQL con buffer pequeño, Node limitado, Redis 96 MB) y **necesita 2 GB de swap** (paso 2).
> El primer build tarda 15–30 minutos. Si más adelante quieres holgura, Oracle Always Free
> da 24 GB por el mismo precio: el compose funciona igual, sin cambios.

---

## 1. Crea la VM gratis (10 minutos)

Google Cloud, plan **Always Free**: 1 × e2-micro 24/7, 30 GB de disco. Requiere cuenta de
facturación con tarjeta, pero dentro de estos límites el costo es **$0**.

1. Entra a <https://console.cloud.google.com> y activa la facturación.
2. **Compute Engine → VM instances → Create instance**
   - Región: **us-central1**, **us-west1** o **us-east1** (las únicas gratis)
   - Máquina: **e2-micro**
   - Disco: **30 GB estándar**
   - Imagen: **Ubuntu 24.04 LTS**
   - **IP externa: cambia Ephemeral por una IP estática** (VPC → IP addresses → Reserve).
     Es importante: si la IP cambia, el HTTPS y la URL del webhook dejan de funcionar.
3. Abre los puertos 80 y 443:
   **VPC network → Firewall → Create firewall rule**
   - Direction: Ingress · Ranges: `0.0.0.0/0` · Protocols: `tcp:80,tcp:443` · Targets: All instances
4. Conecta por **SSH** (botón "SSH" en la fila de la VM; abre una terminal en el navegador).

> Free tier incluye ~1 GB de salida de red al mes. Un negocio pequeño con texto de WhatsApp
> no llega, pero si empiezas a enviar imágenes/videos con frecuencia podrían cobrarte centavos.

## 2. Prepara el servidor (swap obligatorio)

En la terminal SSH de la VM:

```bash
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
sudo sysctl -w vm.swappiness=10
echo 'vm.swappiness=10' | sudo tee /etc/sysctl.d/99-swap.conf
```

Comprueba con `free -h` (debe aparecer Swap: 2.0Gi).

## 3. Sube el código

**Opción A — desde el navegador (más fácil)**

1. En tu PC, PowerShell:

   ```powershell
   cd C:\Users\Admin\Desktop
   tar -czf barberia.tgz --exclude=node_modules --exclude=vendor --exclude=.qa `
     --exclude=_respaldo_rediseno --exclude=.git --exclude=Unabarberiamuychimbita/.env `
     --exclude=deploy/.env Unabarberiamuychimbita
   ```

2. En la ventana SSH de Google Cloud: menú ⚙ (arriba a la derecha) → **Upload file** →
   sube `barberia.tgz` (queda en `~/`).

**Opción B — scp** (si prefieres terminal):

```powershell
scp barberia.tgz USUARIO@IP_DEL_VPS:/tmp/
```

En la VM, en ambos casos:

```bash
sudo mkdir -p /opt/barberia
sudo tar -xzf ~/barberia.tgz -C /opt/barberia 2>/dev/null || sudo tar -xzf /tmp/barberia.tgz -C /opt/barberia
sudo mv /opt/barberia/Unabarberiamuychimbita /opt/barberia/app
sudo chown -R "$USER":"$USER" /opt/barberia
```

`vendor/` y `node_modules/` no se suben: los instala Docker dentro de la imagen.

## 4. Instala Docker y despliega

```bash
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker "$USER"
newgrp docker

cd /opt/barberia/app/deploy
chmod +x deploy.sh
./deploy.sh
```

`deploy.sh`:

1. Detecta la IP pública y genera `.env` con secretos aleatorios
   (`APP_KEY`, MySQL, `EVOLUTION_APIKEY`, PostgreSQL).
2. Construye la imagen de Laravel (PHP 8.3 + Caddy + assets de Vite).
3. Levanta MySQL, PostgreSQL, Redis, Evolution API, web, scheduler y queue.
4. Espera a que el panel responda e imprime las URLs.

Sin dominio propio se usan dominios gratuitos de `sslip.io` con HTTPS automático de Caddy:
`https://panel.IP.sslip.io` y `https://wa.IP.sslip.io` (los imprime el script).

Con dominio propio: edita `deploy/.env` (`APP_HOST` y `WA_HOST`) y apunta los registros A a la IP.

## 5. Usuario administrador

Opción A — solo tu usuario (recomendado):

```bash
cd /opt/barberia/app/deploy
docker compose exec web php artisan tinker --execute="App\Models\User::updateOrCreate(['email' => 'TU@CORREO.com'], ['name' => 'Tu Nombre', 'password' => 'TU_CLAVE_SEGURA', 'role' => 'admin', 'active' => true]);"
```

Opción B — catálogo + datos de demostración (incluye `admin@barberia.com` / `password`):

```bash
docker compose exec web php artisan db:seed --force
```

Si usas la B, cambia esa contraseña al entrar y borra las citas/ventas de demo.

## 6. Enlazar WhatsApp

1. Abre `https://panel.IP.sslip.io` e inicia sesión como admin.
2. Menú **WhatsApp** → **Conectar / ver QR** (el cliente ya crea la instancia con
   `integration: WHATSAPP-BAILEYS`, obligatorio en Evolution v2).
3. Escanea con el teléfono del negocio: WhatsApp → **Dispositivos vinculados → Vincular dispositivo**.
4. Con el estado **Conectado**, pulsa **Conectar webhook automático**
   (registra `https://panel.IP.sslip.io/webhook/evolution` con la apikey correcta).
5. Prueba: escribe `hola` desde otro teléfono. El bot responde el menú y la conversación
   aparece en **WhatsApp → Bandeja de chat**.

## 7. Operación diaria

```bash
cd /opt/barberia/app/deploy

docker compose ps                 # estado
docker compose logs -f web        # logs del panel
docker compose logs -f evolution  # logs de WhatsApp

# Actualizar código (después de subir cambios):
docker compose up -d --build      # migraciones corren solas al arrancar web

# Reiniciar:
docker compose restart

# Respaldo de la base del panel:
docker compose exec db sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" barberia' > respaldo-$(date +%F).sql
```

Backup diario (crontab del host):

```cron
0 3 * * * cd /opt/barberia/app/deploy && docker compose exec -T db sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" barberia' | gzip > /opt/barberia/respaldo-$(date +\%F).sql.gz
```

Notificaciones push del panel (opcional):

```bash
docker compose exec web php artisan barberia:push-keys
# copia las claves a deploy/.env (VAPID_*) y: docker compose up -d
```

## 8. Problemas comunes

| Síntoma | Causa / solución |
|---|---|
| Caddy no emite certificado | Puertos 80/443 cerrados en el firewall de GCP. Prueba `curl -I http://IP`. |
| Error de "rate limit" de Let's Encrypt | `sslip.io` es compartido y a veces agota el límite. Crea un subdominio gratis en <https://www.duckdns.org> apuntando a tu IP y ponlo en `APP_HOST`/`WA_HOST` (`deploy/.env` + `docker compose up -d`). |
| El servidor se congela o muere el build | Falta swap (paso 2) o poca RAM. Revisa `free -h` y `dmesg | tail`. |
| La IP cambió y todo dejó de responder | Usaste IP efímera. Reserva una IP estática y actualiza `deploy/.env`. |
| Panel responde 500 | `docker compose logs web`; suele ser MySQL arrancando (reintenta) o `APP_KEY` vacía. |
| Webhook responde 401 | `EVOLUTION_APIKEY` distinta entre Laravel y Evolution; deben ser idénticas. |
| El QR no aparece | `docker compose logs evolution`; la instancia debe crearse con `integration: WHATSAPP-BAILEYS` (ya corregido). |
| WhatsApp se desconecta | El teléfono debe abrir WhatsApp al menos cada ~14 días, o re-escanear el QR. |
| Olvidé la apikey | `grep EVOLUTION_APIKEY deploy/.env` en el servidor. |

## 9. Seguridad

- `.env` queda con permisos 600 y no se sube al servidor desde el repo.
- Evolution API **no** publica el 8080: solo se llega por HTTPS a través de Caddy.
- Cambia la contraseña del admin y no reutilices la `EVOLUTION_APIKEY`.
- En GCP, restringe el puerto 22 a tu IP cuando termines de configurar (Firewall rules).

## 10. Render (contenedor único, sin VPS)

Todo en **un solo servicio Docker**: Laravel + Caddy + PHP-FPM + Evolution API + scheduler.
Un único servicio gratuito consume ~720 h/mes de las 750 h que da Render.

### Límites del plan gratuito (léelos antes)

| Límite | Consecuencia |
|---|---|
| Se duerme a los 15 min sin tráfico | Usa el workflow `.github/workflows/keepalive.yml` (ping cada 10 min). |
| Sin disco persistente | Cada redeploy borra la sesión de WhatsApp: hay que re-escanear el QR. Las fotos subidas también se pierden. |
| 512 MB de RAM | Suficiente pero justo: no subas `pm.max_children` ni `max-old-space-size`. |
| Postgres gratuito caduca a los ~30 días | Usa **Neon free** (no caduca) como base de datos. |
| Un solo servicio gratuito activo | No crees además un servicio aparte para Evolution. |

### Paso 1 — Base de datos en Neon (gratis, sin tarjeta)

1. <https://neon.tech> → crea cuenta → **New project** (región US East / Ohio).
2. Copia la cadena **Pooled connection**: `postgresql://usuario:clave@ep-xxx-pooler...neon.tech/neondb?sslmode=require`.
3. Esa misma cadena se usa para `DB_URL` **y** `DATABASE_CONNECTION_URI`.

### Paso 2 — Sube el código a GitHub

Desde la raíz del proyecto:

```bash
git init
git add .
git commit -m "Despliegue Render"
git branch -M main
git remote add origin https://github.com/TU-USUARIO/barberia-muychimbita.git
git push -u origin main
```

El repo puede ser privado; `.gitignore` ya excluye `vendor`, `node_modules`, `.env*` y las carpetas pesadas.

### Paso 3 — Crea el servicio en Render

1. <https://dashboard.render.com> → **New** → **Blueprint** → conecta el repo.
2. Render lee `render.yaml` (raíz del repo) y propone el servicio `barberia-muychimbita`, plan free, Dockerfile `deploy/Dockerfile.render`, health check `/login`. Aplica.
3. En **Environment** pega los secretos (están en `deploy/.env.render`, generados en tu máquina):

| Variable | Valor |
|---|---|
| `APP_KEY` | el de `deploy/.env.render` (o `php artisan key:generate --show`) |
| `EVOLUTION_APIKEY` | el de `deploy/.env.render` |
| `EVOLUTION_WEBHOOK_SECRET` | la **misma** clave anterior |
| `APP_URL` | `https://barberia-muychimbita.onrender.com` (la URL real que muestre Render) |
| `DB_URL` | cadena de Neon |
| `DATABASE_CONNECTION_URI` | la misma cadena de Neon |

`SERVER_URL`, `DATABASE_CONNECTION_URI` y `AUTHENTICATION_API_KEY` de Evolution se derivan solas en el arranque.
4. Si el nombre `barberia-muychimbita` está ocupado, Render asignará otro: usa **esa** URL en `APP_URL`.

> ¿Prefieres no usar Blueprint? **New → Web Service → Docker**, repo, Dockerfile `deploy/Dockerfile.render`, plan Free, health check `/login`, y agrega a mano las variables de `render.yaml`.

### Paso 4 — Crea el usuario administrador

Render → tu servicio → **Shell**:

```bash
php artisan tinker --execute="App\Models\User::updateOrCreate(['email'=>'tu@correo.com'],['name'=>'Admin','password'=>bcrypt('TuClaveSegura'),'role'=>'admin','active'=>true]);"
```

O carga los datos demo: `php artisan db:seed --force` (admin `admin@barberia.com` / `password`).

### Paso 5 — Conecta WhatsApp

1. Abre `https://TU-SERVICIO.onrender.com/evolution/manager` → pega la `EVOLUTION_APIKEY`.
2. **Instance** → nombre `barberia`, integración **WHATSAPP-BAILEYS** → **Create** → escanea el QR con el teléfono.
3. En el panel de la barbería (`/whatsapp`) el webhook ya queda apuntando a `https://TU-SERVICIO.onrender.com/webhook/evolution`. Si no, fíjalo a mano con `deploy/local-webhook.sh` cambiando la URL.

### Paso 6 — Evita que se duerma

GitHub → repo → **Settings → Secrets and variables → Actions → Variables** → nueva variable `APP_URL` con la URL del servicio. El workflow `keepalive` hará ping a `/up` cada 10 minutos.

### Problemas comunes en Render

| Síntoma | Causa / solución |
|---|---|
| El deploy se queda en "Deploying" mucho tiempo | La primera build compila extensiones PHP y descarga la imagen de Evolution; tarda 8–15 min. |
| 502 / "Application failed to respond" | Revisa **Logs**: casi siempre `DB_URL` inválida o Neon suspendido. |
| `no pg_hba.conf entry ... SSL off` | Falta `?sslmode=require` en `DB_URL`. |
| Se cae por memoria (OOM) | Confirma `NODE_OPTIONS=--max-old-space-size=192` y `pm.max_children=3`. |
| El QR no carga | `Logs` → programa `evolution`; si dice "Invalid integration", la instancia se creó sin `WHATSAPP-BAILEYS`. |
| Tras un redeploy WhatsApp queda desconectado | Esperado en plan free (sin disco). Re-escanea el QR. |

