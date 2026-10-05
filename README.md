# Bible WordPress plugin

Source for the Bible verse reference popup plugin.

## WordPress updates

The plugin checks the latest published GitHub Release and offers it under **Dashboard → Updates** and **Plugins**. Each release must include the generated ZIP asset named bible.zip; the included GitHub Actions workflow creates it when a version tag such as v1.1.2 is pushed.

This repository is public, so no token is needed. A token is optional: a fine-grained GitHub personal access token with access to this repository and **Contents: Read-only** permission raises the GitHub API rate limit, and is required again only if the repository becomes private. Keep it out of plugin source and WordPress database settings. Define it in wp-config.php before the stop-editing line:

<pre><code>define( 'BIBLE_GITHUB_TOKEN', 'github_pat_your_read_only_token' );</code></pre>

The updater only sends the token to api.github.com. GitHub's temporary release-asset redirect is downloaded without the token.

If you still have version 1.1.1, manually install the latest release asset **bible.zip** once through Plugins → Add New → Upload Plugin and replace the existing plugin. Version 1.1.1 has no updater. After that, configured sites can use native WordPress updates. Allow up to 10 minutes for cached release information to refresh.

## Import limits

Module uploads must be SQLite3 databases (maximum 100 MiB), containing ordinary books and verses tables. Imports support at most 200 books, 100,000 verses and 16 KiB per verse. Both WordPress Bible tables must use InnoDB so failed imports can roll back. Uploaded modules are read from PHP temporary storage and are not published in the plugin directory. JSON settings imports are limited to 1 MiB and 2,000 aliases.

## Publishing a release

1. Update the Version header and BIBLE_PLUGIN_VERSION in bible.php, and the Stable tag in readme.txt, to the same version.
2. Commit and push the source to main.
3. Push a matching tag, for example v1.2.0. The workflow checks the version, packages the plugin with its bible/ folder, and publishes the ZIP as bible.zip.
4. After the workflow finishes, use Dashboard → Updates → Check again on the WordPress site and apply the update.

Create a repository-only fine-grained token with Contents: Read-only. The plugin updater does not need write access.
