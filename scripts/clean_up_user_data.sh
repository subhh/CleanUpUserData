#!/bin/bash

export VUFIND_HOME="/usr/local/vufind"
export VUFIND_LOCAL_DIR="/usr/local/vufind/local/"
export VUFIND_LOCAL_MODULES="CleanUpUserData"

if [ -n "$1" ]; then
    php $VUFIND_HOME/public/index.php util/cleanup_user_data --hours $1
else
    php $VUFIND_HOME/public/index.php util/cleanup_user_data
fi