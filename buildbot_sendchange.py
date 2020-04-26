#!/usr/bin/env python3
#
# PROJECT:     buildbot_sendchange.py
# LICENSE:     GPL-2.0 (https://spdx.org/licenses/GPL-2.0)
# PURPOSE:     Inform BuildBot about Git commits and perform ReactOS-specific categorizing
# COPYRIGHT:   Copyright 2007-2017 BuildBot Contributors
#              Copyright 2017-2020 Colin Finck (colin@reactos.org)
#
# Largely based on https://github.com/buildbot/buildbot-contrib/blob/9df6a1b6dae44eecbd56ed5d6d7ac6952d39066e/master/contrib/git_buildbot.py
# but uses "buildbot sendchange" instead to do ReactOS-specific categorizing
#

import re
import subprocess
import sys

master = "localhost:9990"
repo = "https://git.reactos.org/reactos.git"

# Read oldrev, newrev, ref from stdin like every other Git post-receive hook
for hookinput in sys.stdin:
    [oldrev, newrev, ref] = hookinput.rstrip().split(None, 2)

    # Report all changes between oldrev and newrev
    revlist_pipe = subprocess.Popen(['git', 'rev-list', '--reverse', '{}..{}'.format(oldrev, newrev)], stdout=subprocess.PIPE)
    for revhash in revlist_pipe.stdout:
        revhash = revhash.decode('utf-8').rstrip()

        show_pipe = subprocess.Popen(['git', 'show', '--raw', '--pretty=full', revhash], stdout=subprocess.PIPE)
        files = []
        comments = []
        has_rostests = False

        for line in show_pipe.stdout:
            line = line.decode('utf-8')

            if line.startswith(4 * ' '):
                comments.append(line[4:])
                continue

            m = re.match(r"^:.*[MAD]\s+(.+)$", line)
            if m:
                files.append(m.group(1))

                if '/rostests/' in m.group(1):
                    has_rostests = True

                continue

            m = re.match(r"^Author:\s+([^!\"#$%&'*\/:;?\\^`]+)$", line)
            if m:
                author = m.group(1)
                continue

        if len(files) == 0:
            continue

        cmd = [
            '/srv/buildbot/master_env/bin/buildbot', 'sendchange',
            '--branch', 'master',
            '--logfile', '-',
            '--master', master,
            '--repository', repo,
            '--revision', revhash,
            '--who', author,
            '--vc', 'git'
        ]

        if has_rostests:
            cmd.extend(['--category', 'rostests'])

        cmd.extend(files)
        log = ''.join(comments)

        # Pass all this information to "buildbot sendchange"
        p = subprocess.Popen(cmd, stdin=subprocess.PIPE, encoding='utf-8')
        p.communicate(input=log.encode('utf-8'))
