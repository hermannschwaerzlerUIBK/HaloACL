<?php
/*
 * Copyright (C) Vulcan Inc.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program.If not, see <http://www.gnu.org/licenses/>.
 *
 */

/**
 * @file
 * @ingroup HaloACL_Storage
 *
 * This file provides the access to the SQL database tables that are
 * used by HaloACL.
 *
 * @author Thomas Schweitzer
 *
 */

global $haclgIP;
require_once("$haclgIP/storage/HACL_StorageSQL.php");

/**
 * This class encapsulates all methods that care about the database tables of
 * the HaloACL extension. This is the implementation for LDAP access which reuses
 * many parts of the SQL database.
 *
 */
class HACLStorageLDAP extends HACLStorageSQL {

	//--- Constants ---
	
	const LDAP_MAPPING_BASE = 1000000;
	const GROUP_TYPE_LDAP = 'LDAP';

	//-- Search modes in searchLDAPGroups()
	const AS_MEMBER = 1;
	const BY_NAME = 2;
	const BY_DN = 3; // search by Distinguished Name
	const BY_NAME_FILTER = 4;
	
	
	//--- Fields ---
	
	private $mLDAPBound = false;  // bool: true, if $wgAuth is bound to the LDAP server 
	
	
	//--- Public methods ---

	/**
	 * Initializes the database tables of the HaloACL extensions with LDAP support.
	 * These are:
	 * - halo_acl_ldap_group_id_map:
	 * 		stores the mapping from LDAP distinguished names to numeric group IDs.
	 *
	 */
	public function initDatabaseTables($verbose = true) {

		parent::initDatabaseTables($verbose);

		$db = wfGetDB( DB_MASTER );

		HACLDBHelper::reportProgress("Setting up HaloACL for LDAP ...\n",$verbose);

		// halo_acl_ldap_group_id_map:
		//		stores the mapping from LDAP distinguished names to numeric group
		//		IDs.
		$table = $db->tableName('halo_acl_ldap_group_id_map');

		HACLDBHelper::setupTable($table, array(
            'id' 	=> 'INT(8) NOT NULL AUTO_INCREMENT',
            'dn' 	=> 'VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL'),
		$db, $verbose, "id,dn");
		HACLDBHelper::reportProgress("   ... done!\n",$verbose, "id,dn");

		return true;

	}

	public function dropDatabaseTables($verbose = true) {

		parent::dropDatabaseTables($verbose);

		global $wgDBtype;
		$verbose = true;

		HACLDBHelper::reportProgress("Deleting all database content and tables generated by HaloACL for LDAP...\n\n",$verbose);
		$db = wfGetDB( DB_MASTER );
		$tables = array('halo_acl_ldap_group_id_map');
		foreach ($tables as $table) {
			$name = $db->tableName($table);
			$db->query('DROP TABLE' . ($wgDBtype=='postgres'?'':' IF EXISTS'). $name, 'HACLStorageLDAP::dropDatabaseTables');
			HACLDBHelper::reportProgress(" ... dropped table $name.\n", $verbose);
		}
		HACLDBHelper::reportProgress("All data removed successfully.\n",$verbose);
	}


	/***************************************************************************
	 *
	 * Functions for groups
	 *
	 **************************************************************************/

	/**
	 * Returns the name of the group with the ID $groupID.
	 *
	 * @param int $groupID
	 * 		ID of the group whose name is requested
	 *
	 * @return string
	 * 		Name of the group with the given ID or <null> if there is no such
	 * 		group defined in the database.
	 */
	public function groupNameForID($groupID) {

		$groupDN = $this->getGroupDNForID($groupID);
		if ($groupDN === null) {
			// no group for this ID
			return null;
		}
		
		// get the cn i.e. name of the group
		$groups = $this->searchLDAPGroups($groupDN, self::BY_DN);

		global $wgLDAPGroupNameAttribute;
		$nameAttr = $wgLDAPGroupNameAttribute[$_SESSION['wsDomain']];
		return empty($groups) ? null : $groups[0][$nameAttr];
	}

	/**
	 * Saves the given group in the database.
	 * However, with an LDAP server attached, only "local" groups can be saved, 
	 * i.e. groups that are created by a wiki user. LDAP groups can not be saved.
	 * The IDs of LDAP groups are greater than LDAP_MAPPING_BASE.
	 * Furthermore, a group can not be saved, if a group with the same name already
	 * exists in LDAP.
	 *
	 * @param HACLGroup $group
	 * 		This object defines the group that will be saved.
	 *
	 * @throws
	 * 		HACLStorageException (CANT_SAVE_LDAP_GROUP)
	 * 			if $group is an LDAP group
	 * 		HACLStorageException (SAME_GROUP_IN_LDAP)
	 * 			if there already is an LDAP group with the same name
	 *
	 */
	public function saveGroup(HACLGroup $group) {
		// Throw exception if an LDAP group is saved
		if ($group->getGroupID() >= self::LDAP_MAPPING_BASE) {
			throw new HACLStorageException(HACLStorageException::CANT_SAVE_LDAP_GROUP,
										   $group->getGroupName());
		}
		// Check if there is an LDAP group with the same name
		$groups = $this->searchLDAPGroups($group->getGroupName(), self::BY_NAME);
		if (!empty($groups)) {
			throw new HACLStorageException(HACLStorageException::SAME_GROUP_IN_LDAP,
										   $group->getGroupName());
		}
		
		// Store the group in the normal database
		parent::saveGroup($group);
		
	}

	/**
	 * Retrieves all top level groups from the database and from LDAP. If groups have
	 * the same name in the DB and in LDAP, only the one from LDAP is returned. 
	 *
	 * @return Array
	 * 		Array of Group Objects
	 *
	 */
	public function getGroups() {
		global $wgAuth, $wgLDAPGroupObjectclass, $wgLDAPGroupAttribute;
		
		// Get all group DNs from LDAP server
		$base = $wgAuth->getBaseDN( GROUPDN );
		$groupClass = $wgLDAPGroupObjectclass[$_SESSION['wsDomain']];
		$filter = "(objectclass=$groupClass)";
		$entries = $this->ldapQuery($base, $filter, array('dn'));
		
		$rootGroups = array();
		foreach ($entries as $e) {
			$dn = $e['dn'];
			$rootGroups[$dn] = true;
		}
		
		$memberAttr = $wgLDAPGroupAttribute[$_SESSION['wsDomain']];
		// get the members of all groups
		foreach ($rootGroups as $groupDN => $val) {
			$groupMembers = $this->ldapQuery($groupDN, '(objectclass=*)', array($memberAttr));
			// remove all member groups from the root groups
			$groupMembers = $groupMembers[0];
			$groupMembers = $groupMembers[$memberAttr];
			
			array_shift($groupMembers);
			foreach ($groupMembers as $gm) {
				unset($rootGroups[$gm]);
			}
		}
		
	
		// Get groups from LDAP
		global $wgLDAPGroupNameAttribute;
		$nameAttr = $wgLDAPGroupNameAttribute[$_SESSION['wsDomain']];
		$groups = array();
		$groupNames = array();
		foreach ($rootGroups as $rootDN => $val) {
			$ldapGroup = $this->searchLDAPGroups($rootDN, self::BY_DN);

			foreach ($ldapGroup as $group) {
				$g = $this->createGroupFromLDAP($group);
				$groups[] = $g;
				$groupNames[] = $g->getGroupName();
			}
		}
		
		// Get groups from the database
		$dbGroups = parent::getGroups();
		foreach ($dbGroups as $group) {
			if (array_search($group->getGroupName(), $groupNames) === false) {
				// There is no LDAP group with the same name
				// => add the DB group
				$groups[] = $group;
			}
		}

		return $groups;
	}


	/**
	 * Retrieves the description of the group with the name $groupName from
	 * the LDAP server or the database. If both LDAP and the DB contain a group
	 * with the same name, the one from LDAP is returned.
	 *
	 * @param string $groupName
	 * 		Name of the requested group.
	 *
	 * @return HACLGroup
	 * 		A new group object or <null> if there is no such group in the
	 * 		database or on the LDAP server.
	 *
	 */
	public function getGroupByName($groupName) {
		// search for the group on the LDAP server
		$group = $this->searchLDAPGroups($groupName, self::BY_NAME);
		if (!empty($group)) {
			return $this->createGroupFromLDAP($group[0]);
		}
		
		// No such group on the LDAP server => get it from the DB
		return parent::getGroupByName($groupName);
	}

	/**
	 * Retrieves the description of the group with the ID $groupID from
	 * the database or the LDAP server.
	 *
	 * @param int $groupID
	 * 		ID of the requested group. IDs for LDAP groups are always greater
	 * 		than LDAP_MAPPING_BASE.
	 *
	 * @return HACLGroup
	 * 		A new group object or <null> if there is no such group in the
	 * 		database.
	 *
	 */
	public function getGroupByID($groupID) {
		if ($groupID <= self::LDAP_MAPPING_BASE) {
			// retrieve group from database
			return parent::getGroupByID($groupID);
		}
		
		// Retrieve group from LDAP
		$groupID -= self::LDAP_MAPPING_BASE;
		$db = wfGetDB( DB_SLAVE );
		$gt = $db->tableName('halo_acl_ldap_group_id_map');
		$sql = "SELECT * FROM $gt ".
               "WHERE id = '$groupID';";
		$groupID = false;

		$res = $db->query($sql);

		if ($db->numRows($res) != 1) {
			// unknown ID
			$db->freeResult($res);
			return null;
		}
		
		$row = $db->fetchObject($res);
		$groupDN = $row->dn;
		$db->freeResult($res);
		
		$group = $this->searchLDAPGroups($groupDN, self::BY_DN);

		return (empty($group)) ? null : $this->createGroupFromLDAP($group[0]);
		
	}

	/**
	 * Adds the user with the ID $userID to the group with the ID $groupID,
	 * if the group is not an LDAP group.
	 *
	 * @param int $groupID
	 * 		The ID of the group to which the user is added.
	 * @param int $userID
	 * 		The ID of the user who is added to the group.
	 * @throws
	 * 		HACLStorageException (CANT_MODIFIY_LDAP_GROUP)
	 * 			if $groupID belongs to an LDAP group
	 *
	 */
	public function addUserToGroup($groupID, $userID) {
		if ($groupID >= self::LDAP_MAPPING_BASE) {
			throw new HACLStorageException(HACLStorageException::CANT_MODIFY_LDAP_GROUP,
										   $groupID);
		}
		parent::addUserToGroup($groupID, $userID);
	}

	/**
	 * Adds the group with the ID $childGroupID to the group with the ID
	 * $parentGroupID, if both groups are not LDAP groups.
	 *
	 * @param $parentGroupID
	 * 		The group with this ID gets the new child with the ID $childGroupID.
	 * @param $childGroupID
	 * 		The group with this ID is added as child to the group with the ID
	 *      $parentGroup.
	 * @throws
	 * 		HACLStorageException (CANT_MODIFIY_LDAP_GROUP)
	 * 			if at least one of the group IDs belongs to an LDAP group
	 *
	 */
	public function addGroupToGroup($parentGroupID, $childGroupID) {
		if ($parentGroupID >= self::LDAP_MAPPING_BASE) {
			throw new HACLStorageException(HACLStorageException::CANT_MODIFY_LDAP_GROUP,
										   $parentGroupID);
		}
		global $haclgAllowLDAPGroupMembers;
		if ($haclgAllowLDAPGroupMembers !== true) {
			// Adding LDAP groups to HaloACL groups must be explicitly allowed
			// If we end up here, it is not.
			if ($childGroupID >= self::LDAP_MAPPING_BASE) {
				throw new HACLStorageException(HACLStorageException::CANT_MODIFY_LDAP_GROUP,
											   $childGroupID);
			}
		}
		parent::addGroupToGroup($parentGroupID, $childGroupID);
		
	}

	/**
	 * Removes the user with the ID $userID from the group with the ID $groupID,
	 * if this is not an LDAP group.
	 *
	 * @param $groupID
	 * 		The ID of the group from which the user is removed. Must not be an
	 * 		LDAP group.
	 * @param int $userID
	 * 		The ID of the user who is removed from the group.
	 * @throws
	 * 		HACLStorageException (CANT_MODIFIY_LDAP_GROUP)
	 * 			if at least one of the group IDs belongs to an LDAP group
	 *
	 */
	public function removeUserFromGroup($groupID, $userID) {
		if ($groupID >= self::LDAP_MAPPING_BASE) {
			throw new HACLStorageException(HACLStorageException::CANT_MODIFY_LDAP_GROUP,
										   $groupID);
		}
		parent::removeUserFromGroup($groupID, $userID);
	}

	/**
	 * Removes all members from the group with the ID $groupID, if this is not
	 * an LDAP group.
	 *
	 * @param $groupID
	 * 		The ID of the group from which the user is removed.
	 * @throws
	 * 		HACLStorageException (CANT_MODIFIY_LDAP_GROUP)
	 * 			if at least one of the group IDs belongs to an LDAP group
	 *
	 */
	public function removeAllMembersFromGroup($groupID) {
		if ($groupID >= self::LDAP_MAPPING_BASE) {
			throw new HACLStorageException(HACLStorageException::CANT_MODIFY_LDAP_GROUP,
										   $groupID);
		}
		
		parent::removeAllMembersFromGroup($groupID);

	}


	/**
	 * Removes the group with the ID $childGroupID from the group with the ID
	 * $parentGroupID, if both groups are not LDAP groups.
	 *
	 * @param $parentGroupID
	 * 		This group loses its child $childGroupID.
	 * @param $childGroupID
	 * 		This group is removed from $parentGroupID.
	 * @throws
	 * 		HACLStorageException (CANT_MODIFIY_LDAP_GROUP)
	 * 			if at least one of the group IDs belongs to an LDAP group
	 *
	 */
	public function removeGroupFromGroup($parentGroupID, $childGroupID) {
		if ($parentGroupID >= self::LDAP_MAPPING_BASE) {
			throw new HACLStorageException(HACLStorageException::CANT_MODIFY_LDAP_GROUP,
										   $parentGroupID);
		}
		global $haclgAllowLDAPGroupMembers;
		if ($haclgAllowLDAPGroupMembers !== true) {
			// Adding LDAP groups to HaloACL groups must be explicitly allowed
			// If we end up here, it is not.
			if ($childGroupID >= self::LDAP_MAPPING_BASE) {
				throw new HACLStorageException(HACLStorageException::CANT_MODIFY_LDAP_GROUP,
											   $childGroupID);
			}
		}
		
		parent::removeGroupFromGroup($parentGroupID, $childGroupID);
	}

	/**
	 * Returns the IDs of all users or groups that are a member of the group
	 * with the ID $groupID.
	 *
	 * @param string $memberType
	 * 		'user' => ask for all user IDs
	 *      'group' => ask for all group IDs
	 * @return array(int)
	 * 		List of IDs of all direct users or groups in this group.
	 *
	 */
	public function getMembersOfGroup($groupID, $memberType) {
		
		$members = array();
		if ($groupID >= self::LDAP_MAPPING_BASE) {
			// retrieve the members of an LDAP group.
			$groupDN = $this->getGroupDNForID($groupID);
			if ($groupDN === null) {
				// no LDAP group => no members
				return $members;				
			}
			$ldapMembers = $this->searchGroupMembers($groupDN, $memberType);
			
			global $wgLDAPUserNameAttribute;
			$unameAttr = $wgLDAPUserNameAttribute[$_SESSION['wsDomain']];
			foreach ($ldapMembers as $m) {
				$dn = $m[0]['dn'];
				if ($memberType == 'group') {
					$members[] = $this->getGroupIDForDN($dn);
				} else {
					// search for users
					$userName = $m[0][$unameAttr][0];
					$members[] = User::idFromName($userName);
				}
				
			}
			return $members;
		}
		return parent::getMembersOfGroup($groupID, $memberType);

	}

	/**
	 * Returns all direct groups the user is member of.
	 *
	 * @param string $memberID
	 * 		ID of the user or group who's direct groups are retrieved.
	 * @param string $type
	 * 		HACLGroup::USER: retrieve parent groups of a user
	 * 		HACLGroup::GROUP: retrieve parent groups of a group
	 * @return array<array<"id" => int, "name" => string>>
	 * 		List of IDs of all direct groups of the given user.
	 *
	 */
	public function getGroupsOfMember($memberID, $type = HACLGroup::USER) {
			// get HaloACL groups
		$groups = parent::getGroupsOfMember($memberID, $type);
		$tmpGroupsOfMember = array();
		foreach ($groups as $g) {
			$tmpGroupsOfMember[$g['name']] = $g['id'];
		} 

		// get LDAP groups
		// LDAP groups overwrite HaloACL groups with the same name
		$this->bindLDAPServer();
		global $wgAuth;
		if ($type == HACLGroup::USER) {
			$dn = $wgAuth->getSearchString(User::whoIs($memberID));
		} else {
			$dn = $this->getGroupDNForID($memberID);
		}
		$wgAuth->getGroups($dn);
		$groups = $wgAuth->userLDAPGroups;
		
		$dns = $groups['dn'];
		foreach ($dns as $dn) {
			if (!empty($dn)) {
				$id = $this->getGroupIDForDN($dn);
				$name = $this->groupNameForID($id);			
				$tmpGroupsOfMember[$name] = $id;	// HaloACL groups can be overwritten
			}
		}
		
		// create the final data structure (array<array(id,name)>) 
		$groupsOfMember = array();
		foreach ($tmpGroupsOfMember as $name => $id) {
			$groupsOfMember[] = array('id' => $id, 'name' => $name);
		}
		
		return $groupsOfMember;
	}

	/**
	 * Checks if the given user or group with the ID $childID belongs to the
	 * group with the ID $parentID.
	 *
	 * @param int $parentID
	 * 		ID of the group that is checked for a member.
	 *
	 * @param int $childID
	 * 		ID of the group or user that is checked for membership.
	 *
	 * @param string $memberType
	 * 		HACLGroup::USER  : Checks for membership of a user
	 * 		HACLGroup::GROUP : Checks for membership of a group
	 *
	 * @param bool recursive
	 * 		<true>, checks recursively among all children of this $parentID if
	 * 				$childID is a member
	 * 		<false>, checks only if $childID is an immediate member of $parentID
	 *
	 * @return bool
	 * 		<true>, if $childID is a member of $parentID
	 * 		<false>, if not
	 *
	 */
	public function hasGroupMember($parentID, $childID, $memberType, $recursive) {
		if ($parentID < self::LDAP_MAPPING_BASE) {
			// Parent is a HaloACl group
			if (parent::hasGroupMember($parentID, $childID, $memberType, $recursive)) {
				return true;
			}
		} 
		
		global $wgAuth, $wgLDAPLowerCaseUsername;
		if ($memberType == HACLGroup::USER) {
			// Child is a user
			$user = User::whoIs($childID);
			if ($wgLDAPLowerCaseUsername[$_SESSION['wsDomain']]) {
				$user = strtolower($user);
			}
			$this->bindLDAPServer();
			$dn = $wgAuth->getUserDN($user);
		} else if ($memberType == HACLGroup::GROUP) {
			// Child is a group
			if ($childID < self::LDAP_MAPPING_BASE) {
				// Child group is a HaloACL group which can not be member of an
				// LDAP group.
				return false;
			}
			$dn = $this->getGroupDNForID($childID);
		} else {
			// Invalid member type
			return false;			
		}
		
		$groupsToSearch = array();
		$searchedGroups = array();
		
		do {
			$groups = $this->searchLDAPGroups($dn, self::AS_MEMBER);
			if (count($groups) > 0) {
				// The child is member of an LDAP group
				$searchedGroups[$dn] = true;
				// check if the parent is among the groups of the user
				foreach ($groups as $g) {
					$dn = $g['dn'];
					$groupID = $this->getGroupIDForDN($dn);
					if ($parentID == $groupID) {
						return true;
					}
					if ($recursive) {
						// The LDAP group may be member of a HaloACL group
						// => check if is a member of the specified parent
						if (parent::hasGroupMember($parentID, $groupID, HACLGroup::GROUP, true)) {
							return true;
						}
					}
					if (!isset($searchedGroups[$dn])) {
						$groupsToSearch[] = $dn;
					}
				}
			}
			
			$dn = array_shift($groupsToSearch);
		} while ($recursive && $dn);
			
		
		return false;
	}

	/**
	 * Deletes the group with the ID $groupID from the database. All references
	 * to the group in the hierarchy of groups are deleted as well. 
	 *
	 * However, the group is not removed from any rights, security descriptors etc.
	 * as this would mean that articles will have to be changed.
	 * 
	 * Only HaloACL groups can be deleted. LDAP groups can not be modified.
	 *
	 *
	 * @param int $groupID
	 * 		ID of the group that is removed from the database.
	 * @throws
	 * 		HACLStorageException (CANT_MODIFIY_LDAP_GROUP)
	 * 			if at least one of the group IDs belongs to an LDAP group
	 *
	 */
	public function deleteGroup($groupID) {
		if ($groupID >= self::LDAP_MAPPING_BASE) {
			throw new HACLStorageException(HACLStorageException::CANT_MODIFY_LDAP_GROUP,
										   $groupID);
		}
		parent::deleteGroup($groupID);

	}

	/**
	 * Checks if the group with the ID $groupID exists in the database.
	 *
	 * @param int $groupID
	 * 		ID of the group
	 *
	 * @return bool
	 * 		<true> if the group exists
	 * 		<false> otherwise
	 */
	public function groupExists($groupID) {
		if ($groupID >= self::LDAP_MAPPING_BASE) {
			// Check existence of LDAP group
			return $this->getGroupDNForID($groupID) !== null;
		}
		
		// Check existence of HaloACL group
		return parent::groupExists($groupID);
	}
	
	/**
	 * Checks if there are several definitions for the group with the specified
	 * $groupName. This can happen if the same group is defined in a wiki article
	 * and on the LDAP server.
	 * 
	 * @param string $groupName
	 * 		The name of the group that is checked.
	 * @return bool
	 * 		true: The group is defined in the wiki and on the LDAP server.
	 * 		false: There is only one or no definition for the group
	 */
	public function isOverloaded($groupName) {
		$g = $this->getGroupByName($groupName);
		if ($g == null) {
			// There is no such group
			return false;
		}
		if ($g->getGroupID() >= self::LDAP_MAPPING_BASE) {
			// Group is defined on LDAP server. Is it also defined in the SQL
			// database?
			$g2 = parent::getGroupByName($groupName);
			if ($g2 !== null) {
				// Group exists in database => it is overloaded
				return true;
			}
		}
		return false;
	}

	/**
	 * Retrieves the names of all users from the LDAP server and creates a wiki
	 * account for them.
	 * 
	 * @return array<string>
	 * 		The names of all created users.
	 *  
	 */
	public function createUsersFromLDAP() {
		global $wgLDAPUserObjectclass, $wgLDAPUserNameAttribute, $wgAuth;
		
		// Get the names of all users from LDAP
		$base = $wgAuth->getBaseDN( USERDN );
		$userClass = $wgLDAPUserObjectclass[$_SESSION['wsDomain']];
		$userName = $wgLDAPUserNameAttribute[$_SESSION['wsDomain']];
		
		$filter = "(objectclass=$userClass)";
		$entries = $this->ldapQuery($base, $filter, array('dn', $userName));
		
		$users = array();
		foreach ($entries as $e) {
			$user = ucfirst($e[$userName][0]);
			
			if (User::idFromName($user) == null) {
				// The user does not exist yet.
				User::createNew($user);
				$users[] = $user;
			}
		}
		return $users;
		
	}
	
	/**
	 * Searches for all groups whose name contains the search string $search.
	 * 
	 * @param string $search
	 * 		The group name must contain the string. Comparison is case insensitive.
	 * 
	 * @return array(string => int)
	 * 		A map from group names to group IDs of groups that match the search 
	 * 		string. Matches in the prefix of a group name (e.g. "Group/someName")
	 * 		are not removed.
	 */
	public function searchMatchingGroups($search) {
		// Search matching groups in parent
		$matchingGroups = parent::searchMatchingGroups($search);
		
		// Search matching LDAP groups
		$groups = $this->searchLDAPGroups($search, self::BY_NAME_FILTER);
		
		foreach ($groups as $g) {
			$dn = $g['dn'];
			$id = $this->getGroupIDForDN($dn);
			$matchingGroups[$g['cn']] = $id;
		}
		return $matchingGroups;
	}

	
	/**
	 * Search groups for the supplied DN
	 *
	 * @param string $dn
	 * @return array
	 * @access private
	 */
	private function searchLDAPGroups($dn, $searchMode) {
		global $wgLDAPGroupObjectclass, $wgLDAPGroupAttribute, $wgLDAPGroupNameAttribute;
		global $wgAuth;

		$base = $wgAuth->getBaseDN( GROUPDN );

		$objectclass = $wgLDAPGroupObjectclass[$_SESSION['wsDomain']];
		$attribute = $wgLDAPGroupAttribute[$_SESSION['wsDomain']];
		$nameattribute = $wgLDAPGroupNameAttribute[$_SESSION['wsDomain']];

		// We actually want to search for * not \2a
		$value = $dn;
		if ( $value != "*" )
			$value = $wgAuth->getLdapEscapedString( $value );

		switch ($searchMode) {
			case self::AS_MEMBER:
				$filter = "(&($attribute=$value)(objectclass=$objectclass))";
				break;
			case self::BY_NAME:
				$filter = "(&($nameattribute=$value)(objectclass=$objectclass))";
				break;
			case self::BY_NAME_FILTER:
				$filter = "(&($nameattribute=*$value*)(objectclass=$objectclass))";
				break;
			case self::BY_DN:
				$base = $value;
				$filter = "(objectclass=$objectclass)";
				break;
		}

		$entries = $this->ldapQuery($base, $filter);

		$groups = array();
		foreach ( $entries as $entry ) {
			$groupName = $entry[$nameattribute][0];
			$dn = $entry['dn'];
			$groups[] = array('cn' => $groupName, 'dn' => $dn);
		}

		return $groups;
	}
	
	/**
	 * Search groups for the supplied DN
	 *
	 * @param string $dn
	 * @return array
	 * @access private
	 */
	private function searchGroupMembers($parentGroupDN, $searchMode) {
		global $wgLDAPGroupObjectclass, $wgLDAPGroupAttribute;
		global $wgLDAPGroupNameAttribute, $wgLDAPUserNameAttribute;
		global $wgLDAPUserObjectclass;

		$groupobjectclass = $wgLDAPGroupObjectclass[$_SESSION['wsDomain']];
		$userobjectclass = $wgLDAPUserObjectclass[$_SESSION['wsDomain']];
		$memberAttr = $wgLDAPGroupAttribute[$_SESSION['wsDomain']];
		$nameAttr = $searchMode == 'user' 
						? $wgLDAPUserNameAttribute[$_SESSION['wsDomain']]
						: $wgLDAPGroupNameAttribute[$_SESSION['wsDomain']];

		// Filter for searching all members of a group
		$filter = "(&($memberAttr=*)(objectclass=$groupobjectclass))";
		$result = $this->ldapQuery($parentGroupDN, $filter);

		$groups = array();
		$r = $result[0];
		$members = $r[$memberAttr];
		array_shift($members);
		foreach ($members as $m) {
			// $m is a DN of a member => check if it is a group or user
			$class = $searchMode == 'user' ? $userobjectclass : $groupobjectclass;
			$filter = "(objectclass=$class)";
			$result = $this->ldapQuery($m, $filter, array('objectclass', $nameAttr));
			if (!empty($result)) {
				$groups[] = $result;
			}
		}

		return $groups;
	}

	
	/**
	 * Retrieves the groupID for a given distinguished name of a group.
	 *
	 * @param string $dn
	 * 		Distinguished LDAP name of the group
	 * @param bool addDN
	 * 		true: If the there is yet no ID for the $dn, it is added and an ID
	 * 			  is created.
	 * 		false: No ID is created and the $dn is not added
	 * @return mixed int/bool
	 * 		ID of the group or false if it does not exist (and is not created).
	 */
	private function getGroupIDForDN($dn, $addDN = true) {
		
		$dn = strtolower($dn);
		$db = wfGetDB( DB_SLAVE );
		$gt = $db->tableName('halo_acl_ldap_group_id_map');
		$sql = "SELECT * FROM $gt ".
               "WHERE dn = '$dn';";
		$groupID = false;

		$res = $db->query($sql);

		if ($db->numRows($res) == 1) {
			$row = $db->fetchObject($res);
			$groupID = $row->id+self::LDAP_MAPPING_BASE;
		}
		$db->freeResult($res);

		if ($groupID === false && $addDN === true) {
			// Add the DN to the table and get the new ID
			$setValues = array('dn' => $dn);
			$db->insert($gt, $setValues);
			// retrieve the auto-incremented ID of the DN
			$groupID = $db->insertId()+self::LDAP_MAPPING_BASE;
		}

		return $groupID;

	}
	
	/**
	 * Creates an instance of HACLGroup based on the description returned by an
	 * LDAP query.
	 * 
	 * @param array $group
	 * 		A groups description as returned by an LDAP query. 
	 * @return HACLGroup
	 * 		The new group object.
	 */
	private function createGroupFromLDAP($group) {
		global $wgLDAPGroupNameAttribute;
		$nameAttr = $wgLDAPGroupNameAttribute[$_SESSION['wsDomain']];
			
		$groupID = $this->getGroupIDForDN($group['dn']);
		$groupName = $group[$nameAttr];
		return new HACLGroup($groupID, $groupName, array(), array(), false, self::GROUP_TYPE_LDAP);
	}
	
	/**
	 * Returns the distinguished name of the group with the ID $groupID.
	 * 
	 * @param int $groupID
	 * 		ID of the LDAP group
	 * @return string / null
	 * 		The distinguished name or <null> if there is no such group. 
	 * 		
	 */
	private function getGroupDNForID($groupID) {
		if ($groupID < self::LDAP_MAPPING_BASE) {
			// not an LDAP group
			return null;
		}
		$groupID -= self::LDAP_MAPPING_BASE;
		$db = wfGetDB( DB_SLAVE );
		$gt = $db->tableName('halo_acl_ldap_group_id_map');
		$sql = "SELECT * FROM $gt ".
               "WHERE id = '$groupID';";

		$res = $db->query($sql);
		$groupDN = null;
		if ($db->numRows($res) == 1) {
			$row = $db->fetchObject($res);
			$groupDN = $row->dn;
		}
		$db->freeResult($res);		
		return $groupDN;
	}
	
	private function ldapQuery($baseDN, $filter, $attributes = array()) {
		global $wgUser, $wgAuth;
		
		$this->bindLDAPServer();

		$info = @ldap_search( $wgAuth->ldapconn, $baseDN, $filter, $attributes);
		
		#if ( $info["count"] < 1 ) {
		if ( !$info ) {
			$wgAuth->printDebug( "No entries returned from search.", SENSITIVE );

			//Return an array so that other functions
			//don't error out.
			return array("dn"=>array() );
		}

		$entries = @ldap_get_entries( $wgAuth->ldapconn, $info );

		//We need to shift because the first entry will be a count
		array_shift( $entries );

		return $entries;
		
	}
	
	/**
	 * Binds to the LDAP server with the $wgAuth object. After this it is possible
	 * to query the server with the methods of $wgAuth.
	 * 
	 * @return bool
	 * 		<true>, if the server was successfully bound
	 * 		<false> otherwise
	 * 
	 */
	private function bindLDAPServer() {
		if ($this->mLDAPBound) {
			// already bound
			return;
		}
		global $wgAuth;
		global $wgLDAPProxyAgent, $wgLDAPProxyAgentPassword;
		
		// reset the connection of $wgAuth
		$wgAuth->ldapconn = null;
		$wgAuth->connect();

		if ( isset( $wgLDAPProxyAgent[$_SESSION['wsDomain']] ) ) {
			//We'll try to bind as the proxyagent as the proxyagent should normally have more
			//rights than the user. If the proxyagent fails to bind, we will still be able
			//to search as the normal user (which is why we don't return on fail).
			$wgAuth->printDebug( "Binding as the proxyagent", NONSENSITIVE );
			$this->mLDAPBound = $wgAuth->bindAs( $wgLDAPProxyAgent[$_SESSION['wsDomain']], $wgLDAPProxyAgentPassword[$_SESSION['wsDomain']] );
		} else {
			// This is an anonymous bind
			$this->mLDAPBound = $wgAuth->bindAs();
		}
		return $this->mLDAPBound;
	}

}
