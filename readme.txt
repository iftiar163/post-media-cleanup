=== Post Media Cleanup ===
Contributors:      iftiarhossain
Tags:              media, cleanup, delete, attachments, images
Requires at least: 5.0
Tested up to:      7.0
Requires PHP:      7.4
Stable tag:        2.1.0
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