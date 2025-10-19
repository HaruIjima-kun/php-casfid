<?php
declare(strict_types=1);

namespace App\Interfaces\Http\Controllers;

use App\Domain\ValueObject\Isbn;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Response;
use App\Infrastructure\Logging\Logger;
use App\Infrastructure\Persistence\PdoConnection;
use App\Infrastructure\Persistence\MySqlBookRepository;
use App\Infrastructure\External\OpenLibraryClient;

use App\Infrastructure\Cache\CacheInterface;
use App\Infrastructure\Cache\RedisClientFactory;
use App\Infrastructure\Cache\RedisCache;
use App\Infrastructure\Cache\CacheInvalidator;

use App\Infrastructure\Storage\LocalFileStorage;
use App\Infrastructure\Services\CoverService;

use App\Shared\Uuid;
use App\Shared\Clock;
use PDOException;
use Throwable;

final class BookController
{
    private MySqlBookRepository $repo;
    private Logger $logger;

    /** @var CacheInterface|null */
    private ?CacheInterface $cache = null;

    /** @var CacheInvalidator|null */
    private ?CacheInvalidator $invalidator = null;

    /** Covers **/
    private ?CoverService $coverService = null;
    private bool $storeCovers = false;

    public function __construct(private Config $config)
    {
        $pdo = (new PdoConnection($config))->pdo();
        $this->repo = new MySqlBookRepository($pdo);
        $this->logger = new Logger($config);

        // Redis (caché OpenLibrary + invalidación caché de respuestas)
        $r = RedisClientFactory::make($config);
        if ($r !== null) {
            $this->cache = new RedisCache($r);
            $this->invalidator = new CacheInvalidator($r);
        }

        // Portadas locales
        $this->storeCovers = strtolower($this->config->get('STORE_COVERS', 'true') ?? 'true') === 'true';
        if ($this->storeCovers) {
            $this->coverService = new CoverService($this->config, new LocalFileStorage());
        }
    }

    /**
     * GET /api/v1/libros
     */
    public function index(Request $req): void
    {
        $q = trim((string)$req->queryParam('q', ''));
        $titulo = trim((string)$req->queryParam('titulo', ''));
        $autor = trim((string)$req->queryParam('autor', ''));
        $sort = (string)$req->queryParam('sort', 'titulo');
        $direction = (string)$req->queryParam('direction', 'asc');
        $page = (int)$req->queryParam('page', '1');
        $perPage = (int)$req->queryParam('per_page', (string)($this->config->get('PAGINATION_PER_PAGE', '20') ?? '20'));

        $result = $this->repo->search(
            $q !== '' ? $q : null,
            $titulo !== '' ? $titulo : null,
            $autor !== '' ? $autor : null,
            $sort,
            strtolower($direction) === 'desc' ? 'desc' : 'asc',
            $page,
            $perPage
        );

        Response::json(
            $result['items'],
            [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $result['total'],
                'sort' => $sort,
                'direction' => strtolower($direction) === 'desc' ? 'desc' : 'asc',
                'request_id' => $req->id(),
            ]
        );
    }

    /**
     * GET /api/v1/libros/{id}
     */
    public function show(Request $req): void
    {
        $id = $_SERVER['ROUTE_PARAMS']['id'] ?? null;
        if (!$id) {
            Response::json(null, ['request_id' => $req->id()], [['code' => 'BAD_REQUEST', 'message' => 'Missing id']], 400);
            return;
        }

        $book = $this->repo->findById($id);
        if (!$book || $book['deleted_at'] !== null) {
            Response::json(null, ['request_id' => $req->id()], [['code' => 'NOT_FOUND', 'message' => 'Libro no encontrado']], 404);
            return;
        }

        Response::json($book, ['request_id' => $req->id()]);
    }

    /**
     * POST /api/v1/libros
     */
    public function store(Request $req): void
    {
        $userId = $_SERVER['AUTH_USER_ID'] ?? '00000000-0000-0000-0000-000000000001';
        $body = $req->json();

        $titulo = trim((string)($body['titulo'] ?? ''));
        $autor = trim((string)($body['autor'] ?? ''));
        $isbnIn = trim((string)($body['isbn'] ?? ''));
        $anio = isset($body['anio_publicacion']) && $body['anio_publicacion'] !== ''
            ? (int)$body['anio_publicacion']
            : null;

        $errors = [];
        if ($titulo === '' || mb_strlen($titulo) > 150) $errors['titulo'] = 'obligatorio (<=150)';
        if ($autor === '' || mb_strlen($autor) > 100) $errors['autor'] = 'obligatorio (<=100)';

        try {
            $isbn = (new Isbn($isbnIn))->value();
        } catch (Throwable) {
            $errors['isbn'] = 'inválido';
            $isbn = $isbnIn;
        }

        if ($anio !== null) {
            $max = (int)date('Y') + 3;
            if ($anio > $max) $errors['anio_publicacion'] = "debe ser <= {$max}";
        }

        if ($errors) {
            Response::json(null, ['request_id' => $req->id()], [['code' => 'VALIDATION_ERROR', 'message' => 'Errores de validación', 'details' => $errors]], 400);
            return;
        }

        // Duplicado
        if ($this->repo->findByIsbn($isbn)) {
            Response::json(null, ['request_id' => $req->id()], [['code' => 'CONFLICT', 'message' => 'ISBN ya existe']], 409);
            return;
        }

        // Enriquecimiento externo
        $descripcion = null;
        $portadaUrl = null;
        $portadaPath = null;
        $apiFailed = false;

        try {
            $client = new OpenLibraryClient($this->config, $this->cache);
            $bk = $isbn ? $client->getByIsbn($isbn) : [];
            if ($bk) {
                $descripcion = $bk['description']['value'] ?? ($bk['description'] ?? null);
                if (isset($bk['cover']['large'])) $portadaUrl = $bk['cover']['large'];
                elseif (isset($bk['cover']['medium'])) $portadaUrl = $bk['cover']['medium'];
                elseif (isset($bk['cover']['small'])) $portadaUrl = $bk['cover']['small'];

                if ($anio === null && isset($bk['publish_date']) && preg_match('/(\d{4})/', $bk['publish_date'], $m)) {
                    $anio = (int)$m[1];
                }
                if ($autor === '' && !empty($bk['authors'][0]['name'])) {
                    $autor = (string)$bk['authors'][0]['name'];
                }
            }
        } catch (Throwable) {
            $apiFailed = true;
            $this->logger->warning('external.openlibrary.partial_or_failed', ['isbn' => $isbn, 'titulo' => $titulo, 'autor' => $autor]);
        }

        // Descarga de portada local si procede
        if ($this->storeCovers && $this->coverService && $portadaUrl) {
            try {
                $dl = $this->coverService->download($portadaUrl, $isbn);
                if ($dl !== null) {
                    $portadaPath = $dl;
                }
            } catch (Throwable $e) {
                $this->logger->warning('cover.download.failed', ['isbn' => $isbn, 'error' => $e->getMessage()]);
            }
        }

        $id = Uuid::v4();

        try {
            $this->repo->create([
                'id' => $id,
                'titulo' => $titulo,
                'autor' => $autor,
                'isbn' => $isbn,
                'anio_publicacion' => $anio,
                'descripcion' => $descripcion,
                'portada_url' => $portadaUrl,
                'portada_path' => $portadaPath,
                'created_at' => Clock::now(),
                'created_by' => $userId,
            ]);
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate')) {
                Response::json(null, ['request_id' => $req->id()], [['code' => 'CONFLICT', 'message' => 'ISBN ya existe']], 409);
                return;
            }
            throw $e;
        }

        $this->logger->info('book.created', ['id' => $id, 'isbn' => $isbn, 'titulo' => $titulo, 'autor' => $autor]);

        // Invalidación de caché de respuestas
        if ($this->invalidator) {
            $this->invalidator->invalidateAllListEndpoints();
            $this->invalidator->invalidateBook($id);
        }

        $meta = ['request_id' => $req->id()];
        if ($apiFailed) {
            $meta['external_status'] = 503;
        }

        Response::json([
            'id' => $id,
            'titulo' => $titulo,
            'autor' => $autor,
            'isbn' => $isbn,
            'anio_publicacion' => $anio,
            'descripcion' => $descripcion,
            'portada_url' => $portadaUrl,
            'portada_path' => $portadaPath,
        ], $meta, null, 201);
    }

    /**
     * PUT /api/v1/libros/{id}
     */
    public function update(Request $req): void
    {
        $userId = $_SERVER['AUTH_USER_ID'] ?? '00000000-0000-0000-0000-000000000001';
        $body = $req->json();

        $id = $_SERVER['ROUTE_PARAMS']['id'] ?? null;
        if (!$id) {
            Response::json(null, ['request_id' => $req->id()], [['code' => 'BAD_REQUEST', 'message' => 'Missing id']], 400);
            return;
        }

        $exists = $this->repo->findById($id);
        if (!$exists || $exists['deleted_at'] !== null) {
            Response::json(null, ['request_id' => $req->id()], [['code' => 'NOT_FOUND', 'message' => 'Libro no encontrado']], 404);
            return;
        }

        $titulo = trim((string)($body['titulo'] ?? $exists['titulo']));
        $autor = trim((string)($body['autor'] ?? $exists['autor']));
        $isbnIn = trim((string)($body['isbn'] ?? $exists['isbn']));
        $anio = array_key_exists('anio_publicacion', $body)
            ? (($body['anio_publicacion'] === '' || $body['anio_publicacion'] === null) ? null : (int)$body['anio_publicacion'])
            : ($exists['anio_publicacion'] !== null ? (int)$exists['anio_publicacion'] : null);

        $errors = [];
        if ($titulo === '' || mb_strlen($titulo) > 150) $errors['titulo'] = 'obligatorio (<=150)';
        if ($autor === '' || mb_strlen($autor) > 100) $errors['autor'] = 'obligatorio (<=100)';
        try {
            $isbn = (new Isbn($isbnIn))->value();
        } catch (Throwable) {
            $errors['isbn'] = 'inválido';
            $isbn = $isbnIn;
        }
        if ($anio !== null) {
            $max = (int)date('Y') + 3;
            if ($anio > $max) $errors['anio_publicacion'] = "debe ser <= {$max}";
        }
        if ($errors) {
            Response::json(null, ['request_id' => $req->id()], [['code' => 'VALIDATION_ERROR', 'message' => 'Errores de validación', 'details' => $errors]], 400);
            return;
        }

        $descripcion = $exists['descripcion'];
        $portadaUrl = $exists['portada_url'];
        $portadaPath = $exists['portada_path'] ?? null;
        $apiFailed = false;

        // ¿forzar refresco?
        $refreshCover = (string)$req->queryParam('refresh_cover', '0') === '1';

        $needsEnrichment = ($descripcion === null)
            || ($portadaUrl === null)
            || ($isbn !== $exists['isbn'])
            || ($titulo !== $exists['titulo'])
            || ($autor !== $exists['autor']);

        if ($needsEnrichment) {
            try {
                $client = new OpenLibraryClient($this->config, $this->cache);
                $bk = $isbn ? $client->getByIsbn($isbn) : [];
                if ($bk) {
                    if ($descripcion === null) {
                        $descripcion = $bk['description']['value'] ?? ($bk['description'] ?? null);
                    }
                    if ($portadaUrl === null) {
                        if (isset($bk['cover']['large'])) $portadaUrl = $bk['cover']['large'];
                        elseif (isset($bk['cover']['medium'])) $portadaUrl = $bk['cover']['medium'];
                        elseif (isset($bk['cover']['small'])) $portadaUrl = $bk['cover']['small'];
                    }
                    if ($anio === null && isset($bk['publish_date']) && preg_match('/(\d{4})/', $bk['publish_date'], $m)) {
                        $anio = (int)$m[1];
                    }
                    if ($autor === '' && !empty($bk['authors'][0]['name'])) $autor = (string)$bk['authors'][0]['name'];
                }
            } catch (Throwable) {
                $apiFailed = true;
                $this->logger->warning('external.openlibrary.partial_or_failed', ['isbn' => $isbn, 'id' => $id]);
            }
        }

        // Refresh portada local si procede
        if ($this->storeCovers && $this->coverService) {
            $shouldRedownload = $refreshCover;

            if (!$shouldRedownload && $portadaUrl) {
                try {
                    $alive = $this->coverService->isRemoteAlive($portadaUrl);
                    $shouldRedownload = !$alive;
                } catch (Throwable) {
                    $shouldRedownload = true;
                }
            }

            if ($shouldRedownload && $portadaUrl) {
                try {
                    $newPath = $this->coverService->download($portadaUrl, $isbn);
                    if ($newPath !== null) {
                        $portadaPath = $newPath;
                    }
                } catch (Throwable $e) {
                    $this->logger->warning('cover.download.failed', ['isbn' => $isbn, 'id' => $id, 'error' => $e->getMessage()]);
                }
            }
        }

        try {
            $ok = $this->repo->update($id, [
                'titulo' => $titulo,
                'autor' => $autor,
                'isbn' => $isbn,
                'anio_publicacion' => $anio,
                'descripcion' => $descripcion,
                'portada_url' => $portadaUrl,
                'portada_path' => $portadaPath,
                'updated_at' => Clock::now(),
                'updated_by' => $userId,
            ]);
            if (!$ok) {
                Response::json(null, ['request_id' => $req->id()], [['code' => 'NOT_FOUND', 'message' => 'Libro no encontrado']], 404);
                return;
            }
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate')) {
                Response::json(null, ['request_id' => $req->id()], [['code' => 'CONFLICT', 'message' => 'ISBN ya existe']], 409);
                return;
            }
            throw $e;
        }

        $this->logger->info('book.updated', ['id' => $id, 'isbn' => $isbn, 'titulo' => $titulo, 'autor' => $autor]);

        // Invalidación de caché de respuestas
        if ($this->invalidator) {
            $this->invalidator->invalidateAllListEndpoints();
            $this->invalidator->invalidateBook($id);
        }

        $meta = ['request_id' => $req->id()];
        if ($apiFailed) {
            $meta['external_status'] = 503;
        }

        Response::json([
            'id' => $id,
            'titulo' => $titulo,
            'autor' => $autor,
            'isbn' => $isbn,
            'anio_publicacion' => $anio,
            'descripcion' => $descripcion,
            'portada_url' => $portadaUrl,
            'portada_path' => $portadaPath,
        ], $meta, null, 200);
    }

    /**
     * DELETE /api/v1/libros/{id}
     */
    public function destroy(Request $req): void
    {
        $userId = $_SERVER['AUTH_USER_ID'] ?? '00000000-0000-0000-0000-000000000001';
        $id = $_SERVER['ROUTE_PARAMS']['id'] ?? null;
        if (!$id) {
            Response::json(null, ['request_id' => $req->id()], [['code' => 'BAD_REQUEST', 'message' => 'Missing id']], 400);
            return;
        }

        $mode = strtolower($this->config->get('DELETE_MODE', 'soft') ?? 'soft');

        $ok = false;
        if ($mode === 'hard') {
            $ok = $this->repo->hardDelete($id);
        } else {
            $ok = $this->repo->softDelete($id, Clock::now(), $userId);
        }

        if (!$ok) {
            Response::json(null, ['request_id' => $req->id()], [['code' => 'NOT_FOUND', 'message' => 'Libro no encontrado']], 404);
            return;
        }

        $this->logger->info('book.deleted', ['id' => $id, 'mode' => $mode]);

        // Invalidación de caché de respuestas
        if ($this->invalidator) {
            $this->invalidator->invalidateAllListEndpoints();
            $this->invalidator->invalidateBook($id);
        }

        http_response_code(204);
    }
}
