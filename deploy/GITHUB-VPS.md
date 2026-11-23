# Publicar la barbería desde GitHub en Hostinger

Estos pasos suponen un VPS Ubuntu 24.04 nuevo. Usa el mismo usuario del VPS durante la instalación y las actualizaciones. Los ejemplos usan la consola de administrador y la ruta `/opt/barberia`.

## 1. Publicar el código desde el computador

El repositorio es [pacificcodee-star/unabarberiamuychimbita](https://github.com/pacificcodee-star/unabarberiamuychimbita). En PowerShell, desde la carpeta del proyecto, comprueba la rama y que `origin` apunta a ese repositorio.

```powershell
git status --short --branch
git remote get-url origin
```

Revisa los archivos de la lista. En el editor selecciona los cambios de código, imágenes del sitio, migraciones y archivos de despliegue que quieras publicar, y haz un commit. Los archivos privados de configuración quedan en cada equipo. La rama que contiene el trabajo local en esta copia es `lanzamiento-sitio-publico`.

```powershell
git push -u origin lanzamiento-sitio-publico
```

Si Git rechaza el envío porque hay cambios remotos, conserva ambos historiales y revisa la diferencia antes de continuar.

## 2. Dar al VPS acceso de lectura

En Hostinger abre **VPS → Administrar → Consola web**. Ejecuta como administrador de Ubuntu. Si ya usas Docker en el servidor, comprueba `docker compose version` y conserva la instalación existente. Para Ubuntu 24.04 nuevo con los repositorios de Ubuntu habilitados:

```bash
sudo -i
apt-get update
apt-get install -y git curl openssl cron docker.io docker-compose-v2 docker-buildx
systemctl enable --now docker cron
docker compose version
install -d -m 700 /root/.ssh
ssh-keygen -t ed25519 -f /root/.ssh/barberia_github -C barberia-vps -N ''
cat /root/.ssh/barberia_github.pub
```

Genera la clave una sola vez. Copia la línea pública que empieza por `ssh-ed25519`. En tu repositorio, abre **Settings → Deploy keys → Add deploy key**, pega la línea y deja desmarcada **Allow write access**. [Claves de despliegue de GitHub](https://docs.github.com/en/authentication/connecting-to-github-with-ssh/managing-deploy-keys)

## 3. Hacer el primer despliegue

Habilita la entrada TCP por los puertos 80 y 443 en el firewall del VPS para que Caddy publique el sitio con HTTPS. En la consola del VPS, descarga la rama de producción:

```bash
git clone --branch lanzamiento-sitio-publico \
  --config 'core.sshCommand=ssh -i /root/.ssh/barberia_github -o IdentitiesOnly=yes' \
  git@github.com:pacificcodee-star/unabarberiamuychimbita.git /opt/barberia
cd /opt/barberia
bash deploy/actualizar-desde-github.sh
docker compose -f deploy/docker-compose.yml --env-file deploy/.env ps
```

La primera conexión SSH puede mostrar una huella. Compárala con las [huellas oficiales de GitHub](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/githubs-ssh-key-fingerprints). El despliegue genera `deploy/.env` con claves propias del VPS y muestra las direcciones HTTPS. Para un negocio que empieza de cero, crea el administrador siguiendo la sección 4 de [la guía de despliegue](README.md), abre **WhatsApp** en el panel y vincula el número con el QR.

Si ya atiendes clientes desde otro equipo, migra la base MySQL y los archivos de `storage/app` antes del cambio de sitio. Conserva la clave de aplicación original si hay datos cifrados. Coordina el cambio de WhatsApp para que una sola instancia procese los mensajes del negocio.

## 4. Activar las actualizaciones

En la misma consola del VPS, ejecuta una vez:

```bash
printf '%s\n' '*/2 * * * * root /bin/bash /opt/barberia/deploy/actualizar-desde-github.sh >> /var/log/barberia-deploy.log 2>&1' > /etc/cron.d/barberia-deploy
chmod 644 /etc/cron.d/barberia-deploy
```

Cada dos minutos el VPS comprueba la rama clonada. Cuando llega un commit nuevo, reconstruye la aplicación y ejecuta las migraciones pendientes. El sitio muestra la versión nueva cuando termina la compilación y arranca el contenedor. Los datos de MySQL, los archivos de clientes y la sesión de Evolution viven en volúmenes persistentes. Haz un respaldo antes de publicar migraciones que eliminen o transformen datos.

Puedes ver el avance y el último commit desplegado con estos comandos:

```bash
tail -n 40 /var/log/barberia-deploy.log
cat /opt/barberia/.git/barberia-deployed
```

Si falla la compilación o la comprobación HTTPS, el proceso registra el error y vuelve a intentarlo en la siguiente revisión. Para pausar las actualizaciones, mueve el archivo de cron a `/root/barberia-deploy.cron`.
