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


update_composer_json() {
  sed -i -e "s/\(baykit\/bayserver.*\":\) \(\"\).*\(\"\)/\1 \2${version}\3/" composer.json
  sed -i -e "s/\(version\":\) \(\"\).*\(\"\)/\1 \2${version}\3/" composer.json
}
update_composer_json


cp -r stage/* ${target_dir}
cp LICENSE.* NEWS.md README.md ${target_dir}


echo "***** Setup composer libraries *****"
repo=`pwd`
pushd .
cd ${target_dir}
cat > composer.json <<EOF
{
    "require": {
       "baykit/bayserver": "${version}"
    },
    "repositories": [
       {
            "type": "path",
            "url": "${repo}"
       }
    ]
}
EOF
composer install
rm composer.*
rm vendor/baykit/bayserver
pushd .
cd vendor/baykit
mkdir bayserver
cd bayserver
cp -r ${repo}/packages .
popd
bin/bayserver.sh -init
popd

echo "***** Create archive *****"

cd /tmp
tar czf ${target_name}.tgz ${target_name}

