#!/bin/bash

if [[ -f /usr/local/emhttp/plugins/restic/restic.page && ! -e /usr/local/emhttp/plugins/restic/Restic.page ]]; then
  ln -sf /usr/local/emhttp/plugins/restic/restic.page /usr/local/emhttp/plugins/restic/Restic.page
fi

chmod 0755 /usr/local/sbin/restic-manager 2>/dev/null || true
chmod 0755 /usr/local/emhttp/plugins/restic/scripts/restic-manager.php 2>/dev/null || true
chmod 0755 /usr/local/emhttp/plugins/restic/bin/restic-wrapper 2>/dev/null || true
