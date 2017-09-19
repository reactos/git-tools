#!/usr/bin/env python
#
# PROJECT:     buildbot_sendchange.py
# LICENSE:     GPL-2.0 (https://spdx.org/licenses/GPL-2.0)
# PURPOSE:     Inform BuildBot about Git commits and perform ReactOS-specific categorizing
# COPYRIGHT:   Copyright 2007-2017 BuildBot Contributors
#              Copyright 2017 Colin Finck (colin@reactos.org)
#
# Largely based on https://github.com/buildbot/buildbot-contrib/blob/9df6a1b6dae44eecbd56ed5d6d7ac6952d39066e/master/contrib/git_buildbot.py
# but uses "buildbot sendchange" instead to do ReactOS-specific categorizing
#

import re
import subprocess
import sys

master = "localhost:9990"
repo = "git://git.reactos.org/reactos.git"

# Read oldrev, newrev, ref from stdin like every other Git post-receive hook
for hookinput in sys.stdin:
    [oldrev, newrev, ref] = hookinput.rstrip().split(None, 2)

    # Only send changes to the master branch to BuildBot
    if ref != "refs/heads/master":
        continue

    # Report all changes between oldrev and newrev
    revlist_pipe = subprocess.Popen("git rev-list --reverse %s..%s" % (oldrev, newrev), stdout=subprocess.PIPE)
    for revhash in revlist_pipe.stdout:
        revhash = revhash.rstrip()

        show_pipe = subprocess.Popen("git show --raw --pretty=full %s" % revhash, stdout=subprocess.PIPE)
        files = []
        comments = []

        for line in show_pipe.stdout:
            if line.startswith(4 * ' '):
                comments.append(line[4:])
                continue

            m = re.match(r"^:.*[MAD]\s+(.+)$", line)
            if m:
                files.append(text_type(m.group(1), encoding=encoding))
                continue

            m = re.match(r"^Author:\s+(.+)$", line)
            if m:
                author = text_type(m.group(1), encoding=encoding)
                continue

        log = ''.join(comments)

        # Prepare the category and files arguments
        category_arg = ""
        files_arg = ' '.join('"{0}"'.format(w) for w in files)
        if "/rostests/" in files_arg:
            category_arg = "--category rostests"

        # Pass all this information to "buildbot sendchange"
        if files_arg:
            p = subprocess.Popen("buildbot sendchange %s --logfile - --master %s --repository %s --revision %s --who %s --vc git %s" % (category_arg, master, repo, revhash, author, files_arg), stdin=subprocess.PIPE)
            p.communicate(input=log.encode())
