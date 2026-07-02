=== Post Media Cleanup Webxperthub ===
Contributors:      iftiarhossain
Tags:              media, cleanup, delete, attachments, images
Requires at least: 5.0
Tested up to:      7.0
Requires PHP:      7.4
Stable tag:        1.0.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Automatically deletes all associated media files when a post is permanently deleted.

== Description ==

Post Media Cleanup Webxperthub removes orphaned media files from your server when you permanently delete a post.

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
3. Go to Settings → Post Media Cleanup Webxperthub to configure

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

1. Settings page under Settings → Post Media Cleanup Webxperthub

== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release