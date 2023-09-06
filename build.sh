#!/bin/bash
version=`cat VERSION`

version_file=packages/bayserver-core/baykit/bayserver/Version.php
temp_version_file=/tmp/Version.php
sed "s/VERSION=.*/VERSION='${version}';/" ${version_file} > ${temp_version_file}
mv ${temp_version_file} ${version_file}

target_name=BayServer_PHP-${version}
target_dir=/tmp/${target_name}
rm -fr ${target_dir}
mkdir ${target_dir}
mkdir ${target_dir}/lib


cp -r test/simple/lib/conf/* stage/lib/conf
cp -r test/simple/www/root stage/www
cp -r test/simple/www/cgi-demo stage/www
cp -r stage/* ${target_dir}
cp LICENSE.* NEWS.md README.md composer.json ${target_dir}


echo "***** Setup composer libraries *****"
rm composer.lock
composer install
echo "***** Check vendor *****"
ls vendor
mv vendor ${target_dir}
cp -r packages ${target_dir}
rm composer.lock

cd /tmp
tar czf ${target_name}.tgz ${target_name}

