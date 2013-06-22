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

/**
* Can be used to access a mongoDB database when having a configuration class (as the Config class) and saved in a global $config variable
*
* @see Config
*/
class DatabaseConnection {
	protected static $databaseConnection = null;
	protected static $databases = array();

	/**
	* Returns a database connection according to configuration.
	* It is assumed that there is a global $config object which has the properties DBServer and DBPort.
	* Once a database connection is created it is stored within the static context and re-used when the method is called again.
	*
	* @return MongoClient
	*/
	public static function getDatabaseConnection()  {
		global $config;
		
		if (static::$databaseConnection != null)
			return static::$databaseConnection;

		static::$databaseConnection = new Mongo($config->DBServer . ':' . $config->DBPort);
		return static::$databaseConnection;
	}

	/**
	* Returns a database according to configuration.
	* If name is specified it returns the database with that name, otherwise the database specified in the configuration is returned.
	* When the $name parameter isn't set it is assumed that there is a global $config object which has a DBDatabase property.
	* Once a database is got it is stored within the static context and re-used when the method is called again.
	* The method uses the getDatabaseConnection method to get a database connection.
	*
	* @param String $name
	* @return MongoDB
	* @see DatabaseConnection::getDatabaseConnection()
	*/
	public static function getDatabase($name=null) {
		global $config;

		if ($name == null)
			$name = $config->DBDatabase;

		if (isset(static::$databases[$name]))
			return static::$databases[$name];

		$dbCon = static::getDatabaseConnection();
		static::$databases[$name] = $dbCon->selectDB($name);
		return static::$databases[$name];
	}
}
