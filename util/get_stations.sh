#!/usr/bin/env bash
pdo_query='<?php $pdo = new PDO("mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=azuracast", "azuracast", "azur4c457"); foreach ($pdo->query("SELECT id, short_name FROM stations LIMIT 5") as $r) echo $r["id"]." ".$r["short_name"]."\n";'
echo "$pdo_query" > /tmp/st.php
php /tmp/st.php
