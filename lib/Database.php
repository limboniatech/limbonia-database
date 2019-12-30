<?php
namespace Limbonia;

/**
 * Limbonia Database Class
 *
 * This is an extension to PHP's PDO system for accessing databases
 *
 * @author Lonnie Blansett <lonnie@limbonia.tech>
 * @package Limbonia
 */
class Database extends \PDO
{
  /**
   * List of existing database objects
   *
   * @var array
   */
  private static $hDatabaseObjects = [];

  /**
   * List of database configuration settings
   *
   * @var array
   */
  protected static $hConfig = [];

  /**
   * List of time formats for use in filtering time values
   *
   * @var array
   */
  protected static $hDateTimeFormat =
  [
    'date' => '%Y-%m-%d',
    'time' => '%T',
    'timestamp' => '%Y-%m-%d %T',
    'datetime' => '%Y-%m-%d %T',
    'year' => '%Y'
  ];

  /**
   * List of PDO drivers that do not allow use of database cursors
   *
   * @note These are added as we become aware of them...
   */
  const CURSOR_BLACKLIST = ['mysql'];

  /**
   * List of tables
   *
   * @var array
   */
  protected $aTableList = null;

  /**
   * List of columns per table
   *
   * @var array
   */
  protected static $hColumnList = [];

  /**
   * List of alternate column names that tie back to the canonical column name
   *
   * @var array
   */
  protected $hColumnAlias = [];

  /**
   * Is this instance allowed to use cursors?
   *
   * @var boolean
   */
  protected $bAllowCursor = true;

  /**
   * Return the existing column type from the data passed in
   *
   * @param string|array $xData - Either and array of column data or the actual column type
   * @return string
   */
  public static function columnType($xData)
  {
    return isset($xData['Type']) ? $xData['Type'] : $xData;
  }

  /**
   * Determine of the specified column data matches the specified column type
   *
   * @param string|array $xData - Either and array of column data or the actual column type
   * @param string $sType
   * @return boolean
   */
  public static function columnIs($xData, $sType)
  {
    return preg_match("/$sType/i", self::columnType($xData));
  }

  /**
   * Determine of the specified column is an integer
   *
   * @param string|array $xData - Either and array of column data or the actual column type
   * @return boolen
   */
  public static function columnIsInteger($xData)
  {
    return self::columnIs($xData, 'int');
  }

  /**
   * Determine of the specified column is a float
   *
   * @param string|array $xData - Either and array of column data or the actual column type
   * @return boolen
   */
  public static function columnIsFloat($xData)
  {
    return self::columnIs($xData, 'real|double|float|decimal|numeric');
  }

  /**
   * Determine of the specified column is numeric
   *
   * @param string|array $xData - Either and array of column data or the actual column type
   * @return boolen
   */
  public static function columnIsNumeric($xData)
  {
    return self::columnIsInteger($xData) || self::columnIsFloat($xData);
  }

  /**
   * Determine of the specified column is a date
   *
   * @param string|array $xData - Either and array of column data or the actual column type
   * @return boolen
   */
  public static function columnIsDate($xData)
  {
    return self::columnIs($xData, 'date|time|year');
  }

  /**
   * Determine of the specified column is a string
   *
   * @param string|array $xData - Either and array of column data or the actual column type
   * @return boolen
   */
  public static function columnIsString($xData)
  {
    return self::columnIs($xData, 'char|binary|blob|text|string|enum') || self::columnIsDate($xData);
  }

  /**
   * Return the list of aliases for the given columns
   *
   * @param array $hColumns
   * @return array
   */
  public static function aliasColumns(array $hColumns = [])
  {
    $hAlias = [];

    foreach ($hColumns as $sColumn => $hColumnData)
    {
      $hAlias[\strtolower($sColumn)] = $sColumn;

      if (isset($hColumnData['Key']) && $hColumnData['Key'] == 'Primary')
      {
        $hAlias['id'] = $sColumn;
      }
    }

    return $hAlias;
  }

  /**
   * Filter the specified value to be compatible with the specified type
   *
   * @param string|array $xType - Either and array of column data or the actual column type
   * @param mixed $xValue
   * @return mixed
   */
  public static function filterValue($xType, $xValue)
  {
    $sExtra = null;
    $sType = strtolower(self::columnType($xType));

    if (preg_match("#(.*?)\((.*?)\)#", $sType, $aMatch))
    {
      $sType = $aMatch[1];
      $sExtra = $aMatch[2];
    }

    switch (true)
    {
      case ($sType == 'enum'):
        $aExtra = explode(',', strtolower(preg_replace("/','/", ',', trim($sExtra, "'"))));
        settype($xValue, 'string');
        return in_array(strtolower($xValue), $aExtra) ? $xValue : null;

      case $sType == 'set':
        if (is_array($xValue))
        {
          // this will allow us to lower case the whole thing in the next step
          $xValue = implode(',', $xValue);
        }

        $aSet = explode(',', strtolower($xValue));
        $aRange = explode(',', strtolower(preg_replace("/','/", ',', trim($sExtra, "'"))));
        $aTemp = [];

        foreach ($aSet as $sItem)
        {
          if (in_array(strtolower($sItem), $aRange))
          {
            $aTemp[] = self::quote($sItem);
          }
        }

        return implode(',', array_unique($aTemp));

      case self::columnIsInteger($xType):
        return (integer)$xValue;

      case self::columnIsFloat($xType):
        return (float)$xValue;

      case self::columnIsDate($xType):
        if (empty($xValue) || $xValue == 'CURRENT_TIMESTAMP' || in_array($xValue, ['0000-00-00', '0000-00-00 00:00:00']))
        {
          return null;
        }

        // if the value is numeric and appears to be an integer assume it's a timestamp, otherwise evaluate it as a string
        $iValue = (is_numeric($xValue) && $xValue == (integer)$xValue) ? (integer)$xValue : strtotime($xValue);
        $sTimeFormat = isset(self::$hDateTimeFormat[$sType]) ? self::$hDateTimeFormat[$sType] : '%F %T';
        return strftime($sTimeFormat, $iValue);

      default:
        settype($xValue, 'string');
        // if there is any "extra" and it was on a string, it should be the max length of the string
        return !empty($sExtra) && self::columnIsString($xType) ? substr($xValue, 0, $sExtra) : $xValue;
    }
  }

  /**
   * Prepare the specified value for use in an SQL statement
   *
   * @param string|array $xType - Either and array of column data or the actual column type
   * @param mixed $xValue
   * @return string
   */
  public static function prepareValue($xType, $xValue)
  {
    if (is_null($xValue))
    {
      return 'null';
    }

    $xFilteredValue = self::filterValue($xType, $xValue);

    if (is_null($xFilteredValue))
    {
      return 'null';
    }

    return self::columnIsString($xType) ? "'" . addslashes($xFilteredValue) . "'" : $xFilteredValue;
  }

  /**
   * Generate the "select" part of an SQL statement from the specified column name(s), if any
   *
   * @param string|array $xColumns (optional) - specify either a single column string or an array of column names
   * @return string
   */
  public static function makeSelect($xColumns = null, $sTable = null)
  {
    $aColumn = is_array($xColumns) ? $xColumns : [(string)$xColumns];

    if (count($aColumn) == 1 &&  $aColumn[0] == $sTable)
    {
      return $aColumn[0] . '.*';
    }

    return empty($aColumn) ? '*' : implode(', ', array_unique($aColumn));
  }

  /**
   * Generate the "where" part of an SQL statement from the specified name(s), if any
   *
   * @param string|array $xWhere (optional) - specify either a single string or an array of string
   * @return string
   */
  public static function makeWhere($xWhere = null)
  {
    return empty($xWhere) ? '' : ' WHERE ' . implode(' AND ', (array)$xWhere);
  }

  /**
   * Generate the "order" part of an SQL statement from the specified name(s), if any
   *
   * @param string|array $xOrder (optional) - specify either a single string or an array of string
   * @return string
   */
  public static function makeOrder($xOrder = null)
  {
    return empty($xOrder) ? '' : " ORDER BY " . implode(', ', (array)$xOrder);
  }

  /**
   * Generate an "ID" column name from the specified table name
   *
   * @param string $sTable
   * @return string
   */
  public static function makeIdColumn($sTable)
  {
    return strtolower(preg_replace("/.*_/", '', $sTable)) . 'ID';
  }

  /**
   * Change a flat hash of database options into a DSN string, username, password, and an array with the remaining options
   *
   * @param array $hConfig
   * @return array
   */
  static public function arrayToDSN(array $hConfig = [])
  {
    $hDSN =
    [
      'dsn' => strtolower($hConfig['driver']) . ':',
      'username' => '',
      'password' => '',
      'options' => []
    ];
    unset($hConfig['driver']);

    if (isset($hConfig['host']))
    {
      $hDSN['dsn'] .= "host={$hConfig['host']}";
      unset($hConfig['host']);

      if (isset($hConfig['port']))
      {
        $hDSN['dsn'] .= ";port={$hConfig['port']}";
        unset($hConfig['port']);
      }
    }
    elseif (isset($hConfig['unixsocket']))
    {
      $hDSN['dsn'] .= "unix_socket={$hConfig['unixsocket']}";
      unset($hConfig['unixsocket']);
    }

    if (isset($hConfig['database']))
    {
      $hDSN['dsn'] .= ";dbname={$hConfig['database']}";
      unset($hConfig['database']);
    }

    if (isset($hConfig['charset']))
    {
      $hDSN['dsn'] .= ";charset={$hConfig['charset']}";
      unset($hConfig['charset']);
    }

    if (isset($hConfig['user']))
    {
      $hDSN['username'] = $hConfig['user'];
      unset($hConfig['user']);
    }

    if (isset($hConfig['password']))
    {
      $hDSN['password'] = $hConfig['password'];
      unset($hConfig['password']);
    }

    $hDSN['options'] = $hConfig;
    return $hDSN;
  }

  /**
   * Add either a single database config array or a hash of configs
   *
   * @param array $hConfig - Database configuration data
   * @param string $sName (optional) - Name of the current configuration
   */
  public static function config(array $hConfig, $sName = null)
  {
    $hLowercaseConfig = array_change_key_case($hConfig, CASE_LOWER);

    if (isset($hLowercaseConfig['driver']))
    {
      if (isset($hLowercaseConfig['name']))
      {
        $sName = $hLowercaseConfig['name'];
        unset($hLowercaseConfig['name']);
      }

      ksort($hLowercaseConfig);

      if (empty($sName))
      {
        $sName = md5(serialize($hLowercaseConfig));
      }

      //if this is the first config and it isn't already named default
      if (count(self::$hConfig) === 0 && $sName !== 'default')
      {
        //then use it as the current default, though it can be overridden by a new config named 'default'
        self::$hConfig['default'] = $hLowercaseConfig;
      }

      self::$hConfig[$sName] = $hLowercaseConfig;
    }
    else
    {
      foreach ($hConfig as $xIndex => $hSubconfig)
      {
        $sName = \is_string($xIndex) ? $xIndex : null;
        self::config($hSubconfig, $sName);
      }
    }
  }

  /**
   * Generate and return a database object based on the specified database config section
   *
   * @param string $sSection (optional)
   * @throws \Limbonia\Exception\Database
   * @return \Limbonia\Database
   */
  public static function getDB($sSection = 'default')
  {
    if (empty(self::$hConfig))
    {
      throw new Exception\Database("Database not configured");
    }

    if (empty($sSection) || !isset(self::$hConfig[$sSection]))
    {
      $sSection = 'default';
    }

    if (!isset(self::$hConfig[$sSection]))
    {
      throw new Exception\Database("Default database not configured");
    }

    return self::factory(self::$hConfig[$sSection]);
  }

  /**
   * Generate an instance of the Limbonia Database object based on the specified configuration
   *
   * @param array $hConfig
   * @throws \Limbonia\Exception\Database
   * @return \Limbonia\Database
   */
  static public function factory(array $hConfig): \Limbonia\Database
  {
    $hLowercaseConfig = array_change_key_case($hConfig, CASE_LOWER);

    if (empty($hLowercaseConfig['driver']))
    {
      throw new Exception\Database('A valid SQL driver name was not found!');
    }

    ksort($hLowercaseConfig);
    $sConfigHash = md5(serialize($hLowercaseConfig));

    if (isset(self::$hDatabaseObjects[$sConfigHash]))
    {
      return self::$hDatabaseObjects[$sConfigHash];
    }

    $hDSN = self::arrayToDSN($hLowercaseConfig);
    self::$hDatabaseObjects[$sConfigHash] = new self($hDSN['dsn'], $hDSN['username'], $hDSN['password'], $hDSN['options']);

    return self::$hDatabaseObjects[$sConfigHash];
  }

  /**
   * Creates a Database instance representing a connection to a database
   *
   * @param string $sDSN
   * @param string $sUsername (optional)
   * @param string $sPassword (optional)
   * @param array $hOptions (optional)
   */
  public function __construct($sDSN, $sUsername = null, $sPassword = null, $hOptions = null)
  {
    parent::__construct($sDSN, $sUsername, $sPassword, $hOptions);
    $this->setAttribute(\PDO::ATTR_STATEMENT_CLASS, ['\Limbonia\Result\Database', [$this]]);
    $this->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $this->bAllowCursor = !in_array($this->getType(), self::CURSOR_BLACKLIST);
  }

  /**
   * Prepares a statement for execution and returns a statement object
   *
   * @param string $sStatement <p>
   * This must be a valid SQL statement template for the target database server.
   * </p>
   * @param array $hDriverOptions (optional) <p>
   * This array holds one or more key=&gt;value pairs to set
   * attribute values for the PDOStatement object that this method
   * returns. You would most commonly use this to set the
   * PDO::ATTR_CURSOR value to
   * PDO::CURSOR_SCROLL to request a scrollable cursor.
   * Some drivers have driver specific options that may be set at
   * prepare-time.
   * </p>
   * @return \Limbonia\Result\Database If the database server successfully prepares the statement,
   * <b>PDO::prepare</b> returns a
   * <b>PDOStatement</b> object.
   * If the database server cannot successfully prepare the statement,
   * <b>PDO::prepare</b> returns <b>FALSE</b> or emits
   * <b>PDOException</b> (depending on error handling).
   * </p>
   *
   * @link http://php.net/manual/en/pdo.prepare.php
   */
  public function prepare($sStatement, $hDriverOptions = null)
  {
    if ($this->bAllowCursor)
    {
      if (is_array($hDriverOptions))
      {
        $hDriverOptions[\PDO::ATTR_CURSOR] = \PDO::CURSOR_SCROLL;
      }
      else
      {
        $hDriverOptions = [\PDO::ATTR_CURSOR => \PDO::CURSOR_SCROLL];
      }
    }
    elseif (empty($hDriverOptions))
    {
      $hDriverOptions = [];
    }

    return parent::prepare($sStatement, $hDriverOptions);
  }

  /**
   * Executes an SQL statement, returning a result set as a PDOStatement object
   *
   * @param string $sStatement <p>
   * The SQL statement to prepare and execute.
   * </p>
   * @param array $aInputParameters (optional) <p>
   * An array of values with as many elements as there are bound
   * parameters in the SQL statement being executed.
   * All values are treated as <b>PDO::PARAM_STR</b>.
   * </p>
   *
   * @return \Limbonia\Result\Database returns a \Limbonia\Result\Database object on success, or <b>false</b> on failure.
   *
   * @link http://php.net/manual/en/pdo.query.php
   */
  public function query(string $sStatement, array $aInputParameters = [])
  {
    $oResult = $this->prepare($sStatement);
    $oResult->execute($aInputParameters);
    return $oResult;
  }

  /**
   * Return the driver type for this object
   *
   * @return string
   */
  public function getType()
  {
    return $this->getAttribute(\PDO::ATTR_DRIVER_NAME);
  }

  /**
   * Is this instance allowed to use cursors?
   *
   * @return bool Return true if cursors are allowed and false if not...
   */
  public function allowCursor()
  {
    return $this->bAllowCursor;
  }

  /**
   * The MySQL implementation for the getSchema method
   *
   * @param string $sTable
   * @return string
   */
  protected function mysqlGetSchema($sTable)
  {
    $sShowCreateSQL = "SHOW CREATE TABLE $sTable";
    $oResult = $this->query($sShowCreateSQL);
    return preg_replace("#  #", '', preg_replace("#\n\) ENGINE=.*$#", '', preg_replace("#^.*CREATE TABLE `$sTable` \(\n#", '', $oResult->fetchAll()[0]['Create Table'])));
  }

  /**
   * Returns a schema description of the specified table
   *
   * @param string $sTable
   * @return string
   * @throws Exception\Database
   */
  public function getSchema($sTable)
  {
    $sMethod = $this->getType() . 'GetDatabases';

    if (!method_exists($this, $sMethod))
    {
      throw new Exception\Database(__METHOD__ . ": Can not get the schema of this type, yet.", $this->getType());
    }

    return $this->$sMethod($sTable);
  }

  /**
   * The MySQL implementation for the createTable method
   *
   * @param string $sName - name for the table to create
   * @param string $sSchema
   * @param string $sDatabase (optional) - name of the database to create the table in, defaults to the "current" database for the connection
   * @throws Exception\Database
   */
  protected function mysqlCreateTable($sName, $sSchema, $sDatabase = '')
  {
    if (!empty($sDatabase))
    {
      $sDatabase .= '.';
    }

    $sCreateSQL = "CREATE TABLE IF NOT EXISTS $sDatabase$sName ($sSchema)";

    if (false === $this->exec($sCreateSQL))
    {
      throw new Exception\Database(__METHOD__ . ": Faied to create table: $sName.", $this->getType());
    }
  }

  /**
   * Create a new table based on the specified parameters
   *
   * @param string $sName - name for the table to create
   * @param string $sSchema
   * @param string $sDatabase (optional) - name of the database to create the table in, defaults to the "current" database for the connection
   * @throws Exception\Database
   */
  public function createTable($sName, $sSchema, $sDatabase = '')
  {
    if ($this->hasTable($sName, $sDatabase))
    {
      return;
    }

    $sMethod = $this->getType() . 'CreateTable';

    if (!method_exists($this, $sMethod))
    {
      throw new Exception\Database(__METHOD__ . ": Can not create tables of this type, yet.", $this->getType());
    }

    $this->$sMethod($sName, $sSchema, $sDatabase);

    if (is_null($this->aTableList))
    {
      $this->aTableList = [];
    }

    $this->aTableList[] = $sName;
  }

  /**
   * The MySQL implementation for the getDatabases method
   *
   * @return array
   * @throws Exception\Database
   */
  protected function mysqlGetDatabases()
  {
    $sDatabasesSQL = 'SHOW DATABASES';
    $oDatabases = $this->query($sDatabasesSQL);
    $aDatabases = [];

    foreach ($oDatabases as $hRow)
    {
      $aDatabases[] = array_shift($hRow);
    }

    return $aDatabases;
  }

  /**
   * Return the list of databases that the current connection has access to
   *
   * @return array
   * @throws Exception\Database
   */
  public function getDatabases()
  {
    $sMethod = $this->getType() . 'GetDatabases';

    if (!method_exists($this, $sMethod))
    {
      throw new Exception\Database(__METHOD__ . ": Can not list databases of this type, yet.", $this->getType());
    }

    return $this->$sMethod();
  }

  /**
   * Determine of the specified table is in the specified database
   *
   * @param string $sTable - name of the table to check
   * @param string $sDatabase (optional) - the database used to check for the table (if none is specified then use the "current" database)
   * @return boolean
   */
  public function hasTable($sTable, $sDatabase = '')
  {
    $aTable = $this->getTables($sDatabase);
    return in_array($sTable, $aTable);
  }

  /**
   * The MySQL implementation for the getTables method
   *
   * @param string $sDatabase (optional) - the database used to get the table list (if none is specified then use the "current" database)
   * @return array
   * @throws Exception\Database
   */
  protected function mysqlGetTables($sDatabase = '')
  {
    $aTableList = [];
    $sTableSQL = empty($sDatabase) ? 'SHOW TABLES' : "SHOW TABLES FROM $sDatabase";
    $oGetTables = $this->query($sTableSQL);

    foreach ($oGetTables as $aRow)
    {
      $aTableList[] = array_shift($aRow);
    }

    return $aTableList;
  }

  /**
   * Generate the array of table names in the specified database
   *
   * @param string $sDatabase (optional) - the database used to get the table list (if none is specified then use the "current" database)
   * @return array
   * @throws Exception\Database
   */
  public function getTables($sDatabase = '')
  {
    if (is_null($this->aTableList))
    {
      $this->aTableList = [];
      $sMethod = $this->getType() . 'GetTables';

      if (!method_exists($this, $sMethod))
      {
        throw new Exception\Database(__METHOD__ . ": Can not list tables from this type, yet.", $this->getType());
      }

      $this->aTableList = $this->$sMethod($sDatabase);
    }

    return $this->aTableList;
  }

  /**
   * The MySQL implementation for the getColumns method
   *
   * @param string $sTable - name of the table to get column data from
   * @param boolean $bUseTableName (optional) - Should the table name be prepended to each column name (defaults to false)
   * @return array
   * @throws \Limbonia\Exception\Database
   */
  protected function mysqlGetColumns($sTable)
  {
    $sColumnSQL = "DESC $sTable";
    $oGetColumns = $this->query($sColumnSQL);
    $hColumnList = [];

    foreach ($oGetColumns as $hRow)
    {
      $hColumnList[$hRow['Field']]['Type'] = $hRow['Type'];
      $hRow['Key'] = trim($hRow['Key']);

      if (!empty($hRow['Key']))
      {
        $hColumnList[$hRow['Field']]['Key'] = str_replace('PRI', 'Primary', $hRow['Key']);
        $hColumnList[$hRow['Field']]['Key'] = str_replace('MUL', 'Multi', $hColumnList[$hRow['Field']]['Key']);
      }

      $hColumnList[$hRow['Field']]['Default'] = trim($hRow['Default']);
      $hRow['Extra'] = trim($hRow['Extra']);

      if (!empty($hRow['Extra']))
      {
        $hColumnList[$hRow['Field']]['Extra'] = $hRow['Extra'];
      }
    }

    return $hColumnList;
  }

  /**
   * Generate the list of column data from the specified table
   *
   * @param string $sTable - name of the table to get column data from
   * @param boolean $bUseTableName (optional) - Should the table name be prepended to each column name (defaults to false)
   * @return array
   * @throws \Limbonia\Exception\Database
   */
  public function getColumns($sTable, $bUseTableName = false)
  {
    if (!isset(self::$hColumnList[$sTable]))
    {
      unset($_SESSION['LimboniaTableColumns'][$sTable]);
      if (SessionManager::isStarted() && isset($_SESSION['LimboniaTableColumns'][$sTable]))
      {
        self::$hColumnList[$sTable] = $_SESSION['LimboniaTableColumns'][$sTable];
      }
      else
      {
        $sMethod = $this->getType() . 'GetColumns';

        if (!method_exists($this, $sMethod))
        {
          throw new Exception\Database(__METHOD__ . ": Can not list columns from this database type, yet.", $this->getType());
        }

        self::$hColumnList[$sTable] = $this->$sMethod($sTable);

        if (SessionManager::isStarted())
        {
          if (!isset($_SESSION['LimboniaTableColumns']))
          {
            $_SESSION['LimboniaTableColumns'] = [];
          }

          $_SESSION['LimboniaTableColumns'][$sTable] = self::$hColumnList[$sTable];
        }
      }
    }

    if ((boolean)$bUseTableName)
    {
      $hWithTableName = [];

      foreach (self::$hColumnList[$sTable] as $sName => $hColumn)
      {
        $hWithTableName["$sTable.$sName"] = $hColumn;
      }

      return $hWithTableName;
    }

    return self::$hColumnList[$sTable];
  }

  /**
   * Return the list of valid column aliases for the specified table
   *
   * @param string $sTable
   * @return array
   */
  public function getAliasColumns($sTable): array
  {
    if (isset($this->hColumnAlias[$sTable]))
    {
      return $this->hColumnAlias[$sTable];
    }

    if (SessionManager::isStarted() && isset($_SESSION['LimboniaTableColumnAlias'][$sTable]))
    {
      $this->hColumnAlias[$sTable] = $_SESSION['LimboniaTableColumnAlias'][$sTable];
      return $this->hColumnAlias[$sTable];
    }

    $this->hColumnAlias[$sTable] = self::aliasColumns($this->getColumns($sTable));

    if (SessionManager::isStarted())
    {
      $_SESSION['LimboniaTableColumnAlias'][$sTable] = $this->hColumnAlias[$sTable];
    }

    return $this->hColumnAlias[$sTable];
  }

  /**
   * Does the specified table contain the specified column?
   *
   * @note This function even checks of the specified column name is an alias of a real column name
   *
   * @param string $sTable
   * @param string $sColumn
   * @return mixed Returns the real column name if it exists or false if it doesn't
   */
  public function hasColumn($sTable, $sColumn)
  {
    $hColumnAlias = $this->getAliasColumns($sTable);
    $sLowerColumn = \strtolower($sColumn);
    return isset($hColumnAlias[$sLowerColumn]) ? $hColumnAlias[$sLowerColumn] : false;
  }

  /**
   * Return the column data for the specified column in the specified table, if there is any
   *
   * @param string $sTable
   * @param string $sColumn
   * @return array
   */
  public function getColumnData($sTable, $sColumn): array
  {
    $sRealColumn = $this->hasColumn($sTable, $sColumn);
    return $sRealColumn ? $this->getColumns($sTable)[$sRealColumn] : [];
  }

  /**
   * Return the ID column for the specified table, if there is one
   *
   * @param string $sTable
   * @return string
   */
  public function getIdColumn($sTable): string
  {
    $sIdColumn = $this->hasColumn($sTable, 'id');
    return empty($sIdColumn) ? self::makeIdColumn($sTable) : $sIdColumn;
  }

  /**
   * Return the list of real column names from the specified list of columns
   *
   * @param string $sTable
   * @param string|array $xColumns Either a single column name or an array of column names
   * @param bool $bPrependTable
   * @return array
   */
  public function verifyColumns($sTable, $xColumns, $bPrependTable = false): array
  {
    $aColumns = [];

    if (!empty($xColumns))
    {
      $sTableName = (boolean)$bPrependTable ? "$sTable." : '';
      $aTemp = is_array($xColumns) ? $xColumns : [(string)$xColumns];

      foreach ($aTemp as $sColumn)
      {
        $sRealColumn = $this->hasColumn($sTable, $sColumn);

        if ($sRealColumn)
        {
          $aColumns[] = $sTableName . $sRealColumn;
        }
      }
    }

    return (boolean)$bPrependTable && empty($aColumns) ? [$sTable] : $aColumns;
  }

  /**
   * Validate the specified where array against the specified table, returning only valid fully qualified where options
   *
   * @param string $sTable
   * @param array $hWhere
   * @param bool $bPrependTable
   * @return array
   */
  public function verifyWhere($sTable, array $hWhere = null, $bPrependTable = false): array
  {
    $aWhere = [];

    if (!empty($hWhere) && is_array($hWhere))
    {
      $sTableName = (boolean)$bPrependTable ? "$sTable." : '';
      $hColumns = $this->getColumns($sTable);

      foreach ($hWhere as $sColumn => $xValue)
      {
        $sRealColumn = $this->hasColumn($sTable, $sColumn);

        if (empty($sRealColumn))
        {
          continue;
        }

        if (is_array($xValue))
        {
          foreach ($xValue as $iKey => $sValue)
          {
            $xValue[$iKey] = self::prepareValue($hColumns[$sRealColumn], $sValue);
          }

          $sList = implode(', ', $xValue);
          $aWhere[] = "$sTableName$sRealColumn IN ($sList)";
        }
        else
        {
          $sOperator = '=';

          if (preg_match("/(.*?):(.*)/i", $xValue, $aMatch))
          {
            $sOperator = strtoupper($aMatch[1]);
            $xValue = $aMatch[2];
          }

          if (in_array($sOperator, ['IS', 'IS NOT']) && empty($xValue))
          {
            $xValue = null;
          }

          $aWhere[] = "$sTableName$sRealColumn $sOperator " . self::prepareValue($hColumns[$sRealColumn], $xValue);
        }
      }
    }

    return $aWhere;
  }

  /**
   * Validate the specified order data against the specified table, returning only valid fully qualified order options
   *
   * @param string $sTable
   * @param string|array $xOrder Either a single order string or an array of order strings
   * @param bool $bPrependTable
   * @return array
   */
  public function verifyOrder($sTable, $xOrder, $bPrependTable = false): array
  {
    $aOrder = [];

    if (!empty($xOrder))
    {
      $sTableName = (boolean)$bPrependTable ? "$sTable." : '';
      $aTemp = is_array($xOrder) ? $xOrder : [(string)$xOrder];

      foreach ($aTemp as $sOrder)
      {
        $aOrderParts = explode(' ', $sOrder);
        $sRealColumn = $this->hasColumn($sTable, $aOrderParts[0]);

        if ($sRealColumn)
        {
          $sDirection = $aOrderParts[1] ?? 'ASC';
          $aOrder[] = "$sTableName$sRealColumn $sDirection";
        }
      }
    }

    return $aOrder;
  }

  /**
   * Generate and return an SQL select query based on the passed parameters
   *
   * @param string $sTable
   * @param string|array $xColumns
   * @param array $hWhere
   * @param string|array $xOrder
   * @return string
   * @throws \Limbonia\Exception\Database
   */
  public function makeSearchQuery($sTable, $xColumns = null, $hWhere = [], $xOrder = null)
  {
    if (!$this->hasTable($sTable))
    {
      throw new \Limbonia\Exception\Database("The table ($sTable) does not exist", $this->getType());
    }

    $sColumns = self::makeSelect($this->verifyColumns($sTable, $xColumns));
    $sOrder = self::makeOrder($this->verifyOrder($sTable, $xOrder));
    $sWhere = self::makeWhere($this->verifyWhere($sTable, $hWhere));
    return "SELECT DISTINCT $sColumns FROM $sTable$sWhere$sOrder";
  }

  /**
   * Get a row of data from the specified table using the specified id,
   * if column(s) are specified then data from only those columns will be returned
   *
   * @param string $sTable - name of the table to get the data from
   * @param integer $iID - the ID of the the row to get the data from
   * @param string|array $xColumns (optional) - specify either a single column string or an array of column names
   * @return array
   */
  public function getRowById($sTable, $iID, $xColumns = null)
  {
    settype($iID, 'integer');
    $oResult = $this->prepare('SELECT ' . self::makeSelect($xColumns) . " FROM $sTable WHERE " . $this->getIdColumn($sTable) . ' = :ItemId LIMIT 1');
    $oResult->bindParam(':ItemId', $iID, \PDO::PARAM_INT);
    return $oResult->execute() ? $oResult->fetch() : [];
  }

  /**
   * Insert the specified data into the specified table
   *
   * @param string $sTable
   * @param array $hData
   * @return integer - returns the last inserted id
   * @throws \Limbonia\Exception\DBResult
   */
  public function insert($sTable, $hData)
  {
    $aValue = [];
    $hColumns = $this->getColumns($sTable);
    $aUsedColumn = [];

    foreach ($hColumns as $sName => $hColumnData)
    {
      if (isset($hData[$sName]))
      {
        $aUsedColumn[] = $sName;
        $aValue[] = Database::prepareValue($hColumnData, $hData[$sName]);
      }
    }

    $sSQL = "INSERT INTO {$sTable} (" . implode(',', $aUsedColumn) . ") VALUES (" . implode(',', $aValue) . ")";
    $iRowsAffected = $this->exec($sSQL);

    if (empty($iRowsAffected))
    {
      $aError = $this->errorInfo();
      throw new \Limbonia\Exception\DBResult("Data not inserted into $sTable: {$aError[0]} - {$aError[2]}", $this->getType(), $sSQL, $aError[1]);
    }

    return $this->lastInsertId();
  }

  /**
   * Update the specified row in the specified table with the specified data
   *
   * @param string $sTable
   * @param integer $iID
   * @param array $hData
   * @return integer Return the row ID
   * @throws \Limbonia\Exception\Database|\Limbonia\Exception\DBResult
   */
  public function update($sTable, $iID, $hData)
  {
    settype($iID, 'integer');
    $aSet = [];
    $hColumns = $this->getColumns($sTable);

    foreach ($hData as $sColumn => $xValue)
    {
      $sRealColumn = $this->hasColumn($sTable, $sColumn);

      if ($sRealColumn)
      {
        $aSet[] = $sColumn . ' = ' . self::prepareValue($hColumns[$sRealColumn], $xValue);
      }
    }

    if (empty($aSet))
    {
      throw new \Limbonia\Exception\Database("Item #$iID not updated in $sTable: No valid data found", $this->getType());
    }

    $sSQL = "UPDATE {$sTable} SET " . implode(',', $aSet) . ' WHERE ' . $this->getIdColumn($sTable) ." = $iID";
    $iRowsAffected = $this->exec($sSQL);

    if (empty($iRowsAffected))
    {
      $aError = $this->errorInfo();

      if ($aError[0] != '00000')
      {
        throw new \Limbonia\Exception\DBResult("Item #$iID not updated in $sTable: {$aError[0]} - {$aError[2]}", $this->getType(), $sSQL, $aError[1]);
      }
    }

    return $iID;
  }

  /**
   * Delete the specified row from the specified table
   *
   * @param string $sTable
   * @param integer $iID
   * @return boolean
   * @throws \Limbonia\Exception\DBResult
   */
  public function delete($sTable, $iID)
  {
    settype($iID, 'integer');
    $sSQL = "DELETE FROM $sTable WHERE " . $this->getIdColumn($sTable) . " = $iID";
    $iRowsAffected = $this->exec($sSQL);

    if ($iRowsAffected === false)
    {
      $aError = $this->errorInfo();
      throw new \Limbonia\Exception\DBResult("Item #$iID not deleted from $sTable: {$aError[0]} - {$aError[2]}", $this->getType(), $sSQL, $aError[1]);
    }

    return true;
  }
}