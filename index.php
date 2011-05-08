<?php
error_reporting(E_ALL);

include("./lib/halcyon.php");


define("CONN_DSN", "mysql:host=localhost;dbname=YOUR_DB_NAME");
define("CONN_USR", "guess_what");
define("CONN_PWD", "guess_what_else");

@$PDO = new PDO(CONN_DSN, CONN_USR, CONN_PWD);
@$PDO->query("SET names UTF8");

 
$halcyon = Halcyon::run();
$halcyon->handleRequest();
