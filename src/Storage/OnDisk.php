<?php
namespace DerickR\ActivityPub\Storage;

use \DerickR\ActivityPub\DataTypes\Note;
use \DerickR\ActivityPub\User;
use ValueError;

class OnDisk extends Provider
{
	const usersPath = 'users';
	const infoFile = 'info.json';
	const relationsFile = 'relations.json';
	const likesFile = 'likes.json';

	private string $usersPath;

	function __construct(private string $path)
	{
		$path = realpath($path);

		if (!file_exists($path))
		{
			throw new ValueError("The storage path '{$path}' does not exist");
		}

		if (!file_exists($path . '/' . self::usersPath))
		{
			mkdir($path . '/' . self::usersPath);
			chmod(0777, $path . '/' . self::usersPath);
		}

		$this->usersPath = $path . '/' . self::usersPath;
	}

	private function readContents(string $filename) : string
	{
		$f = fopen($filename, "r");
		flock($f, LOCK_SH);
		$data = stream_get_contents($f);
		flock($f, LOCK_UN);

		return $data;
	}

	private function writeContents(string $filename, string $content) : void
	{
		$f = fopen($filename, "w");
		flock($f, LOCK_EX);
		fwrite($f, $content);
		flock($f, LOCK_UN);
	}


	public function hasUser(string $username) : bool
	{
		return file_exists("{$this->usersPath}/{$username}/" . self::infoFile);
	}

	private function getUserInfo(string $username) : \stdClass
	{
		return json_decode($this->readContents("{$this->usersPath}/{$username}/" . self::infoFile));
	}

	private function getUserRelations(string $username) : array
	{
		if (!file_exists("{$this->usersPath}/{$username}/" . self::relationsFile)) {
			return ['following' => [], 'followers' => []];
		}

		return (array) json_decode($this->readContents("{$this->usersPath}/{$username}/" . self::relationsFile));
	}

	public function getUser(string $username) : User
	{
		$userInfo = $this->getUserInfo($username);
		$relations = $this->getUserRelations($username);

		$userInfo->followers = $relations['followers'];
		$userInfo->following = $relations['following'];

		return User::createFromData($userInfo);
	}

	public function getUserList() : array
	{
		$users = glob( "{$this->usersPath}/*/" . self::infoFile );
		$userList = [];

		foreach ($users as $user)
		{
			if (preg_match( "@.*/(.*)/.*$@", $user, $matches))
			{
				$userName = $matches[1];
				$userInfo = $this->getUser($userName);
				$userList[$userInfo->username] = $userInfo->name;
			}
		}

		return $userList;
	}

	public function createUser(string $username, string $displayName) : User
	{
		$user = User::create($username, $displayName);

		umask(0);
		@mkdir($this->usersPath . '/' . $username, 0777);
		@mkdir($this->usersPath . '/' . $username  . '/images', 0777);
		@mkdir($this->usersPath . '/' . $username  . '/posts', 0777);

		$this->saveUserInfo($user);

		return $this->getUser($username);
	}

	private function saveUserInfo(User $user) : void
	{
		$data = $user->getInfo();
		$this->writeContents("{$this->usersPath}/{$user->username}/" . self::infoFile, json_encode($data, JSON_PRETTY_PRINT));
	}

	public function addFollower( User $user, string $instance, string $account )
	{
		$user->addFollower($instance, $account);
		$this->saveUserRelations($user);
	}

	private function saveUserRelations(User $user) : void
	{
		$data = $user->getRelations();
		$relationsData = [
			'followers' => $data['followers'] ?: [],
			'following' => $data['following'] ?: []
		];
		$this->writeContents("{$this->usersPath}/{$user->username}/" . self::relationsFile, json_encode($relationsData, JSON_PRETTY_PRINT));
	}

	public function saveUser(User $user) : void
	{
		$this->saveUserInfo($user);
		$this->saveUserRelations($user);
	}

	public function getAllPostIdsForUser( User $user ) : array
	{
		$posts = [];
		$postFiles = array_reverse(glob($this->usersPath . '/' . $user->username . '/posts/*.json'));

		foreach ($postFiles as $postFile) {
			$posts[] = json_decode($this->readContents($postFile));
		}

		uasort($posts, function($a, $b) {
			return $a->published <=> $b->published;
		});

		$ids = array_map(function($a) { return $a->id; }, $posts );

		return $ids;
	}

	private function getPostsPath( User $user ) : string
	{
		return $this->usersPath . '/' . $user->username . '/posts/';
	}

	public function getPostJson( User $user, string $postId ) : ?string
	{
		$path = $this->getPostsPath($user) . $postId . '.json';

		if (! file_exists($path)) {
			return null;
		}

		return $this->readContents( $path );
	}

	public function storePostJson( User $user, string $postId, string $postJson ) : void
	{
		$localPostId = self::convertToLocalPostId($postId);
		$path = $this->getPostsPath($user) . $localPostId . '.json';

		$this->writeContents($path, $postJson);
	}

	public function getPost( User $user, string $objectId ) : ?Note
	{
		$postId = preg_replace('@.*/@', '', $objectId);

		$postJson = $this->getPostJson($user, $postId);

		if (!$postJson) {
			return NULL;
		}

		$jsonData = json_decode($postJson);

		if (!is_object($jsonData)) {
			return NULL;
		}

		return Note::fromJsonData($jsonData);
	}

	public function likePost( User $user, Note $post, string $actor, string $messageId ) : void
	{
		error_log(json_encode([$post->getId(), $actor, $messageId]));
		$cl = $this->likeHandler;
		if ($cl !== null) {
			$cl($post->getId(), $actor);
		}
	}

	public function unlikePost( User $user, Note $post, string $actor, string $messageId ) : void
	{
		error_log(json_encode([$post->getId(), $actor, $messageId]));
	}
}
