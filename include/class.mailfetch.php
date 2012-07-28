<?php
/*********************************************************************
    class.mailfetch.php

    mail fetcher class. Uses IMAP ext for now.

    Peter Rotich <peter@osticket.com>
    Copyright (c)  2006-2012 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

require_once(INCLUDE_DIR.'class.mailparse.php');
require_once(INCLUDE_DIR.'class.ticket.php');
require_once(INCLUDE_DIR.'class.dept.php');
require_once(INCLUDE_DIR.'class.email.php');
require_once(INCLUDE_DIR.'class.filter.php');

class MailFetcher {

    var $ht;

    var $mbox;
    var $srvstr;

    var $charset = 'UTF-8';
    var $encodings =array('UTF-8','WINDOWS-1251', 'ISO-8859-5', 'ISO-8859-1','KOI8-R');
    
    function MailFetcher($email, $charset='UTF-8') {

        
        if($email && is_numeric($email)) //email_id
            $email=Email::lookup($email);

        if(is_object($email))
            $this->ht = $email->getMailAccountInfo();
        elseif(is_array($email) && $email['host']) //hashtable of mail account info
            $this->ht = $email; 
        else
            $this->ht = null;
           
        $this->charset = $charset;

        if($this->ht) {
            if(!strcasecmp($this->ht['protocol'],'pop')) //force pop3
                $this->ht['protocol'] = 'pop3';
            else
                $this->ht['protocol'] = strtolower($this->ht['protocol']);

            //Max fetch per poll
            if(!$this->ht['max_fetch'] || !is_numeric($this->ht['max_fetch']))
                $this->ht['max_fetch'] = 20;

            //Mail server string
            $this->srvstr=sprintf('{%s:%d/%s', $this->getHost(), $this->getPort(), $this->getProtocol());
            if(!strcasecmp($this->getEncryption(), 'SSL'))
                $this->srvstr.='/ssl';
        
            $this->srvstr.='/novalidate-cert}';

        }

        //Set timeouts 
        if(function_exists('imap_timeout')) imap_timeout(1,20);

    }

    function getEmailId() {
        return $this->ht['email_id'];
    }

    function getHost() {
        return $this->ht['host'];
    }

    function getPort() {
        return $this->ht['port'];
    }

    function getProtocol() {
        return $this->ht['protocol'];
    }

    function getEncryption() {
        return $this->ht['encryption'];
    }

    function getUsername() {
        return $this->ht['username'];
    }
    
    function getPassword() {
        return $this->ht['password'];
    }

    /* osTicket Settings */

    function canDeleteEmails() {
        return ($this->ht['delete_mail']);
    }

    function getMaxFetch() {
        return $this->ht['max_fetch'];
    }

    function getArchiveFolder() {
        return $this->ht['archive_folder'];
    }

    /* Core */
    
    function connect() {
        return ($this->mbox && $this->ping())?$this->mbox:$this->open();
    }

    function ping() {
        return ($this->mbox && imap_ping($this->mbox));
    }

    /* Default folder is inbox - TODO: provide user an option to fetch from diff folder/label */
    function open($box='INBOX') {
      
        if($this->mbox)
           $this->close();

        $this->mbox = imap_open($this->srvstr.$box, $this->getUsername(), $this->getPassword());

        return $this->mbox;
    }

    function close($flag=CL_EXPUNGE) {
        imap_close($this->mbox, $flag);
    }

    function mailcount() {
        return count(imap_headers($this->mbox));
    }

    //Get mail boxes.
    function getMailboxes() {

        if(!($folders=imap_list($this->mbox, $this->srvstr, "*")) || !is_array($folders))
            return null;

        $list = array();
        foreach($folders as $k => $folder)
            $list[]= str_replace($this->srvstr, '', imap_utf7_decode(trim($folder)));

        return $list;
    }

    //Create a folder.
    function createMailbox($folder) {

        if(!$folder) return false;
            
        return imap_createmailbox($this->mbox, imap_utf7_encode($this->srvstr.trim($folder)));
    }

    /* check if a folder exists - create one if requested */
    function checkMailbox($folder, $create=false) {

        if(($mailboxes=$this->getMailboxes()) && in_array(trim($folder), $mailboxes))
            return true;

        return ($create && $this->createMailbox($folder));
    }


    function decode($encoding, $text) {

        switch($encoding) {
            case 1:
            $text=imap_8bit($text);
            break;
            case 2:
            $text=imap_binary($text);
            break;
            case 3:
            $text=imap_base64($text);
            break;
            case 4:
            $text=imap_qprint($text);
            break;
            case 5:
            default:
             $text=$text;
        } 
        return $text;
    }

    //Convert text to desired encoding..defaults to utf8
    function mime_encode($text, $charset=null, $enc='utf-8') { //Thank in part to afterburner  
        
        if(function_exists('iconv') and $text) {
            if($charset)
                return iconv($charset, $enc.'//IGNORE', $text);
            elseif(function_exists('mb_detect_encoding'))
                return iconv(mb_detect_encoding($text, $this->encodings), $enc, $text);
        }

        return utf8_encode($text);
    }
    
    //Generic decoder - mirrors imap_utf8
    function mime_decode($text) {
        
        $str = '';
        $parts = imap_mime_header_decode($text);
        foreach ($parts as $k => $part)
            $str.= $part->text;
        
        return $str?$str:imap_utf8($text);
    }

    function getLastError() {
        return imap_last_error();
    }

    function getMimeType($struct) {
        $mimeType = array('TEXT', 'MULTIPART', 'MESSAGE', 'APPLICATION', 'AUDIO', 'IMAGE', 'VIDEO', 'OTHER');
        if(!$struct || !$struct->subtype)
            return 'TEXT/PLAIN';
        
        return $mimeType[(int) $struct->type].'/'.$struct->subtype;
    }

    function getHeaderInfo($mid) {
        
        if(!($headerinfo=imap_headerinfo($this->mbox, $mid)) || !$headerinfo->from)
            return null;

        $sender=$headerinfo->from[0];
        //Just what we need...
        $header=array('name'  =>@$sender->personal,
                      'email' =>(strtolower($sender->mailbox).'@'.$sender->host),
                      'subject'=>@$headerinfo->subject,
                      'mid'    =>$headerinfo->message_id
                      );

        return $header;
    }

    function getAttachment($part) {

        if(!$part) return null;

        if($part->ifdisposition && in_array(strtolower($part->disposition), array('attachment', 'inline')))
            return $part->dparameters[0]->value;

        if($part->ifparameters && $part->type == 5)
            return $part->parameters[0]->value;


        return null;
    }

    //search for specific mime type parts....encoding is the desired encoding.
    function getPart($mid, $mimeType, $encoding=false, $struct=null, $partNumber=false) {
          
        if(!$struct && $mid)
            $struct=@imap_fetchstructure($this->mbox, $mid);

        //Match the mime type.
        if($struct && !$struct->ifdparameters && strcasecmp($mimeType, $this->getMimeType($struct))==0) {
            $partNumber=$partNumber?$partNumber:1;
            if(($text=imap_fetchbody($this->mbox, $mid, $partNumber))) {
                if($struct->encoding==3 or $struct->encoding==4) //base64 and qp decode.
                    $text=$this->decode($struct->encoding, $text);

                $charset=null;
                if($encoding) { //Convert text to desired mime encoding...
                    if($struct->ifparameters) {
                        if(!strcasecmp($struct->parameters[0]->attribute,'CHARSET') && strcasecmp($struct->parameters[0]->value,'US-ASCII'))
                            $charset=trim($struct->parameters[0]->value);
                    }
                    $text=$this->mime_encode($text, $charset, $encoding);
                }
                return $text;
            }
        }

        //Do recursive search
        $text='';
        if($struct && $struct->parts) {
            while(list($i, $substruct) = each($struct->parts)) {
                if($partNumber) 
                    $prefix = $partNumber . '.';
                if(($result=$this->getPart($mid, $mimeType, $encoding, $substruct, $prefix.($i+1))))
                    $text.=$result;
            }
        }

        return $text;
    }

    /*
     getAttachments

     search and return a hashtable of attachments....
     NOTE: We're not actually fetching the body of the attachment  - we'll do it on demand to save some memory.

     */
    function getAttachments($part, $index=0) {

        if($part && !$part->parts) {
            //Check if the part is an attachment.
            $filename = '';
            if($part->ifdisposition && in_array(strtolower($part->disposition), array('attachment', 'inline')))
                $filename = $part->dparameters[0]->value;
            elseif($part->ifparameters && $part->type == 5) //inline image without disposition.
                $filename = $part->parameters[0]->value;

            if($filename) {
                return array(
                        array(
                            'name'  => $filename,
                            'mime'  => $this->getMimeType($part),
                            'encoding' => $part->encoding,
                            'index' => ($index?$index:1)
                            )
                        );
            }
        }

        //Recursive attachment search!
        $attachments = array();
        if($part && $part->parts) {
            foreach($part->parts as $k=>$struct) {
                if($index) $prefix = $index.'.';
                $attachments = array_merge($attachments, $this->getAttachments($struct, $prefix.($k+1)));
            }
        }

        return $attachments;
    }

    function getHeader($mid) {
        return imap_fetchheader($this->mbox, $mid,FT_PREFETCHTEXT);
    }

    
    function getPriority($mid) {
        return Mail_Parse::parsePriority($this->getHeader($mid));
    }

    function getBody($mid) {
        
        $body ='';
        if(!($body = $this->getPart($mid,'TEXT/PLAIN', $this->charset))) {
            if(($body = $this->getPart($mid,'TEXT/HTML', $this->charset))) {
                //Convert tags of interest before we striptags
                $body=str_replace("</DIV><DIV>", "\n", $body);
                $body=str_replace(array("<br>", "<br />", "<BR>", "<BR />"), "\n", $body);
                $body=Format::html($body); //Balance html tags before stripping.
                $body=Format::striptags($body); //Strip tags??
            }
        }

        return $body;
    }

    //email to ticket 
    function createTicket($mid) { 
        global $ost;

        if(!($mailinfo = $this->getHeaderInfo($mid)))
            return false;

        //Make sure the email is NOT already fetched... (undeleted emails)
        if($mailinfo['mid'] && ($id=Ticket::getIdByMessageId(trim($mailinfo['mid']), $mailinfo['email'])))
            return true; //Reporting success so the email can be moved or deleted.

	    //Is the email address banned?
        if($mailinfo['email'] && EmailFilter::isBanned($mailinfo['email'])) {
	        //We need to let admin know...
            $ost->logWarning('Ticket denied', 'Banned email - '.$mailinfo['email']);
	        return true; //Report success (moved or delete)
        }

        $emailId = $this->getEmailId();

        $var['name']=$this->mime_decode($mailinfo['name']);
        $var['email']=$mailinfo['email'];
        $var['subject']=$mailinfo['subject']?$this->mime_decode($mailinfo['subject']):'[No Subject]';
        $var['message']=Format::stripEmptyLines($this->getBody($mid));
        $var['header']=$this->getHeader($mid);
        $var['emailId']=$emailId?$emailId:$ost->getConfig()->getDefaultEmailId(); //ok to default?
        $var['name']=$var['name']?$var['name']:$var['email']; //No name? use email
        $var['mid']=$mailinfo['mid'];

        if(!$var['message']) //An email with just attachments can have empty body.
            $var['message'] = '(EMPTY)';

        if($ost->getConfig()->useEmailPriority())
            $var['priorityId']=$this->getPriority($mid);
       
        $ticket=null;
        $newticket=true;
        //Check the subject line for possible ID.
        if($var['subject'] && preg_match ("[[#][0-9]{1,10}]", $var['subject'], $regs)) {
            $tid=trim(preg_replace("/[^0-9]/", "", $regs[0]));
            //Allow mismatched emails?? For now NO.
            if(!($ticket=Ticket::lookupByExtId($tid)) || strcasecmp($ticket->getEmail(), $var['email']))
                $ticket=null;
        }
        
        $errors=array();
        if($ticket) {
            if(!($msgid=$ticket->postMessage($var['message'], 'Email', $var['mid'], $var['header'])))
                return false;

        } elseif (($ticket=Ticket::create($var, $errors, 'Email'))) {
            $msgid = $ticket->getLastMsgId();
        } else {
            //TODO: Log error..
            return null;
        }

        //Save attachments if any.
        if($msgid 
                && $ost->getConfig()->allowEmailAttachments()
                && ($struct = imap_fetchstructure($this->mbox, $mid)) 
                && $struct->parts 
                && ($attachments=$this->getAttachments($struct))) {
                
            //We're just checking the type of file - not size or number of attachments...
            // Restrictions are mainly due to PHP file uploads limitations
            foreach($attachments as $a ) {
                if($ost->isFileTypeAllowed($a['name'], $a['mime'])) {
                    $file = array(
                            'name'  => $a['name'],
                            'type'  => $a['mime'],
                            'data'  => $this->decode($a['encoding'], imap_fetchbody($this->mbox, $mid, $a['index']))
                            );
                    $ticket->saveAttachment($file, $msgid, 'M');
                } else {
                    //This should be really a comment on message - NoT an internal note.
                    //TODO: support comments on Messages and Responses.
                    $error = sprintf('Attachment %s [%s] rejected because of file type', $a['name'], $a['mime']);
                    $ticket->postNote('Email Attachment Rejected', $error, false);
                    $ost->logDebug('Email Attachment Rejected (Ticket #'.$ticket->getExtId().')', $error);
                }
            }
        }

        return $ticket;
    }


    function fetchEmails() {


        if(!$this->connect())
            return false;

        $archiveFolder = $this->getArchiveFolder();
        $delete = $this->canDeleteEmails();
        $max = $this->getMaxFetch();

        $nummsgs=imap_num_msg($this->mbox);
        //echo "New Emails:  $nummsgs\n";
        $msgs=$errors=0;
        for($i=$nummsgs; $i>0; $i--) { //process messages in reverse.
            if($this->createTicket($i)) {

                imap_setflag_full($this->mbox, imap_uid($this->mbox, $i), "\\Seen", ST_UID); //IMAP only??
                if((!$archiveFolder || !imap_mail_move($this->mbox, $i, $archiveFolder)) && $delete)
                    imap_delete($this->mbox, $i);

                $msgs++;
                $errors=0; //We are only interested in consecutive errors.
            } else {
                $errors++;
            }

            if(($max && $msgs>=$max) || $errors>100)
                break;
        }

        @imap_expunge($this->mbox);

        return $msgs;
    }


    /*
       MailFetcher::run()

       Static function called to initiate email polling 
     */
    function run() {
        global $ost;
      
        if(!$ost->getConfig()->isEmailPollingEnabled())
            return;

        //We require imap ext to fetch emails via IMAP/POP3
        //We check here just in case the extension gets disabled post email config...
        if(!function_exists('imap_open')) {
            $msg='osTicket requires PHP IMAP extension enabled for IMAP/POP3 email fetch to work!';
            $ost->logWarning('Mail Fetch Error', $msg);
            return;
        }

        //Hardcoded error control... 
        $MAXERRORS = 5; //Max errors before we start delayed fetch attempts
        $TIMEOUT = 10; //Timeout in minutes after max errors is reached.

        $sql=' SELECT email_id, mail_errors FROM '.EMAIL_TABLE
            .' WHERE mail_active=1 '
            .'  AND (mail_errors<='.$MAXERRORS.' OR (TIME_TO_SEC(TIMEDIFF(NOW(), mail_lasterror))>'.($TIMEOUT*60).') )'
            .'  AND (mail_lastfetch IS NULL OR TIME_TO_SEC(TIMEDIFF(NOW(), mail_lastfetch))>mail_fetchfreq*60)'
            .' ORDER BY mail_lastfetch DESC'
            .' LIMIT 10'; //Processing up to 10 emails at a time.

       // echo $sql;
        if(!($res=db_query($sql)) || !db_num_rows($res))
            return;  /* Failed query (get's logged) or nothing to do... */

        //TODO: Lock the table here??

        while(list($emailId, $errors)=db_fetch_row($res)) {
            $fetcher = new MailFetcher($emailId);
            if($fetcher->connect()) {
                $fetcher->fetchEmails();
                $fetcher->close();
                db_query('UPDATE '.EMAIL_TABLE.' SET mail_errors=0, mail_lastfetch=NOW() WHERE email_id='.db_input($emailId));
            } else {
                db_query('UPDATE '.EMAIL_TABLE.' SET mail_errors=mail_errors+1, mail_lasterror=NOW() WHERE email_id='.db_input($emailId));
                if(++$errors>=$MAXERRORS) {
                    //We've reached the MAX consecutive errors...will attempt logins at delayed intervals
                    $msg="\nosTicket is having trouble fetching emails from the following mail account: \n".
                        "\nUser: ".$fetcher->getUsername().
                        "\nHost: ".$fetcher->getHost().
                        "\nError: ".$fetcher->getLastError().
                        "\n\n ".$errors.' consecutive errors. Maximum of '.$MAXERRORS. ' allowed'.
                        "\n\n This could be connection issues related to the mail server. Next delayed login attempt in aprox. $TIMEOUT minutes";
                    $ost->alertAdmin('Mail Fetch Failure Alert', $msg, true);
                }
            }
        } //end while.
    }
}
?>
