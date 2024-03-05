<?php
namespace DerickR\ActivityPub\Storage;

use \DerickR\ActivityPub\Post;
use \DerickR\ActivityPub\User;
use ValueError;

class OnDisk implements Provider
{
	const usersPath = 'users';
	const infoFile = 'info.json';
	const relationsFile = 'relations.json';

	private string $usersPath;

	function __construct(private string $path)
	{
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

	public function hasUser(string $username) : bool
	{
		return file_exists("{$this->usersPath}/{$username}/" . self::infoFile);
	}

	private function getUserInfo(string $username) : \stdClass
	{
		return json_decode(file_get_contents("{$this->usersPath}/{$username}/" . self::infoFile));
	}

	private function getUserRelations(string $username) : array
	{
		if (!file_exists("{$this->usersPath}/{$username}/" . self::relationsFile)) {
			return ['following' => [], 'followers' => []];
		}

		return (array) json_decode(file_get_contents("{$this->usersPath}/{$username}/" . self::relationsFile));
	}

	public function getUser(string $username) : User
	{
		$userInfo = $this->getUserInfo($username);
		$relations = $this->getUserRelations($username);

		$userInfo->followers = $relations['followers'];
		$userInfo->following = $relations['following'];

		return User::createFromData($userInfo);
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
		$data = $user->asData();
		unset($data['followers']);
		unset($data['following']);

		file_put_contents("{$this->usersPath}/{$user->username}/" . self::infoFile, json_encode($data, JSON_PRETTY_PRINT));
	}

	private function saveUserRelations(User $user) : void
	{
		$data = $user->asData();
		$relationsData = [
			'followers' => $data['followers'] ?: [],
			'following' => $data['following'] ?: []
		];
		file_put_contents("{$this->usersPath}/{$user->username}/" . self::relationsFile, json_encode($relationsData, JSON_PRETTY_PRINT));
	}

	public function saveUser(User $user) : void
	{
		$this->saveUserInfo($user);
		$this->saveUserRelations($user);
	}

	public function getAllPostsForUser( User $user ) : array
	{
		$posts = [];
		$postFiles = array_reverse(glob($this->usersPath . '/' . $user->username . '/posts/*.json'));

		foreach ($postFiles as $postFile) {
			$pathInfo = pathinfo($postFile);
			$posts[] = $pathInfo['filename'];
		}

		return $posts;
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

		return file_get_contents( $path );
	}

	public function storePostJson( User $user, string $postId, string $postJson ) : void
	{
		$path = $this->getPostsPath($user) . $postId . '.json';

		file_put_contents($path, $postJson);
	}
}
