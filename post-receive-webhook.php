<?php
/*
 * PROJECT:     ReactOS GitHub Web Hook
 * LICENSE:     GPL-2.0+ (https://spdx.org/licenses/GPL-2.0+)
 * PURPOSE:     Validates a GitHub webhook request and spawns a subprocess to handle it asynchronously.
 * COPYRIGHT:   Copyright 2017-2018 Colin Finck (colin@reactos.org)
 */

	// This must be run exclusively with HTTPS security enabled!
	if (!array_key_exists("HTTPS", $_SERVER) || $_SERVER["HTTPS"] != "on")
		die("TLS required");

	// Verify that all necessary environment variables are set.
	// Using Apache's SetEnv, they appear in the $_SERVER array.
	if (!array_key_exists("GIT_PROJECT_ROOT", $_SERVER) || !array_key_exists("GITHUB_SECRET", $_SERVER))
		die("Environment variables not set");

	// Verify the signature format.
	if (!array_key_exists("HTTP_X_HUB_SIGNATURE", $_SERVER) || substr($_SERVER["HTTP_X_HUB_SIGNATURE"], 0, 5) != "sha1=")
		die("Wrong signature format");

	// Verify the signature itself.
	$http_signature = substr($_SERVER["HTTP_X_HUB_SIGNATURE"], 5);

	$post_data = file_get_contents("php://input");
	$valid_signature = hash_hmac("sha1", $post_data, $_SERVER["GITHUB_SECRET"]);

	if (!hash_equals($valid_signature, $http_signature))
		die("Invalid signature");

	// Verify the event.
	if (!array_key_exists("HTTP_X_GITHUB_EVENT", $_SERVER))
		die("No event");

	$event = $_SERVER["HTTP_X_GITHUB_EVENT"];

	// Check for the event.
	if ($event == "ping")
	{
		// ping is for testing and we only want to return a pong here.
		die("pong");
	}
	else if ($event == "pull_request" || $event == "push")
	{
		// Parse the JSON payload.
		$payload = json_decode($_POST["payload"]);
		if ($payload === NULL)
			die("Invalid payload");

		// Verify the supplied repository name.
		$repo = $payload->repository->name;
		$repopath = $_SERVER["GIT_PROJECT_ROOT"] . "/{$repo}.git";
		if (!is_dir($repopath))
			die("Invalid repo: {$repo}");

		// Process the request asynchronously in a subprocess to return to GitHub early.
		// Otherwise, we risk a "Service Timeout" (GitHub only waits 10 seconds for a webhook to complete).
		$arguments = [$repo, $repopath, $event];
		if ($event == "push")
		{
			$arguments[] = $payload->before;
			$arguments[] = $payload->after;
			$arguments[] = $payload->ref;
		}

		$argument_string = implode(" ", $arguments);

		shell_exec(__DIR__ . "/post-receive-webhook-worker.php {$argument_string} 1> /var/log/post-receive-webhook/{$repo}/worker.log 2>&1 &");
		die("Spawned subprocess");
	}
	else
	{
		die("Wrong event");
	}
