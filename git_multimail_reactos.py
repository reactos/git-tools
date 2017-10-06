#!/usr/bin/env python
import git_multimail
import sys

git_multimail.LINK_TEXT_TEMPLATE = "%(browse_url)s\n\n"
git_multimail.REVISION_INTRO_TEMPLATE = ""
git_multimail.REVISION_FOOTER_TEMPLATE = ""

git_multimail.main(sys.argv[1:])
