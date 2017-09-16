#!/usr/bin/python3
#
# buildbot_sendchange.py - Inform BuildBot about this commit
# Written by Colin Finck <colin@reactos.org>
#
# Inspired by https://github.com/buildbot/buildbot/blob/master/master/contrib/svn_buildbot.py
# but uses "buildbot sendchange" instead to do ReactOS-specific categorizing
#

"""
buildbot_sendchange.py <REV>
"""

import subprocess
import sys

# Get the arguments
if len(sys.argv) != 2:
    print(__doc__)
    exit(1)

master = "localhost:9990"
repo = "/srv/svn/reactos"
rev = sys.argv[1]

# Get the changed files, remove 4 columns of status information
changed = subprocess.getoutput("svnlook changed -r %s %s" % (rev, repo)).split("\n")
changed = [x[4:] for x in changed]

# Get other commit information
author = subprocess.getoutput("svnlook author -r %s %s" % (rev, repo))
log = subprocess.getoutput("svnlook log -r %s %s" % (rev, repo))

# Filter the files and set category
category_arg = ""
files = ""

for f in changed:
    if f.startswith("trunk/reactos") or f.startswith("trunk/rostests"):
        files += '"' + f + '" '
        if f.startswith("trunk/rostests"):
            category_arg = "--category rostests"

# Pass all this information to "buildbot sendchange"
if files:
    p = subprocess.Popen("buildbot sendchange %s --logfile - --master %s --repository %s --revision %s --who %s --vc svn %s" % (category_arg, master, repo, rev, author, files), stdin=subprocess.PIPE, shell=True)
    p.communicate(input=log.encode())
