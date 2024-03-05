<?php
namespace DerickR\ActivityPub\DataTypes;

class Tag extends DataType
{
	private string $type;
	private string $name;

	public function __construct(string $type, string $name)
	{
		$this->type = $type;
		$this->name = $name;
	}

	static function fromJsonData(object $data) : Tag
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
