<?php
namespace Limbonia\Result;

/**
 * Limbonia Database Result Class
 *
 * This is an extension to PHP's PDOStatement system for accessing database results
 *
 * @author Lonnie Blansett <lonnie@limbonia.tech>
 * @package Limbonia
 */
class Database extends \PDOStatement implements \Limbonia\Interfaces\Result, \ArrayAccess, \Countable
{
  use \Limbonia\Traits\Result;

  /**
   * The query used to calculate the number of rows in a select statement
   */
  const COUNT_QUERY = 'SELECT FOUND_ROWS()';

  /**
   * The parent database object
   *
   * @var \Limbonia\Database
   */
  protected $oDatabase = null;

  /**
   * The entire result set data
   *
   * @NOTE Until the fetch with offsets bug is fixed in MySQL this variable will be needed
   *
   * @var array
   */
  protected $aData = [];

  /**
   * Instantiate the the result set
   *
   * @param \Limbonia\Database $oDatabase
   */
  protected function __construct(\Limbonia\Database $oDatabase)
  {
    $this->oDatabase = $oDatabase;
    $this->setFetchMode(\PDO::FETCH_ASSOC);
  }

  /**
   * Executes a prepared statement
   *
   * @param array $aInputParameters (optional) <p>
   * An array of values with as many elements as there are bound
   * parameters in the SQL statement being executed.
   * All values are treated as <b>PDO::PARAM_STR</b>.
   * </p>
   *
   * @return bool <b>true</b> on success or <b>false</b> on failure.
   *
   * @link http://php.net/manual/en/pdostatement.execute.php
   */
  public function execute($aInputParameters = null)
  {
    $bSuccess = parent::execute($aInputParameters);
    $this->iCurrentRow = -1;
    $this->aData = [];

    if (preg_match("/^select/i", $this->queryString))
    {
      $this->iRowCount = self::COUNT_QUERY == $this->queryString ? 1 : $this->oDatabase->query(self::COUNT_QUERY)->fetchColumn();
    }

    return $bSuccess;
  }

  /**
   * Return an array of all the data in the result set
   *
   * @return array on success or false on failure
   */
  public function getData()
  {
    if ($this->oDatabase->allowCursor())
    {
      return $this->fetchAll();
    }

    if (count($this->aData) < $this->iRowCount)
    {
      $this->aData = $this->fetchAll();
    }

    return $this->aData;
  }

  /**
   * Return the parent database object
   *
   * @return \Limbonia\Database
   */
  public function getDatabase()
  {
    return $this->oDatabase;
  }

  /**
   * Fetches the next row from a result set
   *
   * @param int $iFetchStyle (optional) <p>
   * Controls how the next row will be returned to the caller. This value
   * must be one of the PDO::FETCH_* constants,
   * defaulting to value of PDO::ATTR_DEFAULT_FETCH_MODE
   * (which defaults to PDO::FETCH_ASSOC).
   * @param int $iCursorOrientation (optional) <p>
   * For a PDOStatement object representing a scrollable cursor, this
   * value determines which row will be returned to the caller. This value
   * must be one of the PDO::FETCH_ORI_* constants,
   * defaulting to PDO::FETCH_ORI_NEXT.
   * </p>
   * @param int $iCursorOffset (optional)
   * @return mixed The return value of this function on success depends on the fetch style. In
   * all cases, <b>false</b> is returned on failure.
   *
   * @link http://php.net/manual/en/pdostatement.fetch.php
   */
  public function fetch($iFetchStyle = null, $iCursorOrientation = \PDO::FETCH_ORI_NEXT, $iCursorOffset = 0)
  {
    if ($this->oDatabase->allowCursor())
    {
      return parent::fetch($iFetchStyle, $iCursorOrientation, $iCursorOffset);
    }

    //simulate cursor...
    switch ($iCursorOrientation)
    {
      case \PDO::FETCH_ORI_ABS:
        //use the existing $iCursorOffset, so do nothing
        break;

      case \PDO::FETCH_ORI_FIRST:
        $iCursorOffset = 0;
        break;

      case \PDO::FETCH_ORI_LAST:
        $iCursorOffset = $this->count() - 1;
        break;

      case \PDO::FETCH_ORI_NEXT:
        $iCursorOffset = $this->iCurrentRow + 1;
        break;

      case \PDO::FETCH_ORI_PRIOR:
        $iCursorOffset = $this->iCurrentRow - 1;
        break;

      case \PDO::FETCH_ORI_REL:
        $iCursorOffset += $this->iCurrentRow;
        break;
    }

    //if we're looking for an offset larger than the data
    if ($iCursorOffset > $this->count() - 1 || $iCursorOffset < 0)
    {
      //then the fetch fails...
      return false;
    }

    if (!isset($this->aData[$iCursorOffset]))
    {
      if (count($this->aData) + 1 < $iCursorOffset)
      {
        for ($iNewOffset = count($this->aData) + 1; $iNewOffset < $iCursorOffset - 1; $iNewOffset++)
        {
          $this->aData[$iNewOffset] = parent::fetch($iFetchStyle);
        }
      }

      $this->aData[$iCursorOffset] = parent::fetch($iFetchStyle);
    }

    $this->iCurrentRow = $iCursorOffset;
    return $this->aData[$iCursorOffset];
  }

  /**
   * Return the next row of data from the result set as an associative array
   *
   * @return array
   * @throws \Limbonia\Exception\Database
   */
  public function fetchAssoc()
  {
    $hData = [];
    $iCursorOrientation = \PDO::FETCH_ORI_FIRST;

    while ($hRow = $this->fetch(null, $iCursorOrientation))
    {
      $iCursorOrientation = \PDO::FETCH_ORI_NEXT;
      $xFirst = array_shift($hRow);
      $iRemainingColumns = count($hRow);

      if ($iRemainingColumns == 0)
      {
        throw new \Limbonia\Exception\Database(__METHOD__ . " at least 2 columns!", $this->oDatabase->getType());
      }

      $hData[$xFirst] = $iRemainingColumns == 1 ? array_shift($hRow) : $hRow;
    }

    return $hData;
  }

  /**
   * Return the first (only) row of data in the result set
   *
   * @return array
   */
  public function fetchOne()
  {
    //get the first (and hopefully only) row
    return $this->fetch(null, \PDO::FETCH_ORI_FIRST);
  }

  /**
   * Return the number of rows in the result set
   *
   * @return integer
   */
  public function rowCount()
  {
    return $this->count();
  }

  /**
   * Return the value stored at the specified array offset
   *
   * @note This is an implementation detail of the ArrayAccess Interface
   *
   * @param integer $iOffset
   * @return mixed
   */
  public function offsetGet($iOffset)
  {
    return $this->fetch(null, \PDO::FETCH_ORI_ABS, $iOffset);
  }

  /**
   * Return the number of columns represented by this object
   *
   * @note This is an implementation detail of the Countable Interface
   *
   * @return integer
   */
  public function count()
  {
    if ($this->iCurrentRow === false)
    {
      $this->iCurrentRow = parent::rowCount();
    }

    return $this->iRowCount;
  }
}