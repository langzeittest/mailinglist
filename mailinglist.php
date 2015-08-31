<?php

if (!defined('PHORUM')) return;

//
// Send email to mailing lists (for moderated forums after approve).
//
function mod_mailinglist_after_approve($data) {
    $data[0] = mod_mailinglist_send($data[0]);
    return $data;
}

//
// Send email to mailing lists (for unmoderated forums immediately).
//
function mod_mailinglist_after_post($message) {
    // Message approved?
    if ($message['status'] > 0) {
        $message = mod_mailinglist_send($message);
    }
    return $message;
}

//
// Send email to mailing lists (for unmoderated forums immediately).
//
function mod_mailinglist_send($message) {
    global $PHORUM;

    if (    is_array($PHORUM['mod_mailinglist']['forum_destinations'])
         && isset($PHORUM['mod_mailinglist']['forum_destinations'][$message['forum_id']])
         && $PHORUM['mod_mailinglist']['forum_destinations'][$message['forum_id']] ) {

        include_once("./include/format_functions.php");

        // Add In-reply-to and references headers
        $headers = array();

        // Try to find a useful hostname to use in the Message-ID/In-Reply-To/References
        // taken from email_functions.php -> phorum_email_user for using the same algorithm
        $host = "";
        if (isset($_SERVER["HTTP_HOST"])) {
            $host = $_SERVER["HTTP_HOST"];
        } else if (function_exists("posix_uname")) {
            $sysinfo = @posix_uname();
            if (!empty($sysinfo["nodename"])) {
                $host .= $sysinfo["nodename"];
            }
            if (!empty($sysinfo["domainname"])) {
                $host .= $sysinfo["domainname"];
            }
        } else if (function_exists("php_uname")) {
            $host = @php_uname("n");
        } else if (($envhost = getenv("HOSTNAME")) !== false) {
            $host = $envhost;
        }
        if (empty($host)) {
            $host = "webserver";
        }

        // If its a reply ...
        if ($message['parent_id'] > 0) {
            // Get the previous message
            $previous_message = phorum_db_get_message($message['parent_id']);

            // Compose an RFC compatible Message-ID header.
            if (isset($previous_message["msgid"])) {
                if (strpos($previous_message['msgid'],"@") === false) {
                    // Probably posted through the forum with a raw msgid without host
                    $reply_messageid = "<{$previous_message['msgid']}@$host>";
                } else {
                    // Probably posted by mail and with a good msgid already
                    $reply_messageid = "<{$previous_message['msgid']}>";
                }
            }

            // Finally in-reply-to is ready ...
            $headers[]="In-Reply-To: $reply_messageid";

            // Now lets do the references header
            // Ee take the references from the message we reply to and add their
            // message-id (the same as in-reply-to) to the references list

            if (isset($previous_message["meta"]["phorummail"]["references"])) {
                // we add our own message-id to the references
                $references = $previous_message["meta"]["phorummail"]["references"]." ".$reply_messageid;
            } else {
                $references = $reply_messageid;
            }

            $headers[]="References: ".$references;

            // Store the updated data in the database.
            $new_message = array("meta" => $message["meta"]);
            $new_message["meta"]["phorummail"]["references"] = $references ;
            phorum_db_update_message($message['message_id'], $new_message);

        }

        $mail_data = array(
            // Template variables.
            'forumname'  => strip_tags($PHORUM['DATA']['NAME']),
            'forum_id'   => $PHORUM['forum_id'],
            'message_id' => $message['message_id'],
            'author'     => phorum_api_user_get_display_name($message['user_id'], $message['author'], PHORUM_FLAG_PLAINTEXT),
            'subject'    => $message['subject'],
            'full_body'  => $message['body'],
            'plain_body' => $PHORUM['mod_mailinglist']['strip_body']==1 ? phorum_strip_body($message['body'], true, $PHORUM['strip_quote_mail']) : $message['body'],
            'read_url'   => phorum_get_url(PHORUM_READ_URL, $message['thread'], $message['message_id']),
            'msgid'      => $message['msgid'],

            // For email_user_start.
            'mailmessagetpl' => 'MailinglistMessage',
            'mailsubjecttpl' => 'MailinglistSubject'
        );

        // Process attachments (only if Send Mail Through SMTP Module is enabled)
        if (    isset($PHORUM['mod_mailinglist']['allow_attachments'])
             && $PHORUM['mod_mailinglist']['allow_attachments'] == 1
             && isset($PHORUM['hooks']['send_mail'])
             && is_array($PHORUM['hooks']['send_mail']['mods'])
             && in_array('smtp_mail', $PHORUM['hooks']['send_mail']['mods']) ) {
            include_once('./include/api/base.php');
            include_once('./include/api/file_storage.php');
            if (isset($message['meta']['attachments'])) {
                $attachments = array();
                $debug_message = '';
                foreach ($message['meta']['attachments'] as $attachment) {
                    $dbfile = phorum_db_file_get($attachment['file_id'], TRUE);
                    $mime_type = phorum_api_file_get_mimetype($attachment['name']);
                    $filedata = base64_decode($dbfile['file_data']);
                    $attachments[$attachment['name']] = array(
                        'filename' => $attachment['name'],
                        'mimetype' => $mime_type,
                        'filedata' => $filedata
                    );
                    $debug_message .= htmlspecialchars($attachment['name'], ENT_QUOTES)."\n";
                }

                $mail_data['attachments'] = $attachments;

                // Debugging
                if (function_exists('event_logging_writelog')) {
                    event_logging_writelog(array(
                        'source'   => 'mailing_list',
                        'message'  => 'Attachments processed',
                        'details'  => "Processed the following attachments:\n".$debug_message,
                        'loglevel' => EVENTLOG_LVL_INFO,
                        'category' => EVENTLOG_CAT_MODULE )
                    );
                }
            }
        }

        // Add our own headers if there are any
        if (count($headers)) {
            $mail_data['custom_headers'] = implode("\n",$headers);
        }

        if (isset($_POST[PHORUM_SESSION_LONG_TERM])) {
            // Strip any auth info from the read url
            $mail_data['read_url']
                = preg_replace
                      ( '!,{0,1}'.PHORUM_SESSION_LONG_TERM.'='
                            .urlencode($_POST[PHORUM_SESSION_LONG_TERM]).'!',
                        '',
                        $mail_data['read_url'] );
        }

        $mail_data['mailmessage'] = $PHORUM['DATA']['LANG']['MailinglistMessage'];
        $mail_data['mailsubject'] = $PHORUM['DATA']['LANG']['MailinglistSubject'];

        if (!empty($PHORUM['mod_mailinglist']['forum_from_addresses'][$message['forum_id']])) {
            // Build FROM address
            require_once('./include/api/mail.php');
            $from_name = trim($PHORUM['system_email_from_name']);
            if ($from_name != '') {
                // Handle (Quoted-Printable) encoding of the from name.
                // Mail headers cannot contain 8-bit data as per RFC821.
                $from_name = phorum_api_mail_encode_header($from_name);
                $prefix  = $from_name.' <';
                $postfix = '>';
            } else {
                $prefix = $postfix = '';
            }

            $mail_data['from_address']
                = $prefix
                      .$PHORUM['mod_mailinglist']['forum_from_addresses'][$message['forum_id']]
                      .$postfix;
        }

        phorum_email_user
            ( split(',', $PHORUM['mod_mailinglist']['forum_destinations'][$message['forum_id']]),
              $mail_data );
    }

    return $message;
}

//
// Add sanity checks
//
function mod_mailinglist_sanity_checks($sanity_checks) {
    if (    isset($sanity_checks)
         && is_array($sanity_checks) ) {
        $sanity_checks[] = array(
            'function'    => 'mod_mailinglist_do_sanity_checks',
            'description' => 'Mailing List Module'
        );
    }
    return $sanity_checks;
}

//
// Do sanity checks
//
function mod_mailinglist_do_sanity_checks() {
    global $PHORUM;

    // Check if module settings exists.
    if (    !isset($PHORUM['mod_mailinglist']['forum_destinations'])
         || !is_array($PHORUM['mod_mailinglist']['forum_destinations']) ) {
          return array(
                     PHORUM_SANITY_CRIT,
                     'The default settings for the module are missing.',
                     'Login as administrator in Phorum&#x2019;s administrative '
                         .'interface and go to the &quot;Modules&quot; section. '
                         .'Open the module settings for the Mailing List '
                         .'Module, add at least one mailing list destination '
                         .'and save the changes.'
                 );
    }

    // Check if Send Mail Through SMTP Module is enabled when Send Post Attachments option is marked.
    if (    isset($PHORUM['mod_mailinglist']['allow_attachments'])
         && $PHORUM['mod_mailinglist']['allow_attachments'] == 1
         && !(    isset($PHORUM['hooks']['send_mail'])
               && is_array($PHORUM['hooks']['send_mail']['mods'])
               && in_array('smtp_mail', $PHORUM['hooks']['send_mail']['mods']) ) ) {
          return array(
                     PHORUM_SANITY_WARN,
                     'Sending attachments is not possible because the Send Mail '
                         .'Through SMTP Module is not enabled.',
                     'Login as administrator in Phorum&#x2019;s administrative '
                         .'interface and go to the &quot;Modules&quot; section. '
                         .'Enable the Send Mail Through SMTP Module OR open the '
                         .'module settings for the Mailing List Module and '
                         .'disable the Send Post Attachments option.'
                 );
    }

    // Check if custom language file exists
    $checked = array();
    // Check for the default language file.
    if ( !file_exists
             ("./mods/mailinglist/lang/{$PHORUM['default_forum_options']['language']}.php")
       ) {
        return array(
            PHORUM_SANITY_WARN,
            'Your forum default language is set to &quot;'
                .htmlspecialchars($PHORUM['language'])
                .'&quot;, but the language file &quot;mods/mailinglist/lang/'
                .htmlspecialchars($PHORUM['language'])
                .'.php&quot; is not available on your system.',
            'Install or create the specified language file to make this default '
                .'language work or change the Language setting under Default '
                .'Settings.'
        );
    }
    $checked[$PHORUM['language']] = true;

    // Check for the forum specific language file(s).
    $forums = phorum_db_get_forums();
    foreach ($forums as $id => $forum) {
        if (    !empty($forum['language'])
             && !$checked[$forum['language']]
             && !file_exists("./mods/mailinglist/lang/{$forum['language']}.php")
           ) {
            return array(
                PHORUM_SANITY_WARN,
                'The language for forum &quot;'
                    .htmlspecialchars($forum['name'])
                    .'&quot; is set to &quot;'
                    .htmlspecialchars($forum['language'])
                    .'&quot;, but the language file &quot;mods/mailinglist/lang/'
                    .htmlspecialchars($forum['language'])
                    .'.php&quot; is not available on your system.',
                'Install the specified language file to make this default '
                    .'language work or change the language setting for the '
                    .'forum.'
            );
        }
        $checked[$forum['language']] = true;
    }

    // Check if custom language file contains same array key as the english file
    $PHORUM['DATA']['LANG'] = array();
    include_once('./mods/mailinglist/lang/english.php');
    $orig_data = $PHORUM['DATA']['LANG'];
    $orig_keys = array_keys($PHORUM['DATA']['LANG']);
    // Check all files in the module language directory
    $tmphandle = opendir('./mods/mailinglist/lang/');
    if ($tmphandle) {
        while ($file = readdir($tmphandle)) {
            if ($file == '.' || $file == '..' || $file == 'english.php')
                continue;
            else
                $PHORUM['DATA']['LANG'] = array();
                include("./mods/mailinglist/lang/{$file}");
                $new_keys = array_keys($PHORUM['DATA']['LANG']);

                $missing_keys = array();

                foreach ($orig_keys as $id => $key) {
                    if (!in_array($key, $new_keys)) {
                        $missing_keys[$key] = $orig_data[$key];
                    }
                }

                if (count($missing_keys)) {
                    $tmpmessage
                        = 'The following keys are missing in your custom language file '.$file.':';
                    foreach ($missing_keys as $key => $val) {
                        $tmpmessage .= '<br />'.$key;
                    }
                    return array(
                               PHORUM_SANITY_CRIT,
                               $tmpmessage,
                               'Please add these keys to this language file!'
                           );
                }
        }
        closedir($tmphandle);
    } else {
        return array(
                   PHORUM_SANITY_CRIT,
                   'Error getting file list of module language files.',
                   'Check if the mods/mailinglist/lang/ directory exists.'
               );
    }

    // Check if mailing list destinations are valid email addresses
    $destinations = $PHORUM['mod_mailinglist']['forum_destinations'];
    include_once('./include/email_functions.php');
    foreach ($destinations as $id => $emailarray) {
        foreach (split(',', $emailarray) as $id2 => $email) {
            if (    $email <> ''
                 && !phorum_valid_email($email)
               ) {
                return array(
                    PHORUM_SANITY_WARN,
                    'The mailing list destination '
                        .htmlspecialchars($email)
                        .' seems to be not a valid email address.',
                    'Correct the mailing list destination.'
                );
            }
        }
    }

    // Check if email FROM addresses are valid email addresses
    $from_addresses = $PHORUM['mod_mailinglist']['forum_from_addresses'];
    foreach ($from_addresses as $id => $email) {
        if (    $email <> ''
             && !phorum_valid_email($email)
           ) {
            return array(
                PHORUM_SANITY_WARN,
                'The email FROM address '
                    .htmlspecialchars($email)
                    .' seems to be not a valid email address.',
                'Correct the email FROM address.'
            );
        }
    }

    return array(PHORUM_SANITY_OK, NULL);
}


?>