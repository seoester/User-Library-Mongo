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


}
