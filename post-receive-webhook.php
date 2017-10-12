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

		// The following code should not be run concurrently.
		// Therefore, make sure that no previous instance of this script is still running.
		$lockfile = "/var/log/post-receive-webhook/{$repo}/post-receive-webhook-lock";
		while (file_exists($lockfile))
			sleep(5);

		///// BEGIN OF CRITICAL SECTION /////
		touch($lockfile);

		// Clear the mirror logfile.
		$logfile = "/var/log/post-receive-webhook/{$repo}/git-remote-update.log";
		file_put_contents($logfile, "");

		// Update the mirror repository.
		chdir($_SERVER["GIT_PROJECT_ROOT"] . "/{$repo}.git");
		$exit_code = 0;

		for ($i = 0; $i < 5; $i++)
		{
			// Write into the logfile about this attempt.
			$fp = fopen($logfile, "a");

			if ($exit_code != 0)
				fwrite($fp, "git remote update exited with code {$exit_code}\n\n");

			fwrite($fp, "================ ATTEMPT {$i} ================\n");
			fclose($fp);

			// Run "git remote update".
			$pp = popen("git remote update 1>> {$logfile} 2>&1", "w");
			$exit_code = pclose($pp);
			if ($exit_code == 0)
				break;

			// Wait 3 seconds before trying it another time.
			sleep(3);
		}

		// Load the queue.
		$queuefile = "/var/log/post-receive-webhook/{$repo}/queue";
		$queue = unserialize(file_get_contents($queuefile));
		if (!is_array($queue))
			$queue = array();

		// If this is a push event, we want to enqueue information for the post-receive hook.
		if ($event == "push")
			$queue[] = array($payload->before, $payload->after, $payload->ref);

		// Check if "git remote update" above has succeeded.
		if ($i < 5)
		{
			// Call the post-receive hook for all queued updates.
			foreach ($queue as $u)
			{
				$pp = popen("hooks/post-receive 1> /dev/null 2>&1", "w");
				fwrite($pp, $u[0] . " " . $u[1] . " " . $u[2] . "\n");
				pclose($pp);
			}

			// All done.
			$queue = array();
			echo "OK";
		}
		else
		{
			echo "git remote update failed";
		}

		file_put_contents($queuefile, serialize($queue));
		unlink($lockfile);
		///// END OF CRITICAL SECTION /////
	}
	else
	{
		die("Wrong event");
	}
