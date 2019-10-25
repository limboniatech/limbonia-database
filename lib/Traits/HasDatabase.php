<?php
namespace Limbonia\Traits;

/**
 * Limbonia HasDatabase Trait
 *
 * This trait allows an inheriting class to have a database
 *
 * @author Lonnie Blansett <lonnie@limbonia.tech>
 * @package Limbonia
 */
trait HasDatabase
{
  /**
   * The database for this object
   *
   * @var \Limbonia\Database
   */
  protected $oDatabase = null;

  /**
   * Set this object's database
   *
   * @param \Limbonia\Database $oDatabase
   */
  public function setDatabase(\Limbonia\Database $oDatabase = null)
  {
    $this->oDatabase = $oDatabase;
  }

  /**
   * Return this object's database
   *
   * @return \Limbonia\Database
   */
  public function getDatabase(): \Limbonia\Database
  {
    if (is_null($this->oDatabase))
    {
      return \Limbonia\Database::getDB();
    }

    return $this->oDatabase;
  }
}
