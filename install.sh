#!/bin/bash

# Go to docroot/
cd docroot/
drush sql-drop -y

env="prod"
if [ ! -z "$1" ]; then
  env=$1
fi

echo "Getting '$env' environment database ..."
drush sql-sync "@iucnwildlifed8.$env" @self -y

echo "Importing 'default' configuration..."
drush cim vcs -y

echo "Running database pending updates ..."
drush updatedb
drush cr
echo "Done"
