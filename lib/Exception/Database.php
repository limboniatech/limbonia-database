<?php
namespace Limbonia\Exception;

/**
 * Limbonia Database Exception Class
 *
 * Handles exceptions thrown by the Database class(es)
 *
 * @author Lonnie Blansett <lonnie@limbonia.tech>
 * @package Limbonia
 */
class Database extends \Exception
{
  /**
   * The SQL server type
   *
   * @var string
   */
	protected $sSQLType = null;

  /**
   * Construct a new exception
   *
   * @param string $sMessage The error message
   * @param string $sSQLType (optional) The SQL driver being used, if there is one
   * @param integer $iCode (optional) The error code, if there is one
   */
	public function __construct($sMessage, $sSQLType = "Unknown", $iCode = 0)
	{
		$this->sSQLType = $sSQLType;
		parent::__construct($sMessage, $iCode);
	}

  /**
   * Return the SQL server type
   *
   * @return string
   */
	public function getType()
	{
		return $this->sSQLType;
	}
}