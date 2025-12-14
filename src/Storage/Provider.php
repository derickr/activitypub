<?php
namespace DerickR\ActivityPub\Storage;

use \DerickR\ActivityPub\DataTypes\Note;
use \DerickR\ActivityPub\User;

abstract class Provider
{
	protected ?\Closure $likeHandler = NULL;

	abstract public function hasUser( string $username ) : bool;
	abstract public function getUser( string $username ) : User;
	abstract public function getUserList() : array;
	abstract public function createUser(string $userName, string $displayName) : User;
	abstract public function saveUser( User $user ) : void;

	abstract public function addFollower( User $user, string $instance, string $account );

	abstract public function getAllPostIdsForUser( User $user ) : array;
	abstract public function getPostJson( User $user, string $postId ) : ?string;
	abstract public function storePostJson( User $user, string $postId, string $postJson ) : void;

	abstract public function getPost( User $user, string $postId ) : ?Note;
	abstract public function likePost( User $user, Note $post, string $actor, string $messageId ) : void;
	abstract public function unlikePost( User $user, Note $post, string $actor, string $messageId ) : void;

	protected static function convertToLocalPostId(string $postId) : string
	{
		/* https://social.derickrethans.nl/@blog/posts/d705a07a7c621e8168b526a5d7987f49.json#create */

		if (preg_match('%https?://.*/@.*/posts/([0-9a-f]+)%', $postId, $matches)) {
			return $matches[1];
		}

		throw new \ValueError("Can't retrieve local ID for '$postId'");
	}

	public function setLikeHandler(\Closure $handler)
	{
		$this->likeHandler = $handler;
	}
}
