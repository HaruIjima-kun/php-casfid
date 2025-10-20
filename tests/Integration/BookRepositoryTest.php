<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use App\Infrastructure\Persistence\MySqlBookRepository;
use App\Domain\Entity\Book;

final class BookRepositoryTest extends TestCase
{
    private PDO $pdo;
    private MySqlBookRepository $repo;

    protected function setUp(): void
    {
        // SQLite en memoria para tests
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Schema con portada_path
        $this->pdo->exec("
            CREATE TABLE libros (
                id CHAR(36) PRIMARY KEY,
                titulo VARCHAR(150) NOT NULL,
                autor  VARCHAR(100) NOT NULL,
                isbn   VARCHAR(13) NOT NULL UNIQUE,
                anio_publicacion INT NULL,
                descripcion VARCHAR(2000) NULL,
                portada_url VARCHAR(512) NULL,
                portada_path VARCHAR(512) NULL,
                titulo_norm VARCHAR(150) GENERATED ALWAYS AS (LOWER(REPLACE(REPLACE(REPLACE(REPLACE(titulo,'á','a'),'é','e'),'í','i'),'ó','o'))) VIRTUAL,
                autor_norm  VARCHAR(100) GENERATED ALWAYS AS (LOWER(REPLACE(REPLACE(REPLACE(REPLACE(autor,'á','a'),'é','e'),'í','i'),'ó','o')))  VIRTUAL,
                created_at DATETIME NOT NULL,
                created_by CHAR(36) NOT NULL,
                updated_at DATETIME NULL,
                updated_by CHAR(36) NULL,
                deleted_at DATETIME NULL,
                deleted_by CHAR(36) NULL
            );
            CREATE INDEX idx_titulo_norm ON libros(titulo_norm);
            CREATE INDEX idx_autor_norm  ON libros(autor_norm);
        ");

        $this->repo = new MySqlBookRepository($this->pdo);

        // Seed mínimo
        $now = date('Y-m-d H:i:s');
        $admin = '00000000-0000-0000-0000-000000000001';

        $stmt = $this->pdo->prepare("
            INSERT INTO libros (id,titulo,autor,isbn,anio_publicacion,descripcion,portada_url,portada_path,created_at,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            '33333333-3333-3333-3333-333333333333',
            'La comunidad del anillo', 'J. R. R. Tolkien', '9788445071438', 1954,
            'Primera parte de El Señor de los Anillos.',
            'https://covers.openlibrary.org/b/isbn/9788445071438-L.jpg',
            '/storage/covers/9788445071438_local.jpg',
            $now, $admin
        ]);
        $stmt->execute([
            '11111111-1111-1111-1111-111111111111',
            'El nombre del viento', 'Patrick Rothfuss', '9788401337208', 2007,
            'Kvothe narra su vida.',
            'https://covers.openlibrary.org/b/isbn/9788401337208-L.jpg',
            null,
            $now, $admin
        ]);
    }

    public function testSearchByQAndSort(): void
    {
        $result = $this->repo->search('tolkien', null, null, 'titulo', 'asc', 1, 20);
        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertCount(1, $result['items']);
        $this->assertSame(1, $result['total']);
        $book = $result['items'][0];
        $this->assertInstanceOf(Book::class, $book);
        $this->assertSame('J. R. R. Tolkien', $book->autor());
    }

    public function testPaginationLimit(): void
    {
        $r = $this->repo->search(null, null, null, 'titulo', 'asc', 1, 1);
        $this->assertCount(1, $r['items']);
        $this->assertSame(2, $r['total']);
    }
}
