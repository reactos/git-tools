<?php
/*
 * PROJECT:     ReactOS GitHub Web Hook
 * LICENSE:     GPL-2.0+ (https://spdx.org/licenses/GPL-2.0+)
 * PURPOSE:     Updates the mirror repository and calls the post-receive hook just like Git would do if the commit was pushed to the mirror repository.
 * COPYRIGHT:   Copyright 2017 Colin Finck (colin@reactos.org)
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

	// Respond to a ping event without doing anything else.
	if ($_SERVER["HTTP_X_GITHUB_EVENT"] == "ping")
		die("pong");

	// Only handle "push" events from here onwards.
	if ($_SERVER["HTTP_X_GITHUB_EVENT"] != "push")
		die("Wrong event");

	// Parse the JSON payload.
	$payload = json_decode($_POST["payload"]);
	if ($payload === NULL)
		die("Invalid payload");

	// Update the mirror repository.
	chdir($_SERVER["GIT_PROJECT_ROOT"] . "/" . $payload->repository->name . ".git");
	$pp = popen("git remote update &> /dev/null", "w");
	$exit_code = pclose($pp);
	if ($exit_code != 0)
		die("git remote update exited with code {$exit_code}");

	// Call the post-receive hook.
	$pp = popen("hooks/post-receive &> /dev/null", "w");
	fwrite($pp, $payload->before . " " . $payload->after . " " . $payload->ref . "\n");
	$exit_code = pclose($pp);
	if ($exit_code != 0)
		die("post-receive hook exited with code {$exit_code}");

	// All done!
	die("OK");
