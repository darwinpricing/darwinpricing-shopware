#!/bin/bash
set -e
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
ZIP="$( dirname "${DIR}" )"/darwinpricing-shopware.zip
rm -f ${ZIP}
cd $TMPDIR
rm -rf darwinpricing-shopware && mkdir darwinpricing-shopware && cd darwinpricing-shopware
cp -r ${DIR} ./
mkdir Frontend && mv darwinpricing-shopware Frontend/DarwinPricing
rm -rf Frontend/DarwinPricing/.git Frontend/DarwinPricing/.gitignore Frontend/DarwinPricing/nbproject Frontend/DarwinPricing/build.sh Frontend/DarwinPricing/.DS_Store
zip -r -X ${ZIP} *
echo Created ${ZIP}
