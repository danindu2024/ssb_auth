<?php
// Front controller shim for cPanel. The real application lives OUTSIDE public_html, so its source,
// config, sessions and database settings can never be fetched over the web.
// Edit the path to wherever you uploaded the ssb_auth folder (your cPanel account's home directory).
require '/home/ACCOUNT/ssb_auth/public/index.php';
