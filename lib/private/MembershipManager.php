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

namespace OC;

use OC\Group\BackendGroup;
use OC\User\Account;
use OCP\AppFramework\Db\Entity;
use OCP\IConfig;
use OCP\IDBConnection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCP\DB\QueryBuilder\IQueryBuilder;

class MembershipManager {

	/**
	 * types of memberships in the group
	 */
	const MEMBERSHIP_TYPE_GROUP_USER = 0;
	const MEMBERSHIP_TYPE_GROUP_ADMIN = 1;

	/** @var IConfig */
	protected $config;

	/** @var IDBConnection */
	private $db;

	/** @var \OC\Group\GroupMapper */
	private $groupMapper;

	/** @var \OC\Group\GroupMapper */
	private $accountMapper;

	/** @var \OC\User\AccountTermMapper */
	private $termMapper;

	public function __construct(IDBConnection $db, IConfig $config,
								\OC\User\AccountMapper $accountMapper, \OC\User\AccountTermMapper $termMapper,
								\OC\Group\GroupMapper $groupMapper) {
		$this->db = $db;
		$this->config = $config;
		$this->accountMapper = $accountMapper;
		$this->groupMapper = $groupMapper;
		$this->termMapper = $termMapper;
	}

	private function getTableAlias() {
		return 'm';
	}

	private function getTableName() {
		return 'memberships';
	}

	/**
	 * Return backend group entities for given account (identified by user's uid)
	 *
	 * @param string $userId
	 *
	 * @return BackendGroup[]
	 */
	public function getUserBackendGroups($userId) {
		return $this->getBackendGroupsSqlQuery($userId, false, self::MEMBERSHIP_TYPE_GROUP_USER);
	}

	/**
	 * Return backend group entities for given account (identified by user's internal id)
	 *
	 * NOTE: Search by internal id is used to optimize access when
	 * group backend/account has already been instantiated and internal id is explicitly available
	 *
	 * @param int $accountId
	 *
	 * @return BackendGroup[]
	 */
	public function getUserBackendGroupsById($accountId) {
		return $this->getBackendGroupsSqlQuery($accountId, true, self::MEMBERSHIP_TYPE_GROUP_USER);
	}

	/**
	 * Return backend group entities for given account (identified by user's uid) of which
	 * the user is admin.
	 *
	 * @param string $userId
	 *
	 * @return BackendGroup[]
	 */
	public function getAdminBackendGroups($userId) {
		return $this->getBackendGroupsSqlQuery($userId, false, self::MEMBERSHIP_TYPE_GROUP_ADMIN);
	}

	/**
	 * Return user account entities for given group (identified with gid)
	 *
	 * @param string $gid
	 *
	 * @return Account[]
	 */
	public function getGroupUserAccounts($gid) {
		return $this->getAccountsSqlQuery($gid, false, self::MEMBERSHIP_TYPE_GROUP_USER);
	}

	/**
	 * Return user account entities for given group (identified with group's internal id)
	 *
	 * @param int $backendGroupId
	 *
	 * @return Account[]
	 */
	public function getGroupUserAccountsById($backendGroupId) {
		return $this->getAccountsSqlQuery($backendGroupId, true, self::MEMBERSHIP_TYPE_GROUP_USER);
	}

	/**
	 * Return admin account entities for given group (identified with gid)
	 *
	 * @param string $gid
	 *
	 * @return Account[]
	 */
	public function getGroupAdminAccounts($gid) {
		return $this->getAccountsSqlQuery($gid, false, self::MEMBERSHIP_TYPE_GROUP_ADMIN);

	}

	/**
	 * Return admin account entities for all backend groups
	 *	 *
	 * @return Account[]
	 */
	public function getAdminAccounts() {
		return $this->getAccountsSqlQuery(null, false, self::MEMBERSHIP_TYPE_GROUP_ADMIN);
	}

	/**
	 * Check whether given account (identified by user's uid) is user of
	 * the group (identified with gid)
	 *
	 * @param string $userId
	 * @param string $gid
	 *
	 * @return boolean
	 */
	public function isGroupUser($userId, $gid) {
		return $this->isGroupMember($userId, $gid, self::MEMBERSHIP_TYPE_GROUP_USER, false, false);
	}

	/**
	 * Check whether given account (identified by user's internal id) is user of
	 * the group (identified with group's internal id)
	 *
	 * NOTE: Search by internal id is used to optimize access when
	 * group backend/account has already been instantiated and internal id is explicitly available
	 *
	 * @param int $accountId
	 * @param int $backendGroupId
	 *
	 * @return boolean
	 */
	public function isGroupUserById($accountId, $backendGroupId) {
		return $this->isGroupMember($accountId, $backendGroupId, self::MEMBERSHIP_TYPE_GROUP_USER, true, true);
	}

	/**
	 * Check whether given account (identified by user's uid) is admin of
	 * the group (identified with gid)
	 *
	 * @param string $userId
	 * @param string $gid
	 *
	 * @return boolean
	 */
	public function isGroupAdmin($userId, $gid) {
		return $this->isGroupMember($userId, $gid, self::MEMBERSHIP_TYPE_GROUP_ADMIN, false, false);

	}

	/**
	 * Search for members which match the pattern and
	 * are users in the group (identified with gid)
	 *
	 * @param string $gid
	 * @param string $pattern
	 * @param integer $limit
	 * @param integer $offset
	 * @return Entity[]
	 */
	public function find($gid, $pattern, $limit, $offset) {
		return $this->searchQuery($gid, false, $pattern, $limit, $offset);
	}

	/**
	 * Search for members which match the pattern and
	 * are users in the backend group (identified with internal group id $backendGroupId)
	 *
	 * NOTE: Search by internal id instead of gid is used to optimize access when
	 * group backend has already been instantiated and $backendGroupId is explicitly available
	 *
	 * @param int $backendGroupId
	 * @param string $pattern
	 * @param integer $limit
	 * @param integer $offset
	 * @return Entity[]
	 */
	public function findById($backendGroupId, $pattern, $limit, $offset) {
		return $this->searchQuery($backendGroupId, true, $pattern, $limit, $offset);
	}

	/**
	 * Count members which match the pattern and
	 * are users in the group (identified with gid)
	 *
	 * @param string $gid
	 * @param string $pattern
	 * @param integer $limit
	 * @param integer $offset
	 *
	 * @return int
	 */
	public function count($gid, $pattern, $limit, $offset) {
		return count($this->searchQuery($gid, false, $pattern, $limit, $offset));
	}

	/**
	 * Add a group account (identified by user's internal id $accountId)
	 * to group (identified by group's internal id $backendGroupId).
	 *
	 * @param int $accountId - internal id of an account
	 * @param int $backendGroupId - internal id of backend group
	 *
	 * @return bool
	 */
	public function addGroupUser($accountId, $backendGroupId) {
		return $this->addGroupMember($accountId, $backendGroupId, self::MEMBERSHIP_TYPE_GROUP_USER);
	}

	/**
	 * Add a group admin account (identified by user's internal id $accountId)
	 * to group (identified by group's internal id $backendGroupId).
	 *
	 * @param int $accountId - internal id of an account
	 * @param int $backendGroupId - internal id of backend group
	 *
	 * @return bool
	 */
	public function addGroupAdmin($accountId, $backendGroupId) {
		return $this->addGroupMember($accountId, $backendGroupId, self::MEMBERSHIP_TYPE_GROUP_ADMIN);
	}

	/**
	 * Delete a group user (identified by user's uid)
	 * from group.
	 *
	 * @param string $userId
	 * @param string $gid group user is member of
	 * @return bool
	 */
	public function removeGroupUser($userId, $gid) {
		return $this->removeGroupMemberships($gid, $userId, [self::MEMBERSHIP_TYPE_GROUP_USER]);
	}

	/**
	 * Delete a group admin (identified by user's uid)
	 * from group.
	 *
	 * @param string $userId
	 * @param string $gid group user is member of
	 * @return bool
	 */
	public function removeGroupAdmin($userId, $gid) {
		return $this->removeGroupMemberships($gid, $userId, [self::MEMBERSHIP_TYPE_GROUP_ADMIN]);
	}

	/**
	 * Removes members from group (identified by group's gid),
	 * regardless of the role in the group.
	 *
	 * @param string $gid group user is member of
	 * @return bool
	 */
	public function removeGroupMembers($gid) {
		return $this->removeGroupMemberships($gid, null, [self::MEMBERSHIP_TYPE_GROUP_USER, self::MEMBERSHIP_TYPE_GROUP_ADMIN]);
	}

	/**
	 * Delete the memberships of user (identified by user's uid),
	 * regardless of the role in the group.
	 *
	 * @param string $userId
	 * @return bool
	 */
	public function removeMemberships($userId) {
		return $this->removeGroupMemberships(null, $userId, [self::MEMBERSHIP_TYPE_GROUP_USER, self::MEMBERSHIP_TYPE_GROUP_ADMIN]);
	}

	/**
	 * Check if the given user is member of the group with specific membership type
	 *
	 * @param string|int $userId
	 * @param string|int $groupId
	 * @param string $membershipType
	 * @param bool $isInternalGroupId
	 * @param bool $isInternalUserId
	 *
	 * @return boolean
	 */
	private function isGroupMember($userId, $groupId, $membershipType, $isInternalGroupId, $isInternalUserId) {
		$qb = $this->db->getQueryBuilder();
		$alias = $this->getTableAlias();
		$qb->select($qb->expr()->literal('1'))
			->from($this->getTableName(), $alias);

		$qb = $this->applyPredicates($qb, $userId, $groupId, $isInternalGroupId, $isInternalUserId);

		// Place predicate on membership_type
		$qb->andWhere($qb->expr()->eq($alias.'.membership_type', $qb->createNamedParameter($membershipType)));
		$resultArray = $qb->execute()->fetchAll();

		return empty($resultArray);
	}


	/**
	 * Add user to the group with specific membership type $membershipType.
	 *
	 * //FIXME: Can we use INSERT INTO ... SELECT .. FROM .. ?
	 *
	 * @param int $accountId - internal id of an account
	 * @param int $backendGroupId - internal id of backend group
	 * @param string $membershipType
	 *
	 * @return boolean
	 */
	private function addGroupMember($accountId, $backendGroupId, $membershipType) {
		$qb = $this->db->getQueryBuilder();

		$qb->insert($this->getTableName())
			->values([
				'backend_group_id' => $qb->createNamedParameter($backendGroupId),
				'account_id' => $qb->createNamedParameter($accountId),
				'membership_type' => $qb->createNamedParameter($membershipType),
			]);

		try {
			$qb->execute();
			return true;
		} catch (UniqueConstraintViolationException $e) {
			// TODO: hmmm raise some warning?
		}

		return false;
	}


	/*
	 * Removes users from the groups. If the predicate on a user or group is null, then it will apply
	 * removal to all the entries of that type.
	 *
	 * @param string|null $gid
	 * @param string|null $uid
	 * @param int[] $membershipTypeArray
	 *
	 * @return boolean
	 */
	private function removeGroupMemberships($gid, $uid, $membershipTypeArray) {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName(), $this->getTableAlias());

		if (!is_null($gid) && !is_null($uid)) {
			// Both $gid and $userId predicates are specified
			$qb = $this->applyPredicates($qb, $uid, $gid, false, false);
		} else if (!is_null($gid)) {
			// Group predicate $gid specified
			$qb = $this->applyGroupPredicates($qb, $gid, false);
		} else if (!is_null($uid)) {
			// User predicate $userId specified
			$qb = $this->applyUserPredicates($qb, $uid, false);
		} else {
			return false;
		}

		$qb->andWhere($qb->expr()->in('membership_type',
			$qb->createNamedParameter($membershipTypeArray, IQueryBuilder::PARAM_INT_ARRAY)));
		$qb->execute();

		return true;
	}

	/*
	 * Return backend group entities for given user id $userId (internal or gid) of which
	 * the user has specific membership type
	 *
	 * @param string|int $userId
	 * @param bool $isInternalUserId
	 * @param int $membershipType
	 *
	 * @return BackendGroup[]
	 */
	private function getBackendGroupsSqlQuery($userId, $isInternalUserId, $membershipType) {
		$qb = $this->db->getQueryBuilder();
		$alias = $this->getTableAlias();
		$qb->select(['g.id', 'g.group_id', 'g.display_name', 'g.backend'])
			->from($this->getTableName(), $alias)
			->innerJoin($alias, $this->groupMapper->getTableName(), 'g', $qb->expr()->eq('g.id', $alias.'.backend_group_id'));

		$qb = $this->applyUserPredicates($qb, $userId, $isInternalUserId);

		// Place predicate on membership_type
		$qb->andWhere($qb->expr()->eq($alias.'membership_type', $qb->createNamedParameter($membershipType)));

		$stmt = $qb->execute();
		$groups = [];
		while($attributes = $stmt->fetch()){
			// Map attributes in array to BackendGroup
			$groups[] = $this->groupMapper->mapRowToEntity($attributes);
		}

		$stmt->closeCursor();

		return $groups;
	}

	/**
	 * Return account entities for given group id $groupId (internal or gid) of which
	 * the accounts have specific membership type. If group id is not specified, it will
	 * return result for all groups.
	 *
	 * @param string|int|null $groupId
	 * @param bool $isInternalGroupId
	 * @param int $membershipType
	 * @return Account[]
	 */
	private function getAccountsSqlQuery($groupId, $isInternalGroupId, $membershipType) {
		$qb = $this->db->getQueryBuilder();
		$alias = $this->getTableAlias();
		$qb->select(['a.id','a.user_id', 'a.lower_user_id', 'a.display_name', 'a.email', 'a.last_login', 'a.backend', 'a.state', 'a.quota', 'a.home'])
			->from($this->getTableName(), 'm')
			->innerJoin('m', $this->accountMapper->getTableName(), 'a', $qb->expr()->eq('a.id', 'm.account_id'));

		if (!is_null($groupId)) {
			$qb = $this->applyGroupPredicates($qb, $groupId, $isInternalGroupId);
		}

		// Place predicate on membership_type
		$qb->andWhere($qb->expr()->eq($alias.'.membership_type', $qb->createNamedParameter($membershipType)));

		return $this->getAccountsQuery($qb);
	}

	/**
	 * @param IQueryBuilder $qb
	 * @return Account[]
	 */
	private function getAccountsQuery($qb) {
		$stmt = $qb->execute();
		$accounts = [];
		while($attributes = $stmt->fetch()){
			// Map attributes in array to Account
			$accounts[] = $this->accountMapper->mapRowToEntity($attributes);
		}

		$stmt->closeCursor();
		return $accounts;
	}

	/**
	 * Search for members which match the pattern and
	 * are users in the group - identified with group id $groupId (internal or gid)
	 *
	 * @param string|int $groupId
	 * @param bool $isInternalGroupId
	 * @param string $pattern
	 * @param integer $limit
	 * @param integer $offset
	 *
	 * @return Account[]
	 */
	private function searchQuery($groupId, $isInternalGroupId, $pattern, $limit = null, $offset = null) {
		$alias = $this->getTableAlias();
		$allowMedialSearches = $this->config->getSystemValue('accounts.enable_medial_search', true);
		if ($allowMedialSearches) {
			$parameter = '%' . $this->db->escapeLikeParameter($pattern) . '%';
			$loweredParameter = '%' . $this->db->escapeLikeParameter(strtolower($pattern)) . '%';
		} else {
			$parameter = $this->db->escapeLikeParameter($pattern) . '%';
			$loweredParameter = $this->db->escapeLikeParameter(strtolower($pattern)) . '%';
		}

		// Optimize query if patter is an empty string, and we can retrieve information with faster query
		$emptyPattern = empty($pattern) ? true : false;

		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias('DISTINCT a.id', 'id')
			->addSelect(['a.user_id', 'a.lower_user_id', 'a.display_name', 'a.email', 'a.last_login', 'a.backend', 'a.state', 'a.quota', 'a.home'])
			->from($this->getTableName(), 'a')
			->innerJoin('a', $this->getTableName(), $alias, $qb->expr()->eq('a.id', $alias.'.account_id'));

		if ($emptyPattern) {
			$qb->leftJoin('a', $this->termMapper->getTableName(), 't', $qb->expr()->eq('a.id', 't.account_id'));
		}

		$qb = $this->applyGroupPredicates($qb, $groupId, $isInternalGroupId);

		if (!$emptyPattern) {
			// Non empty pattern means that we need to set predicates on parameters
			// and just fetch all users
			$qb->andwhere($qb->expr()->like('a.lower_user_id', $qb->createNamedParameter($loweredParameter)))
				->orWhere($qb->expr()->iLike('a.display_name', $qb->createNamedParameter($parameter)))
				->orWhere($qb->expr()->iLike('a.email', $qb->createNamedParameter($parameter)))
				->orWhere($qb->expr()->like('t.term', $qb->createNamedParameter($loweredParameter)));
		}

		// Place predicate on membership_type
		$qb->andWhere($qb->expr()->eq($alias.'membership_type', $qb->createNamedParameter(self::MEMBERSHIP_TYPE_GROUP_USER)));

		// Order by display_name so we can use limit and offset
		$qb->orderBy('display_name');

		if (!is_null($offset)) {
			$qb->setFirstResult($offset);
		}

		if (!is_null($limit)) {
			$qb->setMaxResults($limit);
		}

		/** @var Account[] $accounts */
		$accounts = [];
		$stmt = $qb->execute();
		while($row = $stmt->fetch()){
			$accounts[] = $this->accountMapper->mapRowToEntity($row);
		}

		return $accounts;
	}

	/**
	 * @param IQueryBuilder $qb
	 * @param string|int $userId
	 * @param string|int $groupId
	 * @param bool $isInternalGroupId
	 * @param bool $isInternalUserId
	 * @return IQueryBuilder
	 */
	private function applyPredicates($qb, $userId, $groupId, $isInternalGroupId, $isInternalUserId) {
		// Adjust the query depending on availability of accountId and $backendGroupId
		// to have optimized access
		$alias = $this->getTableAlias();
		if ($isInternalGroupId && $isInternalUserId) {
			// No need to JOIN any tables, we already have all information required
			// Apply predicate on backend_group_id and account_id in memberships table
			$qb->where($qb->expr()->eq($alias.'.backend_group_id',
				$qb->createNamedParameter($groupId)));
			$qb->andWhere($qb->expr()->eq($alias.'.account_id',
				$qb->createNamedParameter($userId)));
		} else if ($isInternalGroupId) {
			// We need to join with accounts table, since we miss information on accountId
			$qb->innerJoin($alias, $this->accountMapper->getTableName(),
				'a', $qb->expr()->eq('a.id', $alias.'.account_id'));

			// Apply predicate on user_id in accounts table
			$qb->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)));

			// Apply predicate on backend_group_id in memberships table
			$qb->andWhere($qb->expr()->eq($alias.'.backend_group_id',
				$qb->createNamedParameter($groupId)));
		} else if ($isInternalUserId) {
			// We need to join with backend group table, since we miss information on backendGroupId
			$qb->innerJoin($alias, $this->groupMapper->getTableName(),
				'g', $qb->expr()->eq('g.id', $alias.'.backend_group_id'));

			// Apply predicate on group_id in backend groups table
			$qb->where($qb->expr()->eq('g.group_id', $qb->createNamedParameter($groupId)));

			// Apply predicate on account_id in memberships table
			$qb->andWhere($qb->expr()->eq($alias.'.account_id',
				$qb->createNamedParameter($userId)));
		}
		return $qb;
	}

	/**
	 * @param IQueryBuilder $qb
	 * @param int $id
	 * @param bool $isInternalId
	 * @return IQueryBuilder
	 */
	private function applyUserPredicates($qb, $id, $isInternalId) {
		// Adjust the query depending on availability of accountId
		// to have optimized access
		$alias = $this->getTableAlias();
		if ($isInternalId) {
			// Apply predicate on account_id in memberships table
			$qb->where($qb->expr()->eq($alias.'account_id', $qb->createNamedParameter($id)));
		} else {
			// We need to join with accounts table, since we miss information on accountId
			$qb->innerJoin($alias, $this->accountMapper->getTableName(), 'a', $qb->expr()->eq('a.id', $alias.'.account_id'));

			// Apply predicate on user_id in accounts table
			$qb->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($id)));
		}
		return $qb;
	}

	/**
	 * @param IQueryBuilder $qb
	 * @param string $id
	 * @param bool $isInternalId
	 * @return IQueryBuilder
	 */
	private function applyGroupPredicates($qb, $id, $isInternalId) {
		// Adjust the query depending on availability of accountId
		// to have optimized access
		$alias = $this->getTableAlias();
		if ($isInternalId) {
			// Apply predicate on backend_group_id in memberships table
			$qb->where($qb->expr()->eq($alias.'backend_group_id', $qb->createNamedParameter($id)));
		} else {
			// We need to join with backend group table, since we miss information on backendGroupId
			$qb->innerJoin($alias, $this->groupMapper->getTableName(), 'g', $qb->expr()->eq('g.id', $alias.'.backend_group_id'));

			// Apply predicate on group_id in backend groups table
			$qb->where($qb->expr()->eq('a.group_id', $qb->createNamedParameter($id)));
		}
		return $qb;
	}
}