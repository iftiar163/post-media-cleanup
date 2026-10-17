# WP-CLI with Local

Post Media Cleanup registers the `pmc` WP-CLI command group from the plugin's
main file. The command is available only when WP-CLI is executing and the
`post-media-cleanup` plugin is active. It does not need a separate CLI plugin
or a separate command registration step.

## Open the correct Local shell

1. Start Local and make sure the site is running.
2. Open the site's context menu and choose **Open Site Shell**.
3. In that shell, check that WP-CLI can load the site:

   ```sh
   wp --info
   wp core is-installed
   ```

Local's Site Shell is configured for the selected site's PHP and database. Run
the commands there rather than in a terminal that is not configured for that
Local site. If `wp` is not recognized, reopen **Open Site Shell** for the
running site and check Local's shell/PHP setup.

## Confirm that the plugin command is loaded

The plugin folder and WP-CLI plugin slug are `post-media-cleanup`. Activate it
if needed, then check the command:

```sh
wp plugin is-active post-media-cleanup
wp plugin activate post-media-cleanup
wp help pmc
wp help pmc scan
```

`wp plugin activate` reports that the plugin is already active if it has
already been enabled. `wp help pmc` should list `cleanup`, `logs`, `scan`, and
`status`. If WP-CLI reports an unknown `pmc` command, confirm the site path,
plugin activation, and PHP errors before testing cleanup.

## Read-only checks

These commands do not delete media:

```sh
wp pmc status
wp pmc scan
wp pmc scan --format=count
wp pmc scan --format=json
wp pmc scan --format=csv
wp pmc logs
wp pmc logs --limit=50
wp pmc logs --trigger=wpcli
wp pmc logs --format=json
```

`status` displays the plugin version, relevant settings, and deletion-log
totals. `scan` lists possible orphans; it does not delete anything. A scan
candidate must be an attachment without an existing parent and without a
confirmed media reference. Attachments belonging to a post that still exists
(including one in the Trash) are not candidates.

The `count`, `json`, and `csv` scan formats return data without a progress
message, so they can be piped into other tools. A JSON scan is an array of
objects with `id`, `name`, `type`, `size`, and `date` fields. CSV includes a
header row. If there are no results, these formats return `0`, `[]`, and a
header-only CSV respectively.

## Test cleanup safely on a disposable Local site

Cleanup permanently deletes media files. Use a disposable site or a backup,
never a production site, for this test.

1. Import an expendable image into the Local site's media library. Use an image
   path accessible to the shell:

   ```sh
   wp media import "C:\Users\YourName\Pictures\pmc-cli-test.jpg" --title="PMC CLI test" --porcelain
   ```

   Record the attachment ID printed by `--porcelain`. This import normally
   creates an unattached media item.
2. Check whether that attachment appears in a scan:

   ```sh
   wp pmc scan
   wp pmc scan --format=json
   ```

   It may not appear if the image has a parent or another confirmed reference.
   Do not try to force a candidate by selecting real site media.
3. Preview the deletion list:

   ```sh
   wp pmc cleanup --dry-run
   ```

   Verify the test image is the only item you intend to remove. Dry-run does
   not delete anything and does not require confirmation.
4. On the disposable site only, run the real cleanup and answer the
   confirmation prompt:

   ```sh
   wp pmc cleanup
   ```

   `wp pmc cleanup --yes` skips confirmation; avoid it while learning or
   testing.
5. Confirm the test attachment is gone and inspect the log:

   ```sh
   wp post get ATTACHMENT_ID
   wp pmc logs --trigger=wpcli
   wp pmc status
   ```

   Replace `ATTACHMENT_ID` with the ID from step 1. If deletion logging is
   enabled, the log should include the cleanup entry and `status` should
   include it in the WP-CLI deletion total. Logging can be disabled in the
   plugin settings.

## Command reference

| Command | Behavior |
| --- | --- |
| `wp pmc status` | Show plugin settings and deletion-log statistics. |
| `wp pmc scan [--format=table\|csv\|json\|count]` | Find likely orphaned attachments without deleting them. |
| `wp pmc cleanup --dry-run` | Show what cleanup would delete; do not delete. |
| `wp pmc cleanup` | Rescan, ask for confirmation, and permanently delete validated orphan attachments. |
| `wp pmc cleanup --yes` | Same deletion without the confirmation prompt. |
| `wp pmc logs [--limit=N] [--trigger=TYPE] [--format=table\|csv\|json]` | Display recent deletion log entries. |

The orphan cleanup scanner is a separate, manual action: it does not depend on
the automatic post-deletion **Enabled** setting or its selected post types. It
does re-check each candidate before deletion, and skips any attachment that is
no longer an orphan or is referenced elsewhere. If logging is disabled, the
deletion still occurs but is not written to the plugin's deletion log.
