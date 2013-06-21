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

class UserSession extends Model {
	protected static $collectionName = "usersessions";

	public $id = array("type" => "MongoId", "field" => "_id");
	public $user = array("type" => "User");
	public $ipAddress = array();
	public $lastAction = array();
	public $sessionVars = array("array" => true, "type" => "VariableStorage");

	public function setSessionVar($key, $value) {
		$db = DatabaseConnection::getDatabase();
		foreach ($this->sessionVars as $sessionVarKey => $sessionVar)
			if ($sessionVar->key == $key) {
				$this->$sessionVars[$sessionVarKey] = $value;
				$this->save($db);
				return;
			}
		$sessionVar = new VariableStorage();
		$sessionVar->key = $key;
		$sessionVar->value = $value;
		$this->sessionVars[] = $sessionVar;
		$this->save($db);
	}

	public function getSessionVar($key) {
		foreach ($this->sessionVars as $sessionVar)
			if ($sessionVar->key == $key)
				return $sessionVar->value;
		return null;
	}
}
