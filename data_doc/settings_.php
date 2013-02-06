<?php
/**
* In dieser Klasse werden alle Einstellungen der User Library gespeichert.
* @package userlib
*/
class UserLibrarySettings {
	/**
	* Server, auf dem die Datenbank liegt.
	*/
	const DB_server = "localhost";
	
	/**
	* Die Datenbank, die die User Library zum Speichern von Daten nutzen soll.
	*/
	const DB_database = "userlib";
	
	/**
	* Der Nutzer, mit dem sich die User Library auf der Datenbank einloggt.
	*/
	const DB_user = "root";
	
	/**
	* Das Passwort, das die User Library zum Einloggen auf der Datenbank benutzen soll.
	*/
	const DB_password = "root";
	
	/**
	* Das Pr�fix, das vor die Tabellen der User Library gestellt wird.
	*/
	const DB_prefix = "userlib_";
	
	/**
	* Soll ein Login m�glich sein?
	*/
	const login_enabled = true;
	
	/**
	* Soll ein Registrieren mit {@link User::register()} m�glich sein?
	* Ein Registrieren mit {@link User::create()} ist weiterhin m�glich.
	*/
	const register_enabled = true;
	
	/**
	* Ben�tigen neue Benutzer eine Best�tigung durch die Funktion {@link User::approve()}?
	*/
	const need_approval = false;
	
	/**
	* Password algorithm and options
	*/
	const password_algorithm = 'bcrypt';

	const password_salt_length = 20;
	
	const password_cpu_difficulty = 16384;

	const password_mem_difficulty = 8;

	const password_parallel_difficulty = 1;

	const password_key_length = 32;

	const password_rounds = 10;

	/**
	* Wie lang soll der Aktivierungscode sein, der in der Email durch {@link User::register()} verschickt wird?
	*/
	const length_activationcode = 20;
	
	/**
	* Von welcher Email Adresse sollen Emails verschickt werden?
	*/
	const send_mailaddress = "noreply@localhost";
	
	/**
	* Nach wie vielen Sekunden wird ein Benutzer automatisch ausgeloggt?
	*/
	const autologouttime = 50000;
	
	/**
	* Wie viele fehlerhafte Loginversuche darf ein Benutzer machen, ohne das sein Account gesperrt wird?
	*/
	const maxloginattempts = 5;
	
	/**
	* F�r wie lange wird ein Account gesperrt, nachdem zu viele fehlerhafte Loginversuche vorgenommen wurden.
	*/
	const loginblocktime = 3600;
	
	/**
	* Sollen Securesessions eingeschaltet sein?
	* Bei einer Securesession darf die IP-Adresse eines Benutzers w�hrend einer Sitzung nicht wechseln.
	* Securesessions werden f�r die meisten Webseiten nicht empfohlen, weil zum Beispiel Handys mit mobilen Internet h�ufig die IP-Adresse wechseln.
	* Bei lange dauernden Sitzungen kann es durch die Knappheit von IP-Adressen auch sein, das �ber Kabel/WLAN verbundene Computer/Handys ihre �ffentliche IP-Adresse wechseln.
	*/
	const securesessions = false;
}
?>
