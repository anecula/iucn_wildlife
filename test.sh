#!/usr/bin/env bash

php=`which php`

what=""

if [ -z $@ ]; then
  echo "Running all project-related tests ..."
  what="iucn_search"
else
  what="--class Drupal\\iucn_search\\Tests\\$@"
fi

$php docroot/core/scripts/run-tests.sh --non-html --color --verbose $what
#    --sqlite /tmp/iucnwildlifed8.sqlite \
