<?php

$labels = [];

$labels['title'] = 'Sieve Replay';
$labels['intro'] = 'Re-run your active Sieve filters (Settings → Filters) against messages already sitting in a folder, instead of only mail that arrives from now on.';
$labels['introhint'] = 'Preview never changes anything, whatever the setting below is. Apply is not a dry run: it genuinely moves messages your filters would move, immediately. The setting below only changes what happens to a message a rule discards -- it has no effect on messages a rule moves.';
$labels['folder'] = 'Folder';
$labels['discardpolicy'] = 'When you Apply, if a rule discards a message';
$labels['discard_trash'] = 'Move it to Trash (default)';
$labels['discard_expunge'] = 'Delete it permanently';
$labels['preview'] = 'Preview (dry run)';
$labels['apply'] = 'Apply';
$labels['confirmapply'] = 'This will move/modify messages in the selected folder according to your active Sieve filters. Continue?';
$labels['errorfolder'] = 'That folder is not in your own mailbox.';
$labels['errorpermission'] = 'You are not permitted to use this plugin.';
$labels['errornohelper'] = 'The sievereplay_helper option is not configured -- see config.inc.php.dist.';
$labels['resultempty'] = 'No messages found in this folder.';
$labels['resultsummarypreview'] = 'Checked $total message(s): $moved would move, $discarded would be discarded.';
$labels['resultsummaryapply'] = 'Checked $total message(s): $moved moved, $discarded discarded.';
$labels['resultwouldmove'] = 'would move to $folder';
$labels['resultwoulddiscard'] = 'would be discarded';
$labels['resultmore'] = '...and $count more not shown.';
$labels['resulterror'] = 'Something went wrong:';
$labels['showdetails'] = 'Show full output';
$labels['checking'] = 'Checking…';
$labels['applying'] = 'Applying…';
