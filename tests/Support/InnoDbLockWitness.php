<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\ConnectionInterface;
use PDO;

/** Observe actual child-to-parent InnoDB waits on MariaDB or MySQL 8. */
final class InnoDbLockWitness
{
    /** @param list<int> $children */
    public static function waiting(ConnectionInterface $db, array $children, int $parent, ?string $table = null, ?string $index = null): int
    {
        if (count($children) !== 2 || $children[0] === $children[1]) {
            throw new \InvalidArgumentException('Exactly two distinct child connections are required.');
        }
        $version = (string) $db->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
        if (stripos($version, 'mariadb') !== false) {
            $sql = 'SELECT COUNT(DISTINCT t.trx_mysql_thread_id) AS n FROM information_schema.INNODB_LOCK_WAITS w '
                .'JOIN information_schema.INNODB_TRX t ON t.trx_id = w.requesting_trx_id '
                .'JOIN information_schema.INNODB_TRX b ON b.trx_id = w.blocking_trx_id '
                .'JOIN information_schema.INNODB_LOCKS l ON l.lock_id = w.requested_lock_id '
                .'WHERE t.trx_mysql_thread_id IN (?, ?) AND b.trx_mysql_thread_id = ?';
            $params = [...$children, $parent];
            if ($table !== null) {
                $sql .= ' AND l.lock_table LIKE ?';
                $params[] = '%'.$table.'%';
            }
            if ($index !== null) {
                $sql .= ' AND l.lock_index LIKE ?';
                $params[] = '%'.$index.'%';
            }
        } else {
            // MySQL 8 removed the MariaDB information_schema lock tables.
            // The local test account needs SELECT on these three read-only
            // performance_schema tables; a permission error fails the test.
            $sql = 'SELECT COUNT(DISTINCT child.PROCESSLIST_ID) AS n FROM performance_schema.data_lock_waits w '
                .'JOIN performance_schema.data_locks requested ON requested.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID '
                .'JOIN performance_schema.data_locks blocking ON blocking.ENGINE_LOCK_ID = w.BLOCKING_ENGINE_LOCK_ID '
                .'JOIN performance_schema.threads child ON child.THREAD_ID = requested.THREAD_ID '
                .'JOIN performance_schema.threads owner ON owner.THREAD_ID = blocking.THREAD_ID '
                .'WHERE child.PROCESSLIST_ID IN (?, ?) AND owner.PROCESSLIST_ID = ? '
                .'AND requested.OBJECT_SCHEMA = DATABASE()';
            $params = [...$children, $parent];
            if ($table !== null) {
                $sql .= ' AND requested.OBJECT_NAME = ?';
                $params[] = $table;
            }
            if ($index !== null) {
                $sql .= ' AND requested.INDEX_NAME LIKE ?';
                $params[] = '%'.$index.'%';
            }
        }

        return (int) $db->selectOne($sql, $params)->n;
    }
}
