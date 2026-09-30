<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use PDO;
use Throwable;

/**
 * Mirrors the application tenant context into PostgreSQL session variables that the RLS policies
 * read (see database/migrations/*_create_rls_functions.php):
 *
 *   app.tenant_id   — tenant whose rows are visible / writable
 *   app.user_id     — authenticated user (lets a user list their own memberships before a tenant is chosen)
 *   app.rls_bypass  — 'on' only inside TenantContext::bypass() for platform-level operations
 *
 * Variables are pushed to BOTH the write and read PDO so a future read-replica split cannot
 * silently query without tenant context. They are re-applied whenever the connection is
 * (re)established (TenancyServiceProvider listens for ConnectionEstablished).
 */
final class PostgresSessionVariables
{
    /** @var array{tenant: string, user: string, bypass: string} */
    private array $state = ['tenant' => '', 'user' => '', 'bypass' => 'off'];

    public function __construct(private readonly DatabaseManager $db) {}

    public function apply(?string $tenantId, ?string $userId, bool $bypass): void
    {
        $this->state = [
            'tenant' => $tenantId ?? '',
            'user' => $userId ?? '',
            'bypass' => $bypass ? 'on' : 'off',
        ];

        $this->push($this->db->connection());
    }

    public function reset(): void
    {
        $this->apply(null, null, false);
    }

    /** Called when the default connection is (re)established. */
    public function reapply(Connection $connection): void
    {
        if ($connection->getName() === $this->db->getDefaultConnection()) {
            $this->push($connection);
        }
    }

    private function push(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $sql = "select set_config('app.tenant_id', ?, false), set_config('app.user_id', ?, false), set_config('app.rls_bypass', ?, false)";
        $bindings = [$this->state['tenant'], $this->state['user'], $this->state['bypass']];

        foreach ($this->pdos($connection) as $pdo) {
            try {
                $statement = $pdo->prepare($sql);
                $statement->execute($bindings);
            } catch (Throwable $e) {
                // Inside an aborted transaction Postgres rejects every statement. The rollback that
                // follows restores the pre-transaction values, so swallow here rather than masking
                // the original exception. Outside a transaction this is a real failure.
                if ($connection->transactionLevel() > 0) {
                    Log::debug('Deferred tenant session sync (aborted transaction).', ['error' => $e->getMessage()]);

                    continue;
                }

                throw $e;
            }
        }
    }

    /** @return list<PDO> */
    private function pdos(Connection $connection): array
    {
        $write = $connection->getPdo();
        $read = $connection->getReadPdo();

        return $read === $write ? [$write] : [$write, $read];
    }
}
