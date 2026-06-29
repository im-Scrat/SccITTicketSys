#!/bin/sh
# Container healthcheck for PHP-FPM: pings the pool's ping.path (/ping)
# over the FastCGI socket using cgi-fcgi (provided by libfcgi-bin).
set -e
env -i \
  SCRIPT_NAME=/ping \
  SCRIPT_FILENAME=/ping \
  REQUEST_METHOD=GET \
  cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong
