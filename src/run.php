<?php

$LOG = [];

function _log($msg) {
    global $LOG;
    $_LOG[] = $msg;
    echo $msg.'<br />';
}

$version         = '1.0.2';
$sessionId       = null;
$toUpdateRecords = [];
$identSvcs       = ['v4.ident.me', 'v6.ident.me', 'v4.tnedi.me', 'v6.tnedi.me'];

_log("Welcome to version $version of this tool!");
_log("Configured Ident services: ".implode(", ", $identSvcs));

$myIP4 = null;
$myIP6 = null;

if (empty(getenv("NC_SUBDOMAINS")) || empty(getenv("NC_DOMAIN"))) {
    _log("No domains to handle!");
    exit;
}

$domainlist = explode(",", getenv("NC_SUBDOMAINS"));

_log("Configured zone: ".getenv("NC_DOMAIN"));
_log("Configured entries to update: ".implode(", ", $domainlist));


foreach ($identSvcs as $identSvc) {
    $ch = curl_init($identSvc);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    $res = curl_exec($ch);

    if (!$res) {
        _log("Error contacting $identSvc");
        sleep(5);
        continue;
    }

    if (filter_var($res, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $myIP4 = $res;
    } elseif (filter_var($res, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $myIP6 = $res;
        if (getenv("NC_UPDATE_IP6_PREFIX") == 'true') {
            $prefix_new = explode(":", $myIP6, 5);
        }
    } else {
        _log("Invalid IP: $res");
    }

    if (!empty($myIP4) && !empty($myIP6)) {
        break; // Got all IPs, no need to call other services.
    }

}

if (empty($myIP4) && empty($myIP6)) {
    _log("Cannot get any new IP!");
    exit;
}

_log("New IP4: $myIP4");
_log("New IP6: $myIP6");


require_once('DomainWebserviceSoapClient.php');

$nc = new DomainWebserviceSoapClient();

$r = $nc->login(getenv('NC_KD'), getenv('NC_APIKEY'), getenv('NC_APIPW'), 'NC DDNS Updater v' . $version);

if (!$r || $r->status != 'success') {
    _log("Login failed!");
    _log(print_r($r, true));
    exit;
}

$sessionId = $r->responsedata->apisessionid;


$r = $nc->infoDnsRecords(getenv("NC_DOMAIN"), getenv('NC_KD'), getenv('NC_APIKEY'), $sessionId, 'NC DDNS Updater v' . $version);
if (!$r || $r->status != 'success') {
    _log("Get domains failed!");
    _log(print_r($r, true));
    exit;
}

$dnsRecordSet             = new Dnsrecordset();
$dnsRecordSet->dnsrecords = $r->responsedata->dnsrecords;

foreach ($dnsRecordSet->dnsrecords as $dnsrecord) {
    if (in_array($dnsrecord->hostname, $domainlist)) {
        if (!in_array($dnsrecord->type, ['A', 'AAAA'])) {
            _log("$dnsrecord->hostname is not A/AAAA. Its " . $dnsrecord->type);
            continue;
        }
        _log("Processing $dnsrecord->type: $dnsrecord->hostname");

        $newIP = null;

        switch ($dnsrecord->type) {
            case 'A':
                if(empty($myIP4)) {
                    _log("Skipping v4 update: no new v4 address found!");
                    break;
                }
                $newIP = $myIP4;
                break;
            case 'AAAA':
                if(empty($myIP6)) {
                    _log("Skipping v6 update: no new v6 address found!");
                    break;
                }
                if (getenv("NC_UPDATE_IP6_PREFIX") == 'true') {
                    _log("Only updating v6 prefix!");
                    $prefix_old    = explode(":", $dnsrecord->destination, 5);
                    $prefix_old[0] = $prefix_new[0];
                    $prefix_old[1] = $prefix_new[1];
                    $prefix_old[2] = $prefix_new[2];
                    $prefix_old[3] = $prefix_new[3];
                    $myIP6         = implode(":", $prefix_old);
                }
                $newIP = $myIP6;
                break;
        }
        if ($dnsrecord->destination == $newIP || empty($newIP)) {
            _log("No need to update - same IP!");
        } elseif(!empty($newIP)) {
            _log( "Update $dnsrecord->type to $newIP");
            $dnsrecord->destination = $newIP;
            $toUpdateRecords[]      = $dnsrecord;
        } else {
            _log("Update for $dnsrecord->hostname failed!");
        }
    } else {
        _log("Ignoring $dnsrecord->hostname: not in list!");
    }
}

if (!empty($toUpdateRecords)) {


    $r = $nc->updateDnsRecords(getenv("NC_DOMAIN"), getenv('NC_KD'), getenv('NC_APIKEY'), $sessionId, 'NC DDNS Updater v' . $version, ['dnsrecords' => $toUpdateRecords]);


    if (!$r || $r->status != 'success') {
        _log("Get domains failed!");
        _log(print_r($r, true));
        exit;
    }

} else {
    _log( "Nothing to update!");
}


$r = $nc->logout(getenv('NC_KD'), getenv('NC_APIKEY'), $sessionId, 'NC DDNS Updater v' . $version);
if (!$r || $r->status != 'success') {
    _log("Logout failed!");
    _log(print_r($r, true));
    exit;
}

?>