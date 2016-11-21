#!/bin/bash

set -e $DRUPAL_TI_DEBUG

# Ensure the right Drupal version is installed.
# Note: This function is re-entrant.
drupal_ti_ensure_drupal

cd "$DRUPAL_TI_DRUPAL_DIR"
mkdir -p sites/default/files

drush dl composer_manager --yes

# Temporary prevent composer auto download composer command
drush vset composer_manager_autobuild_packages 0

drush en composer_manager --yes
drush composer-rebuild

pushd sites/default/files/composer
composer require webonyx/graphql-php
popd
