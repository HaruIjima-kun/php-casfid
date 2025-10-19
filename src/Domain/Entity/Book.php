<?php
declare(strict_types=1);

namespace App\Domain\Entity;

final class Book
{
    public function __construct(
        private string $id,
        private string $titulo,
        private string $autor,
        private string $isbn,
        private ?int $anioPublicacion,
        private ?string $descripcion,
        private ?string $portadaUrl,
        private string $createdAt,
        private string $createdBy,
        private ?string $updatedAt,
        private ?string $updatedBy,
        private ?string $deletedAt,
        private ?string $deletedBy,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'titulo' => $this->titulo,
            'autor' => $this->autor,
            'isbn' => $this->isbn,
            'anio_publicacion' => $this->anioPublicacion,
            'descripcion' => $this->descripcion,
            'portada_url' => $this->portadaUrl,
            'created_at' => $this->createdAt,
            'created_by' => $this->createdBy,
            'updated_at' => $this->updatedAt,
            'updated_by' => $this->updatedBy,
            'deleted_at' => $this->deletedAt,
            'deleted_by' => $this->deletedBy,
        ];
    }
}
