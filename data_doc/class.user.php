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

class User extends Model {

	// Constants
	const STATUS_NORMAL = 1;
	const STATUS_BLOCK = 2;
	const STATUS_UNAPPROVED = 3;
	
	const LOGIN_OK = 1;
	const LOGIN_WRONGPASSWORD = 2;
	const LOGIN_USERDOESNOTEXISTS = 3;
	const LOGIN_BLOCKED = 4;
	const LOGIN_LOGINDISABLED = 5;
	const LOGIN_EMAILUNACTIVATED = 6;
	const LOGIN_UNAPPROVED = 7;
	const LOGIN_TOOMANYATTEMPTS = 8;
	
	const REGISTER_OK = 1;
	const REGISTER_REGISTERDISABLED = 2;
	const REGISTER_LOGINNAMEEXISTSALREADY = 3;
	const REGISTER_USERNAMEEXISTSALREADY = 4;
	const REGISTER_EMAILEXISTSALREADY = 5;
	
	const ACTIVATEEMAIL_OK = 1;
	const ACTIVATEEMAIL_ALREADYACTIVATED = 2;
	const ACTIVATEEMAIL_ACTIVATIONCODEWRONG = 3;

	// Collection Name
	protected static $collectionName = "users";

	// Protected Variables
	protected $_instantiated = false;
	protected $_deleted = false;
	protected $_hasOpenedOnlineId = false;
	protected $_onlineId = null;
	protected $_loggedIn = false;
	protected $_hookClasses = array();

	// Fields
	public $id = array("type" => "MongoId", "field" => "_id");
	public $loginname = array();
	public $username = array();
	public $password = array();
	public $email = array();
	public $status = array();
	public $loginAttempts = array();
	public $blockedUntil = array();
	public $secureCookieString = array();
	public $registerDate = array();
	public $activationDate = array();
	public $activationCode = array();
	public $activated = array("default" => false);
	public $permissions = array("array" => true);
	public $groups = array("array" => true, "type" => "Group");
	public $userSessions = array("array" => true, "type" => "UserSession");


	//##################################################################
	//####################   Initiation methods    #####################
	//##################################################################


	public function login($loginname, $password, $force=false) {
		if ($this->created)
			throw new Exception("User object is already instantiated");
		global $config;

		if (! $config->loginEnabled)
			return self::LOGIN_LOGINDISABLED;

		$status = $this->preCheck();
		if ($status != self::LOGIN_OK) {
			$this->resetAttributes();
			return $status;
		}
		if (! $force) {
			$status = $this->passwordCheck($password);
			if ($status != self::LOGIN_OK) {
				$this->finishFaiLogin();
				$this->resetAttributes();
				return $status;
			}
		}
		$status = $this->postCheck();
		if ($status != self::LOGIN_OK) {
			$this->finishUnaLogin();
			$this->resetAttributes();
			return $status;
		}
		$this->finishSucLogin();
		return self::LOGIN_OK;
	}

	public function check() {
		if ($this->_instantiated)
			throw new Exception("User object is already instantiated");

		$this->cleanOnlineTable();
		if (isset($_COOKIE["USER_sessionid"]) && strlen($_COOKIE["USER_sessionid"]) > 0) {
			$db = DatabaseConnection::getDatabase();
			if (isset($_COOKIE['USER_cookie_string']) && strlen($_COOKIE['USER_cookie_string']) > 0
					&& $this->isSessionInDatabase($_COOKIE["USER_sessionid"], $this->id)
					&& $this->load($db)->secureCookieString == $_COOKIE['USER_cookie_string']) {
				$this->_loggedIn = true;
				$this->updateSessions();
			} else
				($this->isSessionInDatabase($_COOKIE["USER_sessionid"]))? $this->updateSessions() : $this->createSession(true);
		} else
			$this->createSession(true);
		$this->_hasOpenedOnlineId = true;
		$this->_onlineId = new MongoId($_COOKIE["USER_sessionid"]);
		$this->_instantiated = true;
	}

	public function openWithId($userId) {
		if ($this->_instantiated)
			throw new Exception("User object is already instantiated");

		if (is_string($userId))
			$this->id = new MongoId($userId);
		elseif (get_class($userId) == "MongoId")
			$this->id = $userId
		else
			throw new Exception("Couldn't recognize format of userId");

		$db = DatabaseConnection::getDatabase();
		$this->load($db);
	}

	//##################################################################
	//######################    Public methods    ######################
	//##################################################################

	public function logout() {
		if (! $this->_instantiated || $this->_deleted || ! $this->_loggedIn)
			throw new Exception("There is no user assigned");

		$db = DatabaseConnection::getDatabase();
		$userSessionColl = $db->selectCollection(UserSession::getCollectionName());
		
		foreach ($this->userSessions as $key => $userSession)
			if ($userSession->id == $this->_onlineId)
				unset($this->userSessions[$key]);
		$this->userSessions = array_values($this->userSessions);

		$userSessionColl->remove(array("_id" => $this->_onlineId));
		$this->save($db);

		$this->_onlineId = null;
		$this->_hasOpenedOnlineId = false;
		$this->_loggedIn = false;

		setcookie($_COOKIE["USER_sessionid"], ' ', time() - 3600);
		setcookie('USER_cookie_string', ' ', time() - 3600);
	}

	public function block() {
		if (! $this->_instantiated || $this->_deleted || $this->activated)
			throw new Exception("There is no user assigned");

		if ($this->status == 12 || $this->status == 11)
			return false;

		if ($this->status == 100)
			$this->status = 12
		else
			$this->status = 11;

		$db = DatabaseConnection::getDatabase();
		$this->save($db);
		return true;
	}

	public function unblock() {
		if (! $this->_instantiated || $this->_deleted || $this->activated)
			throw new Exception("There is no user assigned");

		if ($this->status == 12)
			$this->status = 100;
		elseif ($this->status == 11)
			$this->status = 1;
		else
			return false;

		$db = DatabaseConnection::getDatabase();
		$this->save($db);
		return true;
	}

	public function getStatus() {
		if (! $this->_instantiated || $this->_deleted || $this->activated)
			throw new Exception("There is no user assigned");

		$status = $this->status;

		if ($status == 100)
			return self::STATUS_NORMAL;
		elseif ($status == 11 || $status == 12)
			return self::STATUS_BLOCK;
		elseif ($status == 1)
			return self::STATUS_UNAPPROVED;
		else
			throw new Exception("Unknown status");
	}

	public function approve() {
		if (! $this->_instantiated || $this->_deleted || $this->activated)
			throw new Exception("There is no user assigned");

		if ($this->status == 11)
			$this->status = 12;
		elseif ($status == 12 || $status == 100)
			return false;
		else
			$this->status = 100;

		$db = DatabaseConnection::getDatabase();
		$this->save($db);
		return true;
	}

	public function hasOpenedOnlineId() {
		if (! $this->_instantiated || $this->_deleted)
			throw new Exception("There is no user assigned");

		return $this->_hasOpenedOnlineId;
	}

	public function isLoggedIn() {
		if (! $this->_instantiated || $this->_deleted)
			throw new Exception("There is no user assigned");

		return $this->_loggedIn;
	}

	public function setPassword($password) {
		if (! $this->_instantiated || $this->_deleted)
			throw new Exception("There is no user assigned");

		$db = DatabaseConnection::getDatabase();
		$this->password = static::encodePassword($password, null);
		$this->save($db);
	}

	public function checkPassword($password) {
		if (! $this->_instantiated || $this->_deleted)
			throw new Exception("There is no user assigned");

		return $this->password == static::encodePassword($password, $this->password);
	}

	public function inGroup($group) {
		if (! $this->_instantiated || $this->_deleted || $this->activated)
			throw new Exception("There is no user assigned");

		if (is_string($group))
			$groupId = new MongoId($group);
		elseif (get_class($group) == "MongoId")
			$groupId = $group;
		elseif (get_class($group) == "Group")
			$groupId = $group->id;
		else
			throw new Exception("Couldn't recognize format of group");

		foreach ($this->groups as $userGroup)
			if ($userGroup->id == $groupId)
				return true;

		return false;
	}

	public function addGroup($group) {
		if (! $this->_instantiated || $this->_deleted || $this->activated)
			throw new Exception("There is no user assigned");

		if (is_string($group))
			$groupId = new MongoId($group);
		elseif (get_class($group) == "MongoId")
			$groupId = $group;
		elseif (get_class($group) == "Group")
			$groupId = $group->id;
		else
			throw new Exception("Couldn't recognize format of group");

		if ($this->inGroup($groupId))
			return;

		$this->groups[] = new Group($groupId);
		$db = DatabaseConnection::getDatabase();
		$this->save($db);
	}

	public function removeGroup($group) {
		if (! $this->_instantiated || $this->_deleted || $this->activated)
			throw new Exception("There is no user assigned");

		if (is_string($group))
			$groupId = new MongoId($group);
		elseif (get_class($group) == "MongoId")
			$groupId = $group;
		elseif (get_class($group) == "Group")
			$groupId = $group->id;
		else
			throw new Exception("Couldn't recognize format of group");

		if (! $this->inGroup($groupId))
			return;

		foreach ($this->groups as $key => $userGroup)
			if ($userGroup->id == $groupId)
				unset($this->groups[$key]);

		$this->groups = array_values($this->groups);
		$db = DatabaseConnection::getDatabase();
		$this->save($db);
	}

	public function hasOwnPermission($permission) {
		if (! $this->_instantiated || $this->_deleted || $this->activated)
			throw new Exception("There is no user assigned");

		return array_search($permission, $this->permissions) !== false;
	}

	public function addPermission($permission) {
		if (! $this->_instantiated || $this->_deleted || $this->activated)
			throw new Exception("There is no user assigned");

		if ($this->hasOwnPermission($permission))
			return;

		$this->permissions[] = $permission;
		$db = DatabaseConnection::getDatabase();
		$this->save($db);
	}

	public function removePermission($permission) {
		if (! $this->_instantiated || $this->_deleted || $this->activated)
			throw new Exception("There is no user assigned");

		$key = array_search($permission, $this->permissions)
		if ($key === false)
			return;

		unset($this->permissions[$key]);
		$this->permissions = array_value($this->permissions);
		$db = DatabaseConnection::getDatabase();
		$this->save($db);
	}

	//##################################################################
	//######################    Private methods   ######################
	//##################################################################

	private static function encodePassword($password, $passwordHash=null) {
		global $config;
		switch ($config->passwordAlgorithm) {
			case 'scrypt':
				$keyLength = $config->passwordKeyLength;
				if ($passwordHash == null) {
					$cpuDifficulty = $config->passwordCpuDifficulty;
					$memDifficulty = $config->passwordMemDifficulty;
					$parallelDifficulty = $config->passwordParallelDifficulty;
					$salt = self::genCode($config->passwordSaltLength);
				} else
					list($cpuDifficulty, $memDifficulty, $parallelDifficulty, $salt) = explode('$', $passwordHash);
				
				$hash = scrypt($password, $salt, $cpuDifficulty, $memDifficulty, $parallelDifficulty, $keyLength);
				return $cpuDifficulty . '$' . $memDifficulty . '$' . $parallelDifficulty . '$' . $salt . '$' . $hash;
				break;
			case 'bcrypt':
				$keyLength = $config->passwordKeyLength;
				if ($passwordHash == null) {
					$roundInt = $config->passwordRounds;
					$rounds = (strlen($roundInt) == 1)? "0$roundInt" : $roundInt;
					$salt = self::genCode(22);
					$options = '$2a$' . $rounds . '$' . $salt;
				} else
					$options = substr($passwordHash, 0, 30);
				
				return crypt($password, $options);
				break;
			default:
				throw new Exception("Unsupported password encryption algorithm", 1);
				break;
		}
	}

	private function callPostLoginHooks($userId, $onlineId) {
		foreach ($this->_hookClasses as $hookClass) {
			$hookClass->login($userId, $onlineId);
		}
	}

	//##################################################################
	//##########################    Login     ##########################
	//##################################################################

	private function preCheck($loginname) {
		global $config;
		$coll = DatabaseConnection::getDatabase()->selectCollection(static::$collectionName);
		
		$findResult = $coll->findOne(array("loginname" => $loginname));
		if ($findResult == null)
			return self::LOGIN_USERDOESNOTEXISTS;

		$this->parse($parse);

		if (! $this->activated)
			return self::LOGIN_EMAILUNACTIVATED;

		if ($this->loginAttempts >= $config->maxLoginAttempts && time() < $this->blockedUntil)
			return self::LOGIN_TOOMANYATTEMPTS;

		return self::LOGIN_OK; 
	}

	private function passwordCheck($password) {
		$encodedPassword = self::encodePassword($password, $this->password);
		if ($encodedPassword == $this->password)
			return self::LOGIN_OK;
		return self::LOGIN_WRONGPASSWORD;
	}

	private function postCheck() {
		if ($this->status == 1)
			return self::LOGIN_UNAPPROVED;
		elseif ($this->status == 11 || $this->status == 12)
			return self::LOGIN_BLOCKED;
		elseif ($this->status >= 100 && $this->status < 200)
			return self::LOGIN_OK;
		else
			throw new Exception("Unknown status '$this->status'");
	}

	private function finishFaiLogin() {
		global $config;
		$db = DatabaseConnection::getDatabase();

		$this->loginAttempts++;
		$this->blockedUntil = ($this->loginattempts >= $config->maxLoginAttempts)? time() + $config->loginBlockTime : 0;
		$this->save($db);
	}

	private function funishUnaLogin() {
		$db = DatabaseConnection::getDatabase();

		$this->loginAttempts = 0;
		$this->blockedUntil = 0;
		$this->save($db);
	}

	private function finishSucLogin() {
		$db = DatabaseConnection::getDatabase();

		$this->loginAttempts = 0;
		$this->blockedUntil = 0;
		$this->_instantiated = true;

		$this->createSession();
		$this->_hasOpenedOnlineId = true;
		$this->callPostLoginHooks($this->id, $this->_onlineId);

		$this->save($db);
	}

	//##################################################################
	//######################     Online table     ######################
	//##################################################################


	private function createSession($anon=false) {
		$db = DatabaseConnection::getDatabase();
		$userSession = new UserSession();		
		
		$userSession->ipAddress = getIp();
		$userSession->lastAction = time();

		if (! $anon)
			$userSession->user = $this;
		else
			$userSession->user = null;

		$userSession->save($db);

		setcookie("USER_sessionid", $userSession->id->{'$id'}, 0, "/");
		$_COOKIE["USER_sessionid"] = $userSession->id->{'$id'};
		$this->_onlineId = $userSession->id;
		if (! $anon)
			$this->userSessions[] = $userSession;
	}

	private function cleanOnlineTable() {
		global $config;
		$db = DatabaseConnection::getDatabase();
		$userSessionColl = $db->selectCollection(UserSession::getCollectionName());

		$minLastActionTime = time() - $config->autoLogoutTime;
		$userSessionResults = $coll->find(array("lastAction" => array('$lt' => $minLastActionTime), "user" => array('$not' => null)));

		foreach ($userSessionResults as $userSessionResult) {
			$userSession = new UserSession($userSessionResult);
			$user = $userSession->user;
			$user->load($db);
			foreach ($user->userSessions as $key => $userUserSession)
				if ($userUserSession->id == $userSession->id)
					unset($user->userSessions[$key]);
			$user->userSessions = array_values($user->userSessions);
			$user->save($db);
		}
		$coll->remove(array("lastAction" => array('$lt' => $minLastActionTime), "user" => array('$not' => null)));
	}

	private function isSessionInDatabase($sessionId, &$userId=null) {
		global $config;
		$db = DatabaseConnection::getDatabase();
		$userSessionColl = $db->selectCollection(UserSession::getCollectionName());

		if ($config->secureSessions)
			$result = $userSessionColl->findOne(array("_id" => new MongoId($sessionId), "ipAddress" => getIp()));
		else
			$result = $userSessionColl->findOne(array("_id" => new MongoId($sessionId)));

		if ($result === null)
			return false;
		$userSession = new UserSession($result);
		$userId = $userSession->user->id;
		return true;
	}

	private function updateSessions() {
		$db = DatabaseConnection::getDatabase();
		$userSessionColl = $db->selectCollection(UserSession::getCollectionName());

		$result = $userSessionColl->findOne(array("_id" => new MongoId($_COOKIE["USER_sessionid"])));
		$userSession = new UserSession($result);
		$userSession->lastAction = time();
		$userSession->save($db);
	}

}
