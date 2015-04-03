<?php

/**
 * 用来处理Hasher中的异常
 * @author yuanxch
 * @date 2015/4/3
 */
class Hasher_Exception extends Exception
{
}


/**
 * 定义所有Hash类必须实现的接口
 * @author yuanxch
 * @date 2015/4/3
 */
interface Hasher
{
	/**
	 * Hashes the given string into a 32bit address space.
	 *
	 * Note that the output may be more than 32bits of raw data, for example
	 * hexidecimal characters representing a 32bit value.
	 *
	 * The data must have 0xFFFFFFFF possible values, and be sortable by
	 * PHP sort functions using SORT_REGULAR.
	 *
	 * @param string
	 * @return mixed A sortable format with 0xFFFFFFFF possible values
	 */
	public function hash($string);
}

/**
 * 通过crc32()生成一个32bit空间上的Hash值.
 * 注意crc32有可能是负数，此处通一转为正数
 * @author yuanxch
 * @date 2015/4/3
 */
class Hasher_Crc32	implements Hasher
{
	/* 
	 * @see Hasher_Crc32::hash()
	 */
	public function hash($string)
	{
		return sprintf("%u", crc32($string));
	}
}


/**
 * 通过md5()生成一个32bit空间上的Hash值.
 * 因为md5生成的是33位16进制，此处截取md5的前8位，对应32bit空间。
 * @author yuanxch
 * @date 2015/4/3
 */
class Hasher_Md5 implements Hasher
{
	/*
	 * @see Hasher_Md5::hash()
	 */
	public function hash($string)
	{
		return substr(md5($string), 0, 8); // 8 hexits = 32bit
		// 4 bytes of binary md5 data could also be used, but
		// performance seems to be the same.
	}
}


/**
 * A simple consistent hashing implementation with pluggable hash algorithms.
 */
class SimpleConsistentHashing
{
	/**
	 * The number of positions to hash each target to.
	 *
	 * @var int
	 */
	private $_replicas = 64;

	/**
	 * The hash algorithm, encapsulated in a Hasher implementation.
	 * @var object Hasher
	 */
	private $_hasher;

	/**
	 * Internal counter for current number of targets.
	 * @var int
	 */
	private $_targetCount = 0;

	/**
	 * Internal map of positions (hash outputs) to targets
	 * @var array { position => target, ... }
	 */
	private $_positionToTarget = array();

	/**
	 * Internal map of targets to lists of positions that target is hashed to.
	 * @var array { target => [ position, position, ... ], ... }
	 */
	private $_targetToPositions = array();

	/**
	 * Whether the internal map of positions to targets is already sorted.
	 * @var boolean
	 */
	private $_positionToTargetSorted = false;

	/**
	 * Constructor
	 * @param object $hasher Hasher
	 * @param int $replicas Amount of positions to hash each target to.
	 */
	public function __construct(Hasher $hasher = null, $replicas = null)
	{
		$this->_hasher = $hasher ? $hasher : new Hasher_Crc32();
		if (!empty($replicas)) $this->_replicas = $replicas;
	}

	/**
	 * Add a target.
	 * @param string $target
     * @param float $weight
	 * @chainable
	 */
	public function addTarget($target, $weight = 1)
	{
		if (isset($this->_targetToPositions[$target])) {
			throw new Hasher_Exception("Target '$target' already exists.");
		}

		$this->_targetToPositions[$target] = array();

		// hash the target into multiple positions
		for ($i = 0; $i < round($this->_replicas * $weight); $i++) {
			$position = $this->_hasher->hash($target . "#" . $i);
			$this->_positionToTarget[$position] = $target; // lookup
			$this->_targetToPositions[$target][]= $position; // target removal
		}

		$this->_positionToTargetSorted = false;
		$this->_targetCount++;
		return $this;
	}

	/**
	 * Add a list of targets.
	 * @param array $targets
     * @param float $weight
	 * @chainable
	 */
	public function addTargets($targets, $weight=1)
	{
		foreach ($targets as $target) {
			$this->addTarget($target, $weight);
		}
		return $this;
	}

	/**
	 * Remove a target.
	 * @param string $target
	 * @chainable
	 */
	public function removeTarget($target)
	{
		if (!isset($this->_targetToPositions[$target]))
			throw new Hasher_Exception("Target '$target' does not exist.");

		foreach ($this->_targetToPositions[$target] as $position) {
			unset($this->_positionToTarget[$position]);
		}

		unset($this->_targetToPositions[$target]);
		$this->_targetCount--;
		return $this;
	}

	/**
	 * A list of all potential targets
	 * @return array
	 */
	public function getAllTargets()
	{
		return array_keys($this->_targetToPositions);
	}

	/**
	 * Looks up the target for the given resource.
	 * @param string $resource
	 * @return string
	 */
	public function lookup($resource)
	{
		$targets = $this->lookupList($resource, 1);
		if (empty($targets)) 
			throw new Hasher_Exception('No targets exist');

		return $targets[0];
	}

	/**
	 * Get a list of targets for the resource, in order of precedence.
	 * Up to $requestedCount targets are returned, less if there are fewer in total.
	 *
	 * @param string $resource
	 * @param int $requestedCount The length of the list to return
	 * @return array List of targets
	 */
	public function lookupList($resource, $requestedCount)
	{
		if (!$requestedCount)
			throw new Hasher_Exception('Invalid count requested');

		// handle no targets
		if (empty($this->_positionToTarget))
			return array();

		// optimize single target
		if ($this->_targetCount == 1)
			return array_unique(array_values($this->_positionToTarget));

		// hash resource to a position
		$resourcePosition = $this->_hasher->hash($resource);

		$results = array();
		$collect = false;
		$this->_sortPositionTargets();

		// search values above the resourcePosition
		foreach ($this->_positionToTarget as $key => $value) {
			// start collecting targets after passing resource position
			if (!$collect && $key > $resourcePosition) {
				$collect = true;
			}

			// only collect the first instance of any target
			if ($collect && !in_array($value, $results)) {
				$results[] = $value;
			}

			// return when enough results, or list exhausted
			if (count($results) == $requestedCount || count($results) == $this->_targetCount) {
				return $results;
			}
		}

		// loop to start - search values below the resourcePosition
		foreach ($this->_positionToTarget as $key => $value) {
			if (!in_array($value, $results)) {
				$results []= $value;
			}
			// return when enough results, or list exhausted
			if (count($results) == $requestedCount || count($results) == $this->_targetCount) {
				return $results;
			}
		}
		// return results after iterating through both "parts"
		return $results;
	}

	public function __toString()
	{
		return sprintf(
			'%s{targets:[%s]}',
			get_class($this),
			implode(',', $this->getAllTargets())
		);
	}

	/**
	 * Sorts the internal mapping (positions to targets) by position
	 */
	private function _sortPositionTargets()
	{
		// sort by key (position) if not already
		if (!$this->_positionToTargetSorted) {
			ksort($this->_positionToTarget, SORT_REGULAR);
			$this->_positionToTargetSorted = true;
		}
	}

}

