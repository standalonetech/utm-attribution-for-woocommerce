#!/bin/sh
#
# PostToolUse hook — lints the ONE PHP file that was just written.
#
# Registered in .claude/settings.json for Write / Edit / MultiEdit. Runs on every edit, so
# it must stay fast and must never break a session:
#   - non-.php files exit immediately
#   - only the written file is linted, never the repo
#   - missing linter prints a hint and exits 0
#   - errors exit 2, which feeds the output back to the model as a correction
#
# BLOCKING BY DESIGN: phpcs.xml.dist reported 0 errors when this landed (Oct 2026), so any
# error is one the editor just introduced. Warnings are suppressed (--warning-severity=0).

set -u

root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)

# Hook payload is JSON on stdin. Parsed with php rather than jq — php is already required.
file=$(php -r '$i = json_decode(stream_get_contents(STDIN), true); echo is_array($i) ? ($i["tool_input"]["file_path"] ?? "") : "";')

case "$file" in
	*.php) ;;
	*) exit 0 ;;
esac

[ -f "$file" ] || exit 0

status=0

if ! syntax=$(php -l "$file" 2>&1); then
	printf '%s\n' "$syntax" >&2
	status=1
fi

if [ -x "$root/vendor/bin/phpcs" ]; then
	phpcs="$root/vendor/bin/phpcs"
elif command -v phpcs >/dev/null 2>&1; then
	phpcs=phpcs
elif [ -x "$HOME/.config/composer/vendor/bin/phpcs" ]; then
	phpcs="$HOME/.config/composer/vendor/bin/phpcs"
else
	echo "phpcs not installed — run \`composer global require wp-coding-standards/wpcs\` to enable linting."
	exit 0
fi

"$phpcs" --standard="$root/phpcs.xml.dist" --warning-severity=0 --no-colors -q -- "$file" >&2 || status=1

[ "$status" -eq 0 ] || exit 2
exit 0
