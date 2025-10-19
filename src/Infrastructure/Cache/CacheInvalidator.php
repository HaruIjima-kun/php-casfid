<?php
declare(strict_types=1);

namespace App\Infrastructure\Cache;

use Redis;

/**
 * Invalidación simple de caché de respuestas.
 * En producción, mejor mantener un índice de claves por recurso para borrar O(1).
 */
final class CacheInvalidator
{
    public function __construct(private ?Redis $redis) {}

    public function invalidateAllListEndpoints(): void
    {
        if ($this->redis === null) return;
        $this->deleteByScan('resp:*api%2Fv1%2Flibros*'); // clave md5 → usamos prefijo 'resp:' y patrón amplio
        $this->deleteByScan('resp:*%2Flibros*');        // fallback por si cambia el orden
        $this->deleteByScan('resp:*%2Fhealth*');        // opcional, si cacheas health
        $this->deleteByScan('resp:*');                  // última red de seguridad para demo; restringe en real
    }

    public function invalidateBook(string $id): void
    {
        if ($this->redis === null) return;
        // No sabemos el query exacto, escaneamos por path
        // NOTA: la clave es hash de método|path|query → no hay path en plano.
        // Por simplicidad en esta demo, tiramos de prefijo general.
        $this->deleteByScan('resp:*');
    }

    private function deleteByScan(string $pattern): void
    {
        if ($this->redis === null) return;

        $it = null;
        do {
            $keys = $this->redis->scan($it, $pattern, 100);
            if (is_array($keys) && !empty($keys)) {
                $this->redis->del($keys);
            }
        } while ($it > 0);
    }
}
