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
if [ "$daemon" = 1 ]; then
   $cmd $base/bootstrap.php $* < /dev/null  > /dev/null 2>&1 &
else
   $cmd $base/bootstrap.php $* 
fi
