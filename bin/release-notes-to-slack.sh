#!/usr/bin/env bash
set -euo pipefail

# Generate structured release notes from git commits using Claude CLI,
# then optionally post to #tech-system_releases on Slack (with y/N confirmation).
#
# This repo is a library: athletica-app requires it as dev-master, so a change
# "ships" when athletica-app next runs `composer update` and deploys. With no
# range given, the notes cover everything since the commit athletica-app's
# composer.lock currently pins, i.e. what the next update will pull in.
#
# Usage:
#   bin/release-notes-to-slack.sh                        # preview to stdout
#   bin/release-notes-to-slack.sh --post                 # post to Slack (asks y/N first)
#   bin/release-notes-to-slack.sh ba298fa..HEAD          # specific range
#   bin/release-notes-to-slack.sh --post ba298fa..HEAD   # specific range + post
#   bin/release-notes-to-slack.sh --commits=abc123,def456,ghi789
#                                                        # specific commits only
#                                                        # (mutually exclusive with range)
#   bin/release-notes-to-slack.sh --post --thanks="@harrison for investigation on CX-87"
#                                                        # repeatable: pass --thanks multiple
#                                                        # times to credit multiple people
#
# Requires:
#   - claude CLI installed and on PATH
#   - SLACK_RELEASE_NOTES_WEBHOOK_URL env var, or set in this repo's .env or
#     athletica-app's .env (only for --post)
#   - jq installed (only for --post)
#
# Optional:
#   - SLACK_USER_ID env var (same lookup as above) — when present, "Released by:"
#     uses Slack's <@U…> mention so the message tags your Slack profile.
#     Falls back to `git config user.name` when unset.
#   - ATHLETICA_APP_DIR — path to the athletica-app checkout, used for the
#     default range and as a .env fallback. Defaults to ../athletica-app.

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
REPO_DIR="${SCRIPT_DIR}/.."
APP_DIR="${ATHLETICA_APP_DIR:-${REPO_DIR}/../athletica-app}"
cd "$REPO_DIR"

# Load a variable from this repo's .env, then athletica-app's .env, if it
# isn't already in the environment.
load_env_var() {
  local var_name="$1"
  if [ -n "${!var_name:-}" ]; then
    return
  fi
  local env_file env_val
  for env_file in "${REPO_DIR}/.env" "${APP_DIR}/.env"; do
    [ -f "$env_file" ] || continue
    env_val=$(grep -E "^${var_name}=" "$env_file" | head -n1 | cut -d= -f2-) || true
    env_val="${env_val%\"}"; env_val="${env_val#\"}"
    env_val="${env_val%\'}"; env_val="${env_val#\'}"
    [ -n "$env_val" ] || continue
    export "${var_name}=${env_val}"
    return
  done
}

# Print the commit of this package that athletica-app's composer.lock pins on
# its master branch, or nothing if it can't be found.
app_pinned_commit() {
  [ -d "$APP_DIR/.git" ] || return 0
  git -C "$APP_DIR" show master:composer.lock 2>/dev/null \
    | jq -r '.packages[] | select(.name == "adriangibbons/php-fit-file-analysis") | .source.reference' 2>/dev/null \
    || true
}

POST=false
RANGE=""
THANKS=()
COMMITS_RAW=""

for arg in "$@"; do
  case "$arg" in
    --post) POST=true ;;
    --thanks=*) THANKS+=("${arg#--thanks=}") ;;
    --commits=*) COMMITS_RAW="${arg#--commits=}" ;;
    *) RANGE="$arg" ;;
  esac
done

if [ -n "$COMMITS_RAW" ] && [ -n "$RANGE" ]; then
  echo "Error: --commits cannot be combined with a range argument." >&2
  exit 2
fi

# Validate --post preconditions early so we don't burn a Claude CLI call
# only to discover the webhook is missing or jq isn't installed.
if [ "$POST" = true ]; then
  load_env_var SLACK_RELEASE_NOTES_WEBHOOK_URL || true

  if [ -z "${SLACK_RELEASE_NOTES_WEBHOOK_URL:-}" ]; then
    echo "Error: SLACK_RELEASE_NOTES_WEBHOOK_URL is not set" >&2
    echo "Set it in .env or with: export SLACK_RELEASE_NOTES_WEBHOOK_URL=https://hooks.slack.com/services/..." >&2
    exit 1
  fi

  if ! command -v jq &> /dev/null; then
    echo "Error: jq is required for --post mode" >&2
    exit 1
  fi
fi

if [ -n "$COMMITS_RAW" ]; then
  IFS=',' read -ra COMMITS_ARRAY <<< "${COMMITS_RAW//[[:space:]]/}"
  for sha in "${COMMITS_ARRAY[@]}"; do
    if [ -z "$sha" ] || ! git rev-parse --verify --quiet "$sha^{commit}" >/dev/null 2>&1; then
      echo "Error: not a valid commit: $sha" >&2
      exit 2
    fi
  done
  COMMITS=$(git log --no-walk "${COMMITS_ARRAY[@]}" --pretty=format:"%h %s%n%b")
else
  if [ -z "$RANGE" ]; then
    PINNED=""
    if command -v jq &> /dev/null; then
      PINNED=$(app_pinned_commit)
    fi
    if [ -n "$PINNED" ] && git rev-parse --verify --quiet "$PINNED^{commit}" >/dev/null 2>&1; then
      RANGE="${PINNED:0:7}..HEAD"
      echo "Range: ${RANGE} (since the commit athletica-app's composer.lock pins)" >&2
    else
      echo "Error: could not read the pinned commit from ${APP_DIR}/composer.lock on master." >&2
      echo "Pass a range explicitly, e.g. bin/release-notes-to-slack.sh ba298fa..HEAD" >&2
      exit 2
    fi
  fi
  COMMITS=$(git log "$RANGE" --pretty=format:"%h %s%n%b" --no-merges)
fi

if [ -z "$COMMITS" ]; then
  if [ -n "$COMMITS_RAW" ]; then
    echo "No commits found from --commits list"
  else
    echo "No commits found in range $RANGE"
  fi
  exit 0
fi

load_env_var SLACK_USER_ID || true
if [ -n "${SLACK_USER_ID:-}" ]; then
  DEPLOYER="<@${SLACK_USER_ID}>"
else
  DEPLOYER=$(git config user.name || echo "unknown")
fi
# The only tags in this repo are upstream's (v3.2.4 and earlier), so identify
# the release by commit rather than by a misleading `git describe`.
VERSION=$(git rev-parse --short HEAD)
DATE=$(date +%Y-%m-%d)

read -r -d '' PROMPT << 'PROMPT_END' || true
Generate internal release notes from the git commits below. This is for the FIT PARSER (php-fit-file-analysis) codebase — Athletica's fork of the PHP library that decodes Garmin FIT activity files. athletica-app uses it to read every uploaded or synced activity (records, laps, sessions, heart rate, power, device and sport metadata).

The audience is a mixed internal team (business, technical, and support staff). The notes will be posted to #tech-system_releases on Slack.

Rules:
- Split into exactly two sections with these exact headers:
  — USER-FACING CHANGES —
  — INTERNAL CHANGES —
- User-facing changes are things athletes/coaches will notice in their imported activities (wrong or missing values fixed, new devices or sports recognised, files that previously failed now importing). Each item must start with [Area] Fixed/New/Changed: (areas: Power, Heart rate, Speed, Laps, Sessions, Devices, Sports, Swim, Multisport, Developer fields, Performance, etc.)
- Internal changes are dev/infra work users will NOT notice (refactors, logging, tests, dependencies, CI)
- If there are no user-facing changes, write "No user-facing changes in this release." under that section
- Add a — HEADS UP — section (optional) if there are risky or operationally relevant changes (changed output shape or field names, changed units, behaviour athletica-app code relies on, anything needing a reparse of existing activities)
- Use bullet points with the • character
- Plain, clear language for user-facing items; technical detail is fine for internal items
- Extract any Jira ticket references (APP-xxx, CX-xxx, PAR-xxx, SCRUM-xxx) from commit messages and list them at the end as 🎫 Jira: APP-xxx, CX-xxx
- Slack-compatible text only: *bold* for emphasis, no markdown headings, no code fences in the output
- Sentence case, no em dashes
- Keep it tight; this is for a Slack channel, not a changelog page
PROMPT_END

FULL_PROMPT="${PROMPT}

Format the header as:
🚀 *FIT PARSER ${VERSION}* — released to athletica-app (takes effect on its next composer update and deploy)
👤 Released by: ${DEPLOYER}
📅 ${DATE}

Commits:
${COMMITS}"

NOTES=$(echo "$FULL_PROMPT" | claude -p --model sonnet)

if [ ${#THANKS[@]} -gt 0 ]; then
  NOTES+=$'\n'
  for thanks in "${THANKS[@]}"; do
    NOTES+=$'\n'":raised_hands: Thanks to ${thanks}"
  done
fi

if [ "$POST" = true ]; then
  echo "$NOTES"
  echo
  read -rp "Post these notes to Slack? [y/N] " ans
  if [[ ! "$ans" =~ ^[Yy]$ ]]; then
    echo "Aborted; not posted." >&2
    exit 0
  fi

  PAYLOAD=$(jq -n --arg text "$NOTES" '{"text": $text}')
  HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" -X POST -H 'Content-type: application/json' --data "$PAYLOAD" "$SLACK_RELEASE_NOTES_WEBHOOK_URL")

  if [ "$HTTP_STATUS" = "200" ]; then
    echo "✅ Posted to #tech-system_releases"
  else
    echo "❌ Slack returned HTTP $HTTP_STATUS"
    echo "Notes were:"
    echo "$NOTES"
    exit 1
  fi
else
  echo "$NOTES"
fi
