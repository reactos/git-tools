#!/usr/bin/php
<?php
/*
 * PROJECT:     ReactOS Website
 * LICENSE:     GPL-2.0+ (https://spdx.org/licenses/GPL-2.0+)
 * PURPOSE:     Populates the GitInfo database with information about commits to the master branch
 * COPYRIGHT:   Copyright 2017-2018 Colin Finck (colin@reactos.org)
 */

	// Configuration
	define("DB_HOST", "localhost");
	define("DB_USER", "gitinfo_writer");
	define("DB_PASS", "");
	define("DB_GITINFO", "gitinfo");
	define("LOCKFILE", "/tmp/add-gitinfo-lock");


	// Make sure that only one instance of this script is used at the same time!
	while (file_exists(LOCKFILE))
		sleep(5);

	touch(LOCKFILE);

	try
	{
		// Connect to the database.
		$dbh = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_GITINFO, DB_USER, DB_PASS);
		$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		// STEP 1: Get the latest master revision.
		$newrev = trim(`git rev-parse refs/heads/master`);

		// STEP 2: Get the latest revision added to the master_revisions table.
		$oldrev_stmt = $dbh->query("SELECT rev_hash FROM master_revisions ORDER BY id DESC LIMIT 1");
		$oldrev = $oldrev_stmt->fetchColumn();
		if (!$oldrev)
			throw new RuntimeException("master_revisions table is empty!");

		// STEP 3: Get all revisions in between and add them to the "master_revisions_todo" table.
		$todo_stmt = $dbh->prepare("REPLACE master_revisions_todo (oldrev, newrev) VALUES (:oldrev, :newrev)");
		$todo_stmt->bindParam(":oldrev", $oldrev);
		$todo_stmt->bindParam(":newrev", $newrev);

		$pp = popen("git rev-list --reverse $oldrev..$newrev", "r");
		while (($line = fgets($pp)) !== FALSE)
		{
			$newrev = trim($line);
			$todo_stmt->execute();
			$oldrev = $newrev;
		}

		pclose($pp);

		// STEP 4: Process the "master_revisions_todo" table into a linear "master_revisions" table.
		$newrev_stmt = $dbh->prepare("SELECT newrev FROM master_revisions_todo WHERE oldrev = (SELECT rev_hash FROM master_revisions ORDER BY id DESC LIMIT 1)");

		$insert_stmt = $dbh->prepare("INSERT INTO master_revisions (rev_hash, author_name, author_email, commit_timestamp, message) VALUES (:rev_hash, :author_name, :author_email, FROM_UNIXTIME(:commit_timestamp), COMPRESS(:message))");
		$insert_stmt->bindParam(":rev_hash", $newrev);
		$insert_stmt->bindParam(":author_name", $author_name);
		$insert_stmt->bindParam(":author_email", $author_email);
		$insert_stmt->bindParam(":commit_timestamp", $commit_timestamp);
		$insert_stmt->bindParam(":message", $message);

		$delete_stmt = $dbh->prepare("DELETE FROM master_revisions_todo WHERE newrev = :newrev");
		$delete_stmt->bindParam(":newrev", $newrev);

		for (;;)
		{
			// Check if there is any newrev in our todo table, which directly follows the last rev in the master_revisions table.
			$newrev_stmt->execute();
			$newrev = $newrev_stmt->fetchColumn();
			if (!$newrev)
				break;

			// Get commit information for it.
			$author_name = trim(`git show -s --format=%an $newrev`);
			$author_email = trim(`git show -s --format=%ae $newrev`);
			$commit_timestamp = trim(`git show -s --format=%ct $newrev`);
			$message = trim(`git show -s --format=%B $newrev`);

			// Insert this into the master_revisions table and delete it from the todo table.
			$insert_stmt->execute();
			$delete_stmt->execute();
		}
	}
	catch (Exception $e)
	{
		echo "LINE: " . $e->getLine() . "\n";
		echo $e->getMessage();
	}

	// Let the next call proceed.
	unlink(LOCKFILE);
