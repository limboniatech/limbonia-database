<?php
namespace Limbonia;

/**
 * Limbonia Item Class
 *
 * This is a wrapper around the around a row of item data that allows access to
 * the data
 *
 * @author Lonnie Blansett <lonnie@limbonia.tech>
 * @package Limbonia
 */
class Item implements \ArrayAccess, \Countable, \SeekableIterator
{
  use \Limbonia\Traits\Item;
}