<?php

$version         = '1.0.0';
$sessionId       = null;
$toUpdateRecords = [];
$identSvcs       = ['v4.ident.me', 'v6.ident.me', 'v4.tnedi.me', 'v6.tnedi.me'];

$myIP4 = null;
$myIP6 = null;

if (empty(getenv("NC_SUBDOMAINS")) || empty(getenv("NC_DOMAIN"))) {
    echo "No domains to handle!";
    exit;
}

$domainlist = explode(",", getenv("NC_SUBDOMAINS"));


foreach ($identSvcs as $identSvc) {
    $ch = curl_init($identSvc);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);

    if (!$res) {
        echo "Error contacting $identSvc";
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
        echo "Invalid IP: $res" . PHP_EOL;
    }

    if (!empty($myIP4) && !empty($myIP6)) {
        break; // Got all IPs, no need to call other services.
    }

}

if (empty($myIP4) && empty($myIP6)) {
    echo "Cannot get any new IP!";
    exit;
}

echo "New IP4: $myIP4";
echo "New IP6: $myIP6";


require_once('DomainWebserviceSoapClient.php');

$nc = new DomainWebserviceSoapClient();

$r = $nc->login(getenv('NC_KD'), getenv('NC_APIKEY'), getenv('NC_APIPW'), 'NC DDNS Updater v' . $version);

if (!$r || $r->status != 'success') {
    echo "Login failed!";
    print_r($r);
    exit;
}

$sessionId = $r->responsedata->apisessionid;


$r = $nc->infoDnsRecords(getenv("NC_DOMAIN"), getenv('NC_KD'), getenv('NC_APIKEY'), $sessionId, 'NC DDNS Updater v' . $version);
if (!$r || $r->status != 'success') {
    echo "Get domains failed!";
    print_r($r);
    exit;
}

$dnsRecordSet             = new Dnsrecordset();
$dnsRecordSet->dnsrecords = $r->responsedata->dnsrecords;

foreach ($dnsRecordSet->dnsrecords as $dnsrecord) {
    if (in_array($dnsrecord->hostname, $domainlist)) {
        if (!in_array($dnsrecord->type, ['A', 'AAAA'])) {
            echo "$dnsrecord->hostname is not A/AAAA. Its " . $dnsrecord->type;
            continue;
        }
        echo "Updating $dnsrecord->type $dnsrecord->hostname";

        $newIP = null;

        switch ($dnsrecord->type) {
            case 'A':
                $newIP = $myIP4;
                break;
            case 'AAAA':
                if (getenv("NC_UPDATE_IP6_PREFIX") == 'true') {
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
            echo "No need to update - same IP!";
        } else {
            echo "Set $dnsrecord->type to $dnsrecord->destination" . PHP_EOL;
            $dnsrecord->destination = $newIP;
            $toUpdateRecords[]      = $dnsrecord;
        }
    }
}

print_r($toUpdateRecords);

if (!empty($toUpdateRecords)) {


    $r = $nc->updateDnsRecords(getenv("NC_DOMAIN"), getenv('NC_KD'), getenv('NC_APIKEY'), $sessionId, 'NC DDNS Updater v' . $version, ['dnsrecords' => $toUpdateRecords]);


    if (!$r || $r->status != 'success') {
        echo "Get domains failed!";
        print_r($r);
        exit;
    }

} else {
    echo "Nothing to update!";
}


$r = $nc->logout(getenv('NC_KD'), getenv('NC_APIKEY'), $sessionId, 'NC DDNS Updater v' . $version);
if (!$r || $r->status != 'success') {
    echo "Logout failed!";
    print_r($r);
    exit;
}

?>