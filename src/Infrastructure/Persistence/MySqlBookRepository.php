<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;
use App\Domain\Entity\Book;

final class MySqlBookRepository
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @return array{items: array<int,Book>, total: int}
     */
    public function search(
        ?string $q,
        ?string $titulo,
        ?string $autor,
        string $sort,
        string $direction,
        int $page,
        int $perPage
    ): array {
        $where = ['deleted_at IS NULL'];
        $params = [];

        if ($q !== null && $q !== '') {
            $where[] = '(LOWER(titulo_norm) LIKE :q OR LOWER(autor_norm) LIKE :q)';
            $params[':q'] = '%' . mb_strtolower($q) . '%';
        }
        if ($titulo !== null && $titulo !== '') {
            $where[] = 'LOWER(titulo_norm) LIKE :titulo';
            $params[':titulo'] = '%' . mb_strtolower($titulo) . '%';
        }
        if ($autor !== null && $autor !== '') {
            $where[] = 'LOWER(autor_norm) LIKE :autor';
            $params[':autor'] = '%' . mb_strtolower($autor) . '%';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $allowedSort = ['titulo','autor','anio_publicacion','created_at'];
        if (!in_array($sort, $allowedSort, true)) $sort = 'titulo';
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        $offset = max(0, ($page - 1) * $perPage);

        $sql = "
            SELECT id, titulo, autor, isbn, anio_publicacion, descripcion,
                   portada_url, portada_path,
                   created_at, created_by, updated_at, updated_by, deleted_at, deleted_by
            FROM libros
            $whereSql
            ORDER BY $sort $direction
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->hydrate($row);
        }

        $countSql = "SELECT COUNT(1) AS c FROM libros $whereSql";
        $countStmt = $this->pdo->prepare($countSql);
        foreach ($params as $k => $v) { $countStmt->bindValue($k, $v); }
        $countStmt->execute();
        $total = (int)($countStmt->fetchColumn() ?: 0);

        return ['items' => $items, 'total' => $total];
    }

    public function findById(string $id): ?Book
    {
        $stmt = $this->pdo->prepare("SELECT * FROM libros WHERE id = :id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    public function findByIsbn(string $isbn): ?Book
    {
        $stmt = $this->pdo->prepare("SELECT * FROM libros WHERE isbn = :isbn AND deleted_at IS NULL");
        $stmt->execute([':isbn' => $isbn]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): Book
    {
        $id        = (string)($data['id'] ?? $this->uuid4());
        $now       = (string)($data['created_at'] ?? date('Y-m-d H:i:s'));
        $createdBy = (string)($data['created_by'] ?? '00000000-0000-0000-0000-000000000001');

        $stmt = $this->pdo->prepare("
            INSERT INTO libros (id, titulo, autor, isbn, anio_publicacion, descripcion, portada_url, portada_path, created_at, created_by)
            VALUES (:id, :titulo, :autor, :isbn, :anio_publicacion, :descripcion, :portada_url, :portada_path, :created_at, :created_by)
        ");
        $stmt->execute([
            ':id' => $id,
            ':titulo' => (string)$data['titulo'],
            ':autor' => (string)$data['autor'],
            ':isbn' => (string)$data['isbn'],
            ':anio_publicacion' => $data['anio_publicacion'] ?? null,
            ':descripcion' => $data['descripcion'] ?? null,
            ':portada_url' => $data['portada_url'] ?? null,
            ':portada_path' => $data['portada_path'] ?? null,
            ':created_at' => $now,
            ':created_by' => $createdBy,
        ]);

        return $this->findById($id) ?? new Book(
            $id,
            (string)$data['titulo'],
            (string)$data['autor'],
            (string)$data['isbn'],
            isset($data['anio_publicacion']) ? (int)$data['anio_publicificacion'] : null,
            isset($data['descripcion']) ? (string)$data['descripcion'] : null,
            isset($data['portada_url']) ? (string)$data['portada_url'] : null,
            isset($data['portada_path']) ? (string)$data['portada_path'] : null,
            $now,
            $createdBy,
            null, null, null, null
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(string $id, array $data): Book
    {
        $now       = date('Y-m-d H:i:s');
        $updatedBy = (string)($data['updated_by'] ?? '00000000-0000-0000-0000-000000000001');

        $stmt = $this->pdo->prepare("
            UPDATE libros
               SET titulo = :titulo,
                   autor  = :autor,
                   isbn   = :isbn,
                   anio_publicacion = :anio_publicacion,
                   descripcion = :descripcion,
                   portada_url = :portada_url,
                   portada_path = :portada_path,
                   updated_at = :updated_at,
                   updated_by = :updated_by
             WHERE id = :id AND deleted_at IS NULL
        ");
        $stmt->execute([
            ':id' => $id,
            ':titulo' => (string)$data['titulo'],
            ':autor' => (string)$data['autor'],
            ':isbn' => (string)$data['isbn'],
            ':anio_publicacion' => $data['anio_publicacion'] ?? null,
            ':descripcion' => $data['descripcion'] ?? null,
            ':portada_url' => $data['portada_url'] ?? null,
            ':portada_path' => $data['portada_path'] ?? null,
            ':updated_at' => $now,
            ':updated_by' => $updatedBy,
        ]);

        return $this->findById($id) ?? new Book(
            $id,
            (string)$data['titulo'],
            (string)$data['autor'],
            (string)$data['isbn'],
            isset($data['anio_publicacion']) ? (int)$data['anio_publicacion'] : null,
            isset($data['descripcion']) ? (string)$data['descripcion'] : null,
            isset($data['portada_url']) ? (string)$data['portada_url'] : null,
            isset($data['portada_path']) ? (string)$data['portada_path'] : null,
            (string)($data['created_at'] ?? $now),
            (string)($data['created_by'] ?? '00000000-0000-0000-0000-000000000001'),
            $now,
            $updatedBy,
            null,
            null
        );
    }

    public function softDelete(string $id, string $deletedBy): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE libros SET deleted_at = :ts, deleted_by = :uid WHERE id = :id AND deleted_at IS NULL
        ");
        $stmt->execute([
            ':id' => $id,
            ':ts' => date('Y-m-d H:i:s'),
            ':uid' => $deletedBy,
        ]);
    }

    public function hardDelete(string $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM libros WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Book
    {
        $createdAt = $row['created_at'] ?? date('Y-m-d H:i:s');
        $createdBy = $row['created_by'] ?? '00000000-0000-0000-0000-000000000001';

        return new Book(
            (string)$row['id'],
            (string)$row['titulo'],
            (string)$row['autor'],
            (string)$row['isbn'],
            isset($row['anio_publicacion']) ? (int)$row['anio_publicacion'] : null,
            isset($row['descripcion']) ? (string)$row['descripcion'] : null,
            isset($row['portada_url']) ? (string)$row['portada_url'] : null,
            isset($row['portada_path']) ? (string)$row['portada_path'] : null,
            (string)$createdAt,
            (string)$createdBy,
            isset($row['updated_at']) ? (string)$row['updated_at'] : null,
            isset($row['updated_by']) ? (string)$row['updated_by'] : null,
            isset($row['deleted_at']) ? (string)$row['deleted_at'] : null,
            isset($row['deleted_by']) ? (string)$row['deleted_by'] : null,
        );
    }

    private function uuid4(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
