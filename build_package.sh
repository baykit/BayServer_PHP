#!/bin/bash
version=`cat VERSION`

version_file=packages/bayserver-core/baykit/bayserver/Version.php
temp_version_file=/tmp/Version.php
sed "s/VERSION=.*/VERSION='${version}';/" ${version_file} > ${temp_version_file}
mv ${temp_version_file} ${version_file}

update_composer_json() {
  sed -i -e "s/\(baykit\/bayserver.*\":\) \(\"\).*\(\"\)/\1 \2${version}\3/" composer.json
  sed -i -e "s/\(version\":\) \(\"\).*\(\"\)/\1 \2${version}\3/" composer.json
}


update_composer_json
