=== MRN Podcaster ===
Contributors: mehran-mrn
Tags: podcast, rss, audio player, podcast import, media
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A professional podcast feed synchronizer, editable episode archive, listener comment importer and persistent audio player.

== Description ==

MRN Podcaster uses a primary podcast feed as the canonical source and can enrich
episodes from a backup feed. Episodes become editable WordPress content without
losing feed metadata. The responsive bottom player supports source switching,
speed, seek, volume, minimized mode, automatic fallback and Media Session
background controls.

External comments are deduplicated and imported as pending comments for a site
administrator to approve.

== Installation ==

1. Upload the `mrn-podcaster` folder to `/wp-content/plugins/`.
2. Activate MRN Podcaster.
3. Open Podcaster > Feed and synchronization.
4. Save a primary feed and run the first synchronization.

== Frequently Asked Questions ==

= Will synchronization overwrite edited episode copy? =

No. The plugin tracks the last imported title/body/excerpt. Once an editor
changes those fields, later synchronizations preserve the local copy.

= Is the backup feed required? =

No. It is used only as a second audio/comment source and never creates episodes.

= Are imported comments published automatically? =

No. They are pending until approved by a moderator.

= Can audio be stored locally? =

Yes. Enable the local audio archive setting. It is disabled by default because
podcast media can consume substantial disk space.

== Changelog ==

= 0.1.2 =
* Add a public artwork filter for theme-native episode image fallbacks.

= 0.1.1 =
* Preserve the first-click browser gesture while loading audio metadata.

= 0.1.0 =
* Initial professional foundation.
* Primary and backup feed synchronization.
* Editable episode content model and media sideloading.
* Listener comment deduplication and moderation flow.
* Persistent multi-source player and frontend shortcodes.
* Branded operational dashboard.
