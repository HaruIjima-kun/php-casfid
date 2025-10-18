<?php
declare(strict_types=1);

namespace App\Interfaces\Http\Controllers;

use App\Infrastructure\Http\{Request, Response};
use App\Infrastructure\Config\Config;
use App\Infrastructure\Persistence\PdoConnection;
use App\Infrastructure\Persistence\MySqlBookRepository;

final class BookController
{
    private MySqlBookRepository $repo;

    public function __construct(private Config $config)
    {
        $pdo = (new PdoConnection($config))->pdo();
        $this->repo = new MySqlBookRepository($pdo);
    }

    public function index(Request $req): void
    {
        $q       = $req->queryParam('q');
        $titulo  = $req->queryParam('titulo');
        $autor   = $req->queryParam('autor');
        $sort    = $req->queryParam('sort', $this->config->get('DEFAULT_SORT', 'titulo')) ?? 'titulo';
        $dir     = $req->queryParam('direction', $this->config->get('DEFAULT_DIRECTION', 'asc')) ?? 'asc';
        $page    = max(1, (int)($req->queryParam('page', '1') ?? '1'));
        $perPage = (int)($req->queryParam('per_page', (string)$this->config->get('PAGINATION_PER_PAGE', '20')) ?? '20');
        $perPage = max(1, min(100, $perPage));

        $result = $this->repo->search($q, $titulo, $autor, $sort, $dir, $page, $perPage);

        $data = array_map(fn($b) => $b->toArray(), $result['items']);

        Response::json($data, [
            'page'      => $page,
            'per_page'  => $perPage,
            'total'     => $result['total'],
            'sort'      => $sort,
            'direction' => strtolower($dir) === 'desc' ? 'desc' : 'asc',
        ]);
    }

    public function store(\App\Infrastructure\Http\Request $req): void
    {
        // Auth (ya lo exigiremos desde la ruta con AuthMiddleware)
        $userId = $_SERVER['AUTH_USER_ID'] ?? null;

        $body = $req->json() ?? [];
        $titulo = trim((string)($body['titulo'] ?? ''));
        $autor  = trim((string)($body['autor'] ?? ''));
        $isbnIn = trim((string)($body['isbn'] ?? ''));
        $anio   = isset($body['anio_publicacion']) && $body['anio_publicacion'] !== ''
            ? (int)$body['anio_publicacion'] : null;

        // Validaciones servidor
        $errors = [];
        if ($titulo === '' || mb_strlen($titulo) > 150) $errors['titulo'] = 'obligatorio (<=150)';
        if ($autor === ''  || mb_strlen($autor)  > 100) $errors['autor']  = 'obligatorio (<=100)';
        // ISBN
        try {
            $isbn = (new \App\Domain\ValueObject\Isbn($isbnIn))->value();
        } catch (\Throwable $e) {
            $errors['isbn'] = 'inválido';
            $isbn = $isbnIn;
        }
        // Año: hasta +3 respecto al actual
        if ($anio !== null) {
            $max = (int)date('Y') + 3;
            if ($anio > $max) $errors['anio_publicacion'] = "debe ser <= {$max}";
        }

        if ($errors) {
            \App\Infrastructure\Http\Response::json(null, [], [
                ['code' => 'VALIDATION_ERROR', 'message' => 'Errores de validación', 'details' => $errors]
            ], 400);
            return;
        }

        // Enriquecimiento externo (no bloqueante en caso de fallo)
        $descripcion = null;
        $portadaUrl  = null;
        $apiFailed   = false;

        try {
            $client = new \App\Infrastructure\External\OpenLibraryClient($this->config);
            if ($isbn) {
                $bk = $client->getByIsbn($isbn);
                if ($bk) {
                    $descripcion = $bk['description']['value'] ?? ($bk['description'] ?? null);
                    // portada
                    if (isset($bk['cover']['large']))   $portadaUrl = $bk['cover']['large'];
                    elseif (isset($bk['cover']['medium'])) $portadaUrl = $bk['cover']['medium'];
                    elseif (isset($bk['cover']['small']))  $portadaUrl = $bk['cover']['small'];
                    // intenta completar año/autor si faltan
                    if ($anio === null && isset($bk['publish_date'])) {
                        if (preg_match('/(\\d{4})/', (string)$bk['publish_date'], $m)) $anio = (int)$m[1];
                    }
                    if ($autor === '' && !empty($bk['authors'][0]['name'])) {
                        $autor = (string)$bk['authors'][0]['name'];
                    }
                }
            }
            if ((!$descripcion || !$portadaUrl) && ($titulo !== '' || $autor !== '')) {
                $sr = $client->searchByTitleAuthor($titulo, $autor);
                if (!empty($sr['docs'][0])) {
                    $doc = $sr['docs'][0];
                    $descripcion ??= $doc['first_sentence'] ?? null;
                    // portada por ISBN si existe
                    if (!$portadaUrl && !empty($doc['isbn'][0])) {
                        $isbn0 = $doc['isbn'][0];
                        $portadaUrl = "https://covers.openlibrary.org/b/isbn/{$isbn0}-L.jpg";
                    }
                    if ($anio === null && isset($doc['first_publish_year'])) $anio = (int)$doc['first_publish_year'];
                }
            }
        } catch (\Throwable $e) {
            $apiFailed = true; // registraremos 503 en meta si quieres
        }

        // Portada: guardar archivo si STORE_COVER_FILES=true
        $storeFiles = filter_var($this->config->get('STORE_COVER_FILES', 'false'), FILTER_VALIDATE_BOOL);
        if ($storeFiles && $portadaUrl) {
            try {
                $clientHttp = new \GuzzleHttp\Client(['http_errors' => false, 'timeout' => 5]);
                $res = $clientHttp->get($portadaUrl);
                if ($res->getStatusCode() === 200) {
                    $bytes = (string)$res->getBody();
                    $name = \App\Shared\Uuid::v4() . '.jpg';
                    $path = $this->config->get('COVERS_PATH', 'storage/covers') . DIRECTORY_SEPARATOR . $name;
                    // asegura carpeta
                    if (!is_dir(dirname($path))) @mkdir(dirname($path), 0775, true);
                    file_put_contents($path, $bytes);
                    // opcional: podríamos guardar path local en otro campo; por ahora mantenemos URL remota
                }
            } catch (\Throwable $e) {
                // ignoramos fallo de descarga (no bloqueante)
            }
        }

        // Insertar en BD
        $id = \App\Shared\Uuid::v4();
        $now = \App\Shared\Clock::now();
        $createdBy = $userId ?: '00000000-0000-0000-0000-000000000001';

        // Conflicto por ISBN único -> 409
        try {
            $this->repo->create([
                'id' => $id,
                'titulo' => $titulo,
                'autor'  => $autor,
                'isbn'   => $isbn,
                'anio_publicacion' => $anio,
                'descripcion' => $descripcion,
                'portada_url' => $portadaUrl,
                'created_at' => $now,
                'created_by' => $createdBy,
            ]);
        } catch (\PDOException $e) {
            if ((int)$e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate')) {
                \App\Infrastructure\Http\Response::json(null, [], [
                    ['code' => 'CONFLICT', 'message' => 'ISBN ya existe']
                ], 409);
                return;
            }
            throw $e;
        }

        $meta = [];
        if ($apiFailed) $meta['external_status'] = 503;

        \App\Infrastructure\Http\Response::json([
            'id' => $id,
            'titulo' => $titulo,
            'autor' => $autor,
            'isbn' => $isbn,
            'anio_publicacion' => $anio,
            'descripcion' => $descripcion,
            'portada_url' => $portadaUrl,
            'created_at' => $now,
            'created_by' => $createdBy,
        ], $meta, null, 201);
    }

    public function update(\App\Infrastructure\Http\Request $req): void
    {
        $userId = $_SERVER['AUTH_USER_ID'] ?? null;
        $body = $req->json() ?? [];

        $id     = $this->extractIdFromPath($req->path()); // helper abajo
        if (!$id) { \App\Infrastructure\Http\Response::json(null, [], [['code'=>'BAD_REQUEST','message'=>'Missing id']], 400); return; }

        // Verifica existencia (aunque sea borrado)
        $exists = $this->repo->findById($id);
        if (!$exists || $exists['deleted_at'] !== null) {
            \App\Infrastructure\Http\Response::json(null, [], [['code'=>'NOT_FOUND','message'=>'Libro no encontrado']], 404);
            return;
        }

        $titulo = trim((string)($body['titulo'] ?? $exists['titulo']));
        $autor  = trim((string)($body['autor']  ?? $exists['autor']));
        $isbnIn = trim((string)($body['isbn']   ?? $exists['isbn']));
        $anio   = array_key_exists('anio_publicacion', $body) ? ( ($body['anio_publicacion']===''||$body['anio_publicacion']===null) ? null : (int)$body['anio_publicacion'] ) : ($exists['anio_publicacion'] !== null ? (int)$exists['anio_publicacion'] : null);

        $errors = [];
        if ($titulo === '' || mb_strlen($titulo) > 150) $errors['titulo'] = 'obligatorio (<=150)';
        if ($autor === ''  || mb_strlen($autor)  > 100) $errors['autor']  = 'obligatorio (<=100)';
        try { $isbn = (new \App\Domain\ValueObject\Isbn($isbnIn))->value(); } catch (\Throwable $e) { $errors['isbn'] = 'inválido'; $isbn=$isbnIn; }
        if ($anio !== null) {
            $max = (int)date('Y') + 3;
            if ($anio > $max) $errors['anio_publicacion'] = "debe ser <= {$max}";
        }
        if ($errors) { \App\Infrastructure\Http\Response::json(null, [], [['code'=>'VALIDATION_ERROR','message'=>'Errores de validación','details'=>$errors]], 400); return; }

        // Re-enriquecimiento opcional (si faltan datos actuales o si quieres forzarlo cuando cambie ISBN/título/autor)
        $descripcion = $exists['descripcion'];
        $portadaUrl  = $exists['portada_url'];
        $apiFailed   = false;

        if (!$descripcion || !$portadaUrl || $isbn !== $exists['isbn'] || $titulo !== $exists['titulo'] || $autor !== $exists['autor']) {
            try {
                $client = new \App\Infrastructure\External\OpenLibraryClient($this->config);
                $bk = $isbn ? $client->getByIsbn($isbn) : [];
                if ($bk) {
                    $descripcion = $descripcion ?: ($bk['description']['value'] ?? ($bk['description'] ?? null));
                    if (!$portadaUrl) {
                        if (isset($bk['cover']['large']))   $portadaUrl = $bk['cover']['large'];
                        elseif (isset($bk['cover']['medium'])) $portadaUrl = $bk['cover']['medium'];
                        elseif (isset($bk['cover']['small']))  $portadaUrl = $bk['cover']['small'];
                    }
                    if ($anio === null && isset($bk['publish_date']) && preg_match('/(\\d{4})/',$bk['publish_date'],$m)) {
                        $anio = (int)$m[1];
                    }
                    if ($autor === '' && !empty($bk['authors'][0]['name'])) $autor = (string)$bk['authors'][0]['name'];
                } else {
                    $sr = $client->searchByTitleAuthor($titulo, $autor);
                    if (!empty($sr['docs'][0])) {
                        $doc = $sr['docs'][0];
                        $descripcion ??= $doc['first_sentence'] ?? null;
                        if (!$portadaUrl && !empty($doc['isbn'][0])) $portadaUrl = "https://covers.openlibrary.org/b/isbn/{$doc['isbn'][0]}-L.jpg";
                        if ($anio === null && isset($doc['first_publish_year'])) $anio = (int)$doc['first_publish_year'];
                    }
                }
            } catch (\Throwable $e) {
                $apiFailed = true;
            }
        }

        // Actualizar
        try {
            $ok = $this->repo->update($id, [
                'titulo' => $titulo,
                'autor'  => $autor,
                'isbn'   => $isbn,
                'anio_publicacion' => $anio,
                'descripcion' => $descripcion,
                'portada_url' => $portadaUrl,
                'updated_at' => \App\Shared\Clock::now(),
                'updated_by' => $userId ?: '00000000-0000-0000-0000-000000000001',
            ]);
            if (!$ok) { \App\Infrastructure\Http\Response::json(null, [], [['code'=>'NOT_FOUND','message'=>'Libro no encontrado']], 404); return; }
        } catch (\PDOException $e) {
            if ((int)$e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate')) {
                \App\Infrastructure\Http\Response::json(null, [], [['code'=>'CONFLICT','message'=>'ISBN ya existe']], 409); return;
            }
            throw $e;
        }

        $meta = [];
        if ($apiFailed) $meta['external_status'] = 503;

        \App\Infrastructure\Http\Response::json([
            'id' => $id,
            'titulo' => $titulo,
            'autor' => $autor,
            'isbn' => $isbn,
            'anio_publicacion' => $anio,
            'descripcion' => $descripcion,
            'portada_url' => $portadaUrl,
        ], $meta, null, 200);
    }

    public function destroy(\App\Infrastructure\Http\Request $req): void
    {
        $userId = $_SERVER['AUTH_USER_ID'] ?? '00000000-0000-0000-0000-000000000001';
        $id     = $this->extractIdFromPath($req->path());
        if (!$id) { \App\Infrastructure\Http\Response::json(null, [], [['code'=>'BAD_REQUEST','message'=>'Missing id']], 400); return; }

        $mode = strtolower($this->config->get('DELETE_MODE', 'soft') ?? 'soft');

        $ok = false;
        if ($mode === 'hard') {
            $ok = $this->repo->hardDelete($id);
        } else {
            $ok = $this->repo->softDelete($id, \App\Shared\Clock::now(), $userId);
        }

        if (!$ok) {
            \App\Infrastructure\Http\Response::json(null, [], [['code'=>'NOT_FOUND','message'=>'Libro no encontrado']], 404);
            return;
        }

        http_response_code(204);
    }


    private function extractIdFromPath(string $path): ?string
    {
        // Espera '/api/v1/libros/{uuid}'
        $parts = explode('/', trim($path, '/'));
        $idx = array_search('libros', $parts, true);
        if ($idx !== false && isset($parts[$idx+1])) return $parts[$idx+1];
        return null;
    }

}
