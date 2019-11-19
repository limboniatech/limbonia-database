<?php
namespace Limbonia\Exception;

/**
 * Limbonia DBResult Exception Class
 *
 * Handles exceptions thrown by the DBResult class(es)
 *
 * @author Lonnie Blansett <lonnie@limbonia.tech>
 * @package Limbonia
 */
class DBResult extends Database
{
  /**
   * The SQL query that ran / was running when this Exception was generated
   *
   * @var string
   */
  protected $sQuery = '';

  /**
   * Construct a new exception
   *
   * @param string $sMessage
   * @param string $sSQLType
   * @param string $sQuery
   * @param integer $iCode (optional)
   */
  public function __construct($sMessage, $sSQLType, $sQuery, $iCode = 0)
  {
    $this->sQuery = $sQuery;

    parent::__construct($sMessage, $sSQLType, $iCode);
  }

  /**
   * Return the SQL query that ran / was running when the exception was generated
   *
   * @return string
   */
  public function getquery()
  {
    return $this->sQuery;
  }
}