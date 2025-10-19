# 📚 BooksAPI (PHP 8.3 + MySQL + Redis + Nginx)

API CRUD de libros con enriquecimiento desde Open Library, autenticación JWT, rate limiting, logs y Docker.

---

## 🚀 Requisitos

- Docker Desktop (WSL2 recomendado)
- PowerShell (Windows) o bash
- Composer (ya incluido en el contenedor)

---

## ⚙️ Puesta en marcha

docker compose up -d --build
docker compose exec app composer install
docker compose exec app php -v

Copia .env.example → .env y ajusta (JWT_SECRET, DB creds, etc.).

---

## 🩺 Healthcheck

GET http://localhost:8080/health

Devuelve:
{
"data": {
"ok": true,
"name": "BooksAPI",
"time": "2025-10-18T12:00:00+00:00"
},
"meta": {
"request_id": "..."
},
"errors": null
}

---

## 🔐 Autenticación

POST /auth/login
{ "username": "admin", "password": "admin123" }

Devuelve un JWT válido durante 1 hora.

---

## 📘 Endpoints principales

### Listar libros
GET /api/v1/libros?q=...&titulo=...&autor=...&page=1&per_page=20&sort=titulo&direction=asc

### Crear libro (requiere JWT)
POST /api/v1/libros
Authorization: Bearer <token>
{
"titulo": "El prisma negro",
"autor": "Brent Weeks",
"isbn": "9788490322383"
}

### Actualizar libro
PUT /api/v1/libros/{id}
Authorization: Bearer <token>
{
"titulo": "El prisma negro (edición revisada)"
}

### Borrar libro
DELETE /api/v1/libros/{id}
Authorization: Bearer <token>

(El borrado será soft o hard según DELETE_MODE en .env.)

### Búsqueda específicas
GET /api/v1/libros/buscar/titulo?q=...
GET /api/v1/libros/buscar/autor?q=...

---

## 🧩 Formato estándar de respuesta

{
"data": { ... },
"meta": { "request_id": "..." },
"errors": null
}

Errores:
{
"data": null,
"meta": { "request_id": "..." },
"errors": [
{ "code": "VALIDATION_ERROR", "message": "Errores de validación", "details": { "isbn": "inválido" } }
]
}

---

## 🪵 Logs

- Archivo: storage/logs/app.log
- También se envían a stdout (visible con docker compose logs app) si LOG_STDOUT=true.

Niveles:
- info → CRUD y operaciones normales
- warning → API externa parcial o fallida
- error → Excepciones o fallos graves

---

## 🚦 Rate Limiting

- 60 req/min por IP o token (CLIENT_RATE_LIMIT_PER_MINUTE)
- Usa Redis (REDIS_HOST, REDIS_PORT, etc.)
- Si se excede el límite: 429 Too Many Requests

---

## 🧠 Caching

- Resultados de Open Library se cachean 24h
- Configurable con REDIS_TTL_SECONDS

---

## 🧾 Documentación OpenAPI

- Archivo: docs/openapi.yaml
- Compatible con Swagger UI, Redoc, o VSCode Rest Client

---

## 🧪 Tests

Instalar PHPUnit (si no está):
docker compose exec app composer require --dev phpunit/phpunit:^11

Ejecutar todos los tests:
docker compose exec app ./vendor/bin/phpunit

Cobertura objetivo: ≥85%

Tests incluidos:
- Unitarios: IsbnTest, JwtTest
- Integración: BookRepositoryTest (SQLite en memoria)

---

## 🐳 Docker

Servicios:

| Servicio | Descripción |
|-----------|--------------|
| app | PHP 8.3 (FPM + Composer + extensiones Redis, PDO, JSON) |
| nginx | Servidor HTTP reverse proxy |
| mysql | Base de datos MySQL 8.0 |
| redis | Cache / Rate limiting |

Volúmenes:
- ./storage/logs → /var/www/html/storage/logs

---

## 🛡️ Seguridad

- JWT en cabecera Authorization: Bearer
- Consultas preparadas (PDO)
- Cabeceras seguras:
  X-Frame-Options: DENY
  X-Content-Type-Options: nosniff
  X-XSS-Protection: 1; mode=block
  Content-Security-Policy: default-src 'self';

---

## 📦 Variables de entorno

Archivo .env.example:

APP_ENV=local
APP_DEBUG=true
APP_NAME=BooksAPI

DB_HOST=mysql
DB_PORT=3306
DB_NAME=books
DB_USER=root
DB_PASS=root

JWT_SECRET=changeme
JWT_EXPIRE_SECONDS=3600
JWT_REFRESH_TTL=86400

REDIS_HOST=redis
REDIS_PORT=6379
REDIS_TTL_SECONDS=86400
CLIENT_RATE_LIMIT_PER_MINUTE=60

DELETE_MODE=soft
LOG_STDOUT=true
PAGINATION_PER_PAGE=20
TIMEZONE=Europe/Madrid

---

## 🧰 Comandos útiles

docker compose logs -f app
curl http://localhost:8080/health
docker compose exec app ./vendor/bin/phpunit

---

## 📄 Licencia

MIT — creado como ejemplo educativo de buenas prácticas en PHP 8.3, DDD liviano y SOLID.
