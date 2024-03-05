<?php
namespace DerickR\ActivityPub\Storage;

use \DerickR\ActivityPub\Post;
use \DerickR\ActivityPub\User;

interface Provider
{
	public function hasUser( string $username ) : bool;
	function getUser( string $username ) : User;
	public function createUser(string $userName, string $displayName) : User;
	public function saveUser( User $user ) : void;

	public function getAllPostsForUser( User $user ) : array;
	public function getPostJson( User $user, string $postId ) : ?string;
	public function storePostJson( User $user, string $postId, string $postJson ) : void;
}
