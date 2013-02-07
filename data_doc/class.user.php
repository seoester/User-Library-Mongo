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
	protected $_registeredOnly = false;
	protected $_hasOpenedOnlineId = false;
	protected $_onlineId = false;
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
	//######################   Initial methods    ######################
	//##################################################################


	public function login($loginname, $password, $force=false) {
		if ($this->created)
			throw new Exception("There is already a user assigned");
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

		$this->insertInOnlineTable();
		$this->_hasOpenedOnlineId = true;
		$this->callPostLoginHooks($this->id, $this->_onlineId);

		$this->save($db);
	}

	//##################################################################
	//######################     Online table     ######################
	//##################################################################


	private function insertInOnlineTable($anon=false) {
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
		$this->userSessions[] = $userSession;
	}

}
