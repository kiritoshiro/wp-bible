# Security audit — 1.1.3

Date: 2026-09-29. Baseline: 1d55b403f0f25552137de484ef8f6d6f7d7c2002 (v1.1.2).
Scope: PHP entry points, database imports and reads, browser rendering, GitHub updater and release workflow.

## Confirmed issues and fixes

| Finding | Preconditions / impact | Fix |
| --- | --- | --- |
| Stored XSS in verse HTML | An administrator imports a malicious SQLite module; event handlers on allowed tags reach visitors' popups. | Use WordPress KSES with formatting tags and no attributes, including for already imported verses. Render AJAX error messages as text. |
| Missing admin action authorization | Mutation handlers checked nonces but not capabilities. Nonces are required and this was not an unauthenticated write bypass. | Require manage_options and POST, in addition to each action's nonce. |
| Destructive import before validation | Invalid modules erased live tables; failed writes could leave partial data. | Validate input first, check InnoDB, use transactional DELETE and checked writes with rollback. Refuse unsupported engines. |
| Unsafe upload persistence | Admin uploads could overwrite bundled modules and expose arbitrary uploaded contents under the plugin URL. | Validate upload status, PHP upload provenance, extension and size; import from the temporary file without publishing it. |
| Unvalidated JSON settings / aliases | Malformed admin imports could break settings or generate excessive frontend data. | Shape validation, settings allowlist, width limits, alias count/length limits and deduplication. |
| Unbounded public verse queries | Wide ranges loaded all rows before truncation in PHP. | Bound SQL results to 81 rows; reject malformed scalar inputs. |
| Update transport hardening | API redirects could forward authorization; full download bodies were held in memory and size checked afterwards. | Disable automatic redirects; exact HTTPS CDN host allowlist; never forward token; stream downloads with response limits; validate size and GitHub SHA-256 digest when supplied; clean failed temporary files. |
| Activation overwrote custom aliases | Reactivating replaced user settings with defaults. | Initialize defaults only when missing. |

Release workflow now pins checkout, omits persisted checkout credentials, runs regression checks before publication, and verifies all three version declarations. ZIP packaging retains the original bible/ plugin directory.

## Verification

- 38 regression assertions passed locally using real WordPress 7.1.2, PHP 8.3.30 and MySQL 8.4.3.
- Imported the bundled 31,165 verses; verified rollback after an injected MySQL write failure and rejection of nontransactional tables.
- Exercised real WordPress KSES, permission gates (including a subscriber with a valid nonce), malformed AJAX and JSON input, settings limits, and updater hooks.
- HTTP transport was mocked to check authorization isolation, redirect rejection, download truncation, checksum rejection and temporary-file cleanup.
- Installed the published 1.1.2 ZIP in disposable WordPress and used the real Plugin_Upgrader to replace it with a real 1.1.3 ZIP, with mocked GitHub transport.
- PHP lint, JavaScript syntax and git whitespace checks passed.
- Reproduce automated tests only against a disposable WordPress database named bible_audit: wp eval-file tests/security.php.

## Limits and operational notes

This audit is not a guarantee that all vulnerabilities are absent. Production WordPress, real fine-grained-token permissions, hosting differences, browser interaction and older PHP/WordPress versions were not exercised. No production site was changed. Request flooding still needs hosting-level controls. Updating trusted PHP from GitHub assumes repository/release maintainers and credentials remain trusted; a checksum is an integrity check, not an independent signature.

Imports require InnoDB Bible tables, an ordinary SQLite schema, at most 100 MiB, 200 books, 100,000 verses and 16 KiB per verse. Existing custom module files left in the public plugin directory by older versions are not automatically deleted; remove obsolete uploaded files after preserving any needed backup. The bundled LTRK.SQLite3 is intentionally packaged and public.

Version 1.1.1 has no updater: manually upload the latest bible.zip once. Version 1.1.2 can install this release through its configured updater. The updater currently requires BIBLE_GITHUB_TOKEN, even if the repository is later made public. If another release becomes latest between offering an update and downloading it, refresh the update check after the 10-minute cache expires.

## Reference guidance

- WordPress: https://developer.wordpress.org/apis/security/nonces/ — nonces do not replace authorization.
- PHP: https://www.php.net/strip-tags — allowed tags retain attributes and strip_tags is not an XSS defense.
