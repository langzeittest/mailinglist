<?php

// Make sure that this script is loaded from the admin interface.
if (!defined('PHORUM_ADMIN')) return;

// Save settings in case this script is run after posting
// the settings form.
if (    count($_POST)
     && isset($_POST['forum_destinations']) ) {
    // Create the settings array for this module.
    $PHORUM['mod_mailinglist'] = array
        ( 'strip_body' => $_POST['strip_body'] ? 1 : 0,
          'allow_attachments' => $_POST['allow_attachments'] ? 1 : 0,
          'individual_from' => $_POST['individual_from'] ? 1 : 0,
          'forum_destinations' => $_POST['forum_destinations'],
          'forum_from_addresses' => $_POST['forum_from_addresses'] );

    // Force the options to be integer values.
    settype($PHORUM['mod_mailinglist']['strip_body'], 'int');
    settype($PHORUM['mod_mailinglist']['allow_attachments'], 'int');
    settype($PHORUM['mod_mailinglist']['individual_from'], 'int');

    if (!phorum_db_update_settings(array('mod_mailinglist'=>$PHORUM['mod_mailinglist']))) {
        $error = 'Database error while updating settings.';
    } else {
        phorum_admin_okmsg('Settings Updated');
    }
}

// Apply default values for the settings.
if (!isset($PHORUM['mod_mailinglist']['strip_body'])) {
    $PHORUM['mod_mailinglist']['strip_body'] = 1;
}

if (!isset($PHORUM['mod_mailinglist']['allow_attachments'])) {
    $PHORUM['mod_mailinglist']['allow_attachments'] = 0;
}

if (!isset($PHORUM['mod_mailinglist']['individual_from'])) {
    $PHORUM['mod_mailinglist']['individual_from'] = 0;
}

// We build the settings form by using the PhorumInputForm object.
include_once './include/admin/PhorumInputForm.php';
$frm = new PhorumInputForm('', 'post', 'Save settings');
$frm->hidden('module', 'modsettings');
$frm->hidden('mod', 'mailinglist');

// Here we display an error in case one was set by saving
// the settings before.
if (!empty($error)){
    phorum_admin_error($error);
}

$frm->addbreak('Edit Settings for the Mailing List Module');
// Strip body?
$row = $frm->addrow('Use system settings for stripping HTML and BBcode [tags]? ', $frm->checkbox('strip_body', '1', '', $PHORUM['mod_mailinglist']['strip_body']));
$frm->addhelp($row, 'Strip body', 'If this option is marked all HTML <tags> and BBcode [tags] are stripped from the body depending on the &quot;General Settings&quot;.');
// Allow Attachments?
$row = $frm->addrow('Send post attachments (requires Send Mail Through SMTP Module)? ', $frm->checkbox('allow_attachments', '1', '', $PHORUM['mod_mailinglist']['allow_attachments']));
$frm->addhelp($row, 'Send attachments', 'If this option is marked attachments are send to the mailing list. This requires the Send Mail Through SMTP Module to be enabled. Take care that your mailing list accepts attachments.');
// Individual FROM addresses?
$row = $frm->addrow('Use individual FROM addresses: ', $frm->checkbox('individual_from', '1', '', $PHORUM['mod_mailinglist']['individual_from']));
$frm->addhelp($row, 'Individual FROM addresses', 'If this option is marked you can configure for each forum the FROM address as sent to the mailing list. If you don&#x2019;t set a FROM address for a forum, then the system email FROM address is used.<br />When changing this option you have to save the settings to reflect change in the settings form.');
// Mailing list destinations and email FROM addresses
if ($PHORUM['mod_mailinglist']['individual_from']) {
    $row = $frm->addbreak('Define mailing list destinations and email FROM addresses');
    $frm->addhelp($row, 'Define mailing list destinations and email FROM addresses', 'Insert one or more valid destination email addresses (your mailing lists). Separate several addresses by comma. Insert a single FROM address, or leave blank to use the system email FROM address.');
} else {
    $row = $frm->addbreak('Define mailing list destinations');
    $frm->addhelp($row, 'Define mailing list destinations', 'Insert one or more valid destination email addresses (your mailing lists). Separate several addresses by comma.');
}

$tree = phorum_mod_mailinglist_getforumtree();
foreach ($tree as $data) {
    $level = $data[0];
    $node = $data[1];
    $name = str_repeat('&nbsp;&nbsp;', $level);
    $name .= '<img border="0" src="'.$PHORUM['http_path'].'/mods/mailinglist/images/'
               .($node['folder_flag'] ? 'folder.gif' : 'forum.gif').'" /> ';
    $name .= $node['name'];

    if ($node['folder_flag']) {
        // No settings for folders.
        $frm->addrow($name);
    } else {
        // Settings for forums.
        if (isset($PHORUM['mod_mailinglist']['forum_destinations'][$node['forum_id']])) {
            $destination = $PHORUM['mod_mailinglist']['forum_destinations'][$node['forum_id']];
        } else {
            $destination = '';
        }
        if (isset($PHORUM['mod_mailinglist']['forum_from_addresses'][$node['forum_id']])) {
            $from_address = $PHORUM['mod_mailinglist']['forum_from_addresses'][$node['forum_id']];
        } else {
            $from_address = '';
        }
        if ($PHORUM['mod_mailinglist']['individual_from']) {
            $frm->addrow($name);
            $frm->addrow
                ( str_repeat('&nbsp;&nbsp;', $level + 1).'Destination address(es)',
                  $frm->text_box
                      ( 'forum_destinations['.$node['forum_id'].']',
                        $destination,
                        40 ) );
            $frm->addrow
                ( str_repeat('&nbsp;&nbsp;', $level + 1).'FROM address',
                  $frm->text_box
                      ( 'forum_from_addresses['.$node['forum_id'].']',
                        $from_address,
                        40 ) );
        } else {
            $frm->addrow
                ( $name,
                  $frm->text_box
                      ( 'forum_destinations['.$node['forum_id'].']',
                        $destination,
                        40 ) );
            $frm->hidden('forum_from_addresses['.$node['forum_id'].']', $from_address);
        }
    }
}
// Show settings form
$frm->show();

//
// Internal functions
//

function phorum_mod_mailinglist_getforumtree() {
    // Retrieve all forums and create a list of all parents
    // with their child nodes.
    $forums = phorum_db_get_forums();
    $nodes = array();
    foreach ($forums as $id => $data) {
        $nodes[$data['parent_id']][$id] = $data;
    }

    // Create the full tree of forums and folders.
    $treelist = array();
    phorum_mod_mailinglist_mktree(0, $nodes, 0, $treelist);
    return $treelist;
}

// Recursive function for building the forum tree.
function phorum_mod_mailinglist_mktree($level, $nodes, $node_id, &$treelist) {
    // Should not happen but prevent warning messages, just in case...
    if (!isset($nodes[$node_id])) return;

    foreach ($nodes[$node_id] as $id => $node) {

        // Add the node to the treelist.
        $treelist[] = array($level, $node);

        // Recurse folders.
        if ($node['folder_flag']) {
            $level++;
            phorum_mod_mailinglist_mktree($level, $nodes, $id, $treelist);
            $level--;
        }
    }
}

?>
