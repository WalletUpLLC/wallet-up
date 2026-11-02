#!/bin/bash
# Sync the working tree of the repo into the local WordPress test site's plugin folder.
DST=/tmp/claude-0/-home-user-wallet-up/fd5e39c7-41c4-5b51-8c0a-c79cf509220e/scratchpad/wp/core/wp-content/plugins/wallet-up
rm -rf "$DST" && mkdir -p "$DST"
(cd /home/user/wallet-up && tar --exclude=./.git --exclude=./audit -cf - .) | tar -xf - -C "$DST"
grep -m1 "Version:" "$DST/wallet-up.php"
