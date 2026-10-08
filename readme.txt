=== Post Media Cleanup ===
Contributors:      iftiarhossain
Tags:              media, cleanup, delete, attachments, images
Requires at least: 5.0
Tested up to:      7.0
Requires PHP:      7.4
Stable tag:        2.3.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Automatically deletes all associated media files when a post is permanently deleted.

== Description ==

Post Media Cleanup removes orphaned media files from your server when you permanently delete a post.

When you permanently delete a post, this plugin automatically finds and removes:

* Featured image
* Images and files embedded in post content
* PDFs and any linked files in content
* All attachments uploaded directly to the post

= Key Features =

* Only fires on permanent deletion — moving to trash is always safe
* Skip Shared Media — never deletes a file used by another post
* Works per post type — configure exactly which types trigger cleanup
* Compatible with S3 and cloud storage
* Multisite ready
* Developer friendly — filter hooks to extend behaviour

= For Developers =

Add extra attachments to the deletion list:

`
add_filter( 'postmediaweb_attachment_ids_to_delete', function( $ids, $post_id ) {
    $extra = get_post_meta( $post_id, 'my_custom_pdf', true );
    if ( $extra ) $ids[] = (int) $extra;
    return $ids;
}, 10, 2 );
`

Prevent a specific attachment from being deleted:

`
add_filter( 'postmediaweb_should_delete_attachment', function( $should, $att_id, $post_id ) {
    if ( $att_id === 999 ) return false;
    return $should;
}, 10, 3 );
`

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/
2. Activate through the Plugins screen
3. Go to Settings → Post Media Cleanup to configure

== Frequently Asked Questions ==

= Does it delete media when I move a post to trash? =

No. Only permanent deletion triggers cleanup. Trash is always safe.

= What if the same image is used in two posts? =

With Skip Shared Media enabled (default), the plugin checks before deleting. If the file is used elsewhere it is preserved.

= Does it work with WooCommerce products? =

Yes. Enable Products in the Post Types setting.

= Does it work with S3 or cloud storage? =

Yes. It uses wp_delete_attachment() which cloud storage plugins hook into automatically.

== Screenshots ==

1. Settings page under Settings → Post Media Cleanup

== Changelog ==

= 2.3.0 =
* Added WooCommerce product gallery image collection on product delete
* Added WooCommerce product variation image deletion
* Added short description media scanning for all post types
* Added dedicated WooCommerce settings section (visible only when WooCommerce is active)
* Improved post type selector UI with Built-in vs Custom grouping
* Added post type descriptions for known third party types
* Added warning when no post types are selected

= 2.2.0 =
* Fixed: an image shared across multiple WooCommerce product galleries (`_product_image_gallery`) was deleted when only one of those products was removed, even though other products still used it.
* Fixed: an image referenced only inside Elementor content/templates (`_elementor_data`) was deleted once its original post was removed, even though Elementor still referenced it elsewhere.
* Fixed: "Skip Shared Media" only ever checked the featured-image meta and a literal URL match in post content — it now also checks WooCommerce product galleries, Elementor data, ACF image/file/gallery fields (including repeater and flexible-content sub-fields), term meta (e.g. category thumbnails), Gutenberg image/gallery blocks, `[gallery ids="..."]` and WPBakery shortcodes, and known media-holding site options (custom logo, site icon).
* Fixed: the Bulk Orphan Cleanup scanner used the same narrow "featured image + literal URL" check and could flag genuinely-used media as orphaned for all the reasons above.
* Changed: media-reference detection is conservative by design — a bare numeric value that happens to match an attachment ID (e.g. a generic custom field) is never treated as a confirmed reference on its own; only meta keys/structures positively identified as media storage (WooCommerce, Elementor, ACF field definitions, etc.) count.
* Added: the Bulk Orphan Cleanup deletion step now re-checks each attachment immediately before deleting it, instead of trusting the scan result alone — anything found to have become referenced since the scan (e.g. the site changed while a large scan was running) is skipped rather than deleted, and the admin UI now reports how many were skipped for this reason.
* Added: media-reference detection is now shared between the real-time "Skip Shared Media" check and the Bulk Orphan Cleanup scanner (`Postmediaweb_Reference_Checker`), so the two can no longer disagree with each other.

= 2.1.0 =
* Fixed: deleting a post with a populated ACF gallery field could crash with a fatal error on PHP 8+ (missing return value in the gallery field handler).
* Fixed: the Settings tab could not save changes due to a settings-group name mismatch.
* Fixed: the Bulk Orphan Cleanup tab's Scan/Delete buttons had no JavaScript attached and did nothing, because jQuery was never enqueued on that page.
* Fixed: reactivating the plugin silently reset your saved settings back to defaults.
* Fixed: the orphan scanner could exhaust available memory on large sites by loading all post content and all attachment records into memory at once.
* Fixed: the orphan scanner's content-matching was far slower than intended at scale; it now uses a single indexed pass instead of a full-text scan per attachment.
* Fixed: attachments whose parent post no longer exists were permanently hidden from orphan scans instead of being correctly flagged.
* Fixed: a PHP notice could be triggered by certain Elementor widget settings during media detection.
* Improved: fewer database queries when scanning ACF fields on each deleted post.

= 2.0.0 =
* Added ACF Free and Pro support
* Added Bulk Orphan Cleanup tool with progress indicator
* Added tabbed settings interface

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release