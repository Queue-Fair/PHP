<?php
//You should never need to modify this library file.  If you find that you
//are thinking of doing this, please contact support@queue-fair.com
namespace QueueFair\Adapter;

class QueueFairAdapter
{

    public $parsing = false;

    public $protocol = "https";

    public $cookieNameBase = "QueueFair-Pass-";

    public $config = null;

    public $settings = null;

    public $adapterResult = null;

    public $passedString = null;

    public $passedQueues = [];

    public $uid = null;

    public $continuePage = true;

    public $query="";

    public $remoteAddr="127.0.0.1";

    public $userAgent="UNSET";

    public $cookies=[];

    public $requestedURL = "";

    public $d = true;

    public $extra = "";

    public $addedCacheControlHeader = false;
    
    public function __construct($conf)
    {
        $this->config = $conf;
    }

    protected function setDebug()
    {
        if (!$this->config->debug) {
            $this->d = false;
            return;
        }

        if ($this->config->debug !== true) {
            if ($this->remoteAddr != $this->config->debug) {
                $this->d = false;
                return;
            }
        }

        $this->d = true;
    }

    protected function log($line, $what)
    {
        error_log("QF Line " . $line . ": " . $what."\n");
    }

    protected function isMatch($queue)
    {
        if (!isset($queue)) {
            return false;
        }

        if (!isset($queue->activation)) {
            return false;
        }

        if (!isset($queue->activation->rules)) {
            return false;
        }

        return $this->isMatchArray(
            $queue
            ->activation
            ->rules
        );
    }

    protected function checkAndAddCacheControl()
    {
        if($this->addedCacheControlHeader) {
            return;
        }
        $this->addedCacheControlHeader = true;
        header("Cache-Control: no-store, max-age=0");
    }

    public function isMatchArray($arr)
    {
        if (!isset($arr)) {
            return false;
        }

        $firstOp = true;
        $state = false;

        $lim = count($arr);
        for ($i = 0; $i < $lim; $i++) {
            $rule = $arr[$i];

            if (!$firstOp && isset($rule->operator)) {
                if ($rule->operator == "And" && !$state) {
                    return false;
                } elseif ($rule->operator == "Or" && $state) {
                    return true;
                }
            }

            $ruleMatch = $this->isRuleMatch($rule);

            if ($firstOp) {
                $state = $ruleMatch;
                $firstOp = false;
                if ($this->d) {
                    $this->log(__LINE__, "  Rule 1: " . (($ruleMatch) ? "true" : "false"));
                }
            } else {
                if ($this->d) {
                    $this->log(__LINE__, "  Rule " . ($i+1) . ": " . (($ruleMatch) ? "true" : "false"));
                }
                if ($rule->operator == "And") {
                    $state = ($state && $ruleMatch);
                    if (!$state) {
                        break;
                    }
                } elseif ($rule->operator == "Or") {
                    $state = ($state || $ruleMatch);
                    if ($state) {
                        break;
                    }
                }
            }
        }

        if ($this->d) {
            $this->log(__LINE__, "Final result is " . (($state) ? "true" : "false"));
        }

        return $state;
    }

    protected function startsWith($haystack, $needle)
    {
        return substr_compare($haystack, $needle, 0, strlen($needle)) === 0;
    }

    protected function endsWith($haystack, $needle)
    {
        return substr_compare($haystack, $needle, -strlen($needle)) === 0;
    }
    

    public function extractComponent($rule, $url, $cookieValue) {
        $comp = $url;
        if ($rule->component == "Domain") {
            $comp = str_replace('http://', '', $comp);
            $comp = str_replace('https://', '', $comp);

            $comp = preg_split("/[\/\?#:]/", $comp) [0];
        } elseif ($rule->component == "Path") {
            $domain = str_replace("http://", '', $comp);
            $domain = str_replace('https://', '', $domain);
            $domain = preg_split("/[\/\?#:]/", $domain) [0];
            $comp = substr($comp, strpos($comp, $domain) + strlen($domain));

            if ($this->startsWith($comp, ":")) {
                $i = strpos($comp, "/");
                if ($i !== false) {
                    $comp = substr($comp, $i);
                } else {
		    $comp = "";
		}
            }

            $i = strpos($comp, "#");
            if ($i !== false) {
                $comp = substr($comp, 0, $i);
            }

            $i = strpos($comp, "?");
            if ($i !== false) {
                $comp = substr($comp, 0, $i);
            }
	    if($comp == "") {
		$comp = "/";
	    }
        } elseif ($rule->component == "Query") {
            if (strpos($comp, "?") === false) {
                $comp = "";
            } elseif ($comp == "?") {
                $comp = "";
            } else {
                $comp = substr($comp, strpos($comp, "?") + 1);
            }
        } elseif ($rule->component == "Cookie") {
            $comp = $cookieValue;
        }
        return $comp;
    }

    public function isRuleMatch($rule)
    {
        $comp=$this->extractComponent($rule,$this->getURL(),$this->getCookie($rule->name));
        return $this->isRuleMatchWithValue($rule,$comp);
    }

    protected function isRuleMatchWithValue($rule, $comp) {
        $test = $rule->value;

        if ($rule->caseSensitive == false) {
            $comp = strtolower($comp);
            $test = strtolower($test);
        }

        if ($this->d) {
            $this->log(__LINE__, "  Testing " . $rule->component . " " . $test . " against " . $comp);
        }

        $ret = false;

        if ($rule->match == "Equal" && $comp == $test) {
            $ret = true;
        } elseif ($rule->match == "Contain" && $comp != null && $comp != "" && strpos($comp, $test) !== false) {
            $ret = true;
        } elseif ($rule->match == "Exist") {
            if (!isset($comp) || $comp == null || "" == comp) {
                $ret = false;
            } else {
                $ret = true;
            }
        }
        if ($rule->negate) {
            $ret = !$ret;
        }
        
        return $ret;
    }

    protected function onMatch($queue)
    {
        if ($this->isPassed($queue)) {
            if ($this->d) {
                $this->log(__LINE__, "Already passed " . $queue->name . ".");
            }
	    return true;
        } elseif (!$this->continuePage) {
            return false;
        }

        if ($this->d) {
            $this->log(__LINE__, "Checking at server " . $queue->displayName);
        }
        $this->consultAdapter($queue);
        return false;
    }

    protected function isPassed($queue)
    {
        if (isset($this->passedQueues[$queue->name])) {
            if ($this->d) {
                $this->log(__LINE__, "Queue " . $queue->name . " marked as passed already.");
            }
            return true;
        }

        $queueCookie = $this->getCookie($this->cookieNameBase . $queue->name);
        if (!$queueCookie || $queueCookie == "") {
            if ($this->d) {
                $this->log(__LINE__, "No cookie found for queue " . $queue->name);
            }
            return false;
        }

        if (strpos($queueCookie, $queue->name) === false) {
            if ($this->d) {
                $this->log(__LINE__, "Cookie value is invalid for " . $queue->name);
            }
            return false;
        }

        if (!$this->validateCookie($queue, $queueCookie)) {
            if ($this->d) {
                $this->log(__LINE__, "Cookie failed validation " . $queueCookie);
            }
            $this->setCookie($queue->name, "", 0, (isset($queue->cookieDomain)) ? $queue->cookieDomain : null);
            return false;
        }

        if ($this->d) {
            $this->log(__LINE__, "Found valid cookie for " . $queue->name);
        }
        return true;
    }

    protected function getCookie($cname)
    {

        if (!isset($this->cookies[$cname])) {
            return "";
        }

        $cookie = $this->cookies[$cname];

        if ($cookie == null) {
            return "";
        }

        return $cookie;
    }

    protected function setUIDFromCookie()
    {
        $cookieBase = "QueueFair-Store-" . $this
            ->config->account;

        $uidCookie = $this->getCookie($cookieBase);
        if ($uidCookie != "") {
            $i = strpos($uidCookie, "=");
            if ($i === false) {
		$i = strpos($uidCookie,":");
	    }
	    if ($i === false) {
                if ($this->d) {
                    $this->log(__LINE__, "= not found in UID Cookie! " . $uidCookie);
                }
                $this->uid = $uidCookie;
            } else {
                $this->uid = substr($uidCookie, $i + 1);
                if ($this->d) {
                    $this->log(__LINE__, "UID set to " . $this->uid);
                }
            }
        }
    }

    protected function gotSettings()
    {
        if ($this->d) {
            $this->log(__LINE__, "Got client settings.");
        }
        $this->checkQueryString();
        if (!$this->continuePage) {
            return;
        }
        $this->parseSettings();
    }

    protected function parseSettings()
    {
        if (!$this->settings) {
            if ($this->d) {
                $this->log(__LINE__, "ERROR: Settings not set.");
            }
            return;
        }

        $queues = $this
            ->settings->queues;

        if (count($queues) == 0) {
            if ($this->d) {
                $this->log(__LINE__, "No queues found.");
            }
            return;
        }

        $this->parsing = true;
        if ($this->d) {
            $this->log(__LINE__, "Running through queue rules");
        }
        foreach ($queues as $i => $queue) {
            if (isset($this->passedQueues[$queue->name])) {
                if ($this->d) {
                    $this->log(__LINE__, "Passed from array " . $queue->name);
                }
                continue;
            }

            if ($this->d) {
                $this->log(__LINE__, "Checking " . $queue->displayName);
            }
            if ($this->isMatch($queue)) {
                if ($this->d) {
                    $this->log(__LINE__, "Got a match " . $queue->displayName);
                }

                if (!$this->onMatch($queue)) {
                    if (!$this->continuePage) {
                        return;
                    }
                    if ($this->d) {
                        $this->log(__LINE__, "Found matching unpassed queue " . $queue->displayName);
                    }
                    if ($this->config->adapterMode == "simple") {
                        return;
                    } else {
                        continue;
                    }
                }

                if (!$this->continuePage) {
                    return;
                }

                //Passed.
                $this->passedQueues[$queue->name] = true;
            } else {
                if ($this->d) {
                    $this->log(__LINE__, "Rules did not match " . $queue->displayName);
                }
            }
        }

        if ($this->d) {
            $this->log(__LINE__, "All queues checked.");
        }
        $this->parsing = false;
    }

    protected function consultAdapter($queue)
    {

        if ($this->d) {
            $this->log(
                __LINE__,
                "Consulting Adapter Server for queue " . $queue->name." for page ".$this->getURL()
            );
        }

        $this->adapterQueue = $queue;
        $adapterMode = "safe";

        if (isset($queue->adapterMode)) {
            $adapterMode = $queue->adapterMode;
        } elseif (isset($this->config->adapterMode)) {
            $adapterMode = $this
                ->config->adapterMode;
        }

        if ($this->d) {
            $this->log(__LINE__, "Adapter mode is " . $adapterMode);
        }
        if ("safe" == $adapterMode) {
            $url = $this->protocol . "://" . $queue->adapterServer . "/adapter/" . $queue->name;
            $url .= "?ipaddress=" . urlencode($this->remoteAddr);

            if ($this->uid != null) {
                $url .= "&uid=" . $this->uid;
            }

            $url .= "&identifier=" . urlencode($this->processIdentifier($this->userAgent));

            if ($this->d) {
                $this->log(__LINE__, "Adapter URL " . $url);
            }

            $json = $this->loadURL($url);

            if ($json === false) {
                $this->error("No Settings JSON!");
                return;
            }

            if ($this->d) {
                $this->log(__LINE__, "Downloaded JSON Settings " . $json);
            }
            $this->adapterResult = json_decode($json);
            $this->gotAdapter();
            if (!$this->continuePage) {
                return;
            }
        } else {
            $url = $this->protocol . "://" . $queue->queueServer . "/"
                      . $queue->name . "?target=" . urlencode($this->getURL());

            $url = $this->appendVariantToRedirectLocation($queue, $url);
	    $url = $this->appendExtraToRedirectLocation($queue, $url);
            if ($this->d) {
                $this->log(__LINE__, "Redirecting to adapter server " . $url);
            }

            $this->redirect($url, 0);
        }
    }

    protected function getVariant($queue)
    {
        if ($this->d) {
            $this->log(__LINE__, "Getting variants for " . $queue->name);
        }
        if (!isset($queue->activation)) {
            return null;
        }

        if (!isset($queue->activation->variantRules)) {
            return null;
        }

        $variantRules = $queue
            ->activation->variantRules;

        if ($this->d) {
            $this->log(__LINE__, "Checking variant rules for " . $queue->name);
        }
        $lim = count($variantRules);
        for ($i = 0; $i < $lim; $i++) {
            $variant = $variantRules[$i];
            $variantName = $variant->variant;
            $rules = $variant->rules;
            $ret = $this->isMatchArray($rules);
            if ($this->d) {
                $this->log(__LINE__, "Variant match " . $variantName . " " . $ret);
            }
            if ($ret) {
                return $variantName;
            }
        }

        return null;
    }

    protected function getURL()
    {
        return $this->requestedURL;
    }

    protected function appendVariantToRedirectLocation($queue, $redirectLoc)
    {
        if ($this->d) {
            $this->log(__LINE__, "Looking for variant");
        }
        $variant = $this->getVariant($queue);
        if ($variant == null) {
            if ($this->d) {
                $this->log(__LINE__, "No variant found");
            }
            return $redirectLoc;
        }

        if ($this->d) {
            $this->log(__LINE__, "Found variant " . $variant);
        }

        if (strpos($redirectLoc, "?") !== false) {
            $redirectLoc .= "&";
        } else {
            $redirectLoc .= "?";
        }

        $redirectLoc .= "qfv=" . urlencode($variant);
        return $redirectLoc;
    }

    protected function appendExtraToRedirectLocation($queue, $redirectLoc)
    {
	if ($this->extra === "") {
	    return $redirectLoc;
	}
        $this->log(__LINE__, "Found extra " . $this->extra);

        if (strpos($redirectLoc, "?") !== false) {
            $redirectLoc .= "&";
        } else {
            $redirectLoc .= "?";
        }

        $redirectLoc .= "qfx=" . urlencode($this->extra);
        return $redirectLoc;
    }


    protected function gotAdapter()
    {
        if ($this->d) {
            $this->log(__LINE__, "Got adapter");
        }
        if (!$this->adapterResult) {
            if ($this->d) {
                $this->log(__LINE__, "ERROR: onAdapter() called without result");
            }
            return;
        }

        if (isset($this->adapterResult->uid)) {
            if ($this->uid != null && $this->uid != $this->adapterResult->uid) {
                $this->error(
                    "UID Cookie Mismatch - Contact Queue-Fair Support! expected "
                    . $this->uid . " but received " . $this->adapterResult->uid
                );
            } else {
                $this->uid = $this
                    ->adapterResult->uid;
                if (isset($this->adapterQueue->cookieDomain)
                    && $this->adapterQueue->cookieDomain != null
                    && $this->adapterQueue->cookieDomain != "") {
                    setcookie(
                        "QueueFair-Store-" . $this
                        ->config->account,
                        "u:" . $this->uid,
                        time() + $this
                        ->adapterResult->cookieSeconds,
                        "/",
                        $this
                        ->adapterQueue
                        ->cookieDomain
                    );
                } else {
                    setcookie(
                        "QueueFair-Store-" . $this
                        ->config->account,
                        "u:" . $this->uid,
                        time() + $this
                        ->adapterResult
                        ->cookieSeconds,
                        "/"
                    );
                }
            }
        }

        if (!$this->adapterResult->action) {
            if ($this->d) {
                $this->log(__LINE__, "ERROR: onAdapter() called without result action");
            }
            return;
        }

        if ($this ->adapterResult->action == "SendToQueue") {
            if ($this->d) {
                $this->log(__LINE__, "Sending to queue server.");
            }

            $queryParams = "";
            $winLoc = $this->getURL();
            if ($this->adapterQueue->dynamicTarget != "disabled") {
                $queryParams .= "target=";
                $queryParams .= urlencode($winLoc);
            }

            if (isset($this->uid)) {
                if ($queryParams != "") {
                    $queryParams .= "&";
                }

                $queryParams .= "qfuid=" . $this->uid;
            }

            $redirectLoc = $this
                ->adapterResult->location;
            if ($queryParams != "") {
                $redirectLoc = $redirectLoc . "?" . $queryParams;
            }

            $redirectLoc = $this->appendVariantToRedirectLocation($this->adapterQueue, $redirectLoc);
	    $redirectLoc = $this->appendExtraToRedirectLocation($this->adapterQueue, $redirectLoc);
            if ($this->d) {
                $this->log(__LINE__, "Redirecting to " . $redirectLoc);
            }
            $this->redirect($redirectLoc, 0);
            return;
        }

        //SafeGuard etc
        $this->setCookie(
            $this
            ->adapterResult->queue,
            urldecode(
                $this
                ->adapterResult
                ->validation
            ),
            $this
            ->adapterQueue->passedLifetimeMinutes * 60,
            (isset(
                $this
                ->adapterQueue
                ->cookieDomain
            )) ? $this
            ->adapterQueue->cookieDomain : null
        );

        if (!$this->continuePage) {
            return;
        }

        if ($this->d) {
            $this->log(
                __LINE__,
                "Marking " . $this
                ->adapterResult->queue . " as passed by adapter."
            );
        }
        $this->passedQueues[$this
            ->adapterResult->queue] = true;
    }

    protected function redirect($loc, $sleep)
    {
        if ($sleep > 0) {
            sleep($sleep);
        }

        $this->checkAndAddCacheControl();
        header("Location: " . $loc);
        $this->continuePage = false;
    }

    protected function setCookie($queueName, $value, $lifetimeSeconds, $cookieDomain)
    {
        if ($this->d) {
            $this->log(__LINE__, "Setting cookie for " . $queueName . " to " . $value);
        }

        $this->checkAndAddCacheControl();
        $cookieName = $this->cookieNameBase . $queueName;

        $date = time();
        $date += $lifetimeSeconds;

        if (isset($cookieDomain) && $cookieDomain != null && $cookieDomain != "") {
            setrawcookie($cookieName, $value, $date, "/", $cookieDomain);
        } else {
            setrawcookie($cookieName, $value, $date, "/");
        }

        if ($lifetimeSeconds > 0) {
            $this->passedQueues[$queueName] = true;
            if ($this
                ->config
                ->stripPassedString) {
                $loc = $this->getURL();
                $pos = strpos($loc, "qfqid=");
                if ($pos !== false) {
                    if ($this->d) {
                        $this->log(__LINE__, "Striping passedString from URL");
                    }
                    $loc = substr($loc, 0, $pos - 1);
                    $this->redirect($loc, 0);
                }
            }
        }
    }

    protected function loadURL($url)
    {
        $arrContextOptions = [
            "http" => [
                'timeout' => $this
                    ->config
                    ->readTimeout
            ] ,
            "ssl" => [
                "verify_peer" => false,
                "verify_peer_name" => false,
            ] ,
        ];

        return file_get_contents($url, false, stream_context_create($arrContextOptions));
    }

    protected function loadSettingsFromCache()
    {
        $lockLoc = $this
            ->config->settingsFileCacheLocation . "/queue-fair-settings.lock";

        $lockFound = false;
        $fileLoc = $this
            ->config->settingsFileCacheLocation . "/queue-fair-settings.json";
    
        if (!file_exists($this->config->settingsFileCacheLocation)) {
            throw new \Exception(
                "QF FATAL Settings folder "
                                    .$this->config->settingsFileCacheLocation
                ." NOT FOUND. Please read README.md in the Queue-Fair distribution."
            );
        }

        if (file_exists($fileLoc) && ($this
            ->config->settingsFileCacheLifetimeMinutes == - 1 || time() - filemtime($fileLoc) < $this
            ->config->settingsFileCacheLifetimeMinutes * 60)) {
            if ($this->d) {
                $this->log(__LINE__, "Settngs file found.");
            }
            if (!file_exists($lockLoc)) {
                if ($this->d) {
                    $this->log(__LINE__, "Returning settings file.");
                }
                return file_get_contents($fileLoc);
            }

            $lockFound = true;
            if ($this->d) {
                $this->log(__LINE__, "Lock already exists");
            }
        } else {
            if ($this->d) {
                $this->log(__LINE__, "Local copy of settings not found or expired.");
            }
        }

        if ($lockFound) {
            $i = 0;
            while (file_exists($lockLoc)) {
                sleep(1);
                $i++;
                if ($i > $this
                    ->config
                    ->readTimeout) {
                    if ($this->d) {
                        $this->log(__LINE__, "Lock timed out");
                    }
                    break;
                }
            }

            //If the lock still exists, another thread has failed.
            if (file_exists($lockLoc)) {
                if ($this->d) {
                    $this->log(__LINE__, "Removing expired lock file.");
                }
                unlink($lockLoc);
                return false;
            } else {
                if ($this->d) {
                    $this->log(__LINE__, "Lock file has gone - returning cached file.");
                }
                return file_get_contents($fileLoc);
            }
        }

        return false;
    }

    protected function loadSettings()
    {

        if (strpos($this->config->account, "DELETE") !== false) {
            throw new \Exception(
                "QF FATAL You need to edit queue-fair-adapter.php and enter your account"
                                    ." System Name and Secret where indicated.  Please see README.md in"
                ." the distribution for details of how to install the Queue-Fair adapter."
            );
        }

        $json = $this->loadSettingsFromCache();

        if ($json === false) {
            $lockLoc = $this
                ->config->settingsFileCacheLocation . "/queue-fair-settings.lock";
            touch($lockLoc);
            $url = $this->protocol . "://" . $this
                ->config->filesServer . "/" . $this
                ->config->account . "/" . $this
                ->config->accountSecret . "/queue-fair-settings.json";
            $json = $this->loadURL($url);
            if ($json !== false) {
                if ($this->d) {
                    $this->log(__LINE__, "Download of settings successful - saving");
                }
                $fileLoc = $this
                    ->config->settingsFileCacheLocation . "/queue-fair-settings.json";
                if (file_exists($fileLoc)) {
                    unlink($fileLoc);
                }

                file_put_contents($fileLoc, $json);
            } else {
                $this->error("Download of Settings failed!");
            }

            unlink($lockLoc);
        } else {
            if ($this->d) {
                $this->log(__LINE__, "Settings retrieved from cache");
            }
        }

        if ($json === false) {
            $this->error("No settings available!");
            return;
        }

        if ($this->d) {
            $this->log(__LINE__, "Using JSON Settings " . $json);
        }

        $this->settings = json_decode($json);

        $this->gotSettings();
    }

    protected function error($what)
    {
        error_log("ERROR QueueFair ERROR: " . $what);
    }

    protected function processIdentifier($parameter)
    {

        if ($parameter == null) {
            return null;
        }
        $i = strpos($parameter, "[");
        if ($i === false) {
            return $parameter;
        }

        if ($i < 20) {
            return $parameter;
        }

        return substr($parameter, 0, $i);
    }

    protected function createHash($secret, $message)
    {
        return hash_hmac('sha256', $message, $secret);
    }

    protected function validateCookie($queue, $cookie)
    {
        if ($this->d) {
            $this->log(__LINE__, "Validating cookie " . $cookie);
        }

        try {
            parse_str($cookie, $parsed);
            if (!isset($parsed["qfh"])) {
                return false;
            }

            $hash = $parsed["qfh"];

            $hpos = strrpos($cookie, "qfh=");
            $check = substr($cookie, 0, $hpos);

            $checkHash = $this->createHash($queue->secret, $this->processIdentifier($this->userAgent).$check);

            if ($hash != $checkHash) {
                if ($this->d) {
                    $this->log(__LINE__, "Cookie Hash Mismatch Given " . $hash . " Should be " . $checkHash);
                }
                return false;
            }

            $tspos = $parsed["qfts"];
            if ($tspos < time() - ($queue->passedLifetimeMinutes * 60)) {
                if ($this->d) {
                    $this->log(__LINE__, "Cookie timestamp too old " . (time() - $tspos));
                }
                return false;
            }

            if ($this->d) {
                $this->log(__LINE__, "Cookie Validated ");
            }
            return true;
        } catch (\Exception $e) {
            if ($this->d) {
                $this->log(__LINE__, "Cookie validation failed with error ".$e->getMessage());
            }
        }
        return false;
    }

    protected function validateQuery($queue)
    {
        try {
            $str = $this->query;
            $q = [];
            parse_str($str, $q);

            if ($this->d) {
                $this->log(__LINE__, "Validating Passed Query " . $str);
            }

            $hpos = strrpos($str, "qfh=");
            if ($hpos == false) {
                if ($this->d) {
                    $this->log(__LINE__, "No Hash In Query");
                }
                return false;
            }

            $queryHash = $q["qfh"];

            if (!isset($queryHash)) {
                if ($this->d) {
                    $this->log(__LINE__, "Malformed hash");
                }
                return false;
            }

            $qpos = strrpos($str, "qfqid=");

            if ($qpos === false) {
                if ($this->d) {
                    $this->log(__LINE__, "No Queue Identifier");
                }
                return false;
            }

            $queryQID = $q["qfqid"];
            $queryTS = $q["qfts"];

            $queryAccount = $q["qfa"];
            $queryQueue = $q["qfq"];

            $queryPassType = $q["qfpt"];

            if (!isset($queryTS)) {
                if ($this->d) {
                    $this->log(__LINE__, "No Timestamp");
                }
                return false;
            }

            if (!is_numeric($queryTS)) {
                if ($this->d) {
                    $this->log(__LINE__, "Timestamp Not Numeric");
                }
                return false;
            }

            if ($queryTS > time() + $this->config->queryTimeLimitSeconds) {
                if ($this->d) {
                    $this->log(__LINE__, "Too Late " . $queryTS . " " . time());
                }
                return false;
            }

            if ($queryTS < time() - $this->config->queryTimeLimitSeconds) {
                if ($this->d) {
                    $this->log(__LINE__, "Too Early " . $queryTS . " " . time());
                }
                return false;
            }

            $check = substr($str, $qpos, $hpos - $qpos);

            $checkHash = $this->createHash($queue->secret, $this->processIdentifier($this->userAgent).$check);

            if ($checkHash != $queryHash) {
                if ($this->d) {
                    $this->log(__LINE__, "Failed Hash");
                }
                return false;
            }

            return true;
        } catch (\Exception $e) {
            if ($this->d) {
                $this->log(__LINE__, "Query validation failed with error ".$e->getMessage());
            }
        }
        return false;
    }

    protected function checkQueryString()
    {
        $urlParams = $this->getURL();
        if ($this->d) {
            $this->log(__LINE__, "Checking URL for Passed String " . $urlParams);
        }
        $q = strrpos($urlParams, "qfqid=");
        if ($q === false) {
            return;
        }

        if ($this->d) {
            $this->log(__LINE__, "Passed string found");
        }
        $i = strrpos($urlParams, "qfq=");
        if ($i === false) {
            return;
        }
        if ($this->d) {
            $this->log(__LINE__, "Passed String with Queue Name found");
        }

        $j = strpos($urlParams, "&", $i);
        $subStart = $i + strlen("qfq=");
        $queueName = substr($urlParams, $subStart, $j - $subStart);

        if ($this->d) {
            $this->log(__LINE__, "Queue name is " . $queueName);
        }
        $lim = count(
            $this
            ->settings
            ->queues
        );

        for ($i = 0; $i < $lim; $i++) {
            $queue = $this
                ->settings
                ->queues[$i];
            if ($queue->name != $queueName) {
                continue;
            }

            if ($this->d) {
                $this->log(__LINE__, "Found queue for querystring " . $queueName);
            }
            $value = "" . $urlParams;
            $value = substr($value, strrpos($value, "qfqid"));

            if (!$this->validateQuery($queue)) {
                //This can happen if it's a stale query string too - check for valid cookie.
                $queueCookie = $this->getCookie($this->cookieNameBase . $queueName);
                if (isset($queueCookie) && "" != $queueCookie) {
                    if ($this->d) {
                        $this->log(__LINE__, "Query validation failed but we have cookie " . $queueCookie);
                    }
                    if ($this->validateCookie($queue, $queueCookie)) {
                        if ($this->d) {
                            $this->log(__LINE__, "...and the cookie is valid. That's fine.");
                        }
                        return;
                    }

                    if ($this->d) {
                        $this->log(__LINE__, "Query AND Cookie validation failed!!!");
                    }
                } else {
                    if ($this->d) {
                        $this->log(__LINE__, "Bad queueCookie for " . $queueName . " " . $queueCookie);
                    }
                }

                if ($this->d) {
                    $this->log(__LINE__, "Query validation failed - redirecting to error page.");
                }
                $loc = $this->protocol . "://" . $queue->queueServer . "/" . $queue->name . "?qfError=InvalidQuery";
                $this->redirect($loc, 1);
                return;
            }

            if ($this->d) {
                $this->log(__LINE__, "Query validation succeeded for " . $value);
            }
            $this->passedString = $value;

            $this->setCookie(
                $queueName,
                $value,
                $queue->passedLifetimeMinutes * 60,
                (isset($queue->cookieDomain)) ? $queue->cookieDomain : null
            );
            if (!$this->continuePage) {
                return;
            }
            if ($this->d) {
                $this->log(__LINE__, "Marking " . $queueName . " as passed by queryString");
            }
            $this->passedQueues[$queueName] = true;
        }
    }

    public function go()
    {
        $allowURLFOpenSave=ini_get("allow_url_fopen");
        try {
            ini_set("allow_url_fopen", 1);
            $this->setDebug();
            if ($this->d) {
                $this->log(__LINE__, "Adapter Starting for ".$this->getURL());
            }
            $this->setUIDFromCookie();
            $this->loadSettings();
            if ($this->d) {
                $this->log(__LINE__, "Adapter Finished continue page: ".(($this->continuePage) ? "true" : "false"));
            }
        } catch (\Exception $e) {
            error_log($e);
            if ($this->d) {
                $this->log(__LINE__, "Adapter Finished with Error.");
            }
        }

        ini_set("allow_url_fopen", $allowURLFOpenSave);
        return $this->continuePage;
    }
}
