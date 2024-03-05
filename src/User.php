<?php
namespace DerickR\ActivityPub;

class User
{
	public string $username;
	public string $name;
	public string $bio;
	public string $joinDate;
	public string $iconImage;
	public string $headerImage;
	public string $publicKey;
	private string $privateKey;
	private object $followers;
	private object $following;

	private function createKeyPair()
	{
		$config = [
			"private_key_bits" => 2048,
			"private_key_type" => OPENSSL_KEYTYPE_RSA,
		];

		$keypair = openssl_pkey_new($config);
		openssl_pkey_export($keypair, $privateKey);

		$publicKey = openssl_pkey_get_details($keypair);
		$publicKey = $publicKey["key"];

		$this->privateKey = $privateKey;
		$this->publicKey = $publicKey;
	}

	public function getPrivateKey()
	{
		return $this->privateKey;
	}

	static public function create(string $userName, string $displayName)
	{
		$new = new User;
		$new->username = $userName;
		$new->name = $displayName;
		$new->bio = "";
		$new->joinDate = date('Y-m-d\TH:i:s\Z');

		$new->createKeyPair();

		$new->iconImage = "/images/noIcon.jpg";
		$new->headerImage = "/images/noHeader.jpg";
		$new->followers = new \StdClass;
		$new->following = new \StdClass;

		return $new;
	}

	static public function createFromData( object $data ) : User
	{
		$new = new User;

		$new->username = $data->username;
		$new->name = $data->name ?: $data->username;
		$new->bio = $data->bio ?: "{$data->username} bio";
		$new->joinDate = $data->joinDate ?: date("Y-m-d\TH:i:s\Z");
		$new->iconImage = $data->iconImage ?: "/images/noIcon.jpg";
		$new->headerImage = $data->headerImage ?: "/images/noHeader.jpg";
		$new->followers = $data->followers ?: new \StdClass;
		$new->following = $data->following ?: new \StdClass;
		$new->privateKey = $data->privateKey;
		$new->publicKey = $data->publicKey;

		return $new;
	}

	public function asData() : array
	{
		return [
			'username' => $this->username,
			'name' => $this->name,
			'bio' => $this->bio,
			'joinDate' => $this->joinDate,
			'iconImage' => $this->iconImage,
			'headerImage' => $this->headerImage,
			'followers' => $this->followers,
			'following' => $this->following,
			'privateKey' => $this->privateKey,
			'publicKey' => $this->publicKey,
		];
	}

	public function getFollowers() : array
	{
		$all = [];

		foreach ($this->followers as $instance => $instanceFollowers) {
			foreach ($instanceFollowers->accounts as $account) {
				$all[] = $account;
			}
		}

		return $all;
	}

	public function getFollowerInstances() : array
	{
		$allInstances = [];

		foreach ($this->followers as $instance => $dummy) {
			$allInstances[] = $instance;
		}

		return $allInstances;
	}

	public function addFollower( string $instance, string $account ) : void
	{
		if (!isset($this->followers->$instance)) {
			$this->followers->$instance = new \stdClass;
			$this->followers->$instance->accounts = [];
		}
		if (!in_array($account, $this->followers->$instance->accounts)) {
			$this->followers->$instance->accounts[] = $account;
		}
	}

	public function removeFollower( string $instance, string $account ) : void
	{
		if (!isset($this->followers->$instance)) {
			return;
		}
		$this->followers->$instance->accounts = array_filter(
			$this->followers->$instance->accounts,
			function($v) use ($account) { return $v != $account; }
		);
	}

	public function sign( string $stringToSign ) : string
	{
		$signer = openssl_get_privatekey($this->privateKey);
		openssl_sign($stringToSign, $signature, $signer, OPENSSL_ALGO_SHA256);
		$signature_b64 = base64_encode($signature);

		return $signature_b64;
	}
}
