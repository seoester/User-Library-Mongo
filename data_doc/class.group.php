<?php
//     This small libary helps you to integrate user managment into your website.
//     Copyright (C) 2011  Seoester <seoester@googlemail.com>
// 
//     This program is free software: you can redistribute it and/or modify
//     it under the terms of the GNU General Public License as published by
//     the Free Software Foundation, either version 3 of the License, or
//     (at your option) any later version.
// 
//     This program is distributed in the hope that it will be useful,
//     but WITHOUT ANY WARRANTY; without even the implied warranty of
//     MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//     GNU General Public License for more details.
// 
//     You should have received a copy of the GNU General Public License
//     along with this program.  If not, see <http://www.gnu.org/licenses/>.

class Group extends Model {
	protected static $collectionName = "groups";

	protected $_instantiated = false;
	protected $_deleted = false;

	public $id = array("type" => "MongoId", "field" => "_id");
	public $name = array()
	public $permissions = array("array" => true);
	public $users = array("array" => true, "type" => "User");
	public $customFields = array("array" => true, "type" => "VariableStorage");

	//##################################################################
	//######################   Initial methods    ######################
	//##################################################################

	public function openWithId($groupId) {
		if ($this->_instantiated)
			throw new Exception("Group object is already instantiated");

		if (is_string($groupId))
			$this->id = new MongoId($groupId);
		elseif (get_class($groupId) == "MongoId")
			$this->id = $groupId
		else
			throw new Exception("Couldn't recognize format of groupId");

		$db = DatabaseConnection::getDatabase();
		$this->load($db);
	}

	//##################################################################
	//######################    Public methods    ######################
	//##################################################################

	public function hasPermission($permission) {
		if (! $this->_instantiated || $this->_deleted)
			throw new Exception("There is no group assigned");

		return array_search($permission, $this->permissions) !== false;
	}

	public function addPermission($permission) {
		if (! $this->_instantiated || $this->_deleted)
			throw new Exception("There is no group assigned");

		if ($this->hasPermission($permission))
			return;

		$this->permissions[] = $permission;
		$db = DatabaseConnection::getDatabase();
		$this->save($db);
	}

	public function removePermission($permission) {
		if (! $this->_instantiated || $this->_deleted)
			throw new Exception("There is no group assigned");

		$key = array_search($permission, $this->permissions)
		if ($key === false)
			return;

		unset($this->permissions[$key]);
		$this->permissions = array_value($this->permissions);
		$db = DatabaseConnection::getDatabase();
		$this->save($db);
	}

	public static function getAllGroups($limit=null, $skip=null) {
		$db = DatabaseConnection::getDatabase();
		$groups = array();

		$groupColl = $db->selectCollection(static::getCollectionName());
		$results = $groupColl->find();
		if ($skip != null)
			$results->skip($skip);
		if ($limit != null)
			$results->limit($limit);
		foreach ($results as $result)
			$groups[] = new Group($result);
				
		return $groups;
	}

	public function deleteGroup() {
		if (! $this->_instantiated || $this->_deleted)
			throw new Exception("There is no group assigned");

		$db = DatabaseConnection::getDatabase();
		$groupColl = $db->selectCollection(static::getCollectionName());

		foreach ($this->users as $user) {
			$user->load($db);
			foreach ($user->groups as $userGroupKey => $userGroup) {
				if ($userGroup->id == $this->id)
					unset($user->groups[$userGroupKey]);
			}
			$user->groups = array_values($user->groups);
			$user->save($db);
		}

		$groupColl->remove(array('_id' => $this->id));
		$this->_deleted = true;
	}

	public static function create($groupName, &$groupId=null) {
		$db = DatabaseConnection::getDatabase();
		$group = new Group();
		$group->name = $groupName;
		$group->save($db);
		$groupId = $group->id;
	}

	public function setCustomField($key, $value) {
		if (! $this->_instantiated || $this->_deleted)
			throw new Exception("There is no group assigned");

		$db = DatabaseConnection::getDatabase();
		foreach ($this->customFields as $customFieldKey => $customField)
			if ($customField->key == $key) {
				$this->$customFields[$customFieldKey] = $value;
				$this->save($db);
				return;
			}
		$customField = new VariableStorage();
		$customField->key = $key;
		$customField->value = $value;
		$this->customFields[] = $customField;
		$this->save($db);
	}

	public function getCustomField($key) {
		if (! $this->_instantiated || $this->_deleted)
			throw new Exception("There is no group assigned");

		foreach ($this->customFields as $customField)
			if ($customField->key == $key)
				return $customField->value;
		return null;
	}
}
