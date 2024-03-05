<?php
namespace DerickR\ActivityPub\DataTypes;

class Attachment extends DataType
{
	private string $type;
	private string $mediaType;
	private string $url;
	private string $name;

	public function __construct(string $type, string $mediaType, string $url, string $name)
	{
		$this->type = $type;
		$this->mediaType = $mediaType;
		$this->url = $url;
		$this->name = $name;
	}

	static function fromJsonData(object $data) : Attachment
	{
		$new = new self($data->type, $data->mediaType, $data->url, $data->name);

		return $new;
	}

	public function toJsonData() : array
	{
		return [
			'type' => $this->type,
			'mediaType' => $this->mediaType,
			'url' => $this->url,
			'name' => $this->name,
		];
	}
}
?>
