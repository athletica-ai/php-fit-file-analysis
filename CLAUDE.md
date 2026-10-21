# CLAUDE.md

Athletica's fork of [adriangibbons/php-fit-file-analysis](https://github.com/adriangibbons/php-fit-file-analysis), the PHP library that decodes Garmin FIT activity files. The whole parser is one class, `src/phpFITFileAnalysis.php`.

## How it ships

athletica-app requires this package as `dev-master` from `git@github.com:athletica-ai/php-fit-file-analysis.git`. Merging to `master` changes nothing in production by itself: the change lands when athletica-app runs `composer update adriangibbons/php-fit-file-analysis`, commits the new `composer.lock`, and deploys. The commit athletica-app's `composer.lock` pins is the "currently released" version.

The tags in this repo (`v3.2.4` and earlier) are upstream's. We don't tag our own releases, so don't use `git describe` to name a version.

## Tests

```bash
composer test                      # runs phpunit
vendor/bin/phpunit --filter PowerTest
```

CI (`.github/workflows/tests.yml`) runs on PHP 8.3 (what athletica-app runs) and 8.4.

This repo is public. Never commit real athlete FIT files as fixtures: they carry GPS tracks. Build synthetic files with the helpers in `tests/FitFileBuilder.php` instead.

## FIT profile data

The Garmin FIT SDK is the source of truth for product IDs, manufacturers, sports and other enums. The repo doesn't record which FIT profile version its tables came from, so check the SDK rather than other parsers or forum posts.

## Conventions

- Branches and commit subjects start with the Jira key: `CX-87: honour a developer field's native override for power only`. Keys used: `APP-`, `CX-`, `PAR-`, `SCRUM-`.
- Changes that alter output shape, field names or units affect athletica-app code and stored activities; call them out in the PR and the release notes.

## Release notes

`bin/release-notes-to-slack.sh` generates internal release notes from git commits with the Claude CLI and can post them to `#tech-system_releases`. It mirrors the script of the same name in athletica-app.

```bash
bin/release-notes-to-slack.sh                         # preview: commits since athletica-app's pinned commit
bin/release-notes-to-slack.sh --post                  # post to Slack (asks y/N first)
bin/release-notes-to-slack.sh ba298fa..HEAD           # explicit range
bin/release-notes-to-slack.sh --commits=abc123,def456 # specific commits only
bin/release-notes-to-slack.sh --post --thanks="@harrison for investigation on CX-87"
```

- With no range, it reads the pinned commit from athletica-app's `composer.lock` on `master` (checkout at `../athletica-app`, or set `ATHLETICA_APP_DIR`) and covers `<pinned>..HEAD`. Run it from `master` after merging, so unmerged branch commits aren't included, and post once athletica-app has deployed the update.
- `SLACK_RELEASE_NOTES_WEBHOOK_URL` (needed for `--post`) and `SLACK_USER_ID` (optional, tags you as releaser) come from the environment, this repo's `.env` (gitignored), or athletica-app's `.env`.
- Requires `claude` and `jq` on `PATH`.
