<?php if (!defined('APPLICATION')) exit();
/*
Copyright 2008, 2009 Mark O'Sullivan
This file is part of Garden.
Garden is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
Garden is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
You should have received a copy of the GNU General Public License along with Garden.  If not, see <http://www.gnu.org/licenses/>.
Contact Mark O'Sullivan at mark [at] lussumo [dot] com
*/

/**
 * The Database object contains connection and engine information for a single database.
 * It also allows a database to execute string sql statements against that database.
 *
 * @author Todd Burry
 * @copyright 2003 Mark O'Sullivan
 * @license http://www.opensource.org/licenses/gpl-2.0.php GPL
 * @version @@GARDEN-VERSION@@
 * @namespace Lussumo.Garden.Database
 */

require_once(dirname(__FILE__).DS.'class.dataset.php');

class Gdn_Database {
   /// CONSTRUCTOR ///

   /** @param mixed $Config The configuration settings for this object.
    *  @see Database::Init()
    */
   public function __construct($Config = NULL) {
      $this->ClassName = get_class($this);
      $this->Init($Config);
   }
   
   /// PROPERTIES ///
   
   /** @var string The instance name of this class or the class that inherits from this class. */
   public $ClassName;
	
	private $_CurrentResultSet;
   
   /** @var PDO The connectio to the database. */
   protected $_Connection = FALSE;
   
   /** Get the PDO connection to the database.
    * @return PDO The connection to the database.
    */
   public function Connection() {
      if($this->_Connection === FALSE) {
			try {
				$this->_Connection = new PDO(strtolower($this->Engine) . ':' . $this->Dsn, $this->User, $this->Password, $this->ConnectionOptions);
			} catch (Exception $ex) {
				trigger_error(ErrorMessage('An error occurred while attempting to connect to the database', $this->ClassName, 'Connection', $ex->getMessage()), E_USER_ERROR);
			}
      }
      
      return $this->_Connection;
   }
   
   /** @var array The connection options passed to the PDO constructor **/
   public $ConnectionOptions;
   
   /** @var string The prefix to all database tables. */
   public $DatabasePrefix;
   
   /** @var string The PDO dsn for the database connection.
    *  Note: This does NOT include the engine before the dsn.
    */
   public $Dsn;
   
   /** @var string The name of the database engine for this class. */
   public $Engine;
   
   /** @var string The password to the database. */
   public $Password;
   
   /** @var string The username connecting to the database. */
   public $User;
   
   /// METHODS ///
   
   public function CloseConnection() {
      if (Gdn::Config('Garden.Database.PersistentConnection') !== TRUE) {
         $this->_Connection = null;
      }
   }
   
   /**
    * Initialize the properties of this object.
	 * @param mixed $Config The database is instantiated differently depending on the type of $Config:
	 * - <b>null</b>: The database stored in the factory location Gdn:AliasDatabase will be used.
	 * - <b>string</b>: The name of the configuration section to get the connection information from.
	 * - <b>array</b>: The database properties will be set from the array. The following items can be in the array:
	 *   - <b>Engine</b>: Required. The name of the database engine (MySQL, pgsql, sqlite, odbc, etc.
	 *   - <b>Dsn</b>: Optional. The dsn for the connection. If the dsn is not supplied then the connectio information below must be supplied.
	 *   - <b>Host, Dbname</b>: Optional. The individual database connection options that will be build into a dsn.
	 *   - <b>User</b>: The username to connect to the datbase.
	 *   - <b>Password</b>: The password to connect to the database.
	 *   - <b>ConnectionOptions</b>: Other PDO connection attributes.
	 */
   public function Init($Config = NULL) {
		if(is_null($Config))
			$Config = Gdn::Config('Database');
		elseif(is_string($Config))
			$Config = Gdn::Config($Config);
			
		$DefaultConfig = Gdn::Config('Database');
			
		$this->Engine = ArrayValue('Engine', $Config, $DefaultConfig['Engine']);
		$this->User = ArrayValue('User', $Config, $DefaultConfig['User']);
		$this->Password = ArrayValue('Password', $Config, $DefaultConfig['Password']);
		$this->ConnectionOptions = ArrayValue('ConnectionOptions', $Config, $DefaultConfig['ConnectionOptions']);
      $this->DatabasePrefix = ArrayValue('DatabasePrefix', $Config, ArrayValue('Prefix', $Config, $DefaultConfig['DatabasePrefix']));
		
		if(array_key_exists('Dsn', $Config)) {
         // Get the dsn from the property.
			$this->Dsn = $Config['Dsn'];
		} else {	
			$Host = ArrayValue('Host', $Config, ArrayValue('Host', $DefaultConfig, ''));
			
			// Was the port explicitly defined in the config?
			$Port = ArrayValue('Port', $Config, '');
			if ($Port != '') {
				$Dsn .= 'port='.$Port.';';
			} else {
				// Was the port explicitly defined with the host name? (ie. 127.0.0.1:3306)
				$Dsn = 'host=%s;dbname=%s;';
				$Host = $Config['Host'];
				$Host = explode(':', $Host);
				$Port = count($Host) == 2 ? $Host[1] : '';
				$Host = $Host[0];
				if ($Port != '')
					$Dsn .= 'port='.$Port.';';
			}
            
         $this->Dsn = sprintf($Dsn, $Host, $Config['Dbname']);
		}
   }
   
   /**
    * Executes a string of SQL. Returns a @@DataSet object.
    *
    * @param string $Sql A string of SQL to be executed.
    * @param array $InputParameters An array of values with as many elements as there are bound parameters in the SQL statement being executed.
    */
   public function Query($Sql, $InputParameters = NULL) {
      if ($Sql == '')
         trigger_error(ErrorMessage('Database was queried with an empty string.', $this->ClassName, 'Query'), E_USER_ERROR);

      // Run the Query
      if (!is_null($InputParameters) && count($InputParameters) > 0) {
			// Make sure other unbufferred queries are not open
			if (is_object($this->_CurrentResultSet)) {
				$this->_CurrentResultSet->FetchAllRows();
				$this->_CurrentResultSet->FreePDOStatement(FALSE);
			}

			$PDOStatement = $this->Connection()->prepare($Sql);

			if (!is_object($PDOStatement)) {
				$ErrorInfo = $this->Connection()->errorInfo();
				trigger_error(ErrorMessage('PDO Statement failed to prepare', $this->ClassName, 'Query', $ErrorInfo[2]), E_USER_ERROR);
			}

         if ($PDOStatement->execute($InputParameters) === FALSE) {
            $Error = $PDOStatement->errorInfo();
            trigger_error(ErrorMessage($Error[2], $this->ClassName, 'Query', $Sql), E_USER_ERROR);
         }
      } else {
         $PDOStatement = $this->Connection()->query($Sql);
      }

      if ($PDOStatement === FALSE) {
         $Error = $this->Connection()->errorInfo();
         trigger_error(ErrorMessage($Error[2], $this->ClassName, 'Query', $Sql), E_USER_ERROR);
      }
      
      $Result = TRUE;
      // Did this query modify data in any way?
      if (preg_match('/^\s*"?(insert)\s+/i', $Sql)) {
         $this->_CurrentResultSet = $this->Connection()->lastInsertId(); // TODO: APPARENTLY THIS IS NOT RELIABLE WITH DB'S OTHER THAN MYSQL
      } else {
         // TODO: LOOK INTO SEEING IF AN UPDATE QUERY CAN RETURN # OF AFFECTED RECORDS?

         if (!preg_match('/^\s*"?(update|delete|replace|create|drop|load data|copy|alter|grant|revoke|lock|unlock)\s+/i', $Sql)) {
            // Create a DataSet to manage the resultset
            $this->_CurrentResultSet = new Gdn_DataSet();
            $this->_CurrentResultSet->Connection = $this->Connection();
            $this->_CurrentResultSet->PDOStatement($PDOStatement);
         }
      }
      
      return $this->_CurrentResultSet;
   }
   
   /**
    * Get the database driver class for the database.
    * @return Gdn_DatabaseDriver The database driver class associated with this database.
    */
   public function SQL() {
      $Name = $this->Engine . 'Driver';
      $Result = Gdn::Factory($Name);
      $Result->Database = $this;
      
      return $Result;
   }
   
   /**
    * Get the database structure class for this database.
    * 
    * @return Gdn_DatabaseStructure The database structure class for this database.
    */
   public function Structure() {
      $Name = $this->Engine . 'Structure';
      $Result = Gdn::Factory($Name);
      $Result->Database = $this;
      
      return $Result;
   }
}