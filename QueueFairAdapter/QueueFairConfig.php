<?php

namespace QueueFair\Adapter;

require_once "QueueFairAdapter.php";

class QueueFairConfig
{
    //Your Account Secret is shown on the Your Account page of
    //the Queue-Fair Portal.  If you change it there, you must 
    //change it here too.
    public $accountSecret="DELETE THIS TEXT AND REPLACE WITH YOUR ACCOUNT SECRET";
    
    //The System Name of your account from the Your Account page
    //of the Queue-Fair Portal.
    public $account ="DELETE THIS TEXT AND REPLACE WITH YOUR ACCOUNT SYSTEM NAME";
    
    //Leave this set as is
    public $filesServer="files.queue-fair.net";
    
    //Time limit for Passed Strings to be considered valid,
    //before and after the current time
    public $queryTimeLimitSeconds=30;
    
    //Valid values are true, false, or an "IP_address".	
    public $debug=true;

    //How long to wait in seconds for network reads of config 
    //or Adapter Server (safe mode only)
    public $readTimeout=5;

    //You must set this to a folder that has write permission for your web server
    //If it's not saving as expected turn on debugging above and look for messages in
    //your apache error_log.  You should change this to somewhere outside your
    //web root for maximum security.  On Unix use chmod -R 777 FOLDER_NAME
    //on the desired folder to enable Adapter writes, reads and access to folder contents.
    public $settingsFileCacheLocation="/opt/qfsettings";

    //How long a cached copy of your Queue-Fair settings will be kept before downloading
    //a fresh copy.  Set this to 0 if you are updating your settings in the 
    //Queue-Fair Portal and want to test your changes quickly, but remember 
    //to set it back again when you are finished to reduce load on your server.
    //Set to -1 to disable downloading entirely.
    public $settingsFileCacheLifetimeMinutes=5;

    //Whether or not to strip the Passed String from the URL
    //that the Visitor sees on return from the Queue or Adapter servers
    //(simple mode) - when set to true causes one additinal HTTP request
    //to your site but only on the first matching visit from a particular
    //visitor. The recommended value is true.
    public $stripPassedString=true;

    //Whether to send the visitor to the Adapter server for counting (simple mode),
    //or consult the Adapter server (safe mode).  The recommended value is "safe".
    public $adapterMode = "safe";

    //Leave this as-is.
    public function __toString() 
    {
        return "Config";
    }
}
