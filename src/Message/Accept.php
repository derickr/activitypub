<?php
namespace DerickR\ActivityPub\Message;

use DerickR\ActivityPub\Instance;

class Accept
{
	private string $msgId;

	function __construct( private Instance $instance, private string $account, private string $idToAccept, private string $actor )
	{
		$this->msgId = bin2hex(random_bytes(16));
	}

	function asData() : array
	{
		$hostname = $this->instance->getHostName();

		$message = [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id'       => "https://{$hostname}/@{$this->msgId}",
			'type'     => 'Accept',
			'actor'    => "https://{$hostname}/@{$this->account}",
			'object'   => [
				'@context' => 'https://www.w3.org/ns/activitystreams',
				'id'       => $this->idToAccept,
				'type'     => 'Accept',
				'actor'    => $this->actor,
				'object'   => "https://{$hostname}/@{$this->account}",
			]
		];

		return $message;
	}

	function asJson() : string
	{
		return json_encode($this->asData(), JSON_PRETTY_PRINT);
	}
}
