<?php
declare(strict_types=1);

namespace App\Interfaces\Http\Controllers;

use App\Infrastructure\Config\Config;
use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Response;
use App\Infrastructure\Persistence\MySqlBookRepository;
use App\Domain\Entity\Book;
use PDO;
use InvalidArgumentException;

final class BookController
{
    private MySqlBookRepository $repo;

    public function __construct(
        private Config $config,
        PDO $pdo
    ) {
        $this->repo = new MySqlBookRepository($pdo);
    }

    public function index(Request $req): void
    {
        $q       = $req->query('q');
        $titulo  = $req->query('titulo');
        $autor   = $req->query('autor');
        $sort    = $req->query('sort') ?? 'titulo';
        $dir     = $req->query('direction') ?? 'asc';
        $page    = (int)($req->query('page') ?? '1');
        $perPage = (int)($req->query('per_page') ?? ($this->config->get('PAGINATION_PER_PAGE', '20') ?? '20'));

        $result = $this->repo->search(
            is_string($q) ? $q : null,
            is_string($titulo) ? $titulo : null,
            is_string($autor) ? $autor : null,
            is_string($sort) ? $sort : 'titulo',
            is_string($dir) ? $dir : 'asc',
            $page,
            $perPage
        );

        $data = array_map(fn(Book $b) => $this->present($b), $result['items']);

        Response::json(
            $data,
            [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $result['total'],
                'sort'      => is_string($sort) ? $sort : 'titulo',
                'direction' => is_string($dir) ? $dir : 'asc',
                'request_id'=> $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
            ]
        );
    }

    public function show(Request $req): void
    {
        $id = $req->route('id');
        if (!$id) {
            http_response_code(400);
            Response::json(null, [], [[ 'code'=>'BAD_REQUEST','message'=>'Missing id' ]], 400);
            return;
        }

        $book = $this->repo->findById($id);
        if (!$book) {
            http_response_code(404);
            Response::json(null, [], [[ 'code'=>'NOT_FOUND','message'=>'Book not found' ]], 404);
            return;
        }

        Response::json($this->present($book), ['request_id'=>$_SERVER['HTTP_X_REQUEST_ID'] ?? null]);
    }

    public function store(Request $req): void
    {
        $json = $req->json();

        try {
            $this->validate($json, true);
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            Response::json(null, [], [[ 'code'=>'VALIDATION_ERROR','message'=>$e->getMessage() ]], 422);
            return;
        }

        if ($this->repo->findByIsbn((string)$json['isbn'])) {
            http_response_code(409);
            Response::json(null, [], [[ 'code'=>'CONFLICT','message'=>'ISBN ya existe' ]], 409);
            return;
        }

        $created = $this->repo->create([
            'titulo'            => (string)$json['titulo'],
            'autor'             => (string)$json['autor'],
            'isbn'              => (string)$json['isbn'],
            'anio_publicacion'  => isset($json['anio_publicacion']) ? (int)$json['anio_publicacion'] : null,
            'descripcion'       => isset($json['descripcion']) ? (string)$json['descripcion'] : null,
            'portada_url'       => isset($json['portada_url']) ? (string)$json['portada_url'] : null,
            'portada_path'      => isset($json['portada_path']) ? (string)$json['portada_path'] : null,
        ]);

        Response::json($this->present($created), ['request_id'=>$_SERVER['HTTP_X_REQUEST_ID'] ?? null], null, 201);
    }

    public function update(Request $req): void
    {
        $id = $req->route('id');
        if (!$id) {
            http_response_code(400);
            Response::json(null, [], [[ 'code'=>'BAD_REQUEST','message'=>'Missing id' ]], 400);
            return;
        }

        $book = $this->repo->findById($id);
        if (!$book) {
            http_response_code(404);
            Response::json(null, [], [[ 'code'=>'NOT_FOUND','message'=>'Book not found' ]], 404);
            return;
        }

        $json = $req->json();
        try {
            $this->validate($json, false);
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            Response::json(null, [], [[ 'code'=>'VALIDATION_ERROR','message'=>$e->getMessage() ]], 422);
            return;
        }

        if (isset($json['isbn']) && is_string($json['isbn']) && $json['isbn'] !== $book->isbn()) {
            $exists = $this->repo->findByIsbn($json['isbn']);
            if ($exists && $exists->id() !== $book->id()) {
                http_response_code(409);
                Response::json(null, [], [[ 'code'=>'CONFLICT','message'=>'ISBN ya existe' ]], 409);
                return;
            }
        }

        $updated = $this->repo->update($id, [
            'titulo'            => isset($json['titulo']) ? (string)$json['titulo'] : $book->titulo(),
            'autor'             => isset($json['autor']) ? (string)$json['autor'] : $book->autor(),
            'isbn'              => isset($json['isbn']) ? (string)$json['isbn'] : $book->isbn(),
            'anio_publicacion'  => array_key_exists('anio_publicacion', $json) ? (int)$json['anio_publicacion'] : $book->anioPublicacion(),
            'descripcion'       => array_key_exists('descripcion', $json) ? (string)$json['descripcion'] : $book->descripcion(),
            'portada_url'       => array_key_exists('portada_url', $json) ? (string)$json['portada_url'] : $book->portadaUrl(),
            'portada_path'      => array_key_exists('portada_path', $json) ? (string)$json['portada_path'] : $book->portadaPath(),
        ]);

        Response::json($this->present($updated), ['request_id'=>$_SERVER['HTTP_X_REQUEST_ID'] ?? null]);
    }

    public function destroy(Request $req): void
    {
        $id = $req->route('id');
        if (!$id) {
            http_response_code(400);
            Response::json(null, [], [[ 'code'=>'BAD_REQUEST','message'=>'Missing id' ]], 400);
            return;
        }

        $book = $this->repo->findById($id);
        if (!$book) {
            http_response_code(404);
            Response::json(null, [], [[ 'code'=>'NOT_FOUND','message'=>'Book not found' ]], 404);
            return;
        }

        $soft = filter_var((string)$this->config->get('DELETE_SOFT', 'true'), FILTER_VALIDATE_BOOLEAN);
        if ($soft) {
            $this->repo->softDelete($id, '00000000-0000-0000-0000-000000000001');
        } else {
            $this->repo->hardDelete($id);
        }

        Response::json(['deleted' => true], ['request_id'=>$_SERVER['HTTP_X_REQUEST_ID'] ?? null], null, 200);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function validate(array $data, bool $creating): void
    {
        if ($creating) {
            foreach (['titulo','autor','isbn'] as $f) {
                if (!isset($data[$f]) || trim((string)$data[$f]) === '') {
                    throw new InvalidArgumentException("Campo requerido: {$f}");
                }
            }
        }

        if (isset($data['titulo']) && mb_strlen((string)$data['titulo']) > 150) {
            throw new InvalidArgumentException('titulo demasiado largo');
        }
        if (isset($data['autor']) && mb_strlen((string)$data['autor']) > 100) {
            throw new InvalidArgumentException('autor demasiado largo');
        }
        if (isset($data['descripcion']) && mb_strlen((string)$data['descripcion']) > 2000) {
            throw new InvalidArgumentException('descripcion demasiado larga');
        }
        if (isset($data['isbn']) && !$this->isValidIsbn((string)$data['isbn'])) {
            throw new InvalidArgumentException('isbn inválido');
        }
    }

    private function isValidIsbn(string $isbn): bool
    {
        $x = preg_replace('/[^0-9Xx]/', '', $isbn);
        if (strlen($x) === 13) {
            $sum = 0;
            for ($i = 0; $i < 12; $i++) {
                $d = (int)$x[$i];
                $sum += ($i % 2 === 0) ? $d : $d * 3;
            }
            $chk = (10 - ($sum % 10)) % 10;
            return $chk === (int)$x[12];
        }
        if (strlen($x) === 10) {
            $sum = 0;
            for ($i = 0; $i < 9; $i++) {
                $sum += ((10 - $i) * (int)$x[$i]);
            }
            $chk = 11 - ($sum % 11);
            $last = strtoupper($x[9]) === 'X' ? 10 : (int)$x[9];
            return $chk % 11 === $last;
        }
        return false;
    }

    /** @return array<string,mixed> */
    private function present(Book $b): array
    {
        return [
            'id'                => $b->id(),
            'titulo'            => $b->titulo(),
            'autor'             => $b->autor(),
            'isbn'              => $b->isbn(),
            'anio_publicacion'  => $b->anioPublicacion(),
            'descripcion'       => $b->descripcion(),
            'portada_url'       => $b->portadaUrl(),
            'portada_path'      => $b->portadaPath(),
            'created_at'        => $b->createdAt(),
            'created_by'        => $b->createdBy(),
            'updated_at'        => $b->updatedAt(),
            'updated_by'        => $b->updatedBy(),
            'deleted_at'        => $b->deletedAt(),
            'deleted_by'        => $b->deletedBy(),
        ];
    }
}
