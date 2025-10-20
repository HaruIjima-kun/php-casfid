<?php
declare(strict_types=1);

namespace App\Infrastructure\Services;

use App\Infrastructure\Config\Config;
use App\Infrastructure\Storage\FileStorageInterface;
use GuzzleHttp\Client;

final class CoverService
{
    private Client $http;
    private string $storageDir;
    private string $baseUrl;
    private int $timeout;

    public function __construct(
        /** @phpstan-ignore-next-line */
        private Config $config,
        private FileStorageInterface $storage
    ) {
        $this->timeout = (int)($config->get('COVER_TIMEOUT', '10') ?? '10');
        $this->http = new Client([
            'timeout' => $this->timeout,
            'http_errors' => false,
        ]);
        $this->storageDir = rtrim((string)$config->get('COVER_STORAGE_PATH', '/var/www/html/storage/covers'), '/');
        $this->baseUrl    = rtrim((string)$config->get('COVER_BASE_URL', '/storage/covers'), '/');
    }

    /**
     * Descarga la portada y devuelve la ruta pública (p.ej., /storage/covers/978...jpg).
     * El nombre se basa en ISBN + hash de la URL para evitar colisiones.
     */
    public function download(string $remoteUrl, string $isbn): ?string
    {
        if ($remoteUrl === '') {
            return null;
        }

        $ext = $this->guessExtensionFromUrl($remoteUrl) ?? 'jpg';
        $name = $isbn !== '' ? $isbn : md5($remoteUrl);
        $fileName = sprintf('%s_%s.%s', $name, substr(md5($remoteUrl), 0, 8), $ext);

        $abs = "{$this->storageDir}/{$fileName}";
        $res = $this->http->get($remoteUrl, ['stream' => true]);

        if ($res->getStatusCode() !== 200) {
            return null;
        }

        $body = (string)$res->getBody();
        if ($body === '') {
            return null;
        }

        $this->storage->put($abs, $body);

        // Devuelve URL pública
        return "{$this->baseUrl}/{$fileName}";
    }

    /**
     * HEAD para verificar si la URL remota sigue viva (200).
     */
    public function isRemoteAlive(string $remoteUrl): bool
    {
        if ($remoteUrl === '') {
            return false;
        }
        $res = $this->http->head($remoteUrl);
        return $res->getStatusCode() === 200;
    }

    private function guessExtensionFromUrl(string $url): ?string
    {
        $p = parse_url($url, PHP_URL_PATH);
        if (!$p) return null;
        $ext = pathinfo($p, PATHINFO_EXTENSION);
        $ext = strtolower((string)$ext);
        if (in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) {
            return $ext === 'jpeg' ? 'jpg' : $ext;
        }
        return null;
    }
}
