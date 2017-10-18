<?php
/**
 * @author Piotr Mrowczynski <piotr@owncloud.com>
 *
 * @copyright Copyright (c) 2017, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace Test;

use OC\Group\BackendGroup;
use OC\Group\GroupMapper;
use OC\User\Account;
use OC\User\AccountMapper;
use OC\MembershipManager;
use OCP\IConfig;
use OCP\IDBConnection;
use Test\TestCase;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Class MembershipManagerTest
 *
 * @group DB
 *
 * @package Test
 */
class MembershipManagerTest extends TestCase {

	/** @var IConfig | \PHPUnit_Framework_MockObject_MockObject */
	protected $config;

	/** @var IDBConnection */
	protected $connection;

	/** @var MembershipManager */
	protected $manager;

	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		$groupMapper = \OC::$server->getGroupMapper();
		$accountMapper = \OC::$server->getAccountMapper();

		// create test groups and accounts
		for ($i = 1; $i <= 4; $i++) {
			$backendGroup = $groupMapper->getGroup("testgroup$i");
			$account = $accountMapper->find("testaccount$i");
			if (!is_null($backendGroup)) {
				$groupMapper->delete($backendGroup);
			}
			if ($account) {
				$accountMapper->delete($account);
			}

			$backendGroup = new BackendGroup();
			$backendGroup->setGroupId("testgroup$i");
			$backendGroup->setDisplayName("TestGroup$i");
			$backendGroup->setBackend(self::class);

			$groupMapper->insert($backendGroup);

			$account = new Account();
			$account->setUserId("TestUser$i");
			$account->setDisplayName("Test User $i");
			$account->setEmail("test$i@user.com");
			$account->setBackend(self::class);
			$account->setHome("/foo/TestUser$i");

			$accountMapper->insert($account);

			$accountMapper->setTermsForAccount($account->getId(), ["Term $i A","Term $i B","Term $i C"]);
		}
	}

	public function setUp() {
		parent::setUp();

		$this->config = $this->createMock(IConfig::class);

		$this->connection = \OC::$server->getDatabaseConnection();

		$this->manager = new MembershipManager(
			$this->connection,
			$this->config,
			\OC::$server->getAccountMapper(),
			\OC::$server->getAccountTermMapper(),
			\OC::$server->getGroupMapper()
		);
	}

	public static function tearDownAfterClass () {
		\OC::$server->getDatabaseConnection()->executeQuery('DELETE FROM `*PREFIX*backend_groups`');
		\OC::$server->getDatabaseConnection()->executeQuery('DELETE FROM `*PREFIX*accounts`');
		\OC::$server->getDatabaseConnection()->executeQuery('DELETE FROM `*PREFIX*memberships`');
		parent::tearDownAfterClass();
	}

	/**
	 * Test that deleting group should result in deleting all users, and violating that
	 * should rise exception
	 */
	public function testDeleteFailed() {
		//TODO: Test for getting exception with failed foreign key constrains
	}

	/**
	 * TODO
	 */
	public function testGetAdminAccounts() {
		$result = $this->manager->getAdminAccounts();
		$this->assertEmpty($result);
	}
}