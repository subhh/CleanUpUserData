<?php

    /**
     * SessionInvalidator test.
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
     * @package  Tests
     * @author   Johannes Schultze <schultze@effective-webwork.de>
     * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
     * @link     https://vufind.org/wiki/development:testing:unit_tests Wiki
     */

namespace CleanUpUserDataTest\Session;

use CleanUpUserData\Session\SessionInvalidator;
use Laminas\Config\Config;
use Symfony\Component\Console\Output\BufferedOutput;
use VuFind\Session\HandlerInterface;

/**
 * SessionInvalidator test.
 *
 * @category VuFind
 * @package  Tests
 * @author   Johannes Schultze <schultze@effective-webwork.de>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:testing:unit_tests Wiki
 */
class SessionInvalidatorTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Build a session payload using PHP's "name|serialized" format.
     *
     * @param int $userId User ID stored in the Account container
     *
     * @return string
     */
    protected function buildPhpFormatPayload(int $userId): string
    {
        $account = serialize(new \Laminas\Stdlib\ArrayObject(['userId' => $userId, 'userDetails' => null]));
        $decoy = serialize(new \Laminas\Stdlib\ArrayObject(['bar' => 'baz']));
        return "Account|{$account}Foo|{$decoy}";
    }

    /**
     * Build a session payload using the "php_serialize" format.
     *
     * @param int $userId User ID stored in the Account container
     *
     * @return string
     */
    protected function buildPhpSerializePayload(int $userId): string
    {
        return serialize([
            'Account' => new \Laminas\Stdlib\ArrayObject(['userId' => $userId, 'userDetails' => null]),
            'Foo' => new \Laminas\Stdlib\ArrayObject(['bar' => 'baz']),
        ]);
    }

    /**
     * Test that only the session belonging to the affected user is invalidated
     * when using the Database session backend.
     *
     * @return void
     */
    public function testDatabaseBackendDestroysMatchingSession()
    {
        $rows = [
            ['session_id' => 'sid-match', 'data' => $this->buildPhpFormatPayload(42)],
            ['session_id' => 'sid-other', 'data' => $this->buildPhpFormatPayload(99)],
            ['session_id' => 'sid-empty', 'data' => ''],
        ];

        $sessionTable = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['select'])
            ->getMock();
        $sessionTable->method('select')->willReturn($rows);

        $dbTableManager = $this->createMock(\VuFind\Db\Table\PluginManager::class);
        $dbTableManager->method('get')->with('Session')->willReturn($sessionTable);

        $handler = $this->createMock(HandlerInterface::class);
        $handler->expects($this->once())->method('destroy')->with('sid-match');

        $sessionPluginManager = $this->createMock(\VuFind\Session\PluginManager::class);
        $sessionPluginManager->method('get')->with('database')->willReturn($handler);

        $config = new Config(['type' => 'Database']);
        $invalidator = new SessionInvalidator($sessionPluginManager, $config, $dbTableManager);

        $destroyed = $invalidator->invalidateForUsers([42]);
        $this->assertEquals(1, $destroyed);
    }

    /**
     * Test that only the session file belonging to the affected user is
     * invalidated when using the File session backend.
     *
     * @return void
     */
    public function testFileBackendDestroysMatchingSession()
    {
        $path = sys_get_temp_dir() . '/cleanupuserdata_test_' . uniqid();
        mkdir($path);
        try {
            file_put_contents(
                $path . '/sess_match123',
                $this->buildPhpSerializePayload(7)
            );
            file_put_contents(
                $path . '/sess_other456',
                $this->buildPhpSerializePayload(8)
            );

            $handler = $this->createMock(HandlerInterface::class);
            $handler->expects($this->once())->method('destroy')->with('match123');

            $sessionPluginManager = $this->createMock(\VuFind\Session\PluginManager::class);
            $sessionPluginManager->method('get')->with('file')->willReturn($handler);

            $dbTableManager = $this->createMock(\VuFind\Db\Table\PluginManager::class);

            $config = new Config(['type' => 'File', 'file_save_path' => $path]);
            $invalidator = new SessionInvalidator($sessionPluginManager, $config, $dbTableManager);

            $destroyed = $invalidator->invalidateForUsers([7]);
            $this->assertEquals(1, $destroyed);
        } finally {
            array_map('unlink', glob($path . '/*'));
            rmdir($path);
        }
    }

    /**
     * Test that encrypted sessions ([Session] secure = true) are skipped.
     *
     * @return void
     */
    public function testSecureSessionsAreSkippedWithWarning()
    {
        $sessionPluginManager = $this->createMock(\VuFind\Session\PluginManager::class);
        $sessionPluginManager->expects($this->never())->method('get');
        $dbTableManager = $this->createMock(\VuFind\Db\Table\PluginManager::class);
        $dbTableManager->expects($this->never())->method('get');

        $config = new Config(['type' => 'Database', 'secure' => true]);
        $invalidator = new SessionInvalidator($sessionPluginManager, $config, $dbTableManager);

        $output = new BufferedOutput();
        $destroyed = $invalidator->invalidateForUsers([42], $output);

        $this->assertEquals(0, $destroyed);
        $this->assertStringContainsString('secure', $output->fetch());
    }

    /**
     * Test that an unsupported backend (e.g. Memcache) is skipped.
     *
     * @return void
     */
    public function testUnsupportedBackendLogsWarningAndSkips()
    {
        $sessionPluginManager = $this->createMock(\VuFind\Session\PluginManager::class);
        $sessionPluginManager->expects($this->never())->method('get');
        $dbTableManager = $this->createMock(\VuFind\Db\Table\PluginManager::class);
        $dbTableManager->expects($this->never())->method('get');

        $config = new Config(['type' => 'Memcache']);
        $invalidator = new SessionInvalidator($sessionPluginManager, $config, $dbTableManager);

        $output = new BufferedOutput();
        $destroyed = $invalidator->invalidateForUsers([42], $output);

        $this->assertEquals(0, $destroyed);
        $this->assertStringContainsString('memcache', $output->fetch());
    }

    /**
     * Test that an empty user ID list is a no-op.
     *
     * @return void
     */
    public function testEmptyUserIdsIsANoOp()
    {
        $sessionPluginManager = $this->createMock(\VuFind\Session\PluginManager::class);
        $sessionPluginManager->expects($this->never())->method('get');
        $dbTableManager = $this->createMock(\VuFind\Db\Table\PluginManager::class);
        $dbTableManager->expects($this->never())->method('get');

        $config = new Config(['type' => 'Database']);
        $invalidator = new SessionInvalidator($sessionPluginManager, $config, $dbTableManager);

        $this->assertEquals(0, $invalidator->invalidateForUsers([]));
    }
}
