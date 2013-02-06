<?php
abstract class Model {
	private $_attributes = array();
	private $_idOnlyObjects;
	private $_loaded = false;

	public static function coll($db) {
		return $db->selectCollection(static::$collectionName);
	}

	public static function hasOwnCollection() {
		return static::$collectionName !== null;
	}

	public function __construct($param=null) {
		$this->initialiseAttributes();
		if (is_array($param))
			$this->parse($param);
		elseif ($param !== null)
			$this->id = $param;
	}
	
	public function getIdFieldAttribute() {
		foreach ($this->_attributes as $attributeInfo) {
			if ($attributeInfo["field"] === "_id")
				return $attributeInfo["attribute"];
		}
	}

	private function initialiseAttributes() {
		$defaultAttributeArray = array(
			"type"    => null,
			"array"   => false,
			"default" => null,
			"field"   => null,
			"idOnly"  => false
		);

		foreach ($this as $attribute => $value) {
			if (strpos($attribute, "_") === 0 || ! is_array($value))
				continue;
			$defaultAttributeArray["field"] = $attribute;
			$attributeInfo = array_merge($defaultAttributeArray, $value);
			$attributeInfo["attribute"] = $attribute;
			
			if ($attributeInfo["default"] === null) {
				if ($attributeInfo["array"])
					$this->$attribute = array();
				else
					$this->$attribute = null;
			} else
				$this->$attribute = $attributeInfo["default"];
			$this->_attributes[] = $attributeInfo;
		}
	}
	
	public function recursiveParse($array, &$objects) {
		$idOnlyObjects = array();
		foreach ($this->_attributes as $attributeInfo) {
			if (isset($array[$attributeInfo["field"]])) {

				$value = $array[$attributeInfo["field"]];
				$type = $attributeInfo["type"];
				$attribute = $attributeInfo["attribute"];
				$isArray = $attributeInfo["array"];
				$idOnly = $attributeInfo["idOnly"];

				if ($type == null || $type == "MongoId")
					$this->$attribute = $value;
				else {
					if ($isArray) {
						$this->$attribute = array();
						foreach ($value as $arrayValue) {
							if ($idOnly) {
								$idOnlyObjects[] = array(
									"key" => $attribute,
									"array" => true,
									"id" => $arrayValue,
									"type" => $type
								);
							} else {
								$object = new $type();
								$object->recursiveParse($arrayValue, $objects);
								$this->{$attribute}[] = $object;
								$objects[$type][] = $object;
							}
						}
					} else {
						if ($idOnly) {
							$idOnlyObjects[] = array(
								"key" => $attribute,
								"array" => false,
								"id" => $value,
								"type" => $type
							);
						} else {
							$object = new $type();
							$object->recursiveParse($value, $objects);
							$this->$attribute = $object;
							$objects[$type][] = $object;
						}
					}
				}
			}
		}
		$this->_idOnlyObjects = $idOnlyObjects;
	}

	public function parse($array) {
		$objects = array();
		$this->recursiveParse($array, $objects);

		$this->resolveIdOnlyObjects($objects);
		foreach ($objects as $objectsType) {
			foreach ($objectsType as $object)
				$object->resolveIdOnlyObjects($objects);
		}
	}

	public function resolveIdOnlyObjects($objects) {
		$idOnlyObjects = $this->_idOnlyObjects;

		foreach ($idOnlyObjects as $idOnlyObject) {
			foreach ($objects[$idOnlyObject["type"]] as $object) {
				if ($object->id === $idOnlyObject["id"]) {
					$key = $idOnlyObject["key"];
					if ($idOnlyObject["array"])
						$this->{$key}[] = $object;
					else
						$this->$key = $object;
					break;
				}
			}
		}
	}

	public function load($db, $fields=null) {
		if (static::$collectionName == null)
			throw new Exception("Objects of the type " . get_class($this) . " can't be loaded because they don't have an own collection.");

		if ($this->_loaded)
			return $this;

		foreach ($this->_attributes as $attributeInfo) {
			if ($attributeInfo["field"] === "_id") {
				$idAttribute = $attributeInfo["attribute"];
				$idType = $attributeInfo["type"];
				$idValue = $this->{$idAttribute};
				break;
			}
		}
		if ($idValue === null)
			throw new Exception("Can't load object with id null");

		$coll = $db->selectCollection(static::$collectionName);

		if ($idType === "MongoId" && is_string($idValue))
			$idValue = new MongoId($idValue);


		$array = ($fields === null)? $coll->findOne(array("_id" => $idValue)) : $coll->findOne(array("_id" => $idValue), $fields);
		if ($array === null)
			throw new Exception("Couldn't find object with specified id");

		$this->parse($array);
		$this->_loaded = true;
		return $this;
	}

	public function createDatabaseArray() {
		$array = array();
		foreach ($this->_attributes as $attributeInfo) {
			$type = $attributeInfo["type"];
			$attribute = $attributeInfo["attribute"];
			$isArray = $attributeInfo["array"];
			$idOnly = $attributeInfo["idOnly"];
			$field = $attributeInfo["field"];
			$value = $this->$attribute;

			if ($type == "MongoId") {
				if ($value === null)
					$value = new MongoId();
				$this->$attribute = $value;
				$array[$field] = $value;
			} elseif ($type === null || $value === null)
				$array[$field] = $value;
			else {
				if ($isArray) {
					$array[$field] = array();
					foreach ($value as $arrayValue) {
						if ($idOnly)
							$array[$field][] = $arrayValue->id;
						else {
							if ($arrayValue::hasOwnCollection()) {
								$idField = $arrayValue->getIdFieldAttribute();
								$array[$field][] = array("_id" => $arrayValue->$idField);
							} else
								$array[$field][] = $arrayValue->createDatabaseArray();
						}
					}
				} else {
					if ($idOnly)
						$array[$field] = $value->id;
					else {
						if ($value::hasOwnCollection()) {
							$idField = $value->getIdFieldAttribute();
							$array[$field] = array("_id" => $value->$idField);
						} else
							$array[$field] = $value->createDatabaseArray();
					}
				}
			}
		}
		return $array;
	}

	public function save($db) {
		if (static::$collectionName == null)
			throw new Exception("Objects of the type " . get_class($this) . " can't be saved because they don't have an own collection.");

		$array = $this->createDatabaseArray();

		$coll = $db->selectCollection(static::$collectionName);
		$coll->save($array);
	}
}