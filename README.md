# netcup DDNS updater container
This helper updates your netcup domain with current IPv4/6 IPs. It has support for dynmaic prefixes and only updates the prefix.

You can find the image at https://hub.docker.com/r/rkluth/netcup-docker-dns

## Environment variables
* `NC_KD` netcup customer number
* `NC_APIPW` netcup API password
* `NC_APIKEY` netcup API key
* `NC_UPDATE_IP6_PREFIX` true, if only prefix should be updated, false otherwise
* `NC_DOMAIN` domain (zone) name to use
* `NC_SUBDOMAINS` comma seperated list of entries to update. Example: `intern` or `intern,cloud`

## How to run?
Simply start the container and call `/run.php`. Example: `http://1.2.3.4/run.php`.