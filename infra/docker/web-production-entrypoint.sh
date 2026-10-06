#!/bin/sh
set -eu

node apps/web/scripts/validate-env.mjs
exec node apps/web/server.js
