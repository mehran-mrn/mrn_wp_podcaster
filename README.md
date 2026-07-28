# MRN Podcaster

MRN Podcaster is an independent WordPress podcast publishing engine. It turns
a primary podcast feed into an editable local catalogue, enriches each episode
from an optional backup feed, imports external listener comments for moderation
and provides a persistent, theme-independent audio player.

## Current feature set

- Primary RSS 2.0 or Atom feed as the canonical episode source.
- Optional backup feed matched by external identity, season/episode number,
  then normalized title.
- Idempotent `mrnp_episode` posts with title, body, excerpt, image, duration,
  season, episode number, likes and all audio sources.
- Editor-safe synchronization: title/body/excerpt stop being source-owned after
  an editor changes them. Existing featured images are never replaced.
- Optional local audio archive in the WordPress Media Library.
- WP-Cron synchronization every 15 minutes, hourly, twice daily or daily, with
  an overlap lock and persistent operational logs.
- Pending-by-default episode and show-level comment import with global hash
  deduplication. Standard RSS comment feeds, public Schema.org Review/Comment
  data and public Castbox channel comments are discovered without publishing
  automatically.
- A bottom player with speed, seek, volume, source switching, automatic
  fallback, progress memory, an in-page minimized timer, loading feedback and
  Media Session API controls.
- Shortcodes for episode carousels, approved listener comments and play buttons.
- A protected Persian administration dashboard and explicit uninstall policy.

## Installation

1. Copy this directory to `wp-content/plugins/mrn-podcaster`.
2. Activate **MRN Podcaster**.
3. Open **پادکستر → فید و همگام‌سازی**.
4. Save the primary feed, optionally add a backup feed and platform pages.
5. Run the first manual synchronization from the dashboard.

PHP 8.1+ and WordPress 6.6+ are required.

## Shortcodes

```text
[mrnp_episode_carousel count="8" heading="آخرین اپیزودها"]
[mrnp_listener_comments count="8" heading="از شنوندگان"]
[mrnp_player id="123"]
```

All output is theme-independent and uses stable `mrnp-` classes. A theme can
override the design through CSS custom properties or wrap the shortcodes in its
own layout.

## Comment provider contract

RSS inline comments, standard RSS comment-feed links, Schema.org reviews and
Castbox's publicly rendered channel comments are built in. Podcast platforms
differ widely and many do not offer a public comments API. Provider adapters
can safely add results without changing core synchronization:

```php
add_filter(
	'mrnp_external_comments',
	function ( array $comments, array $episode, array $platforms ): array {
		// Query an authenticated platform API and append normalized comments.
		return $comments;
	},
	10,
	3
);
```

Each appended item may contain `id`, `author`, `author_url`, `text`, `date` and
`source`. `source + id` is hashed globally, and imported comments remain pending
until an administrator approves them.

## Synchronization guarantees

The primary feed is always canonical. A backup episode can contribute audio and
comments, but can never create a WordPress episode or change primary identity.
Network calls use WordPress safe HTTP requests, bounded response sizes, short
timeouts and redirects. Concurrent cron/manual runs are rejected.

The optional local audio archive can consume significant storage and bandwidth.
The setting is off by default.

## Development

```powershell
.\tools\verify.ps1
.\tools\package.ps1
```

The verification script lints every PHP file, validates JavaScript syntax and
runs the standalone parser/normalizer tests.

## License

GPL-2.0-or-later.
