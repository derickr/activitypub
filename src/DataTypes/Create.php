<?php
namespace DerickR\ActivityPub\DataTypes;

class Create extends DataType
{
	private string $context;
	private string $type;
	private string $actor;
	private array $to = [];
	private array $cc = [];
	private DataType $object;

	const ActivityPubType = 'Create';

	static function fromJsonData(object $data) : self
	{
		if ($data->type != self::ActivityPubType) {
			throw new \ValueError("Data does not contain '" . self::ActivityPubType ."', but '{$data->type}' instead");
		}

		$new = new self;

		$new->context = $data->{'@context'};
		$new->id = $data->id;
		$new->type = self::ActivityPubType;
		$new->actor = $data->actor;

		if (isset($data->to) && is_array($data->to)) {
			$new->to = $data->to;
		}
		if (isset($data->cc) && is_array($data->cc)) {
			$new->cc = $data->cc;
		}

		$new->object = parent::getObject($data->object);

		return $new;
	}

	public function toJsondata() : array
	{
		$obj = [
			'@context' => $this->context,
			'id' => $this->id,
			'type' => $this->type,
			'actor' => $this->actor,
			'to' => $this->to,
		];

		if (count($this->cc) > 0) {
			$obj['cc'] = $this->cc;
		}

		$obj['object'] = $this->object->toJsonData();

		return $obj;
	}

	public function __construct( string $id, string $actor, DataType $object )
	{
		$this->context = 'https://www.w3.org/ns/activitystreams';
		$this->id = $id;
		$this->type = self::ActivityPubType;
		$this->actor = $actor;
		$this->to[] = "https://www.w3.org/ns/activitystreams#Public";
		$this->cc = [];
		$this->object = $object;
	}

	public function addCC( string $cc )
	{
		$this->cc[] = $cc;
	}

	public function getId()
	{
		return $this->id;
	}

	public function getEmbeddedId()
	{
		return $this->object->getId();
	}

	public function getEmbeddedObject()
	{
		return $this->object;
	}

	public function getAccountName() : ?string
	{
		if (preg_match('#https://(.*)/@(.*)#', $this->actor, $m)) {
			return $m[2];
		}

		return NULL;
	}
}
?>
