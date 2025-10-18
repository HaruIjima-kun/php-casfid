<?php
declare(strict_types=1);

namespace App\Interfaces\Http\Controllers;

use App\Infrastructure\Http\{Request, Response};
use App\Infrastructure\Config\Config;
use App\Infrastructure\Persistence\PdoConnection;
use App\Infrastructure\Persistence\MySqlBookRepository;

final class BookController
{
    private MySqlBookRepository $repo;

    public function __construct(private Config $config)
    {
        $pdo = (new PdoConnection($config))->pdo();
        $this->repo = new MySqlBookRepository($pdo);
    }

    public function index(Request $req): void
    {
        $q       = $req->queryParam('q');
        $titulo  = $req->queryParam('titulo');
        $autor   = $req->queryParam('autor');
        $sort    = $req->queryParam('sort', $this->config->get('DEFAULT_SORT', 'titulo')) ?? 'titulo';
        $dir     = $req->queryParam('direction', $this->config->get('DEFAULT_DIRECTION', 'asc')) ?? 'asc';
        $page    = max(1, (int)($req->queryParam('page', '1') ?? '1'));
        $perPage = (int)($req->queryParam('per_page', (string)$this->config->get('PAGINATION_PER_PAGE', '20')) ?? '20');
        $perPage = max(1, min(100, $perPage));

        $result = $this->repo->search($q, $titulo, $autor, $sort, $dir, $page, $perPage);

        $data = array_map(fn($b) => $b->toArray(), $result['items']);

        Response::json($data, [
            'page'      => $page,
            'per_page'  => $perPage,
            'total'     => $result['total'],
            'sort'      => $sort,
            'direction' => strtolower($dir) === 'desc' ? 'desc' : 'asc',
        ]);
    }
}
