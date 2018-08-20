#!/usr/bin/php
<?php
/*
 * PROJECT:     ReactOS GitHub Web Hook
 * LICENSE:     GPL-2.0+ (https://spdx.org/licenses/GPL-2.0+)
 * PURPOSE:     Worker process to update the mirror repository and call the post-receive hook just like Git would do if the commit was pushed to the mirror repository.
 * COPYRIGHT:   Copyright 2017-2018 Colin Finck (colin@reactos.org)
 */

	// "post-receive-webhook.php" has passed some arguments.
	if ($argc < 4)
		die("Parameters missing");

	$repo = $argv[1];
	$repopath = $argv[2];
	$event = $argv[3];

	// The following code should not be run concurrently.
	// Therefore, make sure that no previous instance of this script is still running.
	$lockfile = "/var/log/post-receive-webhook/{$repo}/post-receive-webhook-lock";
	$lock_fp = fopen($lockfile, "c+");
	if (!$lock_fp)
		die("Could not create the lockfile $lockfile");

	if (!flock($lock_fp, LOCK_EX))
		die("Could not lock the lockfile $lockfile");

	///// BEGIN OF CRITICAL SECTION /////
	// Clear the mirror logfile.
	$logfile = "/var/log/post-receive-webhook/{$repo}/git-remote-update.log";
	file_put_contents($logfile, "");

	// Update the mirror repository.
	chdir($repopath);
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
	{
		$payload_before = $argv[4];
		$payload_after = $argv[5];
		$payload_ref = $argv[6];
		$queue[] = array($payload_before, $payload_after, $payload_ref);
	}

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
	///// END OF CRITICAL SECTION /////

	flock($lock_fp, LOCK_UN);
	fclose($lock_fp);
