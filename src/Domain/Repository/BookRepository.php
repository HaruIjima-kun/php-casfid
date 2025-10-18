<?php
declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Book;

interface BookRepository
{
    /**
     * @return array{items: Book[], total: int}
     */
    public function search(
        ?string $q,
        ?string $titulo,
        ?string $autor,
        string $sort,
        string $direction,
        int $page,
        int $perPage,
        bool $includeDeleted = false
    ): array;
}
