#!/bin/bash
# Fresh WordPress: wipe the SQLite DB, reinstall, keep plain permalinks (WordPress default).
S=/tmp/claude-0/-home-user-wallet-up/fd5e39c7-41c4-5b51-8c0a-c79cf509220e/scratchpad
rm -rf $S/wp/core/wp-content/database $S/wp/core/wp-content/debug.log $S/pw/state.json
mkdir -p $S/wp/core/wp-content/database
curl -s -d "weblog_title=Test+Shop&user_name=admin&admin_password=password&admin_password2=password&pw_weak=1&admin_email=admin@example.com&blog_public=0&Submit=Install+WordPress&language=" "http://127.0.0.1:8080/wp-admin/install.php?step=2" | grep -oE "<h1>[^<]+</h1>" | head -1
