<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Entity\Book;
use PDO;
use PDOException;

final class MySqlBookRepository
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Búsqueda con filtros: q (normalizado), titulo, autor, orden, paginación.
     *
     * @param string|null $q
     * @param string|null $titulo
     * @param string|null $autor
     * @param string $sort
     * @param string $direction
     * @param int $page
     * @param int $perPage
     * @return array{items: array<array<string,mixed>>, total:int}
     */
    public function search(
        ?string $q,
        ?string $titulo,
        ?string $autor,
        string  $sort = 'titulo',
        string  $direction = 'asc',
        int     $page = 1,
        int     $perPage = 20
    ): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $allowedSort = ['titulo', 'autor', 'anio_publicacion', 'created_at'];
        if (!in_array($sort, $allowedSort, true)) {
            $sort = 'titulo';
        }
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        $where = ['deleted_at IS NULL'];
        $params = [];

        // Buscador normalizado (depende de columnas generadas titulo_norm/autor_norm)
        if ($q !== null && $q !== '') {
            $where[] = '(titulo_norm LIKE :q OR autor_norm LIKE :q)';
            $params[':q'] = '%' . $this->normalize($q) . '%';
        }
        if ($titulo !== null && $titulo !== '') {
            $where[] = 'titulo_norm LIKE :t';
            $params[':t'] = '%' . $this->normalize($titulo) . '%';
        }
        if ($autor !== null && $autor !== '') {
            $where[] = 'autor_norm LIKE :a';
            $params[':a'] = '%' . $this->normalize($autor) . '%';
        }

        $whereSql = implode(' AND ', $where);
        $orderSql = "ORDER BY {$sort} {$direction}";
        $offset = ($page - 1) * $perPage;
        $limitSql = "LIMIT :limit OFFSET :offset";

        // Total
        $sqlCount = "SELECT COUNT(*) AS c FROM libros WHERE {$whereSql}";
        $stmtC = $this->pdo->prepare($sqlCount);
        foreach ($params as $k => $v) {
            $stmtC->bindValue($k, $v);
        }
        $stmtC->execute();
        $total = (int)$stmtC->fetchColumn();

        // Items
        $sql = "SELECT id, titulo, autor, isbn, anio_publicacion, descripcion, portada_url, portada_path,
                       created_at, created_by, updated_at, updated_by, deleted_at, deleted_by
                FROM libros
                WHERE {$whereSql}
                {$orderSql}
                {$limitSql}";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        /** @var array<int, array<string,mixed>> $items */
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): string
    {
        $sql = "INSERT INTO libros
                (id, titulo, autor, isbn, anio_publicacion, descripcion, portada_url, portada_path,
                 created_at, created_by, updated_at, updated_by, deleted_at, deleted_by)
                VALUES
                (:id, :titulo, :autor, :isbn, :anio_publicacion, :descripcion, :portada_url, :portada_path,
                 :created_at, :created_by, :updated_at, :updated_by, :deleted_at, :deleted_by)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', (string)$data['id']);
        $stmt->bindValue(':titulo', (string)$data['titulo']);
        $stmt->bindValue(':autor', (string)$data['autor']);
        $stmt->bindValue(':isbn', (string)$data['isbn']);
        $stmt->bindValue(':anio_publicacion', $data['anio_publicacion'] !== null ? (int)$data['anio_publicacion'] : null, $data['anio_publicacion'] !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':descripcion', $data['descripcion'] ?? null);
        $stmt->bindValue(':portada_url', $data['portada_url'] ?? null);
        $stmt->bindValue(':portada_path', $data['portada_path'] ?? null);
        $stmt->bindValue(':created_at', (string)$data['created_at']);
        $stmt->bindValue(':created_by', (string)$data['created_by']);
        $stmt->bindValue(':updated_at', $data['updated_at'] ?? null);
        $stmt->bindValue(':updated_by', $data['updated_by'] ?? null);
        $stmt->bindValue(':deleted_at', $data['deleted_at'] ?? null);
        $stmt->bindValue(':deleted_by', $data['deleted_by'] ?? null);
        $stmt->execute();

        return (string)$data['id'];
    }

    /**
     * @param string $id
     * @param array<string,mixed> $data
     */
    public function update(string $id, array $data): bool
    {
        // Campos permitidos en update
        $fields = [
            'titulo', 'autor', 'isbn', 'anio_publicacion', 'descripcion',
            'portada_url', 'portada_path', 'updated_at', 'updated_by'
        ];

        $sets = [];
        $params = [':id' => $id];

        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = "{$f} = :{$f}";
                $params[":{$f}"] = $data[$f];
            }
        }

        if (empty($sets)) {
            return false;
        }

        $sql = "UPDATE libros SET " . implode(', ', $sets) . " WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $k => $v) {
            if (($k === ':anio_publicacion') && ($v === null)) {
                $stmt->bindValue($k, null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($k, $v);
            }
        }

        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    public function softDelete(string $id, string $when, string $by): bool
    {
        $sql = "UPDATE libros SET deleted_at = :when, deleted_by = :by WHERE id = :id AND deleted_at IS NULL";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':when', $when);
        $stmt->bindValue(':by', $by);
        $stmt->bindValue(':id', $id);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    public function hardDelete(string $id): bool
    {
        $sql = "DELETE FROM libros WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $id);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    /** @return array<string,mixed>|null */
    public function findById(string $id): ?array
    {
        $sql = "SELECT id, titulo, autor, isbn, anio_publicacion, descripcion, portada_url, portada_path,
                       created_at, created_by, updated_at, updated_by, deleted_at, deleted_by
                FROM libros WHERE id = :id LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $id);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function findByIsbn(string $isbn): ?array
    {
        $sql = "SELECT id, titulo, autor, isbn, anio_publicacion, descripcion, portada_url, portada_path,
                       created_at, created_by, updated_at, updated_by, deleted_at, deleted_by
                FROM libros WHERE isbn = :isbn LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':isbn', $isbn);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = \Normalizer::normalize($s, \Normalizer::FORM_D);
        $s = preg_replace('/\p{Mn}+/u', '', (string)$s);
        return $s ?? '';
    }
}
