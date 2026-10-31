# Removed Post Manager

Retire WordPress content without deleting it. A removed post keeps its data and can be restored, while its URLs answer **410 Gone**, telling search engines the content is gone for good rather than missing by mistake.

Built for editorial sites pruning old or unwanted content after an audit.

## Features

- A **Rimosso** post status, with row and bulk actions in every public post type list. The previous status, date and author are recorded; restoring returns the post to its previous status.
- 410 for every URL the post had: its permalink at removal, extra URLs from an import, and old permalinks saved by Slim SEO.
- Manual 410s for URLs that no longer have a post, for example articles deleted before the plugin was installed. Deleting removed content for good keeps its 410s as manual entries.
- One table holds every 410 path with a unique index, so each 404 costs a single indexed lookup.
- Bulk import by URL or post ID from **Strumenti → Contenuti rimossi** or WP-CLI, with a dry run. URLs without content are reported, not guessed.
- A searchable list of all 410s with CSV export.

## How the 410 is served

| Request | Response |
| --- | --- |
| URL of removed content | 410 |
| Old slug of removed content | 410, instead of core's redirect to `/?p=ID` |
| Manual 410 | 410 |
| Same URL with an explicit redirect in an SEO or redirect plugin | the redirect |

Removed posts are not public, so their URLs are already 404s: the check runs only on 404 requests. It hooks `template_redirect` at priority 9, after manual redirects (Slim SEO uses priority 1) and before automatic "old URL" redirects, which would send removed content to its plain `/?p=ID` permalink.

The response keeps WordPress's 404 handling with a 410 status, so classic and block themes show their own 404 template. To customise it, add a `410.php` template to the theme. It can read the removed post ID with `get_query_var( 'rpm_removed_post' )` (0 for manual 410s). The body class is `error410`.

## Overlap with SEO plugins

Redirect managers can also answer 410 for a URL: Slim SEO's does, for example. That covers the manual 410 list. What they don't provide is a post status that keeps removed content restorable and serves 410 for all its URLs. Use whichever you prefer for URL-only rules; an explicit redirect always wins over this plugin's 410.

## Setup

Requires WordPress 6.8+ and PHP 8.3+.

1. Copy `wp-removed-post-manager` into `wp-content/plugins` and activate it.
2. Remove content from the post list, or import a list in **Strumenti → Contenuti rimossi**.

Removed content is managed by users who can edit it. Manual 410s apply to the whole site and require `edit_others_posts`.

## WP-CLI

```sh
wp removed-posts import removed.csv --user=1 --dry-run
wp removed-posts import removed.csv --user=1 --missing-file=missing.txt
wp removed-posts restore --post-type=any --user=1
```

The CSV's first column holds URLs or post IDs; a `url` header is optional.

## Hooks

| Filter | Purpose |
| --- | --- |
| `removed_post_manager_post_types` | Post types that can be removed (default: public types with UI, except attachments). |
| `removed_post_manager_removed_paths` | URLs recorded as 410 when a post is removed. |

## Uninstalling

Restore removed content first (`wp removed-posts restore --post-type=any`): without the plugin, WordPress no longer recognises the status. Data stays in the database: post meta prefixed `_rpm_`, the `rpm_gone` table of 410 paths and the `rpm_db_version` option.
