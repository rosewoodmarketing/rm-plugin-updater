# RM GitHub Updater — Quick Start

This plugin auto-updates from the GitHub repo `Jared-Nolt/rm-github-plugin`.

## Ship a new version
1) Open `rm-github-plugin.php`, bump the `Version:` header and the `RM_GITHUB_PLUGIN_VERSION` constant.
2) Commit and push to GitHub.
3) Create a GitHub release with tag `vX.Y.Z` matching that version.
4) In WordPress, go to Dashboard → Updates to see the new version.
5) In updater/updater.php remove sections wrapped with // --- Optional Manual & // --- Manual on live site to remove the button that checks for updates.

## Optional: GitHub token (private repo or higher rate limits)
Add in `wp-config.php` (above “That’s all, stop editing!”):
```php
define( 'RM_GITHUB_PLUGIN_TOKEN', 'paste-your-token-here' );
```

## Optional: disable SSL checks in local/dev
Add in `wp-config.php` (not recommended for production):
```php
define( 'RM_GITHUB_PLUGIN_DEV_MODE', true );
```

## Force an immediate check
On the Plugins page, click “Check for updates” under RM GitHub Plugin to clear the cache and re-fetch.

## Common issues
- Not seeing the update? Wait for WP cache (~12h) or use the “Check for updates” link.
- 404 on download? Ensure the repo is public or set `RM_GITHUB_PLUGIN_TOKEN` for private.
- Tag mismatch? Release tag (e.g., `v2.1.1`) must match the plugin version header.