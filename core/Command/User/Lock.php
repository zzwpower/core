<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
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

namespace OC\Core\Command\User;

use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use OCP\Files\IRootFolder;
use OC\Core\Command\Base;

class Lock extends Base {
	/** @var IUserManager */
	protected $userManager;

	/** @var IRootFolder */
	protected $rootFolder;

	/**
	 * @param IUserManager $userManager
	 * @param IRootFolder $rootFolder
	 */
	public function __construct(IUserManager $userManager, IRootFolder $rootFolder) {
		$this->userManager = $userManager;
		$this->rootFolder = $rootFolder;
		parent::__construct();
	}

	protected function configure() {
		$this
			->setName('user:lock')
			->setDescription('locks the current user\'s storage')
			->addArgument(
				'uid',
				InputArgument::REQUIRED,
				'the username'
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$user = $this->userManager->get($input->getArgument('uid'));
		if (is_null($user)) {
			$output->writeln('<error>User does not exist</error>');
			return;
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());

		$userFolder->lock(\OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE);

		$output->writeln('<info>The specified user storage is now locked, hit ctrl+c to unlock</info>');
		while (!$this->hasBeenInterrupted()) {
			sleep(1);
		}

		$output->writeln('<info>The specified user storage is now unlocked</info>');
		$userFolder->unlock(\OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE);
	}
}
