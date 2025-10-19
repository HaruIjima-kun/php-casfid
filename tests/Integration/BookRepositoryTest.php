<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Infrastructure\Persistence\MySqlBookRepository;

final class BookRepositoryTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("
            CREATE TABLE libros (
              id TEXT PRIMARY KEY,
              titulo TEXT NOT NULL,
              autor TEXT NOT NULL,
              isbn TEXT NOT NULL UNIQUE,
              anio_publicacion INTEGER NULL,
              descripcion TEXT NULL,
              portada_url TEXT NULL,
              titulo_norm TEXT,
              autor_norm TEXT,
              created_at TEXT NOT NULL,
              created_by TEXT NOT NULL,
              updated_at TEXT NULL,
              updated_by TEXT NULL,
              deleted_at TEXT NULL,
              deleted_by TEXT NULL
            );
        ");
        $ins = $this->pdo->prepare("
            INSERT INTO libros (id,titulo,autor,isbn,anio_publicacion,descripcion,portada_url,
                                titulo_norm,autor_norm,created_at,created_by)
            VALUES (:id,:titulo,:autor,:isbn,:anio,:desc,:cover,:titnorm,:autnorm,datetime('now'), 'u1');
        ");
        $rows = [
            ['1','El prisma negro','Brent Weeks','9788490322383',2010,'','', 'el prisma negro','brent weeks'],
            ['2','La comunidad del anillo','J. R. R. Tolkien','9788445000663',1954,'','', 'la comunidad del anillo','j. r. r. tolkien'],
            ['3','El nombre del viento','Patrick Rothfuss','9788401337208',2007,'','', 'el nombre del viento','patrick rothfuss'],
        ];
        foreach ($rows as [$id,$t,$a,$i,$y,$d,$c,$tn,$an]) {
            $ins->execute([':id'=>$id,':titulo'=>$t,':autor'=>$a,':isbn'=>$i,':anio'=>$y,':desc'=>$d,':cover'=>$c,':titnorm'=>$tn,':autnorm'=>$an]);
        }
    }

    public function test_search_by_q_and_sort(): void
    {
        $repo = new MySqlBookRepository($this->pdo);
        $res  = $repo->search('tolkien', null, null, 'titulo', 'asc', 1, 20);
        $this->assertSame(1, $res['total']);
        $this->assertSame('9788445000663', $res['items'][0]->toArray()['isbn']);
    }

    public function test_pagination_limit(): void
    {
        $repo = new MySqlBookRepository($this->pdo);
        $res  = $repo->search(null, null, null, 'titulo', 'asc', 1, 2);
        $this->assertSame(3, $res['total']);
        $this->assertCount(2, $res['items']);
    }
}
