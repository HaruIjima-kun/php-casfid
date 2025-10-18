<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Repository\BookRepository;
use App\Domain\Entity\Book;
use PDO;

final class MySqlBookRepository implements BookRepository
{
    public function __construct(private PDO $pdo) {}

    public function search(
        ?string $q,
        ?string $titulo,
        ?string $autor,
        string $sort,
        string $direction,
        int $page,
        int $perPage,
        bool $includeDeleted = false
    ): array {
        // Lista blanca de sort/direction
        $allowedSort = ['titulo', 'autor', 'anio_publicacion', 'created_at'];
        if (!in_array($sort, $allowedSort, true)) {
            $sort = 'titulo';
        }
        $direction = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';

        $where = [];
        $params = [];

        if (!$includeDeleted) {
            $where[] = 'deleted_at IS NULL';
        }

        // Filtros: usamos columnas normalizadas *_norm (MySQL 8 collation AI)
        if ($q !== null && $q !== '') {
            $where[] = '(titulo_norm LIKE :q OR autor_norm LIKE :q)';
            $params[':q'] = '%' . mb_strtolower($q) . '%';
        }
        if ($titulo !== null && $titulo !== '') {
            $where[] = 'titulo_norm LIKE :titulo';
            $params[':titulo'] = '%' . mb_strtolower($titulo) . '%';
        }
        if ($autor !== null && $autor !== '') {
            $where[] = 'autor_norm LIKE :autor';
            $params[':autor'] = '%' . mb_strtolower($autor) . '%';
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $offset = ($page - 1) * $perPage;

        // Total
        $sqlCount = "SELECT COUNT(*) AS c FROM libros {$whereSql}";
        $stmt = $this->pdo->prepare($sqlCount);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->execute();
        $total = (int)($stmt->fetchColumn() ?: 0);

        // Items
        $sql = "SELECT id,titulo,autor,isbn,anio_publicacion,descripcion,portada_url,
                       created_at,created_by,updated_at,updated_by,deleted_at,deleted_by
                FROM libros
                {$whereSql}
                ORDER BY {$sort} {$direction}
                LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $items[] = new Book(
                $row['id'],
                $row['titulo'],
                $row['autor'],
                $row['isbn'],
                $row['anio_publicacion'] !== null ? (int)$row['anio_publicacion'] : null,
                $row['descripcion'],
                $row['portada_url'],
                $row['created_at'],
                $row['created_by'],
                $row['updated_at'],
                $row['updated_by'],
                $row['deleted_at'],
                $row['deleted_by'],
            );
        }

        return ['items' => $items, 'total' => $total];
    }
}
