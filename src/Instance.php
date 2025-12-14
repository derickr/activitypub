<?php
namespace DerickR\ActivityPub;

use DerickR\ActivityPub\DataTypes\Create;
use DerickR\ActivityPub\DataTypes\Update;
use DerickR\ActivityPub\DataTypes\Delete;
use DerickR\ActivityPub\DataTypes\DataType;
use Ramsey\Uuid\Uuid;

class Instance
{
	private bool $debug;
	private ?\Closure $replyHandler;

	public function __construct( private string $hostname, private Storage\Provider $provider )
	{
		$this->debug = false;
		$this->replyHandler = NULL;
	}

	public function setDebug( bool $debug )
	{
		$this->debug = $debug;
	}

	public function getHostName() : string
	{
		return $this->hostname;
	}

	public function setLikeHandler(\Closure $handler)
	{
		$this->provider->setLikeHandler($handler);
	}

	public function setReplyHandler(\Closure $handler)
	{
		$this->replyHandler = $handler;
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

		$iconImage = str_starts_with($user->iconImage, "http") ? $user->iconImage : "https://{$this->hostname}{$user->iconImage}";
		$headerImage = str_starts_with($user->headerImage, "http") ? $user->headerImage : "https://{$this->hostname}{$user->headerImage}";

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
				"url" => $iconImage,
			],
			"image" => [
				"type" => "Image",
				"mediaType" => "image/jpeg",
				"url" => $headerImage,
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
		error_log("REQ: Followers for '{$account}'");
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
			'size' => count( $followers ),
			'totalItems' => count( $followers ),
			'orderedItems' => $followers,
		];

		$this->setJsonContentType();
		echo json_encode( $data, JSON_PRETTY_PRINT );
		die();
	}

	protected function following( string $account ) : never
	{
		error_log("REQ: Following for '{$account}'");
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
		error_log("REQ: Post to inbox for '{$account}'");

		$payload = file_get_contents( 'php://input' );
		$payload = json_decode( $payload );

		if ( !$payload )
		{
			exit();
		}

		/* Save incoming data if we haven't handled it */
		if ( array_key_exists( 'HTTP_SIGNATURE', $_SERVER ) )
		{
			$this->logDebugMessage($_SERVER['HTTP_SIGNATURE'], __FILE__, __LINE__);
		}
		$this->logDebugMessage(json_encode($payload, JSON_PRETTY_PRINT), __FILE__, __LINE__);

		if ( $payload->type === 'Delete' )
		{
			/* Ignore */
			$this->processDeleteMessage( $account, $payload );
			exit();
		}

		if ( $payload->type === 'Undo' && $payload->object->type == 'Follow' )
		{
			$this->processUndoFollowMessage( $account, $payload );
			exit();
		}

		if ( $payload->type === 'Follow' )
		{
			$this->processFollowMessage( $account, $payload );
			exit();
		}

		if ( $payload->type === 'Undo' && $payload->object->type == 'Like' )
		{
			$this->processUndoLikeMessage( $account, $payload );
			exit();
		}

		if ( $payload->type === 'Like' )
		{
			$this->processLikeMessage( $account, $payload );
			exit();
		}

		if ( $payload->type === 'Create' )
		{
			$this->processCreateMessage( $account, $payload );
			exit();
		}

		die();
	}

	protected function outbox( string $account ) : never
	{
		error_log("REQ: Outbox for '{$account}'");

		if ( !$this->provider->hasUser( $account ) )
		{
			$this->returnNotFound('Account not found', [ 'account' => $account ] );
		}

		$user = $this->provider->getUser($account);
		$posts = $this->provider->getAllPostIdsForUser($user);

		$postMessages = [];
		foreach ($posts as $post) {
			$postMessages[] = [
				"type"   => "Create",
				"actor"  => "https://{$this->hostname}/@{$account}",
				"object" => $post,
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

		die();
	}

	private function accept( $account, string $messageId, string $actor )
	{
		$instance = parse_url($actor, PHP_URL_SCHEME) . "://" . parse_url($actor, PHP_URL_HOST);
		$targetHost = parse_url($actor, PHP_URL_HOST);
		$user = $this->provider->getUser($account);

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

	private function processFollowMessage( $account, $payload )
	{
		error_log("REQ: Follow for '{$account}'");

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
/*
		$user = $this->provider->getUser($account);
		$user->addFollower($targetHost, $actor);
		$this->provider->saveUser($user);
*/
		$user = $this->provider->getUser($account);
		$this->provider->addFollower($user, $targetHost, $actor);

		error_log("REQ: Follow for '{$account}' with '{$targetHost}'/'{$actor}'");

		$this->accept( $account, $messageId, $actor );
	}

	private function processUndoFollowMessage( $account, $payload )
	{
		error_log("REQ: Remove follow for '{$account}'");

		if (! $this->provider->hasUser($account))
		{
			$this->returnNotFound('Account not found', [ 'account' => $account ] );
		}

		/* Parse / grab data */
		$messageId = $payload->id;
		$actor = $payload->actor;
		$instance = parse_url($actor, PHP_URL_SCHEME) . "://" . parse_url($actor, PHP_URL_HOST);
		$targetHost = parse_url($actor, PHP_URL_HOST);

		/* Remove follower from user */
		$user = $this->provider->getUser($account);
		$user->removeFollower($targetHost, $actor);
		$this->provider->saveUser($user);

		error_log("REQ: Remove follow for '{$account}' from '{$targetHost}'/'{$actor}'");

		return;
	}

	private function processLikeMessage( $account, $payload )
	{
		error_log("REQ: Like for '{$account}'");

		if (! $this->provider->hasUser($account))
		{
			$this->returnNotFound('Account not found', [ 'account' => $account ] );
		}

		/* Parse / grab data */
		$messageId = $payload->id;
		$actor = $payload->actor;
		$objectId = $payload->object;

		error_log("REQ: Like: Find post for '{$account}' for messageId '{$messageId}' from '{$actor}' with objectId '{$objectId}'");

		/* Find post */
		$user = $this->provider->getUser($account);
		$post = $this->provider->getPost($user, $objectId);

		if ($post) {
			$this->provider->likePost($user, $post, $actor, $messageId);

			error_log("REQ: Like for '{$account}' for messageId '{$messageId}' from '{$actor}'");

			$this->accept( $account, $messageId, $actor );
		} else {

			error_log("REQ: Like / Not Found");
		}
	}

	private function processCreateMessage( $account, $payload )
	{
		error_log("REQ: Create for '{$account}'");

		if (! $this->provider->hasUser($account))
		{
			$this->returnNotFound('Account not found', [ 'account' => $account ] );
		}

		/* See if this is a reply to one of our posts */
/**
(object) array(
   'id' => 'https://phpc.social/users/derickr/statuses/114019000781723182/activity',
   'type' => 'Create',
   'actor' => 'https://phpc.social/users/derickr',
   'published' => '2025-02-17T11:31:22Z',
   'to' =>
  array (
    0 => 'https://www.w3.org/ns/activitystreams#Public',
  ),
   'cc' =>
  array (
    0 => 'https://phpc.social/users/derickr/followers',
    1 => 'https://social.derickrethans.nl/@user3',
  ),
   'object' =>
  (object) array(
     'id' => 'https://phpc.social/users/derickr/statuses/114019000781723182',
     'type' => 'Note',
     'summary' => NULL,
     'inReplyTo' => 'https://social.derickrethans.nl/@blog/posts/66e090a1e7c1667ca29d094f3d331b34',
     'published' => '2025-02-17T11:31:22Z',
     'url' => 'https://phpc.social/@derickr/114019000781723182',
     'attributedTo' => 'https://phpc.social/users/derickr',
     'to' =>
    array (
      0 => 'https://www.w3.org/ns/activitystreams#Public',
    ),
     'cc' =>
    array (
      0 => 'https://phpc.social/users/derickr/followers',
      1 => 'https://social.derickrethans.nl/@user3',
    ),
     'sensitive' => false,
     'atomUri' => 'https://phpc.social/users/derickr/statuses/114019000781723182',
     'inReplyToAtomUri' => 'https://social.derickrethans.nl/@blog/posts/66e090a1e7c1667ca29d094f3d331b34',
     'conversation' => 'tag:phpc.social,2025-02-16:objectId=52699155:objectType=Conversation',
     'content' => '<p><span class="h-card" translate="no"><a href="https://social.derickrethans.nl/@user3" class="u-url mention">@<span>user3</span></a></span> Testing a reply</p>',
     'contentMap' =>
    (object) array(
       'en' => '<p><span class="h-card" translate="no"><a href="https://social.derickrethans.nl/@user3" class="u-url mention">@<span>user3</span></a></span> Testing a reply</p>',
    ),
     'tag' =>
    array (
      0 =>
      (object) array(
         'type' => 'Mention',
         'href' => 'https://social.derickrethans.nl/@user3',
         'name' => '@user3@social.derickrethans.nl',
      ),
    ),

 **/
		if (isset($payload->object) && $payload->object->type == 'Note' && isset($payload->object->inReplyTo) && $this->replyHandler != NULL) {
			/* Process reply */
			/* Parse / grab data */
			$messageId = $payload->id;
			$actor = $payload->actor;
			$objectId = $payload->object->id;
			$inReplyToPost = $payload->object->inReplyTo;

			error_log("REQ: Create:Reply: Find post for '{$account}' from '{$actor}' with objectId '{$inReplyToPost}'");

			/* Find post to reply to */
			$user = $this->provider->getUser($account);
			$post = $this->provider->getPost($user, $inReplyToPost);

			if ($post) {
				$rh = $this->replyHandler;
				$rh($post->getId(), $actor, $payload->object->content);

				error_log("REQ: Create:Reply: for '{$account}' for objectId '{$objectId}' from '{$actor}'");

				/* We need to accept the *messageId* and not the objectId */
				$this->accept( $account, $messageId, $actor );
			} else {

				error_log("REQ: Create:Reply / Not Found");
			}
		}
	}


	private function processDeleteMessage( $account, $payload )
	{
		error_log("REQ: Delete for '{$account}'");

		if (! $this->provider->hasUser($account))
		{
			$this->returnNotFound('Account not found', [ 'account' => $account ] );
		}

		/* Parse / grab data */
		$messageId = $payload->id;
		$actor = $payload->actor;
		$objectId = $payload->object;

		error_log("REQ: Delete for '{$account}' for object '{$objectId}' from '{$actor}' with messageId '{$messageId}'");
	}

	private function processUndoLikeMessage( $account, $payload )
	{
		error_log("REQ: Unlike for '{$account}'");

		if (! $this->provider->hasUser($account))
		{
			$this->returnNotFound('Account not found', [ 'account' => $account ] );
		}

		/* Parse / grab data */
		$messageId = $payload->id;
		$actor = $payload->actor;
		$objectId = $payload->object->object;

		/* Find post */
		$user = $this->provider->getUser($account);
		$post = $this->provider->getPost($user, $objectId);

		if ($post) {
			$this->provider->unlikePost($user, $post, $actor, $messageId);

			error_log("REQ: Unlike for '{$account}' for messageId '{$messageId}' from '{$actor}'");

			$this->accept( $account, $messageId, $actor );
		} else {

//			$this->returnNotFound('Post not found', [ 'objectId' => $objectId ] );
		}
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

	public function returnOK(string $message, array $data) : never
	{
		header('HTTP/1.1 200 OK');
		$this->setJsonContentType();

		echo json_encode(
			[
				'message' => $message,
				...$data
			],
			JSON_PRETTY_PRINT
		);
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

	private function readFromUrl(string $url) : string
	{
		$headers = [
			"Content-Type: application/activity+json",
			"Accept: application/activity+json",
		];

		$ch = curl_init($url);

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		//curl_setopt($ch, CURLOPT_VERBOSE, 1);

		$response = curl_exec($ch);
		if (curl_errno($ch)) {
			$this->logDebugMessage(curl_error($ch), __FILE__, __LINE__);
		} else if ($response != '') {
			$this->logDebugMessage($response, __FILE__, __LINE__, [ 'url' => $url ] );
		}
		curl_close($ch);

		return $response;
	}

	private function deliverMessage(string $inbox, array $headers, string $message, string $protocol = "POST") : void
	{
		$ch = curl_init($inbox);

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $protocol);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $message);
		//curl_setopt($ch, CURLOPT_VERBOSE, 1);
		//curl_setopt($ch, CURLOPT_STDERR, fopen("php://stdout", "w"));

		$response = curl_exec($ch);
		if (curl_errno($ch)) {
			$this->logDebugMessage(curl_error($ch), __FILE__, __LINE__);
		} else if ($response != '') {
			$this->logDebugMessage($response, __FILE__, __LINE__);
		}
		curl_close($ch);
	}

	private function logDebugMessage(string $message, string $filename, int $lineno, array $extra = [])
	{
		if (! $this->debug) {
			return;
		}

		$f = fopen("/tmp/activity-pub-debug-" . posix_geteuid() . ".json", "a");

		$message = json_decode($message);
		$message = var_export($message, true) . "\n" . var_export($extra, true);

		if ($f) {
			$dstring = date('Y-m-d H:i:s');
			fwrite($f, "\n====={$dstring}\n===\n{$filename}:{$lineno}\n" . $message . "\n=====\n");
			fclose($f);
		}
	}

	public function newCreateMessage(string $account, DataType $post) : ?Create
	{
		if (! $this->provider->hasUser($account))
		{
			$this->returnNotFound('Account not found', [ 'account' => $account ] );
		}

		return new Create($post->getId() . '#create', "https://{$this->hostname}/@{$account}", $post);
	}

	public function newUpdateMessage(string $account, DataType $post) : ?Update
	{
		if (! $this->provider->hasUser($account))
		{
			$this->returnNotFound('Account not found', [ 'account' => $account ] );
		}

		/* Check if the post already exists */

		return new Update($post->getId(), "https://{$this->hostname}/@{$account}", $post);
	}

	public function newDeleteMessage(string $account, DataType $post) : ?Delete
	{
		if (! $this->provider->hasUser($account))
		{
			$this->returnNotFound('Account not found', [ 'account' => $account ] );
		}

		/* Check if the post already exists */

		return new Delete($post->getId(), "https://{$this->hostname}/@{$account}");
	}

	public function getUserList() : array
	{
		return $this->provider->getUserList();
	}

	public function getActorData(string $actorUrl) : \stdClass
	{
		$data = $this->readFromUrl($actorUrl);
		if (!$data) {
			throw new \ValueError("Can't read actor profile for {$actorUrl}");
		}

		$data = json_decode($data);
		if (!$data || !isset($data->inbox)) {
			throw new \ValueError("Can't decode JSON from reading actor profile for {$actorUrl}");
		}

		return $data;
	}

	/* Expect in the format @user@server */
	public function followUser(string $account, string $userName) : void
	{
		$user = $this->provider->getUser($account);

		[, $name, $server] = explode('@', $userName);

		$webfingerUrl  = "http://{$server}/.well-known/webfinger?resource=acct:{$name}@{$server}";
		$data = file_get_contents($webfingerUrl);

		if (!$data) {
			throw new \ValueError("Can't request webfinger for {$userName}");
		}

		$data = json_decode($data);
		if (!$data || !isset($data->links)) {
			throw new \ValueError("Can't decode JSON from webfinger result for {$userName}");
		}

		$userUrl = false;
		foreach ($data->links as $link) {
			if ($link->rel === 'self') {
				$userUrl = $link->href;
			}
		}
		if ($userUrl === false) {
			throw new \ValueError("Can't find rel=self link for {$userName}");
		}

		/* Get actor data */
		$data = $this->readFromUrl($userUrl);
		if (!$data) {
			throw new \ValueError("Can't read user profile for {$userName}");
		}

		$data = json_decode($data);
		if (!$data || !isset($data->inbox)) {
			throw new \ValueError("Can't decode JSON from reading user profile for {$userName}");
		}

		$profileInbox = $data->inbox;
		$profilePostbox = parse_url($profileInbox, PHP_URL_PATH);

		$guid = Uuid::uuid4()->toString();

		$message = [
			"@context" => "https://www.w3.org/ns/activitystreams",
			"id"       => "https://{$this->getHostName()}/{$guid}",
			"type"     => "Follow",
			"actor"    => "https://{$this->getHostName()}/@{$account}",
			"object"   => $userUrl,
		];

		$postJson = json_encode($message);

		$this->logDebugMessage($postJson, __FILE__, __LINE__);

		// Set up signing
		$hash = hash('sha256', $postJson, true);
		$digest = base64_encode($hash);
		$date = date('D, d M Y H:i:s \G\M\T');
		$stringToSign = "(request-target): post {$profilePostbox}\nhost: $server\ndate: $date\ndigest: SHA-256=$digest";
		echo $stringToSign, "\n";

		$signature = $user->sign($stringToSign);

		$keyId = "https://{$this->hostname}/@{$account}#main-key";
		$header = 'keyId="' . $keyId . '",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="' . $signature . '"';

		//	Header for POST reply
		$headers = [
			"Host: {$server}",
			"Date: {$date}",
			"Digest: SHA-256={$digest}",
			"Signature: {$header}",
			"Content-Type: application/activity+json",
			"Accept: application/activity+json",
		];

		$this->deliverMessage($profileInbox, $headers, $postJson);
	}

	public function processMessage(Create|Update|Delete $post)
	{
		/* Store post for posterity, which also makes it available in the outbox */
		$outboxItem = [ "@context" => "https://www.w3.org/ns/activitystreams" ];

		$embeddedObject = $post->getEmbeddedObject();
		$jsonData = $embeddedObject !== NULL ? (array) $embeddedObject->toJsonData() : [];

		foreach ($jsonData as $key => $value) {
			$outboxItem[$key] = $value;
		}

		$account = $post->getAccountName();

		$user = $this->provider->getUser($account);
		$this->provider->storePostJson($user, $post->getId(), json_encode($outboxItem, JSON_PRETTY_PRINT));

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
			//$inboxUrl = "http://{$targetHost}{$path}";

			$this->deliverMessage($inboxUrl, $headers, $postJson);
		}

		die();
	}
}
