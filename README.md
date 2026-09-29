# Bible WordPress plugin

Source for the Bible verse reference popup plugin.

## WordPress updates

The plugin checks the latest published GitHub Release and offers it under **Dashboard → Updates** and **Plugins**. Each release must include the generated ZIP asset named bible.zip; the included GitHub Actions workflow creates it when a version tag such as v1.1.2 is pushed.

This repository is private, so the WordPress site needs a fine-grained GitHub personal access token with access to this repository and **Contents: Read-only** permission. Keep the token out of plugin source and WordPress database settings. Define it in wp-config.php before the stop-editing line:

<pre><code>define( 'BIBLE_GITHUB_TOKEN', 'github_pat_your_read_only_token' );</code></pre>

The updater only sends the token to api.github.com. GitHub's temporary release-asset redirect is downloaded without the token. If the repository is made public, the token is no longer needed.

## Publishing a release

1. Update the Version header and BIBLE_PLUGIN_VERSION in bible.php, and the Stable tag in readme.txt, to the same version.
2. Commit and push the source to main.
3. Push a matching tag, for example v1.2.0. The workflow checks the version, packages the plugin with its bible/ folder, and publishes the ZIP as bible.zip.
4. After the workflow finishes, use Dashboard → Updates → Check again on the WordPress site and apply the update.

Create a repository-only fine-grained token with Contents: Read-only. The plugin updater does not need write access.
