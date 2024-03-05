<?php
namespace DerickR\ActivityPub\DataTypes;

abstract class DataType
{
	protected string $id;

	public function getId()
	{
		return $this->id;
	}

	abstract static function fromJsonData(object $data) : self;
	abstract function toJsonData() : array;

	static function fromJsonString(string $jsonString) : self
	{
		$data = json_decode($jsonString);
		if ($data == NULL) {
			throw new \ValueError("Invalid JSON String: '{$jsonString}'");
		}

		return static::fromJsonData($data);
	}

	static public function getObject(object $data) : self
	{
		if (!isset($data->type)) {
			throw new \ValueError("No type associated with object in data");
		}

		switch ($data->type) {
			case "Note":
				return Note::fromJsonData($data);
			default:
				throw new \ValueError("Don't understand the object type '{$data->type}'");
		}
	}
}
