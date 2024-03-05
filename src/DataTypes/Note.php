<?php
namespace DerickR\ActivityPub\DataTypes;

class Note extends DataType
{
	private array $contexts;
	private string $type;
	private array $hashTags = [];
	private \DateTimeImmutable $publishedAt;
	private ?string $attributedTo;
	private string $content;
	private array $to = [];
	private array $cc = [];
	private ?Location $location;
	private array $tags = [];
	private array $attachments = [];

	const ActivityPubType = 'Note';

	public function __construct(string $actor, string $content)
	{
		$r = new \Random\Randomizer;
		$this->id = $actor . '/posts/' . bin2hex($r->getBytes(16)) . '.json';

		$this->type = self::ActivityPubType;
		$this->contexts = [ "https://www.w3.org/ns/activitystreams" ];
		$this->addContext("HashTag", "https://www.w3.org/ns/activitystreams#Hashtag");
		$this->publishedAt = new \DateTimeImmutable();
		$this->attributedTo = $actor;
		$this->content = $content;
		$this->to = [ "https://www.w3.org/ns/activitystreams#Public" ];
		$this->cc = [];
		$this->location = NULL;
		$this->tags = [];
		$this->attachments = [];
	}

	public function addContext(string $descriptor, string $uri)
	{
		$this->contexts[] = [ $descriptor => $uri ];
	}

	public function addCC(string $destination)
	{
		$this->cc[] = $destination;
	}

	public function addLocation(Location $location)
	{
		$this->location = $location;
	}

	public function addTag(Tag $tag)
	{
		$this->tags[] = $tag;
	}

	public function addAttachment(Attachment $attachment)
	{
		$this->attachments[] = $attachments;
	}


	static function fromJsonData(object $data) : Note
	{
		if ($data->type != self::ActivityPubType) {
			throw new \ValueError("Data does not contain '" . self::ActivityPubType ."', but '{$data->type}' instead");
		}

		$new = new self($data->attributedTo, $data->content);

		$new->contexts = $data->{'@context'};
		$new->id = $data->id;
		$new->type = self::ActivityPubType;
		$new->publishedAt = new \DateTimeImmutable($data->published);

		if (isset($data->to) && is_array($data->to)) {
			$new->to = $data->to;
		}
		if (isset($data->cc) && is_array($data->cc)) {
			$new->cc = $data->cc;
		}
		if (isset($data->location) && is_object($data->location)) {
			$new->location = Location::fromJsonData($data->location);
		}

		if (isset($data->tag) && is_array($data->tag)) {
			foreach ($data->tag as $tag) {
				$new->tags[] = Tag::fromJsonData($tag);
			}
		}

		if (isset($data->attachment) && is_array($data->attachment)) {
			foreach ($data->attachment as $attachment) {
				$new->attachments[] = Attachment::fromJsonData($attachment);
			}
		}

		return $new;
	}

	public function toJsonData() : array
	{
		$obj = [
			'@context' => $this->contexts,
			'id' => $this->id,
			'type' => self::ActivityPubType,
			'published' => $this->publishedAt->format(\DateTimeInterface::ISO8601),
			'attributedTo' => $this->attributedTo,
			'content' => $this->content,
			'to' => $this->to,
		];
		if (count($this->cc) > 0) {
			$obj['cc'] = $this->cc;
		}
		if ($this->location != NULL) {
			$obj['location'] = $this->location->toJsonData();
		}
		if (count($this->tags) > 0) {
			$obj['tag'] = [];
			foreach ($this->tags as $tag) {
				$obj['tag'][] = $tag->toJsonData();
			}
		}
		if (count($this->attachments) > 0) {
			$obj['attachment'] = [];
			foreach ($this->attachments as $tag) {
				$obj['attachment'][] = $tag->toJsonData();
			}
		}

		return $obj;
	}
}
