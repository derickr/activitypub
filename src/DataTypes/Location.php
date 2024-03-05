<?php
namespace DerickR\ActivityPub\DataTypes;

class Location extends DataType
{
	private string $type;
	private string $name;

	public function __construct(string $type, string $name)
	{
		$this->type = $type;
		$this->name = $name;
	}

	static function fromJsonData(object $data) : Location
	{
		$new = new self($data->type, $data->name);

		return $new;
	}

	public function toJsonData() : array
	{
		return [
			'type' => $this->type,
			'name' => $this->name,
		];
	}
}
?>
