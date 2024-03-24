===================
ActivityPub Library
===================

The code in this repository can be used to run a light way activity pub server.

Set-Up
======

To use this, create a new directory for your project, and run the following steps::

	composer require derickr/activitypub
	mkdir data && chmod 0600 data && chown www-data:social-www-data data
	mkdir html
	mkdir scripts

The ``data`` directory needs to be able to be written to by your webserver's
Unix user (for example ``www-data``). Other files will also need to be written
to by the Unix user running scripts to create new users.

I have set up the additional group to include both myself (``derick``) and the
web server Unix user (``www-data``) under the group ``social-www-data``::

	social-www-data:x:1015:derick,www-data

In the ``html`` directory, create an ``index.php`` file with the following
content::

	<?php
	require '../vendor/autoload.php';

	use \DerickR\ActivityPub\Instance;
	use \DerickR\ActivityPub\Storage\OnDisk;

	/* Optional for debugging */
	ini_set('error_log', '/tmp/php-errors.log');
	ini_set('log_errors', true);

	$instance = $_SERVER['HTTP_HOST'];
	$i = new Instance( $instance, new OnDisk( __DIR__ . '/../data' ) );
	$i->setDebug( true );
	$i->run();

	if ( preg_match( '#^/images/(.*)\.jpg$#', $_SERVER['REQUEST_URI'], $matches ) )
	{
	    header('Content-Type: image/jpeg');
	
	    echo file_get_contents( __DIR__ . "/images/{$matches[1]}.jpg" );
	    exit();
	}

You can then set-up your web server to route all files through ``index.php``.
My **lighttpd** configuration section looks like::

	$HTTP["host"] =~ "^.*social\.example\.com$" {
	    url.rewrite-once = (
	        "^/.*?(\?.*)?$" => "/index.php$1"
	        )

	    accesslog.filename = "|/usr/bin/cronolog /var/log/lighttpd/social.example.com/%Y/%m-%d-access.log"
	}

Creating New Users
==================

You can create a new user with the following script (stored in the ``scripts``
directory)::

	<?php
	require '../vendor/autoload.php';

	use \DerickR\ActivityPub\Storage\OnDisk;

	if ($argc < 3) {
		echo "Usage: php new-user.php <username> <displayName>\n\n";
		die();
	}

	/** Configuration */
	$account = trim($argv[1]);
	$displayName = trim($argv[2]);

	/** INI Settings */
	ini_set('error_log', '/tmp/php-errors.log');
	ini_set('log_errors', true);

	$provider = new OnDisk(__DIR__ . '/../data');
	$provider->createUser($account, $displayName);

You might have to change the ``require`` line, depending on where you store your script.

Posting a Simple Status
=======================

To post a simple text status, I used the following code::

	<?php
	require '../vendor/autoload.php';

	use \DerickR\ActivityPub\DataTypes\Note;
	use \DerickR\ActivityPub\DataTypes\Tag;
	use \DerickR\ActivityPub\Instance;
	use \DerickR\ActivityPub\Storage\OnDisk;

	if ($argc < 3) {
			echo "Usage: php text-post.php <username> <text>\n\n";
			die();
	}

	/** Configuration */
	$instanceUri = 'social.derickrethans.nl';
	$account = trim($argv[1]);
	$text = trim($argv[2]);

	/** INI Settings */
	ini_set('error_log', '/tmp/php-errors.log');
	ini_set('log_errors', true);

	$provider = new OnDisk( __DIR__ . '/../data');
	$instance = new Instance($instanceUri, $provider);
	$instance->setDebug(true);

	$post = new Note("https://{$instance->getHostName()}/@{$account}", $text);
	$post->addTag(new Tag("HashTag", "#ActivityPub"));

	$message = $instance->newCreateMessage($account, $post);
	$message->addCC("https://{$instance->getHostName()}/@{$account}/followers");

	$instance->processMessage($message);

Posting a Complex Status
========================

To post something more complex, I use the following script::

	<?php
	require '../vendor/autoload.php';

	use \DerickR\ActivityPub\DataTypes\Create;
	use \DerickR\ActivityPub\DataTypes\Note;
	use \DerickR\ActivityPub\Instance;
	use \DerickR\ActivityPub\Storage\OnDisk;

	if ($argc < 3) {
		echo "Usage: php post.php <username> <url>\n\n";
		die();
	}

	/** Configuration */
	$instance = 'social.derickrethans.nl';
	$account = trim($argv[1]);
	$url = trim($argv[2]);

	/** INI Settings */
	ini_set('error_log', '/tmp/php-errors.log');
	ini_set('log_errors', true);

	$provider = new OnDisk( __DIR__ . '/../data');
	$instance = new Instance($instance, $provider);
	$instance->setDebug(true);

	$messageJson = file_get_contents($url);
	$post = Note::fromJsonString($messageJson);

	$message = $instance->newCreateMessage($account, $post);
	$message->addCC("https://{$instance->getHostName()}/@{$account}/followers");

	$instance->processMessage($message);

This reads the URL (or File) given by the second argument to use as post data.

This is a JSON file, which needs to have the contents like below. Make sure to
replace ``user`` by the name of the actual user, as given as first argument to
your script invocation::

	{
		"@context": [
			"https:\/\/www.w3.org\/ns\/activitystreams",
			{
				"Hashtag": "https:\/\/www.w3.org\/ns\/activitystreams#Hashtag"
			}
		],
		"id": "https:\/\/social.example.com\/@user\/posts\/unique-post-filename.json",
		"type": "Note",
		"published": "2024-03-01T18:30:00+00:00",
		"attributedTo": "https:\/\/social.example.org\/@user",
		"content": "",
		"to": [
			"https:\/\/www.w3.org\/ns\/activitystreams#Public"
		],
		"location": {
			"name": "Tasty Restaurant, London S1A 5ET, UK",
			"type": "Place"
		},
		"tag": [
			{
				"type": "Hashtag",
				"name": "#YourHashTag"
			}
		],
		"attachment": [
			{
				"type": "Image",
				"mediaType": "image\/jpeg",
				"url": "https:\/\/derickrethans-blog-photos.s3.eu-west-2.amazonaws.com\/friday-night-dinners\/nazuki-garden-1.jpg",
				"name": "Image Description"
			}
		]
	}

The ``location``, ``tag``, and ``attachment`` fields are all optional. Location
is modelled after https://www.w3.org/TR/activitystreams-vocabulary/#places;
tags after https://www.w3.org/TR/activitystreams-vocabulary/#microsyntaxes
(array form only); and attachments on
https://docs.joinmastodon.org/entities/MediaAttachment/

I'm not 100% sure where this all comes from, but it works from my experiments.
Mastodon doesn't show ``location`` yet though.
