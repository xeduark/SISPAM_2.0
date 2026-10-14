# Despliegue en producción

Guía para montar SISPAM 2 en **https://consultasavia.comitedeestudiosmedicos.com.co**
y para actualizarlo después.

**Esta primera salida es solo para consultar pacientes en Savia:** las cuatro
cuentas compartidas de las sedes (rol `CONSULTA`) y el administrador. Lo demás
queda apagado con `SISPAM_MODULOS` (sección 2).

> **Supuestos.** VPS Linux (Ubuntu 24.04 o Debian 12) con acceso por SSH y
> `sudo`, Nginx, PHP 8.3 con PHP-FPM, MySQL 8 o MariaDB 10.11, sin proxy
> delante. Si hay Cloudflare o un balanceador, mira la sección 4.11. Con
> Debian 12 el PHP de la distribución es 8.2: sirve, cambia `8.3` por `8.2` en
> los nombres de paquetes y servicios.

## Índice

1. [Requisitos del servidor](#1-requisitos-del-servidor)
2. [Lo que cambia en producción](#2-lo-que-cambia-en-producción)
3. [Antes de empezar](#3-antes-de-empezar)
4. [Primer despliegue](#4-primer-despliegue)
5. [Actualizaciones](#5-actualizaciones) (con git o con un paquete)
6. [Savia: credenciales y prueba](#6-savia-credenciales-y-prueba)
7. [Cachés](#7-cachés)
8. [Tareas programadas y colas](#8-tareas-programadas-y-colas)
9. [Copias de seguridad](#9-copias-de-seguridad)
10. [Capacidad: cuántos procesos de PHP](#10-capacidad-cuántos-procesos-de-php)
11. [Lista de verificación](#11-lista-de-verificación)
12. [Prueba después de desplegar](#12-prueba-después-de-desplegar)
13. [Problemas comunes](#13-problemas-comunes)

---

## 1. Requisitos del servidor

| Qué | Mínimo | Nota |
|---|---|---|
| PHP | 8.2 (recomendado 8.3) | Con PHP-FPM |
| Extensiones de PHP | `mbstring`, `xml`/`dom`, `curl`, `intl`, `mysql` (pdo_mysql), `zip`, `gd`, `bcmath`, `fileinfo`, `openssl`, `tokenizer`, `ctype` | `intl` la exige Filament |
| Base de datos | MySQL 8 o MariaDB 10.6+ | `utf8mb4` |
| Servidor web | Nginx (o Apache) | **La raíz del sitio es `public/`**, nunca la carpeta del proyecto |
| Composer | 2.x | |
| Git | Cualquiera | Llave de despliegue de solo lectura |
| Cron | Sí | Para `schedule:run` |
| RAM | 2 GB (recomendado 4 GB) | Ver sección 10 |
| Salida a internet | 443 hacia Savia (`intersavia.saviasaludeps.com`) y hacia Authentik | El firewall tiene que dejar **salir** |

**Permisos:** el código es del usuario `sispam`, y PHP-FPM corre como ese mismo
usuario (sección 4.8). Así `storage/` y `bootstrap/cache/` se escriben sin
pelear con `www-data`. Nginx solo necesita leer `public/`.

## 2. Lo que cambia en producción

Todo esto ya está en el código; aquí se explica qué hace cada ajuste del
`.env.production.example`.

| Ajuste | Qué hace |
|---|---|
| `APP_ENV=production`, `APP_DEBUG=false` | Sin detalles de errores. Las páginas de error salen en español, con el estilo institucional, y nunca muestran el mensaje interno (`resources/views/errors/`). Un error dentro de Livewire sale como notificación en español. |
| `APP_URL=https://…` | Todas las URLs salen con https (`HttpsServiceProvider`), también la de regreso de Authentik. |
| `TRUSTED_PROXIES` | Vacío sin proxy. Con Cloudflare o un balanceador, sus IP (o `*`). |
| `SESSION_SECURE_COOKIE=true` | La cookie de sesión solo viaja por https. |
| `SESSION_ENCRYPT=true` | La sesión va cifrada en la base de datos. |
| `LOG_LEVEL=warning`, `LOG_STACK=daily`, `LOG_DAILY_DAYS=30` | Solo advertencias y errores, un archivo por día, 30 días. |
| `SISPAM_MODULOS=consultar_paciente,usuarios,sedes,auditoria` | **Lo demás no existe en esta instalación, ni para el administrador:** sale del menú y responde 403. La sala pública responde 404. Ver abajo. |
| `CACHE_STORE=database` | Tiene que admitir candados: el token de Savia se renueva con uno. |
| `SAVIA_VERIFY_SSL=true` | Se verifica el certificado de Savia. Solo se apaga si Savia lo pide por escrito. |
| `SAVIA_AUDITAR=false` | La tabla `auditorias` ya registra cada consulta. Así los números de documento no quedan además en el log. Las fallas de Savia sí quedan, como advertencia. |
| `SAVIA_TIMEOUT=10`, `SAVIA_CONNECT_TIMEOUT=5` | Ver sección 10. |
| `SAVIA_ENDPOINT`, `SAVIA_ENDPOINT_TOKEN` | Las direcciones de **producción** de Savia (la del token, por confirmar con ellos). Sin ellas se usaría el ambiente de pruebas; nunca vacías. Sección 6. |
| Gemini, Vision, inventario | Vacías: sus módulos están apagados. |

**Módulos apagados.** `SISPAM_MODULOS` es una lista blanca (claves de
`Rol::MODULOS`). Se revisa en un solo sitio, `User::puede()`, antes que la
excepción del administrador; por eso apaga a la vez el menú, las pantallas,
las acciones y los archivos (órdenes médicas, tickets, actas). Un módulo nuevo
nace apagado en producción hasta que alguien lo nombre. En la matriz de Roles
los apagados siguen visibles, marcados «apagado en esta instalación», para que
editar un rol no les borre los permisos. Para encender uno: agrégalo a la
lista, corre `php artisan optimize` y listo.

**Otras protecciones que ya están:**

- `public/robots.txt` no deja indexar nada.
- En `public/` no hay documentos: una prueba falla si aparece un PDF, una hoja
  de cálculo o un volcado de base de datos (`ConfiguracionDeProduccionTest`).
  Los modelos de referencia están en `docs/referencias/`.
- `DemoProcesoSeeder` se niega a correr en producción, aun con `--force`.
- Si Savia falla, quien consulta ve «Savia no respondió. Espera un momento y
  vuelve a intentarlo…»; el detalle técnico solo lo ve el administrador, y
  queda en el log.

## 3. Antes de empezar

Lo que no depende del código, y conviene tener listo antes de tocar el
servidor (el detalle está en la sección 11):

- [ ] El registro DNS del subdominio apunta a la IP pública del servidor.
- [ ] Savia autorizó la IP pública del servidor y entregó las direcciones y las
      credenciales de **producción**.
- [ ] En Authentik: el proveedor y la aplicación de SISPAM, el grupo
      `CONSULTA`, las 4 cuentas y `AdminSispam`.
- [ ] Hay una etiqueta del código para desplegar (por ejemplo `v1.0.0`).
- [ ] Hay un sitio **fuera del servidor** para las copias de seguridad.

## 4. Primer despliegue

Los comandos con `sudo` van como un usuario administrador del servidor; los
demás, como `sispam` (`sudo -iu sispam`).

### 4.1 Paquetes

```bash
sudo apt update
sudo apt install -y nginx mysql-server git unzip composer certbot python3-certbot-nginx \
    php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl \
    php8.3-intl php8.3-zip php8.3-gd php8.3-bcmath
php -v && composer --version
```

En `/etc/php/8.3/fpm/php.ini` pon `expose_php = Off`.

### 4.2 Usuario y carpeta

```bash
sudo adduser --disabled-password --gecos "" sispam
sudo mkdir -p /var/www/sispam
sudo chown sispam:sispam /var/www/sispam
```

### 4.3 Base de datos (nueva y vacía)

```bash
sudo mysql
```

```sql
CREATE DATABASE sispam CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'sispam'@'localhost' IDENTIFIED BY '<una clave larga>';
GRANT ALL PRIVILEGES ON sispam.* TO 'sispam'@'localhost';
FLUSH PRIVILEGES;
```

**Nunca copies tu base local:** tiene pacientes de prueba. Esta base nace vacía
y se llena con las migraciones y los seeders del paso 4.7.

Revisa que MySQL solo escuche en el servidor (`bind-address = 127.0.0.1`, que
es lo que trae por defecto).

### 4.4 Código

Una llave de despliegue de **solo lectura** para el repositorio:

```bash
sudo -iu sispam
ssh-keygen -t ed25519 -N "" -f ~/.ssh/id_ed25519 -C "sispam-produccion"
cat ~/.ssh/id_ed25519.pub
```

Agrega esa llave en GitHub, en el repositorio `xeduark/SISPAM_2.0` →
*Settings → Deploy keys*, **sin** permiso de escritura. Lo hace quien
administre el repositorio. Luego:

```bash
git clone git@github.com:xeduark/SISPAM_2.0.git /var/www/sispam
cd /var/www/sispam
git checkout --force tags/v1.0.0
```

### 4.5 El `.env`

```bash
cp .env.production.example .env
chmod 600 .env
nano .env
```

Llena lo que está vacío:

- `DB_PASSWORD`: la clave del paso 4.3.
- Las de `AUTHENTIK_*`: las del proveedor en Authentik, y en
  `AUTHENTIK_API_TOKEN` el token de la cuenta de servicio (sección 11).
- `SAVIA_USERNAME` y `SAVIA_PASSWORD`: las de producción que entregó Savia.
  Las direcciones (`SAVIA_ENDPOINT`, `SAVIA_ENDPOINT_TOKEN`) ya vienen. Cómo
  ponerlas y comprobarlas: sección 6.

Las claves solo se escriben aquí y en el gestor de contraseñas. Nunca en el
repositorio ni en un chat.

### 4.6 Dependencias, clave y permisos

```bash
composer install --no-dev --optimize-autoloader --no-interaction
php artisan key:generate --force
chmod -R u+rwX,go-rwx storage bootstrap/cache
```

**Guarda una copia del `.env` fuera del servidor** (en el gestor de
contraseñas). La `APP_KEY` cifra las sesiones y los datos cifrados de la base:
sin ella, una copia de la base no se puede leer completa.

### 4.7 Migraciones y datos iniciales

```bash
php artisan migrate --force
php artisan db:seed --class=SedeSeeder --force
php artisan db:seed --class=AdminUserSeeder --force
php artisan db:seed --class=CuentasDeConsultaSeeder --force   # incluye RolSeeder
```

- **Nunca `php artisan db:seed` a secas:** `DatabaseSeeder` incluye
  `ColaSeeder`, que crea colas y ventanillas de un módulo que está apagado.
  `ColaSeeder` se corre el día que salgan tickets y turnos.
- **Nunca `DemoProcesoSeeder`:** crea pacientes inventados. Igual se niega a
  correr en producción.

Quedan las sedes (LA 30, PREMIUM PLAZA, EDIFICIO BIC, AVENTURA y la
administrativa), los roles (ORIENTADOR, DISPENSADOR, CONSULTA), `AdminSispam`
como administrador y las cuentas `ConsultaPRP`, `ConsultaBIC`, `ConsultaL30` y
`ConsultaAVT`.

### 4.8 PHP-FPM: un grupo propio

Un grupo de procesos para SISPAM, que corre como el usuario `sispam`. Crea
`/etc/php/8.3/fpm/pool.d/sispam.conf`:

```ini
[sispam]
user = sispam
group = sispam
listen = /run/php/sispam.sock
listen.owner = www-data
listen.group = www-data

; Ver la sección 10 para escoger el número según la RAM.
pm = dynamic
pm.max_children = 30
pm.start_servers = 5
pm.min_spare_servers = 5
pm.max_spare_servers = 10
pm.max_requests = 500

; Una consulta lenta a Savia tarda como mucho unos 22 s (sección 10).
request_terminate_timeout = 60s
php_admin_value[max_execution_time] = 60
php_admin_value[memory_limit] = 256M
php_admin_value[upload_max_filesize] = 20M
php_admin_value[post_max_size] = 20M
```

```bash
sudo systemctl reload php8.3-fpm
```

Para que el script de actualización pueda recargar PHP-FPM sin pedir clave:

```bash
echo 'sispam ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.3-fpm' | sudo tee /etc/sudoers.d/sispam
sudo chmod 440 /etc/sudoers.d/sispam
```

### 4.9 Nginx

`/etc/nginx/sites-available/sispam`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name consultasavia.comitedeestudiosmedicos.com.co;

    # La raíz es public/: el .env, storage/ y el código quedan fuera de la web.
    root /var/www/sispam/public;
    index index.php;
    charset utf-8;

    client_max_body_size 20m;
    server_tokens off;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/sispam.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 60s;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/sispam /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

### 4.10 HTTPS y redirección de http a https

Con el DNS ya apuntando al servidor:

```bash
sudo certbot --nginx -d consultasavia.comitedeestudiosmedicos.com.co --redirect \
    -m <correo de quien administra> --agree-tos
sudo certbot renew --dry-run
```

`--redirect` deja la redirección de http a https. Certbot renueva solo el
certificado (temporizador de systemd); el `--dry-run` comprueba que la
renovación va a funcionar.

Cuando todo funcione por https, agrega HSTS dentro del bloque `listen 443` que
creó certbot, y recarga Nginx:

```nginx
add_header Strict-Transport-Security "max-age=31536000" always;
```

### 4.11 Si hay un proxy delante (Cloudflare, balanceador)

- En el `.env`: `TRUSTED_PROXIES` con las IP del proxy, o `*` si no son fijas
  (Cloudflare). Sin esto, Laravel no le cree el `X-Forwarded-Proto` y la
  auditoría guarda la IP del proxy en vez de la de la persona.
- Con Cloudflare: el modo SSL en **Full (strict)**, nunca *Flexible*. Con
  Flexible, Cloudflare le habla al servidor por http y la redirección a https
  entra en un ciclo infinito.
- Luego: `php artisan optimize`.

### 4.12 Cachés, cron y firewall

```bash
php artisan optimize
```

Cron del usuario `sispam` (`crontab -e`):

```cron
* * * * * cd /var/www/sispam && php artisan schedule:run >> /dev/null 2>&1
30 1 * * * /var/www/sispam/scripts/respaldar-base.sh >> /home/sispam/respaldos.log 2>&1
```

Firewall:

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
```

El 3306 queda cerrado: MySQL solo se usa desde el mismo servidor.

### 4.13 Comprobar

```bash
php artisan about
```

Tiene que decir `Environment: production`, `Debug Mode: OFF`,
`URL: consultasavia.comitedeestudiosmedicos.com.co` y los cachés de
configuración, rutas y vistas en `CACHED`.

Luego comprueba Savia (sección 6):

```bash
php artisan savia:probar
```

Sigue con la sección 12.

## 5. Actualizaciones

**Antes de actualizar, una copia de la base:** `scripts/respaldar-base.sh`.
Las sesiones viven en la base, así que una actualización no saca a nadie; la
que estaba consultando ve la página de mantenimiento por unos segundos.

### 5.1 Con git

Siempre a una **etiqueta** del repositorio, nunca a una rama:

```bash
sudo -iu sispam
cd /var/www/sispam
scripts/desplegar.sh v1.0.1
```

`scripts/desplegar.sh` hace, en orden:

1. Trae las etiquetas y comprueba que exista la pedida.
2. `php artisan down`: quien entre ve «SISPAM está en mantenimiento».
3. `git checkout` de la etiqueta.
4. `composer install --no-dev --optimize-autoloader`.
5. `php artisan migrate --force`.
6. `php artisan optimize:clear` y `php artisan optimize`.
7. Recarga PHP-FPM, para que OPcache suelte el código viejo.
8. `php artisan up`.

Si un paso falla, SISPAM **se queda en mantenimiento** y el script dice cómo
volver a la versión anterior. No queda a medio actualizar atendiendo gente.

### 5.2 Sin git: con un paquete

Cuando el servidor no puede descargar del repositorio. El paquete se arma en
un equipo que sí tiene el repositorio, **a partir de la etiqueta**, así que
lleva exactamente esa versión y nada que no esté en git (ni el `.env`, ni
`vendor/`, ni `storage/`).

**En tu equipo:**

```bash
git fetch --tags
git archive --format=tar.gz --prefix=sispam-v1.0.1/ -o sispam-v1.0.1.tar.gz v1.0.1
scp sispam-v1.0.1.tar.gz sispam@<servidor>:/home/sispam/
```

**En el servidor**, como `sispam`. La versión nueva se arma en una carpeta
aparte y se cambia al final: si se descomprimiera encima de la que está
corriendo, los archivos que la versión nueva ya no trae se quedarían ahí.

```bash
/var/www/sispam/scripts/respaldar-base.sh
cd /var/www

# 1. Armar la versión nueva al lado.
mkdir sispam-v1.0.1
tar -xzf ~/sispam-v1.0.1.tar.gz -C sispam-v1.0.1 --strip-components=1
cp -p sispam/.env sispam-v1.0.1/.env

# 2. Mantenimiento, y llevarse storage/ (logs y archivos) a la nueva.
(cd sispam && php artisan down --render="errors::503" --retry=60)
rm -rf sispam-v1.0.1/storage
cp -a sispam/storage sispam-v1.0.1/storage

# 3. Dependencias, base y cachés, desde la carpeta nueva.
cd sispam-v1.0.1
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan optimize:clear
php artisan optimize
cd ..

# 4. Cambiar de carpeta, recargar PHP y salir de mantenimiento.
mv sispam sispam-anterior
mv sispam-v1.0.1 sispam
sudo systemctl reload php8.3-fpm
(cd sispam && php artisan up)
```

`composer install` necesita salida a internet desde el servidor. Si no hay,
corre ese paso en tu equipo sobre la carpeta descomprimida, con
`--ignore-platform-req=ext-*` solo si tu PHP local no tiene alguna extensión, y
empaca `vendor/` junto con lo demás.

**Para volver atrás:** `php artisan down` en `sispam`, luego
`mv sispam sispam-fallida && mv sispam-anterior sispam`, recarga PHP-FPM y
`php artisan up`. Si la versión nueva traía migraciones, revísalas antes:
`php artisan migrate:status` dice cuáles corrieron.

Cuando la versión nueva lleve unos días funcionando, borra `sispam-anterior`.

## 6. Savia: credenciales y prueba

### 6.1 Poner las credenciales

Las credenciales de Savia **solo** van en el `.env` del servidor y en el gestor
de contraseñas. Nunca en el repositorio, en un correo ni en un chat: si
alguna vez pasaron por uno, pide a Savia que las cambie.

```bash
sudo -iu sispam
cd /var/www/sispam
nano .env
```

Escribe `SAVIA_USERNAME` y `SAVIA_PASSWORD`, y revisa que estén las
direcciones de producción:

```dotenv
SAVIA_ENDPOINT=https://intersavia.saviasaludeps.com/intersavia-webservices/rest/afiliado/consultar-afiliado
SAVIA_ENDPOINT_TOKEN=https://intersavia.saviasaludeps.com/intersavia-webservices/rest/token/generacion
```

> La del token se dedujo de la de pruebas: **confírmala con Savia.** Si
> `savia:probar` responde 404 al pedir el token, es esa.

Guarda (`Ctrl+O`, `Enter`, `Ctrl+X`) y deja el archivo solo para `sispam`:

```bash
chmod 600 .env
```

### 6.2 Refrescar las cachés

En producción la configuración está en caché: **Laravel no vuelve a leer el
`.env` hasta que se refresque.** Después de cualquier cambio en el `.env`:

```bash
php artisan optimize
```

`optimize` vuelve a leer el `.env` y guarda la configuración nueva. Las
sesiones no se tocan: nadie tiene que volver a entrar.

### 6.3 Probar con `savia:probar`

```bash
php artisan savia:probar
```

Dice qué ambiente y qué direcciones está usando, si hay credenciales (nunca
cuáles), y **pide un token nuevo**: si Savia lo entrega, las credenciales y
la conexión funcionan. El token queda en uso, como cuando lo renueva una
consulta, así que no estorba a quien esté consultando.

Para probar también una consulta, con un documento:

```bash
php artisan savia:probar 1000873458
php artisan savia:probar 1000873458 --tipo=TI
```

Dice solo si lo encontró, el código de respuesta de Savia y el tiempo.
**Nunca muestra datos del afiliado.** Esa consulta queda en la auditoría, a
nombre de «Sistema», como cualquier otra.

Si algo falla, dice por qué y qué revisar:

| Lo que dice | Qué revisar |
|---|---|
| `Ambiente: PRUEBAS` | Faltan `SAVIA_ENDPOINT` y `SAVIA_ENDPOINT_TOKEN` en el `.env`, o falta `php artisan optimize`. |
| `Faltan SAVIA_USERNAME y SAVIA_PASSWORD` | No están en el `.env`, o falta `php artisan optimize`. |
| HTTP 401 al pedir el token | Credenciales que no son las de producción. |
| HTTP 404 al pedir el token | La dirección del token no es esa: confírmala con Savia. |
| *timed out*, *failed to connect* | El servidor no llega a Savia: firewall de salida, o Savia no ha autorizado la IP pública (`curl -4 ifconfig.me`). |
| Certificado | El certificado de Savia no se pudo verificar: confirma con ellos la dirección. |
| En pausa hasta las … | Hubo varias fallas seguidas; espera esa hora y vuelve a probar. |

El comando sale con código 0 si todo funcionó y 1 si no, por si se quiere
usar en una revisión automática.

## 7. Cachés

| Comando | Qué hace |
|---|---|
| `php artisan optimize` | Guarda en caché la configuración, las rutas, las vistas, los eventos, los íconos y los componentes de Filament. |
| `php artisan optimize:clear` | Borra todo eso, **y además la caché de la aplicación** (el token de Savia, que se vuelve a pedir solo). |
| `php artisan config:clear` | Solo la configuración. |

**Después de cambiar el `.env` hay que correr `php artisan optimize`**: con la
configuración en caché, Laravel no vuelve a leer el `.env`.

## 8. Tareas programadas y colas

- **Cron (`schedule:run`)**: se instala en el paso 4.12. Hoy la única tarea es
  el cierre del día (`tickets:cerrar-dia`, 2:00 a. m.), y **no corre mientras
  los tickets estén apagados**. Se deja instalado para que funcione el día que
  salgan.
- **Worker de colas: no hace falta todavía.** Solo despachan trabajos la
  transcripción de fórmulas y el descuento de inventario, que están apagados.
  Cuando salgan, este servicio de systemd
  (`/etc/systemd/system/sispam-colas.service`):

  ```ini
  [Unit]
  Description=SISPAM: cola de trabajos
  After=network.target mysql.service

  [Service]
  User=sispam
  WorkingDirectory=/var/www/sispam
  ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
  Restart=always

  [Install]
  WantedBy=multi-user.target
  ```

  Y en `scripts/desplegar.sh`, después de `optimize`: `php artisan queue:restart`.

## 9. Copias de seguridad

En la base vive **la auditoría**: quién consultó qué documento y cuándo. Es el
rastro que exige la protección de datos de salud, y no está en ningún otro
sitio.

`scripts/respaldar-base.sh` hace un `mysqldump --single-transaction` (copia
consistente sin bloquear a nadie), lo comprime, comprueba el archivo y borra
los de más de 30 días. Las copias quedan en `/var/backups/sispam`, legibles
solo por `sispam`.

1. Las credenciales van en `/home/sispam/.my.cnf`, nunca en el script:

   ```ini
   [client]
   user=sispam
   password=<la clave de la base>
   ```

   ```bash
   chmod 600 ~/.my.cnf
   sudo mkdir -p /var/backups/sispam && sudo chown sispam:sispam /var/backups/sispam
   scripts/respaldar-base.sh   # la primera, a mano
   ```

2. El cron del paso 4.12 la corre todos los días a la 1:30 a. m.
3. **Sácalas del servidor.** Una copia en el mismo disco no sirve si el
   servidor se pierde. Por ejemplo, con `rclone` o `scp` a otro servidor o a un
   almacenamiento de la empresa, todos los días después de la copia. Las copias
   tienen datos de pacientes: el destino tiene que ser privado y, si es en la
   nube, cifrado.
4. **Prueba restaurar** una vez al mes, en una base aparte:

   ```bash
   mysql -e "CREATE DATABASE sispam_prueba_restauracion"
   gunzip -c /var/backups/sispam/sispam-AAAAMMDD-HHMMSS.sql.gz | mysql sispam_prueba_restauracion
   mysql -e "SELECT COUNT(*) FROM sispam_prueba_restauracion.auditorias"
   mysql -e "DROP DATABASE sispam_prueba_restauracion"
   ```

5. Guarda también el `.env` (por su `APP_KEY`) en el gestor de contraseñas.

## 10. Capacidad: cuántos procesos de PHP

**Cada consulta a Savia ocupa un proceso de PHP-FPM mientras espera.** En las
pruebas de octubre de 2026 Savia respondió en 0,5 s de mediana y 1 s la más
lenta, así que lo normal es tener 2 o 3 procesos ocupados con las 4 sedes
consultando.

El riesgo es **Savia lenta**: cada persona que consulta retiene un proceso
hasta el tiempo límite. Si se acaban los procesos, **la página se congela
para todos**, también para quien no está consultando. Ubuntu trae
`pm.max_children = 5`: con ese valor, 5 consultas lentas bastarían.

Lo que ya hace el código para que eso no pase:

- **Tiempo límite de 10 s por petición** y 5 s para conectar
  (`SAVIA_TIMEOUT`, `SAVIA_CONNECT_TIMEOUT`). Es diez veces la consulta más
  lenta que hemos visto.
- **Un solo token a la vez:** cuando vence, una sola consulta lo renueva
  (candado en la caché) y las demás esperan ese, en vez de pedir cada una el
  suyo. Si Savia anula el token anterior al emitir uno nuevo, las renovaciones
  simultáneas se lo anularían unas a otras. `ConsultasSimultaneasTest` lo
  prueba con 8 procesos de verdad.
- **Pausa cuando Savia está caída:** tras 3 fallas de red seguidas, durante
  30 s las consultas responden de inmediato «Savia no respondió», sin ocupar
  un proceso. Pasada la pausa, la primera consulta prueba; si vuelve a fallar,
  se pausa otra vez.

El peor caso de una consulta queda en unos 22 s (esperar el token 12 s y
consultar 10 s); por eso `max_execution_time`, `request_terminate_timeout` y
`fastcgi_read_timeout` van en 60 s.

**Cuántos procesos.** Suponiendo hasta 20 personas consultando a la vez (4
sedes × 5), más margen para el administrador y las páginas:

| RAM del servidor | `pm.max_children` |
|---|---|
| 2 GB | 15 (la pausa por caída se vuelve indispensable) |
| 4 GB | 30 |
| 8 GB | 40 (más no hace falta para 4 sedes) |

La regla general: `(RAM total − MySQL − 500 MB del sistema) ÷ memoria por proceso`.
Cada proceso de Laravel con Filament usa entre 40 y 60 MB. Para medir lo que de
verdad usan en tu servidor, con SISPAM en uso:

```bash
ps --no-headers -o rss -C php-fpm8.3 | awk '{s+=$1; n++} END {printf "%d procesos, %.0f MB en promedio\n", n, s/n/1024}'
```

**Lo que no bloquea:** las sesiones de Laravel en base de datos no se bloquean
entre peticiones. Una consulta lenta no congela las otras pestañas de la misma
persona ni a nadie más.

## 11. Lista de verificación

Lo que no depende del código. Márcalo antes de anunciar la salida.

### Authentik

- [ ] En el proveedor OAuth2/OpenID de SISPAM, la dirección de regreso exacta:
      `https://consultasavia.comitedeestudiosmedicos.com.co/auth/authentik/callback`.
- [ ] Tipo de cliente *Confidential*; el *Client ID* y el *Client Secret* van
      en `AUTHENTIK_CLIENT_ID` y `AUTHENTIK_CLIENT_SECRET`.
- [ ] La cuenta de servicio con la que SISPAM crea las cuentas de quien se
      registra en Usuarios. Con permisos justos:
      1. **Directory → Users → Create Service account** `sispam-servicio`,
         con *Create group* encendido: crea el grupo `sispam-servicio` con
         ella adentro.
      2. **Directory → Roles → Create** `SISPAM aprovisionamiento`, con estos
         permisos globales y nada más: de *User*, «Can view», «Can add»,
         «Can change» y «Reset Password»; de *Group*, «Can view» y «Can add».
      3. **Directory → Groups →** `sispam-servicio` **→ Edit**: en *Roles*,
         `SISPAM aprovisionamiento`.
      4. **Directory → Tokens and App passwords → Create**: usuario
         `sispam-servicio`, *Intent* **API** y *Expiring* **apagado** (si
         queda encendido, vence a los 30 minutos). Su *Key* va en
         `AUTHENTIK_API_TOKEN`.
- [ ] Ese token se trata como una clave de administrador de Authentik: con
      «Can add User», en Authentik 2024.8 se puede crear un usuario en
      cualquier grupo. Solo en el `.env` del servidor y en el gestor de
      contraseñas; si se filtra, se borra en *Tokens and App passwords*.
- [ ] El slug de la aplicación va en `AUTHENTIK_APP_SLUG`: el cierre de sesión
      usa `…/application/o/<slug>/end-session/`.
- [ ] Scopes `openid`, `email` y `profile`, y que el token traiga el claim
      `groups`: de ahí salen los roles.
- [ ] El *invalidation flow* del proveedor incluye el stage *User Logout*.
      Si no, cerrar sesión en SISPAM deja Authentik abierto en ese equipo, y el
      siguiente entra con un clic.
- [ ] Las cuentas `ConsultaPRP`, `ConsultaBIC`, `ConsultaL30` y `ConsultaAVT`:
      tipo *Internal* y cada una con su clave. Una clave distinta por sede.
      **El grupo `CONSULTA` ya no hace falta para entrar**: en SISPAM una
      cuenta compartida tiene siempre ese rol, y solo ese, venga o no el
      grupo. SISPAM se lo asigna allá al guardarla en Usuarios. Si alguien la
      mete en otro grupo, no gana permisos.
- [ ] El usuario `AdminSispam`, con su clave. No necesita grupo: es
      administrador en SISPAM.
- [ ] En el stage *User Login*, «Terminate other sessions» apagado, si tu
      versión lo trae. Si no, cada ingreso saca a las demás personas de la sede.
- [ ] Que el flujo no exija MFA a las cuentas compartidas, y que la política
      de bloqueo por intentos fallidos no deje a toda una sede por fuera.
- [ ] Si la aplicación limita el acceso por grupo, que incluya `CONSULTA`.

### Savia

- [ ] Autorizó la **IP pública del servidor** (`curl -4 ifconfig.me` en el
      servidor para verla).
- [ ] Entregó las credenciales de **producción**; van en `SAVIA_USERNAME` y
      `SAVIA_PASSWORD` (sección 6).
- [ ] **Confirmó la dirección del token**:
      `https://intersavia.saviasaludeps.com/intersavia-webservices/rest/token/generacion`.
      Se dedujo de la de pruebas.
- [ ] `php artisan savia:probar` dice que las credenciales funcionan, y
      `php artisan savia:probar <un documento>` que lo encuentra.
- [ ] El certificado de producción es válido (`SAVIA_VERIFY_SSL=true`). Si
      dicen que no, que lo pongan por escrito.
- [ ] El firewall del servidor (y el de la red, si hay) deja **salir** hacia el
      puerto de Savia.

### DNS y servidor

- [ ] Registro A de `consultasavia.comitedeestudiosmedicos.com.co` hacia la IP
      del servidor (`dig +short consultasavia.comitedeestudiosmedicos.com.co`).
- [ ] Puertos 80 y 443 abiertos; 3306 cerrado.
- [ ] Certificado vigente y `certbot renew --dry-run` sin errores.
- [ ] Copias de seguridad corriendo y saliendo del servidor.

## 12. Prueba después de desplegar

En una ventana privada del navegador:

En el servidor, primero:

```bash
php artisan savia:probar
php artisan savia:probar <un documento que exista>
```

Luego, en una ventana privada del navegador:

1. `http://consultasavia.comitedeestudiosmedicos.com.co` redirige a https.
2. Entra con **`ConsultaPRP`**. Llegas directo a **Consultar paciente**: sin
   Escritorio y sin preguntas. En el menú no hay nada más, y arriba no está
   el selector de sede.
3. Consulta un documento real. Ves lo básico (nombre, estado, régimen, IPS) y
   ningún botón de registrar ni de ver ficha.
4. Escribe a mano `/admin/pacientes`, `/admin/tickets` y `/admin/users`: las
   tres responden 403 con la página en español. `/admin` te devuelve a
   Consultar paciente. `/sala/PRP` responde 404.
5. Cierra sesión: vuelves a la página de ingreso y Authentik también cerró.
6. Entra con **`AdminSispam`**. En el menú solo están Escritorio, Consultar
   paciente, Usuarios, Sedes, Auditoría y Roles y permisos. El Escritorio no
   tiene el recuadro de Filament (versión, documentación, GitHub).
7. En **Usuarios**, abre `ConsultaPRP`: el campo Roles dice `CONSULTA` y no se
   puede cambiar.
8. En **Administración → Auditoría**: la consulta del paso 3 aparece con la
   cuenta `ConsultaPRP` y la sede PREMIUM PLAZA (PRP).
9. `/admin/pacientes` también le responde 403 al administrador.
10. Escribe una dirección que no existe: la página de error sale en español,
    sin detalles técnicos.

Si algo falla, revisa `storage/logs/laravel-AAAA-MM-DD.log`.

## 13. Problemas comunes

| Síntoma | Causa probable |
|---|---|
| El panel sale sin estilos | `APP_URL` no tiene https, o hay un proxy y falta `TRUSTED_PROXIES`. Luego `php artisan optimize`. |
| Error 419 justo después de entrar | La cookie es segura y la página llegó por http: revisa la redirección a https y `TRUSTED_PROXIES`. |
| Authentik dice que la dirección de regreso no es válida | La de Authentik no coincide letra por letra con `AUTHENTIK_REDIRECT_URI`. |
| «El usuario … no está registrado en SISPAM» | Faltó el seeder de cuentas, o el username de Authentik no es igual al documento. |
| Una persona entra pero no ve nada | No está en el grupo de su rol en Authentik (los roles se copian en cada ingreso). A las cuentas compartidas ya no les pasa: tienen CONSULTA siempre. |
| «Savia no respondió» para todos | `php artisan savia:probar` dice por qué (sección 6.3). También queda la advertencia en el log. |
| Cambié el `.env` y no pasa nada | `php artisan optimize`: la configuración está en caché. |
| Error 500 | `storage/logs/`. Si es de permisos, `storage/` y `bootstrap/cache/` tienen que ser de `sispam`. |
