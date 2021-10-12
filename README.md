---
## Queue-Fair Adapter for PHP README & Installation Guide

Queue-Fair can be added to any PHP installation easily in minutes.  You will need a Queue-Fair account - please visit https://queue-fair.com/free-trial if you don't already have one.  You should also have received our Technical Guide.  You can find out all about Queue-Fair at https://queue-fair.com

## Client-Side JavaScript Adapter

Most of our customers prefer to use the Client-Side JavaScript Adapter, which is suitable for all sites that wish solely to protect against overload.

To add the Queue-Fair Client-Side JavaScript Adapter to your PHP installation, you don't need the PHP files included in this extension.

Instead, add the following tag to the `<head>` section of your pages:
 
```
<script data-queue-fair-client="CLIENT_NAME" src="https://files.queue-fair.net/queue-fair-adapter.js"></script>`
```

Replace CLIENT_NAME with the account system name visibile on the Account -> Your Account page of the Queue-Fair Portal

You shoud now see the Adapter tag when you perform View Source after refreshing your pages.

And you're done!  Your queues and activation rules can now be configured in the Queue-Fair Portal. 

You can also use Google Tag Manager to add the Queue-Fair Client-Side JavaScript Adapter to your pages - find our tag in Community Templates.


## Server-Side Adapter

The Server-Side Adapter means that your web server communicates directly with the Queue-Fair servers, rather than your visitors' browsers.

This can introduce a dependency between our systems, which is why most customers prefer the Client-Side Adapter. See Section 10 of the Technical Guide for help regarding which integration method is most suitable for you.

The Server-Side Adapter is a small library that will run when visitors access your site. It periodically checks to see if you have changed your Queue-Fair settings in the Portal, but other than that if the visitor is requesting a page that does not match any queue's Activation Rules, it does nothing.

If a visitor requests a page that DOES match any queue's Activation Rules, the Adapter consults the Queue-Fair queue servers to make a determination whether that particular visitor should be queued. If so, the visitor is sent to our Queue Servers and execution and generation of the page for that HTTP request for that visitor will cease. If the Adapter determines that the visitor should not be queued, it sets a cookie to indicate that the visitor has been processed and your page executes and shows as normal.

Thus the Server-Side Adapter prevents technically skilled visitors from skipping the queue by disabling the Client-Side JavaScript Adapter, and also reduces load on your web server when things get busy.

This guide assumes you already have a functional web server with PHP version 5.4 or above. Apache2 is used as a base example, but you can use any web server that supports PHP.

Here's every keystroke for the install.

**1.** Create a readable, writable and executable folder so that your Queue-Fair settings can be locally saved (necessary for performance of your web server under load):

```
    sudo mkdir /opt/qfsettings    
    sudo chmod 777 /opt/qfsettings
```

Note: The settings folder can go anywhere, but for maximum security this should not be in your web root.  The executable permission is needed on the folder so that the Adapter can examine its contents.  You can see your Queue-Fair settings in the Portal File Manager - they are updated when you hit Make Live.

**2.** **IMPORTANT:** Make sure the system clock on your webserver is accurately set to network time! On unix systems, this is usually done with the ntp package.  It doesn't matter which timezone you are using.  For Debian/Ubuntu:

```
    sudo apt-get install ntp
```

**3.** Go to your web server document root:

```
    cd /var/www/mysite
```

**4.** Copy the QueueFairAdapter.php and QueueFairConfig.php files from this distibution into your web root, or somewhere that PHP can find them.

```
    cp /path/to/QueueFairAdapter/QueueFairAdapter.php /var/www/mysite
    cp /path/to/QueueFairAdapter/QueueFairConfig.php /var/www/mysite

```

**6.** Next, edit `/var/www/mysite/QueueFairConfig.php`

```
    nano /var/www/mysite/QueueFairConfig.php
```

**7.** At the top of `QueueFairConfig.php` set your account name and account secret to the account System Name and Account Secret shown on the Your Account page of the Queue-Fair portal.  

**8.** Change the `settingsFileCacheLocation` to match the folder path to the settings folder you created above.

**9.** Note the `settingsFileCacheLifetimeMinutes` setting - this is how often your web server will check for updated settings from the Queue-Fair queue servers (which change when you hit Make Live).   The default value is 5 minutes.  You can set this to -1 to disable local caching but **DON'T DO THIS** on your production machine/live queue with real people, or your server may collapse under load.

**10.** Note the `adapterMode` setting.  "safe" is recommended - we also support "simple" - see the Technical Guide for further details.

**11.** **IMPORTANT** Note the `debug` setting - this is set to true by default, BUT you MUST set debug to false on production machines/live queues as otherwise your web logs will rapidly become full.  You can safely set it to a single IP address to just output debug information for a single visitor, even on a production machine.

The debug logging statements will appear in whichever file php has been set-up to output error message information. If using Apache, it will appear in the apache error.log, and you can see the messages with

```
    sudo tail -f /var/log/apache2/error.log | sed 's/\\n/\n/g'
```

**12.** When you have finished making changes to `QueueFairConfig.php`, hit `CTRL-O` to save and `CTRL-X` to exit nano.

To make the Adapter actually run, you need to edit whichever file on your server produces the page(s) you wish to protect.   You should ensure that the Adapter is the first thing that is run when generating any page.  If you have a global header file that outputs the opening <HTML> tag of your pages, then that is a good place.  **Make sure you make a back up copy of any file you need to change before editing.** 

```
    nano /var/www/mysite/header.php
```
and just after the opening `<?php` tag, on the second line, add

```
if(strpos($_SERVER["REQUEST_URI"],"/rest/") === false && strpos($_SERVER["REQUEST_URI"],"/ajax/") === false) { 
    require_once "QueueFairConfig.php";
    require_once "QueueFairAdapter.php";
    
    $queueFair = new QueueFair\Adapter\QueueFairAdapter(new QueueFair\Adapter\QueueFairConfig());
    
    $queueFair->requestedURL = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://")
        .$_SERVER["HTTP_HOST"].$_SERVER["REQUEST_URI"];
    $queueFair->query = $_SERVER["QUERY_STRING"];
    $queueFair->remoteAddr = $_SERVER["REMOTE_ADDR"];
    $queueFair->userAgent = $_SERVER["HTTP_USER_AGENT"];
    $queueFair->cookies = $_COOKIE;
    
    if(!$queueFair->go()) {
        exit();
    }
    unset($queueFair);
}
```
This will ensure that the adapter is the first thing that runs when a vistor accesses any page, which is necessary both to protect your server from load from lots of visitors and also so that the adapter can set the necessary cookies.  You can then use the Activation Rules in the Portal to set which pages on your site may trigger a queue.

The `if` statement prevents the Adapter from running on background PHP AJAX and RestAPI calls - you really only want the Adapter to run on page requests.

In the case where the Adapter sends the request elsewhere (for example to show the user a queue page), the `go()` method will return false and the rest of the page will NOT be generated, which means it isn't sent to the visitor's browser, which makes it secure, as well as preventing your server from having to do the work of producing the rest of the page.  It is important that this code runs *before* any PHP framework you may be using initialises so that your server can perform this under load, when your full PHP framework is too onerous to load.

**NOTE:** If your PHP server is sitting behind a proxy, CDN or load balancer, you may need to edit the property sets in the above stanza to use values from forwarded headers instead.  If you need help with this, contact Queue-Fair support.

Tap `CTRL-O` to save and `CTRL-X` to exit nano.  **You should also back up the index.php file after making your changes** in case it gets overwritten by any future update.

That's it you're done!

### To test the Server-Side Adapter

Use a queue that is not in use on other pages, or create a new queue for testing.

#### Testing SafeGuard
Set up an Activtion Rule to match the page you wish to test.  Hit Make Live.  Go to the Settings page for the queue.  Put it in SafeGuard mode.  Hit Make Live again.

In a new Private Browsing window, visit the page on your site.  

 - Verify that you can see debug output from the Adapter in your error-log.
 - Verify that a cookie has been created named `Queue-Fair-Pass-queuename`, where queuename is the System Name of your queue
 - If the Adapter is in Safe mode, also verify that a cookie has been created named QueueFair-Store-accountname, where accountname is the System Name of your account (on the Your Account page on the portal).
 - If the Adapter is in Simple mode, the Queue-Fair-Store cookie is not created.
 - Hit Refresh.  Verify that the cookie(s) have not changed their values.

#### Testing Queue
Go back to the Portal and put the queue in Demo mode on the Queue Settings page.  Hit Make Live.  Delete any Queue-Fair-Pass cookies from your browser.  In a new tab, visit https://accountname.queue-fair.net , and delete any Queue-Fair-Pass or Queue-Fair-Data cookies that appear there.  Refresh the page that you have visited on your site.

 - Verify that you are now sent to queue.
 - When you come back to the page from the queue, verify that a new QueueFair-Pass-queuename cookie has been created.
 - If the Adapter is in Safe mode, also verify that the QueueFair-Store cookie has not changed its value.
 - Hit Refresh.  Verify that you are not queued again.  Verify that the cookies have not changed their values.

**IMPORTANT:**  Once you are sure the Server-Side Adapter is working as expected, remove the Client-Side JavaScript Adapter tag from your pages, and don't forget to disable debug level logging in `QueueFairConfig.php`, and also set `settingsFileCacheLifetimeMinutes` to at least 5.

**IMPORTANT**: Responses that contain a Location header or a Set-Cookie header from the Adapter must not be cached! The Adapter will automatically add a Cache-Control header disabling caching if it adds any header - but you must check whether the added Cache-Control header is still present in the output to your browser, as some webservers or PHP installations may override the automatically added header, or add a contradictory header.

### For maximum security

The Server-Side Adapter contains multiple checks to prevent visitors bypassing the queue, either by tampering with set cookie values or query strings, or by sharing this information with each other.  When a tamper is detected, the visitor is treated as a new visitor, and will be sent to the back of the queue if people are queuing.

 - The Server-Side Adapter checks that Passed Cookies and Passed Strings presented by web browsers have been signed by our Queue-Server.  It uses the Secret visible on each queue's Settings page to do this.
 - If you change the queue Secret, this will invalidate everyone's cookies and also cause anyone in the queue to lose their place, so modify with care!
 - The Server-Side Adapter also checks that Passed Strings coming from our Queue Server to your web server were produced within the last 30 seconds, which is why your clock must be accurately set.
 -  The Server-Side Adapter also checks that passed cookies were produced within the time limit set by Passed Lifetime on the queue Settings page, to prevent visitors trying to cheat by tampering with cookie expiration times or sharing cookie values.  So, the Passed Lifetime should be set to long enough for your visitors to complete their transaction, plus an allowance for those visitors that are slow, but no longer.
 - The signature also includes the visitor's USER_AGENT, to further prevent visitors from sharing cookie values.

## AND FINALLY

All client-modifiable settings are in `QueueFairConfig.php` .  You should never find you need to modify `QueueFairAdapter.php` - but if something comes up, please contact support@queue-fair.com right away so we can discuss your requirements.

Remember we are here to help you! The integration process shouldn't take you more than an hour - so if you are scratching your head, ask us.  Many answers are contained in the Technical Guide too.  We're always happy to help!

