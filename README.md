# BooksAPI (PHP 8.3, MySQL, Redis, Docker)

Pequeña API para **gestión de libros** con CRUD, búsqueda, caché de respuestas en Redis, rate limiting, autenticación JWT y **descarga local de portadas** desde OpenLibrary. Incluye **OpenAPI (Swagger)**, tests con PHPUnit y análisis estático con PHPStan.

---

## 🧱 Stack

- **PHP 8.3 (FPM)** + **Nginx**
- **MySQL 8** (datos)
- **Redis** (rate limit + caché)
- **PHPUnit** (tests)
- **PHPStan** (análisis estático)
- **Guzzle** (HTTP client)
- **OpenAPI** (docs en `docs/openapi.yaml`)
- **Docker Compose** para orquestación

---

## 🚀 Puesta en marcha

1. Copia `.env.example` a `.env` y revisa valores.
2. Levanta los servicios:

```bash
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php -v
```

3. *Healthcheck*:

```bash
curl -i http://localhost:8080/health
```

Debe devolver `200 OK` y un JSON con `ok: true`.

> Si usas PowerShell, sustituye `curl` por `Invoke-RestMethod` donde prefieras.

---

## 🗄️ Migraciones y seed

### Migración base (tabla `libros`)
```bash
Get-Content src/Infrastructure/Persistence/Migrations/2025_10_17_000001_create_libros.sql | docker compose exec -T mysql mysql -uroot -proot books
```

### Migración portada local (`portada_path`)
```bash
Get-Content src/Infrastructure/Persistence/Migrations/2025_10_19_000002_add_portada_path.sql | docker compose exec -T mysql mysql -uroot -proot books
```

> Verifica columnas:
```bash
docker compose exec -T mysql mysql -uroot -proot -e "USE books; SHOW COLUMNS FROM libros;"
```

---

## 🔐 Autenticación

Login (usuario demo `admin/admin123`):

```bash
TOKEN=$(curl -s -X POST http://localhost:8080/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"admin123"}' | jq -r '.data.access_token')
echo $TOKEN
```

PowerShell:
```powershell
$auth = Invoke-RestMethod -Uri "http://localhost:8080/auth/login" -Method Post -ContentType "application/json" -Body '{"username":"admin","password":"admin123"}'
$TOKEN = $auth.data.access_token
$TOKEN
```

Incluye el header: `Authorization: Bearer <token>`

---

## 📚 Endpoints principales

- `GET /api/v1/libros` — listado + búsqueda (`q`, `titulo`, `autor`, `sort`, `direction`, `page`, `per_page`)
- `GET /api/v1/libros/{id}` — detalle
- `POST /api/v1/libros` — crear (requiere `titulo`, `autor`, `isbn` válidos)
- `PUT /api/v1/libros/{id}` — actualizar (param opcional `refresh_cover=1` fuerza re-descarga de portada)
- `DELETE /api/v1/libros/{id}` — eliminar (soft/hard según `DELETE_MODE`)

**Formato de respuesta estándar**: `{ "data": ..., "meta": ..., "errors": ... }`

---

## 🧠 Enriquecimiento externo + portadas locales

- Al crear/editar, se consulta **OpenLibrary** (con **caché en Redis**).
- Se guarda `portada_url` (remota) y si `STORE_COVERS=true`, se descarga la imagen a **`/storage/covers`** y se expone **`portada_path`** (vía Nginx `/storage/...`).

Forzar refresco de portada en un update:
```bash
curl -X PUT "http://localhost:8080/api/v1/libros/<ID>?refresh_cover=1" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"titulo":"Nuevo título"}'
```

---

## 🧰 Configuración relevante (`.env.example`)

```dotenv
APP_ENV=local
APP_DEBUG=true
PAGINATION_PER_PAGE=20
DELETE_MODE=soft

# MySQL
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=books
DB_USERNAME=root
DB_PASSWORD=root
DB_TIMEZONE=Europe/Madrid

# Redis
REDIS_HOST=redis
REDIS_PORT=6379
RATE_LIMIT_MAX=60
RATE_LIMIT_WINDOW=60
CACHE_ENABLED=true
API_CACHE_TTL_SECONDS=30

# Portadas locales
STORE_COVERS=true
COVER_STORAGE_PATH=/var/www/html/storage/covers
COVER_BASE_URL=/storage/covers
COVER_TIMEOUT=10

# JWT
JWT_SECRET=devsecret
JWT_ISSUER=BooksAPI
JWT_TTL=3600
JWT_REFRESH_TTL=1209600
```

---

## 🧪 Tests & Lint

### PHPUnit
```bash
docker compose exec app composer test
# o:
docker compose exec app ./vendor/bin/phpunit --display-deprecations --testdox
```

### PHPStan
```bash
docker compose exec app composer stan
# o:
docker compose exec app ./vendor/bin/phpstan analyse --memory-limit=512M
```

### (Opcional) PHP-CS-Fixer
```bash
docker compose exec app composer fix
```

---

## 🧵 Caché de respuestas & Rate limiting

- **Response Cache**: middleware con Redis para **GET** (cabecera `X-Cache: HIT/MISS`).
- **Invalidación**: tras POST/PUT/DELETE se invalidan claves relevantes.
- **Rate Limit**: límites por IP/token con cabeceras `X-RateLimit-*` y `429` si se excede.

---

## 🧾 OpenAPI / Swagger

El contrato está en: **`docs/openapi.yaml`** (actualizado).

### 🔎 Swagger UI (docker standalone)

Usa el archivo `docker-compose.swagger.yml` incluido. Arranca Swagger UI en `http://localhost:8081`:

```bash
docker compose -f docker-compose.swagger.yml up -d swagger
# Abrir en el navegador:
# http://localhost:8081
```

---

## 📂 Estructura de proyecto

```
/public            # index.php (front controller)
/src
  /Domain
  /Application
  /Interfaces      # Controllers HTTP
  /Infrastructure  # DB, HTTP, Cache, Middlewares, etc.
    /Persistence/Migrations
    /Services
    /Storage
/tests             # unit e integración
/docs              # openapi.yaml
/docker            # nginx conf, etc.
/storage           # covers (sirviendo por Nginx /storage)
```

---

## 🧹 Git & Commits

- Flujo: `feature/*` → PR → `develop` → `main` (releases).
- Convencional commits (sugerido):
  - `feat: ...`, `fix: ...`, `test: ...`, `docs: ...`, `chore: ...`, `refactor: ...`, `db: ...`, `ops: ...`

---

## 🛡️ Seguridad

- JWT en header `Authorization` (stateless).
- SQL seguro mediante **consultas preparadas**.
- Cabeceras de seguridad habilitadas (CSP, X-Frame-Options, X-Content-Type-Options, X-XSS-Protection).

---

## 🧭 Troubleshooting rápido

- **Router**: asegúrate de tener `Router::middleware()` y `Request::capture()`.
- **Nginx**: recuerda el alias `/storage/` en `docker/nginx/nginx.conf` y reiniciar:
  ```bash
  docker compose restart nginx
  ```
- **Redis** no disponible → se usan fallbacks (sin cache/ratelimit reales).
- **Permisos**: si no puedes escribir en `storage/covers`, revisa permisos del contenedor.
- **Windows**: usa rutas y comillas adecuadas en PowerShell (usa *backticks* para multilínea).
