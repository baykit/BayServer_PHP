#!/bin/sh
base=`dirname $0`

args=$*
daemon=
for arg in $args; do
  if [ "$arg" = "-daemon" ]; then
    daemon=1
  fi
done

cmd=php
script=${base}/../vendor/bin/bayserver_php

if [ "$daemon" = 1 ]; then
   ${script} $* < /dev/null  > /dev/null 2>&1 &
else
   ${script} $* 
fi
