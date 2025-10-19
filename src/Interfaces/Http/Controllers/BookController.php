<?php
declare(strict_types=1);

namespace App\Interfaces\Http\Controllers;

use App\Domain\Entity\Book;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Response;
use App\Infrastructure\Persistence\PdoConnection;
use App\Infrastructure\Persistence\MySqlBookRepository;
use App\Infrastructure\Logging\Logger;
use App\Infrastructure\External\OpenLibraryClient;
use App\Infrastructure\Cache\RedisClientFactory;
use App\Infrastructure\Cache\RedisCache;
use App\Infrastructure\Cache\CacheInterface;
use Throwable;

final class BookController
{
    private MySqlBookRepository $repo;
    private Logger $logger;
    private ?CacheInterface $cache = null;

    public function __construct(private Config $config)
    {
        $pdo = (new PdoConnection($config))->pdo();
        $this->repo = new MySqlBookRepository($pdo);
        $this->logger = new Logger($config);

        $r = RedisClientFactory::make($config);
        if ($r !== null) {
            $this->cache = new RedisCache($r);
        }
    }

    public function index(Request $req): void
    {
        $q = $req->query('q', '');
        $sort = $req->query('sort', 'titulo');
        $direction = $req->query('direction', 'asc');
        $page = (int)$req->query('page', 1);
        $perPage = (int)$req->query('per_page', 20);

        $result = $this->repo->search($q, $sort, $direction, $page, $perPage);

        Response::json(
            $result['items'],
            [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $result['total'],
                'sort' => $sort,
                'direction' => $direction,
                'request_id' => $req->id(),
            ]
        );
    }

    public function show(Request $req): void
    {
        $id = $req->param('id');
        $book = $this->repo->findById($id);
        if (!$book) {
            Response::json(null, ['request_id' => $req->id()], [
                ['code' => 'NOT_FOUND', 'message' => 'Libro no encontrado']
            ], 404);
            return;
        }
        Response::json($book, ['request_id' => $req->id()]);
    }

    public function store(Request $req): void
    {
        $data = $req->json();
        $isbn = $data['isbn'] ?? '';

        try {
            // Verificar duplicado
            if ($this->repo->findByIsbn($isbn)) {
                Response::json(null, ['request_id' => $req->id()], [
                    ['code' => 'CONFLICT', 'message' => 'ISBN ya existe']
                ], 409);
                return;
            }

            // Obtener descripción desde API externa
            $client = new OpenLibraryClient($this->config, $this->cache);
            $ext = $client->getByIsbn($isbn);
            $desc = $ext['description'] ?? $data['descripcion'] ?? null;
            if (is_array($desc)) {
                $desc = $desc['value'] ?? null;
            }
            $portada = $ext['cover']['large'] ?? $data['portada_url'] ?? null;

            $book = new Book(
                $data['titulo'] ?? '',
                $data['autor'] ?? '',
                $isbn,
                $data['anio_publicacion'] ?? null,
                $desc,
                $portada
            );

            $id = $this->repo->create($book->toArray());
            $this->logger->info('Libro creado', ['id' => $id, 'isbn' => $isbn]);
            Response::json(['id' => $id], ['request_id' => $req->id()], null, 201);
        } catch (Throwable $e) {
            $this->logger->error('Error al crear libro', ['msg' => $e->getMessage()]);
            Response::json(null, ['request_id' => $req->id()], [
                ['code' => 'INTERNAL_ERROR', 'message' => $e->getMessage()]
            ], 500);
        }
    }

    public function update(Request $req): void
    {
        $id = $req->param('id');
        $data = $req->json();
        try {
            $book = $this->repo->findById($id);
            if (!$book) {
                Response::json(null, ['request_id' => $req->id()], [
                    ['code' => 'NOT_FOUND', 'message' => 'Libro no encontrado']
                ], 404);
                return;
            }

            $isbn = $data['isbn'] ?? $book['isbn'];
            $client = new OpenLibraryClient($this->config, $this->cache);
            $ext = $client->getByIsbn($isbn);
            $desc = $ext['description'] ?? $data['descripcion'] ?? null;
            if (is_array($desc)) {
                $desc = $desc['value'] ?? null;
            }
            $portada = $ext['cover']['large'] ?? $data['portada_url'] ?? null;

            $merged = array_merge($book, [
                'titulo' => $data['titulo'] ?? $book['titulo'],
                'autor' => $data['autor'] ?? $book['autor'],
                'isbn' => $isbn,
                'anio_publicacion' => $data['anio_publicacion'] ?? $book['anio_publicacion'],
                'descripcion' => $desc,
                'portada_url' => $portada,
            ]);

            $this->repo->update($id, $merged);
            $this->logger->info('Libro actualizado', ['id' => $id]);
            Response::json(['updated' => true], ['request_id' => $req->id()]);
        } catch (Throwable $e) {
            $this->logger->error('Error al actualizar libro', ['msg' => $e->getMessage()]);
            Response::json(null, ['request_id' => $req->id()], [
                ['code' => 'INTERNAL_ERROR', 'message' => $e->getMessage()]
            ], 500);
        }
    }

    public function destroy(Request $req): void
    {
        $id = $req->param('id');
        try {
            $ok = $this->repo->delete($id);
            if (!$ok) {
                Response::json(null, ['request_id' => $req->id()], [
                    ['code' => 'NOT_FOUND', 'message' => 'Libro no encontrado']
                ], 404);
                return;
            }
            $this->logger->info('Libro eliminado', ['id' => $id]);
            Response::json(['deleted' => true], ['request_id' => $req->id()]);
        } catch (Throwable $e) {
            $this->logger->error('Error al eliminar libro', ['msg' => $e->getMessage()]);
            Response::json(null, ['request_id' => $req->id()], [
                ['code' => 'INTERNAL_ERROR', 'message' => $e->getMessage()]
            ], 500);
        }
    }
}
