<?php
namespace DerickR\ActivityPub;

use DerickR\ActivityPub\DataTypes\Create;
use DerickR\ActivityPub\DataTypes\DataType;

class Instance
{
	private bool $debug;

	public function __construct( private string $hostname, private Storage\Provider $provider )
	{
		$this->debug = false;
	}

	public function setDebug( bool $debug )
	{
		$this->debug = $debug;
	}

	public function getHostName() : string
	{
		return $this->hostname;
	}

	public function run()
	{
		$path = $_SERVER['REQUEST_URI'];
		$userPattern = '([-\w]+)';
		$postIdPattern = '([-\w]+)';

		/* WebFinger */
		if (preg_match(
			"#^/.well-known/webfinger\?resource=acct:(.*)@{$this->hostname}#",
			$path,
			$matches
		) ) {
			$this->webFinger( $matches[1] );
		}

		/* Account Info Page */
		if (preg_match("#@{$userPattern}$#", $path, $matches)) {
			$this->accountPage( $matches[1] );
		}

		/* Following and Followers */
		if (preg_match( "#@{$userPattern}/following$#", $path, $matches)) {
			$this->following( $matches[1] );
		}
		if (preg_match( "#@{$userPattern}/followers$#", $path, $matches)) {
			$this->followers( $matches[1] );
		}

		/* Inbox */
		if (preg_match( "#@{$userPattern}/inbox$#", $path, $matches)) {
			$this->inbox( $matches[1] );
		}

		/* Outbox and Individual Posts */
		if (preg_match( "#@{$userPattern}/outbox$#", $path, $matches)) {
			$this->outbox( $matches[1] );
		}
		if (preg_match( "#@{$userPattern}/posts/{$postIdPattern}$#", $path, $matches)) {
			$this->getPost( $matches[1], $matches[2] );
		}
	}

	protected function accountPage( string $account ) : never
	{
		if ( !$this->provider->hasUser( $account ) )
		{
			$this->returnNotFound('Account not found', [ 'account' => $account ] );
		}

		$user = $this->provider->getUser( $account );
		$this->provider->saveUser( $user );

		$data = [
			"@context" => [
				"https://www.w3.org/ns/activitystreams",
				"https://w3id.org/security/v1",
			],
			"id" => "https://{$this->hostname}/@{$user->username}",
			"type" => "Person",
			"following" => "https://{$this->hostname}/@{$user->username}/following",
			"followers" => "https://{$this->hostname}/@{$user->username}/followers",
			"inbox" => "https://{$this->hostname}/@{$user->username}/inbox",
			"outbox" => "https://{$this->hostname}/@{$user->username}/outbox",
			"preferredUsername" => "{$user->username}",
			"name" => "{$user->name}",
			"summary" => "{$user->bio}",
			"url" => "https://{$this->hostname}/@{$user->username}",
			"manuallyApprovesFollowers" => false,
			"discoverable" => true,
			"indexable" => true,
			"published" => $user->joinDate,
			"icon" => [
				"type" => "Image",
				"mediaType" => "image/jpeg",
				"url" => "https://{$this->hostname}{$user->iconImage}",
			],
			"image" => [
				"type" => "Image",
				"mediaType" => "image/jpeg",
				"url" => "https://{$this->hostname}{$user->headerImage}",
			],
			"publicKey" => [
				"id" => "https://{$this->hostname}/@{$user->username}#main-key",
				"owner" => "https://{$this->hostname}/@{$user->username}",
				"publicKeyPem" => $user->publicKey,
			],
			"endpoints" => [
				"sharedInbox" => "https://{$this->hostname}/@{$user->username}/inbox"
			],
		];

		$this->setJsonContentType();
		echo json_encode( $data, JSON_PRETTY_PRINT );
		die();
	}

	protected function followers( string $account ) : never
	{
		if ( !$this->provider->hasUser( $account ) )
		{
			$this->returnNotFound('Account not found', [ 'account' => $account ] );
		}

		$user = $this->provider->getUser($account);
		$followers = $user->getFollowers();

		$data = [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => "https://{$this->hostname}/@{$account}/followers",
			'type' => 'OrderedCollection',
			'totalItems' => count( $followers ),
			'orderedItems' => $followers,
		];

		$this->setJsonContentType();
		echo json_encode( $data, JSON_PRETTY_PRINT );
		die();
	}

	protected function following( string $account ) : never
	{
		if ( !$this->provider->hasUser( $account ) )
		{
			$this->returnNotFound('Account not found', [ 'account' => $account ] );
		}

		$data = [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => "https://{$this->hostname}/@{$account}/following",
			'type' => 'OrderedCollection',
			'totalItems' => 0,
			'orderedItems' => [],
		];

		$this->setJsonContentType();
		echo json_encode( $data, JSON_PRETTY_PRINT );
		die();
	}

	protected function inbox( string $account ) : never
	{
		$payload = file_get_contents( 'php://input' );
		$payload = json_decode( $payload );

		if ( !$payload )
		{
			exit();
		}

		$this->logDebugMessage(json_encode($payload, JSON_PRETTY_PRINT));

		if ( $payload->type === 'Delete' )
		{
			/* Ignore */
			exit();
		}

		if ( $payload->type === 'Follow' )
		{
			$this->processFollowMessage( $account, $payload );
			exit();
		}

		if ( $payload->type === 'Undo' && $payload->object->type == 'Follow' )
		{
			$this->processUndoFollowMessage( $account, $payload );
			exit();
		}

		/* Save incoming data if we haven't handled it */
		if ( array_key_exists( 'HTTP_SIGNATURE', $_SERVER ) )
		{
			$this->logDebugMessage($_SERVER['HTTP_SIGNATURE']);
		}
		$this->logDebugMessage(json_encode($payload, JSON_PRETTY_PRINT));

		die();
	}

	protected function outbox( string $account ) : never
	{
		if ( !$this->provider->hasUser( $account ) )
		{
			$this->returnNotFound('Account not found', [ 'account' => $account ] );
		}

		$user = $this->provider->getUser($account);
		$posts = $this->provider->getAllPostsForUser($user);

		$postMessages = [];
		foreach ($posts as $post) {
			$postMessages[] = [
				"type"   => "Create",
				"actor"  => "https://{$this->hostname}/@{$account}",
				"object" => "https://{$this->hostname}/@{$account}/posts/{$post}"
			];
		}

		$data = [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => "https://{$this->hostname}/@{$account}/outbox",
			'type' => 'OrderedCollection',
			'totalItems' => count( $posts ),
			'orderedItems' => $postMessages,
		];

		$this->setJsonContentType();
		echo json_encode( $data, JSON_PRETTY_PRINT );
		die();
	}

	protected function getPost( string $account, string $postId ) : never
	{
		if ( !$this->provider->hasUser( $account ) )
		{
			$this->returnNotFound('Account not found', [ 'account' => $account ] );
		}
		$user = $this->provider->getUser($account);

		$post = $this->provider->getPostJson($user, $postId);
		if (!$post) {
			$this->returnNotFound('Post not found', [ 'account' => $account, 'postId' => $postId ] );
		}

		$this->setJsonContentType();
		echo $post;
	}

	private function processFollowMessage( $account, $payload )
	{
		if (! $this->provider->hasUser($account))
		{
			$this->returnNotFound('Account not found', [ 'account' => $account ] );
		}

		/* Parse / grab data */
		$messageId = $payload->id;
		$actor = $payload->actor;
		$instance = parse_url($actor, PHP_URL_SCHEME) . "://" . parse_url($actor, PHP_URL_HOST);
		$targetHost = parse_url($actor, PHP_URL_HOST);

		/* Add follower to user */
		$user = $this->provider->getUser($account);
		$user->addFollower($targetHost, $actor);
		$this->provider->saveUser($user);

		/* Create Acceptance Message */
		$acceptMessage = new \DerickR\ActivityPub\Message\Accept( $this, $account, $messageId, $actor );
		$acceptMessageJson = $acceptMessage->asJson();

		/* SIGNING */
		//	Where is this being sent?
		// $path = '/users/derickr/inbox' -- asssume it's in /inbox, but that could be wrong
		$path = parse_url($actor, PHP_URL_PATH) . '/inbox';

		//	Set up signing
		$hash = hash('sha256', $acceptMessageJson, true);
		$digest = base64_encode($hash);
		$date = date('D, d M Y H:i:s \G\M\T');
		$stringToSign = "(request-target): post $path\nhost: $targetHost\ndate: $date\ndigest: SHA-256=$digest";

		$signature = $user->sign($stringToSign);

		$keyId = "https://{$this->hostname}/@{$account}#main-key";
		$header = 'keyId="' . $keyId . '",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="' . $signature . '"';

		//Header for POST reply
		$headers = [
			"Host: {$targetHost}",
			"Date: {$date}",
			"Digest: SHA-256={$digest}",
			"Signature: {$header}",
			"Content-Type: application/activity+json",
			"Accept: application/activity+json",
		];

		// Specify the URL of the remote inbox
		$inboxUrl = $actor . "/inbox";

		$this->deliverMessage($inboxUrl, $headers, $acceptMessageJson);
	}

	private function processUndoFollowMessage( $account, $payload )
	{
		if (! $this->provider->hasUser($account))
		{
			$this->returnNotFound('Account not found', [ 'account' => $account ] );
		}

		/* Parse / grab data */
		$messageId = $payload->id;
		$actor = $payload->actor;
		$instance = parse_url($actor, PHP_URL_SCHEME) . "://" . parse_url($actor, PHP_URL_HOST);
		$targetHost = parse_url($actor, PHP_URL_HOST);

		/* Add follower to user */
		$user = $this->provider->getUser($account);
		$user->removeFollower($targetHost, $actor);
		$this->provider->saveUser($user);

		return;
	}

	protected function webFinger( string $account ) : never
	{
		if ( !$this->provider->hasUser( $account ) )
		{
			$this->returnNotFound('Account not found', [ 'account' => $account ] );
		}

		$data = [
			'subject' => "acct:{$account}@{$this->hostname}",
			'links' => [ [
				'rel' => 'self',
				'type' => 'application/activity+json',
				'href' => "https://{$this->hostname}/@{$account}",
			] ]
		];

		$this->setJsonContentType();
		echo json_encode( $data, JSON_PRETTY_PRINT );
		die();
	}

	private function returnNotFound(string $message, array $data) : never
	{
		header('HTTP/1.1 404 Not Found');
		$this->setJsonContentType();

		echo json_encode(
			[
				'error' => $message,
				...$data
			],
			JSON_PRETTY_PRINT
		);
		die();
	}

	private function setJsonContentType()
	{
		header('Content-Type: application/activity+json');
	}

	private function deliverMessage(string $inbox, array $headers, string $message) : void
	{
		$ch = curl_init($inbox);

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $message);
		//curl_setopt($ch, CURLOPT_VERBOSE, 1);
		//curl_setopt($ch, CURLOPT_STDERR, fopen("php://stdout", "w"));

		$response = curl_exec($ch);
		if (curl_errno($ch)) {
			$this->logDebugMessage(curl_error($ch));
		} else if ($response != '') {
			$this->logDebugMessage($response);
		}
		curl_close($ch);
	}

	private function logDebugMessage( string $message )
	{
		if (! $this->debug) {
			return;
		}

		$f = @fopen("/tmp/activity-pub-debug-" . getmyuid() . ".json", "a");

		if ($f) {
			fwrite($f, "\n=====\n" . $message . "\n=====\n");
			fclose($f);
		}
	}

	public function newCreateMessage(string $account, DataType $post) : ?Create
	{
		if (! $this->provider->hasUser($account))
		{
			$this->returnNotFound('Account not found', [ 'account' => $account ] );
		}

		$guid = preg_replace( "#https://{$this->hostname}/@{$account}/posts/(.*)\.json#", '\1', $post->getId() );
		return new Create($post->getId(), "https://{$this->hostname}/@{$account}", $post);
	}

	public function processMessage(Create $post)
	{
		/* Store post for posterity, which also makes it available in the outbox */
		$outboxItem = [ "@context" => "https://www.w3.org/ns/activitystreams" ];
		foreach ((array) $post->getEmbeddedObject()->toJsonData() as $key => $value) {
			$outboxItem[$key] = $value;
		}

		$account = $post->getAccountName();

		$user = $this->provider->getUser($account);
		$guid = preg_replace( "#https://{$this->hostname}/@{$account}/posts/(.*)\.json#", '\1', $post->getId() );
		$this->provider->storePostJson($user, $guid, json_encode($outboxItem, JSON_PRETTY_PRINT));

		/* Create message to post */
		$postJson = json_encode($post->toJsonData(), JSON_PRETTY_PRINT);

		/* Figure out which instances to post it to */
		$instances = $user->getFollowerInstances();

		foreach ($instances as $targetHost) {
			$path = '/inbox'; // the instance' main inbox

			// Set up signing
			$hash = hash('sha256', $postJson, true);
			$digest = base64_encode($hash);
			$date = date('D, d M Y H:i:s \G\M\T');
			$stringToSign = "(request-target): post $path\nhost: $targetHost\ndate: $date\ndigest: SHA-256=$digest";

			$signature = $user->sign($stringToSign);

			$keyId = "https://{$this->hostname}/@{$account}#main-key";
			$header = 'keyId="' . $keyId . '",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="' . $signature . '"';

			//	Header for POST reply
			$headers = [
				"Host: {$targetHost}",
				"Date: {$date}",
				"Digest: SHA-256={$digest}",
				"Signature: {$header}",
				"Content-Type: application/activity+json",
				"Accept: application/activity+json",
			];

			// Specify the URL of the remote server
			$inboxUrl = "https://{$targetHost}{$path}";

			$this->deliverMessage($inboxUrl, $headers, $postJson);
		}

		die();
	}
}
