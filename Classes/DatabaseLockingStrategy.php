<?php
declare(strict_types=1);

namespace Vd\DatabaseLocks;


use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireException;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Locking Strategy based on Database
 */
class DatabaseLockingStrategy implements LockingStrategyInterface
{

    /**
     * The locking subject (e.g. "pagesection")
     */
    private string $subject;

    /**
     * The name of the lock
     */
    private string $name;

    /**
     * The name for the mutex lock
     */
    private string $mutexName;

    /**
     * The value of the lock
     */
    private string $value;

    /**
     * @var boolean TRUE if lock is acquired by this locker
     */
    private bool $isAcquired = false;

    /**
     * The max amount of time within the database for locking in seconds.
     */
    private int $ttl = 30;

    private string $tableName = 'tx_flock_lock';

    /**
     * @inheritdoc
     */
    public function __construct($subject)
    {

        if (isset($configuration['ttl'])) {
            $this->ttl = (int)$configuration['ttl'];
        }

        $keyPrefix = sha1($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] . '_DATABASE_LOCKING');
        $this->subject = $subject;
        $this->name = sprintf('%s:lock:name:%s', $keyPrefix, $subject);
        $this->mutexName = sprintf('%s:lock:mutex:%s', $keyPrefix, $subject);
        $this->value = uniqid();
    }

    /**
     * Releases lock automatically when instance is destroyed and release resources
     */
    public function __destruct()
    {
        $this->release();
    }

    /**
     * @inheritdoc
     */
    public static function getCapabilities()
    {
        return self::LOCK_CAPABILITY_EXCLUSIVE | self::LOCK_CAPABILITY_NOBLOCK;
    }

    /**
     * @inheritdoc
     */
    public static function getPriority()
    {
        $defaultPriority = 95;
        $configuration = $GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['database'] ?? null;
        if (is_array($configuration) && isset($configuration['priority'])) {
            $priority = (int)$configuration['priority'];
        } else {
            $priority = $defaultPriority;
        }
        return $priority;
    }

    /**
     * @inheritdoc
     */
    public function acquire($mode = self::LOCK_CAPABILITY_EXCLUSIVE)
    {
        if ($this->isAcquired) {
            return true;
        }
        if ($mode & self::LOCK_CAPABILITY_EXCLUSIVE) {
            if ($mode & self::LOCK_CAPABILITY_NOBLOCK) {
                // try to acquire the lock - non-blocking
                if (!$this->isAcquired = $this->lock(false)) {
                    throw new LockAcquireWouldBlockException('Could not acquire exclusive lock (non-blocking).',
                        1561445651);
                }
            } else {
                // try to acquire the lock - blocking
                // N.B. we do this in a loop because between
                // wait() and lock() another process may acquire the lock
                while (!$this->isAcquired = $this->lock()) {

                    // this blocks till the lock gets released or timeout is reached
                    if (!$this->wait()) {
                        throw new LockAcquireException('Could not acquire exclusive lock (blocking+exclusive).',
                            1561445710);
                    }
                }
            }
        } else {
            throw new LockAcquireException('Could not acquire lock due to insufficient capabilities.', 1561445737);
        }


        return $this->isAcquired;
    }

    /**
     * @inheritdoc
     */
    public function release()
    {
        if (!$this->isAcquired) {
            return true;
        }
        // Even in an error, the release is locked
        $this->unlockAndSignal();
        $this->isAcquired = false;
        return !$this->isAcquired;
    }

    /**
     * @inheritdoc
     */
    public function destroy()
    {
        $this->release();
    }

    /**
     * @inheritdoc
     */
    public function isAcquired()
    {
        return $this->isAcquired;
    }

    private function lock(bool $blocking = true): bool
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_flock_lock');

        try {
            // Try to insert a new lock
            $queryBuilder
                ->insert($this->tableName)
                ->values([
                    'name' => $this->name,
                    'value' => $this->value,
                    'ttl' => $this->ttl,
                ])
                ->execute();

            return true;
        } catch (UniqueConstraintViolationException $e) {
            // Lock already exists
            if (!$blocking) {
                // Non-blocking: check if the current request holds the lock
                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($this->tableName);
                $existingLock = $queryBuilder
                    ->select('value')
                    ->from($this->tableName)
                    ->where(
                        $queryBuilder->expr()->eq('name', $queryBuilder->createNamedParameter($this->name))
                    )
                    ->execute()
                    ->fetch();

                if ($existingLock && $existingLock['value'] === $this->value) {
                    return true;
                }
            }
            return false;
        }
    }

    private function wait(): bool
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($this->tableName);

        $blockingTo = max(1, $this->ttl); // Set a blocking timeout based on TTL
        $startTime = time();
        $endTime = $startTime + $blockingTo;

        while (time() < $endTime) {
            $existingLock = $queryBuilder
                ->select('value')
                ->from($this->tableName)
                ->where(
                    $queryBuilder->expr()->eq('name', $queryBuilder->createNamedParameter($this->name))
                )
                ->execute()
                ->fetch();

            if ($existingLock) {
                // If the lock is found, sleep for a short interval before checking again
                usleep(100);
            } else {
                // Lock has been released
                return false;
            }
        }

        // Timeout reached, lock still not released
        return false;
    }

    /**
     * Try to unlock and if succeeds, signal the mutex for others.
     * By using EVAL transactional behavior is enforced.
     */
    private function unlockAndSignal(): bool
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_myextension_lock');
        $queryBuilder
            ->delete($this->tableName)
            ->where(
                $queryBuilder->expr()->eq('name', $queryBuilder->createNamedParameter($this->name))
            )
            ->execute();

        return true;
    }
}
