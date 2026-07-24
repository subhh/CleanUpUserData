<?php

/**
 * Invalidates active sessions belonging to specific users.
 *
 * PHP version 7
 *
 * Copyright (C) Qcovery 2026.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuFind
 * @package  Console
 * @author   Johannes Schultze <schultze@effective-webwork.de>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development Wiki
 */

namespace CleanUpUserData\Session;

use Laminas\Config\Config;
use Symfony\Component\Console\Output\OutputInterface;
use VuFind\Db\Table\PluginManager as DbTablePluginManager;
use VuFind\Session\PluginManager as SessionPluginManager;

/**
 * Invalidates active sessions belonging to specific users.
 *
 * Session storage in VuFind is managed by the session ID. None of the
 * backends (File, Database, Memcache, Redis) stores a user ID
 * in addition to a session, so there is no simple lookup by user id.
 * The only way to find out is to go through the
 * stored sessions and check the serialized data for the
 * "Account" container that VuFind\Auth\Manager writes there.
 *
 * This only works for File and Database. Memcache does not offer a way to list its
 * keys. If [Session] secure = true is set, the data is encrypted with
 * a key that only exists in the user's browser cookie. In these settings
 * the invalidator only warns and skips the affected users.
 *
 * @category VuFind
 * @package  Console
 * @author   Johannes Schultze <schultze@effective-webwork.de>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development Wiki
 */
class SessionInvalidator
{
    /**
     * Session handler plugin manager, used to destroy a session.
     *
     * @var SessionPluginManager
     */
    protected $sessionPluginManager;

    /**
     * The [Session] section of config.ini.
     *
     * @var Config
     */
    protected $sessionConfig;

    /**
     * Database table plugin manager, used to read the "session" table.
     *
     * @var DbTablePluginManager
     */
    protected $dbTableManager;

    /**
     * Constructor
     *
     * @param SessionPluginManager $sessionPluginManager Session handler plugin manager
     * @param Config               $sessionConfig        [Session] section of config.ini
     * @param DbTablePluginManager $dbTableManager       Database table plugin manager
     */
    public function __construct(
        SessionPluginManager $sessionPluginManager,
        Config $sessionConfig,
        DbTablePluginManager $dbTableManager
    ) {
        $this->sessionPluginManager = $sessionPluginManager;
        $this->sessionConfig = $sessionConfig;
        $this->dbTableManager = $dbTableManager;
    }

    /**
     * Destroy any active sessions belonging to the given user IDs.
     *
     * @param int[]                $userIds User IDs to log out
     * @param OutputInterface|null $output   Optional console output, used to
     * report warnings when a session backend cannot be searched
     *
     * @return int Number of sessions that were destroyed
     */
    public function invalidateForUsers(array $userIds, OutputInterface $output = null): int
    {
        if (empty($userIds)) {
            return 0;
        }

        if (!empty($this->sessionConfig->secure)) {
            $this->logWarning(
                $output,
                'Skipping session invalidation: [Session] secure = true so the sessions cannot be inspected by this command.'
            );
            return 0;
        }

        $userIdsToLogOut = [];
        foreach ($userIds as $userId) {
            $userIdsToLogOut[$userId] = true;
        }

        $backendType = strtolower((string)($this->sessionConfig->type));

        if ($backendType === 'database' || $backendType === 'mysqlsession') {
            return $this->invalidateDatabaseSessions($userIdsToLogOut);
        }

        if ($backendType === 'file' || $backendType === 'filesession') {
            return $this->invalidateFileSessions($userIdsToLogOut);
        }

        $this->logWarning(
            $output,
            "Skipping session invalidation. Session backend '{$backendType}' does not support finding sessions by user."
        );
        return 0;
    }

    /**
     * Go through every row of the "session" database table, and destroy the
     * ones that belong to one of the users.
     *
     * @param array $userIdsToLogOut Array of user ids built by invalidateForUsers().
     *
     * @return int Number of sessions that were destroyed
     */
    protected function invalidateDatabaseSessions(array $userIdsToLogOut): int
    {
        $handler = $this->sessionPluginManager->get('database');
        $sessionTable = $this->dbTableManager->get('Session');

        $invalidatedCount = 0;
        foreach ($sessionTable->select([]) as $sessionRow) {
            $userId = $this->extractUserIdFromSessionData((string)$sessionRow['data']);
            if ($userId === null || !isset($userIdsToLogOut[$userId])) {
                continue;
            }

            $handler->destroy((string)$sessionRow['session_id']);
            $invalidatedCount++;
        }

        return $invalidatedCount;
    }

    /**
     * Go through every session file on disk, and destroy the ones that
     * belong to one of the users.
     *
     * @param array $userIdsToLogOut Lookup set built by invalidateForUsers().
     *
     * @return int Number of sessions that were destroyed
     */
    protected function invalidateFileSessions(array $userIdsToLogOut): int
    {
        $handler = $this->sessionPluginManager->get('file');
        $sessionFiles = glob($this->getFileSavePath() . '/sess_*');
        if ($sessionFiles === false) {
            $sessionFiles = [];
        }

        $invalidatedCount = 0;
        foreach ($sessionFiles as $sessionFile) {
            $sessionData = (string)file_get_contents($sessionFile);
            $userId = $this->extractUserIdFromSessionData($sessionData);
            if ($userId === null || !isset($userIdsToLogOut[$userId])) {
                continue;
            }

            $sessionId = substr(basename($sessionFile), strlen('sess_'));
            $handler->destroy($sessionId);
            $invalidatedCount++;
        }

        return $invalidatedCount;
    }

    /**
     * Returns the path to the directory where session files are stored.
     *
     * @return string
     */
    protected function getFileSavePath(): string
    {
        if (isset($this->sessionConfig->file_save_path)) {
            return $this->sessionConfig->file_save_path;
        }

        if (function_exists('sys_get_temp_dir')) {
            $tempDirectory = sys_get_temp_dir();
        } else {
            $tempDirectory = DIRECTORY_SEPARATOR . 'tmp';
        }

        return $tempDirectory . DIRECTORY_SEPARATOR . 'vufind_sessions';
    }

    /**
     * Get the user id from the session data.
     *
     * @param string $data Session data
     *
     * @return int|null
     */
    protected function extractUserIdFromSessionData(string $data): ?int
    {
        if ($data === '') {
            return null;
        }

        $decodedData = @unserialize($data, ['allowed_classes' => false]);
        if (is_array($decodedData) && isset($decodedData['Account'])) {
            $userId = $this->extractUserIdFromContainer($decodedData['Account']);
            if ($userId !== null) {
                return $userId;
            }
        }

        return $this->extractUserIdFromPhpFormat($data);
    }

    /**
     * Extract the user ID from the session data in PHP format.
     *
     * @param string $data Session data
     *
     * @return int|null
     */
    protected function extractUserIdFromPhpFormat(string $data): ?int
    {
        if (!preg_match('/(?:^|[;}])Account\|/', $data, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $accountDataOffset = $matches[0][1] + strlen($matches[0][0]);
        $accountData = substr($data, $accountDataOffset);
        $account = @unserialize($accountData, ['allowed_classes' => false]);

        return $this->extractUserIdFromContainer($account);
    }

    /**
     * Read the userId out of an "Account" session container.
     *
     * @param mixed $container Account container, or null
     *
     * @return int|null
     */
    protected function extractUserIdFromContainer($container): ?int
    {
        if (is_object($container)) {
            $container = (array)$container;
        }

        if (!is_array($container)) {
            return null;
        }

        if (isset($container['userId'])) {
            return (int)$container['userId'];
        }

        if (isset($container['storage']['userId'])) {
            return (int)$container['storage']['userId'];
        }

        return null;
    }

    /**
     * Write a warning to the console output.
     *
     * @param OutputInterface|null $output  Console output
     * @param string               $message Warning message
     *
     * @return void
     */
    protected function logWarning(?OutputInterface $output, string $message): void
    {
        if ($output !== null) {
            $output->writeln($message);
        }
    }
}
