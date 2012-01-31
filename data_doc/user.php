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

require_once "database.php";
require_once "group.php";
require_once "settings.php";

/**
* Die User Klasse ist die zentrale Klasse der User Library.
* Die meisten Funktionen geben false zur�ck falls der Benutzer noch nicht mit {@link User::check()}, {@link User::login()} oder {@link User::openWithId()} initalisiert wurde.
*
* @package userlib
* @version 0.61
*/
class User {
	
	//Constants
	const STATUS_NORMAL = 1;
	const STATUS_BLOCK = 2;
	const STATUS_EMAILUNACTIVATED = 3;
	const STATUS_UNAPPROVED = 4;
	
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
	
	
	//Protected Vars
	protected $created = false;
	protected $userLoggedIn = false;
	protected $id;
	protected $deleted = false;
	protected $dbCache;
	protected $hookClasses = array();
	
	//##################################################################
	//######################   Initial methods    ######################
	//##################################################################
	public function __construct() {
		$this->dbCache = new Cache();
	}
	
	/**
	* Versucht einen Login mit den �bergebenen Daten. Es wird eine der LOGIN_ Konstanten zur�ckgegeben.
	* Die Methode erstellt auch alle notwendigen Sessions die {@link User::check()} braucht. Nach dem Login wird die User Klasse initialisiert.
	*
	*/
	public function login($givenLoginname, $givenPassword) {
		if ($this->created)
			return false;
		
		if (! settings\login_enabled)
			return self::LOGIN_LOGINDISABLED;
		
		$loginStatus = $this->databaseLoginRequest($givenLoginname, $givenPassword);
		
		if ($loginStatus == self::LOGIN_OK) {
			$this->insertInOnlineTable();
			$this->callLoginHooks($this->id);
			return self::LOGIN_OK;
		} else
			return $loginStatus;
	}
	
	/**
	* Liest Session Daten ein, die von der {@link User::login()} Methode erstellt werden.
	*
	*/
	public function check() {
		if ($this->created)
			return false;
		@session_start();
		$this->cleanOnlineTable();
		if (isset($_SESSION['userid']) && strlen($_SESSION['userid']) > 0 && isset($_COOKIE['USER_cookie_string']) && strlen($_COOKIE['USER_cookie_string']) > 0) {
			$userid = $_SESSION['userid'];
			if ($this->isUserInDataBase($userid, session_id()) && $this->checkCookieString($userid, $_COOKIE['USER_cookie_string'])) {
				$this->userLoggedIn = true;
				$this->id = $userid;
				$this->updateOnlineTable();
			}
		}
		if (! $this->userLoggedIn) {
			if ($this->isUserInDatabase(0, session_id(), true))
				$this->updateOnlineTable(true);
			else
				$this->insertInOnlineTable(true);
			$this->id = 0;
		}
		$this->created = true;
	}
	
	/**
	* �ffnet einen Benutzer mithilfe seiner ID in der Datenbank. Gibt es keinen Benutzer mit der angegebenen ID, wird false zur�ckgegeben.
	*
	*/
	public function openWithId($userid) {
		if ($this->created)
			return false;
		
		$returnValue = false;
		
		$dbCon = new DatabaseConnection();
		$stmt = $dbCon->prepare("SELECT `id` FROM `{dbpre}users` WHERE `id`=?");
		
		$stmt->bind_param("i", $userid);
		$stmt->execute();
		$stmt->store_result();
		if ($stmt->num_rows > 0) {
			$this->id = $userid;
			$this->created = true;
			$returnValue = true;
		}
		
		$dbCon->close(true);
		return $returnValue;
	}
	
	//##################################################################
	//######################    Public methods    ######################
	//##################################################################
	/**
	* Vor der logout() Methode muss erst {@link check()} ausgef�hrt werden. logout() l�scht wieder alle Sessions.
	*
	*/
	public function logout() {
		if (! $this->created || $this->deleted)
			return false;
		
		$this->deleteFromOnlineTable();

		session_destroy();
		setcookie(session_name(), ' ', time()-3600);
		setcookie('USER_cookie_string', ' ', time()-3600);
		
		return true;
	}
	
	/**
	* Blockiert den aktuellen Benutzer. Gibt false zur�ck, falls es einen Fehler gab.
	*
	*/
	public function block() {
		if (! $this->created || $this->deleted)
			return false;
		
		$status = $this->getRawStatus();
		$returnValue = false;
		$dbCon = new DatabaseConnection();
		
		if ($status == 100)
			$block_status = 12;
		else
			$block_status = 11;
	
		$stmt = $dbCon->prepare("UPDATE `{dbpre}users` SET `status`='" . $block_status . "' WHERE `id` = ? LIMIT 1;");
		$stmt->bind_param("i", $this->id);
		$stmt->execute();
		
		if ($dbCon->affected_rows() == 1)
			$returnValue = true;
		
		$dbCon->close(true);
		return $returnValue;
	}
	
	/**
	* Hebt die Blockierung des aktuellen Benutzers auf. Gibt false zur�ck, wenn es einen Fehler gab.
	*
	*/
	public function unblock() {
		if (! $this->created || $this->deleted)
			return false;
		
		$status = $this->getRawStatus();
		$blocked = true;
		$returnValue = false;
		
		$dbCon = new DatabaseConnection();
		
		if ($status == 12)
			$old_status = 100;
		elseif ($status == 11)
			$old_status = 1;
		else
			$blocked = false;
		
		if ($blocked) {
			$stmt = $dbCon->prepare("UPDATE `{dbpre}users` SET `status`='" . $old_status . "' WHERE `id` = ? LIMIT 1;");
			$stmt->bind_param("i", $this->id);
			$stmt->execute();
			
			if ($dbCon->affected_rows() == 1)
				$returnValue = true;
		}
		$dbCon->close();
		return $returnValue;
	}
	
	/**
	* Gibt den Status des aktuellen Benutzer zur�ck in Form einer STATUS_ Konstante zur�ck.
	*
	*/
	public function getStatus() {
		if (! $this->created || $this->deleted)
			return false;
		
		$status = $this->getRawStatus();
		$emailActivated = $this->getEmailactivated();
		
		if (! $emailActivated)
			return self::STATUS_EMAILUNACTIVATED;
		elseif ($status == 100)
			return self::STATUS_NORMAL;
		elseif ($status == 11 || $status == 12)
			return self::STATUS_BLOCK;
		elseif ($status == 1)
			return self::STATUS_UNAPPROVED;
		else
			return false;
	}
	
	/**
	* Best�tigt den aktuellen Benutzer. Gibt false zur�ck, wenn es einen Fehler gab.
	*
	*/
	public function approve() {
		if (! $this->created || $this->deleted)
			return false;
		
		$dbCon = new DatabaseConnection();
		
		$stmt = $dbCon->prepare("UPDATE `{dbpre}users` SET `status`='100' WHERE `id`=?");
		
		$stmt->bind_param("i", $this->id);
		$stmt->execute();
		
		$dbCon->close();
	}
	
	/**
	* Gibt true zur�ck, wenn der Besucher eingeloggt ist, false, wenn nicht.
	*
	*/
	public function loggedIn() {
		if (! $this->created || $this->deleted)
			return false;
		
		return $this->userLoggedIn;
	}
	
	/**
	* Gibt die ID in der _users Tabelle des aktuellen Benutzers zur�ck.
	*
	*/
	public function getId() {
		if (! $this->created || $this->deleted)
			return false;
		
		return $this->id;
	}
	
	/**
	* Gibt die Email Adresse des aktuellen Benutzers zur�ck.
	*
	*/
	public function getEmail() {
		if (! $this->created || $this->deleted)
			return false;
		
		if ($this->dbCache->inCache("email"))
			return $this->dbCache->getField("email");
		
		$dbCon = new DatabaseConnection();
		$stmt = $dbCon->prepare("SELECT `email` FROM `{dbpre}users` WHERE `id`=?");
		$returnValue = false;
		
		$stmt->bind_param("i", $this->id);
		$stmt->execute();
		$stmt->bind_result($email);
		if ($stmt->fetch()) {
			$returnValue = $email;
			$this->dbCache->setField("email", $email);
		}
		
		$dbCon->close();
		return $returnValue;
	}
	
	/**
	* Setzt die Email Adresse des aktuellen Benutzers.
	*
	*/
	public function setEmail($email) {
		if (! $this->created || $this->deleted)
			return false;
		
		$dbCon = new DatabaseConnection();
		
		$stmt = $dbCon->prepare("UPDATE `{dbpre}users` SET `email`=? WHERE `id`=?");
		
		$stmt->bind_param("si", $email, $this->id);
		$stmt->execute();
		$this->dbCache->setField("email", $email);
		$dbCon->close();
	}
	
	/**
	* Gibt den Benutzernamen des aktuellen Benutzers zur�ck.
	*
	*/
	public function getUsername() {
		if (! $this->created || $this->deleted)
			return false;
		
		if ($this->dbCache->inCache("username"))
			return $this->dbCache->getField("username");
		
		$dbCon = new DatabaseConnection();
		$returnValue = false;
		$stmt = $dbCon->prepare("SELECT `username` FROM `{dbpre}users` WHERE `id`=?");
		
		$stmt->bind_param("i", $this->id);
		$stmt->execute();
		$stmt->bind_result($username);
		if ($stmt->fetch()) {
			$returnValue = $username;
			$this->dbCache->setField("username", $username);
		}
		
		$dbCon->close();
		return $returnValue;
	}
	
	/**
	* Setzt den Benutzernamen des aktuellen Benutzers.
	*
	*/
	public function setUsername($username) {
		if (! $this->created || $this->deleted)
			return false;
		
		$dbCon = new DatabaseConnection();
		
		$stmt = $dbCon->prepare("UPDATE `{dbpre}users` SET `username`=? WHERE `id`=?");
		
		$stmt->bind_param("si", $username, $this->id);
		$stmt->execute();
		$this->dbCache->setField("username", $username);
		
		$dbCon->close();
	}
	
	/**
	* Gibt den Loginnamen des aktuellen Benutzers zur�ck.
	*
	*/
	public function getLoginname() {
		if (! $this->created || $this->deleted)
			return false;
		
		if ($this->dbCache->inCache("loginname"))
			return $this->dbCache->getField("loginname");
		
		$dbCon = new DatabaseConnection();
		$returnValue = false;
		$stmt = $dbCon->prepare("SELECT `login` FROM `{dbpre}users` WHERE `id`=?");
		
		$stmt->bind_param("i", $this->id);
		$stmt->execute();
		$stmt->bind_result($loginname);
		if ($stmt->fetch()) {
			$returnValue = $loginname;
			$this->dbCache->setField("loginname", $loginname);
		}
		
		$dbCon->close();
		return $returnValue;
	}
	
	/**
	* Setzt den Loginnamen des aktuellen Benutzers.
	*
	*/
	public function setLoginname($loginname) {
		if (! $this->created || $this->deleted)
			return false;
		
		$dbCon = new DatabaseConnection();
		
		$stmt = $dbCon->prepare("UPDATE `{dbpre}users` SET `login`=? WHERE `id`=?");
		
 		$stmt->bind_param("si", $loginname, $this->id);
		$stmt->execute();
		$this->dbCache->setField("loginname", $loginname);
		
		$dbCon->close();
	}
	
	/**
	* Setzt das Password f�r den aktuellen Benutzer neu.
	* Dabei wird auch ein neuer Salt erzeugt
	*
	*/
	public function setPassword($password) {
		if (! $this->created || $this->deleted)
			return false;
		
		$salt = $this->genCode(settings\length_salt);
		$encodedPassword = $this->encodePassword($password, $salt);
		
		$dbCon = new DatabaseConnection();
		$stmt = $dbCon->prepare("UPDATE `{dbpre}users` SET `password`=?, `salt`=? WHERE `id`=?;");
		
		$stmt->bind_param("ssi", $encodedPassword, $salt, $this->id);
		$stmt->execute();
		$dbCon->close();
		
		return true;
	}
	
	/**
	* Gibt ein Array mit den Ids aller Gruppen des Benutzers zur�ck.
	*
	*/
	public function getGroups() {
		if (! $this->created || $this->deleted)
			return false;
		
		$groupArray = array();
		$dbCon = new DatabaseConnection();
		
		$stmt = $dbCon->prepare("SELECT `groupid` FROM `{dbpre}user_groups` WHERE `userid`=?;");
		$stmt->bind_param("i", $this->id);
		$stmt->execute();
		$stmt->bind_result($groupId);
		while ($stmt->fetch()) {
			$group = new Group();
			$group->openWithId($groupId);
			$groupArray[] = $group;
		}
		$dbCon->close();
		
		return $groupArray;
	}
	
	/**
	* Gibt true zur�ck, wenn der Benutzer Mitglied der Gruppe mit der Id $groupId, ansonsten false.
	*
	*/
	public function inGroup($groupId) {
		if (! $this->created || $this->deleted)
			return false;
		
		$dbCon = new DatabaseConnection();
		$level = false;
		
		$stmt = $dbCon->prepare("SELECT `level` FROM `{dbpre}user_groups` WHERE `userid`=? AND `groupid`=?;");
		$stmt->bind_param("ii", $this->id, $groupId);
		$stmt->execute();
		$stmt->bind_result($level);
		if ($stmt->fetch())
			;
		if ($level === null)	//Correct $level, when fetch set it to null
			$level = false;
		$dbCon->close();
		return $level;
	}
	
	/**
	* F�gt den aktuellen Benutzer als Mitglied zu der Gruppe mit der Id $groupId hinzu.
	* Wenn der Benutzer bereits in der Gruppe $groupId ist, wird false zur�ckgegeben.
	* Zus�tzlich kann das Level angegeben werden, das der Benutzer in der Gruppe hat.
	* Wenn kein Level angegeben wird, wird es standardm��ig auf 50 gesetzt.
	*
	*/
	public function addGroup($groupId, $level=50) {
		if (! $this->created || $this->deleted)
			return false;
		
		if (!( $level >= 0) || ! ($level <= 100))
			return false;
		
		if ($this->inGroup($groupId))
			return false;
		
		$dbCon = new DatabaseConnection();
		
		$stmt = $dbCon->prepare("INSERT INTO `{dbpre}user_groups` (`userid`, `groupid`, `level`) VALUES (?, ?, ?);");
		
		$stmt->bind_param("iii", $this->id, $groupId, $level);
		$stmt->execute();
		$dbCon->close();
		return true;
	}
	
	/**
	* Entfernt den aktuellen Benutzer aus der Gruppe mit der Id $groupId.
	* Wenn der Benutzer nicht in der Gruppe $groupId ist, wird false zur�ckgegeben.
	*
	*/
	public function removeGroup($groupId) {
		if (! $this->created || $this->deleted)
			return false;
		
		if (! $this->inGroup($groupId))
			return false;
		
		$dbCon = new DatabaseConnection();
		
		$stmt = $dbCon->prepare("DELETE FROM `{dbpre}user_groups` WHERE `userid`=? AND `groupid`=? LIMIT 1;");
		
		$stmt->bind_param("ii", $this->id, $groupId);
		$stmt->execute();
		$dbCon->close();
		return true;
	}
	
	/**
	* Gibt das Level des aktuellen Benutzers in derGroppe mit der ID $groupId zur�ck.
	*/
	public function getInGroupLevel($groupId) {
		if (! $this->created || $this->deleted)
			return false;
		
		$dbCon = new DatabaseConnection();
		
		$stmt = $dbCon->prepare("SELECT `level` FROM `{dbpre}user_groups` WHERE `userid`=? AND `groupid`=? LIMIT 1;");
		
		$stmt->bind_param("ii", $this->id, $groupId);
		$stmt->execute();
		$stmt->bind_result($level);
		if ($stmt->fetch())
			;
	  else
			$level = false;
		$dbCon->close();
		return $level;
	}
	
	/**
	* Setzt das Level des aktuellen Benutzers in der Gruppe mit der ID $groupId.
	*
	*/
	public function setInGroupLevel($groupId, $level) {
		if (! $this->created || $this->deleted)
			return false;
		
		if (! $level > 0 || ! $level < 100)
			return false;
		
		if (! $this->inGroup($groupId))
			return false;
		
		$dbCon = new DatabaseConnection();
		
		$stmt = $dbCon->prepare("UPDATE `{dbpre}user_groups` SET `level`=? WHERE `userid`=? AND `groupid`=? LIMIT 1;");
		
		$stmt->bind_param("iii", $level, $this->id, $groupId);
		$stmt->execute();
		$dbCon->close();
		return true;
	}
	
	/**
	* Gibt true zur�ck, wenn der Benutzer die Permission $name hat, ansonsten false.
	* Die Gruppen des aktuellen Benutzers werden dabei nicht ber�cksichtigt.
	*
	*/
	public function hasOwnPermission($name) {
		if (! $this->created || $this->deleted)
			return false;
		
		$permissionId = $this->convertPermissionTitleToId($name);
		if ($permissionId === null)
			return false;
		
		$dbCon = new DatabaseConnection();
		
		$stmt = $dbCon->prepare("SELECT `id` FROM `{dbpre}user_permissions` WHERE `userid`=? AND `permissionid`=? LIMIT 1;");
		
		$stmt->bind_param("ii", $this->id, $permissionId);
		$stmt->execute();
		$stmt->bind_result($mappingId);
		if ($stmt->fetch())
			$hasPermission = true;
		else
			$hasPermission = false;
		$dbCon->close();
		
		return $hasPermission;
	}
	
	/**
	* F�gt dem Nutzer die Permission $name hinzu.
	* Wenn der Nutzer die Permission mit dem Titel $name bereits hat, wird false zur�ckgegeben.
	*
	*/
	public function addPermission($name) {
		if (! $this->created || $this->deleted)
			return false;
		
		if ($this->hasOwnPermission($name))
			return false;
		
		$permissionId = $this->convertPermissionTitleToId($name);
		if ($permissionId === null)
			return false;
		
		$dbCon = new DatabaseConnection();
		
		$stmt = $dbCon->prepare("INSERT INTO `{dbpre}user_permissions` (`userid`, `permissionid`) VALUES (?, ?);");
		
		$stmt->bind_param("ii", $this->id, $permissionId);
		$stmt->execute();
		$dbCon->close();
		return true;
	}
	
	/**
	* Entfernt die Permission $title vom aktuellen Benutzer.
	* Wenn der Nutzer die Permission mit dem Titel $name nicht hat, wird false zur�ckgegeben.
	*
	*/
	public function removePermission($name) {
		if (! $this->created || $this->deleted)
			return false;
		
		if (! $this->hasOwnPermission($name))
			return false;
		
		$permissionId = $this->convertPermissionTitleToId($name);
		if ($permissionId === null)
			return false;
		
		$dbCon = new DatabaseConnection();
		
		$stmt = $dbCon->prepare("DELETE FROM `{dbpre}user_permissions` WHERE `userid`=? AND `permissionid`=? LIMIT 1;");
		
		$stmt->bind_param("ii", $this->id, $permissionId);
		$stmt->execute();
		$dbCon->close();
		return true;
	}
	
	/**
	* Gibt true zur�ck, wenn der Benutzer oder eine Gruppe des Benutzers die Permission $name hat, ansonsten false.
	*
	*/
	public function hasPermission($name) {
		if (! $this->created || $this->deleted)
			return false;
		
		if ($this->hasOwnPermission($name))
			return true;
		
		$permissionId = $this->convertPermissionTitleToId($name);
		if ($permissionId === null)
			return false;
		
		$dbCon = new DatabaseConnection();
		$returnValue = false;
		
		$stmt = $dbCon->prepare("SELECT `id` FROM `{dbpre}group_permissions` WHERE `groupid`=? AND `permissionid`=? LIMIT 1;");
		
		$groups = $this->getGroups();
		foreach ($groups as $group) {
			$groupId = $group->getId();
			$stmt->bind_param("ii", $groupId, $permissionId);
			$stmt->execute();
			$stmt->bind_result($mappingId);
			if ($stmt->fetch()) {
				$returnValue = true;
				break;
			}
		}
		
		$dbCon->close();
		return $returnValue;
	}
	
	/**
	* Speichert die �bergebene Variable unter dem Titel $title f�r die aktuelle Session ab.
	* Ist bereits eine Variable mit dem Titel $title vorhanden, wird deren Inhalt �berschrieben.
	*
	*/
	public function saveSessionVar($title, $value) {
		if (! $this->created || $this->deleted)
			return false;
		
		$onlineId = $this->getOnlineId();
		
		$dbCon = new DatabaseConnection();
		
		$searchStmt = $dbCon->prepare("SELECT `id` FROM `{dbpre}sessionsvars` WHERE `title`=? AND `onlineid`=? LIMIT 1;");
		
		$searchStmt->bind_param("si", $title, $onlineId);
		$searchStmt->execute();
		$searchStmt->store_result();
		$searchStmt->bind_result($sessionVarid);
		if ($searchStmt->num_rows > 0) {
			$searchStmt->fetch();
			
			$updateStmt = $dbCon->prepare("UPDATE `{dbpre}sessionsvars` SET `value`=? WHERE `id`=? LIMIT 1;");
			
			$serialziedVar = serialize($value);
			$updateStmt->bind_param("si", $serialziedVar, $sessionVarid);
			$updateStmt->execute();
		} else {
			$insertStmt = $dbCon->prepare("INSERT INTO `{dbpre}sessionsvars` (`onlineid`, `title`, `value`) VALUES (?, ?, ?);");
			
			$serialziedVar = serialize($value);
			$insertStmt->bind_param("iss", $onlineId, $title, $serialziedVar);
			$insertStmt->execute();
		}
		
		$dbCon->close();
	}
	
	/**
	* Gibt den Inhalt der zuvor gespeicherten Variable unter dem Titel $title zur�ck.
	* Wenn es keine Variable mit dem Titel $title gibt, wird NULL zur�ckgegeben.
	*
	*/
	public function getSessionVar($title) {
		if (! $this->created || $this->deleted)
			return false;
		
		$onlineId = $this->getOnlineId();
		$returnValue = null;
		
		$dbCon = new DatabaseConnection();
		
		$stmt = $dbCon->prepare("SELECT `value` FROM `{dbpre}sessionsvars` WHERE `title`=? AND `onlineid`=? LIMIT 1;");
		
		$stmt->bind_param("si", $title, $onlineId);
		$stmt->execute();
		$stmt->store_result();
		$stmt->bind_result($value);
		if ($stmt->num_rows > 0) {
			$stmt->fetch();
			$returnValue = unserialize($value);
		}
		
		$dbCon->close();
		
		return $returnValue;
	}
	
	/**
	* L�scht den aktuellen Benutzer.
	*
	*/
	public function deleteUser() {
		if (! $this->created || $this->deleted)
			return false;
		
		$dbCon = new DatabaseConnection();
		
		$stmt = $dbCon->prepare("DELETE FROM `{dbpre}users` WHERE `id`=? LIMIT 1;");
		
		$stmt->bind_param("i", $this->id);
		$stmt->execute();
		
		$dbCon->close();
		$this->deleted = true;
		
		return true;
	}
	
	/**
	* Versucht die Email Adresse mit den �bergebenen Daten zu aktivieren. Gibt eine ACTIVATEEMAIL_ Konstante zur�ck.
	*
	*/
	public function activateEmail($activationCode) {
		if (! $this->created || $this->deleted)
			return false;
		
		if ($this->getEmailactivated())
			return self::ACTIVATEEMAIL_ALREADYACTIVATED;
		
		$dbCon = new DatabaseConnection();
		
		$checkStmt = $dbCon->prepare("SELECT `activationcode` FROM `{dbpre}users` WHERE `id`=? LIMIT 1;");
		
		$checkStmt->bind_param("i", $this->id);
		$checkStmt->execute();
		$checkStmt->bind_result($correctActivationCode);
		$checkStmt->fetch();
		
		if ($activationCode != $correctActivationCode) {
			$checkStmt->close();
			$dbCon->close();
			return self::ACTIVATEEMAIL_ACTIVATIONCODEWRONG;
		} else {
			$checkStmt->free_result();
			$checkStmt->close();
			$activateStmt = $dbCon->prepare("UPDATE `{dbpre}users` SET `email_activated`='1' WHERE `id`=? LIMIT 1;");
			
			$activateStmt->bind_param("i", $this->id);
			$activateStmt->execute();
			$dbCon->close();
			
			return self::ACTIVATEEMAIL_OK;
		}
	}
	
	/**
	* Erstellt einen neuen Benutzer mit den �bergebenen Werten.
	* Dabei wird keine Mail verschickt.
	* Wenn $approved nicht angegeben wird, werden die Einstellungen verwendet.
	*
	* @static
	*/
	public static function create($loginname, $username, $password, $email, $emailActivated=1, $approved="stan", &$userid="unset") {
		$salt = self::genCode(settings\length_salt);
		$encodedPassword = self::encodePassword($password, $salt);
		$activationCode = self::genCode(settings\length_activationcode);
		if ($approved == "stan") {
			if (settings\need_approval)
				$finalStatus = 1;
			else
				$finalStatus = 100;
		} else {
			if ($approved)
				$finalStatus = 100;
			else
				$finalStatus = 1;
		}
		
		$userid = self::writeUserIntoDatabase($loginname, $username, $encodedPassword, $salt, $email, $activationCode, $emailActivated, $finalStatus);
	}
	
	/**
	* Legt einen neuen Benutzer an.
	* Im $emailtext wird [%actcode%] durch den Aktivierungslink, [%username%] durch den Benutzername, [%loginname%] durch den Loginnamen und [%password%] durch das Passwort ersetzt.
	* Gibt eine der REGISTER_ Konstanten zur�ck.
	*
	*/
	public static function register($loginname, $username, $password, $email, $emailtext, $emailsubject, &$userid="unset") {
		if (! settings\register_enabled)
			return self::REGISTER_REGISTERDISABLED;
		elseif (! self::checkLoginname($loginname))
			return self::REGISTER_LOGINNAMEEXISTSALREADY;
		elseif (! self::checkUsername($username))
			return self::REGISTER_USERNAMEEXISTSALREADY;
		elseif (! self::checkEmail($email))
			return self::REGISTER_EMAILEXISTSALREADY;
		
		$salt = self::genCode(settings\length_salt);
		$encodedPassword = self::encodePassword($password, $salt);
		$activationCode = self::genCode(settings\length_activationcode);
		
		if (settings\need_approval)
			$finalStatus = 1;
		else
			$finalStatus = 100;
		
		$userid = self::writeUserIntoDatabase($loginname, $username, $encodedPassword, $salt, $email, $activationCode, 0, $finalStatus);
		
		$emailtext = str_replace("[%actcode%]", $activationCode, $emailtext);
		$emailtext = str_replace("[%username%]", $username, $emailtext);
		$emailtext = str_replace("[%loginname%]", $loginname, $emailtext);
		$emailtext = str_replace("[%password%]", $password, $emailtext);
		$emailtext = str_replace("[%id%]", $userid, $emailtext);
		
		self::sendMail(settings\send_mailaddress, $email, $emailsubject, $emailtext);
		
		return self::REGISTER_OK;
	}
	
	/**
	* Gibt alle existierenden Benutzer zur�ck.
	* Benutzer werden in Form eines Array von User Objekten zur�ckgegeben.
	*
	* @static
	*/
	public static function getAllUsers() {
		$users = array();
		$dbCon = new DatabaseConnection();
		
		$stmt = $dbCon->prepare("SELECT `id` FROM `{dbpre}users`;");
		
		$stmt->execute();
		$stmt->bind_result($userId);
		while ($stmt->fetch()) {
			$user = new User();
			$user->openWithId($userId);
			$users[] = $user;
		}
		$dbCon->close();
		
		return $users;
	}
	
	/**
	* F�gt einen Hook hinzu.
	* Die $hookClass muss von {@link UserHooks} abgeleitet werden.
	* Es k�nnen mehre Hook Klassen hinzugef�gt werden.
	*
	*/
	public function appendHook(UserHooks $hookClass) {
		$this->hookClasses[] = $hookClass;
	}
	
	/**
	* F�gt ein benutzerdefiniertes Feld mit dem Namen $name und dem $type zu der _users Tabelle hinzu.
	* $type ist dabei reines SQL.
	*
	*/
	public function addCustomField($name, $type) {
		if (! $this->created || $this->deleted)
			return false;
		
		$dbCon = new DatabaseConnection();
		
		$stmt = $dbCon->prepare("ALTER TABLE `{dbpre}users` ADD `custom_$name` $type;");
		$stmt->execute();
		
		$dbCon->close();
	}
	
	/**
	* Entfernt ein benuterdefiniertes Feld wieder.
	*
	*/
	public function removeCustomField($name) {
		if (! $this->created || $this->deleted)
			return false;
		
		$dbCon = new DatabaseConnection();
		
		$stmt = $dbCon->prepare("ALTER TABLE `{dbpre}users` DROP `custom_$name`;");
		$stmt->execute();
		
		$dbCon->close();
	}
	
	/**
	* Speichert den Wert $value in dem benutzerdefinierten Feld $name des aktuellen Benutzers.
	*
	*/
	public function saveCustomField($name, $value) {
		if (! $this->created || $this->deleted)
			return false;
		
		if (is_integer($value))
			$typeAbb ='i';
		elseif (is_double($value))
			$typeAbb = 'd';
		elseif (is_string($value))
			$typeAbb = 's';
		else
			$typeAbb = 'b';
		$dbCon = new DatabaseConnection();
		
		$stmt = $dbCon->prepare("UPDATE `{dbpre}users` SET `custom_$name`=? WHERE `id`=?;");
		$stmt->bind_param($typeAbb . "i", $value, $this->id);
		$stmt->execute();
		$dbCon->close();
		$this->dbCache->setField("custom_$name", $value);
		return true;
	}
	
	/**
	* Liest den Wert aus dem benutzerdefinierten Feld $name des aktuellen Benutzers.
	*
	*/
	public function getCustomField($name) {
		if (! $this->created || $this->deleted)
			return false;
		
		if ($this->dbCache->inCache("custom_$name"))
			return $this->dbCache->getField("custom_$name");
		
		$returnValue = false;
		$dbCon = new DatabaseConnection();
		
		$stmt = $dbCon->prepare("SELECT `custom_$name` FROM `{dbpre}users` WHERE `id`=?;");
		$stmt->bind_param("i", $this->id);
		$stmt->execute();
		$stmt->bind_result($result);
		if ($stmt->fetch())
			$returnValue = $result;
		else
			$returnValue = false;
		$dbCon->close();
		$this->dbCache->setField("custom_$name", $returnValue);
		return $returnValue;
	}
	
	//##################################################################
	//######################    Private methods   ######################
	//##################################################################
	private static function encodePassword($password, $salt) {
		$finalpassword = $password;
		
		for ($i = 0; $i < 10; $i++) {
			$finalpassword = md5($finalpassword . $salt);
		}
		
		return $finalpassword;
	}
	
	private static function genCode($charNum) {
		$letters = array("a", "b", "c", "d", "e", "f", "g", "h", "i", "j", "k", "l", "m", "n", "o", "p", "q", "r", "s", "t", "u", "v", "w", "x", "y", "z", "0", "1", "2", "3", "4", "5", "6", "7", "8", "9");
		$code = "";
		
		for ($i = 0; $i < $charNum; $i++) {
			$rand = mt_rand(0, 35);
			$code .= $letters[$rand];
		}
		
		return $code;
	}
	
	private static function checkUsername($username) {
		$dbCon = new DatabaseConnection();
		
		$stmt = $dbCon->prepare("SELECT `id` FROM `{dbpre}users` WHERE `username`=?;");
		
		$stmt->bind_param("s", $username);
		$stmt->execute();
		$stmt->store_result();
		
		$numRows = $stmt->num_rows;
		
		$dbCon->close(true);
		
		if ($numRows > 0)
			return false;
		else
			return true;
	}
	
	private static function checkLoginname($loginname) {
		$dbCon = new DatabaseConnection();
		
		$stmt = $dbCon->prepare("SELECT `id` FROM `{dbpre}users` WHERE `login`=?;");
		
		$stmt->bind_param("s", $loginname);
		$stmt->execute();
		$stmt->store_result();
		
		$numRows = $stmt->num_rows;
		
		$dbCon->close(true);
		
		if ($numRows > 0)
			return false;
		else
			return true;
	}
	
	private static function checkEmail($email) {
		$dbCon = new DatabaseConnection();
		
		$stmt = $dbCon->prepare("SELECT `id` FROM `{dbpre}users` WHERE `email`=?;");
		
		$stmt->bind_param("s", $email);
		$stmt->execute();
		$stmt->store_result();
		
		$numRows = $stmt->num_rows;
		
		$dbCon->close();
		
		if ($numRows > 0)
			return false;
		else
			return true;
	}
	
	private function validateLogin($status, $emailActivated) {
		if ($emailActivated == 0)
			return self::LOGIN_EMAILUNACTIVATED;
		
		if ($status == 1)
			return self::LOGIN_UNAPPROVED;
		elseif ($status == 11 || $status == 12)
			return self::LOGIN_BLOCKED;
		elseif ($status >= 100 && $status < 200)
			return self::LOGIN_OK;
		else
			return 0;
		
	}
	
	private function databaseLoginRequest($givenLoginname, $givenPassword) {
		$returnValue = 0;
		
		$dbCon = new DatabaseConnection();
		
		$stmt = $dbCon->prepare("SELECT `id`, `salt`, `password`, `email_activated`, `status`, `loginattempts`, `blockeduntil`, `secure_cookie_string` FROM `{dbpre}users` WHERE login = ? LIMIT 1;");
		$stmt->bind_param("s", $givenLoginname);
		$stmt->execute();
		$stmt->store_result();
		if ($stmt->num_rows > 0) {													//Count the results
			$stmt->bind_result($userid, $salt, $password, $emailActivated, $status, $loginattempts, $blockeduntil, $cookieString);
			$stmt->fetch();
		} else																		//No results found
			$returnValue = self::LOGIN_USERDOESNOTEXISTS;
		
		if ($returnValue === 0 && $loginattempts >= settings\maxloginattempts && date('U') < $blockeduntil) {
			$returnValue = self::LOGIN_TOOMANYATTEMPTS;
		}
		
		if ($returnValue > 0) {
			$dbCon->close(true);
			return $returnValue;
		}
		
		$encodedGivenPassword = self::encodePassword($givenPassword, $salt);
		if ($password == $encodedGivenPassword) {
			$validate = $this->validateLogin($status, $emailActivated);
			if ($validate == self::LOGIN_OK) {
				$_SESSION['userid'] = $userid;
				setcookie("USER_cookie_string", $cookieString, 0, "/");
				$this->userLoggedIn = true;
				$this->created = true;
				$this->id = $userid;
				
				$clearAttemptsStmt = $dbCon->prepare("UPDATE `{dbpre}users` SET `loginattempts`=?, `blockeduntil`=? WHERE `id`=?;");
				$loginattempts = 0;
				$blockeduntil = 0;
				$clearAttemptsStmt->bind_param("iii", $loginattempts, $blockeduntil, $this->id);
				$clearAttemptsStmt->execute();
				
				return self::LOGIN_OK;
			} else
				return $validate;
		} else {
			$loginattempts++;
			if ($loginattempts >= settings\maxloginattempts) {
				$blockeduntil = date('U') + settings\loginblocktime;
			} else
				$blockeduntil = 0;
			
			$attemptsStmt = $dbCon->prepare("UPDATE `{dbpre}users` SET `loginattempts`=?, `blockeduntil`=? WHERE `id`=?;");
			$attemptsStmt->bind_param("iii", $loginattempts, $blockeduntil, $userid);
			$attemptsStmt->execute();
			
			return self::LOGIN_WRONGPASSWORD;
		}
		
		$dbCon->close(true);
	}
	
	private function getIp() {
		if(getenv("HTTP_X_FORWARDED_FOR")) 
			$ip = getenv("HTTP_X_FORWARDED_FOR");
		else
			$ip = getenv("REMOTE_ADDR");
		return $ip;
	}
	
	private function getRawStatus() {
		$returnValue = 0;
		
		$dbCon = new DatabaseConnection();
		
		$stmt = $dbCon->prepare("SELECT `status` FROM `{dbpre}users` WHERE `id` = ? LIMIT 1;");
		$stmt->bind_param("i", $this->id);
		$stmt->execute();
		$stmt->bind_result($status);
		$stmt->fetch();
		$returnValue = $status;
		
		$dbCon->close();
		return $returnValue;
	}
	
	private function getEmailactivated() {
		$dbCon = new DatabaseConnection();
		
		$stmt = $dbCon->prepare("SELECT `email_activated` FROM `{dbpre}users` WHERE `id`=? LIMIT 1;");
		
		$stmt->bind_param("i", $this->id);
		$stmt->execute();
		$stmt->bind_result($emailActivated);
		$stmt->fetch();
		
		$dbCon->close();
		
		if ($emailActivated == 0)
			$emailActivated = false;
		else
			$emailActivated = true;
		
		return $emailActivated;
	}
	
	private function removeFromArray($value, array $array) {
		$newArray = array();
		
		foreach ($array as $ar) {
			if ($ar != $value)
				$newArray[] = $ar;
		}
		
		return $newArray;
	}
	
	private function convertPermissionTitleToId($title) {
		$dbCon = new DatabaseConnection();
		
		$stmt = $dbCon->prepare("SELECT `id` FROM `{dbpre}permissions` WHERE `name`=? LIMIT 1;");
		
		$stmt->bind_param("s", $title);
		$stmt->execute();
		$stmt->bind_result($id);
		$stmt->fetch();
		$dbCon->close();
		return $id;
	}
	
	private function getOnlineId() {
		if (! $this->created || $this->deleted )
			return false;
		$session = session_id();
		$ipaddress = $this->getIp();
		$dbCon = new DatabaseConnection();
		
		if (settings\securesessions)
			$stmt = $dbCon->prepare("SELECT `id` FROM `{dbpre}onlineusers` WHERE `userid`=? AND `session`=? AND `ipaddress`=? LIMIT 1;");
		else
			$stmt = $dbCon->prepare("SELECT `id` FROM `{dbpre}onlineusers` WHERE `userid`=? AND `session`=? LIMIT 1;");
		
		if (settings\securesessions)
			$stmt->bind_param("iss", $this->id, $session, $ipaddress);
		else
			$stmt->bind_param("is", $this->id, $session);
		$stmt->execute();
		$stmt->bind_result($onlineid);
		$stmt->fetch();
		
		$dbCon->close();
		
		return $onlineid;
	}
	
	private function checkCookieString($id, $cookieString) {
		$dbCon = new DatabaseConnection();
		$returnValue = false;
		$stmt = $dbCon->prepare("SELECT `secure_cookie_string` FROM `{dbpre}users` WHERE `id`=? LIMIT 1;");
		$stmt->bind_param("i", $id);
		$stmt->execute();
		$stmt->bind_result($db_cookieString);
		if ($stmt->fetch())
			if ($cookieString == $db_cookieString)
				$returnValue = true;
		
		$dbCon->close();
		return $returnValue;
	}
	
	private static function writeUserIntoDatabase($loginname, $username, $encodedPassword, $salt, $email, $activationCode, $emailActivated, $status) {
		$dbCon = new DatabaseConnection();
		$cookieString = self::genCode(100);
		$id = 0;
		
		$insertStmt = $dbCon->prepare("INSERT INTO `{dbpre}users` (`login`, `username`, `password`, `salt`, `email`, `activationcode`, `email_activated`, `status`, `secure_cookie_string`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?);");
		
		$insertStmt->bind_param("ssssssiis", $loginname, $username, $encodedPassword, $salt, $email, $activationCode, $emailActivated, $status, $cookieString);
		$insertStmt->execute();
		$id = $dbCon->insert_id();
		$dbCon->close();
		return $id;
	}
	
	private static function sendMail($FROM, $TO, $SUBJECT, $TEXT) {
		return mail($TO, $SUBJECT, $TEXT, "FROM: " . $FROM);
	}
	
	private function callLoginHooks($userid) {
		foreach ($this->hookClasses as $hookClass) {
			$hookClass->login($userid);
		}
	}
	
	private function callLogoutHooks($userid) {
		foreach ($this->hookClasses as $hookClass) {
			$hookClass->logout($userid);
		}
	}
	
	//##################################################################
	//######################     Online table     ######################
	//##################################################################
	private function insertInOnlineTable($anon=false) {
		$dbCon = new DatabaseConnection();
		
		$stmt = $dbCon->prepare("INSERT INTO `{dbpre}onlineusers` (`userid`, `session`, `ipaddress`, `lastact`) VALUES (?, ?, ?, ?);");
		
		if (! $anon)
			$userid = $this->id;
		else
			$userid = 0;
		$sessionName = session_id();
		$ipAddress = $this->getIp();
		$actTime = date("U");
		
		$stmt->bind_param("issi", $userid, $sessionName, $ipAddress, $actTime);
		$stmt->execute();
		
		$dbCon->close();
	}
	
	private function updateOnlineTable($anon=false) {
		$dbCon = new DatabaseConnection();
		
		$stmt = $dbCon->prepare("UPDATE `{dbpre}onlineusers` SET `lastact`=? WHERE `userid`=? AND `session`=? LIMIT 1;");
		
		if (! $anon)
			$userid = $this->id;
		else
			$userid = 0;
		$sessionName = session_id();
		$actTime = date("U");
		
		$stmt->bind_param("iis", $actTime, $userid, $sessionName);
		$stmt->execute();
		
		$dbCon->close();
	}
	
	private function deleteFromOnlineTable() {
		$dbCon = new DatabaseConnection();
		
		$userid = $this->id;
		$sessionName = session_id();
		
		$searchStmt = $dbCon->prepare("SELECT `id` FROM `{dbpre}onlineusers` WHERE `userid`=? AND `session`=? LIMIT 1;");
		
		$searchStmt->bind_param("is", $userid, $sessionName);
		$searchStmt->execute();
		$searchStmt->bind_result($onlineId);
		
		if ($searchStmt->fetch()) {
			$this->deleteAllUserDataFromOnlineTable($onlineId, $userid);
		}
		
		$dbCon->close();
	}
	
	private function cleanOnlineTable() {
		$dbCon = new DatabaseConnection();
		
		$searchStmt = $dbCon->prepare("SELECT `id`, `userid` FROM `{dbpre}onlineusers` WHERE `lastact`<?;");
		
		$actTime = date("U");
		$minLastTime = $actTime - settings\autologouttime;
		
		$searchStmt->bind_param("i", $minLastTime);
		$searchStmt->execute();
		$searchStmt->bind_result($delId, $delUserId);
		
		while ($searchStmt->fetch()) {
			$this->deleteAllUserDataFromOnlineTable($delId, $delUserId);
		}
		
		$dbCon->close();
	}
	
	private function deleteAllUserDataFromOnlineTable($onlineid, $userid) {
		$dbCon = new DatabaseConnection();
		
		$delVarstmt = $dbCon->prepare("DELETE FROM `{dbpre}sessionsvars` WHERE `onlineid`=? LIMIT 1;");
		$delStmt = $dbCon->prepare("DELETE FROM `{dbpre}onlineusers` WHERE `id`=?;");
		
		$delVarstmt->bind_param("i", $onlineid);
		$delStmt->bind_param("i", $onlineid);
		
		$delVarstmt->execute();
		$delStmt->execute();
		$dbCon->close();
		$this->callLogoutHooks($userid);
	}
	
	private function isUserInDataBase($userid, $session, $anon=false) {
		$dbCon = new DatabaseConnection();
		
		if (settings\securesessions)
			$searchStmt = $dbCon->prepare("SELECT `id` FROM `{dbpre}onlineusers` WHERE `userid`=? AND `session`=? AND `ipaddress`=? LIMIT 1;");
		else
			$searchStmt = $dbCon->prepare("SELECT `id` FROM `{dbpre}onlineusers` WHERE `userid`=? AND `session`=? LIMIT 1;");
		
		$ipaddress = $this->getIp();
		
		if (settings\securesessions)
			$searchStmt->bind_param("iss", $userid, $session, $ipaddress);
		else
			$searchStmt->bind_param("is", $userid, $session);
		
		$searchStmt->execute();
		$searchStmt->bind_result($onlineid);
		
		if ($searchStmt->fetch()) {
			$returnValue = true;
		} else
			$returnValue = false;
		
		$dbCon->close();
		return $returnValue;
	}
}

abstract class UserHooks {
	abstract public function login($userid);
	
	abstract public function logout($userid);
}
?>