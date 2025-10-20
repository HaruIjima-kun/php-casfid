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
        private ?string $portadaPath,
        private string $createdAt,
        private string $createdBy,
        private ?string $updatedAt,
        private ?string $updatedBy,
        private ?string $deletedAt,
        private ?string $deletedBy,
    ) {}

    public function id(): string { return $this->id; }
    public function titulo(): string { return $this->titulo; }
    public function autor(): string { return $this->autor; }
    public function isbn(): string { return $this->isbn; }
    public function anioPublicacion(): ?int { return $this->anioPublicacion; }
    public function descripcion(): ?string { return $this->descripcion; }
    public function portadaUrl(): ?string { return $this->portadaUrl; }
    public function portadaPath(): ?string { return $this->portadaPath; }
    public function createdAt(): string { return $this->createdAt; }
    public function createdBy(): string { return $this->createdBy; }
    public function updatedAt(): ?string { return $this->updatedAt; }
    public function updatedBy(): ?string { return $this->updatedBy; }
    public function deletedAt(): ?string { return $this->deletedAt; }
    public function deletedBy(): ?string { return $this->deletedBy; }

    /** @return array<string,mixed> */
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
            'portada_path' => $this->portadaPath,
            'created_at' => $this->createdAt,
            'created_by' => $this->createdBy,
            'updated_at' => $this->updatedAt,
            'updated_by' => $this->updatedBy,
            'deleted_at' => $this->deletedAt,
            'deleted_by' => $this->deletedBy,
        ];
    }
}
